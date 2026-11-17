<?php

declare(strict_types=1);

/**
 * THIS SITE'S FORM HANDLER.
 *
 * The site is a static copy its CMS made, and this file sends its forms. A GET
 * gives a form a fresh token as its page loads; a POST is a form sent, checked
 * by the CMS's own rules and mailed through this host's own mail program.
 *
 *   config/   what the copy was made with: its secret, the forms it draws, the
 *             settings they read. To send through an SMTP server instead of
 *             the host's mail program, give `smtp` in config/config.php a host
 *             and its password.
 *   lib/      the CMS's form classes, unchanged, and the mail library
 *   data/     what it remembers: spent tokens, messages kept as spam
 *             (data/spam/), and any message the mail program refused
 *             (data/unsent/) — each a PHP file whose text is the message
 *
 * It needs PHP 8.2 or later with the mbstring and fileinfo extensions, and
 * data/ writable by PHP.
 */

if (PHP_VERSION_ID < 80200 || !function_exists('mb_strlen') || !function_exists('finfo_open')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This form needs PHP 8.2 or later, with mbstring and fileinfo, on this host.\n");
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// The CMS's form classes find the secret, and the client's address rules, in
// config/config.php under ROOT_PATH, as they do on the site.
define('ROOT_PATH', __DIR__);
$config = require __DIR__ . '/config/config.php';
define('BASE_URL', (string) $config['address']);
define('BASE_PATH', (string) $config['base_path']);
unset($config);

if (!is_dir(__DIR__ . '/data') || !is_writable(__DIR__ . '/data')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'message' => 'This form cannot be sent yet: this host does not let it write to its folder forms/data/.']));
}

require __DIR__ . '/lib/stand-ins.php';
require __DIR__ . '/lib/autoload.php';

\Cms\Export\Receiver\Handler::run(__DIR__);
