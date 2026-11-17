<?php

declare(strict_types=1);

namespace Cms\SEO;

use Cms\Models\Option;

/**
 * Head — assembles all SEO meta tags for the public <head>.
 *
 * Single point of truth: called once from templates/base.php with `Head::render()`.
 * Nothing SEO-related is scattered across templates.
 *
 * Output order:
 *   0. Google's measurement tags, when Settings → SEO → Google has any: as
 *      high in the head as Google asks for them
 *   1. <title>
 *   2. <meta name="description">
 *   3. <meta name="robots">
 *   4. <link rel="canonical">
 *   5. Open Graph tags
 *   6. Twitter Card tags
 *   7. Schema JSON-LD block
 */
class Head
{
    /** @var array<string, string|null> SEO data for the current page */
    private static array $data = [];

    /**
     * Set the SEO data for the current page.
     * Called by the public controller before rendering.
     *
     * @param array<string, mixed> $data Keys: title, description, canonical,
     *   prev, next, robots, og_title, og_description, og_image, og_type, og_url,
     *   twitter_card, twitter_title, twitter_description, twitter_image,
     *   schema_json, and `feeds`: the types whose feeds the page names
     *   (`Content\Feed`)
     */
    public static function set(array $data): void
    {
        self::$data = $data;
    }

    /**
     * Render all SEO meta tags as an HTML string.
     */
    public static function render(): string
    {
        $d = self::$data;
        // 0. Google's measurement tags (`Site\Google`): first, as high in the
        // head as Google asks; once a page, on public pages only, and nothing
        // at all while none is typed.
        $html = \Cms\Site\Google::head();

        // 1. <title>
        $title = $d['title'] ?? '';
        if ($title !== '') {
            $html .= '<title>' . htmlspecialchars($title) . '</title>' . "\n";
        }

        // 2. <meta name="description">
        $description = $d['description'] ?? '';
        if ($description !== '') {
            $html .= '<meta name="description" content="' . htmlspecialchars($description) . '">' . "\n";
        }

        // 3. <meta name="robots">. A page shown through its share link
        // (`Content\Share`) is not for search engines, whatever it says.
        $robots = \Cms\Content\Share::shown() ? 'noindex, nofollow' : ($d['robots'] ?? 'index, follow');
        $html .= '<meta name="robots" content="' . htmlspecialchars($robots) . '">' . "\n";

        // 4. <link rel="canonical">
        $canonical = $d['canonical'] ?? '';
        if ($canonical !== '') {
            $html .= '<link rel="canonical" href="' . htmlspecialchars($canonical) . '">' . "\n";
        }

        // 4b. A PAGED LIST'S NEIGHBOURS — `rel="prev"` and `rel="next"`, from
        // `Helpers\Paging::head()`, so they cannot disagree with the pager
        // links the page itself prints.
        foreach (['prev', 'next'] as $rel) {
            $href = (string) ($d[$rel] ?? '');
            if ($href !== '') {
                $html .= '<link rel="' . $rel . '" href="' . htmlspecialchars($href) . '">' . "\n";
            }
        }

        // 4c. THE FEEDS OF WHAT THE PAGE LISTS (`Content\Feed`): the home
        // page's every one, a type's pages their type's; an events type's
        // calendar beside its feed (`Content\Calendar`).
        if (is_array($d['feeds'] ?? null) && $d['feeds'] !== []) {
            $html .= \Cms\Content\Feed::links(array_map('strval', $d['feeds']));
            $html .= \Cms\Content\Calendar::links(array_map('strval', $d['feeds']));
        }

        // 5. Open Graph tags
        $ogTitle = $d['og_title'] ?? $title;
        $ogDesc  = $d['og_description'] ?? $description;
        // THE SITE-WIDE FALLBACK IMAGE, `seo_og_image` from the SEO settings
        // screen. It applies only when the page has not set its own: a
        // per-entry featured image still wins, which is the order the Pages and
        // entry forms imply by putting the picker on the item.
        $ogImage = $d['og_image'] ?? '';
        if ($ogImage === '') {
            $ogImage = (string) (\Cms\Models\Option::get('seo_og_image', '') ?? '');
        }
        $ogType  = $d['og_type'] ?? 'website';
        $ogUrl   = $d['og_url'] ?? $canonical;

        if ($ogTitle !== '') {
            $html .= '<meta property="og:title" content="' . htmlspecialchars($ogTitle) . '">' . "\n";
        }
        if ($ogDesc !== '') {
            $html .= '<meta property="og:description" content="' . htmlspecialchars($ogDesc) . '">' . "\n";
        }
        if ($ogImage !== '') {
            $html .= '<meta property="og:image" content="' . htmlspecialchars($ogImage) . '">' . "\n";
        }
        $html .= '<meta property="og:type" content="' . htmlspecialchars($ogType) . '">' . "\n";
        if ($ogUrl !== '') {
            $html .= '<meta property="og:url" content="' . htmlspecialchars($ogUrl) . '">' . "\n";
        }

        // 6. Twitter Card tags
        $twitterCard  = $d['twitter_card'] ?? 'summary';
        $twitterTitle = $d['twitter_title'] ?? $ogTitle;
        $twitterDesc  = $d['twitter_description'] ?? $ogDesc;
        $twitterImage = $d['twitter_image'] ?? $ogImage;

        $html .= '<meta name="twitter:card" content="' . htmlspecialchars($twitterCard) . '">' . "\n";
        if ($twitterTitle !== '') {
            $html .= '<meta name="twitter:title" content="' . htmlspecialchars($twitterTitle) . '">' . "\n";
        }
        if ($twitterDesc !== '') {
            $html .= '<meta name="twitter:description" content="' . htmlspecialchars($twitterDesc) . '">' . "\n";
        }
        if ($twitterImage !== '') {
            $html .= '<meta name="twitter:image" content="' . htmlspecialchars($twitterImage) . '">' . "\n";
        }

        // 7. Schema JSON-LD
        $schemaJson = $d['schema_json'] ?? '';
        if ($schemaJson !== '') {
            $html .= '<script type="application/ld+json">' . $schemaJson . '</script>' . "\n";
        }

        return $html;
    }

    /**
     * Build a <title> string from a pattern and token values.
     *
     * Tokens: the names `variables()` lists — {title}, {sep}, {site_name}, …
     *
     * ALSO AN ITEM'S OWN DESCRIPTION. One `replace()` runs over a title and a
     * meta description alike: a variable typed into the Description box
     * resolves the same way it does in the Title box.
     *
     * @param string               $pattern  e.g. "{title} {sep} {site_name}"
     * @param array<string, string> $tokens  Token values keyed by token name (without braces)
     */
    public static function buildTitle(string $pattern, array $tokens): string
    {
        $result = $pattern;
        foreach ($tokens as $key => $value) {
            $result = str_replace('{' . $key . '}', $value, $result);
        }
        // Clean up empty tokens and double separators
        $result = preg_replace('/\{[a-z_]+\}/', '', $result) ?? $result;
        // AN EMPTY TOKEN IN THE MIDDLE leaves two separators with nothing
        // between them — "Articles |  | Site" on page 1 of a pattern that says
        // "{content_type_label} {sep} {page_num} {sep} {site_name}". One of the
        // pair goes. A separator is one that stands between spaces, so a hyphen
        // inside a word ("e-commerce") is never touched. `-` and `–` join the
        // list because a site's own titles use them.
        //
        // THE SITE'S OWN {sep} JOINS IT TOO, whichever of `separators()` it
        // is. Only that one: adding all fourteen would strip a trailing `*` or
        // `>` off a title an owner typed by hand.
        $class = '\|—–·»\-';
        $sep   = (string) ($tokens['sep'] ?? '');
        if ($sep !== '' && !str_contains('|—–·»-', $sep)) {
            $class .= preg_quote($sep, '/');
        }
        $result = preg_replace('/\s+[' . $class . '](?=\s+[' . $class . '](\s|$))/u', '', $result) ?? $result;
        $result = preg_replace('/\s*[' . $class . ']\s*$/u', '', $result) ?? $result;
        $result = preg_replace('/^\s*[' . $class . ']\s*/u', '', $result) ?? $result;
        return trim((string) preg_replace('/\s{2,}/', ' ', $result));
    }

    /**
     * Get global SEO tokens from the options table.
     *
     * @return array<string, string>
     */
    public static function globalTokens(): array
    {
        $opts = Option::getMany([
            'site_name'     => '',
            'site_tagline'  => '',
            'seo_separator' => '|',
        ]);

        // THE SITE TITLE IS THE SITE'S NAME EVERYWHERE — admin bar, sign-in,
        // tab titles, mail, schema. The CMS is white-label: it never falls
        // back to the product's name. Unset, it is the site's own host name.
        $name = trim((string) ($opts['site_name'] ?? ''));
        if ($name === '') {
            $name = (string) parse_url(defined('BASE_URL') ? BASE_URL : '', PHP_URL_HOST);
        }

        return [
            'site_name' => $name,
            'tagline'   => $opts['site_tagline'] ?? '',
            'sep'       => self::separatorFrom((string) ($opts['seo_separator'] ?? '')),
        ];
    }

    /**
     * THE SEPARATORS — the fourteen characters Settings → SEO offers as the
     * Separator, each with its name.
     *
     * @return array<string, string> character => its name
     */
    public static function separators(): array
    {
        return [
            '-' => 'Dash',
            '–' => 'En dash',
            '—' => 'Em dash',
            ':' => 'Colon',
            '·' => 'Middle dot',
            '•' => 'Bullet',
            '*' => 'Asterisk',
            '⋆' => 'Low asterisk',
            '|' => 'Vertical bar',
            '~' => 'Small tilde',
            '«' => 'Left angle quotation mark',
            '»' => 'Right angle quotation mark',
            '<' => 'Less-than sign',
            '>' => 'Greater-than sign',
        ];
    }

    /**
     * WHAT {sep} READS AS. Migration 021 seeded the option as " | ", spaces
     * and all, when nothing read it; the patterns put their own spaces around
     * the variable, so the character is what counts. Unset, it is the bar —
     * the character every seeded pattern used before it was a variable, so a
     * site that never opens the setting keeps the titles it had.
     */
    private static function separatorFrom(string $stored): string
    {
        $sep = trim($stored);
        return $sep !== '' ? $sep : '|';
    }

    /** The site's separator, as `{sep}` prints it. */
    public static function separator(): string
    {
        return self::separatorFrom((string) (Option::get('seo_separator', '|') ?? ''));
    }

    /**
     * THE VARIABLES — one list: the name a pattern stores, the label its pill
     * shows, and the contexts whose controller SUPPLIES it.
     *
     * `contexts` IS A PROMISE. A variable offered where nothing supplies it is
     * removed when the title is built, so the pill would do nothing — it would
     * be a control that lies. Per controller: every context gets
     * `globalTokens()` (site_name, tagline, sep); Page and Post add title,
     * Post and the type archive add content_type_label, Term adds term_name and
     * taxonomy_label, and the three paged lists add page_num.
     *
     * @return array<string, array{label: string, contexts: list<string>}>
     */
    public static function variables(): array
    {
        $everywhere = array_keys(self::titlePatterns());

        return [
            'title'              => ['label' => 'Title',        'contexts' => ['page', 'content_type']],
            'site_name'          => ['label' => 'Site title',   'contexts' => $everywhere],
            'tagline'            => ['label' => 'Tagline',      'contexts' => $everywhere],
            'term_name'          => ['label' => 'Term title',   'contexts' => ['term']],
            'taxonomy_label'     => ['label' => 'Taxonomy',     'contexts' => ['term']],
            'sep'                => ['label' => 'Separator',    'contexts' => $everywhere],
            'content_type_label' => ['label' => 'Content type', 'contexts' => ['content_type', 'archive']],
            'page_num'           => ['label' => 'Page',         'contexts' => ['archive', 'term', 'home']],
        ];
    }

    /**
     * The variables one context supplies, in the menu's order — what the
     * pill editor on that context's fields offers.
     *
     * @param string $context a key of titlePatterns()
     * @return list<array{name: string, label: string}>
     */
    public static function variablesFor(string $context): array
    {
        $out = [];
        foreach (self::variables() as $name => $variable) {
            if (in_array($context, $variable['contexts'], true)) {
                $out[] = ['name' => $name, 'label' => $variable['label']];
            }
        }
        return $out;
    }

    /**
     * AN ITEM'S OWN TITLE, AS IT IS STORED — empty when it is the pattern.
     *
     * The Search engines panel STARTS FROM the pattern, so saving a page
     * without touching the box posts the pattern straight back. Storing that
     * would freeze today's pattern onto the page, and a later change on
     * Settings → SEO would skip it without a word. Compared with runs of
     * whitespace squeezed, because the field puts a space after each variable
     * it inserts.
     *
     * @param string $context a key of titlePatterns()
     */
    public static function ownTitle(string $posted, string $context): string
    {
        $squeeze = static fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', $s));
        $posted  = $squeeze($posted);

        return $posted === $squeeze(self::titlePattern($context)) ? '' : $posted;
    }

    /**
     * THE TITLE PATTERNS — one list: each context, the label the SEO screen
     * gives it, and its default. The option is `seo_pattern_{context}`.
     *
     * One list, because four — this method's defaults, `SettingsController`'s
     * keys and its saver, and the SEO screen's own loop — would make every new
     * context four edits, and a copy that is missed is a box the owner types
     * into that nothing saves, or a pattern the screen does not offer. Migration
     * 021 seeded the first four as rows; migration 057 upgrades the two paged
     * ones (below) only where they still hold that seed.
     *
     * `{page_num}` IS IN EVERY PAGED LIST'S DEFAULT. Page 2 has its own
     * canonical, so it needs its own title. On page 1 the token is empty and
     * `buildTitle()` closes the gap.
     *
     * `{sep}` STANDS BETWEEN THE PARTS, so the Separator setting reaches every
     * default title. An older seed holds a literal " | " — migration 059 swaps
     * `{sep}` in wherever a row still holds it, and with the separator unset
     * `{sep}` prints that same bar.
     *
     * @return array<string, array{label: string, default: string}>
     */
    public static function titlePatterns(): array
    {
        return [
            'page'         => ['label' => 'Pages',         'default' => '{title} {sep} {site_name}'],
            'content_type' => ['label' => 'Entries',       'default' => '{title} {sep} {content_type_label} {sep} {site_name}'],
            'archive'      => ['label' => 'Archives',      'default' => '{content_type_label} {sep} {page_num} {sep} {site_name}'],
            'term'         => ['label' => 'Term archives', 'default' => '{term_name} {sep} {page_num} {sep} {site_name}'],
            'home'         => ['label' => 'Home',          'default' => '{site_name} {sep} {page_num} {sep} {tagline}'],
        ];
    }

    /**
     * The title pattern for a context: the owner's, or the default.
     *
     * @param string $context a key of titlePatterns()
     */
    public static function titlePattern(string $context): string
    {
        $default = self::titlePatterns()[$context]['default'] ?? '{title} {sep} {site_name}';
        $saved   = trim((string) (Option::get('seo_pattern_' . $context, $default) ?? ''));
        return $saved !== '' ? $saved : $default;
    }
}
