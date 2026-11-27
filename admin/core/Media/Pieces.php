<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Models\Media;
use Cms\Models\MediaRejected;

/**
 * A FILE LARGER THAN ONE REQUEST, SENT IN PIECES.
 *
 * PHP takes no request larger than its own `post_max_size` and no file larger
 * than `upload_max_filesize`, often 2 or 8 MB on shared hosting, whatever the
 * site's own limit (`Media::maxFileSize()`). So a larger file comes in
 * pieces, each a request of its own (`/api/v1/media/upload/piece`, sent by
 * `CMS.upload()` in admin.js), each appended to a file of the account's own
 * outside the web site, in order, until the last makes it whole and it goes
 * into the library as an upload does (`Media::store()`), or takes a library
 * file's place as Replace does.
 *
 * WHAT IS CHECKED, AT EVERY PIECE: the upload's name (32 hex characters, the
 * browser's own random choice) and its account, so one account cannot add to
 * another's; that each piece is the next one; and that the file stays within
 * the site's limit, by what was sent and by what it said it would be. A file
 * whole is admitted by `Media::admit()` as any upload is, its type sniffed
 * from its bytes. Pieces of an upload that stopped are deleted after a day.
 */
final class Pieces
{
    /** The largest piece asked for, whatever PHP would take. */
    private const MOST = 8 * 1024 * 1024;

    /** The smallest: a host that takes less than this takes nothing worth the name. */
    private const LEAST = 256 * 1024;

    /** Room for the request's other fields beside the piece. */
    private const MARGIN = 64 * 1024;

    /** A day: the pieces of an upload that stopped. */
    private const STALE = 86400;

    /** The size each piece is sent at: what PHP takes in one request, less room for the rest. */
    public static function size(): int
    {
        $limit = min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size')));

        return max(self::LEAST, min(self::MOST, $limit - self::MARGIN));
    }

    /**
     * One piece kept: the attachment's id once the last makes the file whole,
     * null before.
     *
     * @param array{upload: string, index: int, count: int, name: string, size: int} $piece
     * @param callable(string, string): int $whole what is done with the file once it is whole,
     *        given its path and its name: the attachment's id (`Media::store()`, or Replace)
     */
    public static function keep(int $userId, array $piece, string $file, callable $whole): ?int
    {
        if (preg_match('/^[a-f0-9]{32}$/', $piece['upload']) !== 1) {
            throw new MediaRejected('That upload has no name the site can keep it under.');
        }
        $max = Media::maxFileSize();
        if ($piece['size'] < 1 || $piece['size'] > $max) {
            throw new MediaRejected(self::tooLarge());
        }
        if ($piece['count'] < 1 || $piece['index'] < 0 || $piece['index'] >= $piece['count']
            || $piece['count'] > (int) ceil($max / self::LEAST) + 1) {
            throw new MediaRejected('That piece is not one of the upload\'s.');
        }

        self::sweep();
        $dir   = self::dir();
        $base  = $dir . '/' . $userId . '-' . $piece['upload'];
        $state = is_file($base . '.json') ? json_decode((string) file_get_contents($base . '.json'), true) : null;
        if ($piece['index'] === 0) {
            @unlink($base . '.part');
            $state = ['name' => $piece['name'], 'size' => $piece['size'], 'count' => $piece['count'], 'next' => 0];
        }
        if (!is_array($state) || ($state['next'] ?? -1) !== $piece['index'] || ($state['count'] ?? 0) !== $piece['count']
            || ($state['size'] ?? 0) !== $piece['size']) {
            throw new MediaRejected('That piece came out of order. Send the file again.');
        }

        $bytes = (string) @file_get_contents($file);
        clearstatcache(true, $base . '.part');
        $have = is_file($base . '.part') ? (int) filesize($base . '.part') : 0;
        if ($have + strlen($bytes) > $piece['size']) {
            self::drop($base);
            throw new MediaRejected('The file is larger than it said it would be. Send it again.');
        }
        if (@file_put_contents($base . '.part', $bytes, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('A piece of the upload could not be kept.');
        }
        @chmod($base . '.part', 0640);

        if ($piece['index'] < $piece['count'] - 1) {
            $state['next'] = $piece['index'] + 1;
            file_put_contents($base . '.json', (string) json_encode($state), LOCK_EX);
            return null;
        }

        try {
            clearstatcache(true, $base . '.part');
            if ((int) filesize($base . '.part') !== $piece['size']) {
                throw new MediaRejected('The file did not arrive whole. Send it again.');
            }
            return $whole($base . '.part', (string) $state['name']);
        } finally {
            self::drop($base);
        }
    }

    /** What a file over the site's limit is told. */
    public static function tooLarge(): string
    {
        return 'File exceeds the ' . (int) round(Media::maxFileSize() / 1048576) . ' MB limit.';
    }

    /** Where the pieces wait: outside the web site, the account's own name on each. */
    private static function dir(): string
    {
        $dir = ROOT_PATH . '/pieces';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The folder for uploads in pieces could not be made.');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# Uploads arriving in pieces. Never served.\nRequire all denied\n");
        }

        return $dir;
    }

    /** An upload's pieces and its record, gone. */
    private static function drop(string $base): void
    {
        @unlink($base . '.part');
        @unlink($base . '.json');
    }

    /** The pieces of uploads that stopped a day ago or more, deleted. */
    private static function sweep(): void
    {
        // Two patterns, not GLOB_BRACE, which some systems' PHP lacks.
        foreach (array_merge(glob(self::dir() . '/*.part') ?: [], glob(self::dir() . '/*.json') ?: []) as $file) {
            if (time() - (int) @filemtime($file) > self::STALE) {
                @unlink($file);
            }
        }
    }

    /** "8M", "512K", "2G" as PHP writes a size, in bytes. */
    private static function bytes(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1' || $size === '0') {
            return self::MOST + self::MARGIN;
        }
        $n = (int) $size;

        return match (strtolower(substr($size, -1))) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => $n,
        };
    }
}
