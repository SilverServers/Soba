<?php

declare(strict_types=1);

/**
 * One GET through the site's front controller, from the command line, for a
 * static copy (`Export\StaticCopy`) — the page a signed-out visitor would get,
 * drawn at the copy's own address.
 *
 *   php render-page.php <front controller> <job file> <path> <body file>
 *
 * Writes the page's bytes to <body file> and prints one JSON line: the status
 * the page set and the fatal error if there was one. Run in a child process,
 * one per page, for the reasons the update's smoke test is
 * (`Update/smoke-request.php`): every page starts from nothing, as a visit
 * does, so nothing one page set can leak into the next, and a fatal cannot
 * take the export down with it. The caller points `error_log` at a file of its
 * own and reads it afterwards: a page that returns 200 with a warning in the
 * log has not passed.
 *
 * ── AT THE COPY'S ADDRESS ───────────────────────────────────────────────────
 * BASE_URL and BASE_PATH are defined here, before the front controller, as the
 * address the copy will be published at, and the front controller keeps them
 * (it defines each only when it is not already). Every address the CMS writes
 * — links, canonicals, the sitemap, structured data — is then the copy's own,
 * with nothing to rewrite afterwards.
 *
 * ── EXPORT MODE ─────────────────────────────────────────────────────────────
 * `CMS_EXPORT` holds the job's settings for the code that draws differently
 * in a copy: Google's tags print as in production, and a form is drawn for
 * the copy's own form handler. The front controller skips what a visit does
 * besides drawing its page (due orders, the daily file check, the
 * notification channels, redirect counts). Only this script defines it, and it
 * runs only from the command line.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

// Before ANY output: the CLI counts headers as sent once a byte is printed,
// after which the page could not set its status.
ob_start();

[$_self, $front, $jobFile, $path, $bodyFile] = $argv + [null, '', '', '/', ''];

$job = json_decode((string) @file_get_contents($jobFile), true);
if (!is_file($front) || !is_array($job) || $bodyFile === '') {
    ob_end_clean();
    echo json_encode(['status' => 0, 'fatal' => 'render-page: missing front controller, job or body file']), "\n";
    exit(1);
}

$address = rtrim((string) ($job['address'] ?? ''), '/');
$url     = parse_url($address) ?: [];
$base    = rtrim((string) ($url['path'] ?? ''), '/');

define('BASE_URL', $address);
define('BASE_PATH', $base);
define('CMS_EXPORT', [
    'php'     => (bool) ($job['php'] ?? false),
    'instead' => (string) ($job['instead'] ?? ''),
    'secret'  => (string) ($job['secret'] ?? ''),
]);

$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = $base . $path;
$_SERVER['QUERY_STRING']    = (string) (parse_url($path, PHP_URL_QUERY) ?? '');
$_SERVER['HTTP_HOST']       = ($url['host'] ?? 'localhost') . (isset($url['port']) ? ':' . $url['port'] : '');
$_SERVER['SERVER_NAME']     = $url['host'] ?? 'localhost';
$_SERVER['SERVER_PORT']     = (string) ($url['port'] ?? ((($url['scheme'] ?? '') === 'https') ? 443 : 80));
$_SERVER['HTTPS']           = (($url['scheme'] ?? '') === 'https') ? 'on' : '';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'CMS-static-copy';
$_SERVER['HTTP_ACCEPT']     = 'text/html';
$_SERVER['SCRIPT_FILENAME'] = $front;
$_SERVER['SCRIPT_NAME']     = '/' . basename($front);
$_SERVER['PHP_SELF']        = '/' . basename($front);
$_COOKIE = [];
$_POST   = [];
parse_str($_SERVER['QUERY_STRING'], $_GET);
chdir(dirname($front));

register_shutdown_function(static function () use ($bodyFile): void {
    $body = '';
    while (ob_get_level() > 0) {
        $body = ob_get_clean() . $body;
    }
    $error = error_get_last();
    $fatal = ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true))
        ? $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        : null;
    $code    = http_response_code();
    $written = file_put_contents($bodyFile, $body) === strlen($body);
    echo json_encode([
        'status' => $code === false ? 200 : $code,
        'bytes'  => strlen($body),
        'fatal'  => $fatal ?? ($written ? null : 'render-page: could not write ' . $bodyFile),
    ], JSON_UNESCAPED_SLASHES), "\n";
});

require $front;
