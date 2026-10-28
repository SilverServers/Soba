<?php

declare(strict_types=1);

/**
 * CMS Configuration
 *
 * Copy this file to config.php and fill in environment-specific values.
 * config.php is gitignored and must never be committed.
 */
return [

    // ----------------------------------------------------------------
    // Site
    // ----------------------------------------------------------------
    'base_url'   => 'https://example.com', // No trailing slash
    'app_env'    => 'development',                     // 'development' or 'production'
    'app_secret' => 'change-me-to-a-random-secret-string',

    // Behind a proxy or CDN (Cloudflare, a load balancer) every request
    // arrives from the proxy's address, so the attempt limit would lock out
    // the proxy — everyone at once. Name the proxy and the header it writes
    // the visitor's address into; the header is believed ONLY from these
    // addresses (Security\ClientIp). Leave both unset when there is no proxy.
    // 'trusted_proxies'      => ['173.245.48.0/20', '10.0.0.5'],
    // 'trusted_proxy_header' => 'CF-Connecting-IP',

    // Admin session lifetime in seconds. Default 7200 (2h).
    'session_lifetime' => 7200,


    // ----------------------------------------------------------------
    // CMS Assistant (optional)
    //
    // Without a key the assistant runs against a stub transport: it can read
    // content and report what it found, but will not propose changes. Get a key
    // at https://console.anthropic.com/settings/keys
    //
    // Spending is capped by the monthly limit in Settings → General (default $25),
    // which is checked before every request.
    // ----------------------------------------------------------------
    // 'anthropic_effort' sets how hard the model thinks: low, medium, high,
    // xhigh or max. Default 'medium', which suits routine content edits and keeps
    // turns short — raise it if the assistant is misreading complicated requests.
    // ----------------------------------------------------------------
    // 'anthropic_api_key'    => 'sk-ant-...',
    // 'anthropic_model'      => 'claude-opus-5',
    // 'anthropic_max_tokens' => 16000,
    // 'anthropic_effort'     => 'medium',

    // ----------------------------------------------------------------
    // Modules (optional) — which optional parts of the CMS this site runs.
    // Every module is on unless switched off here. A switched-off module
    // answers 404 at its addresses and shows nowhere in the admin; its data
    // stays, so switching it back on restores it. Helpers\Modules lists them.
    // ----------------------------------------------------------------
    // 'modules' => [
    //     'pages'        => false,   // static pages and their /{slug} address
    //     'menus'        => false,   // Appearance → Menus and the menu API
    //     'search'       => false,   // the public /search
    //     'commerce'     => false,   // the shop: cart, checkout, orders, discount codes
    //     'appointments' => false,   // the Appointment field in the form builder
    //     'assistant'    => false,   // the Assistant and its spend limit
    //     'forms'        => false,   // forms, their inbox and the public submit address
    // ],

    // ----------------------------------------------------------------
    // Database (MySQL / MariaDB)
    // ----------------------------------------------------------------
    'db_host'    => '127.0.0.1',
    'db_name'    => 'cms',            // Production: update to prod DB name
    'db_user'    => 'YOUR_MYSQL_USER',
    'db_pass'    => 'YOUR_MYSQL_PASS',
    'db_charset' => 'utf8mb4',

];
