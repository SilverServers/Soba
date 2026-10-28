<?php

declare(strict_types=1);

namespace Cms\Assistant;

/**
 * Progress for one in-flight assistant turn, readable by a second request.
 *
 * **Why this exists instead of Server-Sent Events.** A turn takes tens of
 * seconds, and the screen has to show something during it. The obvious answer is
 * to stream the events down the same response — and the code for that works, but
 * only if nothing between PHP and the browser buffers. Apache's `mod_proxy_fcgi`
 * can hold the entire FastCGI response and release it in one lump at the end;
 * the fix is a vhost directive, which a site's owner may have no permission to
 * write. Shared hosting is worse, not better, and the CMS is meant to run there.
 *
 * So the long request writes its progress here, and the browser reads it with a
 * second, short request that completes normally. Nothing has to stream. It works
 * behind any proxy, any buffer, any host.
 *
 * A file rather than a table: this is transient scratch data written several
 * times a second and thrown away minutes later, which is the one shape a database
 * is worst at. The writer appends one JSON object per line; the reader takes
 * everything past the line count it already has.
 *
 * The filename is an HMAC of the user id and turn id under `app_secret`, and the
 * reader re-derives it from its own session — so a turn's progress is unreadable
 * by anyone but the user who started it, even if they know the turn id.
 */
final class TurnProgress
{
    /** Abandoned turns older than this are swept on the next write. */
    private const MAX_AGE = 3600;

    private function __construct(
        private readonly string $path,
    ) {
    }

    /** Begin recording. Truncates anything already at this key. */
    public static function open(int $userId, string $turnId): self
    {
        $directory = self::directory();

        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        self::sweep($directory);

        $progress = new self(self::pathFor($directory, $userId, $turnId));
        @file_put_contents($progress->path, '');

        return $progress;
    }

    /** Append one event. Failures are silent — progress must never break a turn. */
    public function push(array $event): void
    {
        @file_put_contents(
            $this->path,
            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /** Mark the turn over, so a reader stops polling even if the final POST is slow. */
    public function finish(): void
    {
        $this->push(['type' => 'finished']);
    }

    public function discard(): void
    {
        @unlink($this->path);
    }

    /**
     * Read events recorded after the first `$from`.
     *
     * A missing file is not an error: the poll can easily beat the POST that
     * creates it, and "nothing yet" is the right answer either way.
     *
     * @return array{events: array<int, array<string, mixed>>, next: int, finished: bool}
     */
    public static function read(int $userId, string $turnId, int $from): array
    {
        $path = self::pathFor(self::directory(), $userId, $turnId);

        if (!is_file($path)) {
            return ['events' => [], 'next' => $from, 'finished' => false];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return ['events' => [], 'next' => $from, 'finished' => false];
        }

        $events   = [];
        $finished = false;

        foreach (array_slice($lines, max(0, $from)) as $line) {
            $event = json_decode($line, true);

            // A half-written final line is normal when the writer is mid-append.
            // Stop there and pick it up on the next poll rather than skipping it.
            if (!is_array($event)) {
                break;
            }

            if (($event['type'] ?? '') === 'finished') {
                $finished = true;
                continue;
            }

            $events[] = $event;
        }

        return [
            'events'   => $events,
            'next'     => max(0, $from) + count($events) + ($finished ? 1 : 0),
            'finished' => $finished,
        ];
    }

    // ----------------------------------------------------------------

    /**
     * Namespaced per install, so two CMS sites on one box cannot collide in
     * a shared /tmp.
     */
    private static function directory(): string
    {
        return sys_get_temp_dir() . '/cms-assistant-' . substr(sha1(ROOT_PATH), 0, 12);
    }

    private static function pathFor(string $directory, int $userId, string $turnId): string
    {
        $config = require ROOT_PATH . '/config/config.php';
        $secret = (string) ($config['app_secret'] ?? ROOT_PATH);

        return $directory . '/' . hash_hmac('sha256', $userId . ':' . $turnId, $secret) . '.jsonl';
    }

    /** Drop anything left behind by a turn that died mid-flight. */
    private static function sweep(string $directory): void
    {
        $cutoff = time() - self::MAX_AGE;

        foreach (glob($directory . '/*.jsonl') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
