<?php

declare(strict_types=1);

namespace Cms\Helpers;

/**
 * What a visitor gets when something throws, outside development (kept by
 * this name because a site's own tools call it).
 *
 * The full story goes to the PHP error log: class, message, file, line, trace.
 * The visitor gets a bare 500 that names no file, no query and no product. An
 * API caller gets `{"success":false,"error":"Server error."}`, in the shape
 * every other API error uses. Half-rendered output is thrown away first, so a
 * page cannot end in the middle of a table with an error glued to it.
 *
 * `www/index.php` installs it whenever `app_env` is not 'development', and
 * switches `display_errors` off there: errors are never displayed in
 * production.
 */
final class ErrorPage
{
    public static function handle(\Throwable $e): void
    {
        error_log(sprintf(
            "Uncaught %s: %s in %s:%d\n%s",
            $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
        ));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        $api  = str_starts_with($path, $base . '/api/');

        if (!headers_sent()) {
            http_response_code(500);
            header('Cache-Control: no-store');
            header('Content-Type: ' . ($api ? 'application/json' : 'text/html; charset=utf-8'));
        }

        echo $api
            ? json_encode(['success' => false, 'error' => 'Server error.'])
            : '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
              . '<title>Server error</title>'
              . '<p style="font-family:system-ui,sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem;line-height:1.6">'
              . 'Something went wrong on our side. Please try again in a moment.</p>';
    }
}
