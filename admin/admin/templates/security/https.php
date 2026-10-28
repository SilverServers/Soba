<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Security\Hsts;

/**
 * SECURITY → HTTPS — HTTP Strict Transport Security as a setting
 * (`Security\Hsts` has why it is off until an owner decides). A FRAGMENT:
 * `SecurityController` owns `ob_start()` and `layout.php`.
 *
 * @var list<string>         $errors
 * @var bool                 $saved
 * @var array<string, mixed> $form    on, max_age, subdomains, preload — as stored, or as chosen
 * @var bool                 $https   is this site's own address HTTPS
 * @var string|null          $header  what is being sent now, or null
 */
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">HTTPS</h1>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes"><div class="flash flash--success">Settings saved.</div></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title"><?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?></p>
                <ul class="alert__text">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-main">
        <form class="form-card" method="post" action="<?= h(Url::admin('security/https')) ?>">
            <?= Csrf::field() ?>
            <div class="form-card__section">
                <?= panel_title('HTTPS only', 'hsts') ?>
                <div class="field">
                    <label class="check-list__row" for="f-hsts-on">
                        <input type="checkbox" id="f-hsts-on" name="enabled" value="1" <?= $form['on'] ? 'checked' : '' ?>>
                        <span>Tell browsers to use only HTTPS on this site</span>
                    </label>
                    <p class="form-help">A browser that has seen this refuses the site over plain HTTP for as long as you
                        choose below — and nothing you change here afterwards shortens that for the browsers that already
                        have it. If the site's certificate lapses, visitors meet a wall, not a warning.
                        <?php if (!$https): ?>This site's address is not HTTPS, so there is nothing to switch on.<?php endif; ?></p>
                </div>
                <div class="field">
                    <label class="label" for="f-hsts-age">Browsers remember it for</label>
                    <select class="select" id="f-hsts-age" name="max_age">
                        <?php foreach (Hsts::AGES as $seconds => $label): ?>
                            <option value="<?= $seconds ?>" <?= (int) $form['max_age'] === $seconds ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-help">Start with a day. Lengthen it once the site has been on HTTPS without trouble.</p>
                </div>
                <div class="field">
                    <label class="check-list__row" for="f-hsts-sub">
                        <input type="checkbox" id="f-hsts-sub" name="subdomains" value="1" <?= $form['subdomains'] ? 'checked' : '' ?>>
                        <span>Every subdomain too</span>
                    </label>
                    <p class="form-help">Only if every subdomain of this site's domain has a working certificate.</p>
                </div>
                <div class="field">
                    <label class="check-list__row" for="f-hsts-preload">
                        <input type="checkbox" id="f-hsts-preload" name="preload" value="1" <?= $form['preload'] ? 'checked' : '' ?>>
                        <span>Ask to be preloaded</span>
                    </label>
                    <p class="form-help">Lets the site be submitted to the list browsers ship with — a year or more, with every
                        subdomain. Leaving that list takes months.</p>
                </div>
                <div class="field">
                    <span class="label">Sent now</span>
                    <p class="form-help"><?php if ($header !== null): ?><code>Strict-Transport-Security: <?= h($header) ?></code><?php else: ?>Nothing.<?php endif; ?></p>
                </div>
            </div>
            <div class="form-card__section form-actions">
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary">Save changes</button>
            </div>
        </form>
    </div>

</div>
