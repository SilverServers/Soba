<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Models\Option;
use Cms\Site\Look;
use Cms\Ui\Theme;

/**
 * APPEARANCE → CUSTOMIZER: the public site's palette, corners, fonts, header
 * logo and site icon (`Site\Look` has the rules), with the home page beside
 * the controls showing each choice as it is made (`customizer.js`). Save
 * keeps them, for every visitor.
 */
class CustomizerController
{
    /** GET /admin/appearance/customizer */
    public function show(array $vars = []): void
    {
        Auth::requireRole('admin');
        $look = Look::settings();
        $this->render([], isset($_GET['saved']), [
            'palette' => $look['palette'], 'radius' => $look['radius'], 'body' => $look['body'], 'head' => $look['head'],
            'logo' => $look['logo'], 'logo_name' => $look['logo_name'], 'icon' => $look['icon'],
        ]);
    }

    /** POST /admin/appearance/customizer — on a refusal nothing is written, and the form comes back as chosen. */
    public function save(array $vars = []): void
    {
        Auth::requireRole('admin');

        $posted = [
            'palette'   => (string) ($_POST['palette'] ?? ''),
            'radius'    => (string) ($_POST['radius'] ?? ''),
            'body'      => (string) ($_POST['font_body'] ?? ''),
            'head'      => (string) ($_POST['font_head'] ?? ''),
            'logo'      => max(0, (int) ($_POST['logo'] ?? 0)),
            'logo_name' => !empty($_POST['logo_name']),
            'icon'      => max(0, (int) ($_POST['icon'] ?? 0)),
        ];
        $errors = [];
        if (!Theme::exists($posted['palette'])) {
            $errors[] = 'Choose a palette from the list.';
        }
        if (!isset(Look::CORNERS[$posted['radius']])) {
            $errors[] = 'Choose the corners from the list.';
        }
        foreach (['body' => 'the text', 'head' => 'the headings'] as $key => $what) {
            if (!isset(Look::FONTS[$posted[$key]])) {
                $errors[] = 'Choose a font for ' . $what . ' from the list.';
            }
        }
        foreach (['logo' => 'The logo', 'icon' => 'The site icon'] as $key => $what) {
            $problem = Look::pictureProblem($posted[$key]);
            if ($problem !== null) {
                $errors[] = $what . ': ' . lcfirst($problem);
            }
        }
        if ($errors !== []) {
            $this->render($errors, false, $posted);
            return;
        }

        Option::setMany([
            Look::PALETTE_KEY   => $posted['palette'],
            Look::RADIUS_KEY    => $posted['radius'],
            Look::BODY_KEY      => $posted['body'],
            Look::HEAD_KEY      => $posted['head'],
            Look::LOGO_KEY      => (string) $posted['logo'],
            Look::LOGO_NAME_KEY => $posted['logo_name'] ? '1' : '0',
            Look::ICON_KEY      => (string) $posted['icon'],
        ]);
        Look::reset();

        header('Location: ' . ADMIN_URL . '/appearance/customizer?saved=1', true, 303);
        exit;
    }

    /**
     * @param list<string>         $errors
     * @param array<string, mixed> $form the look as kept, or as chosen before a refusal
     */
    private function render(array $errors, bool $saved, array $form): void
    {
        $user        = Auth::user();
        $siteName    = (string) \Cms\SEO\Head::globalTokens()['site_name'];
        $pageTitle   = 'Customizer';
        $currentPage = 'customizer';
        ob_start();
        require ROOT_PATH . '/admin/templates/appearance/customizer.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }
}
