<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Helpers\Dates;
use Cms\Helpers\Words;
use Cms\Models\Page;
use Cms\Models\Revision;
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
        // Every line break posted is a LF, as stored (`Words::lineFeeds()`).
        $_POST = Words::lineFeeds($_POST);

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
            'excerpt'           => is_string($_POST['excerpt'] ?? null) ? $_POST['excerpt'] : null,
            'menu_order'        => (int) ($_POST['menu_order'] ?? 0),
            'status'            => $status,
            'unpublish_at'      => self::unpublishAt(),
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

        $parentOptions = Page::parentOptions((int) $vars['id'], (string) $page['lang']);
        $errors        = [];
        $revisions     = Revision::listFor(Revision::TYPE_PAGE, (int) $vars['id']);
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

        // ── A SAVE THAT CHANGES NOTHING CHANGES NOTHING ─────────────────────
        // As an entry's (`ContentTypeController::handleUpdate()`): every line
        // break posted is a LF, the unpublish box keeps the stored second, and
        // a page found exactly as it was keeps its date and leaves no revision.
        $_POST = Words::lineFeeds($_POST);

        [$errors, $slug, $status, $passwordHash] = $this->validateAndExtract($_POST, (int) $vars['id'], $page);

        if (!empty($errors)) {
            $parentOptions = Page::parentOptions((int) $vars['id'], (string) $page['lang']);
            $revisions     = Revision::listFor(Revision::TYPE_PAGE, (int) $vars['id']);
            require ROOT_PATH . '/admin/templates/pages/edit.php';
            return;
        }

        // WHAT IS ABOUT TO BE OVERWRITTEN, kept, so a save can be undone from
        // the History card, as an entry's can (`Revision`).
        $before   = self::state((int) $vars['id']);
        $revision = Revision::snapshot(Revision::TYPE_PAGE, (int) $vars['id'], $user['id']);

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
        ] + (array_key_exists('unpublish_local', $_POST) ? ['unpublish_at' => self::unpublishAt($page)] : [])
          + (is_string($_POST['excerpt'] ?? null) ? ['excerpt' => $_POST['excerpt']] : []));

        if (self::state((int) $vars['id']) === $before) {
            if ($revision !== null) {
                Revision::forget($revision);
            }
        } else {
            Revision::prune(Revision::TYPE_PAGE, (int) $vars['id']);
        }

        // A LIVE PAGE, RENAMED, LEAVES A 301 BEHIND IT — the rule entries and
        // terms follow (Redirects::moved(), which refuses an address a route
        // owns). `$page` is still the row as it was before the save. Under its
        // language's prefix, for a translation (/fr/about → /fr/a-propos).
        if ($slug !== (string) $page['slug'] && in_array((string) $page['status'], ['published', 'password'], true)) {
            $in = (string) $page['lang'] !== '' ? '/' . $page['lang'] : '';
            \Cms\SEO\Redirects::moved($in . '/' . $page['slug'], $in . '/' . $slug);
        }

        $page          = Page::find((int) $vars['id']);
        $parentOptions = Page::parentOptions((int) $vars['id'], (string) $page['lang']);
        $errors        = [];
        $saved         = true;
        $revisions     = Revision::listFor(Revision::TYPE_PAGE, (int) $vars['id']);
        require ROOT_PATH . '/admin/templates/pages/edit.php';
    }

    /**
     * A COPY TO START THE NEXT PAGE FROM: its words, picture, place under its
     * parent and in the order, and search-engine text, as a Private page of
     * its own at a free address (`about-copy`), opened for editing. A copy is
     * never live by itself and carries no password: whoever makes it public
     * chooses how.
     */
    public function handleCopy(array $vars = []): void
    {
        Auth::requireRole('editor');
        $page = Page::find((int) $vars['id']);

        if ($page === null || (string) $page['status'] === 'trash') {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }

        $user = Auth::user();
        $id   = Page::create([
            'parent_id'         => !empty($page['parent_id']) ? (int) $page['parent_id'] : null,
            'title'             => Page::copyTitle((string) $page['title']),
            // In the page's own language, as a copy of a translation is one.
            'slug'              => Page::copySlug((string) $page['slug'], (string) $page['lang']),
            'lang'              => (string) $page['lang'],
            'content'           => (string) ($page['content'] ?? ''),
            'excerpt'           => $page['excerpt'] ?? null,
            'menu_order'        => (int) ($page['menu_order'] ?? 0),
            'status'            => 'private',
            'featured_image_id' => !empty($page['featured_image_id']) ? (int) $page['featured_image_id'] : null,
            'seo_title'         => (string) ($page['seo_title'] ?? ''),
            'meta_description'  => (string) ($page['meta_description'] ?? ''),
            'author_id'         => (int) $user['id'],
        ]);

        header('Location: ' . ADMIN_URL . '/pages/' . $id . '/edit', true, 303);
        exit;
    }

    /**
     * ITS VERSION IN THE SECOND LANGUAGE (`Content\Translation`): the one it
     * has, or a Private copy made now, opened for translating. Refused with
     * no second language, for a translation and in the trash: back to its own
     * screen, whose Translation panel says why.
     */
    public function handleTranslate(array $vars = []): void
    {
        Auth::requireRole('editor');
        $page = Page::find((int) $vars['id']);

        if ($page === null) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }
        if (\Cms\Content\Translation::problem('pages', $page) !== null) {
            header('Location: ' . ADMIN_URL . '/pages/' . (int) $page['id'] . '/edit#translation', true, 303);
            exit;
        }

        $id = \Cms\Content\Translation::page($page, (int) Auth::user()['id']);
        header('Location: ' . ADMIN_URL . '/pages/' . $id . '/edit', true, 303);
        exit;
    }

    /**
     * Share it by a link (`Content\Share`), for as many days as chosen — a
     * page a visitor cannot see yet. Back to its screen, where the link is.
     */
    public function handleShare(array $vars = []): void
    {
        Auth::requireRole('editor');
        $page = Page::find((int) $vars['id']);
        if ($page === null || (string) $page['status'] === 'trash') {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }
        \Cms\Content\Share::make('page', (int) $page['id'], (int) ($_POST['share_days'] ?? 0), (int) Auth::user()['id']);
        header('Location: ' . ADMIN_URL . '/pages/' . (int) $page['id'] . '/edit#share', true, 303);
        exit;
    }

    /** Stop sharing it: its link stops working at once. */
    public function handleStopShare(array $vars = []): void
    {
        Auth::requireRole('editor');
        \Cms\Content\Share::stop('page', (int) $vars['id']);
        header('Location: ' . ADMIN_URL . '/pages/' . (int) $vars['id'] . '/edit#share', true, 303);
        exit;
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
        // Nothing cascades to the revisions, which span two tables (`Revision`).
        Revision::deleteFor(Revision::TYPE_PAGE, (int) $vars['id']);
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

        // Slug: user-supplied or auto-generated from title, unique within the
        // page's own language.
        $lang    = (string) ($existing['lang'] ?? '');
        $rawSlug = self::sanitizeSlugInput($postData['slug'] ?? '');
        // AN ADDRESS A NEW PAGE MADE FROM ITS TITLE (`slug_auto`, the title
        // card) is made unique, -2 and on, a reserved one counted as taken
        // (`Page::generateSlug()`); one typed by hand is refused when taken.
        $made    = $editId === null && ($postData['slug_auto'] ?? '') === '1';
        if ($rawSlug !== '' && !$made) {
            if (Page::slugExists($rawSlug, $editId, $lang)) {
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
            $slug = Page::generateSlug($rawSlug !== '' ? $rawSlug : trim((string) ($postData['title'] ?? '')), $editId, $lang);
        }

        // The unpublish date: a date and time, after the publish date when
        // there is one (an entry's rule; a page has none).
        $ending = ContentTypeController::endingProblem($postData);
        if ($ending !== null) {
            $errors[] = $ending;
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

    /** The Unpublish date box, in UTC, or null for an empty one. */
    private static function unpublishAt(?array $page = null): ?string
    {
        $box = is_array($_POST['unpublish_local'] ?? null) ? '' : trim((string) ($_POST['unpublish_local'] ?? ''));
        // THE BOX SHOWS THE MINUTE: posted back as shown, the stored second stays.
        if ($page !== null && ($page['unpublish_at'] ?? null) !== null && $box === Dates::toInput((string) $page['unpublish_at'])) {
            return (string) $page['unpublish_at'];
        }
        $utc = Dates::fromInput($box);
        return is_string($utc) ? $utc : null;
    }

    /**
     * What a save can change on a page, to tell whether one did: its row but
     * for the date that moves with it.
     *
     * @return array<string, mixed>
     */
    private static function state(int $id): array
    {
        $row = Page::find($id) ?? [];
        unset($row['updated_at']);

        return $row;
    }

    /**
     * Sanitize a user-supplied slug: lowercase, alphanumeric + hyphens, collapsed.
     */
    private static function sanitizeSlugInput(string $raw): string
    {
        return \Cms\Helpers\Slug::clean($raw);
    }
}
