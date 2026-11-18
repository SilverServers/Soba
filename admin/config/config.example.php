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
    'app_env'    => 'development',                     // 'production' (or left out), 'development', or another name such as 'staging'
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
    // Mail through Brevo (optional)
    //
    // Every message the CMS sends goes through Brevo's API instead of the
    // server's mail program or Settings → Email's SMTP server. It is sent
    // from the From address on Settings → Email, which Brevo must know as one
    // of the account's senders. The key is kept here and nowhere else.
    // ----------------------------------------------------------------
    // 'mail_transport' => 'brevo',
    // 'brevo_api_key'  => 'xkeysib-...',

    // ----------------------------------------------------------------
    // Mail kept, not sent (optional)
    //
    // For a staging copy or a development install: nothing is sent, and
    // every message the CMS would send is written to admin/logs/mail.log
    // instead, where any mail reader opens it. Settings → Email says so.
    // ----------------------------------------------------------------
    // 'mail_transport' => 'log',

    // ----------------------------------------------------------------
    // Form conversions the site sends itself (optional)
    //
    // For a site whose own header prints its Google tags: Settings → SEO →
    // Google stays empty and the CMS prints nothing of Google's, but each real
    // submission's conversion is carried to its form's after-sending page, for
    // the site's page to take with \Cms\Site\Google::conversion() and send.
    // ----------------------------------------------------------------
    // 'site_sends_conversions' => true,

    // ----------------------------------------------------------------
    // A list's page number (optional)
    //
    // Page 2 of an archive, a term's page or the front page's list is
    // ?page=2. A site whose own pages number their lists another way names
    // its parameter here, and the CMS's lists use it too: ?p=2.
    // ----------------------------------------------------------------
    // 'paging_parameter' => 'p',

    // ----------------------------------------------------------------
    // Icons in an entry's text (optional)
    //
    // A site whose theme loads an icon font writes an icon as an empty <i>
    // or <span> with the font's classes and aria-hidden="true". The classes
    // named here are kept on those two tags, a * standing for one or more
    // letters, digits, - or _; every other class, style or attribute still
    // goes. Without the key, <i> and <span> keep no attribute.
    // ----------------------------------------------------------------
    // 'icon_classes' => ['fa-solid', 'fa-regular', 'fa-brands', 'fa-*'],

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
    // The old class names (optional)
    //
    // Before 0.7.0 the CMS drew its forms, galleries and alerts with other
    // class names, and until 0.8.0 it writes them beside the new ones, so a
    // site stylesheet or script aimed at them keeps working. A site whose
    // own files use none of them turns them off here, and then nothing a
    // visitor is sent names them: not a page, not the CMS's stylesheets and
    // scripts. `update.php check` names any of the site's files that still do.
    // ----------------------------------------------------------------
    // 'old_class_names' => false,

    // ----------------------------------------------------------------
    // Database (MySQL / MariaDB)
    // ----------------------------------------------------------------
    'db_host'    => '127.0.0.1',
    'db_name'    => 'cms',            // Production: update to prod DB name
    'db_user'    => 'YOUR_MYSQL_USER',
    'db_pass'    => 'YOUR_MYSQL_PASS',
    'db_charset' => 'utf8mb4',

];
