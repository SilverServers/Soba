<?php

declare(strict_types=1);

namespace Cms\Site;

use RuntimeException;

/**
 * A COPY OF EACH BACKUP OFF THE SERVER, to S3-compatible storage named in
 * config.php, the newest few kept there. A backup kept only beside the site
 * does not survive the server it is on.
 *
 *     'backup_copy' => [
 *         'endpoint' => 'https://s3.us-west-002.backblazeb2.com',
 *         'region'   => 'us-west-002',
 *         'bucket'   => 'example-backups',
 *         'key'      => '…',             // the access key's id
 *         'secret'   => '…',             // and its secret
 *         'prefix'   => 'example.com/',  // optional: where in the bucket
 *         'keep'     => 8,               // optional: how many are kept there; Tools → Backups' own number otherwise
 *         'path_style' => false,         // optional: the bucket in the path, not the host (`S3`)
 *     ],
 *
 * CONFIG, NOT A SETTING, as `trusted_proxies` is: it holds a secret that
 * reaches every backup of the site, so it is written where the site's other
 * secrets are, and config.php is never in a backup.
 *
 * THE COPY IS PART OF THE BACKUP'S JOB (`Backups::step()`). Once the tar is
 * whole the backup is listed and can be downloaded, and its copy goes on in
 * the same turns, a part at a time (`step()`), so a large one survives
 * requests that may only run for seconds: a backup up to one part's size goes
 * up whole, a larger one as a multipart upload of 8 MiB parts. Once it is
 * there, copies beyond the number kept are deleted, oldest first, and only
 * files named as this site's backups are ever touched. A copy that fails says
 * why on Tools → Backups and in System health, and the backup itself is kept
 * as ever.
 */
final class BackupCopy
{
    /** A multipart upload's part: S3 takes 5 MiB and up, the last part smaller. */
    public const PART = 8 * 1024 * 1024;

    /** What a destination must name. */
    private const FIELDS = ['endpoint', 'region', 'bucket', 'key', 'secret'];

    /** A copy still to go up, or going. */
    public const UNDER_WAY = ['waiting', 'sending'];

    /** @return array<string, mixed>|null config.php's `backup_copy`, or null when it names none */
    public static function config(): ?array
    {
        $config = defined('ROOT_PATH') ? ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
        $copy   = is_array($config) ? ($config['backup_copy'] ?? null) : null;

        return is_array($copy) && $copy !== [] ? $copy : null;
    }

    /** What config.php's `backup_copy` lacks, in words; null when it is whole, or names none. */
    public static function problem(): ?string
    {
        $copy = self::config();
        if ($copy === null) {
            return null;
        }
        $missing = array_values(array_filter(self::FIELDS, static fn (string $f): bool => trim((string) ($copy[$f] ?? '')) === ''));
        if ($missing !== []) {
            return "config.php's backup_copy has no " . implode(', ', $missing);
        }
        if (preg_match('~^https?://[^/\s]+/?$~i', trim((string) $copy['endpoint'])) !== 1) {
            return "config.php's backup_copy endpoint is not an address such as https://s3.us-west-002.backblazeb2.com";
        }

        return null;
    }

    /** Whether a copy of each backup goes off the server. */
    public static function ready(): bool
    {
        return self::config() !== null && self::problem() === null;
    }

    /** Where they go, for a sentence: "example-backups at s3.us-west-002.backblazeb2.com"; '' when nowhere. */
    public static function label(): string
    {
        return self::ready() ? self::client()->label() : '';
    }

    /** How many are kept there: config.php's `keep`, else Tools → Backups' own number. */
    public static function keep(): int
    {
        $keep = (int) (self::config()['keep'] ?? 0);

        return $keep > 0 ? min($keep, 1000) : Backups::keep();
    }

    /** A backup's name there. */
    public static function object(string $id): string
    {
        return self::prefix() . $id . '.tar';
    }

    /**
     * Some seconds more of a job's copy, until `$until`: the whole backup, or
     * its next parts. The job's `copy` is changed in place, and the caller
     * saves the job. True once its copy needs nothing more.
     *
     * @param array<string, mixed> $job
     */
    public static function step(array &$job, float $until): bool
    {
        $copy = (array) ($job['copy'] ?? []);
        try {
            if (!self::ready()) {
                throw new RuntimeException(self::problem() ?? 'config.php names no backup_copy any more');
            }
            $s3     = self::client();
            $object = self::object((string) $job['id']);
            $file   = Backups::dir() . '/' . $job['id'] . '.tar';
            if (!is_file($file)) {
                throw new RuntimeException('the backup\'s file is not on the server');
            }
            $size = (int) filesize($file);
            if (($copy['state'] ?? '') === 'waiting') {
                if ($size <= self::PART) {
                    $s3->put($object, (string) file_get_contents($file));
                    $copy = self::copied($copy, $s3, $object);
                } else {
                    $copy = ['state' => 'sending', 'to' => $s3->label(), 'upload' => $s3->begin($object), 'etags' => [], 'offset' => 0];
                }
            }
            while (($copy['state'] ?? '') === 'sending' && microtime(true) < $until) {
                $copy['etags'][] = $s3->part($object, (string) $copy['upload'], count($copy['etags']) + 1,
                    self::read($file, (int) $copy['offset'], self::PART));
                $copy['offset'] = min($size, (int) $copy['offset'] + self::PART);
                if ($copy['offset'] >= $size) {
                    $s3->finish($object, (string) $copy['upload'], array_values($copy['etags']));
                    $copy = self::copied($copy, $s3, $object);
                }
                // Each part is kept as it goes up: a turn that stops here goes on from it.
                $job['copy'] = $copy;
            }
        } catch (\Throwable $e) {
            if (($copy['state'] ?? '') === 'sending' && is_string($copy['upload'] ?? null)) {
                self::abandon((string) $job['id'], (string) $copy['upload']);
            }
            $copy = ['state' => 'failed', 'to' => (string) ($copy['to'] ?? ''), 'at' => time(), 'error' => $e->getMessage()];
            error_log('CMS backup copy: ' . $e->getMessage());
        }
        $job['copy'] = $copy;

        return !in_array($copy['state'] ?? '', self::UNDER_WAY, true);
    }

    /** A multipart upload given up, its parts deleted there; quietly, should the storage not answer. */
    public static function abandon(string $id, string $upload): void
    {
        try {
            if (self::ready()) {
                self::client()->abort(self::object($id), $upload);
            }
        } catch (\Throwable $e) {
            error_log('CMS backup copy: an upload could not be given up: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $copy @return array<string, mixed> */
    private static function copied(array $copy, S3 $s3, string $object): array
    {
        $done = ['state' => 'copied', 'to' => $s3->label(), 'at' => time()];
        try {
            self::prune($s3, $object);
        } catch (\Throwable $e) {
            $done['note'] = 'the copies there beyond the newest ' . self::keep() . ' could not be deleted: ' . $e->getMessage();
        }

        return $done;
    }

    /**
     * The copies there beyond the newest kept deleted, oldest first: only
     * files named as this site's backups. A backup's name is the second it
     * began, so names order them, and the one just copied is the newest
     * whatever its name, should two have begun in one second.
     */
    private static function prune(S3 $s3, string $justCopied): void
    {
        $prefix = self::prefix();
        $mine   = [];
        foreach ($s3->list($prefix) as $found) {
            if ($found['key'] !== $justCopied && preg_match('~^\d{8}-\d{6}-[0-9a-f]{6}\.tar$~', substr($found['key'], strlen($prefix))) === 1) {
                $mine[] = $found['key'];
            }
        }
        rsort($mine, SORT_STRING);
        array_unshift($mine, $justCopied);
        foreach (array_slice($mine, self::keep()) as $old) {
            $s3->delete($old);
        }
    }

    private static function client(): S3
    {
        $copy = (array) self::config();

        return new S3(rtrim(trim((string) $copy['endpoint']), '/'), trim((string) $copy['region']), trim((string) $copy['bucket']),
            trim((string) $copy['key']), (string) $copy['secret'], ($copy['path_style'] ?? false) === true);
    }

    private static function prefix(): string
    {
        return ltrim(trim((string) (self::config()['prefix'] ?? '')), '/');
    }

    /** `$length` bytes of `$file` from `$offset`. */
    private static function read(string $file, int $offset, int $length): string
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('the backup\'s file could not be read');
        }
        try {
            fseek($handle, $offset);
            $out = '';
            while (strlen($out) < $length && !feof($handle)) {
                $chunk = fread($handle, $length - strlen($out));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $out .= $chunk;
            }
            return $out;
        } finally {
            fclose($handle);
        }
    }
}
