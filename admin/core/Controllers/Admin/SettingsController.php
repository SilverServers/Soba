<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\Countries;
use Cms\Commerce\Money;
use Cms\Commerce\PaymentMethods;
use Cms\Commerce\Tax;
use Cms\ContentType\Registry;
use Cms\Models\Option;

/**
 * SettingsController — handles General, SEO, Permalinks, and Email settings pages.
 *
 * Each settings page reads its option keys from the DB, renders the template,
 * and on POST saves the values back.
 */
class SettingsController
{
    /**
     * Settings → the section's own landing page.
     *
     * `/admin/settings` is a page, not a 404. Every other section in the sidebar
     * answers to its bare URL, and a person who types this one, or follows a
     * stale link, or clicks the group heading, should land somewhere that tells
     * them what is in here — not on the CMS's own not-found page.
     */
    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        $sectionTitle = 'Settings';
        $sectionLede  = 'How your site identifies itself, how it appears in search, and who can sign in.';
        $sections     = [
            ['label' => 'General', 'icon' => 'settings',    'url' => ADMIN_URL . '/settings/general',
             'text'  => 'Site title, tagline, timezone, date formats and spam protection.'],
            // NO "and redirects" — they are Tools → Redirects now, and a section
            // card is a promise about what is behind it.
            ['label' => 'SEO', 'icon' => 'seo',        'url' => ADMIN_URL . '/settings/seo',
             'text'  => 'Titles and descriptions in search results, and how pages look when shared.'],
            ['label' => 'Permalinks', 'icon' => 'permalinks', 'url' => ADMIN_URL . '/settings/permalinks',
             'text'  => 'The shape of your page and article web addresses.'],
            ['label' => 'Email', 'icon' => 'email',      'url' => ADMIN_URL . '/settings/email',
             'text'  => 'Where mail from your site is sent from, and how.'],
            ['label' => 'Users', 'icon' => 'users',      'url' => ADMIN_URL . '/users',
             'text'  => 'Who can sign in, and what each of them is allowed to do.'],
        ];

        $pageTitle   = 'Settings';
        $currentPage = 'settings-index';
        require ROOT_PATH . '/admin/templates/partials/section-index.php';
    }

    // ----------------------------------------------------------------
    // General Settings
    // ----------------------------------------------------------------

    /** Option keys for the General settings page, with their defaults. */
    private const GENERAL_KEYS = [
        'site_name'    => '',
        'site_tagline' => '',
        // ── THE FOOTER SAID "© 2026" AND THE PRODUCT'S NAME, AND NOTHING COULD CHANGE IT
        // Hard-coded in `base.php`, so every site built on this shipped the
        // same line with no way to reach it — no address, no company number, no
        // charity registration, none of the things a footer is actually for.
        //
        // BLANK KEEPS THE COPYRIGHT LINE: a site that has said nothing about
        // its footer should not suddenly have an empty one.
        'site_footer_text' => '',
        'admin_email'  => '',
        'timezone'     => 'America/Vancouver',
        'date_format'  => 'M j, Y',
        'time_format'  => 'g:i A',
        // "Entries per page", on Settings → General. Every public list reads
        // it through `Helpers\Paging`.
        'posts_per_page' => '10',
        \Cms\Assistant\Budget::OPTION_LIMIT => '25',
        // SPAM PROTECTION IS SITE-WIDE, not per form. A key pasted once should
        // cover every form on the site, and the built-in challenge needs no
        // key at all — see `Forms\Captcha` for why that is the default.
        \Cms\Forms\Captcha::PROVIDER_KEY => 'builtin',
        \Cms\Forms\Captcha::SITE_KEY     => '',
        \Cms\Forms\Captcha::SECRET_KEY   => '',
    ];

    public function showGeneral(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user     = Auth::user();
        $options  = Option::getMany(self::GENERAL_KEYS);
        $saved    = false;
        $errors   = [];

        $pageTitle   = 'General Settings';
        $currentPage = 'settings-general';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/general.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleGeneral(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user   = Auth::user();
        $errors = [];

        // Validate
        // BLANK IS ALLOWED. Since migration 058 an unset Site title is the
        // white-label default — the host stands in (Head::globalTokens()) —
        // and refusing it made General unsaveable on every fresh install.
        $siteName = trim($_POST['site_name'] ?? '');

        $adminEmail = trim($_POST['admin_email'] ?? '');
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        $timezone = trim($_POST['timezone'] ?? 'America/Vancouver');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = 'Invalid timezone selected.';
            $timezone = 'America/Vancouver';
        }

        // 1 to 100: a hundred cards is already a page nobody scrolls to the
        // end of.
        $perPage = trim((string) ($_POST['posts_per_page'] ?? '10'));
        if (!ctype_digit($perPage) || (int) $perPage < 1 || (int) $perPage > 100) {
            $errors[] = 'Entries per page must be a whole number from 1 to 100.';
        }

        $assistantLimit = trim($_POST[\Cms\Assistant\Budget::OPTION_LIMIT] ?? '25');
        if (strtolower($assistantLimit) !== 'unlimited' && !is_numeric($assistantLimit)) {
            $errors[] = 'Assistant monthly limit must be a number, or the word "unlimited".';
        } elseif (is_numeric($assistantLimit) && (float) $assistantLimit < 0) {
            $errors[] = 'Assistant monthly limit cannot be negative.';
        }

        if (!empty($errors)) {
            // Re-render with submitted values
            $options = [
                'site_name'    => $siteName,
                'site_tagline' => trim($_POST['site_tagline'] ?? ''),
                'site_footer_text' => trim((string) ($_POST['site_footer_text'] ?? '')),
                'admin_email'  => $adminEmail,
                'timezone'     => $timezone,
                'date_format'  => trim($_POST['date_format'] ?? 'M j, Y'),
                'time_format'  => trim($_POST['time_format'] ?? 'g:i A'),
                'posts_per_page' => $perPage,
                \Cms\Assistant\Budget::OPTION_LIMIT => $assistantLimit,
                \Cms\Forms\Captcha::PROVIDER_KEY =>
                    (string) ($_POST[\Cms\Forms\Captcha::PROVIDER_KEY] ?? 'builtin'),
                \Cms\Forms\Captcha::SITE_KEY =>
                    trim((string) ($_POST[\Cms\Forms\Captcha::SITE_KEY] ?? '')),
                \Cms\Forms\Captcha::SECRET_KEY =>
                    trim((string) ($_POST[\Cms\Forms\Captcha::SECRET_KEY] ?? '')),
            ];
            $saved = false;

            $pageTitle   = 'General Settings';
            $currentPage = 'settings-general';

            ob_start();
            require ROOT_PATH . '/admin/templates/settings/general.php';
            $pageContent = ob_get_clean();
            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        Option::setMany([
            'site_name'    => $siteName,
            'site_tagline' => trim($_POST['site_tagline'] ?? ''),
            'site_footer_text' => trim((string) ($_POST['site_footer_text'] ?? '')),
            'admin_email'  => $adminEmail,
            'timezone'     => $timezone,
            'date_format'  => trim($_POST['date_format'] ?? 'M j, Y'),
            'time_format'  => trim($_POST['time_format'] ?? 'g:i A'),
            'posts_per_page' => (string) (int) $perPage,
            \Cms\Assistant\Budget::OPTION_LIMIT => strtolower($assistantLimit) === 'unlimited' ? 'unlimited' : (string) (float) $assistantLimit,
            // A PROVIDER THIS INSTALL DOES NOT KNOW BECOMES THE BUILT-IN ONE.
            // The select offers three; a hand-made POST offers whatever it
            // likes, and an unknown value stored here would leave every form
            // asking `Captcha::provider()` a question it has no answer for.
            \Cms\Forms\Captcha::PROVIDER_KEY => isset(\Cms\Forms\Captcha::providers()[
                (string) ($_POST[\Cms\Forms\Captcha::PROVIDER_KEY] ?? '')
            ]) ? (string) $_POST[\Cms\Forms\Captcha::PROVIDER_KEY] : 'builtin',
            \Cms\Forms\Captcha::SITE_KEY =>
                trim((string) ($_POST[\Cms\Forms\Captcha::SITE_KEY] ?? '')),
            \Cms\Forms\Captcha::SECRET_KEY =>
                trim((string) ($_POST[\Cms\Forms\Captcha::SECRET_KEY] ?? '')),
        ]);

        $options = Option::getMany(self::GENERAL_KEYS);
        $saved   = true;

        $pageTitle   = 'General Settings';
        $currentPage = 'settings-general';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/general.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Permalink Settings
    // ----------------------------------------------------------------

    /** Option keys for the Permalinks page, with their defaults. */
    private const PERMALINK_KEYS = [
        // `/{slug}/` against `/{slug}` — the one part of a permalink
        // structure this router can honour. Read by `Helpers\Url::page()`
        // and the 301 in `Url::settle()`.
        'permalink_trailing_slash' => '0',
    ];

    /**
     * THE PERMALINKS SCREEN WRITES ONE THING: HOW AN ADDRESS ENDS.
     *
     * There is no `permalink_structure` option, because nothing could read one.
     * The routes are static — `www/index.php` registers `/{slug}` for a page and
     * `/{public_slug}/{slug}` for an entry — so a saved structure of
     * `/blog/{year}/{slug}` would report success and change nothing, while
     * looking like it had reconfigured the whole site. `settings/permalinks.php`
     * refuses to draw the control and says why; this is the other half, because
     * a dead option that a screen does not offer is an invitation to someone
     * later to "restore" it.
     *
     * Making it real means dynamic route generation plus a redirect for every
     * URL that moves. That is a feature, not a settings box, and when the router
     * reads a structure the option and the control go back in together.
     *
     * THE TRAILING SLASH IS THE EXCEPTION, because it has its readers:
     * `Helpers\Url::page()` makes every page address on the site from it, and
     * `Url::settle()` answers the other form with a 301 to it. A site moved here
     * keeps the addresses it has, trailing slash and all. So the POST route
     * saves this key and nothing else; a structure posted to it is ignored, not
     * saved.
     */
    public function showPermalinks(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user     = Auth::user();
        $options  = Option::getMany(self::PERMALINK_KEYS);
        $saved    = false;
        $errors   = [];

        $pageTitle   = 'Permalink Settings';
        $currentPage = 'settings-permalinks';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/permalinks.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handlePermalinks(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user   = Auth::user();
        $errors = [];

        // A checkbox: sent as '1' when ticked, absent when not. Anything else
        // a hand-made POST sends is "off" — the option holds '1' or '0' only.
        Option::setMany([
            'permalink_trailing_slash' => ($_POST['permalink_trailing_slash'] ?? null) === '1' ? '1' : '0',
        ]);
        // `Url` read the old value once for this request; the examples on the
        // screen being drawn now must show the new one.
        \Cms\Helpers\Url::resetSettings();

        $options = Option::getMany(self::PERMALINK_KEYS);
        $saved   = true;

        $pageTitle   = 'Permalink Settings';
        $currentPage = 'settings-permalinks';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/permalinks.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Email (SMTP) Settings
    // ----------------------------------------------------------------

    /** Option keys for the Email settings page, with their defaults. */
    private const EMAIL_KEYS = [
        'smtp_host'       => '',
        'smtp_port'       => '587',
        'smtp_username'   => '',
        'smtp_password'   => '',
        'smtp_encryption' => 'tls',
        'smtp_from_email' => '',
        'smtp_from_name'  => '',
    ];

    // ----------------------------------------------------------------
    // Shop
    // ----------------------------------------------------------------

    /**
     * The option keys this screen owns, with their defaults.
     *
     * ── THE PER-METHOD KEYS ARE NOT IN HERE, AND CANNOT BE ─────────────────
     * `commerce_pay_title_bacs` and friends are one pair per payment method, so
     * the list depends on `PaymentMethods::METHODS` and would go stale the day
     * a method is added — a saved override that the screen then stops loading,
     * which looks like the save silently failing. `commerceKeys()` builds them.
     */
    private const COMMERCE_KEYS = [
        'commerce_currency'        => 'CAD',
        'commerce_country'         => 'CA',
        'commerce_payment_methods' => 'bacs',
        'commerce_admin_email'     => '',
        // ── THE THREE WORDS A SHOP'S OWN VOICE OWNS ─────────────────────────
        // Most of the shop's hard-coded strings are FURNITURE — a table's
        // "Quantity" header, a form's "Country" label, the system saying it has
        // no payment method enabled — and each of those is marked at its line
        // with the reason. The answer to text the owner cannot reach is not a
        // new store per string.
        //
        // These three are different, and they are the ones shops actually ask
        // for: basket or cart or bag, "Add to basket" or "Buy now", and the
        // sentence at the top of a receipt. Blank falls back to the templates'
        // own words, so nothing moves until somebody types.
        'commerce_word_basket'     => '',
        'commerce_word_add'        => '',
        'commerce_thanks'          => '',
        // ── TAX DEFAULTS TO NONE ────────────────────────────────────────────
        // Not to a guess from the shop country. A rate this system invented is
        // a rate the owner never agreed to, charged to their customers, on a
        // receipt with their name on it — and the ones we could guess from are
        // wrong as often as not (a Canadian shop may owe GST, GST+PST, or HST
        // at four different rates depending on where the CUSTOMER is).
        //
        // 0 charges nothing and shows nothing, which is the only honest
        // starting point.
        'commerce_tax_rate'        => '0',
        'commerce_tax_label'       => '',
        'commerce_tax_inclusive'   => '0',
        // ── AND DELIVERY DEFAULTS TO FREE ───────────────────────────────────
        // Same reasoning as the tax rate: a shop that has not been told what
        // delivery costs must not invent a figure and add it to an order.
        'commerce_ship_mode'       => 'none',
        'commerce_ship_flat'       => '0',
        'commerce_ship_free_over'  => '0',
        'commerce_ship_note'       => '',
    ];

    /** COMMERCE_KEYS plus the per-method title and instruction overrides. */
    private function commerceKeys(): array
    {
        $keys = self::COMMERCE_KEYS;
        foreach (array_keys(PaymentMethods::METHODS) as $id) {
            $keys[PaymentMethods::titleOptionKey($id)]        = '';
            $keys[PaymentMethods::instructionsOptionKey($id)] = '';
        }

        return $keys;
    }

    public function showCommerce(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user    = Auth::user();
        $options = Option::getMany($this->commerceKeys());
        $saved   = false;
        $errors  = [];

        $this->renderCommerce($options, $saved, $errors, $user);
    }

    public function handleCommerce(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user   = Auth::user();
        $errors = [];

        // ── VALIDATED AGAINST THE TABLES, NOT AGAINST A LIST TYPED HERE ────
        // `Money::CURRENCIES` and `Countries::ALL` are the vocabularies, and
        // both classes already refuse what they do not know. Re-stating the
        // valid set here would be a second list to keep in step; asking them is
        // the same question the renderer asks.
        $currency = strtoupper(trim((string) ($_POST['commerce_currency'] ?? '')));
        if (!isset(Money::CURRENCIES[$currency])) {
            $errors[] = 'Choose a currency from the list.';
        }

        $country = Countries::code((string) ($_POST['commerce_country'] ?? ''));
        if ($country === '') {
            $errors[] = 'Choose a shop country from the list.';
        }

        // AN INVALID ADDRESS IS REFUSED RATHER THAN STORED. `Mailer` skips a
        // malformed recipient silently, so storing one would give a shop that
        // has configured notifications and never receives them, with nothing
        // anywhere saying why.
        //
        // CHECKED HERE, beside the other two, because there is ONE guard below
        // and every message has to be collected before it. Written as a second
        // validate-then-return further down, a bad currency returned first and
        // the bad address was never reached — so an owner who got both wrong
        // was told about them one at a time, on separate round trips.
        $adminEmail = trim((string) ($_POST['commerce_admin_email'] ?? ''));
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The order notification address is not a valid email address.';
        }

        // ── A RATE THIS SYSTEM CANNOT HOLD EXACTLY IS REFUSED ──────────────
        // `Tax::parsePercent()` returns null for `8.875`, a negative, a word or
        // anything over 100. Rounding it to 8.88 instead would leave the screen
        // showing a rate the owner did not type and every receipt a fraction
        // out — the same call `Money::parse()` makes about fractions of a cent.
        $taxRate = Tax::parsePercent((string) ($_POST['commerce_tax_rate'] ?? ''));
        if ($taxRate === null) {
            $errors[] = 'The tax rate must be a percentage with at most two '
                      . 'decimal places, between 0 and 100 — 5, 12.5, 8.88.';
        }

        // ── BLANK IS NOTHING; A TYPO IS REFUSED ────────────────────────────
        // `Money::parse()` returns null for anything it cannot hold exactly, so
        // an owner who types `12.345` or `twelve` is told rather than having a
        // delivery charge silently become zero — which is the failure that
        // costs them money on every order and shows up nowhere.
        $shipFlat = self::parseMoneyField($_POST['commerce_ship_flat'] ?? '');
        if ($shipFlat === null) {
            $errors[] = 'The delivery rate is not an amount of money.';
        }

        $shipFree = self::parseMoneyField($_POST['commerce_ship_free_over'] ?? '');
        if ($shipFree === null) {
            $errors[] = 'The free-delivery threshold is not an amount of money.';
        }

        $shipMode = (string) ($_POST['commerce_ship_mode'] ?? 'none') === 'flat' ? 'flat' : 'none';

        $methods = $_POST['methods'] ?? [];
        if (!is_array($methods)) {
            $methods = [];
        }

        // TURNING EVERY METHOD OFF IS ALLOWED. It closes the shop, which is a
        // legitimate thing to want — seasonal, or while stock is counted — and
        // the settings screen says so in an alert rather than refusing the save.
        // Refusing it would mean an owner who wants to stop taking orders has to
        // find some other way, and there is not one.

        if ($errors !== []) {
            // WHAT WAS TYPED, HANDED BACK. Re-reading the stored options here
            // would throw away everything on the screen because one select was
            // wrong — including the instruction text, which is the longest thing
            // on it and the most annoying to lose.
            $options = $this->postedCommerceOptions();
            $this->renderCommerce($options, false, $errors, $user);
            return;
        }

        $save = [
            'commerce_currency'      => $currency,
            'commerce_country'       => $country,
            'commerce_admin_email'   => $adminEmail,
            // STORED IN HUNDREDTHS OF A PERCENT, which is what `Tax::rate()`
            // reads. The box takes a percentage because that is what a rate is
            // called; nothing else in the system sees the percentage form.
            'commerce_tax_rate'      => (string) $taxRate,
            'commerce_tax_label'     => trim((string) ($_POST['commerce_tax_label'] ?? '')),
            'commerce_tax_inclusive' => !empty($_POST['commerce_tax_inclusive']) ? '1' : '0',
            'commerce_ship_mode'     => $shipMode,
            'commerce_ship_flat'     => (string) $shipFlat,
            'commerce_ship_free_over' => (string) $shipFree,
            'commerce_ship_note'     => trim((string) ($_POST['commerce_ship_note'] ?? '')),
            // ── THE WORDING, AND WHY BLANK IS STORED AS BLANK ───────────────
            // `Commerce\Words` falls back to the built-in word when the option
            // is empty, so clearing the box has to WRITE an empty string rather
            // than skip the key — skipping it would leave the previous value in
            // place and make the field impossible to undo.
            //
            // Adding them to `COMMERCE_KEYS` alone was not enough and the
            // screen said "saved" anyway: that constant is the READ list, and
            // this array is the WRITE list. Two lists, and the gap between them
            // is a save that reports success and stores nothing.
            'commerce_word_basket'   => trim((string) ($_POST['commerce_word_basket'] ?? '')),
            'commerce_word_add'      => trim((string) ($_POST['commerce_word_add'] ?? '')),
            'commerce_thanks'        => trim((string) ($_POST['commerce_thanks'] ?? '')),
        ];

        // `setEnabled()` drops ids that are not in METHODS rather than storing
        // them, so a hand-made POST cannot register a payment method that has
        // no implementation behind it.
        PaymentMethods::setEnabled($methods);

        foreach (array_keys(PaymentMethods::METHODS) as $id) {
            $save[PaymentMethods::titleOptionKey($id)] =
                trim((string) ($_POST['title'][$id] ?? ''));
            $save[PaymentMethods::instructionsOptionKey($id)] =
                trim((string) ($_POST['instructions'][$id] ?? ''));
        }

        Option::setMany($save);

        $this->renderCommerce(Option::getMany($this->commerceKeys()), true, [], $user);
    }

    /**
     * An amount of money from a settings box: minor units, 0 for blank, null
     * for a typo.
     *
     * BLANK IS NOT A TYPO. "I do not charge for this" is the commonest answer
     * to both delivery boxes, and refusing an empty field would make an owner
     * type 0 to mean nothing.
     */
    private static function parseMoneyField(mixed $raw): ?int
    {
        $typed = trim((string) $raw);
        if ($typed === '') {
            return 0;
        }

        return Money::parse($typed);
    }

    /** What the form posted, in the shape the template reads. */
    private function postedCommerceOptions(): array
    {
        $options = [
            'commerce_currency'    => (string) ($_POST['commerce_currency'] ?? ''),
            'commerce_country'     => (string) ($_POST['commerce_country'] ?? ''),
            'commerce_admin_email' => trim((string) ($_POST['commerce_admin_email'] ?? '')),
            // ── THE RATE GOES BACK AS HUNDREDTHS, NOT AS WHAT WAS TYPED ─────
            // The template renders it through `Tax::percent()`, so handing back
            // the raw string would print `5` as `0.05%`. A rate that cannot be
            // parsed has no hundredths — it goes back as 0 and the error
            // message above the form says why, which is better than redrawing
            // the unparseable text and letting a second save store it.
            'commerce_tax_rate'    => (string) (Tax::parsePercent(
                (string) ($_POST['commerce_tax_rate'] ?? '')
            ) ?? 0),
            'commerce_tax_label'   => trim((string) ($_POST['commerce_tax_label'] ?? '')),
            'commerce_tax_inclusive' => !empty($_POST['commerce_tax_inclusive']) ? '1' : '0',
            'commerce_ship_mode'   => (string) ($_POST['commerce_ship_mode'] ?? 'none'),
            // AS MINOR UNITS, because the template renders them through
            // `Money::edit()`. Handing back the raw string would print `12` as
            // `$0.12` on the redraw — the same trap the tax rate has.
            'commerce_ship_flat'   => (string) (self::parseMoneyField(
                $_POST['commerce_ship_flat'] ?? ''
            ) ?? 0),
            'commerce_ship_free_over' => (string) (self::parseMoneyField(
                $_POST['commerce_ship_free_over'] ?? ''
            ) ?? 0),
            'commerce_ship_note'   => trim((string) ($_POST['commerce_ship_note'] ?? '')),
        ];
        foreach (array_keys(PaymentMethods::METHODS) as $id) {
            $options[PaymentMethods::titleOptionKey($id)] =
                trim((string) ($_POST['title'][$id] ?? ''));
            $options[PaymentMethods::instructionsOptionKey($id)] =
                trim((string) ($_POST['instructions'][$id] ?? ''));
        }

        return $options;
    }

    private function renderCommerce(array $options, bool $saved, array $errors, ?array $user): void
    {
        $pageTitle   = 'Shop Settings';
        $currentPage = 'settings-commerce';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/commerce.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function showEmail(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user     = Auth::user();
        $options  = Option::getMany(self::EMAIL_KEYS);
        $saved    = false;
        $errors   = [];

        $pageTitle   = 'Email Settings';
        $currentPage = 'settings-email';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/email.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleEmail(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user   = Auth::user();
        $errors = [];

        $fromEmail = trim($_POST['smtp_from_email'] ?? '');
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'From Email must be a valid email address.';
        }

        $encryption = $_POST['smtp_encryption'] ?? 'tls';
        if (!in_array($encryption, ['', 'tls', 'ssl'], true)) {
            $encryption = 'tls';
        }

        if (!empty($errors)) {
            $options = [
                'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
                'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
                'smtp_username'   => trim($_POST['smtp_username'] ?? ''),
                'smtp_password'   => $_POST['smtp_password'] ?? '',
                'smtp_encryption' => $encryption,
                'smtp_from_email' => $fromEmail,
                'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? ''),
            ];
            $saved = false;

            $pageTitle   = 'Email Settings';
            $currentPage = 'settings-email';

            ob_start();
            require ROOT_PATH . '/admin/templates/settings/email.php';
            $pageContent = ob_get_clean();
            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        // Only update smtp_password if a new value was submitted (non-empty).
        // An empty field means "keep existing password".
        $passwordValue = $_POST['smtp_password'] ?? '';
        $saveOptions = [
            'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
            'smtp_username'   => trim($_POST['smtp_username'] ?? ''),
            'smtp_encryption' => $encryption,
            'smtp_from_email' => $fromEmail,
            'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? ''),
        ];

        if ($passwordValue !== '') {
            $saveOptions['smtp_password'] = $passwordValue;
        }

        Option::setMany($saveOptions);

        $options = Option::getMany(self::EMAIL_KEYS);
        $saved   = true;

        $pageTitle   = 'Email Settings';
        $currentPage = 'settings-email';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/email.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // SEO Settings (tabbed panel)
    // ----------------------------------------------------------------

    /** All SEO option keys with defaults. */
    private const SEO_KEYS = [
        'site_name'                => '',
        'site_tagline'             => '',
        // THE CHARACTER {sep} PRINTS — Title patterns sets it (migration 059).
        'seo_separator'            => '|',
        'seo_og_image'             => '',
        'seo_homepage_title'       => '',
        'seo_homepage_description' => '',
        // THE TITLE PATTERNS ARE NOT LISTED HERE — seoKeys() adds them from
        // `Head::titlePatterns()`, the one list of them.
        'seo_org_type'             => 'Organization',
        'seo_org_name'             => '',
        'seo_org_url'              => '',
        'seo_org_logo'             => '',
        'seo_robots_txt'           => "User-agent: *\nAllow: /\n\nSitemap: {site_url}/sitemap.xml",
        'seo_ping_on_publish'      => '0',
    ];

    public function showSeo(array $vars = []): void
    {
        Auth::requireRole('admin');

        // REDIRECTS LEFT THIS SCREEN — they are Tools → Redirects now, with a
        // controller of their own (`RedirectsController`, which carries the
        // reasoning). This is the OLD redirect turned around: the sidebar's
        // Tools → Redirects link used to be sent here, and a person who
        // bookmarked the tab, or an assistant that stored the link, still has
        // this address. Forwarding costs one line; a tab that quietly became
        // "General" would look like the feature had been deleted.
        if (($_GET['tab'] ?? '') === 'redirects') {
            header('Location: ' . ADMIN_URL . '/tools/redirects');
            exit;
        }

        $user        = Auth::user();
        $options     = Option::getMany(self::seoKeys());
        $tab         = $_GET['tab'] ?? 'general';
        $saved       = false;
        $errors      = [];
        $contentTypes = Registry::all();

        $pageTitle   = 'SEO Settings';
        $currentPage = 'settings-seo';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/seo.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleSeo(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user   = Auth::user();
        $errors = [];
        $tab    = $_POST['_seo_tab'] ?? 'general';

        // Route to the correct save handler based on which tab was submitted
        switch ($tab) {
            case 'general':
                $this->saveSeoGeneral();
                break;
            case 'titles':
                $this->saveSeoTitles();
                break;
            case 'schema':
                $this->saveSeoSchema();
                break;
            case 'robots':
                $this->saveSeoRobots();
                break;
            default:
                break;
        }

        // Re-render
        $options      = Option::getMany(self::seoKeys());
        $saved        = true;
        $contentTypes = Registry::all();

        $pageTitle   = 'SEO Settings';
        $currentPage = 'settings-seo';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/seo.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    private function saveSeoGeneral(): void
    {
        // NO `seo_separator` HERE. It was written from a field this tab never
        // rendered, so every save put " | " back — harmless while nothing read
        // it, and a setting that could not stick once {sep} did. It is the
        // Title patterns tab's now, beside the patterns it prints into.
        Option::setMany([
            'seo_og_image'             => trim($_POST['seo_og_image'] ?? ''),
            // Starts from the Home pattern, and is stored as none while it
            // still says the same (`Head::ownTitle()`).
            'seo_homepage_title'       => \Cms\SEO\Head::ownTitle((string) ($_POST['seo_homepage_title'] ?? ''), 'home'),
            'seo_homepage_description' => trim($_POST['seo_homepage_description'] ?? ''),
            'seo_ping_on_publish'      => !empty($_POST['seo_ping_on_publish']) ? '1' : '0',
        ]);
    }

    /**
     * SEO_KEYS plus one `seo_pattern_{context}` per title pattern, each with its
     * default — from `Head::titlePatterns()`, so a pattern added there is loaded
     * and saved here without a second list to forget.
     *
     * @return array<string, string>
     */
    private static function seoKeys(): array
    {
        $keys = self::SEO_KEYS;
        foreach (\Cms\SEO\Head::titlePatterns() as $context => $pattern) {
            $keys['seo_pattern_' . $context] = $pattern['default'];
        }
        return $keys;
    }

    private function saveSeoTitles(): void
    {
        // EVERY PATTERN THE LIST NAMES, and an emptied box saves its default:
        // a blank pattern would print a page with no title at all. Runs of
        // whitespace are squeezed — the pill editor puts a space after each
        // variable it inserts, and a title never wants two.
        $save = [];
        foreach (\Cms\SEO\Head::titlePatterns() as $context => $pattern) {
            $typed = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['seo_pattern_' . $context] ?? '')));
            $save['seo_pattern_' . $context] = $typed !== '' ? $typed : $pattern['default'];
        }

        // ONE OF THE FOURTEEN, or what is already stored. The screen offers
        // exactly `Head::separators()`, so anything else is a hand-made POST —
        // and refusing it by keeping the stored one is the same thing a select
        // with no matching option would do, without a silent reset to the bar.
        $sep = (string) ($_POST['seo_separator'] ?? '');
        if (array_key_exists($sep, \Cms\SEO\Head::separators())) {
            $save['seo_separator'] = $sep;
        }

        Option::setMany($save);
    }

    private function saveSeoSchema(): void
    {
        $orgType = in_array($_POST['seo_org_type'] ?? '', ['Organization', 'LocalBusiness'], true)
            ? $_POST['seo_org_type']
            : 'Organization';

        Option::setMany([
            'seo_org_type' => $orgType,
            'seo_org_name' => trim($_POST['seo_org_name'] ?? ''),
            'seo_org_url'  => trim($_POST['seo_org_url'] ?? ''),
            'seo_org_logo' => trim($_POST['seo_org_logo'] ?? ''),
        ]);
    }

    private function saveSeoRobots(): void
    {
        Option::set('seo_robots_txt', $_POST['seo_robots_txt'] ?? '');
    }

}
