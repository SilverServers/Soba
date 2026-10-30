<?php

declare(strict_types=1);

namespace Cms\Commerce;

/**
 * THE PROVINCES AND STATES A CHECKOUT ASKS FOR.
 *
 * ── WHY A LIST AND NOT A BOX ────────────────────────────────────────────────
 * A Canadian order's tax is decided by the province it goes to: 13% HST in
 * Ontario, 5% GST in Alberta, GST and QST in Quebec. A free-text box let a
 * customer leave it blank or type "Ont.", and nothing could decide a rate from
 * either. So for the countries listed here the checkout offers a select, the
 * order stores the code — "ON", which is what Canada Post and a shipping label
 * want — and a name is only ever drawn from it.
 *
 * Canada and the United States, written down rather than looked up, as the
 * country list is. Another country keeps the free-text box: its region decides
 * nothing here, and a list of every subdivision on earth would be a guess at
 * what each postal system wants.
 *
 * ── WHAT IS LISTED ──────────────────────────────────────────────────────────
 * Canada's ten provinces and three territories. The fifty states, the District
 * of Columbia and the inhabited territories a parcel can be addressed to; no
 * military codes.
 */
final class Regions
{
    /** Country => [code => name], each sorted by name. */
    public const ALL = [
        'CA' => [
            'AB' => 'Alberta',
            'BC' => 'British Columbia',
            'MB' => 'Manitoba',
            'NB' => 'New Brunswick',
            'NL' => 'Newfoundland and Labrador',
            'NT' => 'Northwest Territories',
            'NS' => 'Nova Scotia',
            'NU' => 'Nunavut',
            'ON' => 'Ontario',
            'PE' => 'Prince Edward Island',
            'QC' => 'Quebec',
            'SK' => 'Saskatchewan',
            'YT' => 'Yukon',
        ],
        'US' => [
            'AL' => 'Alabama',
            'AK' => 'Alaska',
            'AS' => 'American Samoa',
            'AZ' => 'Arizona',
            'AR' => 'Arkansas',
            'CA' => 'California',
            'CO' => 'Colorado',
            'CT' => 'Connecticut',
            'DE' => 'Delaware',
            'DC' => 'District of Columbia',
            'FL' => 'Florida',
            'GA' => 'Georgia',
            'GU' => 'Guam',
            'HI' => 'Hawaii',
            'ID' => 'Idaho',
            'IL' => 'Illinois',
            'IN' => 'Indiana',
            'IA' => 'Iowa',
            'KS' => 'Kansas',
            'KY' => 'Kentucky',
            'LA' => 'Louisiana',
            'ME' => 'Maine',
            'MD' => 'Maryland',
            'MA' => 'Massachusetts',
            'MI' => 'Michigan',
            'MN' => 'Minnesota',
            'MS' => 'Mississippi',
            'MO' => 'Missouri',
            'MT' => 'Montana',
            'NE' => 'Nebraska',
            'NV' => 'Nevada',
            'NH' => 'New Hampshire',
            'NJ' => 'New Jersey',
            'NM' => 'New Mexico',
            'NY' => 'New York',
            'NC' => 'North Carolina',
            'ND' => 'North Dakota',
            'MP' => 'Northern Mariana Islands',
            'OH' => 'Ohio',
            'OK' => 'Oklahoma',
            'OR' => 'Oregon',
            'PA' => 'Pennsylvania',
            'PR' => 'Puerto Rico',
            'RI' => 'Rhode Island',
            'SC' => 'South Carolina',
            'SD' => 'South Dakota',
            'TN' => 'Tennessee',
            'TX' => 'Texas',
            'VI' => 'U.S. Virgin Islands',
            'UT' => 'Utah',
            'VT' => 'Vermont',
            'VA' => 'Virginia',
            'WA' => 'Washington',
            'WV' => 'West Virginia',
            'WI' => 'Wisconsin',
            'WY' => 'Wyoming',
        ],
    ];

    /** What the country calls them, for a label: "Province", "State". */
    private const WORDS = ['CA' => 'Province', 'US' => 'State'];

    /** @return array<string, string> code => name; empty for a country not listed */
    public static function of(string $country): array
    {
        return self::ALL[strtoupper(trim($country))] ?? [];
    }

    /** Does the checkout offer a list for this country, and so require one? */
    public static function listed(string $country): bool
    {
        return self::of($country) !== [];
    }

    /** The field's label for this country — "Province", "State", or both words. */
    public static function word(string $country): string
    {
        return self::WORDS[strtoupper(trim($country))] ?? 'Province or state';
    }

    public static function isValid(string $country, string $code): bool
    {
        return isset(self::of($country)[strtoupper(trim($code))]);
    }

    /** The name for a stored code; the stored text itself for anything else. */
    public static function name(string $country, string $code): string
    {
        return self::of($country)[strtoupper(trim($code))] ?? $code;
    }

    /**
     * A code from what was typed or stored: "ON", "on", "Ontario". Blank when
     * it names nothing in the country's list — an order placed before the list
     * existed, or an import, is read through this rather than trusted.
     */
    public static function code(string $country, string $typed): string
    {
        $typed = trim($typed);
        if ($typed === '') {
            return '';
        }
        $list = self::of($country);
        if (isset($list[strtoupper($typed)])) {
            return strtoupper($typed);
        }
        foreach ($list as $code => $name) {
            if (mb_strtolower($name) === mb_strtolower($typed)) {
                return $code;
            }
        }

        return '';
    }
}
