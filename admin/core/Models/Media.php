<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use Cms\Media\Exif;
use Cms\Media\Optimizer;
use PDO;

class Media
{
    /**
     * HOW THE LIBRARY CAN BE ORDERED: the `sort` an address carries, and the
     * words the Sort by control shows for it. The first is the order when none
     * is asked for.
     */
    public const SORTS = [
        'added-desc'      => 'Date added, newest first',
        'added-asc'       => 'Date added, oldest first',
        'taken-desc'      => 'Date taken, newest first',
        'taken-asc'       => 'Date taken, oldest first',
        'name-asc'        => 'Name, A to Z',
        'name-desc'       => 'Name, Z to A',
        'size-desc'       => 'File size, largest first',
        'size-asc'        => 'File size, smallest first',
        'type'            => 'Type',
        'dimensions-desc' => 'Dimensions, largest first',
        'dimensions-asc'  => 'Dimensions, smallest first',
    ];

    public const DEFAULT_SORT = 'added-desc';

    /**
     * Each order as SQL. EVERY ONE ENDS ON THE ID, so the order is total: an
     * archive imported in one go shares its date added to the second, and
     * without a last key the database may order those rows differently for
     * page 2 than it did for page 1, showing a file twice and another never.
     *
     *   taken       the date taken where it is known, else the date added
     *   name        the name the tile shows: the title, or the stored name
     *               when the title is empty
     *   type        by type, and the newest first within one
     *   dimensions  by area; a file with none (a document, an SVG) comes last
     *               either way
     */
    private const ORDERS = [
        'added-desc'      => 'created_at DESC, id DESC',
        'added-asc'       => 'created_at ASC, id ASC',
        'taken-desc'      => 'COALESCE(taken_at, created_at) DESC, id DESC',
        'taken-asc'       => 'COALESCE(taken_at, created_at) ASC, id ASC',
        'name-asc'        => "CASE WHEN original_name = '' THEN filename ELSE original_name END ASC, id ASC",
        'name-desc'       => "CASE WHEN original_name = '' THEN filename ELSE original_name END DESC, id DESC",
        'size-desc'       => 'file_size DESC, id DESC',
        'size-asc'        => 'file_size ASC, id ASC',
        'type'            => 'mime_type ASC, created_at DESC, id DESC',
        'dimensions-desc' => '(width IS NULL OR height IS NULL) ASC, width * height DESC, id DESC',
        'dimensions-asc'  => '(width IS NULL OR height IS NULL) ASC, width * height ASC, id ASC',
    ];

    /**
     * Return a paginated, filtered list of attachments, in the order asked for
     * (`SORTS`; newest first when none is, or one this does not know).
     *
     * @param int         $page
     * @param int         $perPage
     * @param string|null $type       'image' | 'document' | null (all)
     * @param int|null    $folderId   null = all folders, 0 = root/uncategorized, >0 = specific folder
     * @param string|null $visibility 'public' | 'private' | null (all)
     * @param string|null $search     Search original_name
     * @param string      $sort       a key of `SORTS`
     * @return array{ items: array<int, array<string, mixed>>, total: int }
     */
    public static function all(
        int $page = 1,
        int $perPage = 40,
        ?string $type = null,
        ?int $folderId = null,
        ?string $visibility = null,
        ?string $search = null,
        string $sort = self::DEFAULT_SORT
    ): array {
        $pdo    = Database::getInstance();
        $offset = ($page - 1) * $perPage;

        $where  = [];
        $params = [];

        // Type filter
        if ($type === 'image') {
            $where[] = "mime_type LIKE 'image/%'";
        } elseif ($type === 'document') {
            $where[] = "mime_type NOT LIKE 'image/%'";
        }

        // Folder filter
        if ($folderId === 0) {
            $where[] = 'folder_id IS NULL';
        } elseif ($folderId !== null && $folderId > 0) {
            $where[]              = 'folder_id = :folder_id';
            $params[':folder_id'] = $folderId;
        }

        // Visibility filter
        if ($visibility !== null) {
            $where[]               = 'visibility = :visibility';
            $params[':visibility'] = $visibility;
        }

        // Search filter
        if ($search !== null) {
            $where[]           = 'original_name LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM attachments {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch page. The ORDER BY is one of `ORDERS`, never anything asked for.
        $orderSql = self::ORDERS[$sort] ?? self::ORDERS[self::DEFAULT_SORT];
        $stmt = $pdo->prepare(
            "SELECT * FROM attachments {$whereSql} ORDER BY {$orderSql} LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The whole library's counts, whatever is filtered: every file, and the
     * files in no folder. The folder row's All and Unfiled.
     *
     * @return array{total: int, unfiled: int}
     */
    public static function tally(): array
    {
        $row = Database::getInstance()
            ->query('SELECT COUNT(*) AS total, COALESCE(SUM(folder_id IS NULL), 0) AS unfiled FROM attachments')
            ->fetch(PDO::FETCH_ASSOC);

        return ['total' => (int) ($row['total'] ?? 0), 'unfiled' => (int) ($row['unfiled'] ?? 0)];
    }

    /**
     * Find a single attachment by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM attachments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * WHAT THE LIBRARY TAKES — images and office documents.
     *
     * Moved here from `Api\MediaController`, which held this list, the size
     * limit and the type sniff twice — once for the uploader, once for
     * Replace — so a third door (`importFile()`) would have been a third copy.
     * One list, one limit, one sniff: `admit()`.
     */
    public const ALLOWED_MIMES = [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/csv',
        'text/plain',
        'application/zip',
    ];

    /** Ten megabytes — for every door. */
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * WHETHER A FILE MAY ENTER THE LIBRARY, and what it is — the uploader's,
     * Replace's and `importFile()`'s one rule.
     *
     * The type is SNIFFED from the bytes (`finfo`), never taken from a name or
     * a browser's say-so. A refusal is a `MediaRejected` whose message is a
     * sentence for the person who chose the file.
     *
     * @return array{mime_type: string, file_size: int, width: ?int, height: ?int}
     */
    public static function admit(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MediaRejected('That file could not be read.');
        }
        $size = (int) filesize($path);
        if ($size > self::MAX_FILE_SIZE) {
            throw new MediaRejected('File exceeds the 10 MB limit.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo !== false ? (string) finfo_file($finfo, $path) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new MediaRejected('File type not allowed. Accepted: images (JPEG, PNG, GIF, WebP, SVG) '
                . 'and documents (PDF, DOC, XLS, PPT, CSV, TXT, ZIP).');
        }

        // Dimensions for an image — not an SVG, which `getimagesize()` cannot read.
        $width = $height = null;
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            $dims = @getimagesize($path);
            if ($dims !== false) {
                [$width, $height] = [(int) $dims[0], (int) $dims[1]];
            }
        }

        return ['mime_type' => $mime, 'file_size' => $size, 'width' => $width, 'height' => $height];
    }

    /** The name a file is kept under: unique, and safe on disk and in an address. */
    public static function storedName(string $originalName): string
    {
        $safe = ltrim((string) (preg_replace('/[^a-z0-9._-]/', '', strtolower($originalName)) ?? ''), '.');

        return uniqid('', true) . '_' . ($safe !== '' ? $safe : 'upload');
    }

    /** Where a file of this visibility lives. Private is never served directly. */
    public static function dirFor(string $visibility): string
    {
        return PUBLIC_PATH . ($visibility === 'private' ? '/uploads/private' : '/uploads');
    }

    /**
     * A FILE FROM THIS SERVER'S DISK, into the library — the same
     * row and the same file an upload makes, so nothing downstream can tell
     * them apart. The source is COPIED and left where it is: a migration's
     * `www/images/…` keeps answering at the address it always had.
     *
     * ITS DATES CAN COME WITH IT. A migration moving another site's files
     * carries that site's dates rather than dating every file today, which
     * would put an archive above everything added since:
     *
     *   created_at  when the file was added (the source site's upload date).
     *               Left out, it is now.
     *   taken_at    when the photo was taken. Left out, it is read from the
     *               file's EXIF (`Media\Exif`); given as null, it is not known.
     *
     * Each is anything `when()` reads: a `\DateTimeInterface`, a timestamp, or
     * a string such as `2019-07-04 21:30:00`, which is UTC, the form the column
     * holds, unless it names its own offset.
     *
     * @param array{original_name?: string, folder_id?: ?int, visibility?: string, alt_text?: ?string,
     *              created_at?: \DateTimeInterface|int|string|null, taken_at?: \DateTimeInterface|int|string|null} $opts
     * @throws MediaRejected               the file was refused
     * @throws \InvalidArgumentException   a date that is not one; nothing was stored
     * @throws \RuntimeException           it could not be saved
     */
    public static function importFile(string $path, array $opts = []): int
    {
        return self::store($path, (string) ($opts['original_name'] ?? basename($path)), $opts, false);
    }

    /**
     * Admit, place and record — every door's last step. `$uploaded` means an
     * HTTP upload's temporary file, which only `move_uploaded_file()` may
     * move; anything else is copied.
     *
     * @param array{folder_id?: ?int, visibility?: string, alt_text?: ?string,
     *              created_at?: \DateTimeInterface|int|string|null, taken_at?: \DateTimeInterface|int|string|null} $opts
     */
    public static function store(string $path, string $originalName, array $opts = [], bool $uploaded = false): int
    {
        $facts      = self::admit($path);
        $visibility = ($opts['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $dir        = self::dirFor($visibility);
        $filename   = self::storedName($originalName);

        // ITS DATES, BEFORE ANYTHING IS PLACED: a date that is not one stops
        // here, with no file left behind. The date taken is read from the file
        // as it came, because the copy kept below has no EXIF left.
        $added = self::when($opts['created_at'] ?? null);
        $taken = array_key_exists('taken_at', $opts)
            ? self::when($opts['taken_at'])
            : Exif::taken($path, $facts['mime_type']);

        // A NEW IMAGE IS STORED AS WEBP (`Optimizer`): its own name, ending .webp.
        $webp = Optimizer::webp($path, $facts['mime_type']);
        if ($webp !== null) {
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
            $facts = ['mime_type' => 'image/webp', 'file_size' => strlen($webp['bytes']),
                      'width' => $webp['width'], 'height' => $webp['height']];
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The uploads folder could not be created.');
        }
        $dest  = $dir . '/' . $filename;
        $saved = $webp !== null
            ? @file_put_contents($dest, $webp['bytes']) === strlen($webp['bytes'])
            : ($uploaded ? @move_uploaded_file($path, $dest) : @copy($path, $dest));
        if (!$saved) {
            @unlink($dest);
            throw new \RuntimeException('Failed to save the file.');
        }

        // A ROW OR NO FILE. A file with no row is invisible in the library and
        // never deleted by it.
        try {
            $id = self::create([
                'filename'      => $filename,
                'original_name' => $originalName,
                'mime_type'     => $facts['mime_type'],
                'file_size'     => $facts['file_size'],
                'width'         => $facts['width'],
                'height'        => $facts['height'],
                'folder_id'     => $opts['folder_id'] ?? null,
                'visibility'    => $visibility,
                'alt_text'      => $opts['alt_text'] ?? null,
                'taken_at'      => $taken,
                'created_at'    => $added,
            ]);
        } catch (\Throwable $e) {
            @unlink($dest);
            throw $e;
        }
        // Its smaller widths, for a browser to choose from (`Sizes`).
        \Cms\Media\Sizes::make((array) self::find($id));

        return $id;
    }

    /**
     * A MOMENT TO STORE, from what an import or the command line says: a
     * `\DateTimeInterface`, a Unix timestamp, or a string in one of these
     * shapes:
     *
     *   2019-07-04 21:30:05            (or 21:30, or with a T for the space)
     *   2019-07-04                     midnight
     *   2019-07-04T14:30:05-07:00      with its own offset, or Z
     *
     * A string with no offset is UTC: the form `created_at` itself holds, so a
     * date read from another site's database, or printed by
     * `admin/bin/media-dates.php`, goes back in as it is. Nothing looser is read:
     * "next friday" and 31 February are not dates here. Returned in UTC, as
     * every stored moment is; null for null or ''.
     *
     * @throws \InvalidArgumentException  not a date, or one outside the years 1000 to 9999
     */
    public static function when(\DateTimeInterface|int|string|null $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $at = null;
        if ($value instanceof \DateTimeInterface) {
            $at = \DateTimeImmutable::createFromInterface($value);
        } elseif (is_int($value)) {
            $at = new \DateTimeImmutable('@' . $value);
        } else {
            $text = trim($value);
            $own  = preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $text) === 1;
            $text = $own ? (string) preg_replace('/Z$/', '+00:00', $text) : $text;
            $shapes = $own
                ? ['Y-m-d\\TH:i:sP', 'Y-m-d H:i:sP', 'Y-m-d\\TH:iP', 'Y-m-d H:iP']
                : ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d'];
            foreach ($shapes as $shape) {
                $try  = \DateTimeImmutable::createFromFormat('!' . $shape, $text, $own ? null : new \DateTimeZone('UTC'));
                $errs = \DateTimeImmutable::getLastErrors();
                // 31 February parses, rolled into March with a warning: a date
                // that had to be corrected is not the date given.
                if ($try !== false && ($errs === false || ($errs['warning_count'] === 0 && $errs['error_count'] === 0))) {
                    $at = $try;
                    break;
                }
            }
        }
        $utc  = $at?->setTimezone(new \DateTimeZone('UTC'));
        $year = $utc === null ? 0 : (int) $utc->format('Y');
        if ($utc === null || $year < 1000 || $year > 9999) {
            $shown = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : (string) $value;
            throw new \InvalidArgumentException('Not a date: "' . $shown . '". Write it as 2019-07-04 or 2019-07-04 14:30.');
        }

        return $utc->format('Y-m-d H:i:s');
    }

    /**
     * Insert a new attachment record and return the new ID.
     *
     * `taken_at` and `created_at` are stored UTC moments (`when()`); with no
     * `created_at` the row is dated now, as the column's default dates it.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO attachments
                (filename, original_name, mime_type, file_size, width, height, folder_id, visibility, alt_text,
                 taken_at, created_at)
             VALUES
                (:filename, :original_name, :mime_type, :file_size, :width, :height, :folder_id, :visibility, :alt_text,
                 :taken_at, COALESCE(:created_at, CURRENT_TIMESTAMP))'
        );
        $stmt->execute([
            ':filename'      => $data['filename'],
            ':original_name' => $data['original_name'],
            ':mime_type'     => $data['mime_type'],
            ':file_size'     => $data['file_size'],
            ':width'         => $data['width'] ?? null,
            ':height'        => $data['height'] ?? null,
            ':folder_id'     => $data['folder_id'] ?? null,
            ':visibility'    => $data['visibility'] ?? 'public',
            ':alt_text'      => $data['alt_text'] ?? null,
            ':taken_at'      => $data['taken_at'] ?? null,
            ':created_at'    => $data['created_at'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Update attachment metadata.
     *
     * The two dates are stored UTC moments (`when()`): `taken_at` may be null
     * (not known), `created_at` may not.
     *
     * @param array<string, mixed> $data  Accepted keys: original_name, alt_text, folder_id, visibility,
     *                                    taken_at, created_at
     */
    public static function update(int $id, array $data): bool
    {
        $pdo     = Database::getInstance();
        $fields  = [];
        $params  = [':id' => $id];
        $allowed = ['original_name', 'alt_text', 'folder_id', 'visibility', 'taken_at', 'created_at'];
        if (array_key_exists('created_at', $data) && $data['created_at'] === null) {
            unset($data['created_at']);
        }

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $fields[]       = "{$key} = :{$key}";
                $params[":{$key}"] = $data[$key];
            }
        }

        if ($fields === []) {
            return true;
        }

        $stmt = $pdo->prepare(
            'UPDATE attachments SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        return $stmt->execute($params);
    }

    /**
     * Replace the file for an attachment record.
     * The caller is responsible for creating a MediaVersion snapshot first
     * and for storing the new physical file on disk.
     *
     * @param array<string, mixed> $newFile  Keys: filename, original_name, mime_type, file_size, width, height
     */
    public static function replace(int $id, array $newFile): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE attachments
             SET filename = :filename, original_name = :original_name, mime_type = :mime_type,
                 file_size = :file_size, width = :width, height = :height
             WHERE id = :id'
        );
        return $stmt->execute([
            ':filename'      => $newFile['filename'],
            ':original_name' => $newFile['original_name'],
            ':mime_type'     => $newFile['mime_type'],
            ':file_size'     => $newFile['file_size'],
            ':width'         => $newFile['width'] ?? null,
            ':height'        => $newFile['height'] ?? null,
            ':id'            => $id,
        ]);
    }

    /**
     * Delete an attachment, its physical file, and all version files.
     */
    public static function delete(int $id): bool
    {
        $attachment = self::find($id);
        if ($attachment === null) {
            return false;
        }

        // Delete physical file, and its smaller widths
        \Cms\Media\Sizes::forget($attachment);
        $filePath = self::getFilePath($attachment);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete version files
        $versions = MediaVersion::allForAttachment($id);
        $dir      = self::getDirectory($attachment['visibility'] ?? 'public');
        foreach ($versions as $version) {
            $versionPath = $dir . $version['filename'];
            if (file_exists($versionPath)) {
                unlink($versionPath);
            }
        }

        // Delete DB record (version records cascade via FK)
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM attachments WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Change visibility and physically move the file between directories.
     */
    public static function changeVisibility(int $id, string $newVisibility): bool
    {
        $attachment = self::find($id);
        if ($attachment === null) {
            return false;
        }

        $oldVisibility = $attachment['visibility'] ?? 'public';
        if ($oldVisibility === $newVisibility) {
            return true;
        }

        // A private file has no smaller widths at a public address; a file
        // made public gets them below.
        \Cms\Media\Sizes::forget($attachment);
        $oldDir = self::getDirectory($oldVisibility);
        $newDir = self::getDirectory($newVisibility);

        // Ensure target directory exists
        if (!is_dir($newDir)) {
            mkdir($newDir, 0775, true);
        }

        // Move the current file
        $oldPath = $oldDir . $attachment['filename'];
        $newPath = $newDir . $attachment['filename'];
        if (file_exists($oldPath)) {
            if (!rename($oldPath, $newPath)) {
                return false;
            }
        }

        // Move version files too
        $versions = MediaVersion::allForAttachment($id);
        foreach ($versions as $version) {
            $vOld = $oldDir . $version['filename'];
            $vNew = $newDir . $version['filename'];
            // A version lives in `versions/` below its file's folder, which the
            // other side may not have yet: without it the older copy of a file
            // made private stayed at its public address.
            if (file_exists($vOld)) {
                if (!is_dir(dirname($vNew))) {
                    @mkdir(dirname($vNew), 0775, true);
                }
                rename($vOld, $vNew);
            }
        }

        // Update DB record
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE attachments SET visibility = :vis WHERE id = :id');
        $done = $stmt->execute([':vis' => $newVisibility, ':id' => $id]);
        if ($done && $newVisibility === 'public') {
            \Cms\Media\Sizes::make((array) self::find($id));
        }

        return $done;
    }

    /**
     * Resolve an attachment ID to a full public URL.
     * Returns an empty string if the ID is 0, null, or not found.
     * Private files are routed through the PHP file controller.
     */
    public static function getUrl(int|string|null $id): string
    {
        if (empty($id)) {
            return '';
        }
        $row = self::find((int) $id);
        if ($row === null) {
            return '';
        }
        if (($row['visibility'] ?? 'public') === 'private') {
            return BASE_URL . '/files/' . $row['id'];
        }
        return BASE_URL . '/uploads/' . $row['filename'];
    }

    /**
     * Get the physical filesystem path for an attachment.
     */
    public static function getFilePath(array $attachment): string
    {
        return self::getDirectory($attachment['visibility'] ?? 'public') . $attachment['filename'];
    }

    /**
     * Get the upload directory path for a given visibility.
     */
    private static function getDirectory(string $visibility): string
    {
        return $visibility === 'private'
            ? PUBLIC_PATH . '/uploads/private/'
            : PUBLIC_PATH . '/uploads/';
    }
}
