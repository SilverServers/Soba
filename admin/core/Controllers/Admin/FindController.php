<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Http\Cache;
use Cms\Ui\Find;

/**
 * GET /admin/find?q=… — the top bar's box sent with Enter before its list has
 * come, or without a script: opens the first thing found (`Ui\Find`), else
 * goes back where it was sent from.
 */
class FindController
{
    public function open(array $vars = []): void
    {
        Auth::requireAuth();
        Cache::noStore();
        $first = Find::results((string) ($_GET['q'] ?? ''))[0]['href'] ?? null;
        $back  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $self  = (string) (parse_url(ADMIN_URL, PHP_URL_HOST) ?? '');
        $to    = $first ?? ($back !== '' && (string) (parse_url($back, PHP_URL_HOST) ?? '') === $self ? $back : ADMIN_URL);
        header('Location: ' . $to, true, 303);
        exit;
    }
}
