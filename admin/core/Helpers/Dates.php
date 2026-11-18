<?php

declare(strict_types=1);

namespace Cms\Helpers;

use Cms\Models\Option;

/**
 * DATES, IN THE FORMAT THE SITE OWNER CHOSE.
 *
 * `date_format` and `time_format` have been on the General settings screen's
 * key list since it was written, are validated and saved by
 * `SettingsController::handleGeneral()`, and were read by NOTHING. Every admin
 * template called `date('j M Y', …)` with the format hardcoded, so the two
 * controls stored a preference that could not be observed anywhere.
 *
 * That is the featured-image fault in a different place: a control whose only
 * effect is the sentence next to it. The fix is the same one — make the control
 * work rather than describe it. This class is the consumer that was missing.
 *
 * ── THE OPTIONS ARE READ ONCE PER REQUEST ───────────────────────────────────
 * `Option::get()` runs a prepared SELECT every call and caches nothing, so a
 * 40-row media library or entry list would issue 40 identical queries for a
 * value that cannot change mid-request. The static holds them for the request
 * and no longer; there is no cross-request cache to invalidate when the setting
 * is saved, which is deliberate — a stale format is a support call, and the
 * saving of it already re-renders the page.
 *
 * ── `date('c')` IS NOT AFFECTED, AND MUST NOT BE ────────────────────────────
 * The `datetime=""` attribute on a `<time>` element is machine-readable and is
 * specified as ISO 8601. Formatting it to the owner's preference would break
 * the one thing it exists for. Only the human-visible text goes through here.
 *
 * ── AND THE TIMEZONE WAS THE SAME FAULT, A THIRD TIME ───────────────────────
 * `timezone` sat on the General settings screen beside the two formats —
 * validated, saved, America/Vancouver by default — and was read by nothing
 * (the assistant looked for `site_timezone`, a key no screen writes). Every
 * date in the admin was UTC: a revision made at 2 pm in Kamloops said 9 pm.
 *
 * Storage is UTC (`Database::init()` pins PHP to it, the connection pins
 * MySQL). This class is where a stored moment becomes the owner's time, and —
 * for a publish date typed into a box — where the owner's time becomes a
 * stored moment again. Every stored value handed to `short()`/`stamp()` is a
 * UTC database timestamp; that was checked across every caller when the
 * conversion went in. Something typed as local wall-clock time and stored as
 * such (an event's start, a field value) must NOT be passed here.
 */
final class Dates
{
    /** Matches SettingsController::GENERAL_KEYS — change both together. */
    private const DEFAULT_DATE = 'M j, Y';
    private const DEFAULT_TIME = 'g:i A';
    /** Matches SettingsController's default and migration 019's seed. */
    private const DEFAULT_ZONE = 'America/Vancouver';

    /** @var array<string, string>|null  request-scoped, see the class note */
    private static ?array $formats = null;

    private static ?\DateTimeZone $zone = null;

    /**
     * The owner's date format, e.g. "25 Aug 2026".
     *
     * @param string|int|null $when  anything strtotime() understands, or a
     *                               timestamp. Null and unparseable values
     *                               return '' rather than 1 Jan 1970 — a
     *                               missing date must look missing.
     */
    public static function short(string|int|null $when): string
    {
        return self::render($when, self::formats()['date']);
    }

    /** The owner's date AND time, for a stamp that needs the hour. */
    public static function stamp(string|int|null $when): string
    {
        $f = self::formats();
        return self::render($when, $f['date'] . ', ' . $f['time']);
    }

    /**
     * A stored moment in a format the CALLER chooses (a time zone's name, a
     * machine's format), always in the site's zone. A date a person reads is
     * `short()` or `stamp()`, in the owner's format, on the site as in the
     * admin: the two must not disagree about which day it was, or how it is
     * written.
     */
    public static function format(string|int|null $when, string $format): string
    {
        return self::render($when, $format);
    }

    /**
     * A WALL-CLOCK TIME AS TYPED (an event's start, a field's value) in
     * `$format`, with no zone applied: it is the site's local time already,
     * and converting it as `format()` converts a stored moment would move it.
     * '' for none, or for what is not a time.
     */
    public static function asTyped(?string $value, string $format): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        try {
            $at = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

            return $at->format(\Cms\Site\Words::dateFormat($format, $at));
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * The owner's date format (Settings → General), for a date typed as the
     * site's own (an event's day, a date field) printed with `asTyped()`.
     */
    public static function dateFormat(): string
    {
        return self::formats()['date'];
    }

    /**
     * The owner's time format (Settings → General), for a time typed as the
     * site's own: a 24-hour site's evening meeting is at 18:30.
     */
    public static function timeFormat(): string
    {
        return self::formats()['time'];
    }

    /**
     * Today, as the site counts days: `Y-m-d` in Settings → General's zone.
     *
     * NOT `date('Y-m-d')`: PHP runs in UTC here (`Database::init()`), so in
     * Vancouver a code whose last day is the 30th stopped working at 5 pm on
     * the 30th, and a sale started at 4 pm the evening before.
     */
    public static function today(): string
    {
        return (new \DateTimeImmutable('now', self::zone()))->format('Y-m-d');
    }

    /** The site's zone: Settings → General → Timezone. */
    public static function zone(): \DateTimeZone
    {
        if (self::$zone === null) {
            $name = self::formats()['zone'];
            try {
                self::$zone = new \DateTimeZone($name);
            } catch (\Exception) {
                // A stored name PHP does not know is a bad row, not a reason
                // to show nothing; the settings screen refuses unknown names.
                self::$zone = new \DateTimeZone(self::DEFAULT_ZONE);
            }
        }
        return self::$zone;
    }

    /**
     * A stored (UTC) datetime as the value of `<input type="datetime-local">`,
     * in the site's zone. Empty for none.
     */
    public static function toInput(?string $utc): string
    {
        if ($utc === null || trim($utc) === '') {
            return '';
        }
        $ts = strtotime($utc);
        if ($ts === false) {
            return '';
        }
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(self::zone())->format('Y-m-d\\TH:i');
    }

    /**
     * What a datetime-local box posted, read as the SITE's wall-clock time and
     * returned as UTC for storage. `null` for an empty box; `false` for
     * something that is not a date and time (the caller says so — silently
     * storing "now" instead would publish on a date nobody chose).
     */
    public static function fromInput(string $local): string|false|null
    {
        $local = trim($local);
        if ($local === '') {
            return null;
        }
        foreach (['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $shape) {
            $dt   = \DateTimeImmutable::createFromFormat('!' . $shape, $local, self::zone());
            $errs = \DateTimeImmutable::getLastErrors();
            // 31 February parses — PHP rolls it into March and records a
            // WARNING. A date that had to be corrected is not the date typed.
            if ($dt === false || ($errs !== false && ($errs['warning_count'] > 0 || $errs['error_count'] > 0))) {
                continue;
            }
            $year = (int) $dt->format('Y');
            if ($year < 1000 || $year > 9999) {
                return false; // outside what a DATETIME column holds
            }
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        return false;
    }

    private static function render(string|int|null $when, string $format): string
    {
        if ($when === null || $when === '') {
            return '';
        }
        $ts = is_int($when) ? $when : strtotime($when);
        // strtotime() returns false on nonsense. Rendering that as the epoch
        // puts "1 Jan 1970" on the screen, which reads as real data.
        if ($ts === false) {
            return '';
        }
        // THE OWNER'S ZONE. `$ts` is an absolute moment; '@' builds it in UTC
        // and setTimezone() says it the way a clock in Kamloops would.
        // A VISITOR'S PAGE writes the month and the day in its language
        // (`Site\Words::dateFormat()`); the admin, as PHP does.
        $at = (new \DateTimeImmutable('@' . $ts))->setTimezone(self::zone());

        return $at->format(\Cms\Site\Words::dateFormat($format, $at));
    }

    /** @return array{date: string, time: string, zone: string} */
    private static function formats(): array
    {
        if (self::$formats === null) {
            $opts = Option::getMany([
                'date_format' => self::DEFAULT_DATE,
                'time_format' => self::DEFAULT_TIME,
                'timezone'    => self::DEFAULT_ZONE,
            ]);
            // An option row that exists but is empty would otherwise render
            // every date as an empty string.
            $date = trim((string) ($opts['date_format'] ?? ''));
            $time = trim((string) ($opts['time_format'] ?? ''));
            $zone = trim((string) ($opts['timezone'] ?? ''));
            self::$formats = [
                'date' => $date !== '' ? $date : self::DEFAULT_DATE,
                'time' => $time !== '' ? $time : self::DEFAULT_TIME,
                'zone' => $zone !== '' ? $zone : self::DEFAULT_ZONE,
            ];
        }
        return self::$formats;
    }
}
