<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Commerce\Countries;
use Cms\Commerce\Regions;
use Cms\Database\Database;
use Cms\Helpers\Dates;
use Cms\Models\Option;

/**
 * THE BUSINESS'S OWN DETAILS (Settings → Business details): its name, how to
 * reach it, its hours, where it is and where it works, and its social
 * profiles. A small business's phone number is on the header's call button,
 * the footer, the contact page, every call to action, a sentence in a page and
 * the structured data; kept in a theme's own file, the owner cannot change it
 * without a developer. Kept here, they change it once and every one follows.
 *
 * ── ONE READ FOR A THEME ────────────────────────────────────────────────────
 * `Business::get()`, an array in this shape, every key always present:
 *
 *     'name'   => 'Maple Lane Gardens',          '' while none is given
 *     'short'  => 'Maple Lane',                  the name when none is given
 *     'email'  => 'hello@example.com',
 *     'phones' => ['office' => [                 in the owner's order; the
 *                     'key'    => 'office',      key is its shortcode's
 *                     'label'  => 'Office',      ([phone office])
 *                     'number' => '(250) 555-0134',   as written
 *                     'tel'    => '+12505550134',     for tel: and sms:, ''
 *                                                    when it cannot be dialled
 *                     'texts'  => true,          takes text messages
 *                 ], …],
 *     'phone'  => 'office',                      the first, the main number
 *     'sms'    => 'office',                      the first that takes texts
 *     'hours'  => [
 *         'text'   => '8 am to 6 pm, Monday to Friday',  as written, else
 *                                                        made from the week
 *         'week'   => ['monday' => ['opens' => '08:00', 'closes' => '18:00'],
 *                      …, 'sunday' => null],             null: closed
 *         'groups' => [['days' => ['Monday', …, 'Friday'],
 *                       'opens' => '08:00', 'closes' => '18:00'], …],
 *     ],
 *     'place'  => ['street' => '', 'city' => 'Nelson', 'region' => 'BC',
 *                  'region_name' => 'British Columbia', 'postcode' => '',
 *                  'country' => 'CA', 'country_name' => 'Canada'],
 *     'areas'  => ['west-kootenay' => ['key' => …, 'name' => 'West Kootenay',
 *                  'towns' => ['Nelson', 'Castlegar'], 'phone' => 'office',
 *                  'page_id' => 12, 'url' => 'https://…/west-kootenay'], …],
 *     'social' => ['facebook' => ['key' => 'facebook', 'network' => 'facebook',
 *                  'name' => 'Facebook', 'url' => 'https://www.facebook.com/…'], …],
 *
 * ── THE SAME DETAILS IN THREE MORE PLACES ───────────────────────────────────
 *   · shortcodes in any text a page prints: [phone], [phone office], [sms],
 *     [email], [hours] (`shortcodes()`, run by `Helpers\Shortcode::process()`);
 *   · the site-wide Organization or LocalBusiness node of the structured data
 *     (`schema()`, from `SEO\Schema::siteWide()`);
 *   · `save()`, the one way in: the screen posts through it, and so does a
 *     script that fills them once from a theme's old file.
 *
 * A STREET IS PUBLISHED WHEN ONE IS GIVEN. A business that works from home or
 * at its customers' leaves it empty, and its place is then its town, province
 * and country alone.
 */
final class Business
{
    public const OPTION = 'business_details';

    /** @var array<string, string> key => the day's name, Monday first */
    public const DAYS = [
        'monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday',
        'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday',
    ];

    /**
     * The countries whose numbers are dialled as +1 and ten digits: a number
     * written there as "(250) 555-0134" is dialled +12505550134. Elsewhere a
     * number is dialable as written with + and its country's code.
     */
    private const PLUS_ONE = [
        'CA', 'US', 'AG', 'AI', 'AS', 'BB', 'BM', 'BS', 'DM', 'DO', 'GD', 'GU', 'JM', 'KN', 'KY',
        'LC', 'MP', 'MS', 'PR', 'SX', 'TC', 'TT', 'VC', 'VG', 'VI',
    ];

    /** @var array<string, string> a host's last two labels => the network's key */
    private const NETWORKS = [
        'facebook.com' => 'facebook', 'fb.com' => 'facebook', 'instagram.com' => 'instagram',
        'x.com' => 'x', 'twitter.com' => 'x', 'linkedin.com' => 'linkedin', 'youtube.com' => 'youtube',
        'youtu.be' => 'youtube', 'tiktok.com' => 'tiktok', 'pinterest.com' => 'pinterest',
        'pinterest.ca' => 'pinterest', 'threads.net' => 'threads', 'threads.com' => 'threads',
        'bsky.app' => 'bluesky', 'yelp.com' => 'yelp', 'yelp.ca' => 'yelp', 'tripadvisor.com' => 'tripadvisor',
        'tripadvisor.ca' => 'tripadvisor', 'houzz.com' => 'houzz', 'github.com' => 'github', 'vimeo.com' => 'vimeo',
    ];

    /** @var array<string, string> a network's key => its name */
    private const NETWORK_NAMES = [
        'facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X', 'linkedin' => 'LinkedIn',
        'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'pinterest' => 'Pinterest', 'threads' => 'Threads',
        'bluesky' => 'Bluesky', 'yelp' => 'Yelp', 'tripadvisor' => 'Tripadvisor', 'houzz' => 'Houzz',
        'github' => 'GitHub', 'vimeo' => 'Vimeo',
    ];

    /** @var array<string, mixed>|null this request's read, until a save */
    private static ?array $read = null;

    // ── READING ─────────────────────────────────────────────────────────────

    /**
     * Everything, in the shape above, with what is derived from it.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        if (self::$read === null) {
            self::$read = self::expand(self::stored());
        }

        return self::$read;
    }

    /**
     * What is kept, as `save()` kept it: what the screen fills its boxes with.
     *
     * @return array<string, mixed>
     */
    public static function stored(): array
    {
        $raw     = json_decode((string) Option::get(self::OPTION, ''), true);
        $country = Countries::shopDefault();

        return array_replace_recursive(self::blank($country), is_array($raw) ? $raw : []);
    }

    /** @return array<string, mixed> */
    private static function blank(string $country): array
    {
        return [
            'name' => '', 'short' => '', 'email' => '', 'phones' => [],
            'hours' => ['text' => '', 'week' => array_fill_keys(array_keys(self::DAYS), [])],
            'place' => ['street' => '', 'city' => '', 'region' => '', 'postcode' => '', 'country' => $country],
            'areas' => [], 'social' => [],
        ];
    }

    /**
     * @param array<string, mixed> $kept
     * @return array<string, mixed>
     */
    private static function expand(array $kept): array
    {
        $place   = (array) $kept['place'];
        $country = (string) ($place['country'] ?? '');

        $phones = [];
        foreach ((array) $kept['phones'] as $p) {
            $phones[(string) $p['key']] = [
                'key'    => (string) $p['key'],
                'label'  => (string) $p['label'],
                'number' => (string) $p['number'],
                'tel'    => self::dialable((string) $p['number'], $country),
                'texts'  => !empty($p['texts']),
            ];
        }
        $texts = array_values(array_filter($phones, static fn (array $p): bool => $p['texts']));

        $week = [];
        foreach (array_keys(self::DAYS) as $day) {
            $span       = (array) ($kept['hours']['week'][$day] ?? []);
            $week[$day] = count($span) === 2 ? ['opens' => (string) $span[0], 'closes' => (string) $span[1]] : null;
        }
        $written = trim((string) ($kept['hours']['text'] ?? ''));

        $areas = [];
        foreach ((array) $kept['areas'] as $a) {
            $pageId = (int) ($a['page'] ?? 0);
            $areas[(string) $a['key']] = [
                'key'     => (string) $a['key'],
                'name'    => (string) $a['name'],
                'towns'   => array_values(array_map('strval', (array) ($a['towns'] ?? []))),
                'phone'   => isset($phones[(string) ($a['phone'] ?? '')]) ? (string) $a['phone'] : '',
                'page_id' => $pageId,
                'url'     => $pageId > 0 ? self::pageUrl($pageId) : '',
            ];
        }

        $social = [];
        foreach ((array) $kept['social'] as $s) {
            $url     = (string) ($s['url'] ?? '');
            $network = self::network($url);
            $key     = $network;
            for ($n = 2; isset($social[$key]); $n++) {
                $key = $network . '-' . $n;
            }
            $social[$key] = ['key' => $key, 'network' => $network, 'name' => self::networkName($network, $url), 'url' => $url];
        }

        $name = trim((string) $kept['name']);

        return [
            'name'   => $name,
            'short'  => trim((string) $kept['short']) !== '' ? trim((string) $kept['short']) : $name,
            'email'  => (string) $kept['email'],
            'phones' => $phones,
            'phone'  => $phones !== [] ? (string) array_key_first($phones) : '',
            'sms'    => $texts !== [] ? $texts[0]['key'] : '',
            'hours'  => ['text' => $written !== '' ? $written : self::hoursLine($week), 'week' => $week, 'groups' => self::groups($week)],
            'place'  => [
                'street'       => (string) ($place['street'] ?? ''),
                'city'         => (string) ($place['city'] ?? ''),
                'region'       => (string) ($place['region'] ?? ''),
                'region_name'  => ($place['region'] ?? '') !== '' ? Regions::name($country, (string) $place['region']) : '',
                'postcode'     => (string) ($place['postcode'] ?? ''),
                'country'      => $country,
                'country_name' => $country !== '' ? Countries::name($country) : '',
            ],
            'areas'  => $areas,
            'social' => $social,
        ];
    }

    /** A published page's address, '' once it is not. */
    private static function pageUrl(int $id): string
    {
        try {
            $stmt = Database::getInstance()->prepare("SELECT slug FROM pages WHERE id = ? AND status = 'published'");
            $stmt->execute([$id]);
            $slug = $stmt->fetchColumn();
        } catch (\Throwable) {
            return '';
        }

        return is_string($slug) && $slug !== '' ? \Cms\Helpers\Url::page($slug) : '';
    }

    // ── SAVING ──────────────────────────────────────────────────────────────

    /**
     * Check and keep the details, all at once: nothing is kept while anything
     * needs fixing. `$in` is what the screen posts, in `stored()`'s shape, and
     * tolerant of a script's: towns as a list or one comma-separated line, a
     * social profile as an address alone, a day's hours as two times.
     *
     * @param array<string, mixed> $in
     * @return list<string> what needs fixing, for the person; [] when kept
     */
    public static function save(array $in): array
    {
        $errors = [];
        $clean  = self::clean($in, $errors);
        if ($errors !== []) {
            return $errors;
        }
        Option::set(self::OPTION, (string) json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::$read = null;

        return [];
    }

    /**
     * `$in` as it would be kept, with what needs fixing in `$errors`: what the
     * screen draws again when a save is refused, so nothing typed is lost.
     *
     * @param array<string, mixed> $in
     * @param list<string>         $errors
     * @return array<string, mixed>
     */
    public static function clean(array $in, array &$errors = []): array
    {
        $text = static fn (mixed $v, int $max = 200): string
            => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($v) ? (string) $v : '')), 0, $max);

        $out = self::blank(Countries::shopDefault());
        $out['name']  = $text($in['name'] ?? '');
        $out['short'] = $text($in['short'] ?? '', 80);
        $out['email'] = $text($in['email'] ?? '');
        if ($out['email'] !== '' && filter_var($out['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'The email address "' . $out['email'] . '" is not one an email can be sent to.';
        }

        // ── THE PLACE, first: a number is dialled by its country ────────────
        // None given (a script's save) is the shop's country, as nothing kept is.
        $place   = is_array($in['place'] ?? null) ? $in['place'] : [];
        $country = strtoupper($text($place['country'] ?? '')) ?: Countries::shopDefault();
        if (!Countries::isValid($country)) {
            $errors[] = 'Choose the country from the list.';
            $country  = '';
        }
        $region = $text($place['region'] ?? '', 10);
        if ($region !== '' && ($country === '' || !Regions::isValid($country, $region))) {
            $errors[] = 'Choose the province or state from the list, for the country chosen.';
        }
        $out['place'] = [
            'street'   => $text($place['street'] ?? ''),
            'city'     => $text($place['city'] ?? '', 100),
            'region'   => $region,
            'postcode' => $text($place['postcode'] ?? '', 20),
            'country'  => $country,
        ];

        // ── PHONE NUMBERS ───────────────────────────────────────────────────
        $keys = [];
        foreach (self::rows($in['phones'] ?? []) as $p) {
            $label  = $text($p['label'] ?? '', 80);
            $number = $text($p['number'] ?? '', 40);
            $key    = self::slug($text($p['key'] ?? '', 40));
            if ($label === '' && $number === '' && $key === '') {
                continue;
            }
            $named = $label !== '' ? '"' . $label . '"' : 'A phone number';
            if ($number === '') {
                $errors[] = $named . ' has no number.';
            } elseif (self::dialable($number, $country) === '') {
                $errors[] = $named . ', ' . $number . ', cannot be dialled as written: '
                    . (in_array($country, self::PLUS_ONE, true)
                        ? 'a number here is ten digits, the area code first.'
                        : 'write it with + and the country\'s code, as +44 20 7946 0000 is.');
            }
            $key = $key !== '' ? $key : (self::slug($label) !== '' ? self::slug($label) : 'phone');
            for ($n = 2, $base = $key; isset($keys[$key]); $n++) {
                $key = $base . '-' . $n;
            }
            $keys[$key] = true;
            $out['phones'][] = ['key' => $key, 'label' => $label, 'number' => $number, 'texts' => !empty($p['texts'])];
        }

        // ── OPENING HOURS ───────────────────────────────────────────────────
        $hours = is_array($in['hours'] ?? null) ? $in['hours'] : [];
        $out['hours']['text'] = $text($hours['text'] ?? '');
        foreach (self::DAYS as $day => $dayName) {
            $span   = is_array($hours['week'][$day] ?? null) ? array_values($hours['week'][$day]) : [];
            $opens  = self::time($span[0] ?? ($span['opens'] ?? ''));
            $closes = self::time($span[1] ?? ($span['closes'] ?? ''));
            if ($opens === null || $closes === null) {
                $errors[] = $dayName . '\'s hours are not times of day.';
                continue;
            }
            if ($opens === '' && $closes === '') {
                continue;   // closed
            }
            if ($opens === '' || $closes === '') {
                $errors[] = $dayName . ' needs the time it opens and the time it closes, or neither for a day it is closed.';
                continue;
            }
            // Midnight closes the day it opened; any other time before the
            // opening is a mistake.
            if ($closes !== '00:00' && strcmp($closes, $opens) <= 0) {
                $errors[] = $dayName . ' closes before it opens.';
                continue;
            }
            $out['hours']['week'][$day] = [$opens, $closes];
        }

        // ── SERVICE AREAS ───────────────────────────────────────────────────
        $areaKeys = [];
        foreach (self::rows($in['areas'] ?? []) as $a) {
            $name  = $text($a['name'] ?? '', 120);
            $towns = is_array($a['towns'] ?? null) ? $a['towns'] : explode(',', (string) ($a['towns'] ?? ''));
            $towns = array_values(array_filter(array_map(static fn ($t): string => $text($t, 80), $towns), 'strlen'));
            $phone = $text($a['phone'] ?? '', 40);
            $page  = (int) ($a['page'] ?? 0);
            if ($name === '' && $towns === [] && $page === 0) {
                continue;
            }
            if ($name === '') {
                $errors[] = 'A service area needs a name.';
                continue;
            }
            if ($phone !== '' && !isset($keys[$phone])) {
                $errors[] = '"' . $name . '" names a phone number that is not on this screen.';
                $phone = '';
            }
            if ($page > 0 && !self::pageExists($page)) {
                $errors[] = '"' . $name . '" links to a page that is no longer here.';
                $page = 0;
            }
            $key = self::slug($name) !== '' ? self::slug($name) : 'area';
            for ($n = 2, $base = $key; isset($areaKeys[$key]); $n++) {
                $key = $base . '-' . $n;
            }
            $areaKeys[$key] = true;
            $out['areas'][] = ['key' => $key, 'name' => $name, 'towns' => $towns, 'phone' => $phone, 'page' => $page];
        }

        // ── SOCIAL PROFILES ─────────────────────────────────────────────────
        foreach (self::rows($in['social'] ?? []) as $s) {
            $url = $text(is_array($s) ? ($s['url'] ?? '') : $s, 300);
            if ($url === '') {
                continue;
            }
            if (!preg_match('~^https?://~i', $url)) {
                $url = 'https://' . ltrim($url, '/');
            }
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_contains($host, '.')) {
                $errors[] = '"' . $url . '" is not a web address.';
                continue;
            }
            $out['social'][] = ['url' => $url];
        }

        return $out;
    }

    /**
     * A list's rows, whatever key each came under (the screen's numbered
     * rows, a script's list); `__ROW__`, a row template's, is not one.
     *
     * @return list<mixed>
     */
    private static function rows(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        unset($list['__ROW__']);

        return array_values($list);
    }

    /** "08:00" as typed, '' for none, null for what is not a time of day. */
    private static function time(mixed $v): ?string
    {
        $v = trim(is_scalar($v) ? (string) $v : '');
        if ($v === '') {
            return '';
        }
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $v, $m) !== 1) {
            return null;
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    private static function pageExists(int $id): bool
    {
        $stmt = Database::getInstance()->prepare('SELECT 1 FROM pages WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    /** "Main office" → "main-office". */
    public static function slug(string $text): string
    {
        $text = strtolower((string) (function_exists('iconv') ? (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text) : $text));

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    }

    // ── WHAT IS DERIVED ─────────────────────────────────────────────────────

    /**
     * A number as written, as it is dialled: "+12505550134", with
     * ";ext=12" for an extension. '' when it cannot be: in Canada, the United
     * States and the rest of +1 a number is ten digits (eleven with the
     * leading 1); anywhere, one written with + and its country's code is 8 to
     * 15 digits.
     */
    public static function dialable(string $number, string $country): string
    {
        $number = trim($number);
        $ext    = '';
        if (preg_match('/^(.*?)\s*(?:ext\.?|extension|x|#)\s*(\d{1,6})\s*$/i', $number, $m) === 1) {
            [$number, $ext] = [$m[1], ';ext=' . $m[2]];
        }
        if (preg_match('/[^\d\s().+\-\/]/', $number) === 1) {
            return '';
        }
        $digits = (string) preg_replace('/\D+/', '', $number);
        if (str_starts_with(ltrim($number), '+')) {
            if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
                return '';
            }
            if ($digits[0] === '1' && preg_match('/^1[2-9]\d{2}[2-9]\d{6}$/', $digits) !== 1) {
                return '';
            }
            return '+' . $digits . $ext;
        }
        if (!in_array($country, self::PLUS_ONE, true)) {
            return '';
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits) === 1 ? '+1' . $digits . $ext : '';
    }

    /** The network an address is a profile on, by its host; the host itself for one not known. */
    public static function network(string $url): string
    {
        $host  = strtolower((string) parse_url($url, PHP_URL_HOST));
        $parts = explode('.', $host);
        $tail  = implode('.', array_slice($parts, -2));
        if (isset(self::NETWORKS[$tail])) {
            return self::NETWORKS[$tail];
        }

        return self::slug((string) preg_replace('/^www\./', '', $host)) ?: 'web';
    }

    private static function networkName(string $network, string $url): string
    {
        return self::NETWORK_NAMES[$network]
            ?? (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    /**
     * The days with the same hours, together, Monday first: what the
     * structured data says, one specification for each.
     *
     * @param array<string, array{opens: string, closes: string}|null> $week
     * @return list<array{days: list<string>, opens: string, closes: string}>
     */
    public static function groups(array $week): array
    {
        $groups = [];
        foreach (self::DAYS as $day => $name) {
            $span = $week[$day] ?? null;
            if ($span === null) {
                continue;
            }
            $at = $span['opens'] . '-' . $span['closes'];
            $groups[$at] ??= ['days' => [], 'opens' => $span['opens'], 'closes' => $span['closes']];
            $groups[$at]['days'][] = $name;
        }

        return array_values($groups);
    }

    /**
     * The week as a sentence, in the owner's time format: "Monday to Friday,
     * 8:00 AM to 6:00 PM; Saturday, 9:00 AM to 1:00 PM". '' when no day has
     * hours.
     *
     * @param array<string, array{opens: string, closes: string}|null> $week
     */
    public static function hoursLine(array $week): string
    {
        $order = array_flip(array_values(self::DAYS));
        $lines = [];
        foreach (self::groups($week) as $g) {
            // Days in a row read as a span: Monday to Friday.
            $runs = [];
            foreach ($g['days'] as $d) {
                $last = count($runs) - 1;
                if ($last >= 0 && $order[end($runs[$last])] === $order[$d] - 1) {
                    $runs[$last][] = $d;
                } else {
                    $runs[] = [$d];
                }
            }
            $spans = array_map(static fn (array $r): string => match (count($r)) {
                1       => $r[0],
                2       => $r[0] . ' and ' . $r[1],
                default => $r[0] . ' to ' . end($r),
            }, $runs);
            $days    = count($spans) > 1 ? implode(', ', array_slice($spans, 0, -1)) . ' and ' . end($spans) : $spans[0];
            $lines[] = $days . ', ' . Dates::asTyped($g['opens'], Dates::timeFormat())
                . ' to ' . Dates::asTyped($g['closes'], Dates::timeFormat());
        }

        return implode('; ', $lines);
    }

    // ── SHORTCODES ──────────────────────────────────────────────────────────

    /**
     * [phone], [phone office], [sms], [sms office], [email] and [hours] in a
     * page's text, as the details are now. A number is a link to call it
     * (`tel:`) or to text it (`sms:`), and the address one to write to. One
     * naming a phone that is not on the screen is left as it is written, so
     * the page shows what to fix rather than a gap.
     */
    public static function shortcodes(string $html): string
    {
        if (!str_contains($html, '[')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/\[(phone|sms|email|hours)(?:\s+([a-z0-9-]+))?\s*\]/i',
            static function (array $m): string {
                $b    = self::get();
                $kind = strtolower($m[1]);
                $key  = strtolower($m[2] ?? '');
                $h    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($kind === 'email') {
                    return $b['email'] !== '' ? '<a href="mailto:' . $h($b['email']) . '">' . $h($b['email']) . '</a>' : '';
                }
                if ($kind === 'hours') {
                    return $h($b['hours']['text']);
                }
                $key = $key !== '' ? $key : ($kind === 'sms' ? $b['sms'] : $b['phone']);
                if ($key === '') {
                    return '';
                }
                $p = $b['phones'][$key] ?? null;
                if ($p === null) {
                    return $m[0];
                }
                if ($p['tel'] === '') {
                    return $h($p['number']);
                }
                $scheme = $kind === 'sms' ? 'sms:' . explode(';', $p['tel'])[0] : 'tel:' . $p['tel'];

                return '<a href="' . $h($scheme) . '">' . $h($p['number']) . '</a>';
            },
            $html
        );
    }

    /**
     * The shortcodes that print a word, for a search to read the pages that
     * name them: '[phone' and '[sms' for a word in a number, '[email' for one
     * in the address, '[hours' for one in the hours. Compared as `Words`
     * folds them.
     *
     * @return list<string>
     */
    public static function shortcodesFor(string $word): array
    {
        $word = \Cms\Helpers\Words::fold($word);
        if ($word === '') {
            return [];
        }
        $b   = self::get();
        $has = static fn (string $text): bool => $text !== '' && str_contains(\Cms\Helpers\Words::fold($text), $word);
        $out = [];
        foreach ($b['phones'] as $p) {
            if ($has($p['number'])) {
                $out = ['[phone', '[sms'];
                break;
            }
        }
        if ($has($b['email'])) {
            $out[] = '[email';
        }
        if ($has($b['hours']['text'])) {
            $out[] = '[hours';
        }

        return $out;
    }

    // ── STRUCTURED DATA ─────────────────────────────────────────────────────

    /**
     * The site's Organization or LocalBusiness node, filled from the details:
     * its telephone, email, address, the places it serves, its profiles, and
     * a local business's opening hours, which only a place has. What is
     * empty is left out.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public static function schema(array $node, bool $local): array
    {
        $b = self::get();
        if ($b['short'] !== '' && $b['short'] !== ($node['name'] ?? '')) {
            $node['alternateName'] = $b['short'];
        }
        $phones = array_values($b['phones']);
        if ($phones !== []) {
            $node['telephone'] = $phones[0]['tel'] !== '' ? explode(';', $phones[0]['tel'])[0] : $phones[0]['number'];
        }
        if (count($phones) > 1) {
            $node['contactPoint'] = array_map(static fn (array $p): array => [
                '@type'       => 'ContactPoint',
                'telephone'   => $p['tel'] !== '' ? explode(';', $p['tel'])[0] : $p['number'],
                'contactType' => $p['label'] !== '' ? $p['label'] : 'customer service',
            ], $phones);
        }
        if ($b['email'] !== '') {
            $node['email'] = $b['email'];
        }

        $place   = $b['place'];
        $address = array_filter([
            'streetAddress'   => $place['street'],
            'addressLocality' => $place['city'],
            'addressRegion'   => $place['region'],
            // A postcode names a few streets: with no street, it is not said either.
            'postalCode'      => $place['street'] !== '' ? $place['postcode'] : '',
            'addressCountry'  => $place['city'] !== '' || $place['street'] !== '' ? $place['country'] : '',
        ], 'strlen');
        if (isset($address['addressLocality']) || isset($address['streetAddress'])) {
            $node['address'] = ['@type' => 'PostalAddress'] + $address;
        }

        $served = [];
        foreach ($b['areas'] as $area) {
            foreach ($area['towns'] !== [] ? $area['towns'] : [$area['name']] as $town) {
                $served[$town] = ['@type' => 'Place', 'name' => $town];
            }
        }
        if ($served !== []) {
            $node['areaServed'] = array_values($served);
        }

        if ($local && $b['hours']['groups'] !== []) {
            $node['openingHoursSpecification'] = array_map(static fn (array $g): array => [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => $g['days'],
                'opens'     => $g['opens'],
                // Midnight is the end of that day, as schema.org writes it.
                'closes'    => $g['closes'] === '00:00' ? '23:59' : $g['closes'],
            ], $b['hours']['groups']);
        }

        $profiles = array_values(array_map(static fn (array $s): string => $s['url'], $b['social']));
        if ($profiles !== []) {
            $node['sameAs'] = $profiles;
        }

        return $node;
    }
}
