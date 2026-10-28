<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Helpers\Places;
use Cms\Models\Gallery;
use Cms\Models\Media;

class GalleryController
{
    // ----------------------------------------------------------------
    // List
    // ----------------------------------------------------------------

    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        $galleries   = Gallery::all();
        $placesList  = Places::declared() ? Places::all('gallery') : null;   // where each gallery is shown (change 28)
        $pageTitle   = 'Galleries';
        $currentPage = 'galleries';

        require ROOT_PATH . '/admin/templates/galleries/list.php';
    }

    // ----------------------------------------------------------------
    // Create
    // ----------------------------------------------------------------

    public function showCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $gallery  = null;
        $items    = [];
        $formData = [];
        $errors   = [];
        [$placesList, $holders] = self::placesForGallery();
        $pageTitle   = 'Create Gallery';
        $currentPage = 'galleries';

        require ROOT_PATH . '/admin/templates/galleries/edit.php';
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $errors      = [];

        if ($title === '') {
            $errors[] = 'Gallery title is required.';
        }
        $placeKey = self::postedPlace($errors, null);

        if (!empty($errors)) {
            [$placesList, $holders] = self::placesForGallery();
            $gallery  = null;
            $items    = [];
            $formData = $_POST;
            $pageTitle   = 'Create Gallery';
            $currentPage = 'galleries';

            require ROOT_PATH . '/admin/templates/galleries/edit.php';
            return;
        }

        $settings = [
            'layout'   => $_POST['layout'] ?? 'grid',
            'columns'  => (int) ($_POST['columns'] ?? 3),
            'lightbox' => !empty($_POST['lightbox']),
        ];

        $galleryId = Gallery::create($title, $description ?: null, $settings);

        // Save image items, each with its details (migration 091)
        Gallery::setItems($galleryId, $this->parseItems($_POST));

        // Where it is shown (change 28).
        $had = $placeKey !== null ? Gallery::place($galleryId, $placeKey) : null;

        header('Location: ' . ADMIN_URL . '/galleries/' . $galleryId . '/edit?saved=1' . ($had !== null ? '&moved=' . (int) $had['id'] : ''));
        exit;
    }

    // ----------------------------------------------------------------
    // Edit
    // ----------------------------------------------------------------

    public function showEdit(array $vars = []): void
    {
        Auth::requireRole('admin');

        $gallery = Gallery::find((int) ($vars['id'] ?? 0));
        if (!$gallery) {
            header('Location: ' . ADMIN_URL . '/galleries');
            exit;
        }

        $items    = Gallery::getItems((int) $gallery['id']);
        $formData = [];
        $errors   = [];
        $saved    = isset($_GET['saved']);
        [$placesList, $holders] = self::placesForGallery();
        // A save that took this gallery's place from another says so (change 28).
        $movedFrom = isset($_GET['moved']) ? Gallery::find((int) $_GET['moved']) : null;
        $pageTitle   = 'Edit Gallery: ' . $gallery['title'];
        $currentPage = 'galleries';

        require ROOT_PATH . '/admin/templates/galleries/edit.php';
    }

    public function handleUpdate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $galleryId = (int) ($vars['id'] ?? 0);
        $gallery   = Gallery::find($galleryId);
        if (!$gallery) {
            header('Location: ' . ADMIN_URL . '/galleries');
            exit;
        }

        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $errors      = [];

        if ($title === '') {
            $errors[] = 'Gallery title is required.';
        }
        $placeKey = self::postedPlace($errors, $gallery['place_key'] ?? null);

        if (!empty($errors)) {
            [$placesList, $holders] = self::placesForGallery();
            $items    = Gallery::getItems($galleryId);
            $formData = $_POST;
            $pageTitle   = 'Edit Gallery: ' . $gallery['title'];
            $currentPage = 'galleries';

            require ROOT_PATH . '/admin/templates/galleries/edit.php';
            return;
        }

        $settings = [
            'layout'   => $_POST['layout'] ?? 'grid',
            'columns'  => (int) ($_POST['columns'] ?? 3),
            'lightbox' => !empty($_POST['lightbox']),
        ];

        Gallery::update($galleryId, $title, $description ?: null, $settings);

        // Save image items, each with its details (migration 091)
        Gallery::setItems($galleryId, $this->parseItems($_POST));

        // Where it is shown (change 28). A save without the field — a site
        // with no places — leaves the place alone.
        $had = null;
        if (Places::declared() && array_key_exists('place_key', $_POST) && $placeKey !== ($gallery['place_key'] ?? null)) {
            $had = Gallery::place($galleryId, $placeKey);
        }

        header('Location: ' . ADMIN_URL . '/galleries/' . $galleryId . '/edit?saved=1' . ($had !== null ? '&moved=' . (int) $had['id'] : ''));
        exit;
    }

    // ----------------------------------------------------------------
    // Delete
    // ----------------------------------------------------------------

    public function handleDelete(array $vars = []): void
    {
        Auth::requireRole('admin');

        $galleryId = (int) ($vars['id'] ?? 0);
        $gallery   = Gallery::find($galleryId);
        if ($gallery) {
            Gallery::delete($galleryId);
        }

        header('Location: ' . ADMIN_URL . '/galleries');
        exit;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * The site's gallery places, and which gallery shows each (change 28):
     * [null, []] when the site has none — the Forms screen's rule
     * (`FormController::placesForForm()`), for the other kind.
     *
     * @return array{0: array<string, array<string, mixed>>|null, 1: array<string, array<string, mixed>>}
     */
    private static function placesForGallery(): array
    {
        if (!Places::declared() || Places::all('gallery') === []) {
            return [null, []];
        }
        $holders = [];
        foreach (Gallery::all() as $g) {
            if (($g['place_key'] ?? null) !== null) {
                $holders[(string) $g['place_key']] = $g;
            }
        }
        return [Places::all('gallery'), $holders];
    }

    /**
     * The posted place: null for none; a gallery place the site lists; or the
     * one the gallery already has, even if the list has since dropped it.
     * Anything else — a form's place, a made-up key — is an error.
     *
     * @param list<string> $errors
     */
    private static function postedPlace(array &$errors, ?string $current): ?string
    {
        $key = $_POST['place_key'] ?? '';
        $key = is_string($key) ? trim($key) : '';
        if ($key === '' || !Places::declared()) {
            return null;
        }
        if ($key !== $current && (Places::find($key)['kind'] ?? null) !== 'gallery') {
            $errors[] = 'No place has the key "' . $key . '"; the list may have changed. Pick one again.';
            return $current;
        }
        return $key;
    }

    /**
     * THE GALLERY, IN ORDER, WITH EACH ITEM'S DETAILS — migration 091.
     *
     * The order is `image_ids`, as it always was. The details ride in inputs
     * keyed by the attachment id — `item_caption[ID]`, `item_link[ID]`,
     * `item_thumb[ID]` — which sit inside their tile, so a drag moves them
     * and a remove takes them. A detail for an id that is not in
     * `image_ids` is ignored: no item is added by its caption alone.
     *
     * CHECKED, NOT TRUSTED. A link is a site path (`/…`) or an http(s)
     * address — anything else (`javascript:`, `data:`) is dropped, since it
     * reaches an `href`. A thumbnail must be a file in the library, and not
     * the picture itself.
     *
     * @param  array<string, mixed> $post
     * @return list<array{attachment_id: int, caption: string, link_url: string, thumb_attachment_id: int}>
     */
    private function parseItems(array $post): array
    {
        $detail = static function (string $name, int $id) use ($post): string {
            $all = $post[$name] ?? null;
            $one = is_array($all) ? ($all[$id] ?? '') : '';
            return is_scalar($one) ? trim((string) $one) : '';
        };

        $items = [];
        $ids   = $this->parseImageIds(is_string($post['image_ids'] ?? null) ? $post['image_ids'] : '');
        foreach ($ids as $id) {
            $link  = $detail('item_link', $id);
            $thumb = (int) $detail('item_thumb', $id);
            if ($link !== '' && preg_match('~^(https?://\S+|/(?!/)\S*)$~i', $link) !== 1) {
                $link = '';
            }
            if ($thumb > 0 && ($thumb === $id || Media::find($thumb) === null)) {
                $thumb = 0;
            }
            $items[] = [
                'attachment_id'       => $id,
                'caption'             => mb_substr($detail('item_caption', $id), 0, 300),
                'link_url'            => mb_substr($link, 0, 500),
                'thumb_attachment_id' => $thumb,
            ];
        }
        return $items;
    }

    /**
     * Parse comma-separated attachment IDs from hidden input.
     *
     * @return int[]
     */
    private function parseImageIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            fn(int $id): bool => $id > 0
        ));
    }
}
