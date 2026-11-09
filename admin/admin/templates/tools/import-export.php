<?php

declare(strict_types=1);

use Cms\ContentType\Registry;
use Cms\Controllers\Api\ExportController;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Ui\Sidebar;

/**
 * TOOLS → IMPORT / EXPORT.
 *
 * ── EXPORT ──────────────────────────────────────────────────────────────────
 * Five export endpoints: `/api/v1/export/all`, `/pages`, `/settings`,
 * `/content-type/{key}`, and `/products/{key}` for a type that sells, as a
 * spreadsheet (`Commerce\ProductSheet`). Each sends `Content-Disposition:
 * attachment`, so a plain link downloads a file — no JavaScript, no form, no
 * button that "prepares" anything.
 *
 * ── THE PER-TYPE EXPORTS COME FROM THE REGISTRY ─────────────────────────────
 * Not from a hardcoded list, and not from `ContentTypeDefinition::all()` —
 * anything answering "what exists in this install?" asks the Registry. A type
 * registered in code but absent from the table would otherwise have no export
 * link while its entries sat in the database.
 *
 * ── THE EXPORT LINKS USE Url::public(), NOT Url::admin() ────────────────────
 * The API is routed at the SITE ROOT (`/api/v1/export/…` in www/index.php), not
 * under the admin slug. `Url::admin('../api/…')` would be wrong twice: it
 * produces a path with a `..` segment in it, and it breaks
 * outright the moment ADMIN_URL_SLUG is changed — which is a supported thing to
 * do. `Url::public()` is the helper that means "from the site root".
 *
 * @var array<string, mixed> $user
 */

$pageTitle    = 'Import / Export';
$contentTypes = Registry::all();
// THE TYPES THAT SELL, whose products also go out and come in as a spreadsheet.
$sellingTypes = array_filter($contentTypes, static fn (array $ct, string $key): bool => \Cms\Commerce\Product::isSellableType($key),
    ARRAY_FILTER_USE_BOTH);

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('tools')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Tools
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Import / Export</h1>
        </div>
    </div>

    <div class="form-main">
        <div class="form-card">

            <div class="form-card__section">
                <?= panel_title('Export', 'export') ?>
                <?php // WHAT THE FILE HOLDS, INCLUDING WHAT IT LEAVES OUT, on a
                      // screen whose whole purpose is a backup. Every export
                      // query is `status != 'trash'`, so the bin is the one
                      // thing that does NOT come with you, and finding that out
                      // from a restore is finding it out too late. ?>
                <p class="form-help form-help--lead">
                    JSON, downloaded as it is stored — drafts and hidden items
                    included, the trash excluded.
                </p>

                <?php // `.list`, NOT `.kv-list`. A kv-list is a fact list; these
                      // are rows that DO something, and as one it would put the
                      // same word — "Download" — four times in a column with
                      // nothing but the label to tell them apart. A row shows
                      // the facts it already has, and the fact you want here is
                      // how much is in the file.
                      //
                      // Each row carries the mark of the thing it exports: the
                      // content type's OWN registered glyph, the same one the
                      // sidebar draws, so the row and its destination cannot
                      // wear different marks. ?>
                <?php // `--bleed` because this list IS the whole of its section.
                      // Inset, it would sit on the card's own `--surface` wearing
                      // the same `--surface`, separated by a 1px hairline and a
                      // band of card showing all round it — the "second border
                      // drawn a few pixels in from the first" that
                      // `.list--bleed`'s note in core.css §5 exists to prevent.
                      // Reaching the edges removes the doubled border and the
                      // tone collision at once. ?>
                <div class="list list--bleed export-list">
                    <?php
                    // ONE SOURCE FOR THE NUMBERS. ExportController::counts()
                    // counts what the export queries select, beside those
                    // queries — a COUNT(*) written here would be a second rule
                    // that drifts the first time a WHERE clause moves.
                    $counts = ExportController::counts();
                    $rows   = [
                        [
                            'label' => 'Everything',
                            'sub'   => 'Pages, settings and every content type in one file',
                            'icon'  => 'database',
                            'count' => null,   // a sum of the rows below it, so no figure of its own
                            'href'  => Url::public('api/v1/export/all'),
                        ],
                        [
                            'label' => 'Pages',
                            'sub'   => null,
                            'icon'  => Sidebar::iconFor('pages'),
                            'count' => $counts['pages'],
                            'href'  => Url::public('api/v1/export/pages'),
                        ],
                        [
                            'label' => 'Settings',
                            'sub'   => null,
                            'icon'  => Sidebar::iconFor('settings'),
                            'count' => $counts['settings'],
                            'href'  => Url::public('api/v1/export/settings'),
                        ],
                    ];
                    foreach ($contentTypes as $key => $ct) {
                        $rows[] = [
                            'label' => (string) ($ct['label'] ?? $key),
                            'sub'   => null,
                            'icon'  => Sidebar::iconFor((string) ($ct['menu_icon'] ?? '')),
                            'count' => $counts['types'][(string) $key] ?? 0,
                            'href'  => Url::public('api/v1/export/content-type/' . rawurlencode((string) $key)),
                        ];
                        // AND AS A SPREADSHEET, a type that sells: a row for each
                        // product and each option, the file the import reads back.
                        if (isset($sellingTypes[$key])) {
                            $rows[] = [
                                'label' => (string) ($ct['label'] ?? $key) . ' as a spreadsheet',
                                'sub'   => 'CSV: a row for each product and each of its options',
                                'icon'  => 'file-excel',
                                'count' => null,
                                'href'  => Url::public('api/v1/export/products/' . rawurlencode((string) $key)),
                            ];
                        }
                    }
                    ?>
                    <?php foreach ($rows as $row): ?>
                        <div class="list__row">
                            <div class="export-list__main">
                                <?= icon($row['icon'], 'export-list__glyph icon icon--lg') ?>
                                <div class="export-list__names">
                                    <span class="list__row-title"><?= h($row['label']) ?></span>
                                    <?php if ($row['sub'] !== null): ?>
                                        <div class="list__row-sub"><?= h($row['sub']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php // The record count, right-aligned on its own
                                  // rail so the figures line up down the column —
                                  // the same treatment the Content Types and
                                  // Taxonomies lists give theirs. "Everything"
                                  // has none: it is the sum of every row under
                                  // it, and printing a total beside its parts
                                  // says the same fact twice. ?>
                            <div class="export-list__count">
                                <?php if ($row['count'] !== null): ?>
                                    <?= count_of((int) $row['count'], 'record') ?>
                                <?php endif; ?>
                            </div>

                            <?php // One fixed-width action rail, the verb
                                  // stretched to fill it, so four buttons share
                                  // one left edge instead of four text-sized
                                  // links at four different widths. ?>
                            <div class="list__row-actions">
                                <a class="btn btn--sm" href="<?= h($row['href']) ?>" download>
                                    <?= icon('download', 'icon icon--sm') ?>Download
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-card__section">
                <?= panel_title('Import', 'import') ?>
                <?php // THE UPLOAD, AND WHAT IT WILL NOT DO. Two routes — one
                      // that reads the file and says what it would do, one that
                      // does it — and `Content\Import`'s header has the argument
                      // for why it is two and not one. ?>

                <?php if ($importError !== null): ?>
                    <div class="alert alert--danger">
                        <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                        <div class="alert__body">
                            <p class="alert__title">That file was not imported</p>
                            <p class="alert__text"><?= h($importError) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($importDone !== null): ?>
                    <?php // WHAT IT DID, NOT WHAT IT WAS ASKED TO DO — the same
                          // argument as the assistant's `apply_results`. The
                          // counts come back from the applier, and the log is
                          // every row it touched. ?>
                    <div class="flashes">
                        <div class="flash flash--success" data-import-done>
                            Imported:
                            <?= (int) $importDone['created'] ?> created,
                            <?php if (!empty($importDone['sheet'])): ?>
                                <?= (int) $importDone['updated'] ?> updated,
                            <?php else: ?>
                                <?= (int) $importDone['overwritten'] ?> overwritten,
                            <?php endif; ?>
                            <?= (int) $importDone['skipped'] ?> skipped<?php
                            if ((int) $importDone['settings'] > 0): ?>,
                                <?= (int) $importDone['settings'] ?> setting(s) written<?php
                            endif; ?>.
                        </div>
                    </div>
                    <?php if (!empty($importDone['log'])): ?>
                        <?php // `fold`, THE ADMIN'S OWN DISCLOSURE — the same
                              // element and classes `forms/edit.php` uses for
                              // Advanced and Validation messages. A second
                              // disclosure vocabulary invented here would be a
                              // loose part, and the caret would be missing. ?>
                        <details class="fold">
                            <summary class="fold__head">
                                What it touched, row by row
                                <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                            </summary>
                            <ul class="kv-list">
                                <?php foreach ($importDone['log'] as $line): ?>
                                    <li><code><?= h((string) $line) ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                    <?php // EVERYTHING ARRIVES PRIVATE, and this is the sentence
                          // that stops the support call. A file that published
                          // fifty pages on upload would not be a recoverable
                          // mistake. ?>
                    <?php if (!empty($importDone['sheet'])): ?>
                        <p class="form-help">
                            New products are <strong>Private</strong> — on the site for you and
                            404s for visitors until you publish them. Products updated keep their status.
                        </p>
                    <?php else: ?>
                        <p class="form-help">
                            Everything imported is <strong>Private</strong> — it is on the
                            site for you and 404s for visitors until you publish it.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($importPlan !== null): ?>
                    <?php
                    $planCount = count($importPlan['pages']) + count($importPlan['entries']);
                    $overwrites = count(array_filter(
                        array_merge($importPlan['pages'], $importPlan['entries']),
                        static fn (array $r): bool => in_array($r['action'], ['overwrite', 'update'], true)
                    ));
                    ?>
                    <?php // THE PLAN. Nothing has been written at this point —
                          // the file is waiting in the session and this is the
                          // person in the middle of the two steps. ?>
                    <div class="alert alert--info">
                        <?= icon('circle-info', 'alert__icon icon', '', 18) ?>
                        <div class="alert__body">
                            <p class="alert__title">
                                <?= h((string) $importFile) ?> — nothing has been changed yet
                            </p>
                            <p class="alert__text">
                                <?= $planCount ?> item<?= $planCount === 1 ? '' : 's' ?> to import<?php
                                if ($overwrites > 0): ?>, <strong><?= $overwrites ?> of which would
                                <?= !empty($importPlan['sheet']) ? 'update' : 'overwrite' ?> something already here</strong><?php endif; ?>.
                            </p>
                        </div>
                    </div>

                    <?php foreach ([['Pages', $importPlan['pages']], ['Entries', $importPlan['entries']]] as [$heading, $rows]): ?>
                        <?php if ($rows === []) { continue; } ?>
                        <?= panel_title($heading, $heading === 'Pages' ? 'pages' : 'content') ?>
                        <?php // `entry-list` AS WELL AS `list`. The row grid —
                              // main cell, meta, actions — lives on
                              // `.entry-list .list__row`, and without it the
                              // status chip drops onto its own line under the
                              // slug — three stacked lines per row where there
                              // should be one. ?>
                        <div class="list entry-list imp-list">
                            <?php foreach ($rows as $row): ?>
                                <div class="list__row">
                                    <div class="entry-list__main">
                                        <div class="list__row-title"><?= h((string) $row['title']) ?></div>
                                        <div class="list__row-sub">/<?= h((string) $row['slug']) ?><?php
                                            if (isset($row['type'])): ?> · <?= h((string) $row['type']) ?><?php
                                            endif; ?><?php
                                            if (($row['detail'] ?? '') !== ''): ?> · <?= h((string) $row['detail']) ?><?php
                                            endif; ?></div>
                                    </div>
                                    <div class="list__row-meta">
                                        <?php // THE WORD, not a colour alone. An
                                              // overwrite and a create must be
                                              // tellable apart by someone who
                                              // cannot see the difference between
                                              // two greens. ?>
                                        <?php // THE WORD CARRIES IT, with no
                                              // colour modifier invented for the
                                              // occasion: a state is never a
                                              // colour on its own, and there is
                                              // no `status--warn` in this
                                              // system. ?>
                                        <span class="status status--phrase" data-import-action="<?= h((string) $row['action']) ?>">
                                            <?= match ((string) $row['action']) {
                                                'overwrite' => 'Overwrites what is here',
                                                'update'    => 'Updates what is here',
                                                default     => 'New',
                                            } ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if (($importPlan['notes'] ?? []) !== []): ?>
                        <?php // WHAT THE FILE HAD THAT IS NOT READ, said before
                              // anything is written: a column that is not
                              // imported, an image that is not fetched. ?>
                        <div class="alert alert--info" data-import-notes>
                            <?= icon('circle-info', 'alert__icon icon', '', 18) ?>
                            <div class="alert__body">
                                <ul class="alert__text">
                                    <?php foreach ($importPlan['notes'] as $note): ?>
                                        <li><?= h((string) $note) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($importPlan['skipped'] !== []): ?>
                        <?php // SKIPPED IS NOT SILENT. Dropping half a file and
                              // reporting success is how a third of a site goes
                              // missing on a migration. ?>
                        <?php // AN ALERT, because that is what this is: things
                              // in the file that will not arrive. `alert__text`
                              // as a `<ul>` is the same markup the redirects and
                              // coupon forms use for their error summaries. ?>
                        <?php // `alert--info`. There is no `alert--warning` in this
                              // system — danger, info and compact are the
                              // three that exist, and adding a fourth for
                              // one screen is how a palette drifts. What
                              // will be skipped is information; the title
                              // counts it and the glyph is the alert's. ?>
                        <div class="alert alert--info" data-import-skipped>
                            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                            <div class="alert__body">
                                <p class="alert__title">
                                    <?= count($importPlan['skipped']) === 1
                                        ? 'One thing will be skipped'
                                        : count($importPlan['skipped']) . ' things will be skipped' ?>
                                </p>
                                <ul class="alert__text">
                                    <?php foreach ($importPlan['skipped'] as $s): ?>
                                        <li><strong><?= h((string) $s['what']) ?></strong> — <?= h((string) $s['why']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= h(Url::admin('tools/import/apply')) ?>">
                        <?= Csrf::field() ?>
                        <?php if ($importPlan['settings'] !== []): ?>
                            <?php // SETTINGS ARE THEIR OWN DECISION. An export's
                                  // settings block is the whole options table —
                                  // the site's own address, its currency, its
                                  // mail configuration. Nobody gets their base
                                  // URL rewritten as a side effect of importing
                                  // a hundred news items. ?>
                            <div class="field">
                                <div class="check-list">
                                    <label class="check-list__row">
                                        <input type="checkbox" name="import_settings" value="1">
                                        <span>Also overwrite <?= count($importPlan['settings']) ?> setting<?= count($importPlan['settings']) === 1 ? '' : 's' ?></span>
                                    </label>
                                </div>
                                <p class="form-help">
                                    Includes things like the site address and currency.
                                    Leave this off when importing content into a site
                                    that is already set up.
                                </p>
                                <details class="fold">
                                    <summary class="fold__head">
                                        Which settings would change
                                        <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                                    </summary>
                                    <ul class="kv-list">
                                        <?php foreach ($importPlan['settings'] as $row): ?>
                                            <li>
                                                <code><?= h((string) $row['key']) ?></code>:
                                                <?= $row['from'] === null ? '(not set)' : h((string) $row['from']) ?>
                                                &rarr; <?= h((string) $row['to']) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            </div>
                        <?php endif; ?>

                        <div class="form-actions form-actions--bare">
                            <a class="btn" href="<?= h(Url::admin('tools/import-export')) ?>">Cancel</a>
                            <button type="submit" class="btn btn--primary">
                                Import <?= $planCount ?> item<?= $planCount === 1 ? '' : 's' ?>
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= h(Url::admin('tools/import')) ?>"
                          enctype="multipart/form-data">
                        <?= Csrf::field() ?>
                        <div class="field">
                            <label class="label" for="f-import"><?= $sellingTypes !== [] ? 'Export file, or a spreadsheet of products' : 'Export file' ?></label>
                            <input class="input" type="file" id="f-import" name="import_file"
                                   accept="<?= $sellingTypes !== [] ? 'application/json,.json,text/csv,.csv' : 'application/json,.json' ?>">
                            <?php // WHAT IT MATCHES ON, said before the upload
                                  // rather than discovered after it. An import
                                  // that matches on the ids in the file would
                                  // overwrite unrelated rows on this install —
                                  // see `Content\Import`'s header. ?>
                            <p class="form-help">
                                A <code>.json</code> file from the Download buttons above —
                                this site's or another site's. Items are matched by
                                their <strong>address</strong>, not by their id, so anything
                                whose address is already here will be shown as an overwrite
                                before you decide.
                            </p>
                        </div>
                        <?php if ($sellingTypes !== []): ?>
                            <?php // A SPREADSHEET IS PRODUCTS, of one type that sells:
                                  // which, chosen here, and only read for a .csv. ?>
                            <div class="field">
                                <label class="label" for="f-import-into">A spreadsheet's products go into</label>
                                <select class="select" id="f-import-into" name="import_into">
                                    <?php // NO GUESS WHEN THERE IS A CHOICE: a file of
                                          // merch read as events would plan every row
                                          // as a new event. ?>
                                    <?php if (count($sellingTypes) > 1): ?>
                                        <option value="">Choose one</option>
                                    <?php endif; ?>
                                    <?php foreach ($sellingTypes as $key => $ct): ?>
                                        <option value="<?= h((string) $key) ?>"><?= h((string) ($ct['label'] ?? $key)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-help">
                                    A <code>.csv</code> with a row for each product and each of its options, under
                                    columns such as Type, SKU, Name, Regular price, Sale price, Stock, Categories, and
                                    Attribute 1 name and value, as the spreadsheet download above writes them. A row
                                    whose SKU a product here has updates it; any other is a new product.
                                </p>
                            </div>
                        <?php endif; ?>
                        <div class="form-actions form-actions--bare">
                            <button type="submit" class="btn btn--primary">
                                <?= icon('upload', 'icon icon--sm') ?>Read the file
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
