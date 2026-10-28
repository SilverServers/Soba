<?php

declare(strict_types=1);

/**
 * THE TITLE CARD — title + permalink, full width, ABOVE the two-column grid.
 *
 * Rendered as two identical 32px inputs, 13px regular, same width, same tone,
 * stacked, the title and the slug — the single most important field on the
 * screen and a technical derivative of it — would be indistinguishable, and
 * nothing would tell the owner what either was for.
 *
 * ── WHAT IT DOES, SPECIFICALLY ──────────────────────────────────────────────
 *
 * 1. THE TITLE IS THE PAGE'S SUBSTANCE, so it looks like it. A large borderless
 *    field on a 2px rule — not a box. A box says "one of several settings"; this
 *    says "this is the thing". It is the only field on the screen with its own
 *    typographic weight, which is what makes the eye start there.
 *
 * 2. THE SLUG IS NOT A FIELD, IT IS A URL. "URL slug: about" tells a
 *    non-technical owner nothing. `https://site/about` with `about` underlined
 *    tells them exactly what they are looking at, and it is a real link they can
 *    open. Editing is behind an Edit button because the slug of a published page
 *    is not something to change by accidentally tabbing into it.
 *
 * 3. THE ACTUAL VALUE GOES IN A HIDDEN INPUT. The visible slug is a preview; the
 *    hidden `slug` input is what posts. That is what lets the display be a link
 *    rather than a control, and it is why `permalink.js` writes to both.
 *
 * ── WHY A PARTIAL AND NOT THREE COPIES ──────────────────────────────────────
 * The entry form and the Pages form are ONE layout (core.css §4), and a shape
 * like this pasted per-template drifts.
 *
 * @var string $titleLabel  e.g. "Page title", "News item title"
 * @var string $titleValue
 * @var string $slugPrefix  the public URL up to the slug, WITH its trailing slash
 * @var string $slugValue   '' on create — the server generates it
 * @var string $slugSuffix  what follows the slug in the address: '/' when the
 *                          site ends its addresses with a slash, else ''
 * @var bool   $slugIsLive  is the prefix+slug a URL that actually resolves today?
 * @var string|null $viewUrl the live public URL, or null if it does not resolve
 */

$titleLabel = $titleLabel ?? 'Title';
$titleValue = $titleValue ?? '';
$slugPrefix = $slugPrefix ?? '';
$slugValue  = $slugValue  ?? '';
$slugSuffix = $slugSuffix ?? '';
$slugIsLive = $slugIsLive ?? false;
$viewUrl    = $viewUrl    ?? null;

// A NEW record has no slug yet, so the permalink row has nothing true to show
// until the title is typed. `permalink.js` fills it in live; until then the row
// says what WILL happen rather than showing a URL ending in nothing.
//
// ── THE NAME IS `$permalinkIsNew`, NOT `$isNew`, AND THAT MATTERS ───────────
// `require` shares the caller's variable scope, so naming this `$isNew` would
// CLOBBER the `$isNew` that content-type/edit.php and pages/edit.php compute
// from `$post`/`$page` — and every one of their uses of it comes AFTER this
// require.
//
// Two of the three paths would hide it by agreeing with the caller by accident:
// on a real edit the slug is set (false, correct) and on a fresh create it is
// empty (true, correct). The third does not. A create that FAILS VALIDATION
// re-renders with `$post = null` but keeps what the author typed — including a
// slug — so `$isNew` would flip to false, and the template would then read
// `$post['status']`, `$post['title']` and `$post['password_hash']` off a null.
//
// That logs `Trying to access array offset on null` on a page returning
// **HTTP 200**, with nothing wrong on screen: the status code is not the
// evidence.
$permalinkIsNew = $slugValue === '';
?>
<?php // ITS OWN CARD, above the two-column grid.
      //
      // Folded into the content card as a first section, it would leave only
      // a hairline between two things that are not the same thing.
      //
      // The title and its address are what the page IS CALLED and WHERE IT
      // LIVES. The content is what it SAYS. Separate pieces, separate cards,
      // with a real gap between them. ?>
<div class="form-card title-card">
    <div class="form-card__section">

        <div class="field">
            <label class="label" for="entry-title">
                <?= h($titleLabel) ?><span class="label__req">required</span>
            </label>
            <?php // NOT `.input`. This is the one control on the screen that is
                  // the page's substance rather than a setting about it, and the
                  // control scale governs boxes in a row — this is not in one.
                  // See admin.css §5a. ?>
            <input class="input-title" type="text" id="entry-title" name="title" required
                   value="<?= h($titleValue) ?>" autocomplete="off"
                   placeholder="<?= h($titleLabel) ?>">
        </div>

        <?php // THE PERMALINK ROW: the address with its editable part, a shape
              // every CMS user already knows how to read. ?>
        <div class="permalink" data-permalink
             data-prefix="<?= h($slugPrefix) ?>"
             data-suffix="<?= h($slugSuffix) ?>"
             data-new="<?= $permalinkIsNew ? '1' : '0' ?>">

            <span class="permalink__label">Address</span>

            <span class="permalink__display" data-permalink-display>
                <?php // A REAL LINK when the entry is actually reachable. On a
                      // draft, or before the first save, the URL is a prediction
                      // — so it renders as text. A link that 404s is worse than
                      // no link, and the entry form already refuses to show a
                      // "View on site" control for the same reason. ?>
                <?php if ($slugIsLive): ?>
                    <a class="permalink__url" data-permalink-link
                       href="<?= h($slugPrefix . $slugValue . $slugSuffix) ?>" target="_blank" rel="noopener"
                    ><?= h($slugPrefix) ?><span class="permalink__slug" data-permalink-slug><?= h($slugValue) ?></span><?= h($slugSuffix) ?></a>
                <?php else: ?>
                    <span class="permalink__url" data-permalink-link
                    ><?= h($slugPrefix) ?><span class="permalink__slug" data-permalink-slug><?= h($slugValue) ?></span><?= h($slugSuffix) ?></span>
                <?php endif; ?>

                <button type="button" class="btn btn--sm permalink__edit" data-permalink-edit>Edit</button>

                <?php // VIEW ON SITE BELONGS WITH THE ADDRESS, not in the page
                      // header. It opens the URL printed immediately to its
                      // left; sitting three inches away in the top-right corner
                      // it would be a control with no visible relationship to
                      // the thing it acts on. Glyph-only, so a tooltip is
                      // allowed. ?>
                <?php if ($viewUrl !== null): ?>
                    <a class="icon-btn permalink__view" href="<?= h($viewUrl) ?>"
                       target="_blank" rel="noopener"
                       data-tip="View on site" aria-label="View on site">
                        <?= icon('up-right-from-square', 'icon icon--sm') ?>
                    </a>
                <?php endif; ?>
            </span>

            <span class="permalink__editor" data-permalink-editor hidden>
                <span class="permalink__prefix"><?= h($slugPrefix) ?></span>
                <input class="input input--mono permalink__input" type="text"
                       data-permalink-input value="<?= h($slugValue) ?>"
                       autocomplete="off" spellcheck="false" aria-label="URL slug">
                <?php // THE ADDRESS'S END, when the site ends addresses with a
                      // slash — the prefix's own quiet text, after the box. ?>
                <?php if ($slugSuffix !== ''): ?>
                    <span class="permalink__prefix"><?= h($slugSuffix) ?></span>
                <?php endif; ?>
                <button type="button" class="btn btn--sm btn--primary" data-permalink-ok>OK</button>
                <button type="button" class="btn btn--sm" data-permalink-cancel>Cancel</button>
            </span>

            <?php // THE HIDDEN INPUT IS WHAT POSTS. The row above it is a preview
                  // and a link; this carries the value. Empty on create, and the
                  // server generates from the title — `Post::generateSlug()` is
                  // the one authority, and permalink.js only previews what it
                  // will do.
                  //
                  // INSIDE `[data-permalink]`, not a sibling of it: permalink.js
                  // scopes every lookup to that root, so as a sibling the hidden
                  // input would never be found — the visible slug would update
                  // and the posted value stay empty. On a NEW page that looks
                  // fine, because an empty slug makes the server generate one
                  // from the title anyway; what it silently throws away is a
                  // slug the author typed by hand into the Edit box. ?>
            <input type="hidden" name="slug" data-permalink-value value="<?= h($slugValue) ?>">
        </div>

    </div>
</div>
