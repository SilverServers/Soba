<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Models\Media;
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
            // WHAT THE TWO TICKS WOULD KEEP (`apply()`): rows that were not
            // Private there, and rows with a featured image, of which so many
            // are in this library.
            'kept'        => ['status' => 0, 'images' => 0, 'found' => 0],
        ];
        $kept = static function (array $row) use (&$plan): void {
            if (self::statusOf($row)[0] !== 'private') {
                $plan['kept']['status']++;
            }
            if (($image = self::imageIn($row)) !== null) {
                $plan['kept']['images']++;
                $plan['kept']['found'] += Media::findDescribed($image) !== null ? 1 : 0;
            }
        };

        foreach (self::rowsOf($data, 'pages') as $row) {
            $slug = self::slugOf($row);
            if ($slug === '') {
                $plan['skipped'][] = ['what' => 'a page', 'why' => 'it has no slug, so there is nothing to match it on'];
                continue;
            }
            // A TRANSLATION IS MATCHED IN ITS OWN LANGUAGE (`langOf()`).
            $lang = self::langOf($row);
            if ($lang === null) {
                $plan['skipped'][] = [
                    'what' => sprintf('the page "%s"', (string) ($row['title'] ?? $slug)),
                    'why'  => 'its language is not one an address can hold',
                ];
                continue;
            }
            // ── A SLUG IN THE TRASH IS INVISIBLE AND STILL TAKEN ────────────
            // `findBySlug()` excludes `trash`; the UNIQUE index on `pages.slug`
            // does not. So a page the owner threw away last week is a slug the
            // matcher cannot see and the INSERT cannot have — and the import
            // would die on it with a raw SQLSTATE, roll the whole file back,
            // and tell the person nothing they could act on.
            $live = Page::findBySlug($slug, $lang);
            if ($live === null && Page::slugExists($slug, null, $lang)) {
                $plan['skipped'][] = [
                    'what' => sprintf('the page "%s"', (string) ($row['title'] ?? $slug)),
                    'why'  => sprintf('a page at /%s is in the trash — restore it to overwrite it, or empty the trash to import this as new', self::at($slug, $lang)),
                ];
                continue;
            }

            $plan['pages'][] = [
                'slug'   => self::at($slug, $lang),
                'title'  => (string) ($row['title'] ?? $slug),
                'action' => $live === null ? 'create' : 'overwrite',
                'detail' => self::said($row),
            ];
            $kept($row);
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
                    $lang = self::langOf($row);
                    if ($lang === null) {
                        $plan['skipped'][] = [
                            'what' => sprintf('"%s" (%s)', (string) ($row['title'] ?? $slug), $key),
                            'why'  => 'its language is not one an address can hold',
                        ];
                        continue;
                    }
                    // A SLUG IS UNIQUE ACROSS EVERY POST TYPE in this system —
                    // `Post::slugExists()` checks the whole table, and the
                    // public router looks a slug up without a type. So a row
                    // whose slug is already taken BY ANOTHER TYPE cannot be
                    // matched and must not be overwritten: that is the id
                    // collision this file exists to avoid, wearing a slug.
                    $held = Post::findBySlug($slug, $lang);
                    if ($held !== null && (string) $held['post_type'] !== $key) {
                        $plan['skipped'][] = [
                            'what' => sprintf('"%s" (%s)', (string) ($row['title'] ?? $slug), $key),
                            'why'  => sprintf('the address /%s is already taken by a %s here — rename one of them and import again',
                                self::at($slug, $lang), (string) $held['post_type']),
                        ];
                        continue;
                    }

                    if ($held === null && Post::slugExists($slug, null, $lang)) {
                        $plan['skipped'][] = [
                            'what' => sprintf('"%s" (%s)', (string) ($row['title'] ?? $slug), $key),
                            'why'  => sprintf('an entry at /%s is in the trash — restore it to overwrite it, or empty the trash to import this as new', self::at($slug, $lang)),
                        ];
                        continue;
                    }

                    $refusal = self::skuRefusal($key, $row, $held !== null ? (int) $held['id'] : null);
                    if ($refusal !== '') {
                        $plan['skipped'][] = [
                            'what' => sprintf('"%s" (%s)', (string) ($row['title'] ?? $slug), $key),
                            'why'  => $refusal,
                        ];
                        continue;
                    }

                    $plan['entries'][] = [
                        'type'   => $key,
                        'slug'   => self::at($slug, $lang),
                        'title'  => (string) ($row['title'] ?? $slug),
                        'action' => $held === null ? 'create' : 'overwrite',
                        'detail' => self::said($row),
                    ];
                    $kept($row);
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
     * @param  bool  $keepStatus   "Publish what was published there": each row's status, its
     *                             password and an entry's date as the file has them
     *                             (`statusOf()`); unticked, everything arrives Private
     * @param  bool  $findImages   "Give each its featured image from the library": found by
     *                             its contents or its name (`Media::findDescribed()`)
     * @return array{created: int, overwritten: int, settings: int, skipped: int, log: list<string>}
     */
    public static function apply(string $json, int $authorId, bool $withSettings = false, bool $keepStatus = false, bool $findImages = false): array
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
            $out = self::run($data, $authorId, $withSettings, $out, $keepStatus, $findImages);
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
    private static function run(array $data, int $authorId, bool $withSettings, array $out, bool $keepStatus = false, bool $findImages = false): array
    {
        // THE FEATURED IMAGE THE FILE NAMES, found here when asked; a row whose
        // picture is not in this library comes without one, and the report
        // says which.
        $picture = static function (array $row, string $what) use ($findImages, &$out): ?int {
            $image = $findImages ? self::imageIn($row) : null;
            if ($image === null) {
                return null;
            }
            $id = Media::findDescribed($image);
            if ($id === null) {
                $out['log'][] = 'no featured image for ' . $what . ': ' . self::imageName($image) . ' is not in the library';
            }
            return $id;
        };

        // EACH TRANSLATION, linked to its original once every row is in
        // (`Content\Translation`): table, id, language, the original's slug.
        $links = [];

        foreach (self::rowsOf($data, 'pages') as $row) {
            $slug = self::slugOf($row);
            $lang = self::langOf($row);
            if ($slug === '' || $lang === null) {
                $out['skipped']++;
                continue;
            }

            $existing = Page::findBySlug($slug, $lang);
            if ($existing === null && Page::slugExists($slug, null, $lang)) {
                $out['skipped']++;
                $out['log'][] = 'skipped page /' . self::at($slug, $lang) . ' — that address is in the trash';
                continue;
            }

            $fields   = [
                'title'             => (string) ($row['title'] ?? $slug),
                'slug'              => $slug,
                'content'           => (string) ($row['content'] ?? ''),
                'menu_order'        => (int) ($row['menu_order'] ?? 0),
                // AN IMPORTED PAGE LANDS `private`, whatever the file said,
                // unless the person ticks "Publish what was published there"
                // (below). Every other route into this system defaults there for
                // the same reason: the failure has to land on the state that
                // keeps a page OFF the public site. A file from elsewhere
                // publishing fifty pages the moment it is uploaded, unasked, is
                // not a recoverable mistake.
                'status'            => 'private',
                'seo_title'         => $row['seo_title'] ?? null,
                'meta_description'  => $row['meta_description'] ?? null,
                // NOT `parent_id`, NOT `featured_image_id`, NOT `author_id`.
                // Every one of them is an id from another install: a parent that
                // is a different page here, an attachment that is a different
                // file, an author who is a different person. The featured image
                // comes by its file instead, when asked (`Media::findDescribed()`).
            ];
            // KEPT AS IT WAS THERE, when the person asked: published, or behind
            // its password, which comes as its hash (`statusOf()`).
            if ($keepStatus) {
                [$fields['status'], $hash] = self::statusOf($row);
                if ($hash !== null) {
                    $fields['password_hash'] = $hash;
                }
            }
            $image = $picture($row, 'page /' . $slug);

            if ($existing === null) {
                // `author_id` IS NOT NULLABLE on `pages`, and the importer owns
                // what they import — the id in the file is a user on another
                // install. Omitting it fails the INSERT.
                $pageId = Page::create($fields + [
                    'parent_id'         => null,
                    'featured_image_id' => $image,
                    'author_id'         => $authorId,
                    'lang'              => $lang,
                ]);
                $out['created']++;
                $out['log'][] = 'created page /' . self::at($slug, $lang);
            } else {
                $pageId = (int) $existing['id'];
                Page::update((int) $existing['id'], $fields + [
                    'parent_id'         => $existing['parent_id'],
                    'featured_image_id' => $image ?? $existing['featured_image_id'],
                    // NO `author_id` HERE, and it would be ignored if there
                    // were: `Page::update()` does not name that column, so an
                    // overwrite leaves the page with the author it had. Which is
                    // right — an import is not a change of authorship — and is
                    // stated because the create path three lines up MUST pass
                    // one, and the asymmetry otherwise reads as an oversight.
                ]);
                $out['overwritten']++;
                $out['log'][] = 'overwrote page /' . self::at($slug, $lang);
            }
            if ($lang !== '' && is_string($row['translates'] ?? null) && $row['translates'] !== '') {
                $links[] = ['pages', $pageId, $lang, (string) $row['translates']];
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
                $lang = self::langOf($row);
                if ($slug === '' || $lang === null) {
                    $out['skipped']++;
                    continue;
                }

                $existing = Post::findBySlug($slug, $lang);
                if ($existing === null && Post::slugExists($slug, null, $lang)) {
                    $out['skipped']++;
                    $out['log'][] = 'skipped /' . self::at($slug, $lang) . ' — that address is in the trash';
                    continue;
                }
                if ($existing !== null && (string) $existing['post_type'] !== $key) {
                    // The plan said so and said why; this is the same refusal
                    // at the moment it would have done the damage.
                    $out['skipped']++;
                    $out['log'][] = 'skipped /' . self::at($slug, $lang) . ' — that address belongs to a '
                                  . (string) $existing['post_type'] . ' here';
                    continue;
                }
                $refusal = self::skuRefusal($key, $row, $existing !== null ? (int) $existing['id'] : null);
                if ($refusal !== '') {
                    $out['skipped']++;
                    $out['log'][] = 'skipped /' . $slug . ' — ' . $refusal;
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
                // KEPT AS IT WAS THERE, when the person asked: its status, its
                // password's hash, and the date it was published or is to be;
                // a file with no date leaves an overwritten entry's own, and a
                // new one is dated now, as publishing it here would.
                if ($keepStatus) {
                    [$fields['status'], $hash] = self::statusOf($row);
                    if ($hash !== null) {
                        $fields['password_hash'] = $hash;
                    }
                    if ($fields['status'] !== 'private' && ($at = self::utcOf($row['published_at'] ?? null)) !== null) {
                        $fields['published_at'] = $at;
                    }
                }
                if (($image = $picture($row, $key . ' /' . $slug)) !== null) {
                    $fields['featured_image_id'] = $image;
                }

                if ($existing === null) {
                    $id = Post::create($fields + ['lang' => $lang]);
                    $out['created']++;
                    $out['log'][] = 'created ' . $key . ' /' . self::at($slug, $lang);
                } else {
                    $id = (int) $existing['id'];
                    Post::update($id, $fields);
                    $out['overwritten']++;
                    $out['log'][] = 'overwrote ' . $key . ' /' . self::at($slug, $lang);
                }
                if ($lang !== '' && is_string($row['translates'] ?? null) && $row['translates'] !== '') {
                    $links[] = ['posts', $id, $lang, (string) $row['translates']];
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

                // ── ITS CATEGORIES, MATCHED BY SLUG ───────────────────────────
                // Exported all along and never read back, so a site restored
                // from its own file came back with nothing filed anywhere. FIRST,
                // because an option's size and colour are terms too, and are
                // looked up by slug below once these have made any this install
                // lacked.
                if (is_array($row['terms'] ?? null)) {
                    foreach (self::importTerms($id, $key, $slug, $row['terms']) as $line) {
                        $out['log'][] = $line;
                    }
                }

                // ── A PRODUCT'S OPTIONS, MATCHED BY LABEL ─────────────────────
                // The file is the truth for an entry it overwrites, as it is for
                // the entry's fields above — counts included, and an option the
                // file does not list goes. Matched by label rather than created
                // afresh, so an option kept across a re-import keeps its row and
                // the order lines that point at it. A file with no `options`
                // (made before they were exported) leaves them alone: what the
                // file does not say, the overwrite does not touch.
                if (is_array($row['options'] ?? null) && \Cms\Commerce\Product::isSellableType($key)) {
                    \Cms\Commerce\Variation::sync($id, self::optionRows($id, $row['options']));
                }
            }
        }

        // ── EACH TRANSLATION LINKED TO ITS ORIGINAL, by the original's slug in
        // the site's own language, now that every row is here: an original
        // that is not, or that already has a translation in that language,
        // leaves it unlinked, and the report says so.
        foreach ($links as [$table, $rowId, $lang, $of]) {
            $note = \Cms\Content\Translation::link($table, $rowId, $of);
            if ($note !== null) {
                $out['log'][] = 'not linked: /' . $lang . '/… to /' . $of . ' — ' . $note;
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

    /**
     * HOW A ROW WAS THERE, AS IT CAN BE HERE: published, behind a password with
     * the password's hash the file carries, or Private. A row behind a
     * password whose file carries no usable hash (a file made before exports
     * carried one) is Private: kept behind a password nobody knows, it could
     * never be opened.
     *
     * @param  array<string, mixed> $row
     * @return array{0: string, 1: ?string} the status, and the hash for 'password'
     */
    private static function statusOf(array $row): array
    {
        $hash = is_string($row['password_hash'] ?? null) && password_get_info($row['password_hash'])['algo'] !== null
            ? $row['password_hash'] : null;

        return match ((string) ($row['status'] ?? '')) {
            'published' => ['published', null],
            'password'  => $hash !== null ? ['password', $hash] : ['private', null],
            default     => ['private', null],
        };
    }

    /**
     * What the plan says of a row, beside it: how it was there, published and
     * when, scheduled, behind a password or private, and whether its featured
     * image is in this library. Said whether or not the ticks are ticked, so
     * the person ticking one knows what it would keep.
     *
     * @param array<string, mixed> $row
     */
    private static function said(array $row): string
    {
        $at     = self::utcOf($row['published_at'] ?? null);
        $status = (string) ($row['status'] ?? '');
        $parts  = [match (true) {
            $status === 'published' && $at !== null && $at > gmdate('Y-m-d H:i:s') => 'scheduled there for ' . \Cms\Helpers\Dates::short($at),
            $status === 'published' => 'published there' . ($at !== null ? ', ' . \Cms\Helpers\Dates::short($at) : ''),
            $status === 'password'  => self::statusOf($row)[0] === 'password' ? 'behind a password there' : 'behind a password there that the file does not carry',
            $status !== ''          => 'private there',
            default                 => '',
        }];
        if (($image = self::imageIn($row)) !== null) {
            $parts[] = Media::findDescribed($image) !== null ? 'its featured image is in the library' : 'its featured image is not in the library';
        }

        return implode(' · ', array_filter($parts, 'strlen'));
    }

    /** A date as the export writes it, UTC 'Y-m-d H:i:s', or null for anything else. */
    private static function utcOf(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d H:i:s') === $text ? $text : null;
    }

    /**
     * The row's featured image as the file describes it, when it describes one
     * with something to find it by.
     *
     * @param  array<string, mixed> $row
     * @return array{name: string, file: string, size: int, sha256: string}|null
     */
    private static function imageIn(array $row): ?array
    {
        $image = $row['featured_image'] ?? null;
        if (!is_array($image)) {
            return null;
        }
        $found = [
            'name'   => trim((string) ($image['name'] ?? '')),
            'file'   => basename(trim((string) ($image['file'] ?? ''))),
            'size'   => (int) ($image['size'] ?? 0),
            'sha256' => strtolower(trim((string) ($image['sha256'] ?? ''))),
        ];

        return $found['name'] !== '' || $found['file'] !== '' || $found['sha256'] !== '' ? $found : null;
    }

    /** The picture's name, for the report. @param array{name: string, file: string} $image */
    private static function imageName(array $image): string
    {
        return $image['name'] !== '' ? $image['name'] : ($image['file'] !== '' ? $image['file'] : 'its picture');
    }

    /**
     * Why this entry's SKUs cannot come in, or ''. The same rule the entry form
     * applies (`Product::skuProblems()`): an import is a third way to write a
     * SKU, and a rule only one door enforces is not a rule.
     */
    private static function skuRefusal(string $key, array $row, ?int $existingId): string
    {
        if (!\Cms\Commerce\Product::isSellableType($key)) {
            return '';
        }
        $meta    = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $options = is_array($row['options'] ?? null) ? $row['options'] : [];
        $sku     = is_array($meta[\Cms\Commerce\Product::SKU] ?? null) ? '' : (string) ($meta[\Cms\Commerce\Product::SKU] ?? '');

        return \Cms\Commerce\Product::skuProblems($sku, $options, $existingId)[0] ?? '';
    }

    /**
     * An export's options as `Variation::sync()` takes them, each carrying the
     * id of this product's own option of the same label, if it has one.
     *
     * @param  array<int|string, mixed> $options
     * @return list<array<string, string|int>>
     */
    private static function optionRows(int $postId, array $options): array
    {
        $byLabel = [];
        foreach (\Cms\Commerce\Variation::forProduct($postId) as $v) {
            $byLabel[mb_strtolower((string) $v['label'])] = (int) $v['id'];
        }

        $rows = [];
        foreach ($options as $o) {
            if (!is_array($o) || trim((string) ($o['label'] ?? '')) === '') {
                continue;
            }
            $label  = trim((string) $o['label']);
            $rows[] = [
                'id'           => $byLabel[mb_strtolower($label)] ?? 0,
                'label'        => $label,
                'sku'          => (string) ($o['sku'] ?? ''),
                'price'        => isset($o['price']) && !is_array($o['price']) ? (string) $o['price'] : '',
                'sale_price'   => isset($o['sale_price']) && !is_array($o['sale_price']) ? (string) $o['sale_price'] : '',
                // ITS SIZE AND COLOUR, from slugs back to this install's ids.
                // `Variation::sync()` keeps only option taxonomies' terms.
                'terms'        => self::termIds(is_array($o['terms'] ?? null) ? $o['terms'] : []),
                'manage_stock' => !empty($o['manage_stock']) ? '1' : '',
                'stock_qty'    => (string) (int) ($o['stock_qty'] ?? 0),
                'stock_status' => (string) ($o['stock_status'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Taxonomy key => slug, as an export writes an option's terms, read back as
     * taxonomy key => this install's term id. A slug with no term here is left
     * out: the option is still imported, made of what can be matched.
     *
     * @param  array<int|string, mixed> $bySlug
     * @return array<string, int>
     */
    private static function termIds(array $bySlug): array
    {
        $out = [];
        foreach ($bySlug as $tax => $slug) {
            if (!is_string($tax) || !is_string($slug)) {
                continue;
            }
            $term = \Cms\Models\Term::findBySlug($tax, $slug);
            if ($term !== null) {
                $out[$tax] = (int) $term['id'];
            }
        }

        return $out;
    }

    /**
     * File an imported entry under the terms its export names, making any this
     * install lacks. Only taxonomies this content type has; a set the taxonomy's
     * own rule refuses is left as it was, and said.
     *
     * @param  array<int|string, mixed> $terms
     * @return list<string> lines for the import's log
     */
    private static function importTerms(int $postId, string $typeKey, string $slug, array $terms): array
    {
        $byTaxonomy = [];
        foreach ($terms as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tax  = (string) ($t['taxonomy_key'] ?? '');
            $term = trim((string) ($t['slug'] ?? ''));
            if ($tax !== '' && $term !== '') {
                $name = trim((string) ($t['name'] ?? ''));
                $byTaxonomy[$tax][$term] = $name !== '' ? $name : $term;
            }
        }

        $log  = [];
        $here = \Cms\ContentType\TaxonomyRegistry::getForContentType($typeKey);
        foreach ($byTaxonomy as $tax => $named) {
            if (!isset($here[$tax])) {
                $log[] = 'left /' . $slug . ' out of "' . $tax . '" — that taxonomy is not on ' . $typeKey . ' here';
                continue;
            }
            $ids = [];
            foreach ($named as $termSlug => $name) {
                $found = \Cms\Models\Term::findBySlug($tax, (string) $termSlug);
                $ids[] = $found !== null ? (int) $found['id'] : \Cms\Models\Term::create([
                    'taxonomy_key' => $tax, 'name' => $name, 'slug' => (string) $termSlug,
                    'parent_id' => null, 'sort_order' => 0,
                ]);
            }
            try {
                \Cms\Models\Term::setPostTerms($postId, $tax, $ids);
            } catch (\DomainException $e) {
                $log[] = 'kept /' . $slug . '\'s own ' . $tax . ' — ' . $e->getMessage();
            }
        }

        return $log;
    }

    /**
     * THE LANGUAGE A ROW IS IN (`Site\Language`): '' the site's own, which a
     * file with no `lang` says; a translation's prefix (`fr`); or null for a
     * value an address cannot hold, which is skipped.
     *
     * @param array<string, mixed> $row
     */
    private static function langOf(array $row): ?string
    {
        $lang = $row['lang'] ?? '';
        if (!is_string($lang)) {
            return null;
        }

        return preg_match(\Cms\Site\Language::PREFIX, $lang) === 1 ? $lang : null;
    }

    /** A row's address as the report writes it: `about`, or `fr/about` for a translation. */
    private static function at(string $slug, string $lang): string
    {
        return ($lang !== '' ? $lang . '/' : '') . $slug;
    }

    /** @return list<array<string, mixed>> */
    /** Is this an export? It says `cms_export`. */
    private static function marked(array $data): bool
    {
        return ($data['cms_export'] ?? false) === true;
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
