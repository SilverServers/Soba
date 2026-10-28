<?php

declare(strict_types=1);

namespace Cms\Assistant;

/**
 * One tool the assistant can call.
 *
 * Deliberately knows nothing about Claude, HTTP, or MCP. A tool is a name, a
 * JSON Schema for its input, a minimum role, and a closure that runs it. The
 * transport adapter is responsible for translating this into whatever shape a
 * given API expects — see ToolCatalog for why that separation exists.
 *
 * Tools are read-only by construction in v1: nothing in this namespace writes.
 * Changes are proposed as a change set and applied by ordinary PHP after a human
 * approves them, so the model is never in the write path.
 */
final class Tool
{
    /**
     * @param string               $name        Tool name exposed to the model (snake_case)
     * @param string               $description What it does and when to use it — the model reads this
     * @param array<string, mixed> $inputSchema JSON Schema (object) for the tool's input
     * @param \Closure             $run         fn(array $input, ToolContext $ctx): mixed
     * @param string               $minimumRole 'author' | 'editor' | 'admin'
     * @param bool                 $strict      Enforce exact schema validation at the API
     * @param string               $label       What the site owner is told this is — see label()
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        private readonly \Closure $run,
        public readonly string $minimumRole = 'author',
        public readonly bool $strict = true,
        private readonly string $label = '',
    ) {
    }

    /**
     * What the owner sees while this runs, and afterwards in the transcript.
     *
     * `search_entries` and `find_in_templates` are names for the model's benefit.
     * Showing them to the person whose website this is leaks the machinery for no
     * gain — they cannot act on any of it, and every appearance of a snake_case
     * identifier is a reminder that they are talking to software rather than
     * getting their website changed.
     *
     * Falls back to a de-underscored name so a tool added without a label is
     * merely plain rather than raw.
     */
    public function label(): string
    {
        return $this->label !== ''
            ? $this->label
            : ucfirst(str_replace('_', ' ', $this->name));
    }

    /**
     * Execute the tool.
     *
     * Input is validated against the schema's `required` list before the closure
     * runs — the model can and does omit required properties, and a tool should
     * report that as a usable error rather than raising a TypeError.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> Always an array so results serialise predictably
     */
    public function run(array $input, ToolContext $context): array
    {
        foreach ($this->inputSchema['required'] ?? [] as $required) {
            if (!array_key_exists($required, $input) || $input[$required] === '') {
                return ['error' => "Missing required parameter '{$required}'."];
            }
        }

        $result = ($this->run)($input, $context);

        return is_array($result) ? $result : ['result' => $result];
    }

    /**
     * The tool as a plain definition array.
     *
     * This is the neutral shape. Adapters map it onto a specific wire format —
     * the Messages API wants `input_schema`, MCP wants `inputSchema`, and neither
     * concern belongs here.
     *
     * @return array{name: string, description: string, schema: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'schema'      => $this->inputSchema,
        ];
    }
}
