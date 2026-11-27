<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
// ── CONTENT IS SANITISED HERE, AT THE MODEL, AND NOT IN THE CONTROLLER ──────
// `templates/page.php` emits content UNESCAPED — deliberately, because a body is
// meant to contain markup — so whatever is in this column reaches every
// visitor's browser verbatim.
//
// Several paths write it — the admin's own screens, the assistant's applier,
// import. Sanitising in the controllers would mean the guarantee holds for the
// paths somebody remembered, and a new writer added next year is a hole nobody
// notices — this is "one path in" applied to safety rather than to management.
//
// `Sanitizer::clean()` is idempotent, so cleaning content that is already
// clean costs a parse and changes nothing.
use Cms\Html\Sanitizer;
use PDO;

/**
 * Page model — hierarchical static pages (About, Contact, Services, etc.)
 * Separate from the posts/CPT system. No custom fields in v1.
 */
class Page
{
    /**
     * Return all pages as a flat list ordered by menu_order, then title.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT p.*, " . \Cms\Models\User::nameSql('u') . " AS author_name,
                    parent.title AS parent_title
             FROM pages p
             JOIN users u ON u.id = p.author_id
             LEFT JOIN pages parent ON parent.id = p.parent_id
             WHERE p.status != 'trash'
             ORDER BY p.menu_order ASC, p.title ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all trashed pages, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allTrashed(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT p.*, " . \Cms\Models\User::nameSql('u') . " AS author_name,
                    parent.title AS parent_title
             FROM pages p
             JOIN users u ON u.id = p.author_id
             LEFT JOIN pages parent ON parent.id = p.parent_id
             WHERE p.status = 'trash'
             ORDER BY p.created_at DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count trashed pages.
     */
    public static function countTrashed(): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query("SELECT COUNT(*) FROM pages WHERE status = 'trash'");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Move a page to the trash (sets status = 'trash').
     */
    public static function trash(int $id): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE pages SET status = 'trash' WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Restore a page from the trash (sets status = 'private').
     */
    public static function restore(int $id): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE pages SET status = 'private' WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Find a page by ID. Returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT p.*, ' . \Cms\Models\User::nameSql('u') . ' AS author_name
             FROM pages p
             JOIN users u ON u.id = p.author_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a live page by slug for the public frontend.
     * Returns pages with status published, password, or private.
     *
     * Its `author_name` is the PUBLIC byline (`User::bylineSql()`), as on every
     * public query in `Post`: this row is what the page's JSON-LD and the theme
     * are built from, and with `nameSql()` here an author with no display name
     * put their sign-in name in the source of every page they wrote.
     *
     * A slug is unique within its language: the one in `$lang`, or, when it is
     * null, in the page being drawn's (`Post::inLanguage()`).
     *
     * @return array<string, mixed>|null
     */
    public static function findBySlug(string $slug, ?string $lang = null): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT p.*, " . \Cms\Models\User::bylineSql('u') . " AS author_name
             FROM pages p
             JOIN users u ON u.id = p.author_id
             WHERE p.slug = :slug AND p.status IN ('published', 'password', 'private')
               AND " . \Cms\Models\Post::inLanguage('p', $lang) . "
             LIMIT 1"
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Insert a new page. Returns the new page's ID.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO pages
                 (parent_id, title, slug, lang, translation_of, content, excerpt, menu_order, status, unpublish_at,
                  password_hash, featured_image_id, seo_title, meta_description, author_id)
             VALUES
                 (:parent_id, :title, :slug, :lang, :translation_of, :content, :excerpt, :menu_order, :status, :unpublish_at,
                  :password_hash, :featured_image_id, :seo_title, :meta_description, :author_id)'
        );
        $lang = \Cms\Site\Language::checked((string) ($data['lang'] ?? ''));
        $stmt->execute([
            ':parent_id'         => $data['parent_id']         ?? null,
            ':title'             => $data['title'],
            ':slug'              => $data['slug'],
            // '' the site's own language; a translation names its own and the
            // page it translates.
            ':lang'              => $lang,
            ':translation_of'    => !empty($data['translation_of']) ? (int) $data['translation_of'] : null,
            ':content'           => Sanitizer::clean((string) ($data['content'] ?? '')),
            // A SUMMARY IS PLAIN TEXT, as an entry's excerpt is (migration 135).
            ':excerpt'           => \Cms\Models\Post::cleanExcerpt($data['excerpt'] ?? null),
            ':menu_order'        => $data['menu_order']        ?? 0,
            ':status'            => $data['status']            ?? 'private',
            ':unpublish_at'      => self::unpublishAt($data['unpublish_at'] ?? null),
            ':password_hash'     => $data['password_hash']     ?? null,
            ':featured_image_id' => !empty($data['featured_image_id']) ? (int) $data['featured_image_id'] : null,
            ':seo_title'         => trim($data['seo_title']    ?? '') ?: null,
            ':meta_description'  => trim($data['meta_description'] ?? '') ?: null,
            ':author_id'         => $data['author_id'],
        ]);
        $id = (int) $pdo->lastInsertId();

        // ── AUTO ADD PAGES, AND IT HANGS OFF THE INSERT, NOT OFF A SCREEN ───
        // A menu with `auto_add_pages` set collects every new top-level page.
        // Hung here rather than in `PageController::handleCreate()` because this
        // method is the ONE PATH IN — the admin, the importer and the assistant's
        // applier all reach a page through it, and a hook on one screen would
        // mean an imported page quietly skipped the menu that had asked for
        // every page.
        //
        // TOP-LEVEL ONLY. A child page joining the navigation at the top level is
        // not what "add new pages to this menu" means to anybody, and nesting it
        // under its parent assumes the parent is in that menu at all.
        //
        // IN THE SITE'S OWN LANGUAGE ONLY: a page in the second language is
        // never put in a menu on its own.
        if (empty($data['parent_id']) && $lang === '') {
            MenuItem::autoAddPage($id, (string) $data['title']);
        }

        return $id;
    }

    /**
     * Update an existing page.
     *
     * The unpublish date and the summary are written only when `$data` names
     * them: the page's screen does, and a caller that knows nothing of them
     * (an import, the assistant) leaves what the owner set alone.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $pdo    = Database::getInstance();
        $ending = array_key_exists('unpublish_at', $data);
        $summed = array_key_exists('excerpt', $data);
        $stmt   = $pdo->prepare(
            'UPDATE pages
             SET parent_id         = :parent_id,
                 title             = :title,
                 slug              = :slug,
                 content           = :content,
                 menu_order        = :menu_order,
                 status            = :status,
                 ' . ($ending ? 'unpublish_at      = :unpublish_at,' : '') . '
                 ' . ($summed ? 'excerpt           = :excerpt,' : '') . '
                 password_hash     = :password_hash,
                 featured_image_id = :featured_image_id,
                 seo_title         = :seo_title,
                 meta_description  = :meta_description
             WHERE id = :id'
        );
        $stmt->execute(($ending ? [':unpublish_at' => self::unpublishAt($data['unpublish_at'])] : [])
            + ($summed ? [':excerpt' => \Cms\Models\Post::cleanExcerpt($data['excerpt'])] : []) + [
            ':parent_id'         => $data['parent_id']         ?? null,
            ':title'             => $data['title'],
            ':slug'              => $data['slug'],
            ':content'           => Sanitizer::clean((string) ($data['content'] ?? '')),
            ':menu_order'        => $data['menu_order']        ?? 0,
            ':status'            => $data['status']            ?? 'private',
            ':password_hash'     => $data['password_hash']     ?? null,
            ':featured_image_id' => !empty($data['featured_image_id']) ? (int) $data['featured_image_id'] : null,
            ':seo_title'         => trim($data['seo_title']    ?? '') ?: null,
            ':meta_description'  => trim($data['meta_description'] ?? '') ?: null,
            ':id'                => $id,
        ]);
    }

    /**
     * THE ONE RULE for "a visitor can see this page in a list": published,
     * not taken off by its unpublish date (`Post::notEnded()`, the same
     * clause), and in the language of the list: the page being drawn's, or
     * `$lang` (`Post::inLanguage()`). As SQL, for a WHERE clause; `$alias` is
     * a literal the caller writes.
     */
    public static function live(string $alias = '', ?string $lang = null): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        return "{$a}status = 'published' AND " . \Cms\Models\Post::notEnded($alias)
            . ' AND ' . \Cms\Models\Post::inLanguage($alias, $lang);
    }

    /** An unpublish date as stored: a UTC date and time, or null for none. */
    private static function unpublishAt(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Check whether a slug is already taken in the pages table, within
     * `$lang` ('' the site's own language).
     * Pass $excludeId when editing an existing page (exclude self).
     */
    public static function slugExists(string $slug, ?int $excludeId = null, string $lang = ''): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id FROM pages WHERE slug = :slug AND id != :id AND ' . \Cms\Models\Post::inLanguage('', $lang) . ' LIMIT 1'
        );
        $stmt->execute([':slug' => $slug, ':id' => $excludeId ?? 0]);

        return $stmt->fetch() !== false;
    }

    /**
     * Delete a page. Child pages will have their parent_id set to NULL (FK: ON DELETE SET NULL).
     */
    public static function delete(int $id): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM pages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        // Its share link names it by id, and nothing cascades to it.
        \Cms\Content\Share::deleteFor('page', $id);
    }

    /**
     * Generate a URL-safe slug from a title, guaranteed unique in the pages
     * table within `$lang` ('' the site's own language).
     */
    public static function generateSlug(string $title, ?int $excludeId = null, string $lang = ''): string
    {
        $base = strtolower($title);
        $base = preg_replace('/[^a-z0-9\s-]/', '', $base) ?? $base;
        $base = preg_replace('/[\s-]+/', '-', trim($base)) ?? $base;
        $base = trim($base, '-');

        if ($base === '') {
            $base = 'page';
        }

        $candidate = $base;
        $suffix    = 2;

        // A reserved address counts as taken: a page titled "Search" would
        // otherwise be given `/search`, which a fixed route answers first.
        while (self::slugExists($candidate, $excludeId, $lang)
            || in_array($candidate, \Cms\Helpers\Slug::reservedFirstSegments(), true)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * A copy's title: the page's own with "(copy)" after it, within the
     * column's 500 characters.
     */
    public static function copyTitle(string $title): string
    {
        return mb_substr($title, 0, 493) . ' (copy)';
    }

    /**
     * A copy's address: the page's own with `-copy` after it, or `-copy-2`,
     * `-copy-3`… when that is taken in `$lang` (the page's own language) or
     * one of the site's own addresses.
     */
    public static function copySlug(string $slug, string $lang = ''): string
    {
        $base      = mb_substr($slug, 0, 480) . '-copy';
        $candidate = $base;
        for ($n = 2; self::slugExists($candidate, null, $lang)
                || in_array($candidate, \Cms\Helpers\Slug::reservedFirstSegments(), true); $n++) {
            $candidate = $base . '-' . $n;
        }
        return $candidate;
    }

    /**
     * Return all pages suitable as parent options for a dropdown: those in
     * `$lang`, the language of the page being edited ('' the site's own), as
     * a page's parent is in its language. Excludes the page being edited
     * (can't be its own parent).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parentOptions(?int $excludeId = null, string $lang = ''): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, title FROM pages WHERE id != :id AND status != 'trash' AND " . \Cms\Models\Post::inLanguage('', $lang) . '
             ORDER BY menu_order ASC, title ASC'
        );
        $stmt->execute([':id' => $excludeId ?? 0]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
