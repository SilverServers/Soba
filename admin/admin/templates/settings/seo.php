<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\SEO\Head;
use Cms\Security\Csrf;
use Cms\Ui\VariableEditor;
// NO confirm.js. Nothing on this screen opens a confirm dialog, and a script
// tag for a control that is not here is a request per page load for nothing —
// and the next person reads it as evidence something here confirms.

/**
 * SETTINGS → SEO. A FRAGMENT — the controller owns `ob_start()` and `layout.php`.
 *
 * ── FOUR TABS, FOUR FORMS, ONE HIDDEN FIELD ─────────────────────────────────
 * `handleSeo()` switches on `$_POST['_seo_tab']` and calls one of four private
 * savers. Each saver writes ONLY its own keys, so each tab is a separate
 * `<form>` carrying its own `_seo_tab`. One big form posting every field would
 * still only save the tab it named and silently discard the rest.
 *
 * The tabs are LINKS (`?tab=`) rather than JavaScript panels: the controller
 * already reads `$_GET['tab']`, so the address names the tab you are on and can
 * be linked to. Panels would put that state somewhere no link can reach.
 *
 * ── REDIRECTS IS NOT A TAB HERE ─────────────────────────────────────────────
 * It is Tools → Redirects, with a controller and a screen of its own — see
 * `RedirectsController`. SEO-adjacent as it is, a tab here would make the
 * sidebar's Redirects entry a 302 into this page, and the Tools group could
 * not be landed on. `showSeo()` forwards `?tab=redirects` there.
 *
 * ── WHAT IS DELIBERATELY NOT ON THIS SCREEN ─────────────────────────────────
 * `SEO_KEYS` declares one option that NOTHING reads, checked by searching
 * `admin/core` for the key:
 *
 *   seo_ping_on_publish  0 readers. Nothing pings anything on publish; the
 *                        checkbox would be a switch wired to nothing.
 *
 * It is not rendered. It stays in `SEO_KEYS` because the General saver
 * references it and removing it is a controller change, not a template one —
 * but a control that cannot act does not go on the screen. A control comes
 * only with the code that acts on it.
 *
 * `seo_separator`, by contrast, is read: the patterns use `{sep}`, so the
 * character is set once on the Title patterns tab and every pattern prints it
 * (`Head::globalTokens()`).
 *
 * ── THE PATTERNS AND THE HOMEPAGE PAIR ARE PILLS ────────────────────────────
 * A replacement-variable editor (`variable-editor.js`): each variable is one
 * piece wearing its label, inserted from "Insert variable" or by typing %,
 * dragged to move it. The `<input>` under each is still what posts.
 *
 * `seo_og_image` is the site-wide Open Graph fallback in `SEO\Head`, and
 * `seo_homepage_title` / `seo_homepage_description` are read by
 * `Public\HomeController`. Those three ARE rendered, and their hints describe
 * what those call sites actually do.
 *
 * @var array<string, string|null> $options
 * @var string                     $tab
 * @var array<string, mixed>       $contentTypes
 * @var bool                       $saved
 * @var list<string>               $errors
 * @var array<string, mixed>       $user
 */

$opt = static fn (string $k, string $fallback = ''): string
    => (string) ($options[$k] ?? $fallback);

// A SITE-WIDE IMAGE: the library's picture by its id, or an address kept
// from before there was a picker, shown in a box of its own while it is set.
$siteImage = static function (string $key) use ($opt): array {
    $stored = trim($opt($key));
    return ctype_digit($stored) ? ['id' => (int) $stored, 'url' => ''] : ['id' => 0, 'url' => $stored];
};

$tabs = [
    'general'   => 'General',
    'titles'    => 'Title patterns',
    'schema'    => 'Organisation',
    'robots'    => 'robots.txt',
    'google'    => 'Google',
];
$tab = array_key_exists($tab, $tabs) ? $tab : 'general';

$tabUrl = static fn (string $key): string
    => Url::admin('settings/seo') . ($key === 'general' ? '' : '?tab=' . $key);
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('settings')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Settings
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">SEO</h1>
        </div>
    </div>

    <?php if ($saved && $errors === []): ?>
        <div class="flashes">
            <div class="flash flash--success">Settings saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">
                    <?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?>
                </p>
                <ul class="alert__text">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="tabs__tab<?= $tab === $key ? ' is-active' : '' ?>"
               <?= $tab === $key ? 'aria-current="page"' : '' ?>
               href="<?= h($tabUrl($key)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="form-main">

        <?php if ($tab === 'general'): ?>
            <form method="post" action="<?= h(Url::admin('settings/seo')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_seo_tab" value="general">
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Homepage', 'homepage') ?>

                        <?php // THE BOX STARTS FROM THE HOME PATTERN. Saved
                              // still saying the same, it is stored as none and
                              // follows the pattern (`Head::ownTitle()`);
                              // changed, it is the front page's own — page 1's,
                              // since page 2 on takes the pattern for its page
                              // number (`Public\HomeController`). ?>
                        <?php $homeTitle = $opt('seo_homepage_title'); ?>
                        <div class="field" <?= VariableEditor::attrs('home') ?>>
                            <label class="label" for="f-home-title">Homepage title</label>
                            <input class="input" type="text" id="f-home-title" name="seo_homepage_title"
                                   value="<?= h($homeTitle !== '' ? $homeTitle : Head::titlePattern('home')) ?>">
                            <p class="form-help">
                                Starts as the Home pattern under Title patterns. Change it
                                and the front page keeps its own; empty it to go back.
                            </p>
                        </div>

                        <div class="field" <?= VariableEditor::attrs('home') ?>>
                            <label class="label" for="f-home-desc">Homepage description</label>
                            <textarea class="textarea" id="f-home-desc" name="seo_homepage_description"
                                      rows="3"><?= h($opt('seo_homepage_description')) ?></textarea>
                            <p class="form-help">
                                The grey text under the heading in search results.
                                Around 120&ndash;155 characters.
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Sharing image', 'sharing-image') ?>
                        <?php $ogImage = $siteImage('seo_og_image'); ?>
                        <div class="field">
                            <span class="label">Default image</span>
                            <?= \Cms\ContentType\FieldRenderer::renderImage('seo_og_image', $ogImage['id']) ?>
                            <p class="form-help">
                                Used when a page or entry has no featured image of its
                                own. An item's own image always wins.
                            </p>
                        </div>
                        <?php if ($ogImage['url'] !== ''): ?>
                        <div class="field">
                            <label class="label" for="f-og-image-url">Or an image elsewhere, by its address</label>
                            <input class="input" type="text" id="f-og-image-url" name="seo_og_image_url"
                                   value="<?= h($ogImage['url']) ?>" spellcheck="false">
                            <p class="form-help">Set before the image came from the library. Pages use it while no image is chosen above; empty the box to stop.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </form>

        <?php elseif ($tab === 'titles'): ?>
            <form method="post" action="<?= h(Url::admin('settings/seo')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_seo_tab" value="titles">
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Title patterns', 'title-patterns') ?>
                        <?php // NO LIST OF TOKENS: each box offers exactly its
                              // own context's variables from "Insert variable"
                              // (`Head::variables()` says which controller supplies
                              // which). What a pill cannot say is how Page
                              // behaves, and how to reach the list without the
                              // button — so that is what the one line says. ?>
                        <p class="form-help form-help--lead">
                            Add a variable with Insert variable, or by typing %; drag one
                            to move it. Page reads &ldquo;Page 2&rdquo; from the second page
                            of a list on, and is left out on the first.
                        </p>

                        <?php // THE SEPARATOR — fourteen symbols as a row of
                              // cells, the chosen one on the accent's wash (the
                              // menu dialog's icon grid, `.mi-icons`, whose shape
                              // this is). A stored value that is not one of them
                              // gets a cell of its own, so the grid never shows a
                              // choice the record does not hold. ?>
                        <?php
                        $separators = Head::separators();
                        $separator  = Head::separator();
                        if (!array_key_exists($separator, $separators)) {
                            $separators[$separator] = 'The separator saved before';
                        }
                        ?>
                        <div class="field">
                            <span class="label" id="f-separator-label">Separator</span>
                            <div class="mi-icons mi-icons--glyphs" role="radiogroup" aria-labelledby="f-separator-label">
                                <?php foreach ($separators as $char => $name): ?>
                                    <label class="mi-icons__cell" title="<?= h($name) ?>">
                                        <input type="radio" name="seo_separator" value="<?= h($char) ?>"
                                               aria-label="<?= h($name) ?>"<?= $char === $separator ? ' checked' : '' ?>>
                                        <span aria-hidden="true"><?= h($char) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="form-help">What the Separator variable prints between the parts of a title.</p>
                        </div>

                        <?php // ONE LIST — `Head::titlePatterns()` — which the
                              // controller loads and saves from too, so this loop
                              // holds no copy of its own. ?>
                        <?php foreach (Head::titlePatterns() as $context => $pattern): ?>
                            <?php $key = 'seo_pattern_' . $context; ?>
                            <div class="field" <?= VariableEditor::attrs($context) ?>>
                                <label class="label" for="f-<?= h($key) ?>"><?= h($pattern['label']) ?></label>
                                <input class="input" type="text" id="f-<?= h($key) ?>"
                                       name="<?= h($key) ?>" value="<?= h($opt($key, $pattern['default'])) ?>"
                                       spellcheck="false">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </form>

        <?php elseif ($tab === 'schema'): ?>
            <form method="post" action="<?= h(Url::admin('settings/seo')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_seo_tab" value="schema">
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Organisation', 'organisation') ?>
                        <p class="form-help">
                            Published as structured data on every page, so a search
                            engine can attribute the site to an organisation.
                        </p>

                        <div class="field">
                            <label class="label" for="f-org-type">Type</label>
                            <?php // EXACTLY THE TWO VALUES saveSeoSchema() ACCEPTS.
                                  // Anything else silently becomes 'Organization'. ?>
                            <select class="select" id="f-org-type" name="seo_org_type">
                                <option value="Organization"<?= $opt('seo_org_type', 'Organization') === 'Organization' ? ' selected' : '' ?>>Organisation</option>
                                <option value="LocalBusiness"<?= $opt('seo_org_type') === 'LocalBusiness' ? ' selected' : '' ?>>Local business</option>
                            </select>
                        </div>

                        <div class="field">
                            <label class="label" for="f-org-name">Name</label>
                            <?php // Empty, the business's name stands in (Business details). ?>
                            <input class="input" type="text" id="f-org-name" name="seo_org_name"
                                   value="<?= h($opt('seo_org_name')) ?>"
                                   placeholder="<?= h(\Cms\Site\Business::get()['name'] ?: \Cms\SEO\Head::globalTokens()['site_name']) ?>">
                        </div>

                        <div class="field">
                            <label class="label" for="f-org-url">Website</label>
                            <input class="input" type="text" id="f-org-url" name="seo_org_url"
                                   value="<?= h($opt('seo_org_url')) ?>" spellcheck="false">
                        </div>

                        <?php $orgLogo = $siteImage('seo_org_logo'); ?>
                        <div class="field">
                            <span class="label">Logo</span>
                            <?= \Cms\ContentType\FieldRenderer::renderImage('seo_org_logo', $orgLogo['id']) ?>
                        </div>
                        <?php if ($orgLogo['url'] !== ''): ?>
                        <div class="field">
                            <label class="label" for="f-org-logo-url">Or a logo elsewhere, by its address</label>
                            <input class="input" type="text" id="f-org-logo-url" name="seo_org_logo_url"
                                   value="<?= h($orgLogo['url']) ?>" spellcheck="false">
                            <p class="form-help">Set before the logo came from the library. It is used while no logo is chosen above; empty the box to stop.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </form>

        <?php elseif ($tab === 'robots'): ?>
            <form method="post" action="<?= h(Url::admin('settings/seo')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_seo_tab" value="robots">
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('robots.txt', 'robots') ?>
                        <div class="field">
                            <label class="label" for="f-robots">Contents</label>
                            <textarea class="textarea textarea--tall input--mono" id="f-robots"
                                      name="seo_robots_txt" spellcheck="false"><?= h($opt('seo_robots_txt')) ?></textarea>
                            <p class="form-help">
                                Served at <code><?= h(rtrim(Url::public(''), '/')) ?>/robots.txt</code>.
                                <code>{site_url}</code> is replaced with the site address.
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </form>

        <?php elseif ($tab === 'google'): ?>
            <?php // GOOGLE'S MEASUREMENT TAGS (`Site\Google`): the IDs Google
                  // gives out and nothing else, so there is no box for code. ?>
            <form method="post" action="<?= h(Url::admin('settings/seo')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_seo_tab" value="google">
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Analytics and Tag Manager', 'google-measurement') ?>
                        <div class="field">
                            <label class="label" for="f-google-ga4">Google Analytics measurement ID</label>
                            <input class="input input--mono" type="text" id="f-google-ga4" name="google_ga4_id"
                                   value="<?= h($opt('google_ga4_id')) ?>" placeholder="G-XXXXXXXXXX" autocomplete="off" spellcheck="false">
                            <p class="form-help">GA4's, from Analytics → Admin → Data streams.</p>
                        </div>
                        <div class="field">
                            <label class="label" for="f-google-gtm">Tag Manager container ID</label>
                            <input class="input input--mono" type="text" id="f-google-gtm" name="google_gtm_id"
                                   value="<?= h($opt('google_gtm_id')) ?>" placeholder="GTM-XXXXXXX" autocomplete="off" spellcheck="false">
                            <p class="form-help">
                                Each prints Google's own code on every public page and never in the admin.
                                Leave them empty if the site's own header already prints its tags.
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Search Console', 'google-verification') ?>
                        <div class="field">
                            <label class="label" for="f-google-verification">Verification code</label>
                            <input class="input input--mono" type="text" id="f-google-verification" name="google_site_verification"
                                   value="<?= h($opt('google_site_verification')) ?>" autocomplete="off" spellcheck="false">
                            <p class="form-help">
                                From the HTML tag method: its code, or the whole tag pasted. It is printed on the home page.
                            </p>
                        </div>
                    </div>

                    <?php // ASKED FIRST (`Site\Google::asks()`): on until the box is
                          // unticked, for every site, those from before it too. ?>
                    <div class="form-card__section">
                        <?= panel_title('Asking visitors', 'google-consent') ?>
                        <div class="field">
                            <label class="check-list__row" for="f-google-consent">
                                <input type="checkbox" id="f-google-consent" name="google_consent" value="1"
                                       <?= $opt('google_consent') === '0' ? '' : 'checked' ?>>
                                <span>Ask visitors before measuring</span>
                            </label>
                            <p class="form-help">
                                Nothing loads from Google until a visitor accepts, as Quebec's Law 25 and the GDPR ask.
                                The answer is kept for a year, and the footer's Cookie settings asks again.
                            </p>
                        </div>
                        <div class="field">
                            <label class="label" for="f-google-consent-words">What the question says</label>
                            <textarea class="textarea" id="f-google-consent-words" name="google_consent_words" rows="2"
                                      maxlength="500" placeholder="<?= h(\Cms\Site\Google::CONSENT_WORDS) ?>"><?= h($opt('google_consent_words')) ?></textarea>
                        </div>
                        <div class="field field--narrow">
                            <label class="label" for="f-google-consent-page">The page it links to</label>
                            <select class="select" id="f-google-consent-page" name="google_consent_page">
                                <option value="">None</option>
                                <?php foreach (\Cms\Models\Page::all() as $_consentPage): ?>
                                    <?php if ((string) $_consentPage['status'] !== 'published') { continue; } ?>
                                    <option value="<?= (int) $_consentPage['id'] ?>"<?= $opt('google_consent_page') === (string) $_consentPage['id'] ? ' selected' : '' ?>><?= h((string) $_consentPage['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Events', 'google-events') ?>
                        <div class="field">
                            <label class="check-list__row" for="f-google-clicks">
                                <input type="checkbox" id="f-google-clicks" name="google_click_events" value="1"
                                       <?= $opt('google_click_events') === '1' ? 'checked' : '' ?>>
                                <span>Send clicks on phone numbers and email addresses</span>
                            </label>
                            <p class="form-help">
                                As <code>phone_call_click</code> and <code>email_click</code>, each with <code>method</code>
                                and <code>link_url</code>. Leave this off if the site's own code already sends them.
                                A form's own event is set on the form, under After submitting.
                            </p>
                        </div>
                        <?php if (\Cms\Site\Google::siteSendsConversions()): ?>
                            <p class="form-help">
                                This site's own pages send its form conversions (<code>site_sends_conversions</code> in config.php):
                                each real submission's is carried to its form's after-sending page, which takes it.
                            </p>
                        <?php endif; ?>
                        <?php if (!\Cms\Site\Google::sends()): ?>
                            <p class="form-help">
                                This copy of the site is not in production (<code>app_env</code> in config.php), so its pages
                                print these tags inside a comment, to compare with the live site, and send nothing.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </form>

        <?php endif; ?>

    </div>

    <?php // The media picker's dialog and behaviour, as on any entry form. ?>
    <script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>

</div>

<?php if ($tab === 'general' || $tab === 'titles'): ?>
    <?php // The pill editor: its template once, reorder.js for the drag's mirror
          // and line, then the editor itself. ?>
    <?= VariableEditor::template() ?>
    <script src="<?= h(Asset::admin('js/reorder.js')) ?>"></script>
    <script src="<?= h(Asset::admin('js/variable-editor.js')) ?>" defer></script>
<?php endif; ?>

