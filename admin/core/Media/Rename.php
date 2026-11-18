<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Database\Database;
use Cms\Models\Media;

/**
 * A LIBRARY FILE GIVEN ANOTHER NAME (`Models\Media::rename()`): the file and
 * its width copies renamed where they are kept, its row, and every place the
 * site keeps its address — bodies, excerpts, field values, descriptions,
 * settings, forms, menus, earlier revisions and redirects — changed to the
 * new one, with a redirect from the old address for links from elsewhere.
 *
 * The name is the file's own name made fit for an address, as a new upload's
 * is (`Media::storedName()`), its kind kept: a picture stays .webp. A name
 * another file has takes -2, -3 … (`Media::claimName()`). A file the site
 * already had keeps its name and its address (`Media::register()`).
 *
 * FILES FIRST, THEN THE DATABASE IN ONE GO. A file that cannot be moved puts
 * back every one moved before it and changes nothing else; a database that
 * refuses puts the files back too.
 */
final class Rename
{
    /** Where the site keeps text that may hold a file's address: table => [its key, the columns]. */
    private const STORES = [
        'pages'      => ['id', ['content']],
        'posts'      => ['id', ['content', 'excerpt']],
        'post_meta'  => ['id', ['meta_value']],
        'terms'      => ['id', ['description']],
        'galleries'  => ['id', ['description']],
        'options'    => ['option_key', ['option_value']],
        'forms'      => ['id', ['fields', 'settings']],
        'menu_items' => ['id', ['url', 'settings']],
        'revisions'  => ['id', ['content', 'excerpt', 'meta_json']],
        'redirects'  => ['id', ['to_url']],
    ];

    /** Why the file could not be renamed, or null when it was (or already had that name). */
    public static function to(int $id, string $name): ?string
    {
        $row = Media::find($id);
        if ($row === null) {
            return 'That file is not in the library.';
        }
        if (Media::isSiteFile($row)) {
            return 'This is the site\'s own file, at its own address: it keeps its name.';
        }
        $old = (string) $row['filename'];
        $ext = pathinfo($old, PATHINFO_EXTENSION);
        $typed = trim($name);
        if ($ext !== '' && str_ends_with(strtolower($typed), '.' . strtolower($ext))) {
            $typed = substr($typed, 0, -strlen($ext) - 1);
        }
        if (trim($typed, " .-_\t") === '') {
            return 'A file needs a name.';
        }
        // The whole of what was typed is the name: "garden.gate" is garden-gate.
        $wanted = Media::storedName(str_replace('.', '-', $typed) . ($ext !== '' ? '.' . $ext : ''), $ext !== '' ? $ext : null);
        if ($wanted === $old) {
            return null;
        }

        $dir = rtrim(Media::dirFor((string) ($row['visibility'] ?? 'public')), '/');
        try {
            $new = Media::claimName($dir, $wanted, $ext !== '' ? $ext : null);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        // ── The files: the file, then each width copy, named from the new stem.
        $moves = [[$old, $new]];
        $sizes = Sizes::of($row);
        $stemOld = pathinfo($old, PATHINFO_FILENAME);
        $stemNew = pathinfo($new, PATHINFO_FILENAME);
        foreach ($sizes as $i => $size) {
            $copy = (string) $size['file'];
            if (str_starts_with($copy, $stemOld . '-')) {
                $sizes[$i]['file'] = $stemNew . substr($copy, strlen($stemOld));
                $moves[] = [$copy, $sizes[$i]['file']];
            }
        }
        $done = [];
        foreach ($moves as [$from, $to]) {
            if (!is_file($dir . '/' . $from) || !@rename($dir . '/' . $from, $dir . '/' . $to)) {
                self::undo($dir, $done, $new);
                return 'The file could not be renamed.';
            }
            $done[] = [$from, $to];
        }

        // ── The database, in one go: the row, every address kept, a redirect.
        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE attachments SET filename = :f, sizes = :s WHERE id = :id')->execute([
                ':f'  => $new,
                ':s'  => $sizes === [] ? $row['sizes'] : json_encode(array_values($sizes), JSON_UNESCAPED_SLASHES),
                ':id' => $id,
            ]);
            if (($row['visibility'] ?? 'public') === 'public') {
                $pairs = [];
                foreach ($moves as [$from, $to]) {
                    $pairs['/uploads/' . $from] = '/uploads/' . $to;
                }
                self::rewrite($pdo, $pairs);
                // No rule may start where the file is now (an earlier name of
                // the same file, renamed back): it would send it on to itself.
                $pdo->prepare('DELETE FROM redirects WHERE from_path = :at')->execute([':at' => '/uploads/' . $new]);
                \Cms\SEO\Redirects::create('/uploads/' . $old, '/uploads/' . $new, 301);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::undo($dir, $done, null);
            error_log('CMS media: renaming ' . $old . ' failed: ' . $e->getMessage());
            return 'The file could not be renamed.';
        }

        return null;
    }

    /**
     * Every kept text naming one of `$pairs`' addresses, with the new one in
     * its place: written as it is, or inside JSON (`\/uploads\/…`), and never
     * a longer name that starts the same.
     *
     * @param array<string, string> $pairs old address => new
     */
    private static function rewrite(\PDO $pdo, array $pairs): void
    {
        $find = [];
        foreach ($pairs as $from => $to) {
            $find[] = [self::pattern($from), $to];
            $find[] = [self::pattern(str_replace('/', '\\/', $from)), str_replace('/', '\\/', $to)];
        }
        $stem = pathinfo((string) array_key_first($pairs), PATHINFO_FILENAME);
        foreach (self::STORES as $table => [$key, $columns]) {
            foreach ($columns as $column) {
                $rows = $pdo->prepare("SELECT `{$key}` AS k, `{$column}` AS v FROM `{$table}` WHERE `{$column}` LIKE :like");
                $rows->execute([':like' => '%' . addcslashes($stem, '%_\\') . '%']);
                $update = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = :v WHERE `{$key}` = :k");
                foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $one) {
                    $text = (string) $one['v'];
                    $was  = $text;
                    foreach ($find as [$pattern, $to]) {
                        $text = (string) preg_replace_callback($pattern, static fn (): string => $to, $text);
                    }
                    if ($text !== $was) {
                        $update->execute([':v' => $text, ':k' => $one['k']]);
                    }
                }
            }
        }
    }

    /** An address as it is written, not followed by more of a name. */
    private static function pattern(string $address): string
    {
        return '~' . preg_quote($address, '~') . '(?![A-Za-z0-9._%-])~';
    }

    /** @param list<array{0: string, 1: string}> $done */
    private static function undo(string $dir, array $done, ?string $claimed): void
    {
        foreach (array_reverse($done) as [$from, $to]) {
            @rename($dir . '/' . $to, $dir . '/' . $from);
        }
        if ($claimed !== null && $done === [] && is_file($dir . '/' . $claimed) && filesize($dir . '/' . $claimed) === 0) {
            @unlink($dir . '/' . $claimed);
        }
    }
}
