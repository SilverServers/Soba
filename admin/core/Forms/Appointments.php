<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Database\Database;
use PDO;
use PDOException;

/**
 * BOOKABLE SLOTS — which exist, which are free, and who took one.
 *
 * ── THE SLOTS ARE DERIVED, NOT STORED ───────────────────────────────────────
 * The owner sets a shape — which weekdays, between which hours, how long each
 * appointment is, how far ahead to open the calendar — and the slots follow
 * from it. Storing a row per available slot would mean generating them ahead of
 * time, and then a calendar that quietly runs out because nobody re-ran the
 * generator. Derived, it cannot run out and there is nothing to keep in step.
 *
 * Only BOOKINGS are stored, because a booking is a fact about the world.
 *
 * ── FREE MEANS "NOT IN THE TABLE" ───────────────────────────────────────────
 * And the table is what decides, not a PHP check. "Is it free? then write it"
 * is a race whose window is exactly when two people are looking at the same
 * popular slot — both read free, both write, and two strangers arrive for one
 * appointment. `book()` lets the UNIQUE index refuse the second one.
 *
 * ── NO PAST SLOTS, EVER ─────────────────────────────────────────────────────
 * A form cached, left open in a tab overnight, or crawled last week will offer
 * yesterday. The list is built from `now` every time it is asked for, and
 * `book()` refuses a slot that has already started even if it is somehow still
 * on the page — the same discipline every other field here follows: the
 * control restricts the browser, never the request.
 */
final class Appointments
{
    /** How the owner's settings are read, with the shape they default to. */
    public const DEFAULTS = [
        'appt_days'    => '1,2,3,4,5',   // ISO weekdays: Monday is 1
        'appt_from'    => '09:00',
        'appt_to'      => '17:00',
        'appt_minutes' => '30',
        'appt_ahead'   => '14',          // days of calendar to open
    ];

    /** A ceiling on how far ahead, so one typo cannot render 4,000 radios. */
    private const MAX_AHEAD = 90;

    /**
     * Every slot this field offers, free ones only.
     *
     * @param  array<string, mixed> $field
     * @return array<string, list<array{key: string, label: string, at: string}>>
     *         day heading => slots
     */
    public static function available(int $formId, array $field): array
    {
        $name = (string) ($field['name'] ?? '');
        if ($name === '') {
            return [];
        }

        $days    = self::weekdays($field);
        $minutes = max(5, (int) self::setting($field, 'appt_minutes'));
        $ahead   = min(self::MAX_AHEAD, max(1, (int) self::setting($field, 'appt_ahead')));

        [$fromH, $fromM] = self::clock(self::setting($field, 'appt_from'), 9, 0);
        [$toH,   $toM]   = self::clock(self::setting($field, 'appt_to'), 17, 0);

        $now   = new \DateTimeImmutable('now');
        $taken = self::takenKeys($formId, $name);
        $out   = [];

        for ($d = 0; $d < $ahead; $d++) {
            $day = $now->modify('+' . $d . ' day');
            if (!in_array((int) $day->format('N'), $days, true)) {
                continue;
            }

            $start = $day->setTime($fromH, $fromM);
            $end   = $day->setTime($toH, $toM);
            if ($end <= $start) {
                continue;   // a shape that describes no time at all
            }

            $slots = [];
            for ($at = $start; $at < $end; $at = $at->modify('+' . $minutes . ' minutes')) {
                // PAST SLOTS ARE NOT OFFERED. A page left open overnight would
                // otherwise show this morning as bookable.
                if ($at <= $now) {
                    continue;
                }
                $key = self::key($formId, $name, $at);
                if (isset($taken[$key])) {
                    continue;
                }
                $slots[] = [
                    'key'   => $key,
                    'at'    => $at->format('Y-m-d H:i:s'),
                    'label' => $at->format('g:i A'),
                ];
            }

            // A DAY WITH NOTHING LEFT IS NOT SHOWN. A heading over an empty
            // list reads as a broken control rather than as a full day.
            if ($slots !== []) {
                $out[$day->format('l, j F')] = $slots;
            }
        }

        return $out;
    }

    /**
     * Take a slot, or fail.
     *
     * THE INDEX DECIDES, NOT A LOOK-BEFORE-YOU-LEAP. Returns false when the
     * slot went between the page loading and the button being pressed, which
     * is a real thing that happens to popular times and is the only correct
     * answer to it.
     */
    public static function book(int $formId, array $field, string $slotKey, ?string &$error = null): bool
    {
        $error = null;
        $name  = (string) ($field['name'] ?? '');

        $at = self::slotTime($formId, $name, $slotKey);
        if ($at === null) {
            $error = 'That is not a time we offer.';
            return false;
        }
        if ($at <= new \DateTimeImmutable('now')) {
            $error = 'That time has already passed.';
            return false;
        }

        $minutes = max(5, (int) self::setting($field, 'appt_minutes'));

        try {
            $stmt = Database::getInstance()->prepare(
                'INSERT INTO form_appointments
                    (form_id, field_name, slot_key, starts_at, ends_at)
                 VALUES (:form_id, :field_name, :slot_key, :starts_at, :ends_at)'
            );
            $stmt->execute([
                ':form_id'    => $formId,
                ':field_name' => $name,
                ':slot_key'   => $slotKey,
                ':starts_at'  => $at->format('Y-m-d H:i:s'),
                ':ends_at'    => $at->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException) {
            // The unique index refused it — somebody else got there first.
            // Nothing else in this statement can realistically fail in a way
            // the visitor should be told about differently.
            $error = 'Somebody booked that time while you were filling this in. Please pick another.';
            return false;
        }

        return true;
    }

    /**
     * Give a slot back.
     *
     * Only ever used to undo a hold this same request took: a form with two
     * appointment fields whose second clashes must not leave the first held by
     * a submission that never happened. It matches on the key alone, which is
     * safe because a row with no `submission_id` yet can only be this
     * request's — nothing else creates one.
     */
    public static function release(string $slotKey): void
    {
        $stmt = Database::getInstance()->prepare(
            'DELETE FROM form_appointments
              WHERE slot_key = :key AND submission_id IS NULL'
        );
        $stmt->execute([':key' => $slotKey]);
    }

    /** Tie a booking to the submission it arrived with, once that row exists. */
    public static function attach(string $slotKey, int $submissionId): void
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE form_appointments SET submission_id = :sid WHERE slot_key = :key'
        );
        $stmt->execute([':sid' => $submissionId, ':key' => $slotKey]);
    }

    /** How a booked slot reads in the submissions table and the email. */
    public static function describe(int $formId, string $fieldName, string $slotKey): string
    {
        $at = self::slotTime($formId, $fieldName, $slotKey);

        return $at === null ? '' : $at->format('l, j F Y \a\t g:i A');
    }

    // ── The shape, read from the field ──────────────────────────────────────

    private static function setting(array $field, string $key): string
    {
        $value = trim((string) ($field[$key] ?? ''));

        return $value !== '' ? $value : (string) self::DEFAULTS[$key];
    }

    /** @return list<int> ISO weekday numbers */
    private static function weekdays(array $field): array
    {
        $out = [];
        foreach (explode(',', self::setting($field, 'appt_days')) as $one) {
            $n = (int) trim($one);
            if ($n >= 1 && $n <= 7) {
                $out[] = $n;
            }
        }

        return $out !== [] ? $out : [1, 2, 3, 4, 5];
    }

    /** @return array{0: int, 1: int} */
    private static function clock(string $value, int $hFallback, int $mFallback): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
                return [$h, $i];
            }
        }

        return [$hFallback, $mFallback];
    }

    // ── The key ─────────────────────────────────────────────────────────────

    /**
     * form + field + start, which is what `uniq_slot` is on.
     *
     * The time is in the key rather than only in a column so the uniqueness the
     * database enforces is exactly the uniqueness that matters, in one string,
     * with nothing to get out of step.
     */
    private static function key(int $formId, string $name, \DateTimeImmutable $at): string
    {
        return $formId . ':' . $name . ':' . $at->format('Y-m-d H:i');
    }

    /**
     * The time a key stands for — and null if the key is not this field's.
     *
     * A POSTED KEY IS A STRING FROM A STRANGER. Parsing it back and checking
     * the form and field it names is what stops one form's booking being made
     * against another's calendar.
     */
    private static function slotTime(int $formId, string $name, string $key): ?\DateTimeImmutable
    {
        $prefix = $formId . ':' . $name . ':';
        if (!str_starts_with($key, $prefix)) {
            return null;
        }
        $stamp = substr($key, strlen($prefix));
        $at    = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $stamp);

        return ($at !== false && $at->format('Y-m-d H:i') === $stamp) ? $at : null;
    }

    /** @return array<string, true> the keys already taken, as a lookup */
    private static function takenKeys(int $formId, string $name): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT slot_key FROM form_appointments
              WHERE form_id = :form_id AND field_name = :field_name
                AND starts_at >= NOW()'
        );
        $stmt->execute([':form_id' => $formId, ':field_name' => $name]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $out[(string) $key] = true;
        }

        return $out;
    }
}
