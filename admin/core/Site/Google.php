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
 * page, or, on the form's after-sending page, by a cookie that page reads and
 * clears. Spam, the thank-you a caught bot is shown, and a refused submission
 * send nothing. A site's own thank-you page can ask with `conversion()`.
 *
 * THE COOKIE NAMES THE SUBMISSION, SIGNED. It holds the event, the form, the
 * stored submission's id and when it lapses, signed with the site's
 * `app_secret` (`Forms\Guard::mac()`), so a page only ever gets what this site
 * issued. Taking it marks the submission (`FormSubmission::takeConversion()`),
 * so the same value brought twice, by a reload, another tab or a copy of the
 * cookie, is taken once.
 *
 * ASKED FIRST (Settings → SEO → Google, "Ask visitors before measuring",
 * on until the owner turns it off). Nothing loads from Google until a visitor
 * accepts: `measure.js` sets Google's consent to denied, shows the banner
 * `body()` prints, and only on Accept sets it to granted and loads Google's
 * loaders, which then wait in its tag instead of in the page. The answer is
 * kept for a year in a cookie of this site's own (`cms_consent`), Decline as
 * well as Accept, and the footer's Cookie settings (`consentLink()`) asks
 * again. Tag Manager's no-script frame is left out while asking, since it
 * would load without an answer. The banner's words and the page it links to
 * are the owner's.
 *
 * A SITE THAT PRINTS ITS OWN TAGS AND SENDS ITS OWN CONVERSIONS says so in
 * config.php (`'site_sends_conversions' => true`). The IDs on Settings → SEO
 * → Google are then left empty and nothing of Google's is printed, but a real
 * submission's conversion is carried to the form's after-sending page all the
 * same, whether the form was sent by script or not, for the site's own page to
 * take with `conversion()` and send in its own way.
 */
final class Google
{
    /** The options, typed on Settings → SEO → Google. */
    public const KEYS = [
        'google_ga4_id'            => '',
        'google_gtm_id'            => '',
        'google_site_verification' => '',
        'google_click_events'      => '0',
        'google_consent'           => '1',
        'google_consent_words'     => '',
        'google_consent_page'      => '',
    ];

    /** The visitor's answer, kept by `measure.js`: granted or denied. */
    public const CONSENT_COOKIE = 'cms_consent';

    /** What the banner says until the owner writes their own. */
    public const CONSENT_WORDS = 'This site uses cookies from Google to see how it is visited. They are set only if you accept.';

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

    /** @var array<string, mixed>|null config.php, read once */
    private static ?array $config = null;

    /** The conversion this request answers with, for the "Sent" page. @var array{event: string, form: int, submission: int}|null */
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
        $at   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $n    = $nonce !== '' ? ' nonce="' . $at($nonce) . '"' : '';

        $tags = [];
        if ($ids['google_site_verification'] !== '' && self::isHome()) {
            $tags[] = '<meta name="google-site-verification" content="' . $at($ids['google_site_verification']) . '">';
        }
        if ($ids['google_ga4_id'] !== '' || $ids['google_gtm_id'] !== '') {
            // TAKEN ONLY WHERE IT IS SENT: with no ID there is no script to
            // send it, and it waits for the page that asks `conversion()`.
            $wait = self::waiting();
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
            // ASKED FIRST: Google's loaders wait in measure.js's own tag until
            // the visitor accepts, and the banner's stylesheet comes along.
            if (self::asks()) {
                $data .= ' data-consent';
                foreach (['ga4' => 'google_ga4_id', 'gtm' => 'google_gtm_id'] as $name => $key) {
                    if ($ids[$key] !== '') {
                        $data .= ' data-load-' . $name . '="https://www.googletagmanager.com/gtm.js?id=' . $at($ids[$key]) . '"';
                    }
                }
                $tags[] = '<link rel="stylesheet" href="' . $at(Asset::sitePath('assets/css/consent.css')) . '">';
            }
            // FIRST, AND NOT async: the data layer and `gtag('js')` are there
            // before Google's loader below runs, as in Google's own snippet.
            $tags[] = '<script src="' . $at(Asset::sitePath('assets/js/measure.js')) . '"' . $data . $n . '></script>';
            // GOOGLE'S LOADER FOR EACH ID, as its current snippets write it:
            // the Google tag and a Tag Manager container both load gtm.js.
            foreach (['google_ga4_id', 'google_gtm_id'] as $key) {
                if ($ids[$key] !== '' && !self::asks()) {
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

        $ids = self::ids();
        if (self::asks()) {
            return self::print([self::banner()]);
        }
        $id = $ids['google_gtm_id'];
        if ($id === '') {
            return '';
        }
        return self::print(['<noscript><iframe src="https://www.googletagmanager.com/ns.html?id='
            . htmlspecialchars($id, ENT_QUOTES, 'UTF-8')
            . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>']);
    }

    /**
     * Whether visitors are asked before anything loads from Google: a GA4 or
     * Tag Manager ID typed, and "Ask visitors before measuring" left on.
     */
    public static function asks(): bool
    {
        $ids = self::ids();
        return ($ids['google_ga4_id'] !== '' || $ids['google_gtm_id'] !== '') && $ids['google_consent'] === '1';
    }

    /**
     * The footer's way back to the question, for a page that asks: a button
     * `measure.js` opens the banner with. Empty when nothing is asked, and
     * outside production, where nothing is sent. A whole-page theme prints it
     * in its own footer.
     */
    public static function consentLink(string $class = 'site-footer__consent'): string
    {
        if (!self::asks() || !self::production() || !self::isPublic()) {
            return '';
        }
        return '<button type="button" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" data-cms-consent-open>'
            . htmlspecialchars(Words::of('cookie_settings'), ENT_QUOTES, 'UTF-8') . '</button>';
    }

    /** The banner, hidden until `measure.js` finds no answer: the owner's words, their page, and two equal buttons. */
    private static function banner(): string
    {
        $at    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $ids   = self::ids();
        // THE OWNER'S WORDS, in the site's language; on a page in the second
        // language, the banner's words in it (`Words`).
        $words = Language::drawn() !== ''
            ? Words::of('cookie_words')
            : (trim($ids['google_consent_words']) !== '' ? $ids['google_consent_words'] : self::CONSENT_WORDS);
        $page  = (int) $ids['google_consent_page'] > 0 ? \Cms\Models\Page::find((int) $ids['google_consent_page']) : null;
        $link  = $page !== null && ($page['status'] ?? '') === 'published'
            ? ' <a class="cms-consent__more" href="' . $at(\Cms\Helpers\Url::pageOf($page)) . '">' . $at((string) $page['title']) . '</a>'
            : '';
        return '<div class="cms-consent" data-cms-consent role="region" aria-label="' . $at(Words::of('cookies')) . '" hidden>'
            . '<p class="cms-consent__words">' . $at($words) . $link . '</p>'
            . '<div class="cms-consent__actions">'
            . '<button type="button" class="cms-consent__button" data-cms-consent-answer="granted">' . $at(Words::of('cookie_accept')) . '</button>'
            . '<button type="button" class="cms-consent__button" data-cms-consent-answer="denied">' . $at(Words::of('cookie_decline')) . '</button>'
            . '</div></div>';
    }

    /**
     * THE CONVERSION A FORM LEFT FOR ITS AFTER-SENDING PAGE, for a site's own
     * thank-you page that sends it in its own way: its event, the form's ID
     * and the stored submission's ID, or null. Null too for a cookie that is
     * missing, unsigned, altered, lapsed (five minutes) or already taken.
     * Asking takes it, so `head()` will not send it as well, a reload or
     * another tab gets null, and its cookie is cleared: ask before the page's
     * first byte.
     *
     *     $lead = \Cms\Site\Google::conversion();
     *     // ['event' => 'generate_lead', 'form' => 3, 'submission' => 1482]
     *
     * @return array{event: string, form: int, submission: int}|null
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
     * an ID and the site does not send its own (`siteSendsConversions()`).
     * `submission` is the stored submission's ID; `id` tells one answer from
     * another, so the script sends each one once.
     *
     * @param array<string, mixed> $form
     * @return array{event: string, form: int, id: string, submission: int}|null
     */
    public static function conversionFor(array $form, int $submission = 0): ?array
    {
        $ids = self::ids();
        if ($ids['google_ga4_id'] === '' && $ids['google_gtm_id'] === '' && !self::siteSendsConversions()) {
            return null;
        }
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $event    = self::eventName((string) ($settings['conversion_event'] ?? self::DEFAULT_EVENT));
        if ($event === null) {
            return null;
        }
        return ['event' => $event, 'form' => (int) ($form['id'] ?? 0), 'id' => bin2hex(random_bytes(8)), 'submission' => $submission];
    }

    /**
     * Whether this site prints its own Google tags and sends its own
     * conversions: config.php `'site_sends_conversions' => true`. Off unless
     * it says so.
     */
    public static function siteSendsConversions(): bool
    {
        return filter_var(self::config()['site_sends_conversions'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** The answer is this request's own page, the CMS's "Sent": its head sends the conversion. */
    public static function sentHere(array $conversion): void
    {
        self::$sent = ['event' => (string) $conversion['event'], 'form' => (int) $conversion['form'],
            'submission' => (int) ($conversion['submission'] ?? 0)];
    }

    /**
     * The answer sends the browser on, by a redirect or by the form's own
     * script: the conversion waits for the page it lands on, in a cookie on
     * the host the visitor used, signed (see the class comment).
     */
    public static function sendAfterRedirect(array $conversion): void
    {
        if (headers_sent()) {
            return;
        }
        $body = $conversion['event'] . '.' . (int) $conversion['form'] . '.' . (int) ($conversion['submission'] ?? 0)
            . '.' . (time() + self::WAIT);
        setcookie(self::COOKIE, $body . '.' . self::sign($body), [
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
            // ON UNLESS TURNED OFF: a site from before it was asked starts asking.
            'google_consent'           => $stored['google_consent'] === '0' ? '0' : '1',
            'google_consent_words'     => mb_substr(trim((string) $stored['google_consent_words']), 0, 500),
            'google_consent_page'      => ctype_digit((string) $stored['google_consent_page']) ? (string) $stored['google_consent_page'] : '',
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
     * any other page, and once it has been taken: by this request, or, for the
     * cookie, by any request before it (`FormSubmission::takeConversion()`).
     * Null as well for a cookie that is not this site's signed value, or has
     * lapsed.
     *
     * @return array{event: string, form: int, submission: int}|null
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
        if (preg_match('/^(([A-Za-z][A-Za-z0-9_]{0,39})\.(\d{1,10})\.(\d{1,10})\.(\d{1,12}))\.([0-9a-f]{64})$/D', $cookie, $m) !== 1
            || !hash_equals(self::sign($m[1]), $m[6])
            || (int) $m[5] < time()
            || self::eventName($m[2]) === null
            || (int) $m[4] < 1
            || !\Cms\Models\FormSubmission::takeConversion((int) $m[4])) {
            return null;
        }
        return ['event' => $m[2], 'form' => (int) $m[3], 'submission' => (int) $m[4]];
    }

    /** The carried conversion's signature, with the site's own key (`Forms\Guard::mac()`). */
    private static function sign(string $body): string
    {
        return \Cms\Forms\Guard::mac('conversion|' . $body);
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

    /**
     * This copy is production (`Site\Environment`). A page drawn for a static
     * copy (`Export\StaticCopy`) is the published site's, wherever the copy is
     * made, so it prints the tags as production does.
     */
    private static function production(): bool
    {
        return defined('CMS_EXPORT') || Environment::isProduction();
    }

    /** config.php, read once; empty where there is none. @return array<string, mixed> */
    private static function config(): array
    {
        if (self::$config === null) {
            $config = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            self::$config = is_array($config) ? $config : [];
        }
        return self::$config;
    }
}
