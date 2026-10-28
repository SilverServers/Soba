<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\ContentType\ContentTypeSchema;
use Cms\Database\Database;
use Cms\Models\Meta;
use Cms\Models\Option;
use Cms\Models\Page;
use Cms\Models\Post;
use Cms\Models\Revision;

/**
 * Applies an approved ChangeSet.
 *
 * This is the write path, and there is no model in it. By the time execution
 * reaches here a human has read the diff and pressed a button; everything below
 * is ordinary, auditable PHP going through the same Post/Meta calls the admin
 * screens use.
 *
 * Two guarantees:
 *
 *  1. **Re-validated at apply time.** A change set is validated when proposed and
 *     again here. Time passes between review and approval, and permissions or
 *     content can change in the gap.
 *
 *  2. **Every touched entry is snapshotted first**, tagged with the change set id,
 *     so "what did the assistant change, and on whose instruction" is answerable
 *     from the revisions table alone — and every change is individually reversible.
 */
final class ChangeSetApplier
{
    /**
     * @return array{applied: bool, results: array<int, array<string,mixed>>, errors: array<int,string>}
     */
    public static function apply(ChangeSet $changeSet, ToolContext $context): array
    {
        $errors = $changeSet->validate($context);

        if ($errors !== []) {
            return ['applied' => false, 'results' => [], 'errors' => $errors];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $results = [];

            foreach ($changeSet->operations as $index => $op) {
                // `validate()` above has already rejected anything without an arm
                // here, so this default is unreachable in practice — which is the
                // point of stating it. A bare match throws
                // `UnhandledMatchError`, and the rollback below would report that
                // to the owner as the reason their change failed. The two lists
                // drifting apart is not a hypothetical: ten operations sat in
                // ChangeSet::OPS with no implementation on either side.
                $results[] = match ((string) $op['op']) {
                    'update_field' => self::updateField($op, $changeSet, $context),
                    'update_core'  => self::updateCore($op, $changeSet, $context),
                    'create_entry' => self::createEntry($op, $context),
                    'trash_entry'  => self::trashEntry($op, $changeSet, $context),
                    'update_page'  => self::updatePage($op, $changeSet, $context),
                    default        => throw new \RuntimeException(
                        "Operation '{$op['op']}' has no implementation."
                    ),
                };
            }

            $pdo->commit();

            return ['applied' => true, 'results' => $results, 'errors' => []];
        } catch (\Throwable $e) {
            // All or nothing: a half-applied change set would leave content in a
            // state nobody reviewed.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[assistant] change set ' . $changeSet->id . ' failed: ' . $e->getMessage());

            return [
                'applied' => false,
                'results' => [],
                'errors'  => ['Applying the changes failed, so nothing was changed. ' . $e->getMessage()],
            ];
        }
    }

    // ----------------------------------------------------------------
    // Operations
    // ----------------------------------------------------------------

    private static function updateField(array $op, ChangeSet $changeSet, ToolContext $context): array
    {
        $entryId  = (int) $op['entry_id'];
        $fieldKey = (string) $op['field'];

        self::snapshot($entryId, $changeSet, $context, "Set {$fieldKey}");

        Meta::set($entryId, $fieldKey, $op['to']);

        return [
            'op'       => 'update_field',
            'entry_id' => $entryId,
            'field'    => $fieldKey,
            'from'     => $op['from'] ?? null,
            'to'       => $op['to'],
        ];
    }

    private static function updateCore(array $op, ChangeSet $changeSet, ToolContext $context): array
    {
        $entryId  = (int) $op['entry_id'];
        $fieldKey = (string) $op['field'];
        $entry    = Post::find($entryId);

        self::snapshot($entryId, $changeSet, $context, "Set {$fieldKey}");

        // Post::update() rewrites every column it knows about, so unspecified
        // fields must be carried over from the live row or they would be nulled.
        Post::update($entryId, [
            'title'             => $fieldKey === 'title'      ? (string) $op['to'] : $entry['title'],
            'slug'              => $fieldKey === 'slug'       ? (string) $op['to'] : $entry['slug'],
            'content'           => $fieldKey === 'content'    ? (string) $op['to'] : $entry['content'],
            'excerpt'           => $fieldKey === 'excerpt'    ? (string) $op['to'] : ($entry['excerpt'] ?? null),
            'status'            => $fieldKey === 'status'     ? (string) $op['to'] : $entry['status'],
            'menu_order'        => $fieldKey === 'menu_order' ? (int) $op['to']    : (int) $entry['menu_order'],
            'password_hash'     => $entry['password_hash'],
            'featured_image_id' => $entry['featured_image_id'],
            'published_at'      => $entry['published_at'],
        ]);

        return [
            'op'       => 'update_core',
            'entry_id' => $entryId,
            'field'    => $fieldKey,
            'from'     => $op['from'] ?? null,
            'to'       => $op['to'],
        ];
    }

    private static function createEntry(array $op, ToolContext $context): array
    {
        $typeKey = (string) $op['content_type'];
        $values  = (array) ($op['values'] ?? []);
        $title   = trim((string) $values['title']);

        $status = in_array($values['status'] ?? '', ChangeSet::STATUSES, true)
            ? (string) $values['status']
            : 'private'; // New content stays unpublished unless explicitly asked for.

        $entryId = Post::create([
            'post_type'    => $typeKey,
            'title'        => $title,
            'slug'         => Post::generateSlug($title),
            'content'      => (string) ($values['content'] ?? ''),
            // `excerpt` is a CORE field, so the meta loop below skips it — it
            // has to be written here or a proposed excerpt is silently dropped.
            'excerpt'      => $values['excerpt'] ?? null,
            'status'       => $status,
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
            'author_id'    => $context->userId,
        ]);

        $known = array_column(ContentTypeSchema::fields($typeKey), 'key');

        foreach ($values as $key => $value) {
            if (in_array($key, ChangeSet::CORE_FIELDS, true) || !in_array($key, $known, true)) {
                continue;
            }
            Meta::set($entryId, (string) $key, $value);
        }

        return [
            'op'           => 'create_entry',
            'entry_id'     => $entryId,
            'content_type' => $typeKey,
            'title'        => $title,
            'status'       => $status,
        ];
    }

    private static function trashEntry(array $op, ChangeSet $changeSet, ToolContext $context): array
    {
        $entryId = (int) $op['entry_id'];
        $entry   = Post::find($entryId);

        self::snapshot($entryId, $changeSet, $context, 'Moved to trash');

        Post::trash($entryId);

        return [
            'op'       => 'trash_entry',
            'entry_id' => $entryId,
            'title'    => $entry['title'] ?? null,
        ];
    }

    /**
     * Change one column on a static Page.
     *
     * `Page::update()` rewrites every column it knows about, so everything not
     * being changed is carried over from the live row — otherwise a copy edit
     * would quietly null the page's parent, menu order and featured image.
     */
    private static function updatePage(array $op, ChangeSet $changeSet, ToolContext $context): array
    {
        $pageId   = (int) $op['page_id'];
        $fieldKey = (string) $op['field'];
        $page     = Page::find($pageId);

        self::snapshot($pageId, $changeSet, $context, "Set {$fieldKey}", Revision::TYPE_PAGE);

        $data = [
            'parent_id'         => $page['parent_id'],
            'title'             => $page['title'],
            'slug'              => $page['slug'],
            'content'           => $page['content'],
            'menu_order'        => $page['menu_order'],
            'status'            => $page['status'],
            'password_hash'     => $page['password_hash'],
            'featured_image_id' => $page['featured_image_id'],
            'seo_title'         => $page['seo_title'],
            'meta_description'  => $page['meta_description'],
        ];

        $to = $op['to'] === null ? null : (string) $op['to'];
        // A TITLE THAT IS THE PATTERN IS STORED AS NONE — the rule the Pages
        // form's own save follows (`Head::ownTitle()`), so a change made here
        // cannot freeze today's pattern onto the page where the owner's save
        // would not. `to` below is what was STORED: apply_results records what
        // the applier did, not what the model asked for, and undo works from it.
        if ($fieldKey === 'seo_title' && $to !== null) {
            $to = \Cms\SEO\Head::ownTitle($to, 'page');
        }
        $data[$fieldKey] = $to;

        Page::update($pageId, $data);

        return [
            'op'      => 'update_page',
            'page_id' => $pageId,
            'title'   => $page['title'] ?? null,
            'field'   => $fieldKey,
            'from'    => $op['from'] ?? null,
            'to'      => $to,
        ];
    }
    /** Normalise whatever date the model wrote into what MySQL expects. */
    private static function datetime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $stamp = strtotime((string) $value);

        return $stamp === false ? null : date('Y-m-d H:i:s', $stamp);
    }

    // ----------------------------------------------------------------

    /**
     * Snapshot before writing, tagged with the change set that caused it.
     *
     * The revisions table is keyed by object_type + object_id, so pages and
     * entries share one undo mechanism — including undo of a whole change set.
     */
    private static function snapshot(
        int $objectId,
        ChangeSet $changeSet,
        ToolContext $context,
        string $note,
        string $objectType = Revision::TYPE_POST
    ): void {
        Revision::snapshot(
            $objectType,
            $objectId,
            $context->userId,
            'assistant',
            $note . ' — ' . $changeSet->summary,
            $changeSet->id
        );
        Revision::prune($objectType, $objectId);
    }
}
