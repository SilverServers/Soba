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
        $posted   = $this->parseItems($_POST, $errors);

        if (!empty($errors)) {
            [$placesList, $holders] = self::placesForGallery();
            $gallery  = null;
            $items    = self::postedItems($posted);
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
        Gallery::setItems($galleryId, $posted);

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
        $posted   = $this->parseItems($_POST, $errors);

        if (!empty($errors)) {
            [$placesList, $holders] = self::placesForGallery();
            $items    = self::postedItems($posted);
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
        Gallery::setItems($galleryId, $posted);

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
     * THE GALLERY, IN ORDER, WITH EACH ITEM'S DETAILS — migrations 091, 127.
     *
     * The order is `image_ids`, as it always was. The details ride in inputs
     * keyed by the attachment id — `item_title[ID]`, `item_caption[ID]`,
     * `item_link[ID]`, `item_thumb[ID]` — which sit inside their tile, so a
     * drag moves them and a remove takes them. A detail for an id that is not
     * in `image_ids` is ignored: no item is added by its caption alone.
     *
     * CHECKED, NOT TRUSTED. A link is a site path (`/…`) or an http(s)
     * address — anything else (`javascript:`, `data:`) is dropped, since it
     * reaches an `href`. A thumbnail must be a file in the library, and not
     * the picture itself.
     *
     * A TITLE AND A CAPTION ARE KEPT AS TYPED. Only the white space at their
     * ends goes, and every line break is a LF, whichever the browser sent. One
     * too long for its column is not cut: it adds a sentence naming the
     * picture to `$errors` and stays in what is returned, every character of
     * it, so the screen drawn again shows it to be shortened.
     *
     * @param  array<string, mixed> $post
     * @param  list<string>         $errors
     * @return list<array{attachment_id: int, title: string, caption: string, link_url: string, thumb_attachment_id: int}>
     */
    private function parseItems(array $post, array &$errors): array
    {
        $detail = static function (string $name, int $id) use ($post): string {
            $all = $post[$name] ?? null;
            $one = is_array($all) ? ($all[$id] ?? '') : '';
            return is_scalar($one) ? trim((string) $one) : '';
        };
        $words = static fn (string $name, int $id): string => str_replace(["\r\n", "\r"], "\n", $detail($name, $id));

        $items = [];
        $ids   = $this->parseImageIds(is_string($post['image_ids'] ?? null) ? $post['image_ids'] : '');
        foreach ($ids as $id) {
            $title   = $words('item_title', $id);
            $caption = $words('item_caption', $id);
            $link    = $detail('item_link', $id);
            $thumb   = (int) $detail('item_thumb', $id);
            if ($link !== '' && preg_match('~^(https?://\S+|/(?!/)\S*)$~i', $link) !== 1) {
                $link = '';
            }
            if ($thumb > 0 && ($thumb === $id || Media::find($thumb) === null)) {
                $thumb = 0;
            }
            if (mb_strlen($title) > Gallery::TITLE_MAX || mb_strlen($caption) > Gallery::CAPTION_MAX) {
                $file = Media::find($id);
                $name = $file !== null ? (string) ($file['original_name'] ?: $file['filename']) : 'picture ' . $id;
                array_push($errors, ...Gallery::tooLong($title, $caption, '“' . $name . '”'));
            }
            $items[] = [
                'attachment_id'       => $id,
                'title'               => $title,
                'caption'             => $caption,
                'link_url'            => mb_substr($link, 0, 500),
                'thumb_attachment_id' => $thumb,
            ];
        }
        return $items;
    }

    /**
     * The posted items as `Gallery::getItems()` gives the stored ones, so a
     * save that is refused draws what was posted — its pictures, their order
     * and their details as typed — rather than what was stored before it. An
     * id that is no file in the library is left out.
     *
     * @param  list<array{attachment_id: int, title: string, caption: string, link_url: string, thumb_attachment_id: int}> $posted
     * @return list<array<string, mixed>>
     */
    private static function postedItems(array $posted): array
    {
        $items = [];
        foreach ($posted as $position => $item) {
            $file = Media::find($item['attachment_id']);
            if ($file === null) {
                continue;
            }
            $items[] = [
                'item_id'             => 0,
                'attachment_id'       => $item['attachment_id'],
                'position'            => $position,
                'title'               => $item['title'],
                'caption'             => $item['caption'],
                'link_url'            => $item['link_url'],
                'thumb_attachment_id' => $item['thumb_attachment_id'] > 0 ? $item['thumb_attachment_id'] : null,
            ] + array_intersect_key($file, array_flip(
                ['filename', 'site_path', 'original_name', 'mime_type', 'width', 'height', 'alt_text', 'visibility']
            ));
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
