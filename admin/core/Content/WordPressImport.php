<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Commerce\Product;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
use Cms\Helpers\Slug;
use Cms\Helpers\Url;
use Cms\Html\Sanitizer;
use Cms\Models\Gallery;
use Cms\Models\Media;
use Cms\Models\MediaRejected;
use Cms\Models\Page;
use Cms\Models\Post;
use Cms\Models\Term;
use RuntimeException;

/**
 * A WORDPRESS SITE, BROUGHT IN: the export WordPress writes at Tools → Export
 * (`Wxr`), read on Tools → Import and export, planned and then applied as this
 * site's own exports are (`Import`).
 *
 * ── WHAT COMES IN, AND WHERE ────────────────────────────────────────────────
 * Pages are pages. Posts go into a type of entry the owner chooses, and each of
 * WordPress's other kinds of post into one too, or nowhere; categories and tags
 * into two of those types' taxonomies. The media library's files are fetched
 * from the old site and kept here, and every picture and file the content
 * shows, every link to a page or post that came across, each featured image
 * and each gallery, points at its copy here. Authors, comments, menus and
 * WordPress's own records (revisions, templates, styles) stay behind, and the
 * plan says so with the count.
 *
 * ── MATCHED BY ADDRESS, AND PRIVATE UNTIL PUBLISHED ─────────────────────────
 * `Import`'s rules: an item whose address is here already is shown as an
 * overwrite before anything is written, everything arrives Private (a draft
 * there with no date) unless the owner ticks "Publish what WordPress had
 * published", and the importer owns what they import.
 *
 * ── THE FILES, A FEW AT A TIME ──────────────────────────────────────────────
 * Everything but the media is written in one transaction: all of it, or none.
 * The media are a job taken a few seconds at a time, as a static copy is
 * (`static-copy.js` asks for each step), because a hundred photos fetched in
 * one request outlast any host's time limit. Each is fetched only from a public
 * address over http(s), pinned to the address checked, at most the library's
 * size, and admitted by the library's own rule (`Media::importFile()`). One
 * that cannot be is named in the report, and its pictures keep their old
 * address.
 */
final class WordPressImport
{
    /** WordPress's own records, which are not anything anyone wrote. */
    private const NOT_CONTENT = [
        'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_family', 'wp_font_face', 'scheduled-action', 'shop_order', 'shop_order_refund', 'shop_coupon',
    ];

    /** Statuses of nothing anyone means to keep. */
    private const NOT_WRITTEN = ['trash', 'auto-draft', 'inherit'];

    /** An export waits this long between its plan and its import, and a report this long after. */
    private const KEPT_FOR = 86400;

    /** Each step's share of a request, in seconds. */
    public const STEP_SECONDS = 8.0;

    /** One fetch's limit, in seconds, and how many redirects it follows. */
    private const FETCH_TIMEOUT = 20;
    private const REDIRECTS = 3;

    /** The five providers whose posts and videos are embedded by their code here. */
    private const PROVIDERS = '#^https?://(www\.)?(youtube\.com|youtu\.be|vimeo\.com|x\.com|twitter\.com|instagram\.com|facebook\.com|fb\.watch)/#i';

    /** @var (callable(string, string): ?string)|null in the network's place */
    private static $wire = null;

    // ----------------------------------------------------------------
    // The file, between its plan and its import
    // ----------------------------------------------------------------

    public static function dir(): string
    {
        return ROOT_PATH . '/imports';
    }

    /** Keep an uploaded export outside the web site until it is applied; its token. */
    public static function hold(string $uploaded): string
    {
        self::prepare();
        self::tidy();
        $token = bin2hex(random_bytes(12));
        $to    = self::dir() . '/' . $token . '.xml';
        if (!@move_uploaded_file($uploaded, $to) && !@copy($uploaded, $to)) {
            throw new RuntimeException('The export could not be kept on the server.');
        }
        return $token;
    }

    /** The held export, or null. */
    public static function file(string $token): ?string
    {
        $path = self::dir() . '/' . $token . '.xml';
        return self::isToken($token) && is_file($path) ? $path : null;
    }

    /** A held export, let go without importing it. */
    public static function forget(string $token): void
    {
        if (self::isToken($token)) {
            @unlink(self::dir() . '/' . $token . '.xml');
        }
    }

    /** Put something in the network's place: given a URL and a file, it fills the file or says why not. */
    public static function useWire(?callable $wire): void
    {
        self::$wire = $wire;
    }

    // ----------------------------------------------------------------
    // The plan
    // ----------------------------------------------------------------

    /**
     * What importing the export would do with these choices. Writes nothing.
     *
     * @param  array<string, mixed> $asked `types` (WordPress's kind => a type key, or ''),
     *         `categories` and `tags` (a taxonomy key, or ''), `publish`; what is not
     *         asked is chosen as `choose()` would
     * @return array{ok: bool, error?: string, plan?: array<string, mixed>}
     */
    public static function plan(string $path, array $asked = []): array
    {
        try {
            $read = self::read($path);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $choices = self::choose($read['kinds'], $asked);
        $sorted  = self::sort($read, $choices);

        $plan = [
            'wordpress' => true,
            'site'      => ['title' => $read['site']['title'], 'url' => $read['site']['url']],
            'choices'   => $choices,
            'offer'     => self::offer($read['kinds'], $choices),
            'pages'     => [],
            'entries'   => [],
            'media'     => count($read['files']),
            'terms'     => ['categories' => count($read['site']['categories']), 'tags' => count($read['site']['tags'])],
            'notes'     => [],
            'skipped'   => $sorted['skipped'],
            'settings'  => [],
        ];
        foreach ($sorted['rows'] as $row) {
            $line = ['slug' => $row['slug'], 'title' => $row['title'], 'action' => $row['action'],
                     'detail' => self::statusWord((string) $row['status'], $choices['publish'], (string) $row['password'] !== '')];
            if ($row['to'] === 'page') {
                $plan['pages'][] = $line;
            } else {
                $plan['entries'][] = $line + ['type' => (string) (Registry::get($row['to'])['label'] ?? $row['to'])];
            }
        }
        $plan['notes'] = self::notes($read, $choices, $plan['offer']['taxonomies']);

        return ['ok' => true, 'plan' => $plan];
    }

    /**
     * The choices as they will be used: each of WordPress's kinds of post to a
     * type here (`page` to the pages), categories and tags to a taxonomy of one
     * of those types, and whether to publish. An unknown or unusable choice is
     * the default: posts to a type called news, posts, articles or blog, or the
     * first that does not sell; a kind with a type of the same name here to it;
     * categories to the posts' type's first hierarchical taxonomy, tags to its
     * first flat one.
     *
     * @param  array<string, int>   $kinds WordPress's kind => how many
     * @param  array<string, mixed> $asked
     * @return array{types: array<string, string>, categories: string, tags: string, publish: bool}
     */
    public static function choose(array $kinds, array $asked): array
    {
        $into    = self::destinations();
        $askedTo = is_array($asked['types'] ?? null) ? $asked['types'] : [];
        $types   = [];
        foreach (array_keys($kinds) as $kind) {
            if ($kind === 'page') {
                $types['page'] = isset($askedTo['page']) && $askedTo['page'] === '' ? '' : 'page';
                continue;
            }
            if (array_key_exists($kind, $askedTo)) {
                $want          = (string) $askedTo[$kind];
                $types[$kind]  = $want === '' || isset($into[$want]) ? $want : '';
                continue;
            }
            $types[$kind] = $kind === 'post' ? self::postsDefault($into) : (isset($into[$kind]) ? $kind : '');
        }

        $offered  = self::taxonomiesFor(array_values(array_filter($types, static fn (string $t): bool => $t !== '' && $t !== 'page')));
        $postType = $types['post'] ?? '';
        $pick     = static function (string $name, bool $hierarchical) use ($asked, $offered, $postType): string {
            if (array_key_exists($name, $asked)) {
                $want = (string) $asked[$name];
                return $want === '' || isset($offered[$want]) ? $want : '';
            }
            foreach ($offered as $key => $tax) {
                if (in_array($postType, $tax['content_types'], true) && (bool) $tax['hierarchical'] === $hierarchical) {
                    return $key;
                }
            }
            return '';
        };
        $categories = $pick('categories', true);
        $tags       = $pick('tags', false);
        if ($tags !== '' && $tags === $categories) {
            $tags = '';
        }

        return ['types' => $types, 'categories' => $categories, 'tags' => $tags, 'publish' => !empty($asked['publish'])];
    }

    /**
     * What the plan's choices offer: each kind with its count, the types each
     * may go into, and the taxonomies categories and tags may go into.
     *
     * @param  array<string, int> $kinds
     * @param  array<string, mixed> $choices
     * @return array{kinds: array<string, int>, types: array<string, string>, taxonomies: array<string, string>}
     */
    private static function offer(array $kinds, array $choices): array
    {
        $types = [];
        foreach (self::destinations() as $key => $type) {
            $types[$key] = (string) ($type['label'] ?? $key);
        }
        $taxonomies = [];
        foreach (self::taxonomiesFor(array_keys($types)) as $key => $tax) {
            $taxonomies[$key] = (string) ($tax['label'] ?? $key);
        }
        return ['kinds' => $kinds, 'types' => $types, 'taxonomies' => $taxonomies];
    }

    /** @return array<string, array<string, mixed>> the types an import may write into: every type that does not sell */
    private static function destinations(): array
    {
        $out = [];
        foreach (Registry::all() as $key => $type) {
            if (!Product::isSellableType((string) $key)) {
                $out[(string) $key] = $type;
            }
        }
        return $out;
    }

    /** @param array<string, array<string, mixed>> $into */
    private static function postsDefault(array $into): string
    {
        foreach (['news', 'posts', 'post', 'articles', 'article', 'blog'] as $name) {
            foreach ($into as $key => $type) {
                if ($key === $name || strtolower((string) ($type['admin_slug'] ?? '')) === $name || strtolower((string) ($type['label'] ?? '')) === $name) {
                    return (string) $key;
                }
            }
        }
        return (string) (array_key_first($into) ?? '');
    }

    /**
     * The taxonomies of these types that terms may go into: not a product's
     * options (a size, a colour).
     *
     * @param  list<string> $typeKeys
     * @return array<string, array<string, mixed>>
     */
    private static function taxonomiesFor(array $typeKeys): array
    {
        $out = [];
        foreach (TaxonomyRegistry::all() as $key => $tax) {
            if (empty($tax['is_option']) && array_intersect($typeKeys, (array) $tax['content_types']) !== []) {
                $out[(string) $key] = $tax;
            }
        }
        return $out;
    }

    // ----------------------------------------------------------------
    // Reading and sorting the export
    // ----------------------------------------------------------------

    /**
     * The export, read once: its site, every item that is content (without
     * its content), its files, and what the content holds that does not come
     * across as it is.
     *
     * @return array<string, mixed>
     */
    private static function read(string $path): array
    {
        $out = ['kinds' => [], 'items' => [], 'files' => [], 'records' => 0, 'trashed' => 0, 'comments' => 0,
                'shortcodes' => [], 'embeds' => 0, 'reusable' => 0, 'removed' => 0];
        $out['site'] = Wxr::scan($path, static function (array $item) use (&$out): void {
            if (in_array($item['type'], self::NOT_CONTENT, true)) {
                $out['records']++;
                return;
            }
            if ($item['type'] === 'attachment') {
                if ($item['url'] !== '') {
                    $out['files'][] = self::fileOf($item);
                }
                return;
            }
            if (in_array($item['status'], self::NOT_WRITTEN, true)) {
                $out['trashed'] += $item['status'] === 'trash' ? 1 : 0;
                return;
            }
            $out['kinds'][$item['type']] = ($out['kinds'][$item['type']] ?? 0) + 1;
            $out['comments'] += (int) $item['comments'];
            $notes = [];
            $html  = self::html((string) $item['content'], $notes);
            foreach ($notes['shortcodes'] ?? [] as $name => $n) {
                $out['shortcodes'][$name] = ($out['shortcodes'][$name] ?? 0) + $n;
            }
            $out['embeds']   += $notes['embeds'] ?? 0;
            $out['reusable'] += $notes['reusable'] ?? 0;
            $out['removed']  += self::removedBy($html);
            unset($item['content'], $item['excerpt']);
            $out['items'][] = $item;
        });

        return $out;
    }

    /**
     * Every item, decided: where it goes, its address here, and whether it is
     * new or overwrites, or why it is skipped. The plan and the import ask the
     * same question here, so the import does what the plan said.
     *
     * @param  array<string, mixed> $read
     * @param  array<string, mixed> $choices
     * @return array{rows: list<array<string, mixed>>, skipped: list<array{what: string, why: string}>}
     */
    private static function sort(array $read, array $choices): array
    {
        $rows    = [];
        $skipped = [];
        $taken   = ['page' => [], 'post' => []];
        foreach ($read['items'] as $item) {
            $to    = (string) ($choices['types'][$item['type']] ?? '');
            $title = $item['title'] !== '' ? $item['title'] : '(no title)';
            if ($to === '') {
                continue;   // a kind left out: counted in the notes, not listed
            }
            $slug = self::slugOf($item);
            if ($to === 'page') {
                $what = sprintf('the page "%s"', $title);
                if (in_array($slug, Slug::reservedFirstSegments(), true)) {
                    $skipped[] = ['what' => $what, 'why' => sprintf('/%s is one of this site\'s own addresses', $slug)];
                    continue;
                }
                if (isset($taken['page'][$slug])) {
                    $skipped[] = ['what' => $what, 'why' => sprintf('the export has another page at /%s', $slug)];
                    continue;
                }
                $live = Page::findBySlug($slug);
                if ($live === null && Page::slugExists($slug)) {
                    $skipped[] = ['what' => $what, 'why' => sprintf('a page at /%s is in the trash: restore it to overwrite it, or empty the trash', $slug)];
                    continue;
                }
                $taken['page'][$slug] = true;
                $row         = $item + ['to' => 'page', 'action' => $live === null ? 'create' : 'overwrite', 'here' => $live['id'] ?? null];
                $row['slug'] = $slug;
                $rows[]      = $row;
                continue;
            }
            $what = sprintf('"%s" (%s)', $title, $item['type']);
            if (isset($taken['post'][$slug])) {
                $skipped[] = ['what' => $what, 'why' => sprintf('the export has another entry at %s', $slug)];
                continue;
            }
            $held = Post::findBySlug($slug);
            if ($held !== null && (string) $held['post_type'] !== $to) {
                $skipped[] = ['what' => $what, 'why' => sprintf('the address %s is taken by a %s here', $slug, (string) $held['post_type'])];
                continue;
            }
            if ($held === null && Post::slugExists($slug)) {
                $skipped[] = ['what' => $what, 'why' => sprintf('an entry at %s is in the trash: restore it to overwrite it, or empty the trash', $slug)];
                continue;
            }
            $taken['post'][$slug] = true;
            $row          = $item + ['to' => $to, 'action' => $held === null ? 'create' : 'overwrite', 'here' => $held['id'] ?? null];
            $row['slug']  = $slug;
            $rows[]       = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $read
     * @param  array<string, mixed>  $choices
     * @param  array<string, string> $taxonomies what categories and tags could go into
     * @return list<string>
     */
    private static function notes(array $read, array $choices, array $taxonomies): array
    {
        $notes = [];
        $host  = (string) (parse_url((string) $read['site']['url'], PHP_URL_HOST) ?: '');
        if ($read['files'] !== []) {
            $notes[] = sprintf('%d file%s of its media library %s fetched from %s and kept here, a few at a time once the rest is in; pictures and links in the content then point at the copies.',
                count($read['files']), count($read['files']) === 1 ? '' : 's', count($read['files']) === 1 ? 'is' : 'are', $host !== '' ? $host : 'the old site');
        }
        foreach ($choices['types'] as $kind => $to) {
            if ($to === '' && ($read['kinds'][$kind] ?? 0) > 0) {
                $notes[] = sprintf('%d %s %s left out, as chosen above.', $read['kinds'][$kind], self::kindName($kind, $read['kinds'][$kind]), $read['kinds'][$kind] === 1 ? 'is' : 'are');
            }
        }
        foreach (['categories' => 'Categories', 'tags' => 'Tags'] as $key => $name) {
            if (($read['site'][$key] ?? []) !== [] && $choices[$key] === '') {
                $notes[] = $taxonomies === []
                    ? $name . ' are left out: none of the types chosen has a taxonomy. Add one at Structure → Taxonomies and read the file again.'
                    : $name . ' are left out, as chosen above.';
            }
        }
        if ($read['comments'] > 0) {
            $notes[] = sprintf('%d comment%s stay%s behind: this site does not take comments.', $read['comments'], $read['comments'] === 1 ? '' : 's', $read['comments'] === 1 ? 's' : '');
        }
        if ($read['embeds'] > 0) {
            $notes[] = $read['embeds'] === 1
                ? '1 video or post embedded by its address is kept as a link: paste its embed code from its Share button to show it.'
                : sprintf('%d videos or posts embedded by their address are kept as links: paste each one\'s embed code from its Share button to show it.', $read['embeds']);
        }
        if ($read['removed'] > 0) {
            $notes[] = sprintf('%d piece%s of pasted code from somewhere other than YouTube, Vimeo, X, Instagram or Facebook %s left out.',
                $read['removed'], $read['removed'] === 1 ? '' : 's', $read['removed'] === 1 ? 'is' : 'are');
        }
        if ($read['shortcodes'] !== []) {
            arsort($read['shortcodes']);
            $named = [];
            foreach (array_slice($read['shortcodes'], 0, 6, true) as $name => $n) {
                $named[] = '[' . $name . ']' . ($n > 1 ? ' ×' . $n : '');
            }
            $notes[] = 'Shortcodes from WordPress\'s plugins are kept as text, to replace by hand: ' . implode(', ', $named)
                . (count($read['shortcodes']) > 6 ? ', and ' . (count($read['shortcodes']) - 6) . ' more' : '') . '.';
        }
        if ($read['reusable'] > 0) {
            $notes[] = sprintf('%d reusable block%s %s left out: WordPress keeps %s apart from the posts that show %s.',
                $read['reusable'], $read['reusable'] === 1 ? '' : 's', $read['reusable'] === 1 ? 'is' : 'are', $read['reusable'] === 1 ? 'it' : 'them', $read['reusable'] === 1 ? 'it' : 'them');
        }
        if ($read['records'] > 0) {
            $notes[] = sprintf('%d of WordPress\'s own records (revisions, menus, templates, styles) stay%s behind.', $read['records'], $read['records'] === 1 ? 's' : '');
        }
        if ($read['trashed'] > 0) {
            $notes[] = sprintf('%d item%s in WordPress\'s trash stay%s behind.', $read['trashed'], $read['trashed'] === 1 ? '' : 's', $read['trashed'] === 1 ? 's' : '');
        }
        $notes[] = 'Authors are not brought in: whoever imports owns everything imported.';
        return $notes;
    }

    private static function kindName(string $kind, int $n): string
    {
        return match ($kind) {
            'post'  => $n === 1 ? 'post' : 'posts',
            'page'  => $n === 1 ? 'page' : 'pages',
            default => '"' . $kind . '" item' . ($n === 1 ? '' : 's'),
        };
    }

    /** The word for what an item will be here, as `write()` decides it. */
    private static function statusWord(string $status, bool $publish, bool $password): string
    {
        return match (true) {
            in_array($status, ['draft', 'pending'], true)               => 'Private (a draft there)',
            $publish && $password && $status === 'publish'              => 'Password',
            $publish && in_array($status, ['publish', 'future'], true)  => $status === 'future' ? 'Scheduled' : 'Published',
            default                                                      => 'Private',
        };
    }

    /** An item's address here: its WordPress slug, or its title's, or its kind and number. */
    private static function slugOf(array $item): string
    {
        $slug = Slug::clean(rawurldecode((string) $item['slug']));
        if ($slug === '') {
            $slug = Slug::clean((string) $item['title']);
        }
        return $slug !== '' ? $slug : Slug::clean($item['type'] . '-' . $item['id']);
    }

    /** @return array<string, mixed> what the job needs of an attachment */
    private static function fileOf(array $item): array
    {
        return [
            'wp'      => (int) $item['id'],
            'url'     => (string) $item['url'],
            'title'   => (string) $item['title'],
            'alt'     => (string) ($item['meta']['_wp_attachment_image_alt'] ?? ''),
            'parent'  => (int) $item['parent'],
            'order'   => (int) $item['order'],
            'date'    => self::utc($item),
        ];
    }

    /** WordPress's UTC date for an item, as the columns keep dates, or null. */
    private static function utc(array $item): ?string
    {
        foreach ([(string) $item['date_gmt'], (string) $item['date']] as $at) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at) === 1 && !str_starts_with($at, '0000')) {
                return $at;
            }
        }
        return null;
    }

    // ----------------------------------------------------------------
    // WordPress's HTML, as this site's editor writes it
    // ----------------------------------------------------------------

    /**
     * The block editor's comments gone, a classic post's blank lines made
     * paragraphs, a caption a figure, an alignment the editor's own word, and
     * a video or a post embedded by its address kept as a link to it. What is
     * left for the sanitizer, `Post::create()`'s, is the rest.
     *
     * A picture WordPress numbered (`wp-image-12`) is given its file's own
     * address from `$files` (WordPress's number => address), whatever size of
     * it the post showed: the number goes with the class the sanitizer keeps
     * no copy of, and the address is what `relink()` finds the copy by.
     *
     * @param array<string, mixed>  $notes counted into: `embeds`, `reusable`, `shortcodes` (name => n)
     * @param array<string, string> $files
     */
    public static function html(string $content, array &$notes, array $files = []): string
    {
        $html    = str_replace(["\r\n", "\r"], "\n", $content);
        $classic = !str_contains($html, '<!-- wp:');
        if ($files !== []) {
            $html = (string) preg_replace_callback('#<img\b[^>]*>#i', static function (array $m) use ($files): string {
                if (preg_match('/\bwp-image-(\d+)\b/', $m[0], $n) !== 1 || !isset($files[$n[1]])) {
                    return $m[0];
                }
                return (string) preg_replace('/\bsrc="[^"]*"/', 'src="' . htmlspecialchars($files[$n[1]], ENT_QUOTES, 'UTF-8') . '"', $m[0], 1);
            }, $html);
        }

        // A REUSABLE BLOCK is kept apart from the post: only its number is here.
        $html = (string) preg_replace_callback('#<!--\s*wp:block\s+\{[^}]*\}\s*/-->#', static function () use (&$notes): string {
            $notes['reusable'] = ($notes['reusable'] ?? 0) + 1;
            return '';
        }, $html);

        // AN EMBED BY ADDRESS, the block editor's: its address, as a link.
        $html = (string) preg_replace_callback(
            '#<figure class="wp-block-embed[^"]*"[^>]*>\s*<div class="wp-block-embed__wrapper">\s*(https?://[^\s<]+)\s*</div>(?:\s*<figcaption[^>]*>.*?</figcaption>)?\s*</figure>#s',
            static function (array $m) use (&$notes): string {
                $notes['embeds'] = ($notes['embeds'] ?? 0) + 1;
                $url = htmlspecialchars(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                return '<p><a href="' . $url . '">' . $url . '</a></p>';
            },
            $html
        );
        $html = (string) preg_replace_callback('#\[embed[^\]]*\](https?://[^\[\s]+)\[/embed\]#i', static function (array $m) use (&$notes): string {
            $notes['embeds'] = ($notes['embeds'] ?? 0) + 1;
            $url = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            return '<a href="' . $url . '">' . $url . '</a>';
        }, $html);
        $html = (string) preg_replace(['#<!--\s*/?wp:.*?-->#s', '/\n{3,}/'], ['', "\n\n"], $html);

        // A CAPTION, a classic post's: a figure with its picture and words.
        $html = (string) preg_replace_callback('#\[caption([^\]]*)\](.*?)\[/caption\]#s', static function (array $m): string {
            $align = preg_match('/align="?align(left|right|center|none)"?/', $m[1], $a) === 1 ? ' class="align-' . $a[1] . '"' : '';
            if (preg_match('#^\s*((?:<a\b[^>]*>\s*)?<img\b[^>]*>(?:\s*</a>)?)(.*)$#s', $m[2], $parts) !== 1) {
                return $m[2];
            }
            $words = trim($parts[2]);
            return '<figure' . $align . '>' . $parts[1] . ($words !== '' ? '<figcaption>' . $words . '</figcaption>' : '') . '</figure>';
        }, $html);

        if ($classic) {
            $html = self::paragraphs($html, $notes);
        }

        // ALIGNMENT IN THE EDITOR'S OWN WORDS: a picture's, and a paragraph's.
        $html = (string) preg_replace_callback('/\bclass="([^"]*)"/', static function (array $m): string {
            $classes = preg_replace(['/\balign(left|right|center|none)\b/', '/\bhas-text-align-(left|center|right)\b/'], ['align-$1', 'text-$1'], $m[1]);
            return 'class="' . $classes . '"';
        }, $html);

        // SHORTCODES THIS SITE DOES NOT KNOW, counted; a gallery is made a
        // gallery here once its pictures are (`relink()`).
        if (preg_match_all('#\[([a-z][a-z0-9_-]*)(?:\s[^\]]*)?\]#i', $html, $codes) > 0) {
            foreach ($codes[1] as $name) {
                $name = strtolower($name);
                if ($name !== 'gallery') {
                    $notes['shortcodes'][$name] = ($notes['shortcodes'][$name] ?? 0) + 1;
                }
            }
        }

        return trim($html);
    }

    /**
     * A classic post's text in paragraphs. Its export keeps them as written,
     * with no markup: a blank line between two paragraphs and a line break
     * inside one; a block of HTML is left as it is. A paragraph that is only the
     * address of a video or a post becomes a link to it.
     *
     * @param array<string, mixed> $notes
     */
    private static function paragraphs(string $html, array &$notes): string
    {
        $blocks = '(?:p|div|h[1-6]|ul|ol|li|dl|dt|dd|table|thead|tbody|tfoot|tr|td|th|blockquote|pre|figure|hr|section|article|aside|header|footer|address|form|iframe|script|style|noscript)';
        $out    = [];
        foreach (preg_split('/\n\s*\n/', trim($html)) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (preg_match('#^https?://\S+$#', $chunk) === 1) {
                if (preg_match(self::PROVIDERS, $chunk) === 1) {
                    $notes['embeds'] = ($notes['embeds'] ?? 0) + 1;
                }
                $url   = htmlspecialchars($chunk, ENT_QUOTES, 'UTF-8');
                $out[] = '<p><a href="' . $url . '">' . $url . '</a></p>';
                continue;
            }
            // A BLOCK, or a shortcode on a line of its own: never inside a paragraph.
            if (preg_match('#^<' . $blocks . '\b#i', $chunk) === 1 || preg_match('#^<!--#', $chunk) === 1
                || preg_match('#^\[[a-z][a-z0-9_-]*(\s[^\]]*)?\](.*\[/[a-z][a-z0-9_-]*\])?$#is', $chunk) === 1) {
                $out[] = $chunk;
                continue;
            }
            $out[] = '<p>' . preg_replace('/\n/', "<br>\n", $chunk) . '</p>';
        }
        return implode("\n", $out);
    }

    /** How many iframes and scripts the sanitizer takes out of this HTML. */
    private static function removedBy(string $html): int
    {
        $count = static fn (string $h): int => preg_match_all('#<(iframe|script)\b#i', $h);
        $before = $count($html);
        return $before === 0 ? 0 : max(0, $before - $count(Sanitizer::clean($html)));
    }

    // ----------------------------------------------------------------
    // The import
    // ----------------------------------------------------------------

    /**
     * Do it: every page, entry and term now, in one transaction, and the media
     * as a job `step()` works through; when there are none, the links are
     * pointed here at once. What it did is kept with the job, which the screen
     * reads (`job()`).
     *
     * @param  array<string, mixed> $asked the choices the plan was made with
     * @return array{ok: bool, error?: string, token?: string}
     */
    public static function apply(string $token, array $asked, int $authorId): array
    {
        $path = self::file($token);
        if ($path === null) {
            return ['ok' => false, 'error' => 'There is no export waiting. Upload it again: the plan you saw has expired.'];
        }
        try {
            $read    = self::read($path);
            $choices = self::choose($read['kinds'], $asked);
            $sorted  = self::sort($read, $choices);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $job = [
            'token' => $token, 'site' => (string) $read['site']['url'], 'blog' => (string) $read['site']['blog'],
            'phase' => 'media', 'offset' => 0, 'rewritten' => 0, 'files' => $read['files'], 'made' => [], 'links' => [],
            'media' => [], 'urls' => [], 'failed' => [], 'started' => time(), 'touched' => time(),
            'created' => 0, 'overwritten' => 0, 'skipped' => count($sorted['skipped']), 'log' => [],
            'publish' => $choices['publish'],
        ];
        foreach ($sorted['skipped'] as $s) {
            $job['log'][] = 'skipped ' . $s['what'] . ': ' . $s['why'];
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $terms = self::terms($read['site'], $choices);
            $wanted = [];
            foreach ($sorted['rows'] as $row) {
                $wanted[(int) $row['id']] = $row;
            }
            // THE CONTENT, read again an item at a time: the plan kept none of it.
            Wxr::scan($path, static function (array $item) use (&$job, $wanted, $choices, $terms, $authorId): void {
                $row = $wanted[(int) $item['id']] ?? null;
                if ($row === null || $row['type'] !== $item['type']) {
                    return;
                }
                $job = self::write($job, $row, $item, $choices, $terms, $authorId);
            });
            self::parents($job);
            if ($job['files'] === []) {
                $job['phase'] = 'rewrite';
                while ($job['phase'] === 'rewrite') {
                    $job = self::rewriteSome($job, PHP_FLOAT_MAX);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'Nothing was imported. The import failed part-way and every change was rolled back: ' . $e->getMessage()];
        }

        self::save($job);
        self::forget($token);
        return ['ok' => true, 'token' => $token];
    }

    /**
     * The categories and tags, made where they are missing: WordPress's slug
     * => this site's term id, for each.
     *
     * @param  array<string, mixed> $site
     * @param  array<string, mixed> $choices
     * @return array{category: array<string, int>, post_tag: array<string, int>}
     */
    private static function terms(array $site, array $choices): array
    {
        $out = ['category' => [], 'post_tag' => []];
        if ($choices['categories'] !== '') {
            $left = $site['categories'];
            // PARENTS FIRST, however the file orders them: a pass places each
            // whose parent is placed or absent, until a pass places none.
            while ($left !== []) {
                $placed = 0;
                foreach ($left as $slug => $cat) {
                    $parent = (string) $cat['parent'];
                    if ($parent !== '' && isset($left[$parent]) && !isset($out['category'][$parent])) {
                        continue;
                    }
                    $out['category'][(string) $slug] = self::term($choices['categories'], (string) $slug, (string) $cat['name'],
                        (string) $cat['description'], $out['category'][$parent] ?? null);
                    unset($left[$slug]);
                    $placed++;
                }
                if ($placed === 0) {
                    foreach ($left as $slug => $cat) {   // a loop of parents: placed flat
                        $out['category'][(string) $slug] = self::term($choices['categories'], (string) $slug, (string) $cat['name'], (string) $cat['description'], null);
                    }
                    break;
                }
            }
        }
        if ($choices['tags'] !== '') {
            foreach ($site['tags'] as $slug => $tag) {
                $out['post_tag'][(string) $slug] = self::term($choices['tags'], (string) $slug, (string) $tag['name'], (string) $tag['description'], null);
            }
        }
        return $out;
    }

    /** A term here by its slug, made when it is missing. */
    private static function term(string $taxonomy, string $wpSlug, string $name, string $description, ?int $parent): int
    {
        $slug = Slug::clean(rawurldecode($wpSlug));
        $slug = $slug !== '' ? $slug : Term::generateSlug($name, $taxonomy);
        $here = Term::findBySlug($taxonomy, $slug);
        if ($here !== null) {
            return (int) $here['id'];
        }
        $hierarchical = (bool) (TaxonomyRegistry::get($taxonomy)['hierarchical'] ?? false);
        return Term::create([
            'taxonomy_key' => $taxonomy, 'name' => $name !== '' ? $name : $slug, 'slug' => $slug,
            'description'  => $description !== '' ? $description : null,
            'parent_id'    => $hierarchical ? $parent : null, 'sort_order' => 0,
        ]);
    }

    /**
     * One page or entry, created or overwritten as the plan said.
     *
     * @param  array<string, mixed> $job
     * @param  array<string, mixed> $row  `sort()`'s
     * @param  array<string, mixed> $item `Wxr`'s, with its content
     * @param  array<string, mixed> $choices
     * @param  array<string, array<string, int>> $terms
     * @return array<string, mixed> the job
     */
    private static function write(array $job, array $row, array $item, array $choices, array $terms, int $authorId): array
    {
        $notes   = [];
        $content = self::html((string) $item['content'], $notes, array_column($job['files'], 'url', 'wp'));
        // NO DRAFT HERE: Private is what is not on the site yet, and a draft is
        // that, with no date.
        $draft   = in_array($item['status'], ['draft', 'pending'], true);
        $status  = match (true) {
            $draft                                                                           => 'private',
            $choices['publish'] && $item['password'] !== '' && $item['status'] === 'publish' => 'password',
            $choices['publish'] && in_array($item['status'], ['publish', 'future'], true)   => 'published',
            default                                                                          => 'private',
        };
        $password = $item['password'] !== '' ? password_hash((string) $item['password'], PASSWORD_DEFAULT) : null;
        $title    = $item['title'] !== '' ? (string) $item['title'] : (string) $row['slug'];
        $thumb    = (int) ($item['meta']['_thumbnail_id'] ?? 0);

        if ($row['to'] === 'page') {
            $fields = [
                'title' => $title, 'slug' => $row['slug'], 'content' => $content, 'menu_order' => (int) $item['order'],
                'status' => $status, 'password_hash' => $password,
            ];
            if ($row['action'] === 'create') {
                $id = Page::create($fields + ['parent_id' => null, 'featured_image_id' => null, 'author_id' => $authorId]);
                $job['created']++;
                $job['log'][] = 'created page /' . $row['slug'];
            } else {
                $id   = (int) $row['here'];
                $here = Page::find($id) ?? [];
                Page::update($id, $fields + [
                    'parent_id' => $here['parent_id'] ?? null, 'featured_image_id' => $here['featured_image_id'] ?? null,
                    'seo_title' => $here['seo_title'] ?? null, 'meta_description' => $here['meta_description'] ?? null,
                ]);
                $job['overwritten']++;
                $job['log'][] = 'overwrote page /' . $row['slug'];
            }
            $job['made'][] = ['kind' => 'page', 'id' => $id, 'wp' => (int) $item['id'], 'parent' => (int) $item['parent'], 'thumb' => $thumb, 'title' => $title];
            $job['links'][self::linkKey((string) $item['link'])] = Url::pageOf($row);
            return $job;
        }

        $fields = [
            'post_type' => $row['to'], 'title' => $title, 'slug' => $row['slug'], 'content' => $content,
            'excerpt' => trim(strip_tags((string) $item['excerpt'])) !== '' ? trim(strip_tags((string) $item['excerpt'])) : null,
            'status' => $status, 'password_hash' => $password, 'menu_order' => (int) $item['order'],
            'published_at' => $draft ? null : self::utc($item),
        ];
        if ($row['action'] === 'create') {
            $id = Post::create($fields + ['author_id' => $authorId]);
            $job['created']++;
            $job['log'][] = 'created ' . $row['to'] . ' ' . $row['slug'];
        } else {
            $id = (int) $row['here'];
            unset($fields['post_type']);
            Post::update($id, $fields);
            $job['overwritten']++;
            $job['log'][] = 'overwrote ' . $row['to'] . ' ' . $row['slug'];
        }

        // ITS CATEGORIES AND TAGS, where its type has the taxonomy chosen.
        $here = TaxonomyRegistry::getForContentType((string) $row['to']);
        foreach (['category' => $choices['categories'], 'post_tag' => $choices['tags']] as $domain => $taxonomy) {
            if ($taxonomy === '' || !isset($here[$taxonomy])) {
                continue;
            }
            $ids = [];
            foreach ($item['terms'] as $t) {
                if ($t['domain'] !== $domain || $t['slug'] === '') {
                    continue;
                }
                $ids[] = $terms[$domain][$t['slug']] ?? self::term($taxonomy, (string) $t['slug'], (string) $t['name'], '', null);
            }
            if ($ids !== []) {
                try {
                    Term::setPostTerms($id, $taxonomy, array_values(array_unique($ids)));
                } catch (\DomainException $e) {
                    $job['log'][] = 'left ' . $row['slug'] . ' out of ' . $taxonomy . ': ' . $e->getMessage();
                }
            }
        }

        $publicSlug = (string) (Registry::get((string) $row['to'])['public_slug'] ?? '');
        $job['made'][] = ['kind' => 'entry', 'id' => $id, 'wp' => (int) $item['id'], 'parent' => 0, 'thumb' => $thumb, 'title' => $title];
        if ($publicSlug !== '') {
            $job['links'][self::linkKey((string) $item['link'])] = Url::entry($row, $publicSlug);
        }
        return $job;
    }

    /** Each page under the page it was under there, where that page came across. @param array<string, mixed> $job */
    private static function parents(array $job): void
    {
        $pages = [];
        foreach ($job['made'] as $m) {
            if ($m['kind'] === 'page') {
                $pages[$m['wp']] = $m;
            }
        }
        foreach ($pages as $m) {
            if ($m['parent'] === 0 || !isset($pages[$m['parent']]) || $pages[$m['parent']]['id'] === $m['id']) {
                continue;
            }
            $row = Page::find((int) $m['id']);
            if ($row !== null) {
                Page::update((int) $m['id'], ['parent_id' => (int) $pages[$m['parent']]['id']] + $row);
            }
        }
    }

    // ----------------------------------------------------------------
    // The job: the media, then the links
    // ----------------------------------------------------------------

    /** The job of an import, or null. @return array<string, mixed>|null */
    public static function job(string $token): ?array
    {
        if (!self::isToken($token)) {
            return null;
        }
        $raw = @file_get_contents(self::dir() . '/' . $token . '.json');
        $job = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($job) ? $job : null;
    }

    /**
     * Some seconds more of the job: files fetched, then the pages and entries
     * pointed at them. True once it is done.
     */
    public static function step(string $token, float $seconds): bool
    {
        if (!self::isToken($token)) {
            return true;
        }
        $lock = @fopen(self::dir() . '/' . $token . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return false;   // another step has it
        }
        try {
            $job = self::job($token);
            if ($job === null) {
                return true;
            }
            $until = microtime(true) + max(0.0, $seconds);
            while ($job['phase'] !== 'done' && microtime(true) < $until) {
                if ($job['phase'] === 'media') {
                    $job = self::fetchOne($job);
                    if ($job['offset'] >= count($job['files'])) {
                        $job['phase'] = 'rewrite';
                    }
                } else {
                    $job = self::rewriteSome($job, $until);
                }
                $job['touched'] = time();
                self::save($job);
            }
            return $job['phase'] === 'done';
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            if ((self::job($token)['phase'] ?? '') === 'done') {
                @unlink(self::dir() . '/' . $token . '.lock');
            }
        }
    }

    /** @param array<string, mixed> $job @return array<string, mixed> */
    private static function fetchOne(array $job): array
    {
        $file = $job['files'][$job['offset']];
        $job['offset']++;
        $temp = self::dir() . '/' . $job['token'] . '.part';
        @unlink($temp);
        $why = self::fetch((string) $file['url'], $temp);
        if ($why === null) {
            try {
                $name = rawurldecode(basename((string) parse_url((string) $file['url'], PHP_URL_PATH)));
                $id   = Media::importFile($temp, array_filter([
                    'original_name' => $name !== '' ? $name : 'file',
                    'alt_text'      => $file['alt'] !== '' ? $file['alt'] : null,
                    'created_at'    => $file['date'],
                ], static fn ($v): bool => $v !== null));
                $job['media'][(string) $file['wp']] = $id;
                $job['urls'][self::fileKey((string) $file['url'])] = Media::getUrl($id);
                $job['log'][] = 'fetched ' . $file['url'];
            } catch (MediaRejected $e) {
                $why = $e->getMessage();
            } catch (\Throwable $e) {
                $why = 'it could not be kept: ' . $e->getMessage();
            }
        }
        if ($why !== null) {
            $job['failed'][] = ['url' => (string) $file['url'], 'why' => $why];
            $job['log'][] = 'did not fetch ' . $file['url'] . ': ' . $why;
        }
        @unlink($temp);
        return $job;
    }

    /**
     * Point some of the imported pages and entries at what is here: pictures
     * and files at their copies, links at the pages and entries, featured
     * images, and galleries made galleries.
     *
     * @param  array<string, mixed> $job
     * @return array<string, mixed>
     */
    private static function rewriteSome(array $job, float $until): array
    {
        while ($job['rewritten'] < count($job['made'])) {
            $m = $job['made'][$job['rewritten']];
            $job['rewritten']++;
            if ($m['kind'] === 'page') {
                $row = Page::find((int) $m['id']);
                if ($row !== null) {
                    $html  = self::relink((string) $row['content'], $job, $m);
                    $thumb = (int) ($job['media'][(string) $m['thumb']] ?? 0);
                    if ($html !== (string) $row['content'] || $thumb > 0) {
                        Page::update((int) $m['id'], ['content' => $html, 'featured_image_id' => $thumb > 0 ? $thumb : $row['featured_image_id']] + $row);
                    }
                }
            } else {
                $row = Post::find((int) $m['id']);
                if ($row !== null) {
                    $html   = self::relink((string) $row['content'], $job, $m);
                    $thumb  = (int) ($job['media'][(string) $m['thumb']] ?? 0);
                    $change = ($html !== (string) $row['content'] ? ['content' => $html] : []) + ($thumb > 0 ? ['featured_image_id' => $thumb] : []);
                    if ($change !== []) {
                        Post::update((int) $m['id'], $change);
                    }
                }
            }
            if (microtime(true) >= $until) {
                break;
            }
        }
        if ($job['rewritten'] >= count($job['made'])) {
            $job['phase'] = 'done';
            $job['finished'] = time();
            $job['left'] = self::stillThere($job);
        }
        return $job;
    }

    /**
     * One page's or entry's HTML, pointed here.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $made
     */
    public static function relink(string $html, array $job, array $made): string
    {
        $bases = [];
        foreach ($job['urls'] as $key => $url) {
            $bases[self::baseKey((string) $key)] = $url;
        }
        // A PICTURE BY ITS NUMBER THERE (`wp-image-12`), before its address.
        $html = (string) preg_replace_callback('#<img\b[^>]*>#i', static function (array $m) use ($job): string {
            if (preg_match('/\bwp-image-(\d+)\b/', $m[0], $n) !== 1 || !isset($job['media'][$n[1]])) {
                return $m[0];
            }
            $url = Media::getUrl((int) $job['media'][$n[1]]);
            return $url === '' ? $m[0] : (string) preg_replace('/\bsrc="[^"]*"/', 'src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"', $m[0], 1);
        }, $html);
        // EVERY ADDRESS: a file there, any of its sizes; a page or post there.
        $html = (string) preg_replace_callback('#\b(src|href)="([^"]*)"#i', static function (array $m) use ($job, $bases): string {
            $raw = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $to  = $job['urls'][self::fileKey($raw)] ?? $bases[self::baseKey(self::fileKey($raw))] ?? null;
            if ($to === null && self::fromThere($raw, $job)) {
                $to = $job['links'][self::linkKey($raw)] ?? null;
            }
            return $to === null ? $m[0] : $m[1] . '="' . htmlspecialchars((string) $to, ENT_QUOTES, 'UTF-8') . '"';
        }, $html);
        // A GALLERY: its pictures here, made one of this site's galleries.
        return (string) preg_replace_callback('#\[gallery([^\]]*)\]#i', static function (array $m) use ($job, $made): string {
            $ids = [];
            if (preg_match('/\bids="([\d,\s]+)"/', $m[1], $list) === 1) {
                foreach (preg_split('/\s*,\s*/', trim($list[1])) ?: [] as $wp) {
                    if (isset($job['media'][$wp])) {
                        $ids[] = (int) $job['media'][$wp];
                    }
                }
            } else {
                $files = array_filter($job['files'], static fn (array $f): bool => (int) $f['parent'] === (int) $made['wp']);
                usort($files, static fn (array $a, array $b): int => [$a['order'], $a['wp']] <=> [$b['order'], $b['wp']]);
                foreach ($files as $f) {
                    if (isset($job['media'][(string) $f['wp']])) {
                        $ids[] = (int) $job['media'][(string) $f['wp']];
                    }
                }
            }
            if ($ids === []) {
                return '';
            }
            $gallery = Gallery::create(mb_substr((string) $made['title'], 0, 180) . ' (from WordPress)');
            Gallery::setItems($gallery, $ids);
            return '[gallery id="' . $gallery . '"]';
        }, $html);
    }

    /** A file's address without its scheme, as a key: http and https are one file. */
    private static function fileKey(string $url): string
    {
        return (string) preg_replace('#^https?://(www\.)?#i', '', strtok($url, '?#') ?: $url);
    }

    /** The same, without a size WordPress made (`-300x200`) or its "-scaled" original. */
    private static function baseKey(string $key): string
    {
        return (string) preg_replace('/(-\d+x\d+|-scaled)+(\.[a-z0-9]+)$/i', '$2', $key);
    }

    /** A page's or post's address there, as a key: no scheme, no www, no closing slash. */
    private static function linkKey(string $url): string
    {
        return rtrim(self::fileKey($url), '/');
    }

    /** @param array<string, mixed> $job */
    private static function fromThere(string $url, array $job): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach ([$job['site'], $job['blog']] as $site) {
            $there = strtolower((string) parse_url((string) $site, PHP_URL_HOST));
            if ($there !== '' && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $there)) {
                return true;
            }
        }
        return false;
    }

    /** How many pictures still point at the old site, after it is done. @param array<string, mixed> $job */
    private static function stillThere(array $job): int
    {
        $n = 0;
        foreach ($job['made'] as $m) {
            $row = $m['kind'] === 'page' ? Page::find((int) $m['id']) : Post::find((int) $m['id']);
            if ($row !== null && preg_match_all('#<img\b[^>]*\bsrc="([^"]*)"#i', (string) $row['content'], $srcs) > 0) {
                foreach ($srcs[1] as $src) {
                    $n += self::fromThere(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $job) ? 1 : 0;
                }
            }
        }
        return $n;
    }

    // ----------------------------------------------------------------
    // Fetching a file
    // ----------------------------------------------------------------

    /**
     * Fetch `$url` into `$to`: null when it is there, or why not, in words.
     * Only http(s), only a server on the public internet (a private address,
     * this server's own and the cloud's are refused, unless config.php's
     * `import_private_hosts` names the host), connected to the address that
     * was checked, a redirect checked again, and no more than the library
     * takes.
     */
    private static function fetch(string $url, string $to): ?string
    {
        if (self::$wire !== null) {
            return (self::$wire)($url, $to);
        }
        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            $parts = parse_url($url);
            $host  = strtolower((string) ($parts['host'] ?? ''));
            $https = strtolower((string) ($parts['scheme'] ?? '')) === 'https';
            if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $host === '') {
                return 'its address is not a web address';
            }
            $ip = self::address($host);
            if ($ip === null) {
                return 'its server could not be found, or is not on the public internet';
            }
            $port   = (int) ($parts['port'] ?? ($https ? 443 : 80));
            $target = ($https ? 'https' : 'http') . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port
                . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET', 'timeout' => self::FETCH_TIMEOUT, 'follow_location' => 0, 'ignore_errors' => true,
                    'header' => 'Host: ' . $host . (isset($parts['port']) ? ':' . $port : '') . "\r\nUser-Agent: CMS-import/" . \Cms\Update\Release::version() . "\r\n",
                ],
                'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $in = @fopen($target, 'rb', false, $ctx);
            if ($in === false) {
                $err = error_get_last();
                return 'its server did not answer' . ($err !== null ? ' (' . mb_substr((string) preg_replace('/^fopen\([^)]*\): /', '', (string) $err['message']), 0, 100) . ')' : '');
            }
            $meta   = stream_get_meta_data($in);
            $lines  = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
            $status = 0;
            $next   = null;
            foreach ($lines as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $s) === 1) {
                    $status = (int) $s[1];
                    $next   = null;
                } elseif (preg_match('/^Location:\s*(.+)$/i', (string) $line, $l) === 1) {
                    $next = trim($l[1]);
                }
            }
            if ($status >= 300 && $status < 400 && $next !== null) {
                fclose($in);
                $url = self::resolve($url, $next);
                continue;
            }
            if ($status !== 200) {
                fclose($in);
                return 'its server answered ' . ($status > 0 ? $status : 'nothing');
            }
            $out = fopen($to, 'wb');
            if ($out === false) {
                fclose($in);
                return 'it could not be written here';
            }
            $bytes = 0;
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > Media::MAX_FILE_SIZE) {
                    fclose($in);
                    fclose($out);
                    @unlink($to);
                    return 'it is larger than the library\'s 10 MB';
                }
                fwrite($out, $chunk);
            }
            fclose($in);
            fclose($out);
            return null;
        }
        return 'it is redirected too many times';
    }

    /** The public address to connect to for a host, or null. */
    private static function address(string $host): ?string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (@gethostbynamel($host) ?: []);
        if ($ips === []) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                if (isset($r['ipv6'])) {
                    $ips[] = (string) $r['ipv6'];
                }
            }
        }
        if ($ips === []) {
            return null;
        }
        $private = in_array($host, (array) (self::config()['import_private_hosts'] ?? []), true);
        $flags   = defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        foreach ($ips as $ip) {
            if (!$private && filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                return null;   // one private answer is enough to refuse: a name is not trusted half-way
            }
        }
        return $ips[0];
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', (string) ($p['path'] ?? '/'));
        return $origin . $dir . $location;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        static $config = null;
        if ($config === null) {
            $read   = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            $config = is_array($read) ? $read : [];
        }
        return $config;
    }

    // ----------------------------------------------------------------
    // Bookkeeping
    // ----------------------------------------------------------------

    private static function isToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{24}$/', $token) === 1;
    }

    private static function prepare(): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir . '.');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# Imports waiting to be applied. Never served.\nRequire all denied\n");
        }
    }

    /** Held exports and finished reports older than a day, gone. */
    private static function tidy(): void
    {
        foreach (glob(self::dir() . '/*.{xml,json,lock,part}', GLOB_BRACE) ?: [] as $file) {
            if (filemtime($file) < time() - self::KEPT_FOR) {
                @unlink($file);
            }
        }
    }

    /** @param array<string, mixed> $job */
    private static function save(array $job): void
    {
        self::prepare();
        $file = self::dir() . '/' . $job['token'] . '.json';
        if (@file_put_contents($file . '.tmp', (string) json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false
            || !@rename($file . '.tmp', $file)) {
            throw new RuntimeException('Could not keep the import\'s progress in ' . self::dir() . '.');
        }
    }
}
