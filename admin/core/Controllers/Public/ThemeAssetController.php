<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Helpers\Theme;

/**
 * GET /assets/theme/{key}/{path} — what a theme's pages fetch: its stylesheet,
 * its pictures, its fonts, out of the theme's own `assets/` directory.
 *
 * A theme lives outside the web root (`admin/site/themes/`, beside its PHP), so
 * a browser cannot be pointed at it; this is the one way out, and
 * `Theme::assetFile()` decides what may take it — a sound theme, a plain path
 * inside `assets/`, an extension on `Theme::ASSET_TYPES`, nothing linked out.
 * `/assets/` is a first segment the router already owns
 * (`Slug::reservedFirstSegments()`), and nothing exists under
 * `www/assets/theme/`, so the web server hands these requests here.
 */
final class ThemeAssetController
{
    public function serve(array $vars = []): void
    {
        $file = Theme::assetFile((string) ($vars['key'] ?? ''), (string) ($vars['path'] ?? ''));
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            exit;
        }

        $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mtime = (int) filemtime($file);
        $size  = (int) filesize($file);
        $etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';

        header('Content-Type: ' . Theme::ASSET_TYPES[$ext]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);
        // An SVG opened on its own is a document that can run script; one
        // drawn by a stylesheet or an <img> never runs it either way.
        if ($ext === 'svg') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        header('Content-Length: ' . $size);
        readfile($file);
        exit;
    }
}
