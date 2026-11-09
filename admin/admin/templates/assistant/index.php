<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * THE ASSISTANT.
 *
 * ── IT IS A CHAT, NOT A FORM ────────────────────────────────────────────────
 * A conversation, not an edit-form skeleton — no `.page__header`, no
 * `.panel__grid`, no transcript as a card section and no labelled textarea with
 * a Send button under it.
 *
 * Three parts and one rule:
 *   · a RAIL of conversations, flush and full height, the open one lit
 *   · the CONVERSATION, which is the page rather than a panel on it
 *   · a COMPOSER pinned to the bottom
 * THE RULE: only the transcript scrolls, so the composer is always reachable.
 * That needs `$mainFlush` (layout.php drops `.main`'s padding and its growth)
 * and `min-height: 0` on every ancestor — see admin.css's chat block.
 *
 * ── THE SAFETY MODEL IS THE SCREEN, NOT A NOTE ON IT ────────────────────────
 * It is not negotiable: the model CANNOT WRITE. It emits a change set, a
 * person approves a rendered diff, and PHP applies it. This
 * template is the "person approves a rendered diff" half, so:
 *
 *   NOTHING IS APPLIED BY ARRIVING. A proposal renders as a diff with an Apply
 *   button. There is no auto-apply, no "accept all", and no path from a model
 *   reply to a database write that does not pass through a POST a human made.
 *   EVERY PROPOSAL SHOWS `from` AND `to`. `buildDiff()` resolves the current
 *   value for exactly this reason — an approval screen that shows only the new
 *   value is asking for consent to something it has not disclosed.
 *   UNDO IS OFFERED FROM `apply_results`, which records what the applier DID
 *   rather than what the model asked for. Without that, undo silently no-ops
 *   on anything that created a row.
 *
 * The chat shape is the frame around all of that, never a replacement for it:
 * an ordinary chat client has no proposal step, and this one keeps it.
 *
 * ── PROGRESS IS POLLED, NOT STREAMED ────────────────────────────────────────
 * `mod_proxy_fcgi` buffers the whole FastCGI response, so a streamed reply
 * arrives all at once at the end — this runs on shared hosting and that decided
 * it. `assistant.js` therefore POSTs to `/send` and polls
 * `/admin/assistant/progress` for the turn's events.
 *
 * THE FORM ITSELF POINTS AT `/send-blocking`, NOT `/send`. `send()` answers with
 * JSON, so with JavaScript off a plain submit would paint a raw payload into the
 * window. `sendBlocking()` runs the identical turn silently and redirects, and
 * its own docblock names it the no-JavaScript path. The script swaps the target
 * when it loads.
 *
 * ── THE BUDGET IS SHOWN BEFORE IT IS SPENT ──────────────────────────────────
 * `Budget::spentThisMonth()` and `Budget::limit()` are already resolved by the
 * controller. A ceiling you only discover by hitting it is not a ceiling anyone
 * can plan around. It sits in the rail's foot: a number to watch, not a warning
 * to read.
 *
 * @var list<array<string, mixed>>  $sessions
 * @var array<string, mixed>|null   $session
 * @var list<array<string, mixed>>  $messages
 * @var array<int, array{summary: string, rows: list<array<string,mixed>>, errors: mixed, status: string}> $diffs
 * @var bool                        $usingStub
 * @var string                      $model
 * @var float                       $spent
 * @var float|null                  $limit   null when unlimited
 * @var string|null                 $error
 * @var string|null                 $outcome
 * @var list<string>                $outcomeErrors
 * @var list<string>                $outcomeKept
 * @var array<string, mixed>        $user
 */

$pageTitle = 'Assistant';
$mainFlush = true;   // see layout.php — this screen owns the area edge to edge
$sessionId = $session !== null ? (int) $session['id'] : 0;

// THE FORM POSTS TO THE BLOCKING ROUTE, WHICH IS THE NO-JAVASCRIPT PATH.
// `send()` returns JSON — with JS off, submitting to it would paint a raw
// payload into the browser window. `sendBlocking()` runs the same turn silently
// and ends in a redirect, and its own docblock names it as the progressive-
// enhancement path. `assistant.js` upgrades this form to the polled route when
// it loads; with JS off the form is still a form and still works.
$sendAction = $sessionId > 0
    ? Url::admin('assistant/' . $sessionId . '/send-blocking')
    : Url::admin('assistant/send-blocking');
$pollAction = $sessionId > 0
    ? Url::admin('assistant/' . $sessionId . '/send')
    : Url::admin('assistant/send');

ob_start();
?>
<div class="chat">

    <?php // ── THE CONVERSATION RAIL ───────────────────────────────────────
          // Flush and full height, as a chat client has it. Not a card in the
          // right-hand column, which would put the history of your work in the
          // position this admin uses for settings ABOUT the thing on screen. ?>
    <aside class="chat__rail">
        <div class="chat__rail-head">
            <form method="post" action="<?= h(Url::admin('assistant/new')) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--sm">
                    <?= icon('plus', 'icon icon--sm') ?>
                    New conversation
                </button>
            </form>
        </div>

        <div class="chat__rail-list">
            <?php if ($sessions === []): ?>
                <p class="chat__rail-empty">No conversations yet.</p>
            <?php else: ?>
                <?php foreach ($sessions as $s): ?>
                    <?php $sid = (int) $s['id']; ?>
                    <div class="convo<?= $sid === $sessionId ? ' is-active' : '' ?>">
                        <a class="convo__link" href="<?= h(Url::admin('assistant/' . $sid)) ?>">
                            <span class="convo__title"><?= h((string) $s['title']) ?></span>
                            <span class="convo__meta"><?= h(Dates::short((string) $s['updated_at'])) ?></span>
                        </a>
                        <div class="convo__actions">
                            <form method="post"
                                  action="<?= h(Url::admin('assistant/' . $sid . '/delete')) ?>"
                                  data-confirm="Delete this conversation?"
                                  data-confirm-detail="Changes you already applied stay applied. Only the conversation is removed."
                                  data-confirm-verb="Delete">
                                <?= Csrf::field() ?>
                                <button type="submit" class="icon-btn"
                                        data-tip="Delete conversation" aria-label="Delete conversation">
                                    <?= icon('trash-can', 'icon icon--sm') ?>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php // WHICH BRAIN IS ANSWERING, AND WHAT IT HAS COST. A dot rather
              // than a sentence — the stub case still gets the full banner
              // above the transcript, because "canned replies" is not
              // something a 7px dot can say. ?>
        <div class="chat__rail-foot">
            <span class="chat__dot<?= $usingStub ? '' : ' chat__dot--live' ?>"
                  aria-hidden="true"></span>
            <span class="chat__rail-label"><?= h($usingStub ? 'Stub' : $model) ?></span>
            <span class="chat__rail-spend"
                  title="Spent this month<?= $limit === null ? '' : ' of $' . h(number_format((float) $limit, 2)) ?>">
                $<?= h(number_format((float) $spent, 2)) ?><?php
                    if ($limit !== null): ?> / $<?= h(number_format((float) $limit, 2)) ?><?php endif; ?>
            </span>
        </div>
    </aside>

    <?php // ── THE CONVERSATION ────────────────────────────────────────────── ?>
    <section class="chat__main">

        <?php // `--empty` centres the intro. Decided HERE, from whether there
              // are messages, rather than in CSS from sibling counts. ?>
        <div class="chat__scroll<?= ($session === null || $messages === []) ? ' chat__scroll--empty' : '' ?>"
             data-chat-scroll>

            <?php if ($usingStub): ?>
                <?php // With no API key configured the StubTransport replies
                      // with canned text — which looks exactly like a working
                      // assistant giving poor answers. Say so, or the first
                      // real question wastes somebody's afternoon. ?>
                <div class="alert">
                    <?= icon('circle-exclamation', 'alert__icon icon', '', 18) ?>
                    <div class="alert__body">
                        <p class="alert__title">Running on the stub transport</p>
                        <p class="alert__text">
                            No API key is configured, so replies are canned and no request
                            leaves this server. Nothing here will reflect your site.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error !== null): ?>
                <div class="alert alert--danger">
                    <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                    <div class="alert__body">
                        <p class="alert__title"><?= h((string) $error) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($outcome !== null): ?>
                <div class="flashes">
                    <div class="flash flash--success" data-assistant-outcome><?= h((string) $outcome) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($outcomeErrors !== []): ?>
                <div class="alert alert--danger" data-assistant-refused>
                    <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                    <div class="alert__body">
                        <p class="alert__title">Nothing was changed</p>
                        <ul class="alert__text">
                            <?php foreach ($outcomeErrors as $e): ?>
                                <li><?= h($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
            <?php // WHAT AN UNDO LEFT AS IT IS, because the shop had moved on:
                  // a count since changed, a code since used. Not a failure. ?>
            <?php if ($outcomeKept !== []): ?>
                <div class="alert" data-assistant-kept>
                    <?= icon('circle-info', 'alert__icon icon', '', 18) ?>
                    <div class="alert__body">
                        <p class="alert__title">Some of it was left as it is</p>
                        <ul class="alert__text">
                            <?php foreach ($outcomeKept as $k): ?>
                                <li><?= h($k) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($session === null || $messages === []): ?>
                <?php // THE EMPTY STATE TEACHES THE REGISTER. An owner who has
                      // never used this needs to see that it takes "change the
                      // opening hours", not a query language. The examples are
                      // buttons that fill the composer — a suggestion you
                      // cannot act on is decoration. ?>
                <div class="chat__intro">
                    <p class="chat__intro-title">What would you like to change?</p>
                    <p class="chat__intro-lede">
                        Ask in plain language. Nothing on your site is altered until you
                        have seen the exact change and approved it.
                    </p>
                    <div class="chat__examples">
                        <?php foreach ([
                            'Change the opening hours on the Contact page to 9 to 5',
                            'Add a news item about the AGM on 14 October',
                            'What do the Events entries have on them?',
                        ] as $example): ?>
                            <button type="button" class="chat__example" data-chat-example>
                                <?= h($example) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $message): ?>
                    <?php
                    $mid  = (int) $message['id'];
                    $role = (string) $message['role'];
                    $diff = $diffs[$mid] ?? null;
                    $who  = $role === 'user' ? (string) $user['username'] : 'Assistant';
                    ?>
                    <article class="msg msg--<?= h($role) ?>">
                        <?php // WHO SPOKE. An initial for the person — no picture,
                              // because there is none to fetch and a fetched one
                              // would be a third-party request — and a GLYPH for
                              // the assistant.
                              //
                              // Not an initial for both: with a user called
                              // `admin` beside "Assistant", every avatar in the
                              // transcript would read "A" and only the accent
                              // tint would tell them apart, which is a colour
                              // doing a job that colour alone must never do. ?>
                        <div class="msg__avatar" aria-hidden="true"><?php
                            if ($role === 'user') {
                                echo h(mb_strtoupper(mb_substr($who, 0, 1)));
                            } else {
                                echo icon('comment', 'icon icon--sm');
                            }
                        ?></div>

                        <div class="msg__body">
                            <div class="msg__text"><?= nl2br(h((string) $message['content'])) ?></div>

                            <?php if ($diff !== null): ?>
                                <?php
                                $status = (string) $diff['status'];
                                $rows   = (array) $diff['rows'];
                                ?>
                                <div class="proposal">
                                    <div class="proposal__head">
                                        <strong><?= h((string) ($diff['summary'] ?: 'Proposed changes')) ?></strong>
                                        <span class="proposal__count">
                                            <?= count($rows) ?> change<?= count($rows) === 1 ? '' : 's' ?>
                                        </span>
                                    </div>

                                    <?php if (!empty($diff['errors'])): ?>
                                        <div class="alert alert--danger">
                                            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                                            <div class="alert__body">
                                                <p class="alert__title">This change set was rejected</p>
                                                <ul class="alert__text">
                                                    <?php foreach ((array) $diff['errors'] as $e): ?>
                                                        <li><?= h((string) $e) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="kv-list">
                                        <?php foreach ($rows as $row): ?>
                                            <div class="kv-list__row">
                                                <span class="kv-list__key">
                                                    <?= h((string) ($row['entry'] ?? '')) ?>
                                                    <?php if (($row['type'] ?? null) !== null): ?>
                                                        <span class="kv-list__val--muted"><?= h((string) $row['type']) ?></span>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="kv-list__val">
                                                    <?php if (($row['label'] ?? null) !== null): ?>
                                                        <span class="proposal__field"><?= h((string) $row['label']) ?></span>
                                                    <?php endif; ?>
                                                    <?php // BOTH SIDES, ALWAYS. Consent to a change
                                                          // you cannot see the current value of is
                                                          // not consent. ?>
                                                    <?php if (($row['from'] ?? null) !== null && (string) $row['from'] !== ''): ?>
                                                        <del class="proposal__from"><?= h((string) $row['from']) ?></del>
                                                    <?php endif; ?>
                                                    <?php if (($row['to'] ?? null) !== null): ?>
                                                        <ins class="proposal__to"><?= h((string) $row['to']) ?></ins>
                                                    <?php endif; ?>
                                                    <?php foreach ((array) ($row['details'] ?? []) as $k => $v): ?>
                                                        <span class="proposal__detail"><?= h((string) $k) ?>: <?= h((string) $v) ?></span>
                                                    <?php endforeach; ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="proposal__actions">
                                        <?php if ($status === 'pending' && empty($diff['errors'])): ?>
                                            <form method="post"
                                                  action="<?= h(Url::admin('assistant/message/' . $mid . '/apply')) ?>"
                                                  data-confirm="Apply these <?= count($rows) ?> change<?= count($rows) === 1 ? '' : 's' ?>?"
                                                  data-confirm-detail="Every record touched is snapshotted first, so this can be undone.<?= array_filter($rows, static fn (array $r): bool => isset($r['details']['Emails'])) !== [] ? ' An email to a customer, once sent, stays sent.' : '' ?>"
                                                  data-confirm-verb="Apply">
                                                <?= Csrf::field() ?>
                                                <button type="submit" class="btn btn--primary">Apply</button>
                                            </form>
                                            <form method="post"
                                                  action="<?= h(Url::admin('assistant/message/' . $mid . '/discard')) ?>">
                                                <?= Csrf::field() ?>
                                                <button type="submit" class="link link--xs">Discard</button>
                                            </form>
                                        <?php elseif ($status === 'applied'): ?>
                                            <span class="proposal__state">Applied</span>
                                            <?php // UNDO WORKS FROM apply_results — what the applier
                                                  // DID, not what was asked for. ?>
                                            <form method="post"
                                                  action="<?= h(Url::admin('assistant/message/' . $mid . '/undo')) ?>"
                                                  data-confirm="Undo these changes?"
                                                  data-confirm-detail="Every record is restored from the snapshot taken when it was applied.<?= array_filter($rows, static fn (array $r): bool => in_array((string) ($r['op'] ?? ''), \Cms\Assistant\ShopOps::OPS, true)) !== [] ? ' Anything in the shop that has moved on since is left as it is, and you are told which.' : '' ?>"
                                                  data-confirm-verb="Undo">
                                                <?= Csrf::field() ?>
                                                <button type="submit" class="link link--xs">Undo</button>
                                            </form>
                                        <?php elseif ($status === 'discarded'): ?>
                                            <span class="proposal__state">Discarded</span>
                                        <?php elseif ($status === 'undone'): ?>
                                            <span class="proposal__state">Undone</span>
                                        <?php elseif ($status === 'superseded'): ?>
                                            <span class="proposal__state">Replaced by a later proposal</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>

        <?php // ── THE COMPOSER ────────────────────────────────────────────
              // Pinned. The wrapper is the control — the textarea has no border
              // of its own — which is what lets Send sit inside it. ?>
        <div class="chat__composer">
            <form method="post" action="<?= h($sendAction) ?>"
                  data-assistant-send data-poll-action="<?= h($pollAction) ?>"
                  data-progress-url="<?= h(Url::admin('assistant/progress')) ?>">
                <?= Csrf::field() ?>
                <?php // NO VISIBLE <label>. This admin has no visually-hidden
                      // utility and inventing one for a single control would be
                      // a parallel vocabulary; the established pattern here is
                      // an `aria-label` on the control itself, the same way
                      // every icon-only button in the tree is named. ?>
                <div class="composer">
                    <textarea class="composer__input" id="f-ask" name="message" rows="1"
                              aria-label="Ask for a change"
                              placeholder="Ask for a change&hellip;"
                              data-chat-input></textarea>
                    <button type="submit" class="btn btn--primary btn--sm composer__send">Send</button>
                </div>
                <p class="chat__hint" data-assistant-status hidden>Thinking&hellip;</p>
            </form>
        </div>

    </section>
</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/assistant.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/chat.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
