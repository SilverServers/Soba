<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Option;

/**
 * WHOSE RECEIPT IT IS — the business's name, address and GST/HST number, as
 * Settings → Shop keeps them, for the foot of the receipt page and the
 * customer's email.
 *
 * A business registered for GST/HST gives its registration number on a
 * receipt, and a customer with a question about an order writes to an
 * address, not to a website's name. One reader, so the page and the email
 * cannot print two versions of it.
 */
final class Business
{
    /**
     * The lines to print, blank ones left out: the name, each line of the
     * address, and "GST/HST 123456789 RT0001".
     *
     * @return list<string>
     */
    public static function lines(): array
    {
        $name    = trim((string) (Option::get('commerce_business_name') ?? ''));
        $address = preg_split('/\R/', trim((string) (Option::get('commerce_business_address') ?? ''))) ?: [];
        $number  = trim((string) (Option::get('commerce_tax_number') ?? ''));

        return array_values(array_filter(array_merge(
            [$name],
            array_map('trim', $address),
            [$number !== '' ? 'GST/HST ' . $number : '']
        ), static fn (string $line): bool => $line !== ''));
    }
}
