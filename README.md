# CMS

A content management system for the websites an agency builds and hands over to its clients. What a WordPress site gets from a dozen plugins (custom content types and fields, forms, galleries, SEO, sign-in security) is built into one PHP codebase, with no plugin layer. A site owner who is not technical can change what a small business changes (hours, contact details, events, news, images, forms) and read what its forms collect.

## What it does

- **Content Types and fields.** Define a type (News, Events, Staff, …) with its own fields, archive and address, entirely from the admin. Field groups attach to types by location rules.
- **Taxonomies, menus and pages.** Categories and tags with their own addresses, menus for several locations, parent and child entries, revisions and undo.
- **Media.** A library with folders, chosen from a row over it and managed on a screen of their own, sorted by date added or taken, name, size, type or dimensions. A photo keeps the date its camera wrote, and an import keeps the dates it brings. New images are stored as WebP, with smaller widths beside them that a page offers to a phone or a small picture. Galleries.
- **Embeds.** A video or a post from YouTube, Vimeo, X, Instagram or Facebook goes into any text as the embed code the provider gives out, and the page shows it as the provider draws it.
- **Import Markdown.** A Markdown document, pasted or chosen as a file, goes into the body where the cursor is, with its headings, lists, tables and links. Nothing is saved until the entry is, and pictures that are only on the writer's computer are named so they can be added.
- **Forms.** A form builder (pages, conditions, repeaters, uploads, ratings, appointments), a submissions inbox, and spam handling that discards what is certainly spam and keeps what is only suspicious. A test sent from a form's test address is handled as a real submission, its email goes to a test inbox alone, and the inbox marks it. A submission whose email could not be sent says so in the inbox, and can be sent again. A photo sent through a form keeps nothing about where it was taken or the phone that took it, and a file field can take only the kinds of file it names.
- **Mail.** Every message goes through the server's own mail program, an SMTP server named on Settings → Email, or Brevo's API, chosen with its key in `config.php`. A test email from Settings → Email shows whether it works, and if not, why.
- **A shop.** Products sold as themselves or in sizes and colours, each option with its own code, photo, price, sale and count. A basket and checkout with tax by province or state, delivery by place and local pickup, and discount codes. Orders with their emails and notes, refunds by the line, exchanges, tracking, packing slips and invoices, orders taken by telephone, and a spreadsheet for the books. A stock screen with each count's history, mail when a size runs low or sells out, and a list of who is waiting for it to come back. Prices, sales and counts changed across a selection, and products in and out of a spreadsheet. Customers who sign in from a link sent to their inbox, with what is kept about them given or erased on request. Reports of what sold and the tax collected by province. A feed for Google Merchant Center and Meta's catalogue, and an API with keys and webhooks for other programs. Payment is by bank transfer, cheque or on delivery: there is no card gateway yet.
- **SEO.** Titles and descriptions with variables, structured data, a sitemap, redirects.
- **Business details.** The business's name, phone numbers, email, opening hours, place, service areas and social profiles, kept once on Settings → Business details. A page's text prints them with `[phone]`, `[sms]`, `[email]` and `[hours]`, the structured data carries them, and a theme reads them all with `\Cms\Site\Business::get()`, so a number changed there changes everywhere.
- **Google's measurement tags.** A GA4 measurement ID, a Tag Manager container ID and a Search Console verification code, typed on Settings → SEO → Google, print Google's own code on every public page in production. Each form sends one conversion event for each real submission, and clicks on phone numbers and email addresses can be sent too. A site whose own header prints its tags can have each conversion carried to its own thank-you page instead.
- **Users and sign-in security.** Roles, two-step sign-in, signing in without a password, temporary access, a sign-in page at an address of your own or one made at random, lockouts, a log of who changed what, and a check of the CMS's own files against the release they came from.
- **White-label.** Every name a visitor or an editor sees is the site's own title.
- **Modules.** Pages, menus, search, the shop, appointments, the assistant and forms can each be switched off in `config.php`; their data stays.
- **An assistant (optional).** The site owner asks in plain words. It proposes a change, a person approves the rendered difference, and only then does the CMS apply it: the model has no way to write. In a shop it also reads the orders, stock and sales, and proposes a new count, an order's move, a discount code or an option the same way. It uses the Claude API with the owner's own key and a spend limit.
- **Updates with a way back.** The updater backs up the database and every file it replaces, refuses to overwrite a file the site has changed, and rolls itself back if anything fails.
- **A static copy.** The whole site as files, for a web host that runs no CMS: every page as the site draws it, with its pictures, files and redirects, a search that runs in the visitor's browser, and the shop as a catalogue. Its links can be made to work anywhere: at any address, or opened from a folder with no web server. On a host with PHP its forms are sent by a small handler of its own, by the site's rules; without PHP each form's place shows words of your choosing. Tools → Static copy, or `php admin/bin/export-static.php`.

Nothing is fetched from a third party at runtime: no CDN scripts, no remote fonts. The exceptions are an embed a writer pastes, which loads from its provider, and Google's measurement tags once a site owner types their IDs.

## Requirements

- PHP 8.2 or later, with the PDO MySQL, mbstring, DOM and fileinfo extensions. Two-step sign-in also needs OpenSSL, the assistant and mail through Brevo need curl, and a shop's webhooks need `allow_url_fopen`. Imagick, when present, is used for WebP.
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

Images added to the library before an update get their smaller widths from `php admin/bin/media-sizes.php`.

A library whose files were imported before imports kept their dates has them all dated the day they came in. `php admin/bin/media-dates.php` puts them back: `list` shows each file's dates, `set` changes them for a range of files, and `taken` reads each photo's date from the original files it was imported from.

## A static copy

Tools → Static copy makes a copy of the site that needs no CMS, as one file to download: what is in it goes at the root of the address it was made for. The same from the command line, and from there on a host that does not let the admin start PHP:

```
php admin/bin/export-static.php https://www.example.com [--anywhere] [--no-php] [--instead="Email us at hello@example.com"]
```

- The copy's forms are sent by `forms/send.php`, which needs PHP 8.2 or later with mbstring and fileinfo, and its `forms/data/` folder writable. It mails each answer through the host's own mail program, with the visitor's files attached; `forms/config/config.php` takes an SMTP server's password instead.
- `--anywhere` ("Links that work anywhere" on the screen) makes every link relative, a page's naming its file (`news/index.html`), so the copy works at any address and opened from a folder with no web server, search included; its canonicals and sitemap still name the address given. Opened from a folder, a form shows the words given for it.
- For a host without PHP, `--no-php` (the tick-box on the screen) puts the words given in each form's place. They also stand in for a form the copy cannot send anywhere: one that books appointments, or names nobody to send to.
- A page behind a password is left out, and so are the basket, the checkout and customers' accounts: the shop's products are a catalogue.
- `.htaccess` carries the redirects and the not-found page for Apache; every host gets a page at each old address that sends the visitor on.
- What the copy could not take is listed when it is made.

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

A site's own header prints Google's measurement tags by calling `<?= \Cms\Site\Google::head() ?>` in its `<head>` and `<?= \Cms\Site\Google::body() ?>` straight after `<body>`, as a whole-page theme does. Each prints once a page, and nothing while Settings → SEO → Google is empty, so a site whose header already prints its own tags leaves it empty. `head()` takes the page's nonce when the site's Content-Security-Policy asks for one. A thank-you page of the site's own can ask `\Cms\Site\Google::conversion()` for the conversion a form's redirect left, before the page's first byte: it returns the event, the form's ID and the stored submission's ID, once, and null to a reload, another tab or a direct visit.

A site that prints its own tags and sends its own conversions sets `'site_sends_conversions' => true` in `config.php` and leaves Settings → SEO → Google empty. The CMS then prints nothing of Google's, and a real submission's conversion is still carried to the form's after-sending page ("After sending without JavaScript, go to", with "Go there after a send by script too" ticked for a form sent by script). A site's own form prints the form's hidden fields with `\Cms\Forms\Guard::fields()`, posts to `/forms/{id}/submit` with `Accept: application/json`, and reads `{success, message}`, plus `redirect`, the after-sending page as a path, to go to when there is one.

## Reporting a security problem

Email support@silverservers.com rather than opening a public issue. Say which release you found it in and how to reproduce it.

## Third-party components

- **Inter** and **Fira Mono** fonts, under the SIL Open Font License 1.1. The licence, with each font's copyright, is beside the fonts: `www/assets/fonts/OFL.txt` and `www/admin/assets/fonts/OFL.txt`.
- **Icons** by Font Awesome ([fontawesome.com](https://fontawesome.com)), under CC BY 4.0.
- **FastRoute** (BSD-3-Clause) and **PHPMailer** (LGPL-2.1), with their licences in `admin/vendor/`.

## Licence

All rights reserved. The third-party components above keep their own licences.
