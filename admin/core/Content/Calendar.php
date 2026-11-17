<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Models\Meta;
use Cms\Models\Post;
use Cms\SEO\Head;

/**
 * EVENTS IN A VISITOR'S CALENDAR (iCalendar, RFC 5545): one event as a file,
 * `/{type}/{event}.ics`, behind its page's "Add to calendar", and every live
 * event of a type as one calendar, `/{type}/calendar.ics`, which a calendar
 * app can subscribe to and so keeps up to date. A type has them when it has
 * the event archetype and public pages.
 *
 * ITS TIMES ARE THE SITE'S, as typed (Settings → General's zone,
 * `Events::moment()`), written in UTC, so a calendar anywhere shows them at
 * the right hour. A date with no time is a day-long event; an event with no
 * end is a moment, and a day-long one a day. Its words are the event's own:
 * title, place, address and summary.
 */
final class Calendar
{
    /** @return list<string> the keys of the types whose events go into calendars */
    public static function types(): array
    {
        $out = [];
        foreach (Registry::all() as $key => $type) {
            if ((string) ($type['public_slug'] ?? '') !== '' && in_array('event', (array) ($type['archetypes'] ?? []), true)) {
                $out[] = (string) $key;
            }
        }
        return $out;
    }

    public static function has(string $typeKey): bool
    {
        return in_array($typeKey, self::types(), true);
    }

    /** A type's calendar, as a full address. */
    public static function url(string $typeKey): string
    {
        return Url::page(self::slugOf($typeKey) . '/calendar.ics');
    }

    /** One event's file, as a full address. */
    public static function eventUrl(string $typeKey, string $slug): string
    {
        return Url::page(self::slugOf($typeKey) . '/' . $slug . '.ics');
    }

    /**
     * The head's lines naming these types' calendars, those that have one.
     *
     * @param list<string> $typeKeys
     */
    public static function links(array $typeKeys): string
    {
        $h    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $site = (string) Head::globalTokens()['site_name'];
        $out  = '';
        foreach (array_unique($typeKeys) as $key) {
            if (self::has((string) $key)) {
                $out .= '<link rel="alternate" type="text/calendar" title="' . $h($site . ' · ' . (string) (Registry::get((string) $key)['label'] ?? $key))
                    . '" href="' . $h(self::url((string) $key)) . '">' . "\n";
            }
        }
        return $out;
    }

    /**
     * One live event as a calendar of one, or null when it is not one: not
     * live, not of this type, or with no start.
     */
    public static function forEvent(string $typeKey, string $slug): ?string
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT p.id, p.title, p.slug, p.content, p.excerpt, p.updated_at FROM posts p
             WHERE p.post_type = :pt AND p.slug = :slug AND ' . Post::live('p') . ' LIMIT 1'
        );
        $stmt->execute([':pt' => $typeKey, ':slug' => $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $event = self::vevent($typeKey, $row);
        return $event === null ? null : self::calendar([$event], null);
    }

    /** Every live event of a type with a start, as one calendar to subscribe to. */
    public static function forType(string $typeKey): string
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT p.id, p.title, p.slug, p.content, p.excerpt, p.updated_at FROM posts p
             WHERE p.post_type = :pt AND ' . Post::live('p') . ' ORDER BY p.id'
        );
        $stmt->execute([':pt' => $typeKey]);
        $events = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $event = self::vevent($typeKey, $row);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        $name = (string) Head::globalTokens()['site_name'] . ' · ' . (string) (Registry::get($typeKey)['label'] ?? $typeKey);
        return self::calendar($events, $name);
    }

    /** @param list<list<string>> $events each a VEVENT's lines */
    private static function calendar(array $events, ?string $name): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CMS//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
        if ($name !== null) {
            $lines[] = 'X-WR-CALNAME:' . self::text($name);
            $lines[] = 'X-WR-TIMEZONE:' . Dates::zone()->getName();
        }
        foreach ($events as $event) {
            array_push($lines, ...$event);
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /**
     * An event's VEVENT lines, or null when it has no start.
     *
     * @param array<string, mixed> $row
     * @return list<string>|null
     */
    private static function vevent(string $typeKey, array $row): ?array
    {
        $id      = (int) $row['id'];
        $startAt = trim((string) (Meta::get($id, 'event_start_date') ?? ''));
        $endAt   = trim((string) (Meta::get($id, 'event_end_date') ?? ''));
        $start   = Events::moment($startAt);
        if ($start === null) {
            return null;
        }
        $allDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startAt) === 1;
        $end    = $endAt !== '' ? Events::moment($endAt, $allDay) : null;
        $utc    = static fn (\DateTimeImmutable $at): string => $at->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $page   = Url::page(self::slugOf($typeKey) . '/' . (string) $row['slug']);
        $host   = (string) (parse_url($page, PHP_URL_HOST) ?: 'localhost');
        $where  = trim((string) (Meta::get($id, 'event_location') ?? ''));
        $about  = Post::summary($row, 500);
        // THE ENTRY'S OWN LAST CHANGE (stored in UTC), so a calendar that has
        // it already can tell an event that moved from one that did not.
        $stamp  = new \DateTimeImmutable((string) ($row['updated_at'] ?? 'now'), new \DateTimeZone('UTC'));

        $lines = ['BEGIN:VEVENT', 'UID:' . $typeKey . '-' . $id . '@' . $host, 'DTSTAMP:' . $utc($stamp)];
        if ($allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
            $last    = $end !== null && $end >= $start ? $end : $start;
            $lines[] = 'DTEND;VALUE=DATE:' . $last->modify('+1 day')->format('Ymd');
        } else {
            $lines[] = 'DTSTART:' . $utc($start);
            if ($end !== null && $end > $start) {
                $lines[] = 'DTEND:' . $utc($end);
            }
        }
        $lines[] = 'SUMMARY:' . self::text((string) $row['title']);
        if ($where !== '') {
            $lines[] = 'LOCATION:' . self::text($where);
        }
        $lines[] = 'URL:' . $page;
        $lines[] = 'DESCRIPTION:' . self::text(trim($about['text'] . ($about['cut'] ? '…' : '') . "\n\n" . $page));
        $lines[] = 'END:VEVENT';
        return $lines;
    }

    /** Text as iCalendar keeps it: a backslash, a comma, a semicolon and a line break escaped. */
    private static function text(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
    }

    /** A line of more than 75 bytes folded, as RFC 5545 asks, never inside a character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out  = '';
        $part = '';
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (strlen($part . $char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $part;
                $part = '';
            }
            $part .= $char;
        }
        return $out . ($part !== '' ? "\r\n " . $part : '');
    }

    private static function slugOf(string $typeKey): string
    {
        return (string) (Registry::get($typeKey)['public_slug'] ?? '');
    }
}
