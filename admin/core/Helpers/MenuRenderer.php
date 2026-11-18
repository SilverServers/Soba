<?php

declare(strict_types=1);

namespace Cms\Helpers;

use Cms\Auth\Auth;
use Cms\Commerce\Cart;
use Cms\Commerce\Money;
use Cms\ContentType\MenuLocationRegistry;
use Cms\Models\Media;
use Cms\Models\Menu;
use Cms\Models\MenuItem;
use Cms\Models\Option;
use Cms\Ui\Icons;
use Cms\Ui\MenuItemSettings;
use Cms\Ui\MenuItemStyles;
use Cms\Ui\MenuLocationSettings;
use Cms\Ui\MenuToggleBlocks;

/**
 * A MENU, RENDERED.
 *
 * Usage in a template:
 *   <?= \Cms\Helpers\MenuRenderer::render('primary', 'site-header__nav') ?>
 *
 * ── THE CURRENT PAGE IS MARKED, AND THAT IS NOT DECORATION ─────────────────
 * A navigation that cannot say where you are is the commonest accessibility
 * failure on the web and the commonest complaint about a site that "feels
 * confusing". `aria-current="page"` is what a screen reader announces; the class
 * is what the eye follows. Both, or neither is any use.
 *
 * A PARENT OF THE CURRENT PAGE IS MARKED TOO, but differently — `aria-current`
 * belongs to the page you are ON, and putting it on an ancestor tells a screen
 * reader there are two current pages.
 *
 * ── AND IT WORKS WITHOUT JAVASCRIPT ────────────────────────────────────────
 * Everything is authored here, nothing fetched. A dropdown that
 * needs a script is a dropdown that does not open for the first paint, for a
 * reader with scripting off, or when one other script on the page throws. The
 * markup is a `<ul>` with `:hover`/`:focus-within` in the theme's own CSS, so
 * keyboard tabbing opens a sub-menu with no JS at all.
 */
class MenuRenderer
{
    /**
     * THE CURRENT LOCATION'S BEHAVIOUR, for the duration of one `render()`.
     *
     * Static because `renderTree()` is recursive and private and the outermost
     * call is the only one that draws the `<nav>` — threading it through five
     * signatures to be read once at the top is noise. Reset on every `render()`,
     * so two locations on one page cannot inherit each other's answers.
     *
     * @var array<string, string>
     */
    private static array $behaviour = [];

    /**
     * THE PHONE'S TOGGLE BAR for the location being drawn — migration 052, read
     * whole by `MenuToggleBlocks`. Set per `render()`, like the behaviour.
     *
     * @var list<array<string, mixed>>
     */
    private static array $toggleBlocks = [];

    /**
     * This location's settings — or the defaults, when a tree is drawn outside
     * `render()` and nothing has set them.
     *
     * @return array<string, string>
     */
    private static function behaviour(): array
    {
        return self::$behaviour !== [] ? self::$behaviour : MenuLocationSettings::defaults();
    }

    /**
     * IS THIS LOCATION THE HEADER'S BAR?
     *
     * The header's menu is a bar: its sub-menus open and animate, it collapses
     * behind a button on a phone, and it can stick to the top of the window.
     * Every other location is a row of links — the plain branch at the foot of
     * `render()` — so a setting about any of that is offered only where this
     * says yes. `MenuLocationSettings` marks those fields `'bar' => true`, and
     * the location card asks this, so the card and the markup answer the
     * question in one place.
     */
    public static function isBar(string $location): bool
    {
        return $location === 'primary' || $location === '';
    }

    /**
     * Render the menu assigned to a location.
     *
     * @param string $location  registered location slug ('primary', 'footer')
     * @param string $ulClass   class for the outer <ul>
     * @return string           HTML, or '' when no menu is assigned there
     */
    public static function render(string $location, string $ulClass = ''): string
    {
        // IN THE PAGE'S LANGUAGE (`Site\Language`): a page drawn in the second
        // language shows the menu at its location's twin (`primary--fr`), and
        // none when that has none, never the site's own language's.
        $at   = $location !== '' ? MenuLocationRegistry::inLanguage($location, \Cms\Site\Language::drawn()) : $location;
        $menu = Menu::findByLocation($at);
        if ($menu === null) {
            return '';
        }

        // ── HOW THIS MENU BEHAVES HERE ──────────────────────────────────────
        // Migration 050's per-pair bag. Read before the items, because the
        // `<nav>` carries it and an empty menu returns early below.
        self::$behaviour = MenuLocationSettings::read(
            Menu::settingsFor((int) $menu['id'])[$at] ?? null
        );
        self::$toggleBlocks = MenuToggleBlocks::read(
            Menu::toggleBlocksFor((int) $menu['id'])[$at] ?? null
        );

        $items = MenuItem::allForMenu((int) $menu['id']);
        if ($items === []) {
            return '';
        }

        $tree = self::visible(MenuItem::buildTree($items));
        if ($tree === []) {
            // EVERY ITEM HIDDEN IS AN EMPTY MENU, not an empty `<ul>`. A menu
            // whose items are all "signed in only" renders nothing to a
            // visitor, and an empty list still draws its borders and padding.
            return '';
        }

        return self::renderTree($tree, $ulClass, self::currentPath(), true, $location);
    }

    /**
     * Drop the items this viewer is not meant to see.
     *
     * DEPTH-FIRST, and a hidden parent takes its children with it: an item
     * nobody can reach is not a menu entry, and leaving the children behind
     * would promote them to the top level, which is not what anybody asked for.
     *
     * @param  list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private static function visible(array $nodes): array
    {
        $signedIn = Auth::check();
        $out      = [];

        foreach ($nodes as $node) {
            $who = self::audienceOf($node);

            // `audienceOf()` has already reduced this to one of three words, so
            // these two comparisons are reading its OUTPUT, not re-deriving the
            // vocabulary — which is why they stay as literals.
            if (($who === 'signed_in' && !$signedIn) || ($who === 'signed_out' && $signedIn)) {
                continue;
            }

            if (!empty($node['children'])) {
                $node['children'] = self::visible($node['children']);
            }

            $out[] = $node;
        }

        return $out;
    }

    /** 'everyone' | 'signed_in' | 'signed_out' — an unreadable rule means everyone. */
    private static function audienceOf(array $node): string
    {
        $raw = $node['visibility_rules'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return 'everyone';
        }

        $rule = json_decode($raw, true);
        $who  = is_array($rule) ? (string) ($rule['who'] ?? '') : '';

        // A RULE THAT WILL NOT PARSE SHOWS THE ITEM. The other way round, one
        // malformed row silently removes a link from the site's navigation and
        // nothing anywhere says why.
        // THE MODEL'S OWN LIST, minus the empty string that means "everyone" —
        // this method's contract is to NAME that case, not to return ''. No
        // copy of the three words of its own; see `MenuItem::VISIBILITY`.
        return in_array($who, MenuItem::VISIBILITY, true) && $who !== ''
            ? $who
            : 'everyone';
    }

    /**
     * The request path, normalised the way `www/index.php` normalises it before
     * routing — leading slash forced, trailing trimmed, query cut.
     *
     * The same rule as `Redirects`, and for the same reason: a comparison
     * against a path that is spelled differently never matches, and the symptom
     * is a navigation that simply never highlights anything.
     */
    private static function currentPath(): string
    {
        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $path = is_string($path) ? $path : '/';

        if (defined('BASE_PATH')) {
            $base = rtrim((string) BASE_PATH, '/');
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }
        }

        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param bool                       $top   the outermost list, which gets the <nav>
     */
    private static function renderTree(array $nodes, string $ulClass, string $here, bool $top = false, string $location = ''): string
    {
        if ($nodes === []) {
            return '';
        }

        $classAttr = $ulClass !== '' ? ' class="' . htmlspecialchars($ulClass) . '"' : '';
        $html      = '<ul' . $classAttr . '>';
        $b         = self::behaviour();

        foreach ($nodes as $node) {
            $url         = MenuItem::resolveUrl($node);
            $hasChildren = !empty($node['children']);
            $subType     = (string) ($node['sub_menu_type'] ?? 'standard');
            $isMega      = $hasChildren && $subType === 'mega';
            $isTabbed    = $hasChildren && $subType === 'tabbed';

            $isCurrent  = self::isCurrent($url, $here);
            $hasCurrent = !$isCurrent && $hasChildren && self::containsCurrent($node['children'], $here);

            // ── WHAT THIS ITEM WAS TOLD ─────────────────────────────────
            // Migration 050's bag, read through the one definition the dialog
            // and the sanitiser also read. An unreadable bag is the defaults:
            // one malformed row must not be able to remove a link from a site's
            // navigation with nothing anywhere saying why.
            $set = MenuItemSettings::read($node['settings'] ?? null);

            $classes = [];
            if (!empty($node['css_class'])) {
                $classes[] = (string) $node['css_class'];
            }

            // EACH ONE IS A CLASS, NOT AN INLINE STYLE. "Hide on a phone" is a
            // breakpoint question, and a breakpoint cannot be expressed in a
            // style attribute — the theme's own CSS owns the widths, so the
            // markup only has to say which items are which.
            if ($set['hide_on_mobile'])          { $classes[] = 'menu__item--no-phone'; }
            if ($set['hide_on_desktop'])         { $classes[] = 'menu__item--no-desktop'; }
            if ($set['hide_sub_menu_on_mobile']) { $classes[] = 'menu__item--sub-no-phone'; }
            if ($set['collapse_children'])       { $classes[] = 'menu__item--collapsed'; }
            // CLOSE THE MENU WHEN THIS ITEM IS CLICKED — for a link to a place on
            // the same page, where following the link leaves the panel hanging
            // open over the section it just scrolled to.
            // `theme-menu.js` acts on the class.
            if ($set['close_after_click'])       { $classes[] = 'menu__item--close-on-click'; }
            // "Once the menu is stuck": an item for the stuck bar only,
            // or one the stuck bar can do without. The theme reads the class
            // against `menu--stuck`, which the sticky script sets.
            if ($set['sticky_visibility'] === 'show') { $classes[] = 'menu__item--sticky-show'; }
            if ($set['sticky_visibility'] === 'hide') { $classes[] = 'menu__item--sticky-hide'; }
            if ($set['hide_arrow'])              { $classes[] = 'menu__item--no-arrow'; }
            if ($set['item_align'] === 'left')   { $classes[] = 'menu__item--left'; }
            if ($set['item_align'] === 'right')  { $classes[] = 'menu__item--right'; }
            if ($set['align'] === 'right')       { $classes[] = 'menu__item--sub-right'; }
            if ($set['icon_position'] === 'top' && (string) $set['icon'] !== '') {
                $classes[] = 'menu__item--icon-top';
            }
            if ($hasChildren) {
                $classes[] = 'menu__item--parent';
            }
            if ($isMega) {
                $classes[] = 'menu__item--mega';
            }
            // A TILE IN A MEGA PANEL'S GRID, spanning its columns — set by
            // `renderMega()` on the items it lays out, and nowhere else.
            if (isset($node['__span'])) {
                $classes[] = 'menu__span--' . (int) $node['__span'];
            }
            if ($isTabbed) {
                // A TABBED PANEL IS A MEGA PANEL with a different interior, so it
                // keeps `--mega` for everything the theme already says about a
                // dropped panel — width, surface, the phone stacking — and adds
                // its own class for the interior only.
                $classes[] = 'menu__item--mega menu__item--tabbed';
            }
            if ($isCurrent) {
                $classes[] = 'is-current';
            }
            if ($hasCurrent) {
                // THE ANCESTOR GETS A CLASS AND NOT `aria-current`. Two elements
                // claiming to be the current page is worse than none claiming
                // it: a screen reader announces both.
                $classes[] = 'is-current-parent';
            }

            // THE STYLING TAB: a class the theme reads and a custom property
            // holding the value, per ticked row — nothing for a row not ticked.
            $styleAttr = MenuItemStyles::apply(MenuItemStyles::read($set['styles']), $classes);

            $html .= '<li' . ($classes === [] ? '' : ' class="' . htmlspecialchars(implode(' ', $classes)) . '"')
                   . $styleAttr . '>';

            $linkAttrs = '';
            if (!empty($node['target_blank']) && (int) $node['target_blank'] === 1) {
                $linkAttrs .= ' target="_blank" rel="noopener noreferrer"';
            }
            if ($isCurrent) {
                $linkAttrs .= ' aria-current="page"';
            }
            // THE IMAGE SWAP: the picture this item brings up in its panel's
            // picture column, read by `theme-menu.js`.
            if ((string) $set['image_swap'] !== '') {
                $swapUrl = Media::getUrl((int) $set['image_swap']);
                if ($swapUrl !== '') {
                    $linkAttrs .= ' data-image-swap-url="' . htmlspecialchars($swapUrl) . '"'
                                . ' data-image-swap-size="' . htmlspecialchars((string) $set['image_swap_size']) . '"';
                }
            }

            // ── THE LABEL, WITH WHATEVER IT WAS GIVEN ───────────────────
            $label = htmlspecialchars((string) $node['label']);

            // HIDING THE TEXT STILL LEAVES IT FOR A SCREEN READER. An icon-only
            // item with its label deleted is an unlabelled link, which is the
            // single most common accessibility failure in a navigation — so the
            // text is moved out of sight, never removed.
            $inner = $set['hide_text']
                ? '<span class="menu__label menu__label--hidden">' . $label . '</span>'
                : '<span class="menu__label">' . $label . '</span>';

            // ── THE DESCRIPTION, where this location shows them ─────────────
            // The location's "Item descriptions" switch, migration 051's
            // column. Label and description are wrapped together so they stack
            // as one block beside the icon rather than three things in a row.
            // Never with the text hidden: a description under an invisible
            // label is a caption for nothing.
            $desc = trim((string) ($node['description'] ?? ''));
            if ($b['descriptions'] === 'on' && $desc !== '' && !$set['hide_text']) {
                $inner = '<span class="menu__text">' . $inner
                       . '<span class="menu__desc">' . htmlspecialchars($desc) . '</span></span>';
            }

            if ((string) $set['icon'] !== '') {
                // A GLYPH FROM THE LIBRARY, or a custom icon — a picture.
                $glyph = $set['icon'] === 'custom'
                    ? self::customIcon($set)
                    : '<span class="menu__icon">' . Icons::render((string) $set['icon'], 'icon icon--sm') . '</span>';
                $inner = $set['icon_position'] === 'right' ? $inner . $glyph : $glyph . $inner;
            }

            // ── THE BADGE, in one of its four styles ─────────────────────────
            // `badge_style` is allowlisted by `MenuItemSettings`, so it is safe in
            // a class name; "disabled" keeps the text and draws nothing.
            if ((string) $set['badge_text'] !== '' && $set['badge_style'] !== 'disabled') {
                $badgeClass = 'menu__badge menu__badge--' . $set['badge_style'];
                if ($set['badge_hide_on_mobile']) {
                    $badgeClass .= ' menu__badge--no-phone';
                }
                if ($set['badge_hide_on_desktop']) {
                    $badgeClass .= ' menu__badge--no-desktop';
                }
                $inner .= '<span class="' . $badgeClass . '">'
                       . htmlspecialchars((string) $set['badge_text']) . '</span>';
            }

            // ── AN ITEM THAT IS NOT A LINK ──────────────────────────────────
            // For a heading that only opens its sub-menu. A `<span>` and not an
            // `<a href="#">`: the latter is a link that goes nowhere, which a
            // keyboard user lands on and a screen reader announces as a
            // destination. `tabindex="0"` keeps it reachable so the sub-menu can
            // still be opened without a pointer, and `aria-expanded` says what it
            // does.
            // ── REPLACEMENTS: the item as a logo, a search box, HTML,
            // or the basket's total or count — or null, drawn as usual below.
            $replaced = self::replacement($set, $url, $linkAttrs, $inner, $label);
            if ($replaced !== null) {
                $html .= $replaced;
            } elseif ($set['disable_link'] && $hasChildren) {
                $html .= '<span class="menu__link menu__link--static" tabindex="0"'
                       . ' role="button" aria-expanded="false">' . $inner . '</span>';
            } else {
                $html .= '<a class="menu__link" href="' . htmlspecialchars($url) . '"'
                       . $linkAttrs . '>' . $inner . '</a>';
            }

            if ($hasChildren) {
                if ($isMega) {
                    $html .= self::renderMega($node, $here);
                } elseif ($isTabbed) {
                    $html .= self::renderTabbed($node, $here);
                } else {
                    $html .= self::renderTree($node['children'], 'menu__sub', $here);
                }
            }

            $html .= '</li>';
        }

        $html .= '</ul>';

        if (!$top) {
            return $html;
        }

        // ── THE `<nav>` IS ON THE OUTSIDE AND ONLY THERE ────────────────────
        // A nested `<nav>` per sub-menu is three landmarks for one navigation,
        // which is how a screen reader's landmark list becomes useless.
        //
        // AND IT IS NAMED AFTER ITS LOCATION. A page with a header menu and a
        // footer menu would have two landmarks both called "Main" — the label
        // is what tells them apart in a landmark list, and two of the same name
        // is the same as none.
        $label = $location === 'primary' || $location === ''
            ? \Cms\Site\Words::of('nav_main')
            : (string) (MenuLocationRegistry::all()[$location]['label'] ?? ucfirst($location));

        // ── WHAT THE LOCATION WAS TOLD, AS CLASSES AND ONE ATTRIBUTE ────────
        // Classes for everything CSS can answer; a data attribute for the one
        // thing it cannot. A breakpoint is a media query and a media query
        // cannot read a custom property, which is why the control offers four
        // fixed widths and the theme carries a block for each rather than an
        // inline `<style>` per menu.
        $b    = self::behaviour();
        $navClasses = ['menu'];
        $navClasses[] = 'menu--open-' . ($b['event'] === 'click' ? 'click' : 'hover');
        $navClasses[] = 'menu--fx-' . str_replace('_', '-', (string) $b['effect']);
        $navClasses[] = 'menu--speed-' . (string) $b['effect_speed'];
        $navClasses[] = 'menu--phone-' . str_replace('_', '-', (string) $b['mobile']);
        $navClasses[] = 'menu--bp-' . (string) $b['breakpoint'];

        // `second_click` is the ONE answer only a script can give — whether a
        // second click follows the link or closes the panel. It rides as data so
        // the theme's script can read it, and with no script at all the link
        // simply works, which is the safe half of the choice.
        $navData = ' data-second-click="' . htmlspecialchars((string) $b['second_click']) . '"';

        // ── STICKY — the location's Sticky tab ──────────────────────────────
        // Everything the theme's script needs, as data on the `<nav>`: every
        // value is allowlisted or bounded by `MenuLocationSettings`. With
        // scripting off the bar scrolls with the page, which is the safe half.
        // The header's bar only — a footer's row of links has nothing to stick.
        // ── ORIENTATION AND HOW SUB-MENUS FOLD ──────────────────────────────
        // The orientation as a class the theme lays out, and the phone's
        // sub-menu state and behaviour — plus the accordion's — as data for the
        // script that folds them. The header's bar only: a footer's row of links
        // shows its sub-items inline and folds nothing.
        if (self::isBar($location)) {
            if ($b['orientation'] !== 'horizontal') {
                $navClasses[] = 'menu--' . $b['orientation'];
            }
            // The page overlay: the page dims while the menu is in use.
            if ($b['overlay_desktop'] === 'on') {
                $navClasses[] = 'menu--overlay-desktop';
            }
            if ($b['overlay_mobile'] === 'on') {
                $navClasses[] = 'menu--overlay-mobile';
            }
            $navData .= ' data-mobile-state="' . htmlspecialchars((string) $b['mobile_state']) . '"'
                      . ' data-mobile-behaviour="' . htmlspecialchars((string) $b['mobile_behaviour']) . '"';
            if ($b['orientation'] === 'accordion') {
                $navData .= ' data-accordion="' . htmlspecialchars((string) $b['accordion_behaviour']) . '"';
            }
        }

        if (self::isBar($location) && $b['sticky_enabled'] === 'on') {
            foreach ([
                'desktop'        => $b['sticky_desktop'],
                'mobile'         => $b['sticky_mobile'],
                'opacity'        => $b['sticky_opacity'],
                'offset'         => $b['sticky_offset'],
                'expand'         => $b['sticky_expand'],
                'expand-mobile'  => $b['sticky_expand_mobile'],
                'hide'           => $b['sticky_hide'],
                'hide-tolerance' => $b['sticky_hide_tolerance'],
                'hide-offset'    => $b['sticky_hide_offset'],
            ] as $attr => $value) {
                $navData .= ' data-sticky-' . $attr . '="' . htmlspecialchars((string) $value) . '"';
            }
        }

        // ── THE PHONE PANEL BELONGS TO THE HEADER MENU ALONE ────────────────
        // A footer menu is a row of links: no dropdowns, no panel, nothing to
        // collapse. Emitting the switch for every menu would put a SECOND
        // `id="menu-open"` on the page — invalid, and worse than invalid: the
        // footer's label would toggle the HEADER's checkbox, because a `for=`
        // resolves to the first matching id in the document.
        if (!self::isBar($location)) {
            $navClasses[] = 'menu--plain';
            return '<nav class="' . htmlspecialchars(implode(' ', $navClasses)) . '"'
                 . $navData . ' aria-label="' . htmlspecialchars($label) . '">'
                 . $html . '</nav>';
        }

        // THE PHONE'S TOGGLE BAR, from its blocks (migration 052). The default
        // is one toggle reading "Menu", because a hamburger with no label is
        // the most-complained-about control on the mobile web. The switch
        // carries the first toggle's name, so a toggle drawn only as three
        // lines or a picture still says what it opens.
        return '<nav class="' . htmlspecialchars(implode(' ', $navClasses)) . '"'
             . $navData . ' aria-label="' . htmlspecialchars($label) . '">'
             . '<input type="checkbox" id="menu-open" class="menu__switch"'
             . ' aria-label="' . htmlspecialchars(self::toggleName()) . '">'
             . self::toggleBar()
             . $html
             . '</nav>';
    }

    /** What the switch is called: the first toggle's words or its label. */
    private static function toggleName(): string
    {
        foreach (self::$toggleBlocks as $block) {
            if ($block['type'] === 'menu_toggle' && (string) $block['closed_text'] !== '') {
                return (string) $block['closed_text'];
            }
            if (in_array($block['type'], ['menu_toggle_animated', 'menu_toggle_custom'], true)
                && (string) $block['label'] !== '') {
                return (string) $block['label'];
            }
        }

        return \Cms\Site\Words::of('menu_toggle');
    }

    /**
     * THE BAR: three zones, each block in the order the owner dragged it to.
     * Hidden above the phone's width by the theme — on a desktop the menu is
     * the bar.
     */
    private static function toggleBar(): string
    {
        $zones = ['left' => '', 'center' => '', 'right' => ''];
        foreach (self::$toggleBlocks as $block) {
            $zones[(string) $block['align']] .= self::toggleBlock($block);
        }

        $out = '<div class="menu__bar">';
        foreach ($zones as $align => $inner) {
            $out .= '<div class="menu__bar-zone menu__bar-zone--' . $align . '">' . $inner . '</div>';
        }

        return $out . '</div>';
    }

    /**
     * ONE BLOCK as the phone shows it. Every value arrives cleaned by
     * `MenuToggleBlocks` — sizes and styles from allowlists, colours as hex,
     * numbers bounded, links to a safe scheme, HTML through `Sanitizer` — so a
     * class name or a custom property built from one is built from a known
     * shape. Owner-chosen colours and sizes are CUSTOM PROPERTIES; the theme's
     * rules decide what they apply to.
     *
     * @param array<string, mixed> $b
     */
    private static function toggleBlock(array $b): string
    {
        $h    = static fn (mixed $s): string => htmlspecialchars((string) $s);
        $vars = static function (array $pairs) use ($h): string {
            $css = '';
            foreach ($pairs as $name => $value) {
                if ((string) $value !== '') {
                    $css .= $name . ':' . $value . ';';
                }
            }

            return $css === '' ? '' : ' style="' . $h($css) . '"';
        };
        $blank = static fn (array $b): string => !empty($b['new_window']) ? ' target="_blank" rel="noopener"' : '';

        switch ($b['type']) {
            case 'menu_toggle':
                $icon = static fn (string $slug, string $state): string => $slug === ''
                    ? ''
                    : '<span class="menu__toggle-icon menu__toggle-icon--' . $state . '">'
                      . Icons::render($slug, 'icon') . '</span>';
                $icons = $icon((string) $b['closed_icon'], 'closed') . $icon((string) $b['open_icon'], 'open');
                $text  = (string) $b['closed_text'] === (string) $b['open_text']
                    ? ((string) $b['closed_text'] !== '' ? '<span class="menu__toggle-text">' . $h($b['closed_text']) . '</span>' : '')
                    : '<span class="menu__toggle-text menu__toggle-text--closed">' . $h($b['closed_text']) . '</span>'
                      . '<span class="menu__toggle-text menu__toggle-text--open">' . $h($b['open_text']) . '</span>';
                $inner = $b['icon_position'] === 'after' ? $text . $icons : $icons . $text;

                return '<label class="menu__toggle menu__toggle--text-' . $b['text_size']
                     . ' menu__toggle--icon-' . $b['icon_size'] . '" for="menu-open"'
                     . $vars(['--tb-text' => $b['text_colour'], '--tb-icon' => $b['icon_colour']]) . '>'
                     . $inner . '</label>';

            case 'menu_toggle_animated':
                return '<label class="menu__toggle menu__toggle--animated menu__toggle--'
                     . str_replace('_', '-', (string) $b['size']) . '" for="menu-open"'
                     . $vars(['--tb-icon' => $b['colour']]) . '>'
                     . '<span class="menu__burger" aria-hidden="true"><span></span></span></label>';

            case 'menu_toggle_custom':
                $img = static function (int $id, string $state) use ($h): string {
                    $url = Media::getUrl($id);

                    return $url === '' ? ''
                        : '<img class="menu__toggle-img menu__toggle-img--' . $state . '" src="' . $h($url) . '" alt="">';
                };
                $inner = $img((int) $b['closed_image'], 'closed') . $img((int) $b['open_image'], 'open');
                // NO PICTURE CHOSEN YET: the label, so the bar never holds an
                // invisible control.
                if ($inner === '') {
                    $inner = '<span class="menu__toggle-text">' . $h($b['label']) . '</span>';
                }

                return '<label class="menu__toggle menu__toggle--images" for="menu-open">' . $inner . '</label>';

            case 'spacer':
                return '<span class="menu__bar-spacer" aria-hidden="true"'
                     . $vars(['--tb-w' => (int) $b['width'] . 'px']) . '></span>';

            case 'logo':
                $url = Media::getUrl((int) $b['image']);
                if ($url === '') {
                    return '';
                }
                $img = '<img src="' . $h($url) . '" alt="' . $h(\Cms\SEO\Head::globalTokens()['site_name']) . '"'
                     . $vars(['--tb-h' => (int) $b['max_height'] . 'px', '--tb-y' => (int) $b['offset'] . 'px']) . '>';

                return (string) $b['url'] !== ''
                    ? '<a class="menu__bar-logo" href="' . $h(self::barHref((string) $b['url'])) . '"' . $blank($b) . '>' . $img . '</a>'
                    : '<span class="menu__bar-logo">' . $img . '</span>';

            case 'search':
                // WHERE IT GOES: `/search`, the site's own.
                return '<form class="menu__bar-search menu__bar-search--' . str_replace('_', '-', (string) $b['style']) . '"'
                     . ' role="search" method="get" action="' . $h(Url::search()) . '"'
                     . $vars(['--tb-w' => (int) $b['width'] . 'px']) . '>'
                     . '<input class="menu__bar-search-input" type="search" name="q"'
                     . ' placeholder="' . $h($b['placeholder']) . '" aria-label="' . $h(\Cms\Site\Words::of('search_label')) . '">'
                     . '<button class="menu__bar-search-button" type="submit" aria-label="' . $h(\Cms\Site\Words::of('search')) . '">'
                     . Icons::render('magnifying-glass', 'icon') . '</button>'
                     . '</form>';

            case 'icon':
                $cls   = 'menu__bar-icon menu__bar-icon--' . $b['size'];
                $name  = (string) $b['title'] !== ''
                    ? ' aria-label="' . $h($b['title']) . '" title="' . $h($b['title']) . '"'
                    : '';
                $glyph = Icons::render((string) $b['icon'], 'icon');
                $style = $vars(['--tb-icon' => $b['colour']]);

                return (string) $b['url'] !== ''
                    ? '<a class="' . $cls . '" href="' . $h(self::barHref((string) $b['url'])) . '"' . $name . $blank($b) . $style . '>' . $glyph . '</a>'
                    : '<span class="' . $cls . '"' . $name . $style . '>' . $glyph . '</span>';

            case 'html':
                // CLEANED ON THE WAY IN (`Sanitizer::clean`, idempotent), so it is
                // drawn as stored.
                return (string) $b['html'] === '' ? '' : '<div class="menu__bar-html">' . $b['html'] . '</div>';
        }

        return '';
    }

    /**
     * A CUSTOM ICON: a picture, with another for while the item is pointed
     * at, sized for a desktop and for a phone. Sizes travel as custom
     * properties the theme reads; the vertical alignment as the `align-self` a
     * flex row understands, because the link lays its parts out in a row.
     *
     * @param array<string, mixed> $set
     */
    private static function customIcon(array $set): string
    {
        $url = Media::getUrl((int) $set['custom_icon']);
        if ($url === '') {
            return '';
        }
        $hover = (string) $set['custom_icon_hover'] !== '' ? Media::getUrl((int) $set['custom_icon_hover']) : '';

        $align = [
            'middle' => 'center', 'top' => 'flex-start', 'text-top' => 'flex-start', 'super' => 'flex-start',
            'bottom' => 'flex-end', 'text-bottom' => 'flex-end', 'sub' => 'flex-end',
        ][(string) $set['custom_icon_align']] ?? 'center';

        // CUSTOM PROPERTIES ONLY — the house's sanctioned inline idiom —
        // written so the first one is plain to read.
        $more = ((string) $set['custom_icon_width_mobile'] !== '' ? '--ci-mw:' . (int) $set['custom_icon_width_mobile'] . 'px;' : '')
              . ((string) $set['custom_icon_height_mobile'] !== '' ? '--ci-mh:' . (int) $set['custom_icon_height_mobile'] . 'px;' : '')
              . '--ci-align:' . $align . ';';

        return '<span class="menu__icon menu__icon--custom" style="--ci-w:' . (int) $set['custom_icon_width'] . 'px;'
             . '--ci-h:' . (int) $set['custom_icon_height'] . 'px;' . htmlspecialchars($more) . '">'
             . '<img class="menu__icon-img" src="' . htmlspecialchars($url) . '" alt="">'
             . ($hover !== '' ? '<img class="menu__icon-img menu__icon-img--hover" src="' . htmlspecialchars($hover) . '" alt="">' : '')
             . '</span>';
    }

    /**
     * REPLACEMENTS — the item's markup when it is replaced, or null.
     *
     * `$inner` is the link's content as drawn (label, icon, badge) and `$label`
     * the escaped label inside it: a replacement of the item's TEXT swaps the
     * label and leaves its icon and badge.
     *
     * @param array<string, mixed> $set
     */
    private static function replacement(array $set, string $url, string $linkAttrs, string $inner, string $label): ?string
    {
        $h = static fn (mixed $s): string => htmlspecialchars((string) $s);

        // The label's own text replaced, first occurrence — so an icon or a
        // badge beside it stays.
        $text = static function (string $with) use ($inner, $label): string {
            $at = strpos($inner, '>' . $label . '<');

            return $at === false ? $with : substr_replace($inner, '>' . $with . '<', $at, strlen($label) + 2);
        };
        $link = static fn (string $href, string $content): string =>
            '<a class="menu__link" href="' . htmlspecialchars($href) . '"' . $linkAttrs . '>' . $content . '</a>';

        switch ((string) $set['replace_type']) {
            case 'logo':
                $src = Media::getUrl((int) $set['replace_logo']);
                if ($src === '') {
                    return null;
                }
                $alt = (string) (Media::find((int) $set['replace_logo'])['alt_text'] ?? '');
                if ($alt === '') {
                    $alt = \Cms\SEO\Head::globalTokens()['site_name'];
                }
                // AN ITEM GOING NOWHERE LINKS HOME.
                $href = $url === '' || $url === '#' ? Url::home() : $url;

                return '<a class="menu__link menu__link--logo" href="' . $h($href) . '"' . $linkAttrs . '>'
                     . '<img class="menu__logo" src="' . $h($src) . '" alt="' . $h($alt) . '"'
                     . ' width="' . (int) $set['replace_logo_width'] . '" height="' . (int) $set['replace_logo_height'] . '">'
                     . '</a>';

            case 'search':
                $glyph = $set['replace_search_icon'] === 'item' && (string) $set['icon'] !== '' && $set['icon'] !== 'custom'
                    ? Icons::render((string) $set['icon'], 'icon')
                    : Icons::render('magnifying-glass', 'icon');
                $vars = [
                    '--rs-h'         => (int) $set['replace_search_height'] . 'px',
                    '--rs-w'         => (int) $set['replace_search_width'] . 'px',
                    '--rs-radius'    => (int) $set['replace_search_radius'] . 'px',
                    '--rs-y'         => (int) $set['replace_search_offset'] . 'px',
                    '--rs-text'      => $set['replace_search_text'],
                    '--rs-icon'      => $set['replace_search_icon_colour'],
                    '--rs-icon-open' => $set['replace_search_icon_colour_open'],
                    '--rs-bg'        => $set['replace_search_bg'],
                    '--rs-bg-open'   => $set['replace_search_bg_open'],
                ];
                $css = '';
                foreach ($vars as $name => $value) {
                    if ((string) $value !== '') {
                        $css .= $name . ':' . $value . ';';
                    }
                }

                return '<form class="menu__search menu__search--' . str_replace('_', '-', (string) $set['replace_search_style']) . '"'
                     . ' role="search" method="get" action="' . $h(Url::search()) . '" style="' . $h($css) . '">'
                     . '<input class="menu__search-input" type="search" name="q"'
                     . ' placeholder="' . $h($set['replace_search_placeholder']) . '" aria-label="' . $h(\Cms\Site\Words::of('search_label')) . '">'
                     . '<button class="menu__search-button" type="submit" aria-label="' . $h($set['replace_search_label'] ?: \Cms\Site\Words::of('search')) . '">'
                     . $glyph . '</button>'
                     . '</form>';

            case 'html':
                $code = (string) $set['replace_html'];
                if ($code === '') {
                    return null;
                }
                // CLEANED ON THE WAY IN (`Sanitizer::clean`), so drawn as stored.
                if ($set['replace_html_mode'] === 'outer') {
                    return '<span class="menu__link menu__link--html">' . $code . '</span>';
                }
                if ($set['replace_html_mode'] === 'href') {
                    // AN ADDRESS, not markup: the text, if it is one a link may
                    // go to — or the item's own address, untouched.
                    $to = trim(html_entity_decode(strip_tags($code), ENT_QUOTES));
                    if (!preg_match('~^(/(?!/)|#|https?://|mailto:|tel:)~i', $to)) {
                        return null;
                    }

                    return $link(str_starts_with($to, '/') ? BASE_URL . $to : $to, $inner);
                }

                return $link($url, $text($code));

            case 'basket_total':
                return $link($url, $text($h(Money::format(Cart::subtotal()))));

            case 'basket_count':
                return $link($url, $text((string) Cart::count()));
        }

        return null;
    }

    /** A bar link: a path on this site gains the site's address; the rest stand. */
    private static function barHref(string $url): string
    {
        return str_starts_with($url, '/') ? BASE_URL . $url : $url;
    }

    /**
     * A MEGA PANEL — the sub-menu laid out in columns instead of a stack.
     *
     * ── THE COLUMNS ARE A COUNT, NOT A LAYOUT ENGINE ────────────────────────
     * No widgets dragged into a grid. This takes one number — how many columns
     * the children are dealt into — because the thing a site owner actually
     * wants is "my twelve links should not be a twelve-item vertical list", and
     * every additional degree of freedom is a way to build a panel that breaks
     * at 900px.
     */
    private static function renderMega(array $node, string $here): string
    {
        // ── A GRID, ROW-MAJOR, EACH ITEM SPANNING ITS COLUMNS ───────────────
        // The standard layout: the panel is `panel_columns` wide and
        // every item under the parent is a tile spanning `mega_menu_columns` of
        // them, laid out left to right and wrapping.
        //
        // NOT COLUMN-WISE. Dealing the items down a column, so reading order
        // runs down it, is sound for a pile of equal links and meaningless once
        // an item can be wider than one column: a span has no place in a
        // column-wise deal. A tile that spans is laid out the way every grid
        // lays one out.
        $children = $node['children'];

        $total = 0;
        foreach ($children as $i => $child) {
            $set  = MenuItemSettings::read($child['settings'] ?? null);
            $span = max(1, min(MenuItem::MAX_PANEL_COLUMNS, (int) $set['span']));
            $children[$i]['__span'] = $span;
            $total += $span;
        }

        $columns = self::columnCount($node, $total);

        // A SPAN WIDER THAN THE PANEL IS CLAMPED TO IT. `grid-column: span 5` in a
        // three-column grid does not wrap — it manufactures two implicit columns
        // and the panel grows sideways off the screen.
        foreach ($children as $i => $child) {
            $children[$i]['__span'] = min($child['__span'], $columns);
        }

        // THE IMAGE SWAP, WITHOUT A WIDGET. There are no panel widgets, so a
        // panel whose items carry pictures draws the picture column itself —
        // the parent's own picture first, else the first item's.
        $swap = self::swapColumn($node, $children);

        return '<div class="menu__mega menu__mega--grid menu__mega--cols-' . $columns
             . ($swap !== '' ? ' menu__mega--swap' : '') . '"'
             . ' data-columns="' . $columns . '">'
             . self::renderTree($children, 'menu__mega-items', $here)
             . $swap
             . '</div>';
    }

    /**
     * The picture column for a panel, or '' when nothing in it has a picture.
     * Decorative — the links it illustrates carry the names — so it is hidden
     * from a screen reader and its alt is empty.
     *
     * @param array<string, mixed>       $node
     * @param list<array<string, mixed>> $children
     */
    private static function swapColumn(array $node, array $children): string
    {
        $first = null;
        foreach (array_merge([$node], $children) as $candidate) {
            $set = MenuItemSettings::read($candidate['settings'] ?? null);
            if ((string) $set['image_swap'] === '') {
                continue;
            }
            $url = Media::getUrl((int) $set['image_swap']);
            if ($url === '') {
                continue;
            }
            $first ??= ['url' => $url, 'size' => (string) $set['image_swap_size']];
        }
        // A parent's picture alone, with nothing in the panel to swap, is
        // still the picture the panel opens with.
        if ($first === null) {
            return '';
        }

        return '<figure class="menu__swap" aria-hidden="true">'
             . '<img class="menu__swap-img menu__swap-img--' . htmlspecialchars($first['size']) . '"'
             . ' src="' . htmlspecialchars($first['url']) . '" alt="">'
             . '</figure>';
    }

    /**
     * A TABBED PANEL — the second level becomes tabs, the third fills the panel.
     *
     * The structure is the one the owner already built: an item under a tabbed
     * parent is a TAB, and whatever is under that item is what the tab shows.
     * Nothing new to author.
     *
     * ── A TAB WITH NOTHING UNDER IT IS STILL A LINK ─────────────────────────
     * Its panel is simply empty. Refusing to render it would silently drop an
     * item the owner put there, and demoting the whole menu to a plain list
     * because ONE tab is childless would be the menu rearranging itself.
     *
     * ── AND IT NEEDS NO SCRIPT ──────────────────────────────────────────────
     * The first tab's panel shows by default and `:hover` / `:focus-within`
     * swap it, so this works with scripting off and is reachable by tabbing —
     * the same rule the rest of this renderer follows.
     */
    private static function renderTabbed(array $node, string $here): string
    {
        $html = '<div class="menu__mega menu__mega--tabbed"><ul class="menu__tabs">';

        foreach ($node['children'] as $tab) {
            $url     = MenuItem::resolveUrl($tab);
            $current = self::isCurrent($url, $here);
            $inside  = !empty($tab['children']) && self::containsCurrent($tab['children'], $here);

            $classes = ['menu__tab'];
            if ($current) { $classes[] = 'is-current'; }
            // THE TAB HOLDING THE CURRENT PAGE IS MARKED, and marked differently
            // — `aria-current` belongs to the page you are ON, and two elements
            // claiming it tells a screen reader there are two current pages.
            if ($inside)  { $classes[] = 'is-current-parent'; }

            $html .= '<li class="' . htmlspecialchars(implode(' ', $classes)) . '">'
                   . '<a class="menu__tab-link" href="' . htmlspecialchars($url) . '"'
                   . ($current ? ' aria-current="page"' : '') . '>'
                   . htmlspecialchars((string) $tab['label']) . '</a>';

            $html .= '<div class="menu__tab-panel">';
            if (!empty($tab['children'])) {
                $html .= self::renderTree($tab['children'], 'menu__sub', $here);
            }
            $html .= '</div></li>';
        }

        return $html . '</ul></div>';
    }

    /**
     * How many columns to deal into: what the item says, clamped to what it has.
     *
     * FOUR IS THE CEILING because a fifth column on a 1280px screen is 190px
     * wide, which does not hold a link. And a panel is never given more columns
     * than it has children — three columns holding one item each and one empty
     * is what an unclamped count produces.
     */
    private static function columnCount(array $node, int $childCount): int
    {
        $raw = $node['mega_columns'] ?? null;
        $n   = 0;

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $n = is_array($decoded) ? (int) ($decoded['count'] ?? 0) : (int) $raw;
        } elseif (is_int($raw)) {
            $n = $raw;
        }

        if ($n < 1) {
            $n = 2;     // the default a mega panel is for
        }

        // NEVER WIDER THAN WHAT FILLS IT. `$childCount` is the SUM OF THE
        // SPANS, not the number of items: a four-column panel holding two
        // single-column items would otherwise be half empty space, which is the
        // "three columns holding one link each and one empty" this clamp exists
        // to prevent.
        return max(1, min(MenuItem::MAX_PANEL_COLUMNS, $n, $childCount));
    }

    /**
     * Is this link the page being viewed?
     *
     * ── AN ABSOLUTE URL ON THIS SITE IS STILL THIS SITE ─────────────────────
     * An off-site link cannot be the current page — but `MenuItem::
     * resolveUrl()` returns ABSOLUTE urls for this install's own pages, so a
     * guard refusing every URL with a scheme on it would reject every item in
     * the menu and mark nothing. A guard that refuses everything is
     * indistinguishable, from the outside, from a feature that was never built.
     *
     * So: a different HOST is never current; this host is compared by path.
     */
    private static function isCurrent(string $url, string $here): bool
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            $mine  = defined('BASE_URL') ? (string) BASE_URL : '';
            $host  = parse_url($url, PHP_URL_HOST);
            $myHost = $mine !== '' ? parse_url($mine, PHP_URL_HOST) : null;

            if (!is_string($host) || !is_string($myHost) || strcasecmp($host, $myHost) !== 0) {
                return false;
            }

            $url = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

            // AND THE SUBDIRECTORY PREFIX COMES OFF, so an install at
            // /site/ compares `/site/about` against the `/about` the request
            // path was normalised to.
            if (defined('BASE_PATH')) {
                $base = rtrim((string) BASE_PATH, '/');
                if ($base !== '' && str_starts_with($url, $base)) {
                    $url = substr($url, strlen($base));
                }
            }
        }

        $path = strtok($url, '?');
        $path = is_string($path) ? $path : '/';
        $path = '/' . ltrim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return $path === $here;
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function containsCurrent(array $nodes, string $here): bool
    {
        foreach ($nodes as $node) {
            if (self::isCurrent(MenuItem::resolveUrl($node), $here)) {
                return true;
            }
            if (!empty($node['children']) && self::containsCurrent($node['children'], $here)) {
                return true;
            }
        }

        return false;
    }
}
