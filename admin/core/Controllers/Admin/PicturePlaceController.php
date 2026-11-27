<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Helpers\Places;
use Cms\Models\PicturePlace;

/**
 * FILES AND MEDIA → PICTURE PLACES: each of the site's picture places
 * (`Helpers\Places`, kind 'picture') with the picture it shows, and the media
 * picker to choose, change or remove it (`Models\PicturePlace`). One sheet and
 * one Save, as Settings → Branding is; on a refusal nothing is written, the
 * refused spot comes back with its own picture, and the rest as chosen.
 */
final class PicturePlaceController
{
    public function show(array $vars = []): void
    {
        Auth::requireRole('admin');
        $chosen = [];
        foreach (array_keys(Places::all('picture')) as $key) {
            $chosen[(string) $key] = PicturePlace::chosen((string) $key);
        }
        $this->render([], isset($_GET['saved']), $chosen);
    }

    public function handle(array $vars = []): void
    {
        Auth::requireRole('admin');
        $posted = is_array($_POST['picture'] ?? null) ? $_POST['picture'] : [];
        $chosen = [];
        $errors = [];
        foreach (Places::all('picture') as $key => $place) {
            $key = (string) $key;
            // A place the form did not post keeps its picture.
            if (!array_key_exists($key, $posted)) {
                $chosen[$key] = PicturePlace::chosen($key);
                continue;
            }
            $raw = $posted[$key];
            if (!is_scalar($raw) || !ctype_digit(trim((string) $raw))) {
                $errors[]     = $place['name'] . ': choose a picture from the library.';
                $chosen[$key] = PicturePlace::chosen($key);
                continue;
            }
            // The rule Settings → Branding's logo keeps: a public picture in the
            // library. A spot refused comes back with its own picture, the
            // message naming it; the others come back as chosen.
            $problem = \Cms\Ui\Branding::pictureProblem((int) trim((string) $raw));
            if ($problem !== null) {
                $errors[]     = $place['name'] . ': ' . lcfirst($problem);
                $chosen[$key] = PicturePlace::chosen($key);
                continue;
            }
            $chosen[$key] = (int) trim((string) $raw);
        }
        if ($errors !== []) {
            $this->render($errors, false, $chosen);
            return;
        }
        foreach ($chosen as $key => $id) {
            PicturePlace::choose($key, $id);
        }
        header('Location: ' . ADMIN_URL . '/picture-places?saved=1', true, 303);
        exit;
    }

    /**
     * @param list<string>       $errors
     * @param array<string, int> $chosen key => the picture's id, 0 for none
     */
    private function render(array $errors, bool $saved, array $chosen): void
    {
        $user        = Auth::user();
        $places      = Places::all('picture');
        $placesError = Places::error();
        $pageTitle   = 'Picture places';
        $currentPage = 'picture-places';

        ob_start();
        require ROOT_PATH . '/admin/templates/media/picture-places.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }
}
