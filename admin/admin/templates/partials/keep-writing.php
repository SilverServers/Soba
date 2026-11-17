<?php

declare(strict_types=1);

/**
 * THE EDITORS' TWO NOTICES (`keep-writing.js`, `Ui\KeepWriting`), each hidden
 * until the script shows it: writing this browser kept that the site does not
 * have, offered back; and a sign-in that ended while somebody was writing.
 *
 * Amber, the alert surface for something waiting on the writer: nothing is
 * lost in either case, and in both the next move is theirs.
 */
?>
<div class="alert" data-keep-writing-offer hidden>
    <?= icon('clock', 'alert__icon icon', '', 18) ?>
    <div class="alert__body">
        <p class="alert__title">What you wrote at <span data-keep-writing-when></span> was not saved</p>
        <p class="alert__text">This browser kept the title and the text. Put them back here, then save.</p>
        <div class="alert__actions">
            <button type="button" class="btn btn--sm btn--primary" data-keep-writing-restore>Put it back</button>
            <button type="button" class="btn btn--sm" data-keep-writing-discard>Discard it</button>
        </div>
    </div>
</div>
<div class="alert" data-keep-writing-signed-out hidden>
    <?= icon('lock', 'alert__icon icon', '', 18) ?>
    <div class="alert__body">
        <p class="alert__title">You have been signed out</p>
        <p class="alert__text">What you are writing is kept in this browser. Sign in again in another tab, then come back here and save.</p>
        <div class="alert__actions">
            <a class="btn btn--sm" href="<?= h(\Cms\Security\LoginUrl::url()) ?>" target="_blank" rel="noopener">Sign in</a>
        </div>
    </div>
</div>
<?php
/* WHO ELSE HAS IT OPEN (`Content\Presence`, `Ui\KeepWriting::others()`), when
   it was drawn, and kept up while somebody writes. Amber, as the notices above
   are: nothing is wrong yet, and the next move is the writer's. Two lines,
   each hidden until it has something to say: who is in it, and somebody's
   save since this screen was drawn. */
$_presence = \Cms\Ui\KeepWriting::others();
?>
<div class="alert" data-presence<?= $_presence === [] ? ' hidden' : '' ?>>
    <?= icon('user-group', 'alert__icon icon', '', 18) ?>
    <div class="alert__body">
        <p class="alert__title">Someone else has this open</p>
        <ul class="alert__text" data-presence-list>
            <?php foreach ($_presence as $_who): ?>
                <li><?= h($_who['name']) ?>, since <?= h($_who['opened']) ?><?= $_who['writing'] !== null ? ', last writing at ' . h($_who['writing']) : '' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<div class="alert" data-presence-saved hidden>
    <?= icon('clock', 'alert__icon icon', '', 18) ?>
    <div class="alert__body">
        <p class="alert__title" data-presence-saved-title></p>
        <p class="alert__text">Saving now replaces what they saved; theirs stays in History.</p>
    </div>
</div>
