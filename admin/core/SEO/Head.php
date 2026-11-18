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
     *   schema_json, `feeds`: the types whose feeds the page names
     *   (`Content\Feed`), and `alternates`: the page in each language, tag
     *   => address, the site's own first (`Site\Language::versions()`)
     */
    public static function set(array $data): void
    {
        self::$data = $data;
    }

    /**
     * The page being drawn in each language it is in, tag => address, the
     * site's own first: what `set()` was given, empty for a page in one.
     *
     * @return array<string, string>
     */
    public static function alternates(): array
    {
        return is_array(self::$data['alternates'] ?? null) ? self::$data['alternates'] : [];
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

        // 4a. THE PAGE IN EACH LANGUAGE IT IS IN (`Site\Language`): one
        // alternate for each, and the site's own language's as the address
        // for any other reader (x-default).
        $alternates = self::alternates();
        if (count($alternates) > 1) {
            foreach ($alternates as $tag => $url) {
                $html .= '<link rel="alternate" hreflang="' . htmlspecialchars((string) $tag) . '" href="' . htmlspecialchars((string) $url) . '">' . "\n";
            }
            $html .= '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars((string) reset($alternates)) . '">' . "\n";
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
            $ogImage = self::siteImage((string) (\Cms\Models\Option::get('seo_og_image', '') ?? ''));
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
     * WHAT AN ENTRY'S OWN PAGE PUTS IN ITS HEAD, resolved: its title (its own,
     * or its type's pattern, every variable filled), its description (its
     * own, or else its excerpt), its robots, its social image (its own, or
     * else its featured image, as an address) and its canonical address. For
     * a site that draws its own head around an entry it reads through the
     * models, whose `_seo_title` is as it was typed, `{sep}` and all. The
     * entry's own page reads it here too, so the two cannot disagree.
     *
     * VARIABLES AND ALL: the Search engines panel edits the title and the
     * description as pills, so `{title}` or `{sep}` typed there resolves here
     * as the pattern's do, and an empty title is one left on the pattern
     * (`ownTitle()`). The excerpt stands in for no description because it is
     * a summary the owner already wrote; it is prose, not a pattern, so its
     * braces are left as they are. The social image is stored as a picture's
     * id; a full address, from an import or from before the form wrote ids,
     * is used as it is.
     *
     * @param array<string, mixed>      $post a row of `posts` (`Post::find()`)
     * @param array<string, mixed>|null $meta its meta, when the caller has read it already
     * @return array{title: string, description: string, robots: string, image: string, canonical: string}
     */
    public static function forEntry(array $post, ?array $meta = null): array
    {
        $key    = (string) ($post['post_type'] ?? '');
        $type   = \Cms\ContentType\Registry::get($key) ?? [];
        $meta ??= \Cms\Models\Meta::getAll((int) ($post['id'] ?? 0));
        $tokens = self::globalTokens();
        $tokens['title']              = (string) ($post['title'] ?? '');
        $tokens['content_type_label'] = (string) ($type['label'] ?? ucfirst($key));

        $own         = trim((string) ($meta['_seo_title'] ?? ''));
        $description = self::buildTitle(trim((string) ($meta['_meta_description'] ?? '')), $tokens);
        if ($description === '') {
            $description = trim((string) ($post['excerpt'] ?? ''));
        }
        $image = (string) ($meta['_og_image'] ?? '');
        if (ctype_digit($image)) {
            $image = \Cms\Models\Media::getUrl((int) $image);
        }
        if ($image === '' && !empty($post['featured_image_id'])) {
            $image = \Cms\Models\Media::getUrl((int) $post['featured_image_id']);
        }

        return [
            'title'       => self::buildTitle($own !== '' ? $own : self::titlePattern('content_type'), $tokens),
            'description' => $description,
            'robots'      => (string) ($meta['_seo_robots'] ?? 'index, follow'),
            'image'       => $image,
            // `Url::entry()`: the one builder of an entry's address, in its language.
            'canonical'   => \Cms\Helpers\Url::entry($post, (string) ($type['public_slug'] ?? $key)),
        ];
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
        //
        // IN THE PAGE'S LANGUAGE: on a page drawn in the second language, the
        // title and tagline it has in that language (`Site\Language::words()`).
        $name = trim(\Cms\Site\Language::words('site_name', (string) ($opts['site_name'] ?? '')));
        if ($name === '') {
            $name = (string) parse_url(defined('BASE_URL') ? BASE_URL : '', PHP_URL_HOST);
        }

        return [
            'site_name' => $name,
            'tagline'   => \Cms\Site\Language::words('site_tagline', (string) ($opts['site_tagline'] ?? '')),
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

    /**
     * A SITE-WIDE IMAGE FROM SETTINGS → SEO (`seo_og_image`, `seo_org_logo`),
     * as a page prints it: the picture the media library keeps under the
     * stored id, at its address now, so a file renamed or a site moved to its
     * own domain keeps it; or an address outside the library, kept from before
     * there was a picker, as it is. '' for none, and for an id that is no
     * public picture.
     */
    public static function siteImage(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '' || !ctype_digit($stored)) {
            return $stored;
        }
        $row = \Cms\Models\Media::find((int) $stored);

        return $row !== null && ($row['visibility'] ?? 'public') === 'public' && str_starts_with((string) ($row['mime_type'] ?? ''), 'image/')
            ? \Cms\Models\Media::urlOf($row)
            : '';
    }

}
