<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Helpers\Asset;
use Cms\Models\Option;
use Cms\Security\LoginUrl;

/**
 * GOOGLE'S MEASUREMENT TAGS: a GA4 measurement ID, a Tag Manager container ID
 * and a Search Console verification code, typed on Settings → SEO → Google,
 * and the code Google gives out for each, on the site's public pages.
 *
 * WHERE THEY PRINT. `Head::render()` prints `head()` on every framed page, and
 * `base.php` prints `body()` straight after `<body>`. A whole-page theme, the
 * CMS's own 404 page and a site that keeps its own router call the same two
 * in their own markup:
 *
 *     <head> … <?= \Cms\Site\Google::head() ?> </head>
 *     <body><?= \Cms\Site\Google::body() ?> …
 *
 * Each prints once a request, whoever calls it and however often, so a page
 * that reaches both its own call and `Head::render()` still has one of each.
 * Never in the admin, on the sign-in page, or anywhere under the admin's
 * address: there they print nothing.
 *
 * WITH NONE TYPED, NOTHING: no Google address on any page and no script of
 * the CMS's either. A site whose own header already prints its tags leaves
 * them empty, so they are never on a page twice.
 *
 * ONLY IN PRODUCTION (`app_env`). Anywhere else the same tags print inside a
 * comment, so a copy of the site can be compared with the live one, and
 * nothing is sent.
 *
 * NO INLINE SCRIPT. What Google's snippets do inline (the data layer,
 * `gtag()` and `gtag('js')`) is done by `measure.js`, a file on this site,
 * which reads the IDs from its own tag; Google's own loader for each ID is
 * printed beside it, as Google's current snippets write it. A page
 * whose Content-Security-Policy allows this site's scripts and Google's hosts
 * needs nothing more. A site whose policy asks for a nonce hands it to
 * `head()`, and every script printed here carries it.
 *
 * CONVERSIONS. A real submission of a form that names an event (the form's
 * After submitting; `generate_lead` until changed) is sent once, however the
 * form finishes: in the JSON answer its script reads, on the CMS's "Sent"
 * page, or, after a redirect, by a cookie the next page reads and clears.
 * Spam, the thank-you a caught bot is shown, and a refused submission send
 * nothing. A site's own thank-you page can ask with `conversion()`.
 */
final class Google
{
    /** The options, typed on Settings → SEO → Google. */
    public const KEYS = [
        'google_ga4_id'            => '',
        'google_gtm_id'            => '',
        'google_site_verification' => '',
        'google_click_events'      => '0',
    ];

    /** A conversion waiting for the page a form's redirect lands on. */
    public const COOKIE = 'cms_conversion';

    /** The event a form sends until its own is typed: GA4's recommended event for a lead. */
    public const DEFAULT_EVENT = 'generate_lead';

    /** How long a conversion waits for its page, in seconds. A redirect is followed at once. */
    private const WAIT = 300;

    private static bool $headDone = false;
    private static bool $bodyDone = false;

    /** @var array<string, string>|null */
    private static ?array $ids = null;
    private static ?bool $production = null;

    /** The conversion this request answers with, for the "Sent" page. @var array{event: string, form: int}|null */
    private static ?array $sent = null;

    /** Whether this request has taken the waiting conversion already. */
    private static bool $taken = false;

    // ----------------------------------------------------------------
    // What may be typed
    // ----------------------------------------------------------------

    /** A GA4 measurement ID, as GA4 writes it (`G-` and letters and digits), or null. */
    public static function analyticsId(string $typed): ?string
    {
        $id = strtoupper(trim($typed));
        return preg_match('/^G-[A-Z0-9]{4,15}$/D', $id) === 1 ? $id : null;
    }

    /** A Tag Manager container ID (`GTM-` and letters and digits), or null. */
    public static function tagManagerId(string $typed): ?string
    {
        $id = strtoupper(trim($typed));
        return preg_match('/^GTM-[A-Z0-9]{4,15}$/D', $id) === 1 ? $id : null;
    }

    /**
     * A Search Console verification code, or null. Search Console's "HTML tag"
     * method hands out a whole meta tag; pasted as it is, it is read for its
     * code, and nothing else of it is kept.
     */
    public static function verificationCode(string $typed): ?string
    {
        $typed = trim($typed);
        if (preg_match('/^<meta\s[^<>]*>$/i', $typed) === 1) {
            if (preg_match('/\sname\s*=\s*(["\'])google-site-verification\1/i', $typed) !== 1
                || preg_match('/\scontent\s*=\s*(["\'])([^"\']*)\1/i', $typed, $m) !== 1) {
                return null;
            }
            $typed = trim($m[2]);
        }
        return preg_match('/^[A-Za-z0-9_-]{10,100}$/D', $typed) === 1 ? $typed : null;
    }

    /** The event names GA4 keeps for itself on the web (its reserved names). */
    private const RESERVED_EVENTS = [
        'ad_impression', 'app_remove', 'app_store_refund', 'app_store_subscription_cancel', 'app_store_subscription_renew',
        'click', 'error', 'file_download', 'first_open', 'first_visit', 'form_start', 'form_submit', 'in_app_purchase',
        'page_view', 'scroll', 'session_start', 'user_engagement', 'view_complete', 'video_progress', 'video_start',
        'view_search_results',
    ];

    /**
     * An event a form may send: GA4's rule for an event's name (a letter, then
     * letters, digits and underscores, up to 40), and not one GA4 keeps for
     * itself: a reserved name, or a reserved prefix. Null when it is not one.
     */
    public static function eventName(string $typed): ?string
    {
        $name = trim($typed);
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $name) === 1
            && !in_array($name, self::RESERVED_EVENTS, true)
            && preg_match('/^(query_id|google_|ga_|firebase_)/i', $name) !== 1 ? $name : null;
    }

    // ----------------------------------------------------------------
    // The page
    // ----------------------------------------------------------------

    /**
     * The head's tags, once a request: the verification on the home page, this
     * site's `measure.js` with the IDs on it, and Google's loaders.
     */
    public static function head(string $nonce = ''): string
    {
        if (self::$headDone || !self::isPublic()) {
            return '';
        }
        self::$headDone = true;

        $ids  = self::ids();
        $wait = self::waiting();
        $at   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $n    = $nonce !== '' ? ' nonce="' . $at($nonce) . '"' : '';

        $tags = [];
        if ($ids['google_site_verification'] !== '' && self::isHome()) {
            $tags[] = '<meta name="google-site-verification" content="' . $at($ids['google_site_verification']) . '">';
        }
        if ($ids['google_ga4_id'] !== '' || $ids['google_gtm_id'] !== '') {
            $data = '';
            foreach (['ga4' => $ids['google_ga4_id'], 'gtm' => $ids['google_gtm_id']] as $name => $id) {
                if ($id !== '') {
                    $data .= ' data-' . $name . '="' . $at($id) . '"';
                }
            }
            if ($ids['google_click_events'] === '1') {
                $data .= ' data-clicks';
            }
            if ($wait !== null) {
                $data .= ' data-conversion="' . $at($wait['event']) . '" data-form="' . $wait['form'] . '"';
            }
            $data .= ' data-path="' . $at(self::cookiePath()) . '"';
            // FIRST, AND NOT async: the data layer and `gtag('js')` are there
            // before Google's loader below runs, as in Google's own snippet.
            $tags[] = '<script src="' . $at(Asset::sitePath('assets/js/measure.js')) . '"' . $data . $n . '></script>';
            // GOOGLE'S LOADER FOR EACH ID, as its current snippets write it:
            // the Google tag and a Tag Manager container both load gtm.js.
            foreach (['google_ga4_id', 'google_gtm_id'] as $key) {
                if ($ids[$key] !== '') {
                    $tags[] = '<script async src="https://www.googletagmanager.com/gtm.js?id=' . $at($ids[$key]) . '"' . $n . '></script>';
                }
            }
        }

        return self::print($tags);
    }

    /** Tag Manager's `<noscript>`, for straight after `<body>`, once a request. */
    public static function body(): string
    {
        if (self::$bodyDone || !self::isPublic()) {
            return '';
        }
        self::$bodyDone = true;

        $id = self::ids()['google_gtm_id'];
        if ($id === '') {
            return '';
        }
        return self::print(['<noscript><iframe src="https://www.googletagmanager.com/ns.html?id='
            . htmlspecialchars($id, ENT_QUOTES, 'UTF-8')
            . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>']);
    }

    /**
     * THE CONVERSION A FORM'S REDIRECT LEFT FOR THIS PAGE, for a site's own
     * thank-you page that sends it in its own way: its event and the form's
     * ID, or null. Asking takes it, so `head()` will not send it as well, and
     * clears its cookie: ask before the page's first byte.
     *
     * @return array{event: string, form: int}|null
     */
    public static function conversion(): ?array
    {
        return self::waiting();
    }

    // ----------------------------------------------------------------
    // A form's real submission
    // ----------------------------------------------------------------

    /**
     * The conversion a real submission of this form sends, or null: none when
     * the form names no event, or when neither Analytics nor Tag Manager has
     * an ID. `id` tells one submission from another, so the script sends each
     * one once.
     *
     * @param array<string, mixed> $form
     * @return array{event: string, form: int, id: string}|null
     */
    public static function conversionFor(array $form): ?array
    {
        $ids = self::ids();
        if ($ids['google_ga4_id'] === '' && $ids['google_gtm_id'] === '') {
            return null;
        }
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $event    = self::eventName((string) ($settings['conversion_event'] ?? self::DEFAULT_EVENT));
        if ($event === null) {
            return null;
        }
        return ['event' => $event, 'form' => (int) ($form['id'] ?? 0), 'id' => bin2hex(random_bytes(8))];
    }

    /** The answer is this request's own page, the CMS's "Sent": its head sends the conversion. */
    public static function sentHere(array $conversion): void
    {
        self::$sent = ['event' => (string) $conversion['event'], 'form' => (int) $conversion['form']];
    }

    /** The answer is a redirect: the conversion waits for the page it lands on, in a cookie. */
    public static function sendAfterRedirect(array $conversion): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $conversion['event'] . ':' . (int) $conversion['form'], [
            'expires'  => time() + self::WAIT,
            'path'     => self::cookiePath(),
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            // The page's script clears it as well, for a page whose first
            // bytes were sent before the CMS could.
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    /** Whether this copy of the site sends them: `app_env` is production. */
    public static function sends(): bool
    {
        return self::production();
    }

    // ----------------------------------------------------------------
    // Inside
    // ----------------------------------------------------------------

    /**
     * Only what is typed and still valid: a value put in the database by hand
     * is read by the same rules the screen saves by.
     *
     * @return array<string, string>
     */
    private static function ids(): array
    {
        if (self::$ids !== null) {
            return self::$ids;
        }
        $stored = Option::getMany(self::KEYS);
        return self::$ids = [
            'google_ga4_id'            => self::analyticsId((string) $stored['google_ga4_id']) ?? '',
            'google_gtm_id'            => self::tagManagerId((string) $stored['google_gtm_id']) ?? '',
            'google_site_verification' => self::verificationCode((string) $stored['google_site_verification']) ?? '',
            'google_click_events'      => $stored['google_click_events'] === '1' ? '1' : '0',
        ];
    }

    /**
     * The tags, or, outside production, the same tags inside a comment that
     * says why they were not sent.
     *
     * @param list<string> $tags
     */
    private static function print(array $tags): string
    {
        if ($tags === []) {
            return '';
        }
        if (self::production()) {
            return implode("\n", $tags) . "\n";
        }
        return "<!-- Google's tags, not sent: this site's app_env is not production. In production this page prints:\n"
            . implode("\n", $tags) . "\n-->\n";
    }

    /**
     * The conversion this page sends: the "Sent" page's own, or the one a
     * redirect left in the cookie, which is cleared as it is read. Null for
     * any other page, and once it has been taken.
     *
     * @return array{event: string, form: int}|null
     */
    private static function waiting(): ?array
    {
        if (self::$taken) {
            return null;
        }
        if (self::$sent !== null) {
            self::$taken = true;
            return self::$sent;
        }
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($cookie === '') {
            return null;
        }
        self::$taken = true;
        unset($_COOKIE[self::COOKIE]);
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => self::cookiePath(), 'samesite' => 'Lax']);
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9_]{0,39}):(\d{1,10})$/D', $cookie, $m) !== 1 || self::eventName($m[1]) === null) {
            return null;
        }
        return ['event' => $m[1], 'form' => (int) $m[2]];
    }

    /** The request's path below the site's own, with no query and no trailing slash. */
    private static function path(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }
        return '/' . trim($path, '/');
    }

    /** A public page: not the admin, nothing under its address, not the sign-in page. */
    private static function isPublic(): bool
    {
        $path  = self::path();
        $admin = '/' . trim(defined('ADMIN_URL_SLUG') ? (string) ADMIN_URL_SLUG : 'admin', '/');
        if ($path === $admin || str_starts_with($path, $admin . '/')) {
            return false;
        }
        return !(LoginUrl::enabled() && $path === LoginUrl::path());
    }

    private static function isHome(): bool
    {
        return self::path() === '/';
    }

    private static function cookiePath(): string
    {
        $base = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        return $base === '' ? '/' : $base . '/';
    }

    /** `app_env` is exactly "production", read from config.php once. */
    private static function production(): bool
    {
        if (self::$production === null) {
            $config = defined('ROOT_PATH') && is_file(ROOT_PATH . '/config/config.php')
                ? (static fn (string $__f): mixed => require $__f)(ROOT_PATH . '/config/config.php')
                : null;
            self::$production = is_array($config) && strtolower(trim((string) ($config['app_env'] ?? ''))) === 'production';
        }
        return self::$production;
    }
}
