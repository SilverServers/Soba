<?php

declare(strict_types=1);

use Cms\Commerce\Webhooks;
use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SETTINGS → API.
 *
 * The keys another program reaches the shop with, each named for what uses
 * it, with a Revoke; the webhooks, each with what it is told and its last
 * result; a key or a webhook's secret just made, shown in full this once; and
 * how a program uses them.
 *
 * @var list<array<string, mixed>>               $keys
 * @var list<array<string, mixed>>               $hooks
 * @var string|null                              $made
 * @var array{url: string, secret: string}|null  $hookMade
 * @var string|null                              $problem
 */

$base = Url::page('api/v1');

ob_start();
?>
<div class="page">
    <a class="page__back" href="<?= h(Url::admin('settings')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Settings
    </a>
    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">API</h1>
            <p class="page__subtitle">For bookkeeping, a shipping service or a till: what reads the shop's orders and stock, and what it tells when an order comes in.</p>
        </div>
    </div>

    <?php if ($made !== null): ?>
        <?php // THE KEY, IN FULL, THIS ONCE. Only its hash is kept. ?>
        <div class="alert alert--info" data-api-key-made>
            <?= icon('circle-info', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">Copy the new key now: it is not shown again</p>
                <input class="input" type="text" readonly value="<?= h($made) ?>" aria-label="The new key" data-api-key>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($hookMade !== null): ?>
        <?php // THE WEBHOOK'S SECRET, THIS ONCE, as a key is (`Webhooks::add()`). ?>
        <div class="alert alert--info" data-webhook-made>
            <?= icon('circle-info', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">Copy the webhook's secret now: it is not shown again</p>
                <p class="alert__text"><?= h($hookMade['url']) ?> checks each message it is sent with it.</p>
                <input class="input" type="text" readonly value="<?= h($hookMade['secret']) ?>" aria-label="The webhook's secret" data-webhook-secret>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($problem !== null): ?>
        <div class="alert alert--danger" data-api-problem>
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body"><p class="alert__text"><?= h($problem) ?></p></div>
        </div>
    <?php endif; ?>

    <div class="form-main">
        <div class="form-card">
            <?php // `--bleed`: each list is the rest of its section, and the
                  // form that adds to it is the next section. A form after a
                  // bled list in the same section rides up over its last row. ?>
            <div class="form-card__section" data-api-keys>
                <?= panel_title('Keys', 'api') ?>
                <?php if ($keys === []): ?>
                    <p class="form-help">No program has a key yet.</p>
                <?php else: ?>
                    <div class="list list--bleed entry-list api-list">
                        <?php foreach ($keys as $k): ?>
                            <div class="list__row" data-api-key-row="<?= (int) $k['id'] ?>">
                                <div class="entry-list__main">
                                    <span class="list__row-title"><?= h((string) $k['name']) ?></span>
                                    <?php $sub = '…' . (string) $k['hint'] . ' · ' . (!empty($k['can_write']) ? 'reads and writes' : 'reads only')
                                        . ' · made by ' . (string) $k['created_by'] . ' on ' . Dates::short((string) $k['created_at']); ?>
                                    <div class="list__row-sub" title="<?= h($sub) ?>"><?= h($sub) ?></div>
                                </div>
                                <div class="list__row-meta">
                                    <?php if ($k['last_used_at'] !== null): ?>
                                        <span title="<?= h(Dates::stamp((string) $k['last_used_at'])) ?>">used <?= h(Dates::short((string) $k['last_used_at'])) ?></span>
                                    <?php else: ?>
                                        <span>not used yet</span>
                                    <?php endif; ?>
                                </div>
                                <div class="list__row-actions">
                                    <form method="post" action="<?= h(Url::admin('settings/api/keys/' . (int) $k['id'] . '/revoke')) ?>"
                                          data-confirm="Revoke the key for <?= h((string) $k['name']) ?>?"
                                          data-confirm-detail="Whatever uses it is refused from now. This cannot be undone: a new key is made instead."
                                          data-confirm-verb="Revoke">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="btn btn--sm">Revoke</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= h(Url::admin('settings/api/keys')) ?>" class="form-card__section" data-api-make>
                <?= Csrf::field() ?>
                <div class="field">
                    <label class="label" for="api-key-name">Make a key for</label>
                    <input class="input" type="text" id="api-key-name" name="name" maxlength="120" placeholder="The bookkeeping, the till at markets" required>
                </div>
                <div class="field">
                    <label class="check-list__row" for="api-key-write">
                        <input type="checkbox" id="api-key-write" name="can_write" value="1">
                        <span>It may also write: set counts and move orders</span>
                    </label>
                </div>
                <div class="form-actions form-actions--bare">
                    <button type="submit" class="btn">Make the key</button>
                </div>
            </form>

            <div class="form-card__section" data-api-webhooks>
                <?= panel_title('Webhooks', 'webhooks') ?>
                <?php if ($hooks === []): ?>
                    <p class="form-help">No address is told anything yet.</p>
                <?php else: ?>
                    <div class="list list--bleed entry-list api-list">
                        <?php foreach ($hooks as $hook): ?>
                            <?php $told = implode(', ', array_map(static fn (string $e): string => Webhooks::EVENTS[$e] ?? $e,
                                array_values(array_filter(explode(',', (string) $hook['events']))))); ?>
                            <div class="list__row" data-webhook-row="<?= (int) $hook['id'] ?>">
                                <div class="entry-list__main">
                                    <span class="list__row-title" title="<?= h((string) $hook['url']) ?>"><?= h((string) $hook['url']) ?></span>
                                    <div class="list__row-sub" title="<?= h($told) ?>"><?= h($told) ?></div>
                                </div>
                                <?php // ITS LAST RESULT, the time it was sent in its title. ?>
                                <div class="list__row-meta">
                                    <?php if ((string) $hook['last_status'] !== ''): ?>
                                        <span class="entry-list__author" title="Last sent <?= h(Dates::stamp((string) $hook['last_at'])) ?>: <?= h((string) $hook['last_status']) ?>"><?= h((string) $hook['last_status']) ?></span>
                                    <?php else: ?>
                                        <span>nothing sent yet</span>
                                    <?php endif; ?>
                                </div>
                                <div class="list__row-actions">
                                    <form method="post" action="<?= h(Url::admin('settings/api/webhooks/' . (int) $hook['id'] . '/remove')) ?>"
                                          data-confirm="Stop telling <?= h((string) $hook['url']) ?>?"
                                          data-confirm-detail="It is sent nothing from now. Added again, it has a new secret."
                                          data-confirm-verb="Remove">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="btn btn--sm">Remove</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= h(Url::admin('settings/api/webhooks')) ?>" class="form-card__section" data-webhook-add>
                <?= Csrf::field() ?>
                <div class="field">
                    <label class="label" for="webhook-url">Tell this address</label>
                    <input class="input" type="url" id="webhook-url" name="url" maxlength="500" placeholder="https://" required>
                </div>
                <fieldset class="field">
                    <legend class="label">When</legend>
                    <div class="check-list">
                        <?php foreach (Webhooks::EVENTS as $event => $words): ?>
                            <label class="check-list__row">
                                <input type="checkbox" name="events[]" value="<?= h($event) ?>"<?= $event === 'order.paid' ? ' checked' : '' ?>>
                                <span><?= h($words) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="form-actions form-actions--bare">
                    <button type="submit" class="btn">Add the webhook</button>
                </div>
            </form>

            <div class="form-card__section api-docs">
                <?= panel_title('How a program uses it', 'history') ?>
                <?php // WHAT EACH REQUEST ANSWERS, beside the request. ?>
                <div class="kv-list">
                    <?php foreach ([
                        ['Every product on sale, its sizes and colours inside', 'GET ' . $base . '/products'],
                        ['Orders, newest first', 'GET ' . $base . '/orders?since=2026-11-01T00:00:00Z'],
                        ['One order', 'GET ' . $base . '/orders/{id}'],
                        ['Mark an order sent', 'POST ' . $base . '/orders/{id}/status {"status": "completed", "tracking": {"carrier": "Canada Post", "number": "…"}}'],
                        ['Set a count', 'POST ' . $base . '/stock {"sku": "UH-NAV-M", "count": 12}'],
                    ] as [$what, $request]): ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key"><?= h($what) ?></span>
                            <code class="kv-list__val"><?= h($request) ?></code>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="form-help">Each request sends its key as <code>X-Api-Key: cms_…</code>, or as <code>Authorization: Bearer cms_…</code> where the server passes that header on. A webhook is POSTed the order, as <code>/orders/{id}</code> gives it, with <code>X-CMS-Signature: sha256=</code> the HMAC of the body with its secret.</p>
            </div>
        </div>
    </div>
</div>
<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
