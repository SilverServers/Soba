<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Site\Language;
use Cms\Site\Settings;
use Cms\Site\SettingsPages;

/**
 * A SITE'S OWN SETTINGS PAGE (`Site\SettingsPages`), at
 * `/admin/site-settings/{key}`: its fields in their sections, one Save, for
 * the roles its row names. With a second language, a tab for each language
 * edits that language's values (`Site\Settings`), every field's but a tick
 * box's, which is the same in both. A refusal writes nothing
 * and draws the page again as posted; each save is one line in Security →
 * Activity log → Changes, as every admin save is (`Security\Audit`).
 */
final class SettingsPageController
{
    public function show(array $vars = []): void
    {
        $page = $this->page($vars);
        if ($page === null) {
            return;
        }
        $lang   = $this->lang((string) ($_GET['lang'] ?? ''));
        $values = [];
        foreach ($this->fields($page['key'], $lang) as $field) {
            $values[(string) $field['key']] = Settings::stored($page['key'], (string) $field['key'], $lang);
        }
        $this->render($page, $lang, $values, [], isset($_GET['saved']));
    }

    public function handle(array $vars = []): void
    {
        $page = $this->page($vars);
        if ($page === null) {
            return;
        }
        $lang   = $this->lang((string) ($_POST['lang'] ?? ''));
        $fields = $this->fields($page['key'], $lang);
        $errors = Settings::problems($fields, $_POST, $lang !== '');
        if ($errors !== []) {
            $values = [];
            foreach ($fields as $field) {
                $values[(string) $field['key']] = $_POST['field_' . $field['key']] ?? null;
            }
            $this->render($page, $lang, $values, $errors, false);
            return;
        }
        Settings::save($page['key'], $fields, $_POST, $lang);
        header('Location: ' . ADMIN_URL . '/site-settings/' . $page['key'] . '?saved=1' . ($lang !== '' ? '&lang=' . rawurlencode($lang) : ''), true, 303);
        exit;
    }

    /**
     * The page at this address, for an account its row lets in; null after
     * answering 404 when the site declares no such page.
     *
     * @return array<string, mixed>|null
     */
    private function page(array $vars): ?array
    {
        Auth::requireAuth();
        $page = SettingsPages::get((string) ($vars['key'] ?? ''));
        if ($page === null) {
            http_response_code(404);
            require ROOT_PATH . '/templates/404.php';
            return null;
        }
        Auth::requireRole((string) $page['role']);

        return $page;
    }

    /**
     * The page's fields in one language: in the second, those it keeps a value
     * of its own for (`Settings::inSecondLanguage()`).
     *
     * @return list<array<string, mixed>>
     */
    private function fields(string $key, string $lang): array
    {
        $fields = SettingsPages::fields($key);

        return $lang === '' ? $fields : array_values(array_filter($fields, [Settings::class, 'inSecondLanguage']));
    }

    /** The language asked for: the second when it is the site's second, else the site's own (''). */
    private function lang(string $asked): string
    {
        $second = Language::prefix();

        return $second !== '' && $asked === $second ? $second : '';
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $values field key => its value in `$lang`
     * @param list<string>         $errors
     */
    private function render(array $page, string $lang, array $values, array $errors, bool $saved): void
    {
        $user        = Auth::user();
        $sections    = SettingsPages::sections((string) $page['key']);
        if ($lang !== '') {
            $sections = array_values(array_filter(array_map(static fn (array $s): array => [
                'label'  => $s['label'],
                'fields' => array_values(array_filter($s['fields'], [Settings::class, 'inSecondLanguage'])),
            ], $sections), static fn (array $s): bool => $s['fields'] !== []));
        }
        $second      = Language::prefix();
        $pageTitle   = (string) $page['label'];
        $currentPage = 'site-settings';

        ob_start();
        require ROOT_PATH . '/admin/templates/settings/site-page.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }
}
