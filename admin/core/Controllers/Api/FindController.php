<?php

declare(strict_types=1);

namespace Cms\Controllers\Api;

use Cms\Auth\Auth;
use Cms\Http\Cache;
use Cms\Ui\Find;

/**
 * GET /api/v1/find?q=… — what the top bar's box offers as one types
 * (`Ui\Find`): `{"results": [{"name", "kind", "href"}]}`, best first, only
 * what the signed-in account may open.
 */
class FindController
{
    public function results(array $vars = []): void
    {
        Auth::requireAuth();
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();
        echo json_encode(['results' => Find::results((string) ($_GET['q'] ?? ''))], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }
}
