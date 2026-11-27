<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\Countries;
use Cms\Commerce\Money;
use Cms\Commerce\PaymentMethods;
use Cms\Commerce\Regions;
use Cms\Commerce\Shipping;
use Cms\Commerce\TaxRates;
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
            ['label' => 'Business details', 'icon' => 'business', 'url' => ADMIN_URL . '/settings/business',
             'text'  => 'Phone numbers, email, opening hours, where you are and where you work.'],
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
        // THE SHOP'S TWO, ON THE MENU'S OWN CONDITION (`Ui\AdminMenu`), before
        // Users as the menu has them: a card for every row the Settings menu
        // shows, and none it does not.
        if (\Cms\ContentType\Registry::withArchetype('product') !== [] && \Cms\Helpers\Modules::enabled('commerce')) {
            array_splice($sections, count($sections) - 1, 0, [
                ['label' => 'Shop', 'icon' => 'commerce', 'url' => ADMIN_URL . '/settings/commerce',
                 'text'  => 'Tax, delivery, payment, discount wording and what a customer agrees to.'],
                ['label' => 'API', 'icon' => 'api', 'url' => ADMIN_URL . '/settings/api',
                 'text'  => 'Keys and webhooks for the programs that read and change the shop.'],
            ]);
        }

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
        // `<html lang>` on every public page (`Site\Language`).
        \Cms\Site\Language::OPTION => \Cms\Site\Language::DEFAULT,
        // A second language for pages and entries, at its own addresses ('' none).
        \Cms\Site\Language::SECOND => '',
        // The title, tagline and footer text in it ('' the site's own).
        'site_name_second'        => '',
        'site_tagline_second'     => '',
        'site_footer_text_second' => '',
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

        // THE SECOND LANGUAGE: none, or one the select offers that nothing in
        // the way refuses (`Site\Language::problem()`).
        $secondLanguage = trim((string) ($_POST[\Cms\Site\Language::SECOND] ?? ''));
        $ownLanguage    = isset(\Cms\Site\Language::ALL[(string) ($_POST[\Cms\Site\Language::OPTION] ?? '')])
            ? (string) $_POST[\Cms\Site\Language::OPTION] : \Cms\Site\Language::DEFAULT;
        if ($secondLanguage !== '' && $secondLanguage === $ownLanguage) {
            $errors[] = 'The second language must be another language than the site\'s own.';
        } elseif (($why = \Cms\Site\Language::problem($secondLanguage)) !== null) {
            $errors[] = $why;
        }

        // THE SITE'S OWN WORDS IN THE SECOND LANGUAGE, when the screen drew
        // them (a second language is chosen); untouched otherwise.
        $secondWords = [];
        foreach (\Cms\Site\Language::SECOND_WORDS as $secondKey) {
            if (array_key_exists($secondKey, $_POST)) {
                $secondWords[$secondKey] = trim((string) $_POST[$secondKey]);
            }
        }

        if (!empty($errors)) {
            // Re-render with submitted values
            $options = $secondWords + [
                'site_name'    => $siteName,
                'site_tagline' => trim($_POST['site_tagline'] ?? ''),
                'site_footer_text' => trim((string) ($_POST['site_footer_text'] ?? '')),
                'admin_email'  => $adminEmail,
                \Cms\Site\Language::OPTION => (string) ($_POST[\Cms\Site\Language::OPTION] ?? \Cms\Site\Language::DEFAULT),
                \Cms\Site\Language::SECOND => $secondLanguage,
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
            // ONLY A LANGUAGE THE SELECT OFFERS. A hand-made POST's anything
            // else is English, never a `lang` the page could not stand behind.
            \Cms\Site\Language::OPTION => $ownLanguage,
            \Cms\Site\Language::SECOND => $secondLanguage,
        ] + $secondWords + [
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
    /**
     * SETTINGS → BRANDING (`Ui\Branding`): the site's logo, in a version for
     * light backgrounds and one for dark, and the sign-in pages' theme.
     */
    public function showBranding(array $vars = []): void
    {
        Auth::requireRole('admin');
        $s = \Cms\Ui\Branding::settings();
        $this->renderBranding([], isset($_GET['saved']), [
            'logo_light' => $s['logo_light'], 'logo_dark' => $s['logo_dark'], 'signin_theme' => $s['signin_theme'],
        ]);
    }

    /** Keep the sheet; on a refusal nothing is written, and the sheet comes back as chosen. */
    public function handleBranding(array $vars = []): void
    {
        Auth::requireRole('admin');
        $posted = [
            'logo_light'   => max(0, (int) ($_POST['logo_light'] ?? 0)),
            'logo_dark'    => max(0, (int) ($_POST['logo_dark'] ?? 0)),
            'signin_theme' => (string) ($_POST['signin_theme'] ?? ''),
        ];
        $errors = [];
        foreach (['logo_light' => 'The logo for light backgrounds', 'logo_dark' => 'The logo for dark backgrounds'] as $key => $what) {
            $problem = \Cms\Ui\Branding::pictureProblem($posted[$key]);
            if ($problem !== null) {
                $errors[] = $what . ': ' . lcfirst($problem);
            }
        }
        if (!\Cms\Ui\Theme::offers($posted['signin_theme'])) {
            $errors[] = 'Choose the sign-in page\'s theme from the list.';
        }
        if ($errors !== []) {
            $this->renderBranding($errors, false, $posted);
            return;
        }
        Option::setMany([
            \Cms\Ui\Branding::LOGO_LIGHT_KEY   => (string) $posted['logo_light'],
            \Cms\Ui\Branding::LOGO_DARK_KEY    => (string) $posted['logo_dark'],
            \Cms\Ui\Branding::SIGNIN_THEME_KEY => $posted['signin_theme'],
        ]);
        \Cms\Ui\Branding::reset();
        header('Location: ' . ADMIN_URL . '/settings/branding?saved=1', true, 303);
        exit;
    }

    /**
     * @param list<string>         $errors
     * @param array<string, mixed> $form
     */
    private function renderBranding(array $errors, bool $saved, array $form): void
    {
        $user        = Auth::user();
        $pageTitle   = 'Branding';
        $currentPage = 'settings-branding';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/branding.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    /**
     * SETTINGS → WORDS: the theme's own words (`Site\Words`), the owner's for
     * the site's language and for its second.
     */
    public function showWords(array $vars = []): void
    {
        Auth::requireCan('words');
        $this->renderWords(false);
    }

    /** Keep the owner's words for each language the screen drew. */
    public function handleWords(array $vars = []): void
    {
        Auth::requireCan('words');
        \Cms\Site\Words::save(false, is_array($_POST['own'] ?? null) ? $_POST['own'] : []);
        if (\Cms\Site\Language::second() !== '') {
            \Cms\Site\Words::save(true, is_array($_POST['second'] ?? null) ? $_POST['second'] : []);
        }
        $this->renderWords(true);
    }

    private function renderWords(bool $saved): void
    {
        $user        = Auth::user();
        $ownWords    = \Cms\Site\Words::owner(false);
        $secondWords = \Cms\Site\Words::owner(true);
        $pageTitle   = 'Words';
        $currentPage = 'settings-words';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/words.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

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
        // A test's address and inbox, for a form that names none of its own
        // (`Forms\Notify::testInbox()`).
        'forms_test_address' => '',
        'forms_test_inbox'   => '',
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
        // WHOSE RECEIPT IT IS, AND WHAT THE CUSTOMER AGREES TO.
        'commerce_business_name'    => '',
        'commerce_business_address' => '',
        'commerce_tax_number'       => '',
        'commerce_terms_page'       => '0',
        'commerce_offer_signup'     => '0',
        // THE EMAILS AN ORDER SENDS ITS CUSTOMER AS IT MOVES: on, as a
        // customer who paid expects to hear that it arrived, that the parcel
        // went, and that money came back. `OrderMail` reads them.
        'commerce_mail_paid'        => '1',
        'commerce_mail_sent'        => '1',
        'commerce_mail_refunded'    => '1',
        // RUNNING LOW at 2 or fewer, and the shop told when anything does or
        // sells out (`StockAlert`).
        'commerce_low_stock'        => '2',
        'commerce_mail_low'         => '1',
        // UNPAID ORDERS ARE KEPT until somebody cancels them, unless the shop
        // gives them days (`Commerce\Lapse`).
        'commerce_lapse_days'       => '0',
        // AND THE DAY BEFORE, a reminder to pay, once.
        'commerce_lapse_remind'     => '1',
        // ── TAX DEFAULTS TO NONE ────────────────────────────────────────────
        // Not to a guess from the shop country. A rate this system invented is
        // a rate the owner never agreed to, charged to their customers, on a
        // receipt with their name on it — and the ones we could guess from are
        // wrong as often as not (a Canadian shop may owe GST, GST+PST, or HST
        // at four different rates depending on where the CUSTOMER is).
        //
        // 0 charges nothing and shows nothing, which is the only honest
        // starting point.
        'commerce_region'          => '',
        'commerce_tax_rates'       => '[]',
        'commerce_tax_inclusive'   => '0',
        // ── AND DELIVERY DEFAULTS TO FREE ───────────────────────────────────
        // Same reasoning as the tax rate: a shop that has not been told what
        // delivery costs must not invent a figure and add it to an order.
        'commerce_ship_rates'      => '[]',
        'commerce_ship_note'       => '',
        'commerce_pickup'          => '0',
        'commerce_pickup_where'    => '',
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

        // THE SHOP'S OWN PROVINCE, for a country the checkout lists them for:
        // where a basket's tax is estimated and a collected order is taxed.
        $typedRegion = trim((string) ($_POST['commerce_region'] ?? ''));
        $region      = $country !== '' ? Regions::code($country, $typedRegion) : '';
        if ($typedRegion !== '' && $region === '') {
            $errors[] = 'Choose the shop\'s province or state from the list, one of its country\'s.';
        }

        // ── THE RATES, ROW BY ROW ───────────────────────────────────────────
        // A rate this system cannot hold exactly is refused, not rounded:
        // `TaxRates::parse()` returns null for a fourth decimal place, a
        // negative, a word or anything over 100. Each refusal names its row.
        [$taxRates, $taxErrors] = self::postedTaxRates($_POST['tax_rates'] ?? []);
        array_push($errors, ...$taxErrors);

        // ── RUNNING LOW: a whole number, so a count can be compared with it.
        $lowStock = trim((string) ($_POST['commerce_low_stock'] ?? '2'));
        if (!preg_match('/^\d{1,4}$/', $lowStock)) {
            $errors[] = 'Running low is a whole number of items, 0 or more.';
        }

        // ── THE DAYS AN UNPAID ORDER IS KEPT: whole days, 0 for always.
        $lapseDays = trim((string) ($_POST['commerce_lapse_days'] ?? '0'));
        if (!preg_match('/^\d{1,3}$/', $lapseDays === '' ? '0' : $lapseDays)) {
            $errors[] = 'The days an unpaid order is kept are a whole number, 0 to keep it until cancelled.';
        }

        // ── THE PAGE A CUSTOMER AGREES TO, if one is chosen: one that exists.
        $termsPage = (int) ($_POST['commerce_terms_page'] ?? 0);
        if ($termsPage > 0 && \Cms\Models\Page::find($termsPage) === null) {
            $errors[] = 'Choose the page a customer agrees to from the list.';
        }

        // ── WHAT DELIVERY COSTS, ROW BY ROW ─────────────────────────────────
        // An amount typed as `12.345` or `twelve` is told rather than having a
        // delivery charge silently become zero — the failure that costs money
        // on every order and shows up nowhere. Each refusal names its row.
        [$shipRates, $shipErrors] = self::postedShipRates($_POST['ship_rates'] ?? []);
        array_push($errors, ...$shipErrors);

        $methods = $_POST['methods'] ?? [];
        if (!is_array($methods)) {
            $methods = [];
        }

        // TURNING EVERY METHOD OFF IS ALLOWED. It closes the shop, which is a
        // legitimate thing to want — seasonal, or while stock is counted — and
        // the settings screen says so in an alert rather than refusing the save.
        // Refusing it would mean an owner who wants to stop taking orders has to
        // find some other way, and there is not one.

        // "FILL IN CANADA'S GST AND HST" puts them in the list and saves
        // nothing: the owner reads them, adds a province they are registered
        // in, and saves. The rest of the form comes back as it was typed.
        if ((string) ($_POST['tax_preset'] ?? '') === 'canada') {
            $this->renderCommerce(
                $this->postedCommerceOptions(),
                false,
                [],
                $user,
                self::taxRowsForForm(TaxRates::canada()),
                self::typedShipRows($_POST['ship_rates'] ?? []),
                'Canada\'s GST and HST are filled in below, in place of the rates that were there. Check them, '
                . 'add any provincial tax you are registered for, and save.'
            );
            return;
        }

        if ($errors !== []) {
            // WHAT WAS TYPED, HANDED BACK. Re-reading the stored options here
            // would throw away everything on the screen because one select was
            // wrong — including the instruction text, which is the longest thing
            // on it and the most annoying to lose.
            $options = $this->postedCommerceOptions();
            $this->renderCommerce(
                $options,
                false,
                $errors,
                $user,
                self::typedTaxRows($_POST['tax_rates'] ?? []),
                self::typedShipRows($_POST['ship_rates'] ?? [])
            );
            return;
        }

        $save = [
            'commerce_currency'      => $currency,
            'commerce_country'       => $country,
            'commerce_admin_email'   => $adminEmail,
            'commerce_region'        => $region,
            // IN THOUSANDTHS OF A PERCENT, as `TaxRates` reads them. The boxes
            // take percentages because that is what a rate is called.
            'commerce_tax_rates'     => (string) json_encode(TaxRates::clean($taxRates)),
            'commerce_tax_inclusive' => !empty($_POST['commerce_tax_inclusive']) ? '1' : '0',
            'commerce_ship_rates'    => (string) json_encode(Shipping::clean($shipRates)),
            'commerce_ship_note'     => trim((string) ($_POST['commerce_ship_note'] ?? '')),
            'commerce_pickup'        => !empty($_POST['commerce_pickup']) ? '1' : '0',
            'commerce_pickup_where'  => trim((string) ($_POST['commerce_pickup_where'] ?? '')),
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
            'commerce_business_name'    => trim((string) ($_POST['commerce_business_name'] ?? '')),
            'commerce_business_address' => trim((string) ($_POST['commerce_business_address'] ?? '')),
            'commerce_tax_number'       => trim((string) ($_POST['commerce_tax_number'] ?? '')),
            'commerce_terms_page'       => (string) max(0, $termsPage),
            'commerce_offer_signup'     => !empty($_POST['commerce_offer_signup']) ? '1' : '0',
            'commerce_mail_paid'        => !empty($_POST['commerce_mail_paid']) ? '1' : '0',
            'commerce_mail_sent'        => !empty($_POST['commerce_mail_sent']) ? '1' : '0',
            'commerce_mail_refunded'    => !empty($_POST['commerce_mail_refunded']) ? '1' : '0',
            'commerce_low_stock'        => (string) (int) $lowStock,
            'commerce_mail_low'         => !empty($_POST['commerce_mail_low']) ? '1' : '0',
            'commerce_lapse_days'       => (string) (int) $lapseDays,
            'commerce_lapse_remind'     => !empty($_POST['commerce_lapse_remind']) ? '1' : '0',
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
            // THE RATES GO BACK AS TYPED, through `typedTaxRows()`: each row's
            // box shows what the owner wrote, and the message above the form
            // names the row that cannot be stored.
            'commerce_region'      => (string) ($_POST['commerce_region'] ?? ''),
            'commerce_tax_inclusive' => !empty($_POST['commerce_tax_inclusive']) ? '1' : '0',
            'commerce_pickup'      => !empty($_POST['commerce_pickup']) ? '1' : '0',
            'commerce_pickup_where' => trim((string) ($_POST['commerce_pickup_where'] ?? '')),
            'commerce_ship_note'   => trim((string) ($_POST['commerce_ship_note'] ?? '')),
            // AND THE REST AS TYPED. The wording boxes came back blank and the
            // ticked methods as stored, so a refusal about a delivery rate
            // undid changes the owner had made further down the form.
            'commerce_word_basket' => trim((string) ($_POST['commerce_word_basket'] ?? '')),
            'commerce_word_add'    => trim((string) ($_POST['commerce_word_add'] ?? '')),
            'commerce_thanks'      => trim((string) ($_POST['commerce_thanks'] ?? '')),
            'commerce_business_name'    => trim((string) ($_POST['commerce_business_name'] ?? '')),
            'commerce_business_address' => trim((string) ($_POST['commerce_business_address'] ?? '')),
            'commerce_tax_number'       => trim((string) ($_POST['commerce_tax_number'] ?? '')),
            'commerce_terms_page'       => (string) (int) ($_POST['commerce_terms_page'] ?? 0),
            'commerce_offer_signup'     => !empty($_POST['commerce_offer_signup']) ? '1' : '0',
            'commerce_mail_paid'        => !empty($_POST['commerce_mail_paid']) ? '1' : '0',
            'commerce_mail_sent'        => !empty($_POST['commerce_mail_sent']) ? '1' : '0',
            'commerce_mail_refunded'    => !empty($_POST['commerce_mail_refunded']) ? '1' : '0',
            'commerce_low_stock'        => trim((string) ($_POST['commerce_low_stock'] ?? '')),
            'commerce_mail_low'         => !empty($_POST['commerce_mail_low']) ? '1' : '0',
            'commerce_lapse_days'       => trim((string) ($_POST['commerce_lapse_days'] ?? '')),
            'commerce_lapse_remind'     => !empty($_POST['commerce_lapse_remind']) ? '1' : '0',
            'commerce_payment_methods' => implode(',', array_map(
                'strval',
                is_array($_POST['methods'] ?? null) ? $_POST['methods'] : []
            )),
        ];
        foreach (array_keys(PaymentMethods::METHODS) as $id) {
            $options[PaymentMethods::titleOptionKey($id)] =
                trim((string) ($_POST['title'][$id] ?? ''));
            $options[PaymentMethods::instructionsOptionKey($id)] =
                trim((string) ($_POST['instructions'][$id] ?? ''));
        }

        return $options;
    }

    /**
     * The rates as posted, read for saving: each row's rate parsed, and a
     * sentence for each row that cannot be — numbered as the owner sees them,
     * blank rows not counted.
     *
     * @return array{0: list<array{country: string, region: string, label: string, rate: int}>, 1: list<string>}
     */
    private static function postedTaxRates(mixed $posted): array
    {
        $rows   = [];
        $errors = [];
        $n      = 0;
        foreach (self::typedTaxRows($posted) as $row) {
            if ($row['label'] === '' && $row['rate'] === '') {
                continue;
            }
            $n++;
            $rate = TaxRates::parse($row['rate']);
            if ($rate === null) {
                $errors[] = 'Tax rate ' . $n . ': the rate must be a percentage with at most three decimal '
                          . 'places, between 0 and 100 — 5, 13, 9.975.';
                continue;
            }
            if ($row['country'] !== '' && !Countries::isValid($row['country'])) {
                $errors[] = 'Tax rate ' . $n . ': choose a country from the list.';
                continue;
            }
            if ($row['region'] !== '' && Regions::code($row['country'], $row['region']) === '') {
                $errors[] = 'Tax rate ' . $n . ': choose a province or state of the country it names, or none '
                          . 'for all of it.';
                continue;
            }
            $rows[] = ['country' => $row['country'], 'region' => $row['region'], 'label' => $row['label'], 'rate' => $rate];
        }

        return [$rows, $errors];
    }

    /**
     * The rate rows exactly as typed, for redrawing a refused form.
     *
     * @return list<array{country: string, region: string, label: string, rate: string}>
     */
    private static function typedTaxRows(mixed $posted): array
    {
        $out = [];
        foreach (is_array($posted) ? $posted : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'country' => strtoupper(trim((string) ($row['country'] ?? ''))),
                'region'  => strtoupper(trim((string) ($row['region'] ?? ''))),
                'label'   => trim((string) ($row['label'] ?? '')),
                'rate'    => trim((string) ($row['rate'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Stored rows as the form shows them, each rate as it would be typed.
     *
     * @param  list<array{country: string, region: string, label: string, rate: int}> $rows
     * @return list<array{country: string, region: string, label: string, rate: string}>
     */
    private static function taxRowsForForm(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'country' => $r['country'],
            'region'  => $r['region'],
            'label'   => $r['label'],
            'rate'    => TaxRates::percent($r['rate']),
        ], TaxRates::clean($rows));
    }

    /**
     * The delivery rows as posted, read for saving, each money box parsed and
     * each refusal naming its row. A row with neither amount typed is a blank
     * line on the form, and is dropped.
     *
     * @return array{0: list<array{country: string, region: string, rate: int, free_over: int}>, 1: list<string>}
     */
    private static function postedShipRates(mixed $posted): array
    {
        $rows   = [];
        $errors = [];
        $n      = 0;
        foreach (self::typedShipRows($posted) as $row) {
            if ($row['rate'] === '' && $row['free_over'] === '' && $row['country'] === '') {
                continue;
            }
            $n++;
            $rate = self::parseMoneyField($row['rate']);
            $free = self::parseMoneyField($row['free_over']);
            if ($rate === null || $free === null) {
                $errors[] = 'Delivery rate ' . $n . ': ' . ($rate === null ? 'what delivery costs' : 'the free-delivery threshold')
                          . ' is not an amount of money.';
                continue;
            }
            if ($row['country'] !== '' && !Countries::isValid($row['country'])) {
                $errors[] = 'Delivery rate ' . $n . ': choose a country from the list.';
                continue;
            }
            if ($row['region'] !== '' && Regions::code($row['country'], $row['region']) === '') {
                $errors[] = 'Delivery rate ' . $n . ': choose a province or state of the country it names, or none for all of it.';
                continue;
            }
            $rows[] = ['country' => $row['country'], 'region' => $row['region'], 'rate' => $rate, 'free_over' => $free];
        }

        return [$rows, $errors];
    }

    /**
     * The delivery rows exactly as typed, for redrawing a refused form.
     *
     * @return list<array{country: string, region: string, rate: string, free_over: string}>
     */
    private static function typedShipRows(mixed $posted): array
    {
        $out = [];
        foreach (is_array($posted) ? $posted : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'country'   => strtoupper(trim((string) ($row['country'] ?? ''))),
                'region'    => strtoupper(trim((string) ($row['region'] ?? ''))),
                'rate'      => trim((string) ($row['rate'] ?? '')),
                'free_over' => trim((string) ($row['free_over'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Stored delivery rows as the form shows them, amounts as they would be
     * typed: "8.00", blank for no threshold.
     *
     * @return list<array{country: string, region: string, rate: string, free_over: string}>
     */
    private static function shipRowsForForm(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'country'   => $r['country'],
            'region'    => $r['region'],
            'rate'      => Money::edit($r['rate']),
            'free_over' => $r['free_over'] > 0 ? Money::edit($r['free_over']) : '',
        ], Shipping::clean($rows));
    }

    /**
     * @param list<array{country: string, region: string, label: string, rate: string}>|null $taxRows
     *        the rate rows to draw: as typed after a refusal, filled in by the
     *        Canada button, or null for the stored ones
     * @param list<array{country: string, region: string, rate: string, free_over: string}>|null $shipRows
     *        the delivery rows to draw, the same way
     */
    private function renderCommerce(
        array $options,
        bool $saved,
        array $errors,
        ?array $user,
        ?array $taxRows = null,
        ?array $shipRows = null,
        string $notice = ''
    ): void {
        $taxRows  ??= self::taxRowsForForm(TaxRates::all());
        $shipRows ??= self::shipRowsForForm(Shipping::rates());
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
        // What the last test email did (`handleEmailTest()`), shown once.
        $tested   = is_array($_SESSION['email_test'] ?? null) ? $_SESSION['email_test'] : null;
        unset($_SESSION['email_test']);

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
        $test = [];
        foreach (['forms_test_address' => 'The test address', 'forms_test_inbox' => 'The test inbox'] as $key => $name) {
            $test[$key] = trim((string) ($_POST[$key] ?? ''));
            if ($test[$key] !== '' && filter_var($test[$key], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = $name . ' must be an email address.';
            }
        }
        // MAIL THROUGH BREVO, OR KEPT IN THE MAIL LOG (config.php): the
        // sending server's fields are not drawn, so what is stored for them
        // stays as it is.
        $noServer = in_array(\Cms\Mail\Mailer::transport(), ['brevo', 'log'], true);

        if (!empty($errors)) {
            $options = [
                'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
                'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
                'smtp_username'   => trim($_POST['smtp_username'] ?? ''),
                'smtp_password'   => $_POST['smtp_password'] ?? '',
                'smtp_encryption' => $encryption,
                'smtp_from_email' => $fromEmail,
                'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? ''),
            ] + $test;
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
        $saveOptions = ($noServer ? [] : [
            'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
            'smtp_username'   => trim($_POST['smtp_username'] ?? ''),
            'smtp_encryption' => $encryption,
        ]) + [
            'smtp_from_email' => $fromEmail,
            'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? ''),
        ] + $test;

        if ($passwordValue !== '' && !$noServer) {
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

    /**
     * SEND A TEST EMAIL: one message to the address typed, through whatever
     * this site sends with (`Mailer::transportName()`: Brevo, an SMTP server,
     * or the server's own mail program), as the settings are saved. What came
     * of it is shown on Settings → Email, once: sent, or the transport's own
     * reason it was not (`Mailer::lastError()`), never a key or a password.
     * Back to the screen by a redirect, so a reload does not send it again.
     */
    public function handleEmailTest(array $vars = []): void
    {
        Auth::requireRole('admin');
        $to  = trim((string) ($_POST['test_to'] ?? ''));
        $how = \Cms\Mail\Mailer::transportName();
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $_SESSION['email_test'] = ['ok' => false, 'to' => $to, 'how' => $how, 'why' => 'that is not an email address'];
        } else {
            $site = \Cms\SEO\Head::globalTokens()['site_name'];
            $user = Auth::user();
            $who  = $user !== null ? \Cms\Models\User::displayName($user) : 'an administrator';
            $now  = time();
            $body = "This is a test email from {$site}, sent from Settings → Email by {$who} at "
                . \Cms\Helpers\Dates::stamp($now) . ' ' . \Cms\Helpers\Dates::format($now, 'T') . ".\n\n"
                . "It went through {$how}. If it arrived, the site's mail works: form notifications and order emails go out the same way.\n";
            $ok = \Cms\Mail\Mailer::send([$to], 'Test email from ' . $site, $body);
            $_SESSION['email_test'] = ['ok' => $ok, 'to' => $to, 'how' => $how, 'why' => $ok ? '' : \Cms\Mail\Mailer::lastError()];
        }
        header('Location: ' . ADMIN_URL . '/settings/email', true, 303);
        exit;
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
                $errors = $this->saveSeoGeneral();
                break;
            case 'titles':
                $this->saveSeoTitles();
                break;
            case 'schema':
                $errors = $this->saveSeoSchema();
                break;
            case 'robots':
                $this->saveSeoRobots();
                break;
            case 'google':
                $errors = $this->saveSeoGoogle();
                break;
            default:
                break;
        }

        // Re-render: with what was typed, when it was refused.
        $options      = Option::getMany(self::seoKeys());
        if ($errors !== []) {
            $options = array_merge($options, self::googleTyped());
        }
        $saved        = true;
        $contentTypes = Registry::all();

        $pageTitle   = 'SEO Settings';
        $currentPage = 'settings-seo';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/seo.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }

    /** @return list<string> what was refused */
    private function saveSeoGeneral(): array
    {
        // NO `seo_separator` HERE. It was written from a field this tab never
        // rendered, so every save put " | " back — harmless while nothing read
        // it, and a setting that could not stick once {sep} did. It is the
        // Title patterns tab's now, beside the patterns it prints into.
        $image = self::siteImage('seo_og_image');
        Option::setMany(array_filter([
            'seo_og_image'             => $image,
            // Starts from the Home pattern, and is stored as none while it
            // still says the same (`Head::ownTitle()`).
            'seo_homepage_title'       => \Cms\SEO\Head::ownTitle((string) ($_POST['seo_homepage_title'] ?? ''), 'home'),
            'seo_homepage_description' => trim($_POST['seo_homepage_description'] ?? ''),
            'seo_ping_on_publish'      => !empty($_POST['seo_ping_on_publish']) ? '1' : '0',
        ], static fn (?string $v): bool => $v !== null));

        return $image === null ? ['The default image must be a picture from the media library.'] : [];
    }

    /**
     * A SITE-WIDE IMAGE AS SETTINGS → SEO STORES IT: the media library's id of
     * the picture chosen, which a page resolves to its address when it is
     * drawn (`Head::siteImage()`); else what the box beside the picker holds,
     * an address kept from before there was a picker, the address of a
     * library picture becoming that picture's id; else none. Null when what
     * was chosen is not a public picture of the library.
     */
    private static function siteImage(string $key): ?string
    {
        $id = max(0, (int) ($_POST[$key] ?? 0));
        if ($id > 0) {
            return \Cms\SEO\Head::siteImage((string) $id) !== '' ? (string) $id : null;
        }
        $url = trim((string) ($_POST[$key . '_url'] ?? ''));
        if ($url === '') {
            return '';
        }
        $found = \Cms\Models\Media::idAt($url);

        return $found !== null && \Cms\SEO\Head::siteImage((string) $found) !== '' ? (string) $found : $url;
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
        $keys = self::SEO_KEYS + \Cms\Site\Google::KEYS;
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

    /** @return list<string> what was refused */
    private function saveSeoSchema(): array
    {
        $orgType = in_array($_POST['seo_org_type'] ?? '', ['Organization', 'LocalBusiness'], true)
            ? $_POST['seo_org_type']
            : 'Organization';
        $logo = self::siteImage('seo_org_logo');
        // Blank means this site's own address (`SEO\Schema`), so the address
        // itself is kept as blank, and follows the site when it moves.
        $orgUrl = trim($_POST['seo_org_url'] ?? '');

        Option::setMany(array_filter([
            'seo_org_type' => $orgType,
            'seo_org_name' => trim($_POST['seo_org_name'] ?? ''),
            'seo_org_url'  => \Cms\SEO\Schema::isOwnAddress($orgUrl) ? '' : $orgUrl,
            'seo_org_logo' => $logo,
        ], static fn (?string $v): bool => $v !== null));

        return $logo === null ? ['The logo must be a picture from the media library.'] : [];
    }

    private function saveSeoRobots(): void
    {
        Option::set('seo_robots_txt', $_POST['seo_robots_txt'] ?? '');
    }

    /**
     * GOOGLE'S MEASUREMENT TAGS (`Site\Google`): only the IDs Google gives
     * out, each as Google writes it, and nothing else. Any one that is not an
     * ID refuses the whole save, in words, and keeps what was typed on the
     * screen; empty clears it.
     *
     * @return list<string> what is wrong, or nothing when it saved
     */
    private function saveSeoGoogle(): array
    {
        $typed  = self::googleTyped();
        $ga4    = \Cms\Site\Google::analyticsId($typed['google_ga4_id']);
        $gtm    = \Cms\Site\Google::tagManagerId($typed['google_gtm_id']);
        $verify = \Cms\Site\Google::verificationCode($typed['google_site_verification']);

        $errors = [];
        if ($typed['google_ga4_id'] !== '' && $ga4 === null) {
            $errors[] = str_starts_with(strtoupper($typed['google_ga4_id']), 'UA-')
                ? 'A UA- ID is Universal Analytics, which Google has retired. A GA4 measurement ID starts G- (Analytics → Admin → Data streams).'
                : 'The measurement ID is G- and then letters and digits, as Analytics → Admin → Data streams shows it.';
        }
        if ($typed['google_gtm_id'] !== '' && $gtm === null) {
            $errors[] = 'The container ID is GTM- and then letters and digits, as Tag Manager shows it beside the container\'s name.';
        }
        if ($typed['google_site_verification'] !== '' && $verify === null) {
            $errors[] = 'The verification code is the content of Search Console\'s HTML tag: letters, digits, hyphens and underscores. The whole tag may be pasted instead.';
        }
        if ($errors !== []) {
            return $errors;
        }

        Option::setMany([
            'google_ga4_id'            => $ga4 ?? '',
            'google_gtm_id'            => $gtm ?? '',
            'google_site_verification' => $verify ?? '',
            'google_click_events'      => $typed['google_click_events'],
            'google_consent'           => $typed['google_consent'],
            'google_consent_words'     => $typed['google_consent_words'],
            'google_consent_page'      => $typed['google_consent_page'],
        ]);
        return [];
    }

    /** @return array<string, string> the Google tab's fields as typed */
    private static function googleTyped(): array
    {
        return [
            'google_ga4_id'            => trim((string) ($_POST['google_ga4_id'] ?? '')),
            'google_gtm_id'            => trim((string) ($_POST['google_gtm_id'] ?? '')),
            'google_site_verification' => trim((string) ($_POST['google_site_verification'] ?? '')),
            'google_click_events'      => !empty($_POST['google_click_events']) ? '1' : '0',
            // Asking first is on unless the box is unticked; a page that was
            // never a choice is no page.
            'google_consent'           => !empty($_POST['google_consent']) ? '1' : '0',
            'google_consent_words'     => mb_substr(trim((string) ($_POST['google_consent_words'] ?? '')), 0, 500),
            'google_consent_page'      => ctype_digit((string) ($_POST['google_consent_page'] ?? '')) ? (string) $_POST['google_consent_page'] : '',
        ];
    }

}
