<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Models\Page;
use Cms\SEO\Head;

class PageController
{
    public function index(array $vars = []): void
    {
        Auth::requireRole('editor');
        $user  = Auth::user();
        $pages = Page::all();
        require ROOT_PATH . '/admin/templates/pages/list.php';
    }

    public function showCreate(array $vars = []): void
    {
        Auth::requireRole('editor');
        $user          = Auth::user();
        $page          = null;
        $parentOptions = Page::parentOptions();
        $errors        = [];
        require ROOT_PATH . '/admin/templates/pages/edit.php';
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireRole('editor');
        $user = Auth::user();

        [$errors, $slug, $status, $passwordHash] = $this->validateAndExtract($_POST, null);

        if (!empty($errors)) {
            $page          = null;
            $parentOptions = Page::parentOptions();
            require ROOT_PATH . '/admin/templates/pages/edit.php';
            return;
        }

        $id = Page::create([
            'parent_id'         => !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null,
            'title'             => trim($_POST['title']),
            'slug'              => $slug,
            'content'           => $_POST['content'] ?? '',
            'menu_order'        => (int) ($_POST['menu_order'] ?? 0),
            'status'            => $status,
            'password_hash'     => $passwordHash,
            'featured_image_id' => !empty($_POST['featured_image_id']) ? (int) $_POST['featured_image_id'] : null,
            // The title box starts from the Pages pattern; still saying exactly
            // that, it is stored as none and goes on following the pattern.
            'seo_title'         => Head::ownTitle((string) ($_POST['seo_title'] ?? ''), 'page'),
            'meta_description'  => trim($_POST['meta_description'] ?? ''),
            'author_id'         => $user['id'],
        ]);

        header('Location: ' . ADMIN_URL . '/pages/' . $id . '/edit');
        exit;
    }

    public function showEdit(array $vars = []): void
    {
        Auth::requireRole('editor');
        $user = Auth::user();
        $page = Page::find((int) $vars['id']);

        if ($page === null) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }

        $parentOptions = Page::parentOptions((int) $vars['id']);
        $errors        = [];
        require ROOT_PATH . '/admin/templates/pages/edit.php';
    }

    public function handleUpdate(array $vars = []): void
    {
        Auth::requireRole('editor');
        $user = Auth::user();
        $page = Page::find((int) $vars['id']);

        if ($page === null) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }

        [$errors, $slug, $status, $passwordHash] = $this->validateAndExtract($_POST, (int) $vars['id'], $page);

        if (!empty($errors)) {
            $parentOptions = Page::parentOptions((int) $vars['id']);
            require ROOT_PATH . '/admin/templates/pages/edit.php';
            return;
        }

        Page::update((int) $vars['id'], [
            'parent_id'         => !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null,
            'title'             => trim($_POST['title']),
            'slug'              => $slug,
            'content'           => $_POST['content'] ?? '',
            'menu_order'        => (int) ($_POST['menu_order'] ?? 0),
            'status'            => $status,
            'password_hash'     => $passwordHash,
            'featured_image_id' => !empty($_POST['featured_image_id']) ? (int) $_POST['featured_image_id'] : null,
            'seo_title'         => Head::ownTitle((string) ($_POST['seo_title'] ?? ''), 'page'),
            'meta_description'  => trim($_POST['meta_description'] ?? ''),
        ]);

        // A LIVE PAGE, RENAMED, LEAVES A 301 BEHIND IT — the rule entries and
        // terms follow (Redirects::moved(), which refuses an address a route
        // owns). `$page` is still the row as it was before the save.
        if ($slug !== (string) $page['slug'] && in_array((string) $page['status'], ['published', 'password'], true)) {
            \Cms\SEO\Redirects::moved('/' . $page['slug'], '/' . $slug);
        }

        $page          = Page::find((int) $vars['id']);
        $parentOptions = Page::parentOptions((int) $vars['id']);
        $errors        = [];
        $saved         = true;
        require ROOT_PATH . '/admin/templates/pages/edit.php';
    }

    public function handleTrash(array $vars = []): void
    {
        Auth::requireRole('editor');
        Page::trash((int) $vars['id']);
        header('Location: ' . ADMIN_URL . '/pages');
        exit;
    }

    public function handleRestore(array $vars = []): void
    {
        Auth::requireRole('editor');
        Page::restore((int) $vars['id']);
        header('Location: ' . ADMIN_URL . '/pages/trash');
        exit;
    }

    public function showTrash(array $vars = []): void
    {
        Auth::requireRole('editor');
        $user  = Auth::user();
        $pages = Page::allTrashed();
        require ROOT_PATH . '/admin/templates/pages/trash.php';
    }

    public function handleDelete(array $vars = []): void
    {
        Auth::requireRole('editor');
        Page::delete((int) $vars['id']);
        header('Location: ' . ADMIN_URL . '/pages/trash');
        exit;
    }

    /**
     * Validate POST data, resolve the slug and status/password.
     *
     * @param array<string, mixed>      $postData
     * @param int|null                  $editId    Page ID being edited (null on create)
     * @param array<string, mixed>|null $existing  Existing page row (needed to preserve password hash on edit)
     * @return array{0: string[], 1: string, 2: string, 3: string|null}
     *         [errors, slug, status, password_hash]
     */
    private function validateAndExtract(array $postData, ?int $editId, ?array $existing = null): array
    {
        $errors = [];

        if (trim((string) ($postData['title'] ?? '')) === '') {
            $errors[] = 'Title is required.';
        }

        // Slug: user-supplied or auto-generated from title.
        $rawSlug = self::sanitizeSlugInput($postData['slug'] ?? '');
        if ($rawSlug !== '') {
            if (Page::slugExists($rawSlug, $editId)) {
                $errors[] = 'That URL slug is already in use. Please choose a different one.';
            } elseif (in_array($rawSlug, \Cms\Helpers\Slug::reservedFirstSegments(), true)) {
                // A FIXED ROUTE WINS over `/{slug}`, so a page here would save
                // and then never be seen — and that includes the sign-in page's
                // address while it has moved (`Security\LoginUrl`). Content
                // Types, taxonomies and redirects already refused these.
                $errors[] = '/' . $rawSlug . ' is one of the site\'s own addresses. Please choose a different one.';
            }
            $slug = $rawSlug;
        } else {
            $slug = Page::generateSlug(trim((string) ($postData['title'] ?? '')), $editId);
        }

        // Status
        $allowedStatuses = ['published', 'password', 'private'];
        $status = in_array($postData['status'] ?? '', $allowedStatuses, true)
            ? $postData['status']
            // AN UNRECOGNISED STATUS IS NOT PUBLISHED. A hand-made POST, or a
            // select that grows an option the allowlist has not been told
            // about, must fail towards the state that keeps the page off the
            // public site rather than the one that puts it on it.
            : 'private';

        // Password hash
        $passwordHash = null;
        if ($status === 'password') {
            $newPassword = trim($postData['post_password'] ?? '');
            if ($newPassword !== '') {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            } elseif ($existing !== null) {
                $passwordHash = $existing['password_hash'] ?? null;
            }
        }

        return [$errors, $slug, $status, $passwordHash];
    }

    /**
     * Sanitize a user-supplied slug: lowercase, alphanumeric + hyphens, collapsed.
     */
    private static function sanitizeSlugInput(string $raw): string
    {
        return \Cms\Helpers\Slug::clean($raw);
    }
}
