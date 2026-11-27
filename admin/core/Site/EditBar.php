<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;

/**
 * EDITING FROM THE PUBLIC SITE: staff who find what to change on the site
 * itself, a page or an event, get a way to its editor from there.
 *
 * For a signed-in account, and for it alone, a public page carries a small
 * bar at its top, with Edit this page (or this entry, or this list), and a
 * theme prints a pencil beside each entry it lists with `pencil($post)`,
 * which leads to that entry's editor and prints nothing for anyone who may
 * not change it (`Auth::canChange()`).
 *
 * ── ONLY IN A PAGE ANSWERED TO A SIGNED-IN ACCOUNT ──────────────────────────
 * The session cookie is the whole site's, and a public page resumes a session
 * its visitor already carries (`Security\Session::begin()`), so the server
 * knows who is asking with no script asking it. Such an answer is sent
 * `no-store, private` (`begin()`, from the front controller), so no browser or
 * proxy keeps it for anyone else; a visitor carries no session, so their page
 * holds nothing of this, not even a script; and a static copy draws each page
 * in a process of its own with no session, and is never signed in.
 *
 * ── PRINTED THROUGH THE HEAD EVERY THEME PRINTS ─────────────────────────────
 * `head()` is part of `SEO\Head::render()`, framed and whole-page themes
 * alike: the stylesheet, the script, and the bar's links as data, which the
 * script draws at the top of the page. The stylesheet and the script name no
 * address of the admin's or of the sign-in page, so a visitor who fetches
 * them learns nothing from them.
 */
final class EditBar
{
    /** @var list<array{label: string, url: string}> what this page's bar offers */
    private static array $links = [];

    private static bool $printed = false;

    /** Whether this answer is to a signed-in account, on a public page, and not for a static copy. */
    public static function shown(): bool
    {
        return !defined('CMS_EXPORT')
            && session_status() === PHP_SESSION_ACTIVE
            && Auth::check()
            && Google::isPublic();
    }

    /**
     * The front controller's call, before anything is printed: an answer
     * to a signed-in account is never kept by a browser or a proxy for anyone
     * else, since it may carry the bar and the pencils.
     */
    public static function begin(): void
    {
        if (self::shown() && !headers_sent()) {
            \Cms\Http\Cache::noStore(true);
        }
    }

    /**
     * A page's own: Edit this page, for an editor; and Edit {list} for each
     * list the page prints (`PageLists::forPage()`, the theme's
     * `page_lists`): a type's entries for anyone signed in, a taxonomy's
     * terms for an editor, as their screens allow.
     */
    public static function forPage(array $page): void
    {
        if (!self::shown()) {
            return;
        }
        if (Auth::hasRole('editor') && isset($page['id'])) {
            self::$links[] = ['label' => 'Edit this page', 'url' => Url::admin('pages/' . (int) $page['id'] . '/edit')];
        }
        foreach (PageLists::forPage($page) as $list) {
            if (($list['kind'] ?? '') === 'terms' && !Auth::hasRole('editor')) {
                continue;
            }
            self::$links[] = ['label' => 'Edit ' . (string) $list['plural'], 'url' => (string) $list['open']];
        }
    }

    /** An entry's own page: Edit this {entry}, for whoever may change it. */
    public static function forEntry(array $post): void
    {
        $url = self::editorOf($post);
        if ($url !== null) {
            $type = Registry::get((string) $post['post_type']);
            self::$links[] = ['label' => 'Edit this ' . strtolower((string) ($type['label_single'] ?? 'entry')), 'url' => $url];
        }
    }

    /** A type's list of entries: Edit {its entries}, its list in the admin. */
    public static function forList(string $typeKey): void
    {
        $type = Registry::get($typeKey);
        if (self::shown() && $type !== null) {
            self::$links[] = ['label' => 'Edit ' . (string) $type['label'], 'url' => Url::admin((string) $type['admin_slug'])];
        }
    }

    /** A term's page: Edit this {term}, for an editor. */
    public static function forTerm(array $term): void
    {
        $tax = TaxonomyRegistry::get((string) ($term['taxonomy_key'] ?? ''));
        if (self::shown() && $tax !== null && Auth::hasRole('editor') && isset($term['id'])) {
            self::$links[] = ['label' => 'Edit this ' . strtolower((string) $tax['label_single']),
                              'url'   => Url::admin('taxonomies/' . (string) $tax['admin_slug'] . '/terms/' . (int) $term['id'] . '/edit')];
        }
    }

    /**
     * A PENCIL BESIDE AN ENTRY A THEME PRINTS, to that entry's editor:
     * `<?= \Cms\Site\EditBar::pencil($post) ?>`. Nothing for a visitor, in a
     * static copy, or for an account that may not change the entry.
     *
     * @param array<string, mixed> $post a row of `posts`
     */
    public static function pencil(array $post): string
    {
        $url = self::editorOf($post);
        if ($url === null) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return '<a class="cms-edit-pencil" href="' . $e($url) . '" aria-label="' . $e('Edit “' . (string) ($post['title'] ?? '') . '”') . '">'
            . \Cms\Ui\Icons::render('pen', 'cms-edit-pencil__icon', '', 14) . '</a>';
    }

    /**
     * What `SEO\Head::render()` prints for it: the stylesheet, the script and
     * the bar's links, once a page, and only for a signed-in account. A site
     * whose Content-Security-Policy asks for a nonce prints
     * `EditBar::head($nonce)` in its own head first, as it hands Google's
     * tags theirs, and each script carries it.
     */
    public static function head(string $nonce = ''): string
    {
        if (self::$printed || !self::shown()) {
            return '';
        }
        self::$printed = true;
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $n    = $nonce !== '' ? ' nonce="' . $e($nonce) . '"' : '';
        $data = json_encode(['links' => self::$links], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<link rel="stylesheet" href="' . $e(Asset::sitePath('assets/css/edit-bar.css')) . '">' . "\n"
             . '<script src="' . $e(Asset::sitePath('assets/js/edit-bar.js')) . '"' . $n . ' defer></script>' . "\n"
             . '<script type="application/json" id="cms-edit-bar"' . $n . '>' . $data . '</script>' . "\n";
    }

    /** Forget this request's links, for one that draws a second page. */
    public static function reset(): void
    {
        self::$links   = [];
        self::$printed = false;
    }

    /** An entry's editor, when the signed-in account may change it; else null. */
    private static function editorOf(array $post): ?string
    {
        if (!self::shown() || !isset($post['id'], $post['post_type']) || !Auth::canChange($post)) {
            return null;
        }
        $type = Registry::get((string) $post['post_type']);

        return $type !== null ? Url::admin((string) $type['admin_slug'] . '/' . (int) $post['id'] . '/edit') : null;
    }
}
