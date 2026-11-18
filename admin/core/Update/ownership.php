<?php

declare(strict_types=1);

/**
 * WHO OWNS WHAT, AS DATA — the one list the release packager and
 * `admin/bin/update.php` both read. Nothing else may keep its own copy.
 *
 * An installed site is the CMS's files and the site's files in one tree. An
 * update replaces the CMS's and must never touch the site's, so the line
 * between them is written down here once, as paths relative to the site's
 * project root (the directory holding `admin/` and `www/`).
 *
 *   core     Shipped in every package and REPLACED on every update. A path
 *            ending in `/` is a directory the CMS owns entirely: the update
 *            swaps the whole directory, so a file inside it that the release
 *            does not list was either shipped by an older release (it goes) or
 *            put there by the site (the update stops and names it, because the
 *            site's work belongs in `admin/site/` — see below).
 *
 *   seed     Shipped by a FRESH INSTALL only. From the moment it is unpacked it
 *            is the site's: an update never writes it, and an update package
 *            never carries it.
 *
 *   site     Never shipped and never touched. EVERYTHING not listed under
 *            `core` is the site's too; these are named so the packager can
 *            refuse to ship any of them and the updater can refuse a package
 *            that carries one.
 *
 * The most specific entry wins: `www/uploads/` is the site's, and the two
 * `.htaccess` files inside it that keep uploads from executing are the CMS's.
 *
 * `@front`, `@admin` and `@htaccess` depend on the layout, recorded in each
 * install's `admin/manifest.json`:
 *
 *   standalone  the CMS is the whole site. `www/index.php` is the CMS's front
 *               controller and `www/.htaccess` its rewrite rules (a seed).
 *   router      the CMS sits beside a site that keeps its own router. Its
 *               front controller ships as `www/cms.php`; the site's own
 *               `www/index.php` and `www/.htaccess` are never shipped or
 *               touched, and the site's `.htaccess` carries a rewrite block
 *               handing the CMS's paths to `cms.php` instead. (Before 0.7.0
 *               it shipped under its old name (Compat\OldNames::ROUTER_FRONT); an install that still has
 *               that file, and no `front_controller` of its own, keeps it
 *               there — Compat\OldNames::routerFront().)
 *
 * AND ON EACH INSTALL, ITS OWN NAME FOR ONE OF THEM (`Ownership::installNames()`):
 * the front controller is `www/{front_controller}` when the site named it
 * (e.g. `www/cms.php`, beside the site's own `index.php`), from the
 * site's config.php, which no update touches. A package and its manifest
 * always say `www/admin/` and the layout's `@front`, and the updater and the
 * file check translate at the site. (An install from before that keeps its
 * admin at an old `admin_url_slug` folder is read that way only until the
 * updater moves it back: `Ownership::oldAdminFolder()`.)
 */
return [
    'core' => [
        'admin/admin/',
        'admin/core/',
        'admin/templates/',
        'admin/vendor/',
        'admin/composer.json',
        'admin/composer.lock',
        'admin/VERSION',
        'admin/manifest.json',
        'admin/config/config.example.php',
        // The operator scripts, by name: nothing else in admin/bin/ ships.
        // security.php is the way back in when the sign-in page has been moved
        // and nobody remembers where. run-due.php is what a host's cron runs.
        // backup.php lists, makes and puts back the site's backups.
        // export-static.php makes a static copy of the site where the admin
        // cannot start PHP to make one. media-sizes.php makes the smaller
        // widths of images added before they were made.
        // media-dates.php corrects the library's dates, an imported archive's
        // above all. form-numbers.php sets the number the next form
        // submission takes. media-site-files.php puts files the site already
        // has under its web root into the library where they are.
        'admin/bin/migrate.php',
        'admin/bin/update.php',
        'admin/bin/security.php',
        'admin/bin/run-due.php',
        'admin/bin/backup.php',
        'admin/bin/export-static.php',
        'admin/bin/media-sizes.php',
        'admin/bin/media-dates.php',
        'admin/bin/config.php',
        'admin/bin/form-numbers.php',
        'admin/bin/media-site-files.php',
        '@admin',
        'www/assets/',
        'www/uploads/.htaccess',
        'www/uploads/private/.htaccess',
        '@front',
    ],

    'seed' => [
        'admin/config/content-types.php',
        'admin/config/taxonomies.php',
        'admin/config/menus.php',
        '@htaccess',
    ],

    'site' => [
        'admin/config/config.php',
        // The site's theme. A template here is used instead of the
        // same-named one in admin/templates/, so a site's look survives every
        // update. See Cms\Helpers\Template.
        'admin/site/',
        // Update backups, staged releases and the update history.
        'admin/updates/',
        // Static copies of the site, and their archives (`Export\StaticCopy`).
        'admin/exports/',
        // The site's backups (`Site\Backups`).
        'admin/backups/',
        // WordPress exports waiting to be imported, and the imports' reports
        // (`Content\WordPressImport`).
        'admin/imports/',
        'admin/logs/',
        'www/uploads/',
        // The site's public files. www/site/theme.css and www/site/admin.css
        // load after the CMS's own stylesheets when they exist.
        'www/site/',
    ],

    'layouts' => [
        'standalone' => ['@front' => 'www/index.php',   '@admin' => 'www/admin/', '@htaccess' => 'www/.htaccess'],
        'router'     => ['@front' => 'www/cms.php',     '@admin' => 'www/admin/', '@htaccess' => null],
    ],

    // Where the front controller lives in THIS repository. Packages rename it
    // to the layout's `@front`.
    'source_front' => 'www/index.php',
];
