<?php

declare(strict_types=1);

namespace Cms\Assistant\Transport;

/**
 * A scripted transport for building and testing without an API key.
 *
 * The whole pipeline — tool loop, change set validation, diff review, apply,
 * revisions — is exercisable with no network call and no spend. That matters
 * beyond convenience: it means the parts that touch real content can be tested
 * deterministically, instead of depending on what a model happened to say.
 *
 * Two modes:
 *
 *  - **Scripted**: hand it a queue of TransportResponse objects and it returns
 *    them in order. Use this to reproduce an exact sequence in a test.
 *  - **Reflex** (default): it inspects the tools it was offered and produces a
 *    plausible walk — list types, read an entry, then propose a change — so the
 *    UI can be driven end to end without writing a script.
 */
final class StubTransport implements Transport
{
    /** @var array<int, TransportResponse> */
    private array $script;

    /** @var array<int, array{system: string, messages: array, tools: array}> */
    public array $calls = [];

    /** @param array<int, TransportResponse> $script */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'Stub transport (no API key — scripted responses)';
    }

    public function send(
        string $system,
        array $messages,
        array $tools,
        ?callable $onEvent = null
    ): TransportResponse {
        $this->calls[] = ['system' => $system, 'messages' => $messages, 'tools' => array_keys($tools)];

        $response = $this->script !== []
            ? array_shift($this->script)
            : $this->reflex($messages, $tools);

        // Replay the reply as one chunk. Nothing here is slow enough to need real
        // streaming, but emitting the event keeps the streaming UI exercisable
        // without an API key — the same reason this class exists at all.
        if ($onEvent !== null && $response->text !== '') {
            $onEvent(['type' => 'text', 'text' => $response->text]);
        }

        return $response;
    }

    /**
     * Produce a believable next step from the conversation so far.
     *
     * Counts how many tool results are already in the history and advances one
     * stage per turn, so the runner's loop terminates rather than spinning.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $tools
     */
    private function reflex(array $messages, array $tools): TransportResponse
    {
        $toolResultTurns = 0;
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'user' && is_array($message['content'] ?? null)) {
                foreach ($message['content'] as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $toolResultTurns++;
                    }
                }
            }
        }

        // Stage 1: find out what content types exist.
        if ($toolResultTurns === 0 && isset($tools['list_content_types'])) {
            return new TransportResponse(
                text: 'Let me look at what content this site has.',
                toolCalls: [['id' => 'stub_1', 'name' => 'list_content_types', 'input' => []]],
                stopReason: 'tool_use',
            );
        }

        // Stage 2: read the entry the request is most likely about.
        if ($toolResultTurns === 1 && isset($tools['search_entries'])) {
            return new TransportResponse(
                text: 'Now let me find the relevant entries.',
                toolCalls: [[
                    'id'    => 'stub_2',
                    'name'  => 'search_entries',
                    'input' => ['content_type' => 'pricing_tier'],
                ]],
                stopReason: 'tool_use',
            );
        }

        // Stage 3: stop. A real model would call propose_changes here; the stub
        // does not invent edits to real content, because a stub that proposes
        // writes is a stub that can cause them.
        return new TransportResponse(
            text: 'I have read the site content. (Stub transport: configure an API key '
                . 'in admin/config/config.php to get real proposals.)',
            stopReason: 'end_turn',
        );
    }
}
