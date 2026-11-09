# CMS

A content management system for the websites an agency builds and hands over to its clients. What a WordPress site gets from a dozen plugins (custom content types and fields, forms, galleries, SEO, sign-in security) is built into one PHP codebase, with no plugin layer. A site owner who is not technical can change what a small business changes (hours, contact details, events, news, images, forms) and read what its forms collect.

## What it does

- **Content Types and fields.** Define a type (News, Events, Staff, …) with its own fields, archive and address, entirely from the admin. Field groups attach to types by location rules.
- **Taxonomies, menus and pages.** Categories and tags with their own addresses, menus for several locations, parent and child entries, revisions and undo.
- **Media.** A library with folders. New images are stored as WebP. Galleries.
- **Embeds.** A video or a post from YouTube, Vimeo, X, Instagram or Facebook goes into any text as the embed code the provider gives out, and the page shows it as the provider draws it.
- **Import Markdown.** A Markdown document, pasted or chosen as a file, goes into the body where the cursor is, with its headings, lists, tables and links. Nothing is saved until the entry is, and pictures that are only on the writer's computer are named so they can be added.
- **Forms.** A form builder (pages, conditions, repeaters, uploads, ratings, appointments), a submissions inbox, and spam handling that discards what is certainly spam and keeps what is only suspicious.
- **A shop.** Products sold as themselves or in sizes and colours, each option with its own code, photo, price, sale and count. A basket and checkout with tax by province or state, delivery by place and local pickup, and discount codes. Orders with their emails and notes, refunds by the line, exchanges, tracking, packing slips and invoices, orders taken by telephone, and a spreadsheet for the books. A stock screen with each count's history, mail when a size runs low or sells out, and a list of who is waiting for it to come back. Prices, sales and counts changed across a selection, and products in and out of a spreadsheet. Customers who sign in from a link sent to their inbox, with what is kept about them given or erased on request. Reports of what sold and the tax collected by province. A feed for Google Merchant Center and Meta's catalogue, and an API with keys and webhooks for other programs. Payment is by bank transfer, cheque or on delivery: there is no card gateway yet.
- **SEO.** Titles and descriptions with variables, structured data, a sitemap, redirects.
- **Users and sign-in security.** Roles, two-step sign-in, signing in without a password, temporary access, a sign-in page at an address of your own or one made at random, lockouts, a log of who changed what, and a check of the CMS's own files against the release they came from.
- **White-label.** Every name a visitor or an editor sees is the site's own title.
- **Modules.** Pages, menus, search, the shop, appointments, the assistant and forms can each be switched off in `config.php`; their data stays.
- **An assistant (optional).** The site owner asks in plain words. It proposes a change, a person approves the rendered difference, and only then does the CMS apply it: the model has no way to write. In a shop it also reads the orders, stock and sales, and proposes a new count, an order's move, a discount code or an option the same way. It uses the Claude API with the owner's own key and a spend limit.
- **Updates with a way back.** The updater backs up the database and every file it replaces, refuses to overwrite a file the site has changed, and rolls itself back if anything fails.

Nothing is fetched from a third party at runtime: no CDN scripts, no remote fonts. The one exception is an embed a writer pastes, which loads from its provider.

## Requirements

- PHP 8.2 or later, with the PDO MySQL, mbstring, DOM and fileinfo extensions. Two-step sign-in also needs OpenSSL, the assistant needs curl, and a shop's webhooks need `allow_url_fopen`. Imagick, when present, is used for WebP.
- MySQL or MariaDB
- Apache with `mod_rewrite` (the rules are in `www/.htaccess`), or a server that sends every request that is not a file to `www/index.php`

The two PHP libraries the CMS uses, FastRoute and PHPMailer, are included in `admin/vendor/`. There is no Composer step.

## Installing

This repository is an installation: `admin/` holds the code and its configuration, and `www/` is the web server's document root.

1. Copy `admin/` and `www/` to the server, and point the document root at `www/`.
2. Create a database. Copy `admin/config/config.example.php` to `admin/config/config.php` and fill it in: the site's address, the database, and a long random `app_secret`. Use `'app_env' => 'production'` for a live site.
3. Run `php admin/bin/migrate.php run`. It prints the password for the first sign-in, once, with the address of the sign-in page.
4. Sign in there as `admin` with that password. The CMS asks for a name and password of your own straight away. Then set the Site title under Settings → General.

If that password is lost before anyone signs in, `php admin/bin/security.php password admin` prints a new one. The same command is the way back in for any account whose owner cannot sign in.

To move the sign-in page away from `/admin/login`, use Security → Login protection: type an address of your own, or press Randomize.

A shop's unpaid orders are cancelled after the days Settings → Shop allows, and their customers reminded the day before, checked on visits at most every ten minutes. Where the host has cron, `php admin/bin/run-due.php` runs the same check on a timetable, so it happens on time on a quiet site.

## Updating

Each release is published on this repository's Releases page with an install package and an update package. To update a site, put the update package in the site's root and unpack it. It lands in `admin/updates/incoming/<version>/` and overwrites nothing:

```
tar -xzf cms-standalone-<version>-update.tar.gz --keep-old-files
php admin/updates/incoming/<version>/admin/bin/update.php check
php admin/updates/incoming/<version>/admin/bin/update.php apply
```

`check` says what will change and names any file the site has changed itself. `apply` backs up, updates, runs the release's migrations, and rolls everything back if a step fails.

## Beside a site that keeps its own router

A site may keep its own `www/index.php` and run the CMS beside it. Install from the release's router package (`cms-router-<version>-install.tar.gz`), whose front controller is `www/cms.php`, and have the site's router ask the CMS first:

```php
require __DIR__ . '/../admin/vendor/autoload.php';
if (\Cms\Site\Front::owns($_SERVER['REQUEST_URI'] ?? '/')) {
    require __DIR__ . '/cms.php';
    exit;
}
```

`owns()` answers for the admin, the API, private files, form posts, the sign-in page wherever it has moved, and every Content Type and taxonomy published at an address. The site declares its own first segments in `admin/config/site-addresses.php`. Such a site updates from the release's router update package (`cms-router-<version>-update.tar.gz`), the same way.

## Reporting a security problem

Email support@silverservers.com rather than opening a public issue. Say which release you found it in and how to reproduce it.

## Third-party components

- **Inter** and **Fira Mono** fonts, under the SIL Open Font License 1.1. The licence, with each font's copyright, is beside the fonts: `www/assets/fonts/OFL.txt` and `www/admin/assets/fonts/OFL.txt`.
- **Icons** by Font Awesome ([fontawesome.com](https://fontawesome.com)), under CC BY 4.0.
- **FastRoute** (BSD-3-Clause) and **PHPMailer** (LGPL-2.1), with their licences in `admin/vendor/`.

## Licence

All rights reserved. The third-party components above keep their own licences.
