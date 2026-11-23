<?php

declare(strict_types=1);

/**
 * admin-theme — a site's own admin themes, from the command line.
 *
 *   php admin/bin/admin-theme.php list
 *   php admin/bin/admin-theme.php check www/site/admin-themes/<key>.css
 *   php admin/bin/admin-theme.php --self-test
 *
 * A site's own admin theme is one stylesheet in `www/site/admin-themes/`,
 * named for its key (`Ui\Theme`). It is written the way Tuxedo's block in
 * `www/admin/assets/css/tokens/signature-styles.css` is: a
 * `:root[data-theme="<key>"]` block with every token, blocks scoped to
 * `.nav-top` and to the sidebar (`.nav-rail`, `.sb`) for chrome of its own,
 * and a comment line `Name: <name>`.
 *
 *   list   every theme, the CMS's and the site's own, and which the site
 *          offers: all of them, or the ones config.php names in
 *          'admin_themes', the first of them the default
 *   check  whether a theme is ready: its file name is a plain key that is not
 *          a theme the CMS ships; it holds its `:root[data-theme]` block with
 *          every token Tuxedo's has; a top bar or sidebar block of its own
 *          sets every token Tuxedo's do, and a sidebar block covers the rail,
 *          the sidebar, an entry's side column and the drawer on a phone, as
 *          Tuxedo's does; nothing in it loads from another site (`@import`,
 *          or an address with a scheme or beginning `//`); and its text reads
 *          on its backgrounds, at 4.5:1 (WCAG AA): the ink on the page and on
 *          a card, the second ink on a card, the hero's and the inset's, a
 *          primary button's text (`--ink-on-strong`, white unless the theme
 *          sets it) on the accent, and the ink of its top bar and sidebar on
 *          their own backgrounds and on a selected row
 *
 * Exit status: 0 ready (or listed), 1 not ready, 2 bad usage.
 *
 * One of the operator scripts in admin/bin/ that ship in a package (listed in
 * admin/core/Update/ownership.php).
 */

use Cms\Ui\Theme;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT_PATH', dirname(__DIR__));
define('PUBLIC_PATH', dirname(__DIR__, 2) . '/www');
require ROOT_PATH . '/vendor/autoload.php';

const USAGE = "Usage:\n"
    . "  php admin/bin/admin-theme.php list           every theme, and which the site offers\n"
    . "  php admin/bin/admin-theme.php check <file>   whether a site's own theme is ready\n";

/** Every token Tuxedo's `:root[data-theme]` block defines: a theme without one inherits the default's. */
const TOKENS = [
    '--bg', '--surface', '--surface-2', '--ink', '--ink-2', '--ink-3', '--ink-disabled', '--border', '--border-strong',
    '--accent', '--accent-strong', '--accent-soft', '--hover', '--active', '--focus', '--danger', '--danger-soft',
    '--warning', '--warning-soft', '--shadow', '--color-scheme', '--picker-invert', '--surface-hero', '--ink-hero',
    '--surface-inset', '--ink-inset',
];

/** Every token Tuxedo's top bar and sidebar blocks set again: a block without one lets the page's show through. */
const CHROME = [
    '--bg', '--surface', '--surface-2', '--ink', '--ink-2', '--ink-3', '--ink-disabled', '--border', '--border-strong',
    '--hover', '--active', '--color-scheme', '--picker-invert',
];

/** The places a sidebar block covers, as Tuxedo's does: the rail, the sidebar, an entry's side column, the drawer on a phone. */
const SIDEBAR = ['.nav-rail', '.sb', '.entity-rail-col', '.nav-drawer'];

/**
 * Each rule's selectors and custom properties, comments removed.
 *
 * @return list<array{selectors: list<string>, props: array<string, string>}>
 */
function rules(string $css): array
{
    $css = (string) preg_replace('/\/\*.*?\*\//s', '', $css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $found, PREG_SET_ORDER);
    $rules = [];
    foreach ($found as $rule) {
        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $rule[2], $props, PREG_SET_ORDER);
        $map = [];
        foreach ($props as $p) {
            $map[strtolower($p[1])] = trim($p[2]);
        }
        $rules[] = [
            'selectors' => array_map(static fn (string $s): string => (string) preg_replace('/\s+/', ' ', trim($s)), explode(',', $rule[1])),
            'props'     => $map,
        ];
    }
    return $rules;
}

/** @return array<string, string>|null the properties of the first rule naming `$selector` */
function block(array $rules, string $selector): ?array
{
    foreach ($rules as $rule) {
        if (in_array($selector, $rule['selectors'], true)) {
            return $rule['props'];
        }
    }
    return null;
}

/**
 * Why a theme is not ready, or [] when it is.
 *
 * @return list<string>
 */
function problems(string $key, string $css): array
{
    $out = [];
    if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $key) !== 1) {
        $out[] = 'Its file name must be its key: lower-case letters, digits and dashes, ending .css.';
    }
    if (in_array($key, ['tuxedo', 'midnight', 'pearl'], true)) {
        $out[] = "\"{$key}\" is a theme the CMS ships; give the site's own theme a name of its own.";
    }
    if (Theme::read($key, $css) === null) {
        $out[] = "It has no :root[data-theme=\"{$key}\"] block.";
        return $out;
    }

    // Read as a browser would: comments gone, escapes decoded.
    $flat = (string) preg_replace('/\/\*.*?\*\//s', '', $css);
    $flat = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static fn (array $m): string => (string) mb_chr((int) hexdec($m[1])), $flat);
    $flat = strtolower((string) preg_replace('/\\\\(.)/su', '$1', $flat));
    if (str_contains($flat, '@import')) {
        $out[] = 'It uses @import: everything it needs must be in the file, or beside it.';
    }
    if (str_contains($flat, '://') || preg_match('#(\(|["\'\s,])//#', $flat) === 1) {
        $out[] = 'An address in it is another site\'s: a font or picture must be a file beside it, named by a relative path.';
    }

    $rules = rules($css);
    $root  = block($rules, ':root[data-theme="' . $key . '"]') ?? [];
    $missing = array_values(array_diff(TOKENS, array_keys($root)));
    if ($missing !== []) {
        $out[] = 'Its :root block leaves out ' . implode(', ', $missing) . '; each would be the default theme\'s.';
    }

    // A TOP BAR OR SIDEBAR OF ITS OWN, as Tuxedo's: each block sets every
    // token Tuxedo's does, or the page's light ones show through in it (a
    // selected row's --surface-2 behind the sidebar's light ink), and a
    // sidebar block covers all four places. A theme with neither, as Pearl,
    // has the page's colours there.
    $scoped = static fn (string $selector): ?array => block($rules, ':root[data-theme="' . $key . '"] ' . $selector);
    $places = array_combine(SIDEBAR, array_map($scoped, SIDEBAR));
    $found  = array_filter($places, static fn (?array $props): bool => $props !== null);
    if ($found !== [] && count($found) < count(SIDEBAR)) {
        $out[] = 'Its sidebar block leaves out ' . implode(', ', array_keys(array_diff_key($places, $found)))
            . ': the sidebar\'s block covers ' . implode(', ', SIDEBAR) . ', as Tuxedo\'s does.';
    }
    $chrome = ['the top bar' => $scoped('.nav-top'), 'the sidebar' => $found === [] ? null : reset($found)];
    foreach ($chrome as $where => $props) {
        $missing = $props === null ? [] : array_values(array_diff(CHROME, array_keys($props)));
        if ($missing !== []) {
            $out[] = ucfirst($where) . "'s block leaves out " . implode(', ', $missing) . "; each would be the page's.";
        }
    }

    $pairs = [
        ['root', '--ink', '--bg', 'the ink on the page'],
        ['root', '--ink', '--surface', 'the ink on a card'],
        ['root', '--ink-2', '--surface', 'the second ink on a card'],
        ['root', '--ink-hero', '--surface-hero', "the hero's ink on its background"],
        ['root', '--ink-inset', '--surface-inset', "the inset's ink on its background"],
        ['root', '--ink-on-strong', '--accent', "a primary button's text on the accent"],
    ];
    foreach ($chrome as $where => $props) {
        if ($props !== null) {
            $pairs[] = [$where, '--ink', '--bg', "{$where}'s ink on its background"];
            $pairs[] = [$where, '--ink', '--surface-2', "{$where}'s ink on a selected row"];
        }
    }
    foreach ($pairs as [$scope, $fg, $bg, $what]) {
        // A primary button's text is white (`tokens-base.css`) unless the theme sets its own.
        $props = ($scope === 'root' ? $root : ($chrome[$scope] ?? []) + $root) + ['--ink-on-strong' => '#ffffff'];
        $a = str_starts_with($fg, '#') ? $fg : (string) ($props[$fg] ?? '');
        $b = (string) ($props[$bg] ?? '');
        $ratio = Theme::contrast($a, $b);
        if ($ratio === null) {
            $out[] = "To measure {$what}, " . (str_starts_with($fg, '#') ? $bg : "{$fg} and {$bg}") . ' must be #hex colours.';
        } elseif ($ratio < 4.5) {
            $out[] = sprintf('%s is %.1f:1; text needs 4.5:1.', ucfirst($what), $ratio);
        }
    }
    return $out;
}

/** Tuxedo's blocks under another key, its accent the darker green its buttons need: a theme that is ready. */
function sample(string $key): string
{
    $chrome = " --ink: #f5f5f6; --ink-2: #b4bdc7; --ink-3: #8b95a3; --ink-disabled: #424b58;\n"
        . "  --border: #1a2230; --border-strong: #2a3340; --hover: rgba(255, 255, 255, 0.05); --active: rgba(255, 255, 255, 0.08);\n"
        . "  --color-scheme: dark; --picker-invert: 0.6;\n";

    return "/* Name: Self test */\n"
        . ":root[data-theme=\"{$key}\"] {\n"
        . "  --bg: #edf1f5; --surface: #fafcfd; --surface-2: #e1e6eb; --ink: #0c1219; --ink-2: #4a5360; --ink-3: #57636f;\n"
        . "  --ink-disabled: #bcc3cc; --border: #d6dbe1; --border-strong: #bcc4cc; --accent: #167a3e; --accent-strong: #11612f;\n"
        . "  --accent-soft: rgba(27, 148, 75, 0.10); --hover: rgba(0, 0, 0, 0.04); --active: rgba(0, 0, 0, 0.07);\n"
        . "  --focus: rgba(27, 148, 75, 0.45); --danger: #b73d3d; --danger-soft: rgba(183, 61, 61, 0.10); --warning: #a17a30;\n"
        . "  --warning-soft: rgba(161, 122, 48, 0.10); --shadow: 0 4px 12px rgba(0, 0, 0, 0.10); --color-scheme: light;\n"
        . "  --picker-invert: 0; --surface-hero: #0d1218; --ink-hero: #f5f5f6; --surface-inset: #fafcfd; --ink-inset: #0c1219;\n"
        . "}\n"
        . ":root[data-theme=\"{$key}\"] .nav-rail,\n:root[data-theme=\"{$key}\"] .sb,\n"
        . ":root[data-theme=\"{$key}\"] .entity-rail-col,\n:root[data-theme=\"{$key}\"] .nav-drawer {\n"
        . "  --bg: #06090c; --surface: #161c25; --surface-2: #1d2530;" . $chrome . "}\n"
        . ":root[data-theme=\"{$key}\"] .nav-top {\n"
        . "  --bg: #0d1218; --surface: #0d1218; --surface-2: #161c25;" . $chrome . "}\n";
}

/** `$css` with `$old`, which must be in it exactly once, made `$new`: a case whose change no longer applies fails, not passes. */
function swap(string $css, string $old, string $new): string
{
    if (substr_count($css, $old) !== 1) {
        fwrite(STDERR, "self-test: the sample does not hold '{$old}' exactly once\n");
        exit(1);
    }
    return str_replace($old, $new, $css);
}

$args = array_slice($argv, 1);

if ($args === ['--self-test']) {
    // EVERY RULE MUST BE ABLE TO FAIL: Tuxedo under another key is ready, and
    // each sabotage of it must be refused.
    $good = sample('selftest');
    $pageOnly = strstr($good, ':root[data-theme="selftest"] .nav-rail', true);
    if ($pageOnly === false) {
        fwrite(STDERR, "self-test: the sample has no sidebar block\n");
        exit(1);
    }
    $cases = [
        'Tuxedo under another key is ready'           => ['selftest', $good, false],
        'dark button text on a pale accent is ready'  => ['selftest', swap($good, '--accent: #167a3e;', '--accent: #9fe0b8; --ink-on-strong: #0c1219;'), false],
        'no top bar or sidebar of its own, as Pearl, is ready' => ['selftest', $pageOnly, false],
        'a token left out'                            => ['selftest', swap($good, ' --ink-3: #57636f;', ''), true],
        'an @import'                                  => ['selftest', $good . "@import url(theme.css);\n", true],
        'a font from another site'                    => ['selftest', $good . "@font-face { src: url(https://fonts.example/x.woff2); }\n", true],
        'a font from another site, //'                => ['selftest', $good . "@font-face { src: url(//fonts.example/x.woff2); }\n", true],
        'grey ink on the page'                        => ['selftest', swap($good, '--ink: #0c1219;', '--ink: #c0c4c8;'), true],
        'white on a pale accent'                      => ['selftest', swap($good, '--accent: #167a3e;', '--accent: #9fe0b8;'), true],
        'dark ink on the dark top bar'                => ['selftest', swap($good, "--bg: #0d1218; --surface: #0d1218; --surface-2: #161c25; --ink: #f5f5f6;",
                                                              "--bg: #0d1218; --surface: #0d1218; --surface-2: #161c25; --ink: #1a1f26;"), true],
        'a sidebar block without the whole set'       => ['selftest', swap($good, ' --surface-2: #1d2530;', ''), true],
        'a top bar block without the whole set'       => ['selftest', swap($good, ' --surface: #0d1218;', ''), true],
        'a sidebar block that leaves out the drawer'  => ['selftest', swap($good, ",\n:root[data-theme=\"selftest\"] .nav-drawer {", ' {'), true],
        'a pale selected row in the dark sidebar'     => ['selftest', swap($good, '--surface-2: #1d2530;', '--surface-2: #e1e6eb;'), true],
        'a key the CMS ships'                         => ['tuxedo', sample('tuxedo'), true],
        'a file name that is not a plain key'         => ['Self_Test', sample('Self_Test'), true],
        'a file name that is not its key'             => ['other', $good, true],
        'a colour that cannot be measured'            => ['selftest', swap($good, '--ink: #0c1219;', '--ink: var(--x);'), true],
    ];
    $wrong = 0;
    foreach ($cases as $case => [$key, $css, $refused]) {
        $got = problems($key, $css) !== [];
        if ($got !== $refused) {
            $wrong++;
            echo "  FAIL  {$case}: " . ($refused ? 'not refused' : 'refused: ' . implode(' ', problems($key, $css))) . "\n";
        }
    }

    // THE SITE'S LIST, config.php's 'admin_themes', tried on the three
    // themes the CMS ships.
    $offered = static fn (): string => implode(',', array_column(Theme::offered(), 'key'));
    $lists = [
        'without the key, every theme is offered, Tuxedo the default' => [[],
            static fn (): bool => array_slice(array_column(Theme::offered(), 'key'), 0, 3) === ['tuxedo', 'midnight', 'pearl'] && Theme::defaultKey() === 'tuxedo'],
        'a list offers its themes alone, in its order, the first the default' => [['pearl', 'tuxedo'],
            static fn (): bool => $offered() === 'pearl,tuxedo' && Theme::defaultKey() === 'pearl'],
        'a choice the list leaves out is not offered, and wears the default' => [['pearl', 'tuxedo'],
            static fn (): bool => !Theme::offers('midnight') && Theme::resolve(['theme_preset' => 'midnight']) === 'pearl'],
        'a choice the list keeps is worn, and no choice wears the default' => [['pearl', 'tuxedo'],
            static fn (): bool => Theme::resolve(['theme_preset' => 'tuxedo']) === 'tuxedo' && Theme::resolve(null) === 'pearl'],
        'a key that names no theme is passed over' => [['nosuch', 'midnight'],
            static fn (): bool => $offered() === 'midnight' && Theme::defaultKey() === 'midnight'],
        'a list that names no theme offers every theme' => [['nosuch'],
            static fn (): bool => Theme::defaultKey() === 'tuxedo' && count(Theme::offered()) === count(Theme::all())],
        'a theme named twice is offered once' => [['pearl', 'pearl', 'tuxedo'],
            static fn (): bool => $offered() === 'pearl,tuxedo'],
    ];
    foreach ($lists as $case => [$keys, $holds]) {
        Theme::configure($keys);
        if (!$holds()) {
            $wrong++;
            echo "  FAIL  {$case}: offered " . $offered() . ', default ' . Theme::defaultKey() . "\n";
        }
    }
    Theme::configure(null);

    $total = count($cases) + count($lists);
    echo $wrong === 0
        ? "admin-theme self-test: {$total} cases, each as it must be.\n"
        : "admin-theme self-test: {$wrong} of {$total} cases wrong.\n";
    exit($wrong === 0 ? 0 : 1);
}

if ($args === ['list']) {
    $offered = array_column(Theme::offered(), 'key');
    foreach (Theme::all() as $t) {
        printf("%-14s %-24s %-5s %-36s %s\n", $t['key'], $t['label'], $t['kind'],
            $t['href'] === null ? 'ships with the CMS' : Theme::SITE_DIR . '/' . $t['key'] . '.css',
            $t['key'] === Theme::defaultKey() ? 'default' : (in_array($t['key'], $offered, true) ? 'offered' : 'not offered'));
    }
    if (Theme::named() !== []) {
        echo "\nconfig.php's 'admin_themes': " . implode(', ', Theme::named()) . "\n";
        foreach (array_diff(Theme::named(), array_column(Theme::all(), 'key')) as $key) {
            echo "  {$key} is no theme here, so it is passed over.\n";
        }
    }
    exit(0);
}

if (count($args) === 2 && $args[0] === 'check') {
    $file = $args[1];
    if (!is_file($file)) {
        fwrite(STDERR, "No file at {$file}.\n");
        exit(2);
    }
    $key = basename($file, '.css');
    $out = substr($file, -4) === '.css' ? problems($key, (string) file_get_contents($file)) : ['Its name must end .css.'];
    if (realpath(dirname($file)) !== realpath(PUBLIC_PATH . '/' . Theme::SITE_DIR)) {
        $out[] = 'It must be in www/' . Theme::SITE_DIR . '/ for the admin to find it.';
    }
    if ($out === []) {
        $theme = Theme::read($key, (string) file_get_contents($file));
        echo "READY  {$key}: \"{$theme['label']}\", {$theme['kind']}. Set it on an account's Admin theme, or as the sign-in page's on Settings → Branding.\n";
        exit(0);
    }
    echo "NOT READY  {$key}\n";
    foreach ($out as $problem) {
        echo "  - {$problem}\n";
    }
    exit(1);
}

fwrite(STDERR, USAGE);
exit(2);
