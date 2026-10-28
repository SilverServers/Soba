<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;

/**
 * "Coming Soon" pages for admin sections that are planned and not built yet.
 *
 * The point of these is that the **full admin scope is visible and navigable** from
 * day one — a sidebar item that leads to a clear "not built yet, here is what it
 * will do" page is honest, where a missing item hides the roadmap and a dead link
 * looks like a bug.
 *
 * Only two routes land here: the Theme Builder and the Customizer. Every other
 * admin section has a real controller.
 */
class PlaceholderController
{
    public function themeBuilder(array $vars = []): void
    {
        $this->render(
            'Theme Builder',
            'Design custom header, body, and footer templates and assign them to specific pages, content types, or archives.',
            'theme-builder'
        );
    }

    public function customizer(array $vars = []): void
    {
        $this->render(
            'Customizer',
            'Configure global site styling — colors, fonts, logo, and design tokens that apply across your entire site.',
            'customizer'
        );
    }

    // ----------------------------------------------------------------

    private function render(string $pageTitle, string $description, string $currentPage): void
    {
        Auth::requireAuth();

        ob_start();
        require ROOT_PATH . '/admin/templates/partials/coming-soon.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }
}
