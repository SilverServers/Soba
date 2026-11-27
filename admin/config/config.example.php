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
    // Screens a role is granted (optional)
    //
    // Business details, Words, Menus and Redirects are administrators'
    // unless a role below is granted them here: `business`, `words`, `menus`
    // and `redirects`. A grant reaches its role and every role above it, so
    // editors' screens are shop managers' too. Settings, Security and Users
    // stay administrators'.
    // ----------------------------------------------------------------
    // 'grants' => ['editor' => ['business', 'words', 'menus', 'redirects']],

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
    // The admin themes the site offers (optional)
    //
    // Every theme is offered, Carton the default, unless they are named
    // here: then those alone, in this order, and the first is the theme of
    // every account that has not chosen one. A site's own theme is its file
    // name in www/site/admin-themes/, without .css.
    // ----------------------------------------------------------------
    // 'admin_themes' => ['clientname', 'tuxedo'],

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
    // The largest file the library takes (optional)
    //
    // In megabytes: ten when left out, two thousand and forty-eight at most.
    // A file larger than the server takes in one request is sent in pieces,
    // so this is the site's own limit, not PHP's upload limit; the space on
    // the host's disk still is one.
    // ----------------------------------------------------------------
    // 'upload_max_mb' => 50,

    // ----------------------------------------------------------------
    // A copy of each backup off the server (optional)
    //
    // Backups are kept in admin/backups/, on this server, so they do not
    // survive it. Named here, S3-compatible storage (Amazon S3, Backblaze B2,
    // Wasabi and the like) takes a copy of each finished backup, and keeps
    // the newest `keep` of them (Tools → Backups' own number when left out).
    // The key needs to write, list and delete in the bucket. `prefix` is
    // where in the bucket, for a bucket that holds more than this site's.
    // `path_style` puts the bucket in the address's path rather than its
    // host, for storage reached at an address of its own. Tools → Backups
    // and System health say how each copy went (Site\BackupCopy).
    // ----------------------------------------------------------------
    // 'backup_copy' => [
    //     'endpoint' => 'https://s3.us-west-002.backblazeb2.com',
    //     'region'   => 'us-west-002',
    //     'bucket'   => 'example-backups',
    //     'key'      => 'the access key ID',
    //     'secret'   => 'the secret access key',
    //     'prefix'   => 'example.com/',
    //     'keep'     => 8,
    // ],

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
