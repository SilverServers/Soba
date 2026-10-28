<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Models\Media;
use Cms\Models\Option;
use Cms\Security\LoginUrl;
use Cms\Security\SignInPage;
use Cms\Security\TwoStep;
use Cms\Ui\Brand;
use Cms\Ui\Theme;

/**
 * APPEARANCE → BRANDING — the CMS in each site's own name, in two tabs
 * (`Ui\Brand`, `Security\SignInPage`).
 *
 *   Admin     what the admin calls itself, its logo, the site icon, and the
 *             look everyone gets
 *   Sign-in   the pages at the sign-in address: their logo, their background,
 *             CSS of the owner's own
 *
 * Administrators only. The Sign-in tab asks for the password again first:
 * what it changes is printed on the page where passwords are typed.
 */
final class BrandingController
{
    /** @var array<string, string> tab => its name */
    public const TABS = ['admin' => 'Admin', 'sign-in' => 'Sign-in page'];

    public function show(array $vars = []): void
    {
        Auth::requireRole('admin');
        $tab = self::tab();
        if ($tab === 'sign-in') {
            TwoStep::requireConfirmed('/admin/appearance/branding?tab=sign-in');
        }

        $this->render($tab, [], isset($_GET['saved']), []);
    }

    /** The Admin tab's one form. On failure nothing is written, and it comes back as typed. */
    public function saveAdmin(array $vars = []): void
    {
        Auth::requireRole('admin');

        $posted = [
            'name'    => trim((string) ($_POST['name'] ?? '')),
            'logo'    => max(0, (int) ($_POST['logo'] ?? 0)),
            'icon'    => max(0, (int) ($_POST['icon'] ?? 0)),
            'palette' => (string) ($_POST['palette'] ?? ''),
            'radius'  => (string) ($_POST['radius'] ?? ''),
        ];
        $errors = [];
        if (mb_strlen($posted['name']) > Brand::NAME_MAX) {
            $errors[] = sprintf('The name can be at most %d characters.', Brand::NAME_MAX);
        }
        foreach (['logo' => 'The logo', 'icon' => 'The site icon'] as $key => $what) {
            if ($posted[$key] > 0 && !self::isImage($posted[$key])) {
                $errors[] = $what . ' must be a picture from the media library.';
            }
        }
        if (!Theme::exists($posted['palette'])) {
            $errors[] = 'Choose a palette from the list.';
        }
        if (!in_array($posted['radius'], Theme::RADII, true)) {
            $errors[] = 'Choose the corners from the list.';
        }

        if ($errors !== []) {
            $this->render('admin', $errors, false, $posted);
            return;
        }

        Option::setMany([
            Brand::NAME_KEY    => $posted['name'],
            Brand::LOGO_KEY    => (string) $posted['logo'],
            Brand::ICON_KEY    => (string) $posted['icon'],
            Brand::ADMIN_THEME_KEY  => $posted['palette'],
            Brand::ADMIN_RADIUS_KEY => $posted['radius'],
        ]);
        Brand::reset();

        header('Location: ' . ADMIN_URL . '/appearance/branding?saved=1', true, 303);
        exit;
    }

    /** The Sign-in tab's one form. On failure nothing is written. */
    public function saveSignIn(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/appearance/branding?tab=sign-in');

        $posted = [
            'logo'             => max(0, (int) ($_POST['logo'] ?? 0)),
            'logo_width'       => trim((string) ($_POST['logo_width'] ?? '')),
            'background'       => trim((string) ($_POST['background'] ?? '')),
            'background_image' => max(0, (int) ($_POST['background_image'] ?? 0)),
            'css'              => str_replace("\r\n", "\n", (string) ($_POST['css'] ?? '')),
        ];
        $errors = [];
        if (!ctype_digit($posted['logo_width']) || (int) $posted['logo_width'] < SignInPage::WIDTH[0] || (int) $posted['logo_width'] > SignInPage::WIDTH[1]) {
            $errors[] = sprintf('The logo is from %d to %d pixels wide.', SignInPage::WIDTH[0], SignInPage::WIDTH[1]);
        }
        if ($posted['background'] !== '' && SignInPage::colour($posted['background']) === null) {
            $errors[] = 'The background colour is a # and six hex digits — or empty for the usual one.';
        }
        $problem = SignInPage::cssProblem($posted['css']);
        if ($problem !== null) {
            $errors[] = $problem;
        }

        if ($errors !== []) {
            $this->render('sign-in', $errors, false, $posted);
            return;
        }

        Option::setMany([
            SignInPage::LOGO_KEY             => (string) $posted['logo'],
            SignInPage::LOGO_WIDTH_KEY       => (string) (int) $posted['logo_width'],
            SignInPage::BACKGROUND_KEY       => (string) (SignInPage::colour($posted['background']) ?? ''),
            SignInPage::BACKGROUND_IMAGE_KEY => (string) $posted['background_image'],
            SignInPage::CSS_KEY              => trim($posted['css']),
        ]);

        header('Location: ' . ADMIN_URL . '/appearance/branding?tab=sign-in&saved=1', true, 303);
        exit;
    }

    /** The tab asked for, or the first. */
    private static function tab(): string
    {
        $tab = (string) ($_GET['tab'] ?? 'admin');
        return isset(self::TABS[$tab]) ? $tab : 'admin';
    }

    /** A library item that a browser draws as a picture. */
    private static function isImage(int $id): bool
    {
        $row = Media::find($id);
        return $row !== null && str_starts_with((string) ($row['mime_type'] ?? ''), 'image/');
    }

    /**
     * @param list<string>         $errors
     * @param array<string, mixed> $posted  what was typed, after a refused save
     */
    private function render(string $tab, array $errors, bool $saved, array $posted): void
    {
        $user = Auth::user();
        if ($tab === 'sign-in') {
            $s    = SignInPage::settings();
            $form = $posted !== [] ? $posted : ['logo' => $s['logo'], 'logo_width' => (string) $s['logo_width'], 'background' => $s['background'],
                                                'background_image' => $s['background_image'], 'css' => $s['css']];
            $preview = LoginUrl::url() . '?preview=1';
        } else {
            $s    = Brand::settings();
            $form = $posted !== [] ? $posted : ['name' => $s['name'], 'logo' => $s['logo'], 'icon' => $s['icon'],
                                                'palette' => $s['admin_theme'], 'radius' => $s['admin_radius']];
        }
        $siteName = (string) \Cms\SEO\Head::globalTokens()['site_name'];

        $pageTitle   = 'Branding';
        $currentPage = 'branding';
        ob_start();
        require ROOT_PATH . '/admin/templates/appearance/branding.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }
}
