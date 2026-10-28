<?php

declare(strict_types=1);

namespace Cms\Assistant\Transport;

/**
 * One model turn, normalised.
 *
 * Deliberately not the SDK's response object: the runner should not have to know
 * whether a block was a TextBlock or a ToolUseBlock, only what was said and what
 * was called.
 */
final class TransportResponse
{
    /**
     * @param string                                                     $text       Assistant prose for this turn
     * @param array<int, array{id: string, name: string, input: array}>  $toolCalls  Tools the model wants run
     * @param string                                                     $stopReason 'end_turn' | 'tool_use' | 'max_tokens' | 'refusal'
     * @param array<string, int>                                         $usage      input/output token counts, for cost accounting
     * @param array<int, array<string, mixed>>                           $rawContent Original content blocks, replayed verbatim on the next turn
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls = [],
        public readonly string $stopReason = 'end_turn',
        public readonly array $usage = [],
        public readonly array $rawContent = [],
    ) {
    }

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }

    /** The model declined the request — surface it rather than treating it as an empty reply. */
    public function isRefusal(): bool
    {
        return $this->stopReason === 'refusal';
    }
}
