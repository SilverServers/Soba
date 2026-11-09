<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\ContentType\ContentTypeSchema;
use Cms\ContentType\Registry;
use Cms\Models\Meta;
use Cms\Models\Page;
use Cms\Models\Post;

/**
 * A proposed set of content changes, awaiting human approval.
 *
 * This is the only thing the assistant is allowed to produce. It never writes;
 * it emits one of these, a person reviews the diff, and ChangeSetApplier carries
 * it out with ordinary PHP. The model is not in the write path, so a malformed or
 * adversarial proposal is a document that fails validation rather than a mutation
 * that already ran.
 *
 * Shape:
 *
 *   {
 *     "summary": "Raise the Team tier to $179/month",
 *     "operations": [
 *       {"op":"update_field","entry_id":2,"field":"product_price","from":149,"to":179},
 *       {"op":"update_core","entry_id":2,"field":"title","from":"Team","to":"Team Plus"},
 *       {"op":"create_entry","content_type":"team_member","values":{"title":"Nia Feld"}},
 *       {"op":"trash_entry","entry_id":13},
 *       {"op":"update_page","page_id":4,"field":"content","from":"<p>…</p>","to":"<p>…</p>"},
 *       {"op":"set_stock","entry_id":1,"option_id":7,"from":4,"to":10},
 *       {"op":"set_order_status","order_id":12,"from":"processing","to":"completed","tracking":{"carrier":"Canada Post","number":"…"}},
 *       {"op":"create_coupon","values":{"code":"SPRING20","kind":"percent","amount":"20"}},
 *       {"op":"add_option","entry_id":1,"choices":{"size":"XXL","colour":"Navy"},"stock":3}
 *     ]
 *   }
 *
 * Pages address by `page_id` and entries by `entry_id`, on purpose: they are
 * separate tables with overlapping id ranges, and a single ambiguous `id` would
 * make "4" mean two different things depending on an adjacent field.
 *
 * Every operation carries `from` — the value the assistant saw when it read the
 * entry. Validation rejects the operation if the stored value no longer matches,
 * so a proposal built against stale data fails loudly instead of silently
 * clobbering someone else's edit.
 */
final class ChangeSet
{
    /**
     * Every operation the model may propose.
     *
     * ── THIS LIST IS A PROMISE, NOT A WISHLIST ──────────────────────────────
     * It is handed to the model verbatim as the `op` enum on `propose_changes`,
     * so every value here is something the model is told it may use, and an
     * operation that nothing implements can only fail when it is proposed.
     *
     * The rule: **an operation belongs here only when `validate()` and
     * `ChangeSetApplier` both have an arm for it.**
     */
    public const OPS = ['update_field', 'update_core', 'create_entry', 'trash_entry', 'update_page',
                        // THE SHOP'S (`ShopOps`): a count, an order's status, a
                        // discount code, an option.
                        'set_stock', 'set_order_status', 'create_coupon', 'add_option'];

    /**
     * The operations this person may be offered: the shop's only where there
     * is a shop and they may run it, so the model is never told it can do
     * something every proposal of which would be refused.
     *
     * @return list<string>
     */
    public static function opsFor(ToolContext $context): array
    {
        $shop = ShopTools::open() && $context->hasRole('shop_manager');

        return array_values(array_filter(self::OPS, static fn (string $op): bool => $shop || !in_array($op, ShopOps::OPS, true)));
    }

    /**
     * Core columns an assistant may touch.
     *
     * Deliberately excludes `author_id` and `password_hash`: one is a record of
     * who wrote a thing and the other is a credential, and neither is a routine
     * content change.
     *
     * `menu_order` is where a hand-ordered list keeps its order, and it is here
     * so that a request such as "swap these two" has an operation that can
     * express a position. That is not a new kind of operation; it is one existing
     * column added to the list of columns `update_core` already knows how to
     * write, snapshot and undo.
     */
    public const CORE_FIELDS = ['title', 'content', 'excerpt', 'status', 'slug', 'menu_order'];

    /**
     * Page columns an assistant may touch.
     *
     * Pages have no field schema, so this list *is* the schema as far as the
     * assistant is concerned. `parent_id` is excluded deliberately: reparenting a
     * page rewrites URLs across the site and cascades to its children, which is a
     * structural decision rather than the routine copy edit this is for.
     */
    public const PAGE_FIELDS = ['title', 'content', 'slug', 'status', 'seo_title', 'meta_description'];

    public const STATUSES = ['published', 'private'];

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * @param string                            $id         Opaque identifier, recorded on every revision it causes
     * @param string                            $summary    One-line description shown above the diff
     * @param array<int, array<string, mixed>>  $operations
     */
    public function __construct(
        public readonly string $id,
        public readonly string $summary,
        public readonly array $operations,
    ) {
    }

    /**
     * Build from a decoded JSON payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload, ?string $id = null): self
    {
        return new self(
            $id ?? bin2hex(random_bytes(8)),
            trim((string) ($payload['summary'] ?? '')),
            array_values(array_filter(
                (array) ($payload['operations'] ?? []),
                'is_array'
            )),
        );
    }

    /**
     * Validate every operation against the live database and the field schema.
     *
     * Returns errors rather than throwing: a proposal with three good operations
     * and one bad one should show the reviewer exactly which one is wrong.
     *
     * @return array<int, string> Empty when the change set is safe to apply
     */
    public function validate(ToolContext $context): array
    {
        $this->errors = [];

        if ($this->summary === '') {
            $this->errors[] = 'The change set needs a summary.';
        }

        if ($this->operations === []) {
            $this->errors[] = 'The change set contains no operations.';
            return $this->errors;
        }

        foreach ($this->operations as $index => $op) {
            $label = 'Operation ' . ($index + 1);
            $type  = (string) ($op['op'] ?? '');

            if (!in_array($type, self::OPS, true)) {
                $this->errors[] = "{$label}: unknown operation '{$type}'.";
                continue;
            }

            // The `default` arm is not decoration. Without it, an op listed in
            // OPS with no branch here throws `UnhandledMatchError` — an uncaught
            // fatal in the middle of a turn, which reaches the owner as a blank
            // 500 rather than as a rejected proposal. With it, the two can never
            // disagree fatally: the worst case is an operation the reviewer is
            // told is unknown.
            match ($type) {
                'update_field' => $this->validateUpdateField($label, $op, $context),
                'update_core'  => $this->validateUpdateCore($label, $op, $context),
                'create_entry' => $this->validateCreateEntry($label, $op, $context),
                'trash_entry'  => $this->validateTrashEntry($label, $op, $context),
                'update_page'  => $this->validateUpdatePage($label, $op, $context),
                'set_stock', 'set_order_status', 'create_coupon', 'add_option'
                               => $this->validateShop($label, $type, $op, $context),
                default        => $this->errors[] = "{$label}: '{$type}' is listed as an "
                    . 'operation but nothing implements it. This is a bug in the CMS, not '
                    . 'in the request.',
            };
        }

        return $this->errors;
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    // ----------------------------------------------------------------
    // Per-operation validation
    // ----------------------------------------------------------------

    private function validateUpdateField(string $label, array $op, ToolContext $context): void
    {
        $entry = $this->requireEntry($label, $op, $context);
        if ($entry === null) {
            return;
        }

        $fieldKey = (string) ($op['field'] ?? '');
        $field    = ContentTypeSchema::field((string) $entry['post_type'], $fieldKey);

        if ($field === null) {
            $this->errors[] = "{$label}: '{$entry['post_type']}' has no field '{$fieldKey}'.";
            return;
        }

        if (!array_key_exists('to', $op)) {
            $this->errors[] = "{$label}: missing 'to' value.";
            return;
        }

        if (!empty($field['required']) && ($op['to'] === null || $op['to'] === '')) {
            $this->errors[] = "{$label}: '{$field['label']}' is required and cannot be emptied.";
        }

        // A `select` may only be set to one of its declared options — otherwise the
        // edit screen would render a value it cannot display.
        if (($field['type'] ?? '') === 'select' && !empty($field['options'])) {
            $allowed = array_keys($field['options']);
            if ($op['to'] !== null && !in_array((string) $op['to'], $allowed, true)) {
                $this->errors[] = "{$label}: '{$op['to']}' is not a valid option for '{$field['label']}'"
                    . ' (expected one of: ' . implode(', ', $allowed) . ').';
            }
        }

        if (($field['type'] ?? '') === 'number' && $op['to'] !== null && !is_numeric($op['to'])) {
            $this->errors[] = "{$label}: '{$field['label']}' must be a number.";
        }

        $this->checkStale($label, $op, Meta::get((int) $entry['id'], $fieldKey));
    }

    private function validateUpdateCore(string $label, array $op, ToolContext $context): void
    {
        $entry = $this->requireEntry($label, $op, $context);
        if ($entry === null) {
            return;
        }

        $fieldKey = (string) ($op['field'] ?? '');

        if (!in_array($fieldKey, self::CORE_FIELDS, true)) {
            $this->errors[] = "{$label}: '{$fieldKey}' is not an editable core field"
                . ' (allowed: ' . implode(', ', self::CORE_FIELDS) . ').';
            return;
        }

        if (!array_key_exists('to', $op)) {
            $this->errors[] = "{$label}: missing 'to' value.";
            return;
        }

        if ($fieldKey === 'title' && trim((string) $op['to']) === '') {
            $this->errors[] = "{$label}: title cannot be empty.";
        }

        if ($fieldKey === 'status' && !in_array((string) $op['to'], self::STATUSES, true)) {
            $this->errors[] = "{$label}: status must be one of " . implode(', ', self::STATUSES)
                . ". Use trash_entry to remove an entry.";
        }

        if ($fieldKey === 'slug') {
            $slug = (string) $op['to'];
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                $this->errors[] = "{$label}: slug may only contain lowercase letters, numbers and hyphens.";
            } elseif (Post::slugExists($slug, (int) $entry['id'])) {
                $this->errors[] = "{$label}: the slug '{$slug}' is already used by another entry.";
            }
        }

        if ($fieldKey === 'menu_order') {
            // The column is UNSIGNED. A negative value is a database error at
            // apply time, which surfaces to the owner as "applying the changes
            // failed" with no way to act on it — so it is caught in review,
            // where the reviewer can still see what was meant.
            if (!is_numeric($op['to']) || (int) $op['to'] < 0 || (float) $op['to'] != (int) $op['to']) {
                $this->errors[] = "{$label}: a position must be a whole number, zero or more.";
            }
        }

        $this->checkStale($label, $op, $entry[$fieldKey] ?? null);
    }

    private function validateCreateEntry(string $label, array $op, ToolContext $context): void
    {
        $typeKey = (string) ($op['content_type'] ?? '');

        if (Registry::get($typeKey) === null) {
            $this->errors[] = "{$label}: no content type '{$typeKey}'.";
            return;
        }

        $values = (array) ($op['values'] ?? []);

        if (trim((string) ($values['title'] ?? '')) === '') {
            $this->errors[] = "{$label}: a new entry needs a title.";
        }

        $definition = ContentTypeSchema::resolve($typeKey);
        $known      = array_column($definition['fields'] ?? [], 'key');

        foreach ($values as $key => $value) {
            if (in_array($key, self::CORE_FIELDS, true)) {
                continue;
            }
            if (!in_array($key, $known, true)) {
                $this->errors[] = "{$label}: '{$typeKey}' has no field '{$key}'.";
            }
        }

        // Required fields must be present, or the entry would be created invalid —
        // something the admin UI would never allow.
        foreach ($definition['fields'] ?? [] as $field) {
            if (!empty($field['required']) && !array_key_exists($field['key'], $values)) {
                $this->errors[] = "{$label}: '{$field['label']}' is required.";
            }
        }
    }

    private function validateTrashEntry(string $label, array $op, ToolContext $context): void
    {
        $this->requireEntry($label, $op, $context);
    }

    /** The shop's operations, checked as the shop's own screens check them (`ShopOps`). */
    private function validateShop(string $label, string $type, array $op, ToolContext $context): void
    {
        foreach (ShopOps::validate($type, $op, $context) as $problem) {
            $this->errors[] = "{$label}: {$problem}";
        }
    }

    /**
     * Change one column on one static Page.
     *
     * Pages have no per-entry author, so the author-scoping that guards entries
     * does not apply. Editing site-wide static copy is an editor's job, which is
     * why the tool that produces this requires that role.
     */
    private function validateUpdatePage(string $label, array $op, ToolContext $context): void
    {
        $pageId = (int) ($op['page_id'] ?? 0);

        if ($pageId <= 0) {
            $this->errors[] = "{$label}: missing page_id.";
            return;
        }

        $page = Page::find($pageId);

        if ($page === null) {
            $this->errors[] = "{$label}: no page with id {$pageId}.";
            return;
        }

        $fieldKey = (string) ($op['field'] ?? '');

        if (!in_array($fieldKey, self::PAGE_FIELDS, true)) {
            $this->errors[] = "{$label}: '{$fieldKey}' is not an editable page field"
                . ' (allowed: ' . implode(', ', self::PAGE_FIELDS) . ').';
            return;
        }

        if (!array_key_exists('to', $op)) {
            $this->errors[] = "{$label}: missing 'to' value.";
            return;
        }

        if ($fieldKey === 'title' && trim((string) $op['to']) === '') {
            $this->errors[] = "{$label}: a page title cannot be empty.";
        }

        if ($fieldKey === 'status' && !in_array((string) $op['to'], self::STATUSES, true)) {
            $this->errors[] = "{$label}: status must be one of " . implode(', ', self::STATUSES) . '.';
        }

        if ($fieldKey === 'slug') {
            $slug = (string) $op['to'];
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                $this->errors[] = "{$label}: slug may only contain lowercase letters, numbers and hyphens.";
            } elseif (Page::slugExists($slug, $pageId)) {
                $this->errors[] = "{$label}: the slug '{$slug}' is already used by another page.";
            }
        }

        $this->checkStale($label, $op, $page[$fieldKey] ?? null);
    }
    // ----------------------------------------------------------------
    // Shared checks
    // ----------------------------------------------------------------

    /**
     * Resolve and authorise the entry an operation targets.
     *
     * @return array<string, mixed>|null
     */
    private function requireEntry(string $label, array $op, ToolContext $context): ?array
    {
        $entryId = (int) ($op['entry_id'] ?? 0);

        if ($entryId <= 0) {
            $this->errors[] = "{$label}: missing entry_id.";
            return null;
        }

        $entry = Post::find($entryId);

        if ($entry === null) {
            $this->errors[] = "{$label}: no entry with id {$entryId}.";
            return null;
        }

        // Permission is answered from the session, never from the change set —
        // anything inside the change set is model-controlled.
        $filter = $context->authorFilter();
        if ($filter !== null && (int) $entry['author_id'] !== $filter) {
            $this->errors[] = "{$label}: you do not have permission to change entry {$entryId}.";
            return null;
        }

        return $entry;
    }

    /**
     * Reject an operation whose `from` no longer matches what is stored.
     *
     * The assistant built this proposal from a read that may be minutes old. If
     * someone edited the entry in between, applying it would silently discard
     * their change — so the mismatch has to surface for the reviewer.
     */
    private function checkStale(string $label, array $op, mixed $current): void
    {
        if (!array_key_exists('from', $op)) {
            return; // `from` is optional; without it there is nothing to compare.
        }

        $expected = $op['from'];

        // Loose comparison on scalars: 149 and "149" mean the same thing here,
        // since meta values come back from MySQL as strings.
        $matches = is_scalar($expected) && is_scalar($current)
            ? (string) $expected === (string) $current
            : $expected == $current;

        if (!$matches) {
            $this->errors[] = sprintf(
                '%s: the current value has changed since this was proposed (expected %s, found %s). '
                . 'Re-read the entry and propose again.',
                $label,
                self::describe($expected),
                self::describe($current)
            );
        }
    }

    /** Render a value for an error message without dumping a whole array. */
    private static function describe(mixed $value): string
    {
        if ($value === null) {
            return 'nothing';
        }
        if (is_scalar($value)) {
            $string = (string) $value;
            return '"' . (mb_strlen($string) > 60 ? mb_substr($string, 0, 57) . '…' : $string) . '"';
        }
        return 'a ' . gettype($value);
    }
}
