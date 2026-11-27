<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Models\Meta;
use Cms\Models\Post;
use PDO;

/**
 * ANNOUNCEMENTS: urgent notices, promotions and closures, as content. Each is
 * an entry of a type with the Announcement archetype (`ArchetypeDefaults`),
 * which has no page of its own: its title and message, how it shows (a
 * banner across the top, a pop-up over the page, a slide-in at the corner,
 * or a slot the theme places), a tone, a link with its button's words, the
 * pages it shows on and those it never does, and the publish and unpublish
 * dates every entry has.
 *
 * WHERE THEY PRINT. `base.php` and the 404 page print `body()` straight after
 * `<body>`, as `Google::body()` is printed; a whole-page theme does the same,
 * and prints `slot('sidebar')` wherever it has a place of that name. Each
 * prints on public pages only, `body()` once a request.
 *
 * WHAT THE SCRIPT DOES (`www/assets/js/announcements.js`). Every one that
 * may show on the page is printed hidden, newest first; the script shows the
 * first in each place that has started and not ended by the visitor's clock
 * and that this visitor has not dismissed. A dismissal is kept in the browser
 * by the announcement's id and its version, which its last change makes, so
 * a changed message shows again. A pop-up shows once, and a pop-up or a
 * slide-in waits while the consent banner (`Google`) is asking.
 *
 * ON TIME FROM A PAGE KEPT. An announcement starting within a day is printed
 * with its start, and one with an end with its end, so a page a browser or a
 * proxy kept shows it and hides it on time; a static copy carries every one
 * still to come.
 *
 * A theme restyles them by their classes (`www/assets/css/announcements.css`)
 * or draws its own from `forPath()`.
 */
final class Announcements
{
    public const ARCHETYPE = 'announcement';

    /** How an announcement shows, and its words on the entry's form. */
    public const PLACEMENTS = [
        'banner'  => 'A banner across the top',
        'modal'   => 'A pop-up over the page',
        'slidein' => 'A slide-in at the corner',
        'slot'    => 'In a place the theme has',
    ];

    /** The tones, and their words: each is the colour of an edge, never a background. */
    public const TONES = ['info' => 'Information', 'success' => 'Good news', 'warning' => 'Notice', 'alert' => 'Urgent'];

    /** How far ahead an announcement is printed with its start, for a page kept that long. */
    private const AHEAD = 86400;

    /** The address's question a signed-in account previews one by: `/?announcement=12`. */
    public const PREVIEW = 'announcement';

    /** @var list<array<string, mixed>>|null this request's announcements, read once */
    private static ?array $live = null;

    private static bool $assets   = false;
    private static bool $bodyDone = false;

    /** The types whose entries are announcements. @return list<string> */
    public static function types(): array
    {
        return array_map('strval', array_keys(Registry::withArchetype(self::ARCHETYPE)));
    }

    /**
     * Every announcement live now or starting within a day (in a static copy,
     * every one still to come), in the page's language, newest first, each
     * with its fields read.
     *
     * @return list<array{id: int, title: string, content: string, placement: string, slot: string, tone: string,
     *                    link: string, button: string, show_on: string, never_on: string, starts: int, ends: ?int, version: string}>
     */
    public static function live(): array
    {
        if (self::$live !== null) {
            return self::$live;
        }
        $types = self::types();
        if ($types === []) {
            return self::$live = [];
        }
        $ahead = defined('CMS_EXPORT') ? '' : ' AND p.published_at <= UTC_TIMESTAMP() + INTERVAL ' . self::AHEAD . ' SECOND';
        $stmt  = Database::getInstance()->prepare(
            'SELECT p.id, p.title, p.content, p.published_at, p.unpublish_at, p.updated_at FROM posts p'
            . ' WHERE p.post_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')'
            . ' AND ' . Post::publishedSql('p') . $ahead
            . ' AND ' . Post::notEnded('p') . ' AND ' . Post::inLanguage('p')
            . ' ORDER BY p.published_at DESC, p.id DESC'
        );
        $stmt->execute($types);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $meta = Meta::getAllFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return self::$live = array_map(static fn (array $row): array => self::shape($row, $meta[(int) $row['id']] ?? []), $rows);
    }

    /**
     * THE ONE A SIGNED-IN ACCOUNT ASKED TO SEE (`?announcement=12`, the link
     * on its entry's screen), whatever its status and dates: shown on the page
     * whether or not it was dismissed, and never remembered. Null for anyone
     * signed out, as a private entry is.
     *
     * @return array<string, mixed>|null
     */
    public static function previewed(): ?array
    {
        $id = (int) (is_scalar($_GET[self::PREVIEW] ?? null) ? $_GET[self::PREVIEW] : 0);
        $types = self::types();
        if ($id <= 0 || $types === [] || !\Cms\Auth\Auth::check()) {
            return null;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT p.id, p.title, p.content, p.published_at, p.unpublish_at, p.updated_at FROM posts p'
            . ' WHERE p.id = ? AND p.status <> \'trash\' AND p.post_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')'
        );
        $stmt->execute(array_merge([$id], $types));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['preview' => true] + self::shape($row, Meta::getAll($id));
    }

    /**
     * The address that previews one: the first page its Shows on names, or
     * the front page, asking for it by its id.
     */
    public static function previewUrl(int $id, string $showOn = ''): string
    {
        $at = '/';
        foreach (preg_split('/\R/', $showOn) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_contains($line, '*') && preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $line) !== 1) {
                $at = '/' . ltrim($line, '/');
                break;
            }
        }

        return Url::resolve($at) . (str_contains($at, '?') ? '&' : '?') . self::PREVIEW . '=' . $id;
    }

    /**
     * A row of `posts` and its fields, as the rest of this class reads one.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private static function shape(array $row, array $m): array
    {
        $text      = static fn (string $key): string => trim(is_scalar($m[$key] ?? null) ? (string) $m[$key] : '');
        $placement = $text('announcement_placement');
        $tone      = $text('announcement_tone');
        $placement = isset(self::PLACEMENTS[$placement]) ? $placement : 'banner';

        return [
            'id'        => (int) $row['id'],
            'title'     => (string) $row['title'],
            'content'   => (string) ($row['content'] ?? ''),
            'placement' => $placement,
            'slot'      => $text('announcement_slot'),
            'tone'      => isset(self::TONES[$tone]) ? $tone : 'info',
            'link'      => $text('announcement_link'),
            'button'    => $text('announcement_button'),
            'show_on'   => $text('announcement_show_on'),
            'never_on'  => $text('announcement_never_on'),
            'starts'    => $row['published_at'] !== null ? self::epoch((string) $row['published_at']) : 0,
            'ends'      => $row['unpublish_at'] !== null ? self::epoch((string) $row['unpublish_at']) : null,
            // ITS VERSION IS ITS LAST CHANGE, with its words and its place:
            // a dismissal of the old one does not hide the new.
            'version'   => substr(md5(implode("\0", [(string) $row['updated_at'], (string) $row['title'],
                (string) $row['content'], $placement, $text('announcement_link'), $text('announcement_button')])), 0, 12),
        ];
    }

    /**
     * The announcements that show on this address: those whose Shows on names
     * it, or names nothing, and whose Never on does not.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPath(string $path): array
    {
        return array_values(array_filter(self::live(), static fn (array $a): bool =>
            ($a['show_on'] === '' || self::matches($path, $a['show_on'])) && !self::matches($path, $a['never_on'])));
    }

    /**
     * Does an address match a list of them, one a line? `/` is the front
     * page; `*` alone is every page; a line ending in `*` is every page under
     * what comes before it, and that page itself (`/events/*` is the events
     * list and every event). A full address counts as its path, and case and
     * a closing slash do not count.
     */
    public static function matches(string $path, string $lines): bool
    {
        $at = self::normal($path);
        foreach (preg_split('/\R/', $lines) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line === '*') {
                return true;
            }
            if (str_ends_with($line, '*')) {
                $stem = rtrim(self::normal(substr($line, 0, -1)), '/');
                if ($stem === '' || $at === $stem || str_starts_with($at, $stem . '/')) {
                    return true;
                }
                continue;
            }
            if ($at === self::normal($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A PAGE'S ANNOUNCEMENTS, for straight after `<body>`: every banner,
     * pop-up and slide-in that may show on it (a slot's are `slot()`'s), each
     * hidden until the script shows it, and the script and stylesheet. Once
     * a request, on public pages only; nothing on a page with none.
     */
    public static function body(?string $path = null): string
    {
        if (self::$bodyDone || !Google::isPublic()) {
            return '';
        }
        self::$bodyDone = true;

        // THE ONE PREVIEWED comes first in its place, and only once.
        $preview = self::previewed();
        $html    = $preview !== null && $preview['placement'] !== 'slot' ? self::markup($preview) : '';
        foreach (self::forPath($path ?? Google::path()) as $a) {
            if ($a['placement'] !== 'slot' && $a['id'] !== ($preview['id'] ?? null)) {
                $html .= self::markup($a);
            }
        }

        return $html === '' ? '' : self::assets() . $html;
    }

    /**
     * The announcements for a place the theme has, by its name: what a
     * template prints there (`<?= \Cms\Site\Announcements::slot('sidebar') ?>`).
     */
    public static function slot(string $name, ?string $path = null): string
    {
        if (!Google::isPublic()) {
            return '';
        }
        $preview = self::previewed();
        $html    = $preview !== null && $preview['placement'] === 'slot' && $preview['slot'] === $name ? self::markup($preview) : '';
        foreach (self::forPath($path ?? Google::path()) as $a) {
            if ($a['placement'] === 'slot' && $a['slot'] === $name && $a['id'] !== ($preview['id'] ?? null)) {
                $html .= self::markup($a);
            }
        }

        return $html === '' ? '' : self::assets() . $html;
    }

    /** Forget what this request read and printed, for one that draws a second page. */
    public static function reset(): void
    {
        self::$live     = null;
        self::$assets   = false;
        self::$bodyDone = false;
    }

    /**
     * One announcement, hidden. A pop-up is the browser's own dialog, shown
     * with showModal(): the focus kept inside it and Escape to close are the
     * browser's. A banner, a slide-in and a slot are regions of the page,
     * named by their titles, and printed first in it so a screen reader comes
     * to them first.
     *
     * @param array<string, mixed> $a
     */
    private static function markup(array $a): string
    {
        $e      = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $id     = 'cms-announcement-' . (int) $a['id'];
        $place  = (string) $a['placement'];
        $words  = trim(Post::renderedContent(['content' => (string) $a['content']]));
        $link   = $a['link'] !== '' ? Url::resolve((string) $a['link']) : '';
        $button = $a['button'] !== '' ? (string) $a['button'] : Words::of('announcement_more');
        $named  = ' aria-labelledby="' . $id . '-title"' . ($words !== '' ? ' aria-describedby="' . $id . '-message"' : '');
        $data   = ' data-cms-announcement="' . (int) $a['id'] . '" data-version="' . $e((string) $a['version']) . '"'
                . ' data-placement="' . $e($place) . '"' . ($place === 'slot' ? ' data-slot="' . $e((string) $a['slot']) . '"' : '')
                . ' data-starts="' . (int) $a['starts'] . '"' . ($a['ends'] !== null ? ' data-ends="' . (int) $a['ends'] . '"' : '')
                . (!empty($a['preview']) ? ' data-preview' : '');
        $class  = 'cms-announcement cms-announcement--' . $place . ' cms-announcement--' . $e((string) $a['tone']);
        $inner  = '<div class="cms-announcement__body">'
                . '<h2 class="cms-announcement__title" id="' . $id . '-title">' . $e((string) $a['title']) . '</h2>'
                . ($words !== '' ? '<div class="cms-announcement__message" id="' . $id . '-message">' . $words . '</div>' : '')
                . ($link !== '' ? '<p class="cms-announcement__action"><a class="cms-announcement__link" href="' . $e($link) . '">' . $e($button) . '</a></p>' : '')
                . '</div>'
                . '<button type="button" class="cms-announcement__close" data-cms-announcement-close aria-label="' . $e(Words::of('announcement_dismiss')) . '">'
                . '<span aria-hidden="true">&times;</span></button>';

        return $place === 'modal'
            ? '<dialog class="' . $class . '"' . $named . $data . '>' . $inner . '</dialog>'
            : '<div class="' . $class . '" role="region"' . $named . $data . ' hidden>' . $inner . '</div>';
    }

    /** The stylesheet and the script, once a page. */
    private static function assets(): string
    {
        if (self::$assets) {
            return '';
        }
        self::$assets = true;
        $at = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return '<link rel="stylesheet" href="' . $at(Asset::sitePath('assets/css/announcements.css')) . '">'
             . '<script src="' . $at(Asset::sitePath('assets/js/announcements.js')) . '" defer></script>';
    }

    /**
     * An address as a path from the site's root, in lower case, with no
     * closing slash. A full one, as copied from the browser, loses the
     * folder a site in one has, as the page's own path does (`Google::path()`).
     */
    private static function normal(string $address): string
    {
        $address = trim($address);
        $path    = (string) (parse_url($address, PHP_URL_PATH) ?? '');
        $base    = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        if ($base !== '' && preg_match('#^[a-z][a-z0-9+.-]*://#i', $address) === 1
            && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }

        return '/' . trim(mb_strtolower(rawurldecode($path)), '/');
    }

    /** A stored UTC moment as seconds since 1970. */
    private static function epoch(string $utc): int
    {
        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));

        return $at !== false ? $at->getTimestamp() : 0;
    }
}
