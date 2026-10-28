<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Compat\OldNames;
use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Models\Meta;
use Cms\Models\Option;
use Cms\Models\Page;
use Cms\Models\Post;

/**
 * BRINGING CONTENT IN — the other half of `Api\ExportController`.
 *
 * A one-way export is half a feature.
 *
 * ── MATCHED BY SLUG. NEVER BY ID ────────────────────────────────────────────
 * The single most important rule in this file. An export carries the ids it had
 * on the machine it came from, and those ids mean something ELSE here — page 14
 * there is page 14 here, and it is a different page. Importing by id does not
 * fail loudly; it overwrites unrelated content and reports success.
 *
 * A slug is the thing a person recognises, the thing the address is made of, and
 * the thing two installs can genuinely agree on.
 *
 * ── PLAN FIRST, APPLY SECOND, AND A PERSON IN BETWEEN ───────────────────────
 * The assistant's safety model, applied to a file upload: `plan()` reads the
 * file and answers "here is what I would create and what I would overwrite",
 * touching nothing. `apply()` takes that plan back and does it. The screen shows
 * the plan and waits.
 *
 * An import that reads a file and starts writing is a destructive operation
 * with no confirm step, run against content the owner cannot get back.
 *
 * ── AND IT RECORDS WHAT IT DID, NOT WHAT IT WAS ASKED ───────────────────────
 * `apply()` returns per-row results, as the assistant's `apply_results` does: a
 * report built from the request describes an intention, and the one thing you
 * need after an import that went wrong is what actually happened.
 */
final class Import
{
    /** Rows an import may create. Anything else in the file is ignored, loudly. */
    public const KINDS = ['pages', 'content_types', 'settings'];

    /**
     * Read an export file and say what importing it would do. Writes nothing.
     *
     * @param  string $json the uploaded file's contents
     * @return array{ok: bool, error?: string, plan?: array<string, mixed>}
     */
    public static function plan(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'That file is not JSON. An export is a .json file downloaded from Tools → Import and export.'];
        }

        // THE MARKER IS CHECKED, and the failure names what was expected. A
        // stranger's JSON parsed as an empty plan would otherwise report
        // "nothing to import" — technically true, and useless.
        if (!self::marked($data)) {
            return ['ok' => false, 'error' => 'That JSON is not an export from Tools → Import and export — it has no `cms_export` marker. Exports come from the Download buttons above.'];
        }

        $plan = [
            'exported_at' => is_string($data['exported_at'] ?? null) ? $data['exported_at'] : null,
            'version'     => is_string($data['version'] ?? null) ? $data['version'] : null,
            'pages'       => [],
            'entries'     => [],
            'settings'    => [],
            'skipped'     => [],
        ];

        foreach (self::rowsOf($data, 'pages') as $row) {
            $slug = self::slugOf($row);
            if ($slug === '') {
                $plan['skipped'][] = ['what' => 'a page', 'why' => 'it has no slug, so there is nothing to match it on'];
                continue;
            }
            // ── A SLUG IN THE TRASH IS INVISIBLE AND STILL TAKEN ────────────
            // `findBySlug()` excludes `trash`; the UNIQUE index on `pages.slug`
            // does not. So a page the owner threw away last week is a slug the
            // matcher cannot see and the INSERT cannot have — and the import
            // would die on it with a raw SQLSTATE, roll the whole file back,
            // and tell the person nothing they could act on.
            $live = Page::findBySlug($slug);
            if ($live === null && Page::slugExists($slug)) {
                $plan['skipped'][] = [
                    'what' => sprintf('the page "%s"', (string) ($row['title'] ?? $slug)),
                    'why'  => sprintf('a page at /%s is in the trash — restore it to overwrite it, or empty the trash to import this as new', $slug),
                ];
                continue;
            }

            $plan['pages'][] = [
                'slug'   => $slug,
                'title'  => (string) ($row['title'] ?? $slug),
                'action' => $live === null ? 'create' : 'overwrite',
            ];
        }

        // ── AN EXPORT NAMES ITS TYPES; THIS INSTALL MAY NOT HAVE THEM ───────
        // The file carries `content_types[key].entries` and a label, and nothing
        // else about the type — no admin slug, no archetypes, no field groups.
        // So a type this install has never heard of cannot be created from it,
        // and the entries cannot be filed anywhere.
        //
        // SAID OUT LOUD, per type, with the count. Silently dropping half a file
        // is how an import "succeeds" and a third of a site goes missing.
        $types = $data['content_types'] ?? [];
        if (is_array($types)) {
            foreach ($types as $key => $block) {
                $key     = (string) $key;
                $entries = is_array($block) ? ($block['entries'] ?? []) : [];
                $entries = is_array($entries) ? $entries : [];

                if (Registry::get($key) === null) {
                    $plan['skipped'][] = [
                        'what' => sprintf('%d %s entr%s', count($entries),
                            is_array($block) && isset($block['label']) ? (string) $block['label'] : $key,
                            count($entries) === 1 ? 'y' : 'ies'),
                        'why'  => sprintf('this install has no content type called "%s" — make one first and import again', $key),
                    ];
                    continue;
                }

                foreach ($entries as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $slug = self::slugOf($row);
                    if ($slug === '') {
                        $plan['skipped'][] = ['what' => 'an entry in ' . $key, 'why' => 'it has no slug'];
                        continue;
                    }
                    // A SLUG IS UNIQUE ACROSS EVERY POST TYPE in this system —
                    // `Post::slugExists()` checks the whole table, and the
                    // public router looks a slug up without a type. So a row
                    // whose slug is already taken BY ANOTHER TYPE cannot be
                    // matched and must not be overwritten: that is the id
                    // collision this file exists to avoid, wearing a slug.
                    $held = Post::findBySlug($slug);
                    if ($held !== null && (string) $held['post_type'] !== $key) {
                        $plan['skipped'][] = [
                            'what' => sprintf('"%s" (%s)', (string) ($row['title'] ?? $slug), $key),
                            'why'  => sprintf('the address /%s is already taken by a %s here — rename one of them and import again',
                                $slug, (string) $held['post_type']),
                        ];
                        continue;
                    }

                    if ($held === null && Post::slugExists($slug)) {
                        $plan['skipped'][] = [
                            'what' => sprintf('"%s" (%s)', (string) ($row['title'] ?? $slug), $key),
                            'why'  => sprintf('an entry at /%s is in the trash — restore it to overwrite it, or empty the trash to import this as new', $slug),
                        ];
                        continue;
                    }

                    $plan['entries'][] = [
                        'type'   => $key,
                        'slug'   => $slug,
                        'title'  => (string) ($row['title'] ?? $slug),
                        'action' => $held === null ? 'create' : 'overwrite',
                    ];
                }
            }
        }

        // ── SETTINGS ARE THE DANGEROUS PART ─────────────────────────────────
        // An export's `settings` is the whole options table, and it includes the
        // site's own address, its currency and its mail configuration. Importing
        // those wholesale points this install at the other one's URLs.
        //
        // So they are listed one by one with their old and new values, and the
        // screen makes them a SEPARATE tick. Nobody gets the site's base address
        // rewritten as a side effect of importing a hundred news items.
        $settings = $data['settings'] ?? [];
        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                if (!is_string($key) || is_array($value)) {
                    continue;
                }
                $current = Option::get($key);
                if ((string) $value === (string) $current) {
                    continue;   // identical: not a change, so not in the plan
                }
                $plan['settings'][] = [
                    'key'  => $key,
                    'from' => $current === null ? null : (string) $current,
                    'to'   => (string) $value,
                ];
            }
        }

        return ['ok' => true, 'plan' => $plan];
    }

    /**
     * Do it, and report what was DONE.
     *
     * `$json` is read again rather than the plan being trusted to carry the
     * content: the plan is a summary for a person to read, and rebuilding the
     * rows from it would mean two parsers that can disagree.
     *
     * @param  bool  $withSettings the settings tick — off by default; see plan()
     * @return array{created: int, overwritten: int, settings: int, skipped: int, log: list<string>}
     */
    public static function apply(string $json, int $authorId, bool $withSettings = false): array
    {
        $data = json_decode($json, true);
        $out  = ['created' => 0, 'overwritten' => 0, 'settings' => 0, 'skipped' => 0, 'log' => []];

        if (!is_array($data) || !self::marked($data)) {
            $out['log'][] = 'The file was not an export from Tools → Import and export; nothing was changed.';
            return $out;
        }

        // ── ALL OF IT, OR NONE OF IT ────────────────────────────────────────
        // Without a transaction, a failure on row nineteen of twenty leaves
        // eighteen pages already overwritten: the person approved a plan for
        // twenty items and gets eighteen, with a 500 where the report should
        // have been — no way to tell which.
        //
        // A bulk overwrite must not be able to stop halfway. One transaction,
        // and a failure leaves the site exactly as the plan found it.
        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $out = self::run($data, $authorId, $withSettings, $out);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();

            return [
                'created' => 0, 'overwritten' => 0, 'settings' => 0, 'skipped' => 0,
                'log' => [
                    'Nothing was imported. The file failed part-way through and every '
                    . 'change was rolled back: ' . $e->getMessage(),
                ],
            ];
        }

        return $out;
    }

    /**
     * The import itself. Separate from `apply()` so the transaction that wraps
     * it has one thing to wrap and one place to roll back from.
     *
     * @param  array<string, mixed> $data
     * @param  array<string, mixed> $out
     * @return array<string, mixed>
     */
    private static function run(array $data, int $authorId, bool $withSettings, array $out): array
    {

        foreach (self::rowsOf($data, 'pages') as $row) {
            $slug = self::slugOf($row);
            if ($slug === '') {
                $out['skipped']++;
                continue;
            }

            $existing = Page::findBySlug($slug);
            if ($existing === null && Page::slugExists($slug)) {
                $out['skipped']++;
                $out['log'][] = 'skipped page /' . $slug . ' — that address is in the trash';
                continue;
            }

            $fields   = [
                'title'             => (string) ($row['title'] ?? $slug),
                'slug'              => $slug,
                'content'           => (string) ($row['content'] ?? ''),
                'menu_order'        => (int) ($row['menu_order'] ?? 0),
                // AN IMPORTED PAGE LANDS `private`, whatever the file said.
                // Every other route into this system defaults there for the same
                // reason: the failure has to land on the state that keeps a page
                // OFF the public site. A file from elsewhere publishing fifty
                // pages the moment it is uploaded is not a recoverable mistake.
                'status'            => 'private',
                'seo_title'         => $row['seo_title'] ?? null,
                'meta_description'  => $row['meta_description'] ?? null,
                // NOT `parent_id`, NOT `featured_image_id`, NOT `author_id`.
                // Every one of them is an id from another install: a parent that
                // is a different page here, an attachment that is a different
                // file, an author who is a different person.
            ];

            if ($existing === null) {
                // `author_id` IS NOT NULLABLE on `pages`, and the importer owns
                // what they import — the id in the file is a user on another
                // install. Omitting it fails the INSERT.
                Page::create($fields + [
                    'parent_id'         => null,
                    'featured_image_id' => null,
                    'author_id'         => $authorId,
                ]);
                $out['created']++;
                $out['log'][] = 'created page /' . $slug;
            } else {
                Page::update((int) $existing['id'], $fields + [
                    'parent_id'         => $existing['parent_id'],
                    'featured_image_id' => $existing['featured_image_id'],
                    // NO `author_id` HERE, and it would be ignored if there
                    // were: `Page::update()` does not name that column, so an
                    // overwrite leaves the page with the author it had. Which is
                    // right — an import is not a change of authorship — and is
                    // stated because the create path three lines up MUST pass
                    // one, and the asymmetry otherwise reads as an oversight.
                ]);
                $out['overwritten']++;
                $out['log'][] = 'overwrote page /' . $slug;
            }
        }

        $types = $data['content_types'] ?? [];
        foreach (is_array($types) ? $types : [] as $key => $block) {
            $key = (string) $key;
            if (Registry::get($key) === null) {
                $entries = is_array($block) ? ($block['entries'] ?? []) : [];
                $out['skipped'] += is_array($entries) ? count($entries) : 0;
                $out['log'][]    = 'skipped everything for "' . $key . '" — no such content type here';
                continue;
            }

            $entries = is_array($block) ? ($block['entries'] ?? []) : [];
            foreach (is_array($entries) ? $entries : [] as $row) {
                if (!is_array($row)) {
                    $out['skipped']++;
                    continue;
                }
                $slug = self::slugOf($row);
                if ($slug === '') {
                    $out['skipped']++;
                    continue;
                }

                $existing = Post::findBySlug($slug);
                if ($existing === null && Post::slugExists($slug)) {
                    $out['skipped']++;
                    $out['log'][] = 'skipped /' . $slug . ' — that address is in the trash';
                    continue;
                }
                if ($existing !== null && (string) $existing['post_type'] !== $key) {
                    // The plan said so and said why; this is the same refusal
                    // at the moment it would have done the damage.
                    $out['skipped']++;
                    $out['log'][] = 'skipped /' . $slug . ' — that address belongs to a '
                                  . (string) $existing['post_type'] . ' here';
                    continue;
                }
                $fields   = [
                    'post_type'    => $key,
                    'title'        => (string) ($row['title'] ?? $slug),
                    'slug'         => $slug,
                    'content'      => (string) ($row['content'] ?? ''),
                    'status'       => 'private',
                    // THE IMPORTER OWNS IT. `author_id` in the file is a user on
                    // another install; carrying it over either points at a
                    // stranger or at nobody, and `posts.author_id` is NOT NULL.
                    'author_id'    => $authorId,
                ];

                // ── WHAT THE FILE DOES NOT SAY, THE OVERWRITE DOES NOT TOUCH ──
                // `menu_order` is set only when the file has one. An export does
                // not write one, so a `?? 0` default would reset a hand-arranged
                // list on every re-import — and adding the entry's own order
                // back with `+ ['menu_order' => …]` would not save it, because
                // an array union keeps the LEFT key. Post::update() writes only
                // the keys it is given, so a key that is absent here is a value
                // that stays.
                if (array_key_exists('menu_order', $row)) {
                    $fields['menu_order'] = (int) $row['menu_order'];
                }
                // Exports carry the excerpt from migration 053 on; an older
                // file has no such key and leaves the entry's own alone.
                if (array_key_exists('excerpt', $row)) {
                    $fields['excerpt'] = $row['excerpt'];
                }

                if ($existing === null) {
                    $id = Post::create($fields);
                    $out['created']++;
                    $out['log'][] = 'created ' . $key . ' /' . $slug;
                } else {
                    $id = (int) $existing['id'];
                    Post::update($id, $fields);
                    $out['overwritten']++;
                    $out['log'][] = 'overwrote ' . $key . ' /' . $slug;
                }

                // THE FIELDS AN ARCHETYPE DECLARES come across, because they are
                // the entry — a Team member without their role is a name. Keys
                // are taken as they are; a field this install does not declare
                // sits in `post_meta` unread rather than being dropped, which is
                // what happens to any meta whose field group is removed.
                $meta = $row['meta'] ?? [];
                if (is_array($meta)) {
                    foreach ($meta as $mk => $mv) {
                        if (is_string($mk) && !is_array($mv)) {
                            Meta::set($id, $mk, (string) $mv);
                        }
                    }
                }
            }
        }

        if ($withSettings) {
            $settings = $data['settings'] ?? [];
            foreach (is_array($settings) ? $settings : [] as $k => $v) {
                if (is_string($k) && !is_array($v)) {
                    Option::set($k, (string) $v);
                    $out['settings']++;
                }
            }
            $out['log'][] = 'wrote ' . $out['settings'] . ' setting(s)';
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    /**
     * Is this an export? It says `cms_export`, or — made before 0.7.0 —
     * the marker it had then (Compat\OldNames::EXPORT_MARKER), accepted for good.
     */
    private static function marked(array $data): bool
    {
        return ($data['cms_export'] ?? false) === true || ($data[OldNames::EXPORT_MARKER] ?? false) === true;
    }

    private static function rowsOf(array $data, string $key): array
    {
        $rows = $data[$key] ?? [];

        return is_array($rows)
            ? array_values(array_filter($rows, 'is_array'))
            : [];
    }

    private static function slugOf(array $row): string
    {
        return self::normaliseSlug((string) ($row['slug'] ?? ''));
    }

    /**
     * The slug a row is matched on, normalised the way a slug field is.
     *
     * A file hand-edited between export and import is the normal case — that is
     * most of what an import is FOR — so `About Us` in the slug column becomes
     * `about-us` rather than creating a row nothing can ever reach.
     *
     * PUBLIC, AND NEEDS NO DATABASE. `plan()` has to ask whether a slug is
     * taken, so it cannot run without one; this is the part of it that is
     * arithmetic, split out so it can be checked on its own.
     */
    public static function normaliseSlug(string $raw): string
    {
        $slug = strtolower(trim($raw));

        // A PASTED ADDRESS IS A SLUG WITH FURNITURE ON IT. `/news/autumn-agm`
        // and `https://old-site.test/news/autumn-agm` are both things a person
        // types into a hand-edited export, and the last segment is what they
        // meant. Taking the whole string would make a slug no route can reach.
        $slug = (string) preg_replace('#^[a-z]+://[^/]+#', '', $slug);
        $slug = explode('?', $slug)[0];
        $slug = explode('#', $slug)[0];
        $slug = trim($slug, '/');
        if (str_contains($slug, '/')) {
            $parts = array_filter(explode('/', $slug));
            $slug  = (string) end($parts);
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}
