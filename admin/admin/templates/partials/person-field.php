<?php

declare(strict_types=1);

/**
 * WHICH OF THE SITE'S PEOPLE AN ACCOUNT IS —
 * the field on Add user and Edit user. The site lists its people
 * (`Helpers\People`, its people.php) and its byline links that person's page
 * and photo; the label and help are the site's own, as it names its people.
 *
 * The screens draw it only for an ADMINISTRATOR and only when the site lists
 * people: a byline's page and photo are the site's to give, and an author who
 * could set this could sign as somebody else.
 *
 * One person is one account, so a person another account already is shows
 * whose, and is not offered. A person dropped from the list since is kept
 * and marked, so the next save does not silently unlink it.
 *
 * Expects:
 *   $personNow      string  the key the account holds ('' for none)
 *   $personAccount  int     the account's id; 0 for one not yet made
 */

$pfPeople  = \Cms\Helpers\People::all();
$pfHolders = \Cms\Models\User::personHolders();
?>
<div class="field">
    <label class="label" for="f-person"><?= h(\Cms\Helpers\People::label()) ?></label>
    <select class="select" id="f-person" name="person_key">
        <option value="">Not linked</option>
        <?php foreach ($pfPeople as $pfKey => $pfPerson): ?>
            <?php
            $pfHolder = $pfHolders[$pfKey] ?? null;
            $pfOther  = $pfHolder !== null && (int) $pfHolder['id'] !== $personAccount;
            ?>
            <option value="<?= h((string) $pfKey) ?>"<?= $personNow === (string) $pfKey ? ' selected' : '' ?><?= $pfOther ? ' disabled' : '' ?>><?= h($pfPerson['name']) ?><?= $pfOther ? ' — ' . h(\Cms\Models\User::displayName($pfHolder)) . '’s account' : '' ?></option>
        <?php endforeach; ?>
        <?php if ($personAccount > 0 && $personNow !== '' && !isset($pfPeople[$personNow])): ?>
            <option value="<?= h($personNow) ?>" selected>“<?= h($personNow) ?>” (no longer on the list)</option>
        <?php endif; ?>
    </select>
    <?php if (\Cms\Helpers\People::help() !== ''): ?>
        <p class="form-help"><?= h(\Cms\Helpers\People::help()) ?></p>
    <?php endif; ?>
</div>
