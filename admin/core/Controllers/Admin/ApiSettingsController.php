<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\Webhooks;
use Cms\Helpers\Url;
use Cms\Security\ApiKeys;

/**
 * SETTINGS → API: the keys other programs reach the shop with, and the
 * addresses it tells when an order comes in or moves (`Security\ApiKeys`,
 * `Commerce\Webhooks`).
 *
 * An administrator's: a key reads every order, and one that writes moves
 * them. A key is shown in full once, on the page its making returns to, and
 * never again; so is a webhook's secret.
 */
class ApiSettingsController
{
    private const MADE = 'api_key_made';

    private const HOOK_MADE = 'api_webhook_made';

    public function show(array $vars = []): void
    {
        Auth::requireRole('admin');

        $keys     = ApiKeys::all();
        $hooks    = Webhooks::all();
        $made     = $_SESSION[self::MADE] ?? null;
        $hookMade = $_SESSION[self::HOOK_MADE] ?? null;
        $problem  = $_SESSION['api_settings_problem'] ?? null;
        unset($_SESSION[self::MADE], $_SESSION[self::HOOK_MADE], $_SESSION['api_settings_problem']);

        $pageTitle   = 'API';
        $currentPage = 'settings-api';

        require ROOT_PATH . '/admin/templates/settings/api.php';
    }

    public function makeKey(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user = Auth::user();
        $made = ApiKeys::create((string) ($_POST['name'] ?? ''), !empty($_POST['can_write']),
            (string) ($user['name'] ?? $user['username'] ?? ''));
        // SHOWN ONCE, on the page this returns to.
        $_SESSION[self::MADE] = $made['key'];
        $this->back();
    }

    public function revokeKey(array $vars = []): void
    {
        Auth::requireRole('admin');
        ApiKeys::revoke((int) ($vars['id'] ?? 0));
        $this->back();
    }

    public function addHook(array $vars = []): void
    {
        Auth::requireRole('admin');
        $url     = (string) ($_POST['url'] ?? '');
        $events  = is_array($_POST['events'] ?? null) ? array_map('strval', $_POST['events']) : [];
        $problem = Webhooks::problem($url, $events);
        if ($problem !== null) {
            $_SESSION['api_settings_problem'] = $problem;
        } else {
            // ITS SECRET, SHOWN ONCE, as a key is, on the page this returns to.
            $_SESSION[self::HOOK_MADE] = ['url' => trim($url), 'secret' => Webhooks::add($url, $events)];
        }
        $this->back();
    }

    public function removeHook(array $vars = []): void
    {
        Auth::requireRole('admin');
        Webhooks::remove((int) ($vars['id'] ?? 0));
        $this->back();
    }

    private function back(): never
    {
        header('Location: ' . Url::admin('settings/api'), true, 303);
        exit;
    }
}
