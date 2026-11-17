<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Auth\Auth;

/**
 * THE PAGE AND ENTRY EDITORS' FORM, told what `keep-writing.js` needs: what
 * it keeps a copy of the writing under, how often to ask whether the sign-in
 * is still good, and what the last save came to. One place for both editors,
 * so the two cannot drift. The notices it shows are `partials/keep-writing.php`.
 *
 * AND WHO ELSE HAS IT OPEN (`Content\Presence`). Drawing the editor of a page
 * or entry that exists is opening it: the person is put in it, and the
 * screen is told who else is, and since when, for its notice. Asking while
 * somebody writes keeps them in it and answers again.
 */
final class KeepWriting
{
    /** @var list<array{name: string, opened_at: string, writing_at: ?string}> */
    private static array $others = [];
    /**
     * The form's attributes, each with a leading space.
     *
     * @param string      $key      "page:12", "news:new": the copy's name in the browser
     * @param bool        $saved    this screen is the answer to a save that worked
     * @param bool        $unsaved  this screen is drawn again with what was typed, unsaved
     * @param string|null $presence "page:12", "post:34": what is open, for one that exists
     */
    public static function attributes(string $key, bool $saved, bool $unsaved, ?string $presence = null): string
    {
        $open = '';
        if ($presence !== null && preg_match('/^(page|post):(\d+)$/', $presence, $m) === 1 && Auth::check()) {
            $me = (int) (Auth::user()['id'] ?? 0);
            \Cms\Content\Presence::open($m[1], (int) $m[2], $me);
            self::$others = \Cms\Content\Presence::others($m[1], (int) $m[2], $me);
            $open = ' data-keep-writing-presence="' . htmlspecialchars($presence) . '"'
                . ' data-keep-writing-opened="' . htmlspecialchars(gmdate('Y-m-d H:i:s')) . '"';
        }

        // A QUARTER OF THE SIGN-IN'S IDLE WINDOW, between half a minute and
        // five: asked that often while somebody types, the sign-in cannot run
        // out under them, and no oftener than it needs to be.
        $beat = max(30, min(300, intdiv(Auth::idleSeconds(), 4)));

        return ' data-keep-writing="' . htmlspecialchars($key) . '"'
            . ' data-keep-writing-ping="/api/v1/session"'
            . ' data-keep-writing-beat="' . $beat . '"'
            . ($saved ? ' data-keep-writing-saved' : '')
            . ($unsaved ? ' data-keep-writing-unsaved' : '')
            . $open;
    }

    /**
     * Who else had it open when it was drawn, as the notice says it: a name,
     * and when they opened it and last wrote in it, in the owner's format.
     *
     * @return list<array{name: string, opened: string, writing: ?string}>
     */
    public static function others(): array
    {
        return self::told(self::$others);
    }

    /**
     * People in an editor, as the notice and the session answer say them.
     *
     * @param  list<array{name: string, opened_at: string, writing_at: ?string}> $rows
     * @return list<array{name: string, opened: string, writing: ?string}>
     */
    public static function told(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'name'    => $r['name'],
            'opened'  => \Cms\Helpers\Dates::stamp($r['opened_at']),
            'writing' => $r['writing_at'] !== null ? \Cms\Helpers\Dates::stamp($r['writing_at']) : null,
        ], $rows);
    }
}
