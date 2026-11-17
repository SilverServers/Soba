<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Database\Database;
use Cms\Models\Option;
use Cms\Site\Business;

/**
 * SETTINGS → BUSINESS DETAILS: the screen over `Site\Business`, for an
 * administrator. It reads what is kept and posts through `Business::save()`,
 * the one way in, so a script that fills the details once goes through the
 * same checks as the screen.
 */
final class BusinessController
{
    public function show(array $vars = []): void
    {
        Auth::requireRole('admin');
        $this->render(Business::stored(), [], false);
    }

    public function save(array $vars = []): void
    {
        Auth::requireRole('admin');

        $post = static fn (string $key): array => is_array($_POST[$key] ?? null) ? $_POST[$key] : [];
        $in = [
            'name'   => (string) ($_POST['name'] ?? ''),
            'short'  => (string) ($_POST['short'] ?? ''),
            'email'  => (string) ($_POST['email'] ?? ''),
            'phones' => $post('phones'),
            'hours'  => $post('hours'),
            'place'  => $post('place'),
            'areas'  => $post('areas'),
            'social' => $post('social'),
        ];

        $errors = Business::save($in);
        if ($errors !== []) {
            // Drawn again as typed, cleaned, so nothing is lost.
            $this->render(Business::clean($in), $errors, false);
            return;
        }
        $this->render(Business::stored(), [], true);
    }

    /**
     * @param array<string, mixed> $kept   `Business::stored()`'s shape
     * @param list<string>         $errors
     */
    private function render(array $kept, array $errors, bool $saved): void
    {
        $user    = Auth::user();
        $details = Business::get();
        $local   = Option::get('seo_org_type', 'Organization') === 'LocalBusiness';
        $pages   = [];
        if (\Cms\Helpers\Modules::enabled('pages')) {
            $pages = Database::getInstance()
                ->query("SELECT id, title FROM pages WHERE status = 'published' ORDER BY title")
                ->fetchAll(\PDO::FETCH_KEY_PAIR);
        }

        $pageTitle   = 'Business details';
        $currentPage = 'settings-business';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/business.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }
}
