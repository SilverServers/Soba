<?php

declare(strict_types=1);

namespace Cms\Html;

/**
 * A YOUTUBE EMBED. A writer copies the video's embed code from YouTube (Share →
 * Embed) and pastes it into a body; the editor shows the video, saving keeps
 * the embed code, and every theme prints it as it is: YouTube's own player,
 * exactly as that code draws it on any other site.
 *
 * This says which `<iframe>` is one (`isEmbed()`), and which of its
 * attributes stay (`ATTRIBUTES`) — the ones YouTube's embed code writes.
 * `Html\Sanitizer` keeps such a frame and removes every other.
 */
final class YouTube
{
    /** The attributes YouTube's embed code writes on its <iframe>. Nothing else is kept. */
    public const ATTRIBUTES = ['width', 'height', 'src', 'title', 'frameborder', 'allow', 'referrerpolicy', 'allowfullscreen'];

    /** Where an earlier release's `[video]` is drawn from: YouTube's own embed address. */
    private const EMBED = 'https://www.youtube.com/embed/';

    /** YouTube's two embed hosts: the standard one and the privacy-enhanced one. */
    private const HOSTS = ['www.youtube.com', 'www.youtube-nocookie.com'];

    /**
     * Is this the `src` of a YouTube embed? https, one of YouTube's two embed
     * hosts, a path under `/embed/` — a single video, a `videoseries` playlist
     * or a `live_stream` — with whatever query YouTube put on it.
     */
    public static function isEmbed(string $src): bool
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '' || preg_match('/[\s"\'<>\\\\]/', $src) === 1) {
            return false;
        }
        $parts = parse_url($src);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true)
            && preg_match('#^/embed/[A-Za-z0-9_-]+/?$#', (string) ($parts['path'] ?? '')) === 1;
    }

    /**
     * A video an earlier release stored as `[video youtube="ID"]`, drawn as
     * YouTube's own embed code would draw it.
     */
    public static function iframe(string $id, int $start = 0, string $title = ''): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return '<iframe width="560" height="315" src="' . $e(self::EMBED . $id . ($start > 0 ? '?start=' . $start : '')) . '"'
            . ' title="' . $e($title !== '' ? $title : 'YouTube video player') . '" frameborder="0"'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"'
            . ' referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>';
    }
}
