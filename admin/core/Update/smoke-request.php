<?php

declare(strict_types=1);

/**
 * One GET through a site's front controller, from the command line — the
 * update's smoke test, run in a child process so a fatal cannot take the
 * updater down with it.
 *
 *   php smoke-request.php <front controller> <base_url> <path> <token>
 *
 * Prints one JSON line: the status the page set, its size, its <title>,
 * whether it drew a password field, and the fatal error if there was one. The
 * caller runs it with `error_log` pointed at a file of its own and reads that
 * file afterwards, because a page that returns 200 with a warning in the log
 * has not passed.
 *
 * `<token>` is the one in `admin/updates/.maintenance`: the front controller
 * lets a request carrying it through while every visitor gets the 503.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

// Before ANY output: the CLI counts headers as sent once a byte is printed,
// after which the page could not set its status or start its session.
ob_start();

[$_self, $front, $base, $path, $token] = $argv + [null, '', '', '/', ''];
if (!is_file($front)) {
    ob_end_clean();
    echo json_encode(['status' => 0, 'fatal' => "no front controller at {$front}"]), "\n";
    exit(1);
}

$url = parse_url($base) ?: [];
$_SERVER['REQUEST_METHOD']       = 'GET';
$_SERVER['REQUEST_URI']          = rtrim((string) ($url['path'] ?? ''), '/') . $path;
$_SERVER['QUERY_STRING']         = (string) (parse_url($path, PHP_URL_QUERY) ?? '');
$_SERVER['HTTP_HOST']            = ($url['host'] ?? 'localhost') . (isset($url['port']) ? ':' . $url['port'] : '');
$_SERVER['SERVER_NAME']          = $url['host'] ?? 'localhost';
$_SERVER['SERVER_PORT']          = (string) ($url['port'] ?? ((($url['scheme'] ?? '') === 'https') ? 443 : 80));
$_SERVER['HTTPS']                = (($url['scheme'] ?? '') === 'https') ? 'on' : '';
$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT']      = 'CMS-update-smoke';
$_SERVER['HTTP_X_CMS_SMOKE'] = $token;
$_SERVER['SCRIPT_FILENAME']      = $front;
$_SERVER['SCRIPT_NAME']          = '/' . basename($front);
$_SERVER['PHP_SELF']             = '/' . basename($front);
parse_str($_SERVER['QUERY_STRING'], $_GET);
chdir(dirname($front));

register_shutdown_function(static function (): void {
    $body  = '';
    while (ob_get_level() > 0) {
        $body = ob_get_clean() . $body;
    }
    $error = error_get_last();
    $fatal = ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true))
        ? $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        : null;
    $code = http_response_code();
    echo json_encode([
        'status'   => $code === false ? 200 : $code,
        'bytes'    => strlen($body),
        'title'    => preg_match('#<title>(.*?)</title>#s', $body, $m) === 1 ? trim(html_entity_decode($m[1])) : null,
        'password' => str_contains($body, 'type="password"'),
        'fatal'    => $fatal,
    ], JSON_UNESCAPED_SLASHES), "\n";
});

require $front;
