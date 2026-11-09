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
 * `Html\Sanitizer` keeps such a frame, as it keeps the other providers'
 * (`Html\Embed`), and removes every other.
 */
final class YouTube implements EmbedFrame
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
        return Embed::address($src, self::HOSTS, '#^/embed/[A-Za-z0-9_-]+/?$#');
    }

    public static function attributes(): array
    {
        return self::ATTRIBUTES;
    }

    /** YouTube's code writes no style, on its frame or round it. */
    public static function frameStyle(string $style, bool $wrapped): bool
    {
        return false;
    }

    public static function boxStyle(string $style): bool
    {
        return false;
    }

    /** Nor a script: the frame is the whole of it. */
    public static function scripts(): array
    {
        return [];
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
