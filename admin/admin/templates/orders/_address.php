<?php

declare(strict_types=1);

use Cms\Commerce\Countries;

/**
 * AN ADDRESS, AS BOXES: name, street, town, province, postal code, country.
 *
 * The order's correction fold and the new order screen both draw it. The
 * province is a list for a country `Regions` lists, posted as `<who>_region`,
 * and a box for any other, posted as `<who>_region_text`: `Checkout::readForm()`
 * reads whichever the country calls for, the same as at the checkout.
 *
 * @var string   $who       'billing' or 'shipping'
 * @var callable $addr      field name => the value to show
 * @var string   $idPrefix  so two of these on one page keep their ids apart
 */
    $country = strtoupper($addr($who . '_country'));
    $listed  = \Cms\Commerce\Regions::listed($country);
    $id      = static fn (string $k): string => $idPrefix . '-' . $who . '-' . $k;
    foreach (['name' => 'Full name', 'line1' => 'Address', 'line2' => 'Apartment, suite, etc.', 'city' => 'Town or city'] as $k => $label): ?>
        <div class="field">
            <label class="label" for="<?= h($id($k)) ?>"><?= h($label) ?></label>
            <input class="input" type="text" id="<?= h($id($k)) ?>" name="<?= h($who . '_' . $k) ?>" value="<?= h($addr($who . '_' . $k)) ?>">
        </div>
    <?php endforeach; ?>
    <div class="form-grid form-grid--2">
        <div class="field">
            <label class="label" for="<?= h($id('region')) ?>"><?= h(\Cms\Commerce\Regions::word($country)) ?></label>
            <select class="select" id="<?= h($id('region')) ?>" name="<?= h($who . '_region') ?>"<?= $listed ? '' : ' hidden' ?>>
                <option value="">Choose…</option>
                <?php foreach (\Cms\Commerce\Regions::ALL as $listCountry => $list): ?>
                    <optgroup label="<?= h(Countries::name($listCountry)) ?>">
                        <?php foreach ($list as $code => $regionName): ?>
                            <option value="<?= h($code) ?>"<?= $listCountry === $country && $addr($who . '_region') === $code ? ' selected' : '' ?>><?= h($regionName) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <input class="input" type="text" name="<?= h($who . '_region_text') ?>" aria-label="Province or state"
                   value="<?= $listed ? '' : h($addr($who . '_region')) ?>"<?= $listed ? ' hidden' : '' ?>>
        </div>
        <div class="field">
            <label class="label" for="<?= h($id('post')) ?>">Postal code</label>
            <input class="input" type="text" id="<?= h($id('post')) ?>" name="<?= h($who . '_post') ?>" value="<?= h($addr($who . '_post')) ?>">
        </div>
    </div>
    <div class="field">
        <label class="label" for="<?= h($id('country')) ?>">Country</label>
        <select class="select" id="<?= h($id('country')) ?>" name="<?= h($who . '_country') ?>">
            <?php if ($who === 'shipping'): ?><option value="">Same as the billing address</option><?php endif; ?>
            <?php foreach (Countries::ALL as $code => $countryName): ?>
                <option value="<?= h($code) ?>"<?= $country === $code ? ' selected' : '' ?>><?= h($countryName) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
