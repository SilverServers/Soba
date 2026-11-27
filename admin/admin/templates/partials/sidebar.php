<?php

declare(strict_types=1);

use Cms\Ui\Sidebar;

/**
 * THE ONE SIDEBAR — rendered once, on every admin page, by this file.
 *
 * A screen DECLARES its sidebar as a typed spec (`Ui\Sidebar`, assembled by
 * `Ui\AdminMenu`); it never writes sidebar markup. This file owns ALL of it, and
 * there is deliberately no raw-HTML escape hatch — that absence is what stops
 * eleven competing idioms growing in the same 240px column.
 *
 * ── TWO KINDS OF TOP-LEVEL ENTRY ────────────────────────────────────────────
 *   LEAD ROW   Dashboard, Assistant, Pages. A link, no submenu, no heading
 *              above it, and no collapse control — see `Sidebar::lead()`.
 *   GROUP      A link that is ALSO a heading, with a submenu under it. It has
 *              its own icon, and railed that glyph is the only thing standing
 *              for the whole group — see `Sidebar::group()`.
 *
 * ── THE FLYOUT ──────────────────────────────────────────────────────────────
 * Railed, a group's submenu is not merely hidden: hovering or focusing the
 * glyph pops it out to the right, with the group's name as its header. The
 * panel is the SAME `.sb__sec-body` node either way — expanded it sits under
 * the row in flow, railed `sidebar.js` fixes it beside the rail.
 * Rendering a second copy for the flyout is the obvious approach, and it
 * duplicates every id and leaves a binding made on one copy dead on the other.
 *
 * COLLAPSE STATE IS SERVER-RENDERED, from the cookie `sidebar.js` writes. The JS
 * only WRITES; the server decides what renders. If JS restored the state after
 * load, every navigation would paint a section open for one frame and snap it
 * shut — the single most-noticed chrome jitter there is.
 *
 * ONE NODE. The mobile drawer RELOCATES this element; it never renders a second
 * copy.
 *
 * @var array{nav: list<array<string, mixed>>} $spec
 */

$spec      = $spec ?? ['nav' => []];
$open      = array_flip(Sidebar::openSections());
$railed    = Sidebar::isRailed();

/**
 * WHICH GROUPS ARE SHUT — decided ONCE, here, before anything renders.
 *
 * The rule (shut unless it holds the current page or the viewer opened it)
 * cannot live inside the render loop: the collapse-all control sits ABOVE the
 * loop and has to know whether anything is open before it can say which way it
 * points. Two copies of a rule that must agree will drift apart, so the loop
 * below READS this map rather than recomputing it.
 *
 * @var array<string, bool> $collapsed  section key => is it rendered collapsed
 * @var array<string, bool> $current    section key => does it hold the active row
 */
$collapsed  = [];
$current    = [];
$groupCount = 0;
$openCount  = 0;

foreach ($spec['nav'] as $section) {
    $key = (string) $section['key'];

    // A SECTION CONTAINING THE ACTIVE ROW IS NEVER RENDERED COLLAPSED.
    // The cookie is the user's preference, but landing on a page whose own
    // sidebar row is hidden inside a shut section is a column telling you that
    // you are nowhere. The rule is kept on the server so the section never
    // paints open and snaps shut.
    $hasActive = !empty($section['active']);
    foreach ($section['items'] as $candidate) {
        if (!empty($candidate['active'])) {
            $hasActive = true;
            break;
        }
    }

    // SHUT UNLESS it holds the current page, or the viewer opened it. The
    // inverse — open unless collapsed — would put every group on screen at once
    // and bury the one you were in.
    $current[$key]   = $hasActive;
    $collapsed[$key] = !$hasActive && !isset($open[$key]);

    // The lead rows are not a group: no heading, no chevron, nothing to
    // collapse. They must not be counted by the control that offers to, and
    // neither must a heading over rows, which is always open.
    if ((string) $section['label'] !== '' && empty($section['heading'])) {
        $groupCount++;
        if (!$collapsed[$key]) {
            $openCount++;
        }
    }
}

/**
 * ONE BUTTON, AND THE COLUMN DECIDES WHICH JOB IT IS DOING.
 *
 * With anything open it collapses everything; with everything already shut it
 * expands. Two separate buttons would be worse: one of them is always a no-op,
 * and on a 240px column that is a control whose only job is to do nothing half
 * the time.
 *
 * Its direction is server-rendered from the SAME state the sections are, for the
 * same reason — decide it in JS after load and the glyph paints one way for a
 * frame and then flips.
 *
 * Below two groups there is nothing to collapse ALL of, so the control is not
 * rendered at all rather than rendered inert.
 *
 * AND ONE THING THAT LOOKS LIKE A BUG AND IS NOT: collapse-all on a page whose
 * own group is open shuts it, and the next navigation re-opens it, because a
 * section holding the active row is never RENDERED collapsed (see the pre-pass
 * above). That rule outranks this control — a column that has hidden the row you
 * are standing on tells you that you are nowhere. Do not "fix" it by weakening
 * the pre-pass.
 */
$showCollapseAll = $groupCount >= 2;
$allCollapsed    = $openCount === 0;

/**
 * One NAVIGATE row: glyph + name + optional count.
 *
 * `$inFlyout` decides the ONE thing that differs between the two places this is
 * called from, and it is the tooltip. A tooltip is legal only on a glyph-only
 * element:
 *
 *   LEAD ROW    railed, it loses its label and becomes exactly that — so it
 *               gets a tip, and `sidebar.js` adds and removes them on toggle so
 *               the attribute never outlives the state that permits it.
 *   FLYOUT ROW  keeps its visible label inside the panel at every width, so it
 *               is never glyph-only and must never carry one.
 *
 * Getting this wrong is invisible in both directions: a missing tip is a
 * nameless glyph, and a surplus one is a hover bubble over a word already on
 * screen.
 */
$navRow = function (array $it, bool $inFlyout = false) use ($railed): void {
    $glyph = Sidebar::iconFor($it['icon'] ?? null);
    $full  = (string) ($it['full'] ?? $it['label']);
    $tip   = $railed && !$inFlyout;
    ?>
    <a class="sb__item<?= !empty($it['active']) ? ' is-active' : '' ?><?= !empty($it['child']) ? ' sb__item--child' : '' ?>"
       href="<?= h((string) $it['href']) ?>"
       <?= $tip ? 'data-tip="' . h($full) . '" ' : '' ?>data-label="<?= h($full) ?>"
       aria-label="<?= h($full) ?>"<?= !empty($it['active']) ? ' aria-current="page"' : '' ?>>
        <?php // INLINE SVG, unconditionally. Sidebar::iconFor() cannot return
              // null and there is no iconless branch here: a transparent spacer,
              // on the rail — where the glyph IS the row — would be a blank
              // clickable box you cannot name or aim at. ?>
        <?= icon($glyph, 'sb__item-icon icon') ?>
        <span class="sb__item-name"><?= h((string) $it['label']) ?></span>
        <?php if (($it['count'] ?? null) !== null && (int) $it['count'] > 0): ?>
            <span class="sb__item-count"><?= (int) $it['count'] ?></span>
        <?php endif; ?>
    </a>
    <?php
};
?>
<?php // data-tip-side: this column sits against the LEFT edge, so everything in
      // it points its tooltip out to the right. Above or below would land on the
      // neighbouring row. One attribute here covers every tip inside. ?>
<aside class="sb<?= $railed ? ' is-railed' : '' ?>" data-sidebar data-tip-side="right" aria-label="Sidebar">
    <?php // Top-mounted: you reach for these before you read the column, not
          // after scrolling past it. ONE band holds both: stacking a second
          // full-width button under the rail toggle would put two hairlines
          // above the first section. ?>
    <?php // SOURCE ORDER IS VISUAL ORDER — no `order:` in the CSS. The rail
          // toggle comes FIRST because it is the band's primary control and it
          // sits left; a tab order that disagrees with the eye is a thing you
          // only notice by keyboard, i.e. never, in a screenshot. ?>
    <div class="sb__bar">
        <?php // The rail toggle never carries a tooltip — the chevron speaks
              // for itself. It does get an accurate accessible name, which is
              // a different thing. ?>
        <button type="button" class="sb__bar-btn sb__rail-toggle" data-sb-rail
                aria-label="<?= $railed ? 'Expand sidebar' : 'Collapse sidebar' ?>">
            <?= icon('angle-left') ?>
        </button>

        <?php if ($showCollapseAll): ?>
            <?php // GLYPH-ONLY, SO IT CARRIES A TOOLTIP — and unlike the rail
                  // toggle beside it, this one needs it: a chevron pointing
                  // left at the column's edge reads as "narrow this", but two
                  // stacked chevrons name nothing on their own. `sidebar.js`
                  // rewrites the tip and the aria-label together when the
                  // direction flips, so neither can outlive the state.
                  //
                  // BOTH GLYPHS ARE IN THE DOM and CSS shows one. The
                  // alternative is JS swapping innerHTML, which means the icon
                  // markup exists in two places — a PHP renderer and a JS
                  // string — and the one nobody looks at goes stale. ?>
            <button type="button"
                    class="sb__bar-btn sb__collapse-all<?= $allCollapsed ? ' is-all-collapsed' : '' ?>"
                    data-sb-collapse-all
                    aria-label="<?= $allCollapsed ? 'Expand all sections' : 'Collapse all sections' ?>"
                    data-tip="<?= $allCollapsed ? 'Expand all' : 'Collapse all' ?>">
                <?= icon('angles-up', 'sb__ca-glyph sb__ca-collapse icon') ?>
                <?= icon('angles-down', 'sb__ca-glyph sb__ca-expand icon') ?>
            </button>
        <?php endif; ?>
    </div>

    <div class="sb__scroll">
        <?php foreach ($spec['nav'] as $section): ?>
            <?php
            $key    = (string) $section['key'];
            $label  = (string) $section['label'];
            $isLead = $label === '';

            // Both computed in the pre-pass above — see the note there for why
            // the rule is not re-stated here.
            $isCollapsed = $collapsed[$key];
            $hasActive   = $current[$key];
            $bodyId      = 'sb-' . preg_replace('~[^a-z0-9]+~i', '-', $key);
            ?>

            <?php if ($isLead): ?>
                <?php // No header and no collapse control: the rows above the
                      // first heading are the places you go from anywhere, not
                      // a category. A column whose first control can hide the
                      // way back to the Dashboard is a column that can be
                      // locked. ?>
                <section class="sb__sec sb__sec--nav sb__sec--lead" data-sb-sec="<?= h($key) ?>">
                    <div class="sb__list">
                        <?php foreach ($section['items'] as $it) { $navRow($it); } ?>
                    </div>
                </section>
            <?php elseif (!empty($section['heading'])): ?>
                <?php // A HEADING OVER ROWS, in a column the site arranges
                      // (`Ui\Sidebar::heading()`): the list's own divider label,
                      // not a dressed-up disclosure, since there is nothing to
                      // open; its rows always shown, drawn as the lead rows are.
                      // With no rows, it heads the groups that follow it. ?>
                <section class="sb__sec sb__sec--nav sb__sec--rows" data-sb-sec="<?= h($key) ?>" role="group" aria-labelledby="<?= h($bodyId) ?>-label">
                    <div class="list__group-header" id="<?= h($bodyId) ?>-label"><?= h($label) ?></div>
                    <?php if ($section['items'] !== []): ?>
                        <div class="sb__list">
                            <?php foreach ($section['items'] as $it) { $navRow($it); } ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="sb__sec sb__sec--nav sb__sec--group<?= $isCollapsed ? ' is-collapsed' : '' ?><?= $hasActive ? ' is-current' : '' ?>"
                         data-sb-sec="<?= h($key) ?>" data-sb-group>
                    <?php // THE GROUP ROW — one control with one job. One
                          // element that both navigates and toggles is two
                          // jobs fighting over one click. ?>
                    <?php // ── THE GROUP HEADER IS A DISCLOSURE, NOT A LINK ──
                          // An anchor with a separate chevron beside it would
                          // be wrong: clicking a group to see what is in it
                          // would NAVIGATE you somewhere. Opening and closing a
                          // section is not a destination, and a control that
                          // does both takes you off the page you were on every
                          // time you try to look at the menu.
                          //
                          // As with <summary>, the header toggles and nothing
                          // else. The group's own destination is the "All X"
                          // row inside it, which is exactly what that row is
                          // for.
                          //
                          // A real <button>, not a div with a handler — it needs
                          // to be in the tab order and answer the space bar. ?>
                    <div class="sb__group">
                        <button type="button"
                                class="sb__item sb__item--parent<?= $hasActive ? ' is-current' : '' ?>"
                                data-sb-toggle
                                aria-expanded="<?= $isCollapsed ? 'false' : 'true' ?>"
                                aria-controls="<?= h($bodyId) ?>"
                                data-label="<?= h($label) ?>"
                                aria-label="<?= h($label) ?>">
                            <?= icon(Sidebar::iconFor((string) $section['icon']), 'sb__item-icon icon') ?>
                            <span class="sb__item-name"><?= h($label) ?></span>
                            <?php // ALWAYS RENDERED; `shell.css` hides it on the
                              // rail. Omitting it when $railed would only be
                              // right for a page SERVED railed — collapse the
                              // column with the toggle and the carets would
                              // already be in the DOM with nothing to hide
                              // them, so the 52px rail would grow a column of
                              // chevrons pointing at nothing.
                              //
                              // Server render and JS toggle are two paths to one
                              // state, and any rule expressed in only one of them
                              // is wrong down the other. Visibility belongs in
                              // CSS, which both paths share. ?>
                            <?= icon('angle-down', 'sb__chev icon icon--xs', '', 12) ?>
                        </button>
                    </div>

                    <?php // ONE node, two presentations. Expanded it sits in
                          // flow under the row; railed `sidebar.js` fixes it
                          // beside the rail as a flyout. It is never rendered
                          // twice — a second copy duplicates every id, and a
                          // binding made on one copy is dead on the other. ?>
                    <?php // NO `hidden` ATTRIBUTE. `.sb__sec.is-collapsed
                          // .sb__sec-body { display: none }` already hides it,
                          // and display:none already takes a node out of tab
                          // order — so `hidden` would add nothing and cost the
                          // flyout: railed, a group the server had collapsed
                          // would stay shut on hover, because the attribute
                          // would sit there asserting display:none from a rule
                          // the show rule never fights. One mechanism, not
                          // two. ?>
                    <div class="sb__sec-body" id="<?= h($bodyId) ?>">
                        <?php // The flyout needs to say WHICH group it belongs
                              // to — it floats away from its row, and a bare
                              // list of "All / Add new" beside a rail of glyphs
                              // names nothing. Hidden while expanded, where the
                              // row above it already says so. ?>
                        <div class="sb__flyout-title" aria-hidden="true"><?= h($label) ?></div>
                        <div class="sb__list">
                            <?php foreach ($section['items'] as $it) { $navRow($it, true); } ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</aside>
