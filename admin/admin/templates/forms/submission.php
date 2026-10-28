<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * ONE SUBMISSION.
 *
 * ── IT IS LABELLED WITH THE FORM'S OWN FIELD LABELS ─────────────────────────
 * `data` is stored keyed by field NAME (`your_email`), which is a machine key.
 * The form's definition holds the LABEL the sender actually read ("Your
 * email"), so this screen joins the two and shows what the person filling it in
 * saw. Printing raw keys would make the reader translate.
 *
 * A key with no matching field is still shown, under its raw name. That happens
 * when a field is renamed or removed after a submission arrives — and dropping
 * it would silently hide something a real person typed. It is labelled as no
 * longer on the form so the reader knows why it looks different.
 *
 * ── EVERYTHING IS ESCAPED, INCLUDING THE KEYS ───────────────────────────────
 * This is the one screen in the admin that renders text typed by an anonymous
 * member of the public. `nl2br(h())` and not the reverse: escaping AFTER
 * converting newlines would turn the generated `<br>` into visible text, and
 * escaping only the value while echoing the key raw would leave an injection
 * point on the field name.
 *
 * ── SPAM SAYS WHY, AND CAN BE PUT BACK ──────────────────────────────────────
 * A submission a form turned away opens here too (`Forms\Guard`), with the
 * reason in the Received panel and "Not spam" in the header, which puts it
 * among the received. A received one offers "Mark as spam" instead. The panel
 * also says where it came from — the page, the browser — and whether the
 * notification email went.
 *
 * @var array<string, mixed> $submission  id, form_id, data, status, spam_reason, source_page, is_read, ip_address, user_agent, notified, submitted_at
 * @var array<string, mixed> $form
 * @var array<string, mixed> $user
 */

$sid       = (int) $submission['id'];
$formId    = (int) $form['id'];
$isSpam    = ($submission['status'] ?? 'received') === 'spam';
$pageTitle = 'Submission #' . $sid;
if ($isSpam) {
    $sidebarHere = '/admin/forms/spam';
}

// BOTH MODELS DECODE FOR YOU. `FormSubmission::find()` json_decodes `data` and
// `Form::find()` decodes `fields`, so both arrive as ARRAYS. Decoding again
// casts an array to string — a PHP warning per render, on a page that still
// returns HTTP 200 and looks fine: only the error log shows it.
//
// The `is_string` branch stays, because these two keys arrive as raw JSON from
// any caller that queried the table directly rather than through the model.
$data = $submission['data'] ?? [];
if (is_string($data)) {
    $data = json_decode($data, true);
}
$data = is_array($data) ? $data : [];

$definition = $form['fields'] ?? [];
if (is_string($definition)) {
    $definition = json_decode($definition, true);
}
$definition = is_array($definition) ? $definition : [];

// EXPANDED, like the renderer and the validator. Without this every answer
// that came from an embedded form is a key this screen has never heard of, so
// it prints the storage name and labels it "no longer on the form" — about a
// question that is on the form, was answered five minutes ago, and is right
// there. The definition has three readers and all three have to agree.
$definition = \Cms\Forms\Embed::expand($definition);

/** name => label, from the form's own definition. */
$labels = [];
/**
 * name => type, for the two answers that are not text.
 *
 * A File upload and a Signature store an ATTACHMENT ID, because that is the
 * short scalar everything downstream expects — required, logic, the notification
 * email. Printed raw it is a bare number in a table: a figure with no unit and
 * no way to act on it. Here it becomes the file, by name, that you can open.
 */
$types = [];
foreach ($definition as $field) {
    if (is_array($field) && ($field['name'] ?? '') !== '') {
        $labels[(string) $field['name']] = (string) ($field['label'] ?? $field['name']);
        $types[(string) $field['name']]  = (string) ($field['type'] ?? 'text');
    }
}

ob_start();
?>
<div class="page">

    <?php if ($isSpam): ?>
        <a class="page__back" href="<?= h(Url::admin('forms/spam')) ?>">
            <?= icon('angle-left', 'icon icon--sm') ?>Spam
        </a>
    <?php else: ?>
        <a class="page__back" href="<?= h(Url::admin('forms/' . $formId . '/submissions')) ?>">
            <?= icon('angle-left', 'icon icon--sm') ?><?= h((string) $form['title']) ?>
        </a>
    <?php endif; ?>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Submission</h1>
        </div>

        <div class="page__header-actions">
            <?php if ($isSpam): ?>
                <form method="post" action="<?= h(Url::admin('forms/submissions/' . $sid . '/not-spam')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn--primary">Not spam</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= h(Url::admin('forms/submissions/' . $sid . '/spam')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn">Mark as spam</button>
                </form>
            <?php endif; ?>
            <form method="post"
                  action="<?= h(Url::admin('forms/submissions/' . $sid . '/delete')) ?>"
                  data-confirm="Delete this submission?"
                  data-confirm-detail="What the sender typed is removed permanently."
                  data-confirm-verb="Delete submission">
                <?= Csrf::field() ?>
                <button type="submit" class="btn">Delete</button>
            </form>
        </div>
    </div>

    <div class="form-main">
        <div class="form-card">

            <div class="form-card__section">
                <?= panel_title('Answers', 'answers') ?>

                <?php if ($data === []): ?>
                    <p class="form-help">This submission arrived with no data.</p>
                <?php else: ?>
                    <div class="kv-list">
                        <?php foreach ($data as $key => $value): ?>
                            <?php
                            $known = array_key_exists((string) $key, $labels);
                            $label = $known ? $labels[(string) $key] : (string) $key;
                            if (is_array($value)) {
                                $value = implode(', ', array_map('strval', $value));
                            }
                            ?>
                            <?php
                            // AN UPLOAD IS A FILE, NOT A NUMBER. The stored
                            // value is the attachment id; this is where it
                            // becomes something you can open. A row whose
                            // attachment has since been deleted says so rather
                            // than linking to a 404 — the file is gone and the
                            // submission is still a fact.
                            $kind = $types[(string) $key] ?? '';
                            $file = null;
                            if (in_array($kind, ['file', 'signature'], true)
                                && ctype_digit((string) $value)) {
                                $file = \Cms\Models\Media::find((int) $value);
                            }
                            ?>
                            <div class="kv-list__row">
                                <span class="kv-list__key">
                                    <?= h($label) ?><?php if (!$known): ?> <span class="kv-list__val--muted">(no longer on the form)</span><?php endif; ?>
                                </span>
                                <span class="kv-list__val">
                                    <?php if ($file !== null): ?>
                                        <a href="<?= h(\Cms\Models\Media::getUrl((int) $value)) ?>">
                                            <?= icon('paperclip', 'icon icon--sm') ?><?php
                                            ?><?= h((string) ($file['original_name'] ?: $file['filename'])) ?>
                                        </a>
                                    <?php elseif (in_array($kind, ['file', 'signature'], true)): ?>
                                        <span class="kv-list__val--muted">The file is no longer stored.</span>
                                    <?php else: ?>
                                        <?= nl2br(h((string) $value)) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-card__section">
                <?= panel_title('Received', 'received') ?>
                <div class="kv-list">
                    <div class="kv-list__row">
                        <span class="kv-list__key">When</span>
                        <span class="kv-list__val"><?= h(Dates::stamp((string) $submission['submitted_at'])) ?></span>
                    </div>
                    <?php // THE IP IS SHOWN ONLY IF ONE WAS RECORDED. The column
                          // is nullable and `FormSubmission::create()` takes it as
                          // an optional argument, so an empty row here would be a
                          // label with nothing after it. ?>
                    <?php if (($submission['ip_address'] ?? '') !== ''): ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key">From</span>
                            <span class="kv-list__val"><?= h((string) $submission['ip_address']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($isSpam): ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Turned away</span>
                            <span class="kv-list__val"><?= h((string) ($submission['spam_reason'] ?? '')) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (($submission['source_page'] ?? '') !== ''): ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Page</span>
                            <span class="kv-list__val"><?= h((string) $submission['source_page']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (($submission['user_agent'] ?? '') !== ''): ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Browser</span>
                            <span class="kv-list__val"><?= h((string) $submission['user_agent']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php // WHETHER THE EMAIL WENT — NULL when none was due (the form
                          // has no recipients, or it is spam). ?>
                    <?php if (!$isSpam && ($submission['notified'] ?? null) !== null): ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Email</span>
                            <span class="kv-list__val"><?= (int) $submission['notified'] === 1 ? 'Sent' : 'Could not be sent' ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
