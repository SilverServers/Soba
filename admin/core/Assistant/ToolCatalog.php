<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\ContentType\ContentTypeSchema;
use Cms\ContentType\Registry;
use Cms\Models\Media;
use Cms\Models\Meta;
use Cms\Models\Page;
use Cms\Models\Post;
use Cms\Models\Term;

/**
 * The tools the Assistant can call.
 *
 * Transport-agnostic on purpose. These tools run in the same PHP process as the
 * data, so the in-process path needs no network hop and no second authentication
 * system. Exposing the same catalog over MCP later — for Claude Desktop or another
 * agent — is then an adapter, not a rewrite.
 *
 * **Every tool here is read-only.** v1 defines no write tool at all: not gated,
 * absent. The assistant reads, then proposes a change set; ordinary PHP applies it
 * after a human approves. That keeps prompt injection from ever being a write
 * primitive, which matters because CMS content (form submissions, imported copy)
 * is untrusted input that lands in the model's context.
 */
final class ToolCatalog
{
    /**
     * Every tool, keyed by name.
     *
     * @return array<string, Tool>
     */
    public static function all(): array
    {
        $tools = [
            self::viewPage(),
            self::findText(),
            self::listContentTypes(),
            self::describeContentType(),
            self::searchEntries(),
            self::readEntry(),
            self::listPages(),
            self::readPage(),
            // THE SHOP'S, where there is one (`ShopTools`).
            ...ShopTools::all(),
        ];

        $keyed = [];
        foreach ($tools as $tool) {
            $keyed[$tool->name] = $tool;
        }

        return $keyed;
    }

    /**
     * Tools the given user is allowed to call.
     *
     * @return array<string, Tool>
     */
    public static function forContext(ToolContext $context): array
    {
        return array_filter(
            self::all(),
            static fn (Tool $tool): bool => $context->hasRole($tool->minimumRole)
        );
    }

    /** Look up one tool by name. Null if it does not exist. */
    public static function get(string $name): ?Tool
    {
        return self::all()[$name] ?? null;
    }

    /**
     * Dispatch a tool call.
     *
     * Unknown names and insufficient permissions come back as tool results rather
     * than exceptions — the model should see the refusal and adjust, not have the
     * conversation aborted.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function call(string $name, array $input, ToolContext $context): array
    {
        $tool = self::get($name);

        if ($tool === null) {
            return ['error' => "Unknown tool '{$name}'."];
        }

        if (!$context->hasRole($tool->minimumRole)) {
            return ['error' => "You do not have permission to use '{$name}'."];
        }

        try {
            return $tool->run($input, $context);
        } catch (\Throwable $e) {
            // Never leak a stack trace or SQL into the model's context.
            error_log('[assistant] tool ' . $name . ' failed: ' . $e->getMessage());
            return ['error' => 'That lookup failed. Try a narrower query.'];
        }
    }


    /**
     * What the owner should be told a tool is, given its stored name.
     *
     * `tools_used` stores the raw names because those are the data; this turns
     * them into something a person reads. Unknown names — a tool removed since a
     * conversation happened — degrade to a de-underscored version rather than
     * breaking an old transcript.
     */
    public static function label(string $name): string
    {
        $tool = self::get($name);

        if ($tool !== null) {
            return $tool->label();
        }

        // propose_changes lives on the runner, not in this catalog.
        if ($name === AssistantRunner::PROPOSE_TOOL) {
            return 'Writing up the change';
        }

        return ucfirst(str_replace('_', ' ', $name));
    }
    // ----------------------------------------------------------------
    // Tools
    // ----------------------------------------------------------------

    // ----------------------------------------------------------------
    // Seeing the site the way its owner does
    //
    // Every request a site owner makes is positional and visual — "the opening
    // message", "the button at the top", "swap those two cards". Answering from
    // stored data alone means guessing which of six storage layers produced the
    // thing on screen, and that guessing is what caused every wrong answer this
    // feature has given. These two replace the guessing: look at the page, then
    // look up where the words live.
    // ----------------------------------------------------------------

    /** What a visitor actually sees, in order. */
    private static function viewPage(): Tool
    {
        return new Tool(
            name: 'view_page',
            label: 'Looking at your page',
            description:
                'See a page the way a visitor does: every piece of visible text in the order it '
                . 'appears, tagged as a heading, link, button or paragraph, and grouped into the '
                . 'header, the page itself, and the footer. **Start here whenever someone describes '
                . 'something by where it is or what it looks like** — "the opening message", "the '
                . 'button at the top", "the first thing I see", "those two cards". Then use '
                . 'find_text on the exact words to find out what to change.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'path' => [
                        'type'        => 'string',
                        'description' => 'Page path, e.g. "/" for the home page, "/news", "/about".',
                    ],
                ],
                'required'             => ['path'],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                return PageView::of((string) $input['path']);
            },
            minimumRole: 'editor',
        );
    }

    /** Where a given piece of visible text actually lives. */
    private static function findText(): Tool
    {
        return new Tool(
            name: 'find_text',
            label: 'Finding where those words come from',
            description:
                'Find where a piece of visible text comes from — a content entry, a page, a page '
                . 'setting, text that appears on every page, a block, or the page layout. Returns '
                . 'every place it occurs and, for each, exactly which operation would change it. '
                . 'Use this rather than guessing which of those to look in.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'text' => [
                        'type'        => 'string',
                        'description' => 'The words as they appear on the page.',
                    ],
                ],
                'required'             => ['text'],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $hits = SiteSearch::find((string) $input['text'], $ctx);

                return $hits === []
                    ? [
                        'matches' => [],
                        'note'    => 'Those exact words are nowhere on the site. Check the wording with '
                                   . 'view_page — the owner may be paraphrasing what they see.',
                      ]
                    : ['matches' => $hits];
            },
            minimumRole: 'editor',
        );
    }

    /** What kinds of content does this site have? Always the first call. */
    private static function listContentTypes(): Tool
    {
        return new Tool(
            name: 'list_content_types',
            label: 'Checking what kinds of content you have',
            description:
                'List every content type on this site, with how many published entries each has. '
                . 'Call this first to find out what kinds of content exist before searching or reading. '
                . 'Returns the type key needed by the other tools.',
            inputSchema: [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'required'             => [],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $types = [];

                foreach (Registry::all() as $key => $definition) {
                    $entries = Post::all($key, $ctx->authorFilter());

                    $types[] = [
                        'key'         => $key,
                        'label'       => $definition['label'],
                        'singular'    => $definition['label_single'],
                        'archetypes'  => $definition['archetypes'] ?? [],
                        'entry_count' => count($entries),
                    ];
                }

                return ['content_types' => $types];
            },
        );
    }

    /** The field schema for one content type — what an entry is made of. */
    private static function describeContentType(): Tool
    {
        return new Tool(
            name: 'describe_content_type',
            label: 'Checking how those items are set up',
            description:
                'Describe the fields of one content type: every field key, its label, its type, and '
                . 'whether it is required. Call this before proposing any change to an entry, so the '
                . 'field keys and value formats you use are correct.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'content_type' => [
                        'type'        => 'string',
                        'description' => 'The type key from list_content_types, e.g. "pricing_tier".',
                    ],
                ],
                'required'             => ['content_type'],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $key        = (string) $input['content_type'];
                $definition = ContentTypeSchema::resolve($key);

                if ($definition === null) {
                    return ['error' => "No content type '{$key}'. Call list_content_types for valid keys."];
                }

                return [
                    'key'        => $key,
                    'label'      => $definition['label'],
                    'archetypes' => $definition['archetypes'] ?? [],
                    'supports'   => $definition['supports'] ?? [],
                    'fields'     => array_map(
                        static fn (array $f): array => array_filter([
                            'key'        => $f['key'] ?? null,
                            'label'      => $f['label'] ?? null,
                            'type'       => $f['type'] ?? 'text',
                            'required'   => !empty($f['required']) ? true : null,
                            'options'    => $f['options'] ?? null,
                            'sub_fields' => isset($f['sub_fields'])
                                ? array_column($f['sub_fields'], 'key')
                                : null,
                        ], static fn ($v): bool => $v !== null),
                        $definition['fields'] ?? []
                    ),
                ];
            },
        );
    }

    /** Find entries by title. Titles and ids only — reading is a separate call. */
    private static function searchEntries(): Tool
    {
        return new Tool(
            name: 'search_entries',
            label: 'Searching your content',
            description:
                'Find entries of a content type, optionally filtered by a title search. Returns ids, '
                . 'titles and status only — call read_entry for an entry\'s field values. Use this to '
                . 'locate the specific entries a request refers to.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'content_type' => [
                        'type'        => 'string',
                        'description' => 'The type key from list_content_types.',
                    ],
                    'query' => [
                        'type'        => 'string',
                        'description' => 'Optional case-insensitive substring match against the title.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum results (default 25, max 100).',
                    ],
                ],
                'required'             => ['content_type'],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $key = (string) $input['content_type'];

                if (Registry::get($key) === null) {
                    return ['error' => "No content type '{$key}'. Call list_content_types for valid keys."];
                }

                $query = trim((string) ($input['query'] ?? ''));
                $limit = max(1, min(100, (int) ($input['limit'] ?? 25)));

                $entries = Post::all($key, $ctx->authorFilter());

                if ($query !== '') {
                    $entries = array_values(array_filter(
                        $entries,
                        static fn (array $e): bool => stripos((string) $e['title'], $query) !== false
                    ));
                }

                $total   = count($entries);
                $entries = array_slice($entries, 0, $limit);

                return [
                    'total'   => $total,
                    'showing' => count($entries),
                    // A TRANSLATION SAYS ITS LANGUAGE and the entry it
                    // translates (`Content\Translation`), so "the French one"
                    // is one entry and not a guess between two of one title.
                    'entries' => array_map(static fn (array $e): array => [
                        'id'     => (int) $e['id'],
                        'title'  => $e['title'],
                        'slug'   => $e['slug'],
                        'status' => $e['status'],
                    ] + ((string) $e['lang'] !== '' ? [
                        'language'       => \Cms\Site\Language::nameOf((string) $e['lang']),
                        'translation_of' => $e['translation_of'] !== null ? (int) $e['translation_of'] : null,
                    ] : []), $entries),
                ];
            },
        );
    }

    /** One entry in full, with field values resolved into readable form. */
    private static function readEntry(): Tool
    {
        return new Tool(
            name: 'read_entry',
            label: 'Reading one of your items',
            description:
                'Read one entry in full: its core fields plus every custom field value, with images '
                . 'and linked entries resolved to filenames and titles rather than bare ids; a product also '
                . 'with its price, sale, code and count, and each of its options ("M / Navy") with its '
                . 'option_id, code, price and count. Call this before proposing a change, so you know the '
                . 'current value of anything you are changing.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Entry id from search_entries.',
                    ],
                ],
                'required'             => ['id'],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $id   = (int) $input['id'];
                $post = Post::find($id);

                if ($post === null) {
                    return ['error' => "No entry with id {$id}."];
                }

                // Authors may only read their own entries.
                $filter = $ctx->authorFilter();
                if ($filter !== null && (int) $post['author_id'] !== $filter) {
                    return ['error' => "You do not have access to entry {$id}."];
                }

                $definition = ContentTypeSchema::resolve((string) $post['post_type']);
                $rawMeta    = Meta::getAll($id);
                $fields     = [];

                foreach ($definition['fields'] ?? [] as $field) {
                    $key = $field['key'] ?? null;
                    if ($key === null || str_starts_with($key, '_')) {
                        continue;
                    }
                    $fields[$key] = self::presentValue($field, $rawMeta[$key] ?? null);
                }

                // Taxonomy assignments made through the sidebar panels, which live in
                // post_term_relationships rather than post_meta.
                $terms = [];
                foreach (\Cms\ContentType\TaxonomyRegistry::getForContentType((string) $post['post_type']) as $taxKey => $tax) {
                    $assigned = Term::getPostTerms($id, $taxKey);
                    if ($assigned !== []) {
                        $terms[$taxKey] = array_column($assigned, 'name');
                    }
                }

                $out = [
                    'id'           => $id,
                    'content_type' => $post['post_type'],
                    'title'        => $post['title'],
                    'slug'         => $post['slug'],
                    'status'       => $post['status'],
                    'content'      => $post['content'],
                    // Migration 053. A field `update_core` can change must be
                    // one this tool returns, or the model has no `from` to send.
                    'excerpt'      => $post['excerpt'] ?? null,
                    'published_at' => $post['published_at'],
                    'updated_at'   => $post['updated_at'] ?? null,
                    'fields'       => $fields,
                    'taxonomies'   => $terms,
                ];

                // A PRODUCT'S PRICE, OPTIONS AND COUNTS, where the site sells.
                // Without them "how many medium navy tees are left" had no
                // answer: the options and their counts are not fields.
                $shop = ShopTools::product($post);
                if ($shop !== null) {
                    $out['shop'] = $shop;
                }

                return $out;
            },
        );
    }

    // ----------------------------------------------------------------
    // Pages
    //
    // Pages are a separate system from Content Types — static, hierarchical, no
    // field schema — so they need their own tools. Without these the assistant
    // could not see half the CMS, and worse, said so: asked to edit the About
    // page it replied that no such page existed and speculated it might be
    // hard-coded outside the CMS. Confidently wrong is the failure mode that
    // matters most here.
    // ----------------------------------------------------------------

    /** Every page, as a tree the model can navigate by title. */
    private static function listPages(): Tool
    {
        return new Tool(
            name: 'list_pages',
            label: 'Looking through your pages',
            description:
                'List the site\'s static Pages — About, Contact, Services and so on. Pages are '
                . 'separate from content types: they are hierarchical, evergreen, and have no '
                . 'custom fields. Use this to find a page before reading or changing it.',
            inputSchema: [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'required'             => [],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $pages = [];

                foreach (Page::all() as $page) {
                    // A TRANSLATION SAYS ITS LANGUAGE and the page it
                    // translates (`Content\Translation`).
                    $pages[] = array_filter([
                        'id'             => (int) $page['id'],
                        'title'          => $page['title'],
                        'slug'           => $page['slug'],
                        'status'         => $page['status'],
                        'parent_id'      => $page['parent_id'] !== null ? (int) $page['parent_id'] : null,
                        'language'       => (string) $page['lang'] !== '' ? \Cms\Site\Language::nameOf((string) $page['lang']) : null,
                        'translation_of' => $page['translation_of'] !== null ? (int) $page['translation_of'] : null,
                    ], static fn ($v): bool => $v !== null);
                }

                return ['total' => count($pages), 'pages' => $pages];
            },
        );
    }

    /** One page in full. */
    private static function readPage(): Tool
    {
        return new Tool(
            name: 'read_page',
            label: 'Reading one of your pages',
            description:
                'Read one static Page in full — its title, slug, body content and SEO fields. '
                . 'Call this before proposing any change to a page, so you know the current text '
                . 'of whatever you are changing.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Page id from list_pages.',
                    ],
                ],
                'required'             => ['id'],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $id   = (int) $input['id'];
                $page = Page::find($id);

                if ($page === null) {
                    return ['error' => "No page with id {$id}. Call list_pages for valid ids."];
                }

                $slug = (string) ($page['slug'] ?? '');

                return array_filter([
                    'id'               => $id,
                    'title'            => $page['title'],
                    'slug'             => $slug,
                    'status'           => $page['status'],
                    'parent_id'        => $page['parent_id'] !== null ? (int) $page['parent_id'] : null,
                    'content'          => $page['content'],
                    'seo_title'        => $page['seo_title'] ?? null,
                    'meta_description' => $page['meta_description'] ?? null,
                    'updated_at'       => $page['updated_at'] ?? null,
                ], static fn ($v): bool => $v !== null);
            },
        );
    }
    // ----------------------------------------------------------------
    // Value presentation
    // ----------------------------------------------------------------

    /**
     * Turn a stored meta value into something the model can reason about.
     *
     * Bare attachment ids and post ids are meaningless to a model — it cannot tell
     * whether `7` is right or wrong. Resolving them to filenames and titles is what
     * makes "swap Dave's photo" or "the tier linked to Aiko" answerable.
     *
     * @return mixed
     */
    private static function presentValue(array $field, mixed $raw): mixed
    {
        $type = $field['type'] ?? 'text';

        if ($raw === null || $raw === '') {
            return null;
        }

        return match ($type) {
            'image' => self::presentImage($raw),

            'relationship' => array_values(array_filter(array_map(
                static function ($id): ?array {
                    $related = Post::find((int) $id);
                    return $related === null
                        ? null
                        : ['id' => (int) $related['id'], 'title' => $related['title']];
                },
                self::decodeList($raw)
            ))),

            'taxonomy' => array_values(array_filter(array_map(
                static function ($id): ?string {
                    $term = Term::find((int) $id);
                    return $term['name'] ?? null;
                },
                self::decodeList($raw)
            ))),

            'repeater' => self::decodeList($raw),

            'number' => is_numeric($raw) ? $raw + 0 : $raw,

            default => $raw,
        };
    }

    /** Resolve an attachment id to something nameable. */
    private static function presentImage(mixed $raw): ?array
    {
        $attachment = Media::find((int) $raw);

        if ($attachment === null) {
            return null;
        }

        return [
            'id'       => (int) $attachment['id'],
            'filename' => $attachment['filename'] ?? null,
            'alt'      => $attachment['alt_text'] ?? null,
        ];
    }

    /**
     * Decode a JSON-encoded meta value into a list.
     * Complex field types are stored as JSON by Meta::set().
     *
     * @return array<int, mixed>
     */
    private static function decodeList(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
