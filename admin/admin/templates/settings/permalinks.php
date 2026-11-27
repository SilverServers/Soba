<?php

declare(strict_types=1);

use Cms\ContentType\Registry;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SETTINGS → PERMALINKS.
 *
 * A FRAGMENT — the controller owns `ob_start()` and `layout.php`. See
 * settings/general.php.
 *
 * ── WHY THERE IS NO "PERMALINK STRUCTURE" INPUT ─────────────────────────────
 * The routes are static. `www/index.php` registers `/{slug}` for a page and
 * `/{public_slug}/{slug}` for an entry, and `Page::findBySlug()` takes one slug.
 * A structure box would therefore accept `/blog/{year}/{slug}`, save it, report
 * success, and change nothing — a control whose only effect is the words beside
 * it, and worse, because this one would look like it had reconfigured the whole
 * site. `PERMALINK_KEYS` holds only the slash setting, below.
 *
 * Making the structure real means dynamic route generation and a redirect for
 * every URL that moves. That is a feature, not a template, and it does not get
 * smuggled in behind a settings screen.
 *
 * SO THIS SCREEN SHOWS THE ADDRESSES THAT ACTUALLY EXIST. The structure is
 * read-only because the router is — and that is NOT a forbidden read-only row.
 * That rule is about a Content Type the admin cannot manage; this is a
 * statement of fact about routing, with no control implying otherwise. When the
 * router reads a structure, the control goes in beside it.
 *
 * ── THE ONE CONTROL: HOW AN ADDRESS ENDS ────────────────────────────────────
 * "End addresses with a slash" is here because it has readers:
 * `Url::page()` makes every page address from it and `Url::settle()` 301s the
 * other form to it. The examples below end the way the setting says, so the
 * screen shows what it does rather than describing it.
 *
 * @var array<string, string|null> $options
 * @var bool                       $saved
 * @var list<string>               $errors
 * @var array<string, mixed>       $user
 */

$contentTypes = Registry::all();
$siteUrl      = rtrim(Url::public(''), '/');
$slashOn      = ($options['permalink_trailing_slash'] ?? '0') === '1';
$end          = $slashOn ? '/' : '';
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('settings')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Settings
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Permalinks</h1>
            <p class="page__subtitle">The web address each kind of content lives at.</p>
        </div>
    </div>

    <?php if ($saved && $errors === []): ?>
        <div class="flashes">
            <div class="flash flash--success">Settings saved.</div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= h(Url::admin('settings/permalinks')) ?>">
        <?= Csrf::field() ?>

        <div class="form-main">
            <div class="form-card">

                <div class="form-card__section">
                    <?= panel_title('Address ending', 'address') ?>
                    <div class="field">
                        <label class="check-list__row" for="f-trailing-slash">
                            <input type="checkbox" id="f-trailing-slash"
                                   name="permalink_trailing_slash" value="1"
                                   <?= $slashOn ? 'checked' : '' ?>>
                            <span>End addresses with a slash</span>
                        </label>
                        <?php // BOTH FORMS, as the addresses they are — and what
                              // happens to the one not chosen, which is the thing
                              // an owner moving a site actually needs to know. ?>
                        <p class="form-help">
                            Off: <code><?= h($siteUrl) ?>/about</code>. On:
                            <code><?= h($siteUrl) ?>/about/</code>. The other form
                            redirects permanently to the one chosen, so links already
                            out there keep working. The home page and files such as
                            <code>sitemap.xml</code> are the same either way.
                        </p>
                    </div>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Pages', 'pages') ?>
                    <?php // SHOWN, NOT DESCRIBED. A worked example with a real slug
                          // in it beats "pages use a flat structure". ?>
                    <div class="kv-list">
                        <div class="kv-list__row">
                            <span class="kv-list__key">Address</span>
                            <span class="kv-list__val"><code><?= h($siteUrl) ?>/<em>page-slug</em><?= h($end) ?></code></span>
                        </div>
                    </div>
                    <p class="form-help">
                        Flat, whatever the page's parent is. A child page of
                        &ldquo;About&rdquo; still lives at its own slug — nesting orders
                        the menu, it does not nest the address.
                    </p>
                </div>

                <?php if ($contentTypes !== []): ?>
                    <div class="form-card__section">
                        <?= panel_title('Content types', 'content-types') ?>
                        <div class="kv-list">
                            <?php foreach ($contentTypes as $typeKey => $type): ?>
                                <?php
                                $publicSlug = (string) \Cms\ContentType\Registry::publicSlug((string) $typeKey);
                                $label      = (string) ($type['label'] ?? $type['type_key'] ?? '');
                                ?>
                                <div class="kv-list__row">
                                    <span class="kv-list__key"><?= h($label) ?></span>
                                    <span class="kv-list__val">
                                        <?php if ($publicSlug === ''): ?>
                                            <?php // A TYPE WHOSE ENTRIES HAVE NO PAGES
                                                  // (`Registry::hasPages()`) has no public
                                                  // address. Printing a half-address for
                                                  // it would invent a page that 404s. ?>
                                            <span class="kv-list__val--muted">No page of their own</span>
                                        <?php else: ?>
                                            <code><?= h($siteUrl . '/' . $publicSlug) ?>/<em>entry-slug</em><?= h($end) ?></code>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="form-help">
                            The first segment is the content type's public slug, set on
                            its own screen under Structure.
                        </p>
                    </div>
                <?php endif; ?>

                <div class="form-card__section">
                    <?= panel_title('Changing an address', 'moving-an-address') ?>
                    <p class="form-help">
                        Editing a page or entry's address is done on that item, in the
                        Address row under its title. Redirects from old addresses are
                        managed under Tools.
                    </p>
                </div>

                <div class="form-card__section form-actions">
                    <a class="link link--xs" href="<?= h(Url::admin('settings')) ?>">Cancel</a>
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--primary">Save changes</button>
                </div>

            </div>
        </div>

    </form>

</div>
