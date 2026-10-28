<?php

declare(strict_types=1);

namespace Cms\Assistant\Transport;

use Cms\Assistant\Tool;
use Cms\Assistant\ToolCatalog;

/**
 * Talks to the Claude Messages API over a plain HTTPS POST.
 *
 * ── WHY THIS IS HAND-WRITTEN ────────────────────────────────────────────────
 * The CMS authors its own code rather than adding dependencies, so this takes
 * the place of the `anthropic-ai/sdk` Composer package. It is one JSON
 * endpoint, and fiddly to get right, for one reason set out below.
 *
 * ── THE PART THAT WOULD HAVE BEEN GUESSED WRONG ─────────────────────────────
 * The SDK took camelCase parameter names and mapped them to the API's snake_case
 * on the wire. That mapping reaches down into the content blocks the runner
 * hand-builds — `toolUseID` → `tool_use_id`, `isError` → `is_error` — so it is
 * not a detail of the SDK's surface, it is part of the contract every caller in
 * this project already writes to.
 *
 * It is **not** a blind recursive camelCase→snake_case conversion, and that is
 * the trap. The SDK converts DECLARED PROPERTIES ONLY. A tool's `inputSchema`
 * becomes `input_schema`, but the JSON Schema inside it is passed through
 * untouched — so `additionalProperties`, which strict mode requires, stays
 * exactly as written. A generic converter would rename it to
 * `additional_properties`, the API would stop seeing a closed schema, and strict
 * tools would quietly fail validation in a way no local test would show.
 *
 * So `wire()` below is an explicit, position-by-position mapping, and every key
 * in it was READ OFF THE WIRE — from the bytes the SDK itself sent — rather
 * than inferred.
 *
 * ── STREAMING ───────────────────────────────────────────────────────────────
 * Progress is POLLED, not streamed, all the way to the browser, because
 * `mod_proxy_fcgi` buffers the whole FastCGI response. The SSE stream here is
 * between PHP and the API only: it exists so the `$onEvent` callback can write
 * progress into the database as it arrives, which the browser then polls for.
 */
final class ClaudeTransport implements Transport
{
    /** The API this speaks. Pinned; a new version is a deliberate change. */
    public const API_VERSION = '2023-06-01';

    public const DEFAULT_BASE_URL = 'https://api.anthropic.com';

    /**
     * Opus 5 by default: reading a field schema and composing a correct change set
     * is reasoning work, and a wrong edit is expensive to unwind. Overridable per
     * install for cost-sensitive deployments.
     */
    public const DEFAULT_MODEL = 'claude-opus-5';

    /**
     * Reasoning effort.
     *
     * The API defaults to `high`. For this workload — read a field schema, compare
     * two numbers, emit a change set — `high` mostly buys extra deliberation and
     * extra one-tool-at-a-time round trips, which add up to turns of 30-60s.
     * `medium` consolidates tool calls and finishes noticeably sooner.
     * The safety net is unchanged either way: a human approves every diff.
     */
    public const DEFAULT_EFFORT = 'medium';

    /** Accepted `output_config.effort` values, per the Messages API. */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * How long one turn may take.
     *
     * A tool-using turn on Opus with adaptive thinking runs to tens of seconds,
     * and the timeout has to sit above that or the assistant fails on exactly the
     * requests it is most useful for. No connect-phase wait is folded in here —
     * that is separate and short, because a server that cannot be reached should
     * say so immediately rather than after three minutes.
     */
    public const TIMEOUT = 300;

    public const CONNECT_TIMEOUT = 15;

    private readonly string $effort;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly int $maxTokens = 16000,
        string $effort = self::DEFAULT_EFFORT,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        // An unrecognised value would be a 400 from the API on every single turn,
        // which is a miserable way to find a typo in config.php. Fall back instead.
        $effort = strtolower(trim($effort));
        $this->effort = in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
    }

    /** Build from config.php, so callers do not have to know the key's shape. */
    public static function fromConfig(array $config): self
    {
        return new self(
            $config['anthropic_api_key'] ?? null,
            $config['anthropic_model'] ?? self::DEFAULT_MODEL,
            (int) ($config['anthropic_max_tokens'] ?? 16000),
            (string) ($config['anthropic_effort'] ?? self::DEFAULT_EFFORT),
            // Undocumented on purpose: it points the transport at another
            // endpoint, such as a local server that records requests. An install
            // has no reason to set it, and if one does, it is their own endpoint
            // they are choosing.
            (string) ($config['anthropic_base_url'] ?? self::DEFAULT_BASE_URL),
        );
    }

    public function effort(): string
    {
        return $this->effort;
    }

    public function isConfigured(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    public function describe(): string
    {
        return 'Claude API (' . $this->model . ')';
    }

    public function send(
        string $system,
        array $messages,
        array $tools,
        ?callable $onEvent = null
    ): TransportResponse {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'No Anthropic API key configured. Add "anthropic_api_key" to admin/config/config.php.'
            );
        }

        // The system prompt and tool list are stable across a conversation, so
        // caching them keeps the repeated cost of resending the site's schema
        // every turn down.
        $body = [
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'system'     => [
                ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages'      => $this->wireMessages($messages),
            'tools'         => $this->wireTools($tools),
            'thinking'      => ['type' => 'adaptive'],
            'output_config' => ['effort' => $this->effort],
        ];

        // No listener means nobody is watching the tokens arrive, so the blocking
        // call is both simpler and slightly cheaper to handle. `assistant.php` and
        // any future non-browser caller take this path.
        if ($onEvent === null) {
            return $this->normalise($this->post($body));
        }

        return $this->stream($body, $onEvent);
    }

    // ══ THE WIRE FORMAT ════════════════════════════════════════════════════

    /**
     * Messages, with the runner's camelCase block keys mapped to the API's.
     *
     * Only the keys the SDK declared are touched, and only on `tool_result`
     * blocks, which are the only blocks this project builds by hand. Everything
     * else — thinking blocks with their signatures, tool_use blocks replayed
     * verbatim from a previous turn — passes through byte for byte, which is what
     * the API requires of a replayed assistant turn.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function wireMessages(array $messages): array
    {
        return array_map(function (array $message): array {
            if (!is_array($message['content'] ?? null)) {
                return $message;
            }

            $message['content'] = array_map(static function ($block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'tool_result') {
                    return $block;
                }

                // `toolUseID`, not `toolUseId` — the SDK's own casing, and what
                // every call site in AssistantRunner already writes.
                if (array_key_exists('toolUseID', $block)) {
                    $block['tool_use_id'] = $block['toolUseID'];
                    unset($block['toolUseID']);
                }
                if (array_key_exists('isError', $block)) {
                    $block['is_error'] = $block['isError'];
                    unset($block['isError']);
                }

                return $block;
            }, $message['content']);

            return $message;
        }, $messages);
    }

    /**
     * Map our neutral Tool objects onto the API's shape.
     *
     * `strict` is per-tool. Strict mode requires `additionalProperties: false` on
     * EVERY nested object, which a schema with dynamic keys cannot satisfy — a
     * change set's `values` is a map of arbitrary field keys. Those tools opt out
     * and rely on ChangeSet::validate() instead, which checks against the live
     * database and the field schema and so catches far more than JSON Schema would.
     *
     * THE SCHEMA GOES ON THE WIRE UNTOUCHED. See the class comment: it is JSON
     * Schema, not SDK fields, and renaming anything inside it silently disables
     * the strictness it is asking for.
     *
     * @param array<string, Tool> $tools
     * @return array<int, array<string, mixed>>
     */
    private function wireTools(array $tools): array
    {
        $definitions = [];

        foreach ($tools as $tool) {
            $definitions[] = [
                'name'         => $tool->name,
                'description'  => $tool->description,
                'input_schema' => $tool->inputSchema,
                'strict'       => $tool->strict,
            ];
        }

        return $definitions;
    }

    // ══ TRANSPORT ══════════════════════════════════════════════════════════

    /**
     * One blocking POST.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> the decoded Message
     */
    private function post(array $body): array
    {
        $handle = $this->handle($body, false);

        $raw    = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            // "cURL" in the text on purpose: AssistantController::friendlyError()
            // turns that into "Could not reach the Claude API from this server",
            // which is the useful thing to say about a network failure.
            throw new \RuntimeException('cURL error talking to the Claude API: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException($this->errorMessage($status, $decoded, (string) $raw));
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('The Claude API returned a response that is not JSON.');
        }

        return $decoded;
    }

    /**
     * Stream a turn, reporting progress as it arrives.
     *
     * The events are folded back into exactly the Message object a blocking call
     * would have returned, so `normalise()` — and therefore the whole runner — is
     * unchanged by streaming. All the callback does is narrate.
     *
     * @param array<string, mixed> $body
     */
    private function stream(array $body, callable $onEvent): TransportResponse
    {
        $body['stream'] = true;

        $message   = ['content' => [], 'stop_reason' => 'end_turn', 'usage' => []];
        $blocks    = [];   // index => block being built
        $partials  = [];   // index => accumulated input_json_delta text
        $thinking  = false;
        $buffer    = '';   // bytes not yet forming a complete SSE event
        $failed    = null;

        $consume = function (string $chunk) use (
            &$buffer, &$message, &$blocks, &$partials, &$thinking, &$failed, $onEvent
        ): int {
            $length = strlen($chunk);
            $buffer .= $chunk;

            // SSE events are separated by a blank line. Anything after the last
            // separator is an incomplete event and stays in the buffer — a reader
            // that parsed on every chunk boundary would truncate one at random,
            // which is the whole reason this is buffered rather than line-by-line.
            while (($break = strpos($buffer, "\n\n")) !== false) {
                $raw    = substr($buffer, 0, $break);
                $buffer = substr($buffer, $break + 2);

                $json = '';
                foreach (explode("\n", $raw) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $json .= ltrim(substr($line, 5));
                    }
                }
                if ($json === '') {
                    continue;
                }

                $event = json_decode($json, true);
                if (!is_array($event)) {
                    continue;
                }

                try {
                    $this->accumulate($event, $message, $blocks, $partials, $thinking, $onEvent);
                } catch (\Throwable $e) {
                    // Thrown from inside a cURL write callback, an exception would
                    // be swallowed and the turn would look like it simply ended.
                    // Carry it out and rethrow once the transfer is over.
                    $failed ??= $e;
                }
            }

            return $length;
        };

        $handle = $this->handle($body, true);
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static fn ($h, string $chunk): int => $consume($chunk));

        $ok     = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($failed !== null) {
            throw $failed;
        }
        if ($ok === false) {
            throw new \RuntimeException('cURL error talking to the Claude API: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            // An error response to a stream request is a plain JSON body, not SSE,
            // so it is sitting whole in the buffer.
            throw new \RuntimeException(
                $this->errorMessage($status, json_decode($buffer, true), $buffer)
            );
        }

        ksort($blocks);
        $message['content'] = array_values($blocks);

        return $this->normalise($message);
    }

    /**
     * Fold one SSE event into the message being rebuilt.
     *
     * @param array<string, mixed>            $event
     * @param array<string, mixed>            $message
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, string>              $partials
     */
    private function accumulate(
        array $event,
        array &$message,
        array &$blocks,
        array &$partials,
        bool &$thinking,
        callable $onEvent
    ): void {
        $index = (int) ($event['index'] ?? 0);

        switch ($event['type'] ?? '') {
            case 'message_start':
                $start = (array) ($event['message'] ?? []);
                // Keep everything except content, which arrives block by block.
                unset($start['content']);
                $message = [...$message, ...$start];
                break;

            case 'content_block_start':
                $block = (array) ($event['content_block'] ?? []);
                $blocks[$index] = $block;

                if (($block['type'] ?? '') === 'tool_use') {
                    $partials[$index] = '';
                    // Announcing the tool the moment its block opens is what turns
                    // a silent 30s pause into "Looking at your Team…".
                    $onEvent([
                        'type'  => 'tool',
                        'name'  => (string) ($block['name'] ?? ''),
                        // The label travels with the event so the browser never has
                        // to keep its own copy of what each tool is called in plain
                        // words.
                        'label' => ToolCatalog::label((string) ($block['name'] ?? '')),
                    ]);
                }
                break;

            case 'content_block_delta':
                $delta = (array) ($event['delta'] ?? []);

                switch ($delta['type'] ?? '') {
                    case 'text_delta':
                        $blocks[$index]['text'] = ($blocks[$index]['text'] ?? '') . (string) $delta['text'];
                        $onEvent(['type' => 'text', 'text' => (string) $delta['text']]);
                        break;

                    case 'thinking_delta':
                        $blocks[$index]['thinking'] =
                            ($blocks[$index]['thinking'] ?? '') . (string) $delta['thinking'];
                        if (!$thinking) {
                            // Thinking display defaults to "omitted", so these
                            // deltas carry no readable text — they are only useful
                            // as a sign of life.
                            $thinking = true;
                            $onEvent(['type' => 'thinking']);
                        }
                        break;

                    case 'signature_delta':
                        // The signature is what makes a thinking block replayable
                        // on the next turn. Drop it and the API rejects the whole
                        // conversation.
                        $blocks[$index]['signature'] =
                            ($blocks[$index]['signature'] ?? '') . (string) $delta['signature'];
                        break;

                    case 'input_json_delta':
                        // A tool call's arguments arrive as fragments that only
                        // parse once concatenated. Decoding each one as it lands
                        // works right up until an input is long enough to be split.
                        $partials[$index] = ($partials[$index] ?? '') . (string) $delta['partial_json'];
                        break;
                }
                break;

            case 'content_block_stop':
                if (isset($partials[$index])) {
                    $decoded = $partials[$index] === '' ? [] : json_decode($partials[$index], true);
                    $blocks[$index]['input'] = is_array($decoded) ? $decoded : [];
                    unset($partials[$index]);
                }
                break;

            case 'message_delta':
                $message = [...$message, ...(array) ($event['delta'] ?? [])];
                // Output tokens are only final here — `message_start` reports zero,
                // and billing off that number would report every turn as free.
                $message['usage'] = [
                    ...(array) ($message['usage'] ?? []),
                    ...(array) ($event['usage'] ?? []),
                ];
                break;

            case 'error':
                $error = (array) ($event['error'] ?? []);
                throw new \RuntimeException(
                    'The Claude API reported an error mid-stream: '
                    . (string) ($error['message'] ?? 'no detail given')
                );
        }
    }

    /**
     * A cURL handle for one request.
     *
     * @param array<string, mixed> $body
     */
    private function handle(array $body, bool $stream): \CurlHandle
    {
        $handle = curl_init(rtrim($this->baseUrl, '/') . '/v1/messages');

        if ($handle === false) {
            throw new \RuntimeException('cURL could not be initialised on this server.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
                'anthropic-version: ' . self::API_VERSION,
                'x-api-key: ' . (string) $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            // Certificate verification is ON, explicitly rather than by default.
            // This is the one outbound request the application makes and it
            // carries the API key; weakening it should take deleting a line, not
            // merely forgetting to add one.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $handle;
    }

    /**
     * What went wrong, in terms the layer above can act on.
     *
     * The status code is in the text because `AssistantController::friendlyError()`
     * matches on it to decide what the owner is told — a 401 is "check your key",
     * a 403 is "no credit", a 429 is "wait a moment".
     */
    private function errorMessage(int $status, mixed $decoded, string $raw): string
    {
        $detail = is_array($decoded)
            ? (string) ($decoded['error']['message'] ?? '')
            : '';

        if ($detail === '') {
            $detail = trim(substr($raw, 0, 300));
        }

        return 'The Claude API returned HTTP ' . $status
            . ($detail !== '' ? ': ' . $detail : '');
    }

    /**
     * Flatten the API's polymorphic content blocks into a TransportResponse.
     *
     * @param array<string, mixed> $message
     */
    private function normalise(array $message): TransportResponse
    {
        $text      = '';
        $toolCalls = [];
        $raw       = [];

        foreach ((array) ($message['content'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }

            // Replayed verbatim on the next turn — thinking blocks with their
            // signatures included, which the API requires and which are not
            // otherwise read by anything here.
            $raw[] = $block;

            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = [
                    'id'    => (string) ($block['id'] ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    'input' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return new TransportResponse(
            text:       trim($text),
            toolCalls:  $toolCalls,
            stopReason: (string) ($message['stop_reason'] ?? 'end_turn'),
            usage:      [
                'input'  => (int) ($message['usage']['input_tokens'] ?? 0),
                'output' => (int) ($message['usage']['output_tokens'] ?? 0),
            ],
            rawContent: $raw,
        );
    }
}
