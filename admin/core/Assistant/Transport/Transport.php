<?php

declare(strict_types=1);

namespace Cms\Assistant\Transport;

use Cms\Assistant\Tool;

/**
 * How the assistant reaches a model.
 *
 * The seam that keeps the rest of `core/Assistant/` free of vendor detail. The
 * tool catalog, change sets and applier know nothing about Claude — swapping the
 * SDK for raw cURL, or adding an MCP server later, means writing another class
 * here and nothing else.
 *
 * It is also what makes the whole pipeline testable with no API key and no spend:
 * see StubTransport.
 */
interface Transport
{
    /**
     * Send one turn and return what came back.
     *
     * `$onEvent`, when given, is called as the turn unfolds so a caller can show
     * progress instead of a 30-60s blank. It receives arrays shaped
     * `['type' => 'text', 'text' => …]`, `['type' => 'thinking']` or
     * `['type' => 'tool', 'name' => …]`. It is advisory: a transport that cannot
     * stream may ignore it, and the return value is identical either way.
     *
     * @param string                          $system   System prompt
     * @param array<int, array<string,mixed>> $messages Conversation so far, in wire shape
     * @param array<string, Tool>             $tools    Tools the model may call, keyed by name
     * @param (callable(array<string, mixed>): void)|null $onEvent Progress listener
     */
    public function send(
        string $system,
        array $messages,
        array $tools,
        ?callable $onEvent = null
    ): TransportResponse;

    /** Whether this transport can actually reach a model (e.g. an API key is present). */
    public function isConfigured(): bool;

    /** Human-readable name, for the "not configured" notice in the admin. */
    public function describe(): string;
}
