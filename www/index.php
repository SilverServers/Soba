<?php

declare(strict_types=1);

// ----------------------------------------------------------------
// Imports
// ----------------------------------------------------------------

use Cms\Database\Database;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use FastRoute\Dispatcher;
use Cms\Router\Router;
use Cms\Controllers\Admin\AuthController;
use Cms\Controllers\Admin\ContentTypeController;
use Cms\Controllers\Admin\ContentTypeDefinitionController;
use Cms\Controllers\Admin\DashboardController;
use Cms\Controllers\Admin\FieldGroupController;
use Cms\Controllers\Admin\MediaController as AdminMediaController;
use Cms\Controllers\Admin\PageController;
use Cms\Controllers\Admin\PlaceholderController;
use Cms\Controllers\Admin\RedirectsController;
use Cms\Controllers\Admin\SettingsController;
use Cms\Controllers\Admin\TaxonomyController;
use Cms\Controllers\Admin\TermController;
use Cms\Controllers\Admin\ToolsController;
use Cms\Controllers\Admin\UserController;
use Cms\Controllers\Admin\ImportController;
use Cms\Controllers\Admin\MenuController as AdminMenuController;
use Cms\Controllers\Admin\GalleryController;
use Cms\Controllers\Admin\FormController as AdminFormController;
use Cms\Controllers\Api\ExportController;
use Cms\Controllers\Api\MenuController as ApiMenuController;
use Cms\Controllers\Api\MediaController as ApiMediaController;
use Cms\Controllers\Api\TaxonomyController as ApiTaxonomyController;
use Cms\Controllers\Public\HomeController;
use Cms\Controllers\Public\PostController as PublicPostController;
use Cms\Controllers\Public\PageController as PublicPageController;
use Cms\Controllers\Public\ContentTypeController as PublicContentTypeController;
use Cms\Controllers\Public\TermController as PublicTermController;
use Cms\Controllers\Public\SeoController as PublicSeoController;
use Cms\Controllers\Public\FormController as PublicFormController;
use Cms\Controllers\Public\SearchController as PublicSearchController;
use Cms\SEO\Redirects;
use Cms\Security\Csrf;

// ----------------------------------------------------------------
// Bootstrap
// ----------------------------------------------------------------

// ROOT_PATH  = non-public code (core/, config/, vendor/, admin/templates/, templates/)
// PUBLIC_PATH = web-accessible document root (www/) — uploads, admin/assets
// `defined() ||`: a site's own router may have started the CMS already and
// handed this request over (a site's own router in front) — it
// defines these four first, the same values, and requires this file.
defined('ROOT_PATH')   || define('ROOT_PATH', dirname(__DIR__) . '/admin');
defined('PUBLIC_PATH') || define('PUBLIC_PATH', __DIR__);
// This file is `www/index.php` where the CMS serves the whole site. Behind a
// site's own router it has another name (`cms.php`, or the site's
// `front_controller`), and `/` is the site's page, not the CMS's front page
// (`Helpers\Url::FRONT_PAGE`).
defined('SERVES_FRONT_PAGE') || define('SERVES_FRONT_PAGE', basename(__FILE__) === 'index.php');

// ----------------------------------------------------------------
// Maintenance — an update is replacing the CMS's files
// ----------------------------------------------------------------

// `admin/bin/update.php` raises this flag before it moves a single file and
// lowers it once the new release is migrated and has passed its smoke test.
// While it is up the code on disk may be half one release and half the next,
// and the database may be mid-migration, so nothing below may run — not even
// the autoloader. The one request let through is the update's own smoke test,
// which carries the random token written into the flag: it is how the update
// checks the new release through the real web server before anyone else sees
// it. Reading the token needs access to admin/, which is never served.
if (is_file(ROOT_PATH . '/updates/.maintenance')) {
    $maintenance = json_decode((string) @file_get_contents(ROOT_PATH . '/updates/.maintenance'), true);
    $smokeToken  = is_array($maintenance) ? (string) ($maintenance['token'] ?? '') : '';
    if ($smokeToken === '' || !hash_equals($smokeToken, (string) ($_SERVER['HTTP_X_CMS_SMOKE'] ?? ''))) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        exit("This site is being updated. Please try again in a minute.\n");
    }
    unset($maintenance, $smokeToken);
}

require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/core/Helpers/Url.php';

// Load environment config.
$configPath = ROOT_PATH . '/config/config.php';
if (!file_exists($configPath)) {
    // THE VISITOR GETS A BARE 500: the file's
    // path and the fix go to the error log, where whoever is installing
    // looks — not to every visitor of a site that lost its config. It names
    // no product: the CMS is white-labelled per install.
    error_log('CMS: ' . $configPath . ' not found. Copy config.example.php beside it and fill in values.');
    http_response_code(500);
    exit('Not configured.');
}
$config = require $configPath;

// MODULE SWITCHES: which optional parts of the CMS this install runs, from
// config.php's `modules` — every one on unless switched off. A switched-off
// module's routes are dropped once they are all registered, below
// (`Helpers\Modules`).
\Cms\Helpers\Modules::configure(is_array($config['modules'] ?? null) ? $config['modules'] : []);

// PRODUCTION REALLY MEANS PRODUCTION: outside development, an error is logged
// and never shown, and an uncaught exception is a bare 500 that names no file,
// query or product (`Helpers\ErrorPage`). Development is left as the server
// sets it.
if (strtolower((string) ($config['app_env'] ?? 'production')) !== 'development') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler([\Cms\Helpers\ErrorPage::class, 'handle']);
}

// Derive BASE_PATH from BASE_URL so the router can strip it from REQUEST_URI.
// e.g. https://example.com/site → /site
$parsedUrl = parse_url($config['base_url']);
defined('BASE_URL')  || define('BASE_URL', rtrim($config['base_url'], '/'));
defined('BASE_PATH') || define('BASE_PATH', $parsedUrl['path'] ?? '');

// THE ADMIN LIVES AT /admin. Hiding it is one setting: Security → Login
// protection → "Move the sign-in page" (`Security\LoginUrl`). A fixed admin
// address in config.php (`admin_url_slug`) is no longer read; the updater
// moves an install that had one onto the setting.
define('ADMIN_URL_SLUG', 'admin');
define('ADMIN_URL', BASE_URL . '/' . ADMIN_URL_SLUG);

// ----------------------------------------------------------------
// Database — init and run pending migrations
// ----------------------------------------------------------------

Database::init($config);
Database::runMigrations(ROOT_PATH . '/core/Database/migrations', ROOT_PATH . '/site/migrations');

// ----------------------------------------------------------------
// Session
// ----------------------------------------------------------------

$sessionLifetime = (int) ($config['session_lifetime'] ?? 7200);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (isset($config['session_name']) && $config['session_name'] !== '') {
    session_name((string) $config['session_name']);
}

// A SITE'S OWN TOOL (`Site\Tools`: admin/config/admin-tools.php, its entry in
// admin/site/tools/) is handed over here, with the database and the session
// cookie set up and before the CMS's template functions exist — a tool brings
// its own `icon()`. For any other address this returns at once.
\Cms\Site\Tools::dispatch();

// A SESSION ONLY WHERE ONE IS NEEDED (`Security\Session`).
// The admin and the sign-in page, wherever it has moved, start one up
// front; a visitor who already carries the cookie resumes theirs — a signed-in
// editor previewing a draft, an unlocked page, a cart; anything else starts one
// only when it needs one (`Session::ensure()`). An anonymous visitor to a
// public page gets no session and no cookie: nothing for them to carry, no
// write per hit, and a page a cache may keep.
$sessionPath = '/' . ltrim((string) (strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/'), '/');
if (BASE_PATH !== '' && str_starts_with($sessionPath, rtrim(BASE_PATH, '/'))) {
    $sessionPath = '/' . ltrim(substr($sessionPath, strlen(rtrim(BASE_PATH, '/'))), '/');
}
$sessionPath = $sessionPath === '/' ? '/' : rtrim($sessionPath, '/');
\Cms\Security\Session::begin(
    $sessionPath === '/' . ADMIN_URL_SLUG || str_starts_with($sessionPath, '/' . ADMIN_URL_SLUG . '/')
    || (\Cms\Security\LoginUrl::enabled() && $sessionPath === \Cms\Security\LoginUrl::path())
);

// h() / icon() / partial() for the admin templates. Functions, not classes, so
// the autoloader cannot reach them — see the file's own header. After the site
// tools' hand-over, above, which must not meet them.
require_once ROOT_PATH . '/core/Ui/globals.php';

// ----------------------------------------------------------------
// Content Types and taxonomies — code-registered, then made in the admin
// (`ContentType\Registries`, which `Site\Front::owns()` loads the same way)
// ----------------------------------------------------------------

\Cms\ContentType\Registries::load();

// ----------------------------------------------------------------
// Menu Location Registry — load registered menu locations
// ----------------------------------------------------------------

require ROOT_PATH . '/config/menus.php';

// ----------------------------------------------------------------
// Page custom fields — structured content for static Pages
// ----------------------------------------------------------------


// ----------------------------------------------------------------
// Routing
// ----------------------------------------------------------------

$router = new Router();

// -----------------------------------------------------------------------
// Public — static routes
// -----------------------------------------------------------------------
$router->addRoute('GET', '/',             [HomeController::class,    'index']);
// Pages route registered after CT routes (below) so CT archive slugs take priority

// -----------------------------------------------------------------------
// Public — SEO routes (sitemaps + robots.txt)
// -----------------------------------------------------------------------
$router->addRoute('GET', '/sitemap.xml',          [PublicSeoController::class, 'sitemapIndex']);
$router->addRoute('GET', '/sitemap-pages.xml',    [PublicSeoController::class, 'sitemapPages']);
$router->addRoute('GET', '/sitemap-{slug}.xml',   [PublicSeoController::class, 'sitemapContentType']);
$router->addRoute('GET', '/robots.txt',           [PublicSeoController::class, 'robotsTxt']);

// -----------------------------------------------------------------------
// Public — site search. What a menu's search block and search replacement
// submit to; before pages, so it is not taken for a slug.
// -----------------------------------------------------------------------
$router->addRoute('GET', '/search', [PublicSearchController::class, 'index']);

// -----------------------------------------------------------------------
// Public — form submissions
// -----------------------------------------------------------------------
$router->addRoute('POST', '/forms/{id:\d+}/submit', [PublicFormController::class, 'handleSubmit']);

// -----------------------------------------------------------------------
// Public — private file serving
// -----------------------------------------------------------------------
use Cms\Controllers\Public\FileController;
$router->addRoute('GET', '/files/{id:\d+}', [FileController::class, 'serve']);

// -----------------------------------------------------------------------
// Public — a theme's stylesheet, pictures and fonts (Helpers\Theme). The
// theme lives outside the web root; `Theme::assetFile()` decides what leaves.
// -----------------------------------------------------------------------
use Cms\Controllers\Public\ThemeAssetController;
$router->addRoute('GET', '/assets/theme/{key:[a-z0-9][a-z0-9_-]*}/{path:.+}', [ThemeAssetController::class, 'serve']);

// -----------------------------------------------------------------------
// Public — the shop
//
// EVERY MUTATION IS A POST. `GET /cart/add/12` would let any page on the
// internet fill a visitor's basket with an <img> tag, and would let the
// browser's prefetcher do it too. The controller checks CSRF on each and
// answers 303, so a refresh re-reads rather than re-submits.
//
// The receipt is reached by an unguessable KEY, never by an id — `/order/125`
// would be somebody else's name, address and telephone number. See
// `Order::findByKey()`.
// -----------------------------------------------------------------------
use Cms\Controllers\Public\ShopController;
$router->addRoute('GET',  '/cart',                    [ShopController::class, 'cart']);
$router->addRoute('POST', '/cart/add',                [ShopController::class, 'addToCart']);
$router->addRoute('POST', '/cart/update',             [ShopController::class, 'updateCart']);
$router->addRoute('POST', '/cart/coupon',             [ShopController::class, 'applyCoupon']);
$router->addRoute('GET',  '/checkout',                [ShopController::class, 'checkout']);
$router->addRoute('POST', '/checkout',                [ShopController::class, 'placeOrder']);
$router->addRoute('GET',  '/checkout/totals',         [ShopController::class, 'checkoutTotals']);
// `[a-f0-9]+` and not `[a-f0-9]{32}`: FastRoute parses the braces itself, so a
// quantifier inside one is a pattern it has to be trusted to read correctly.
// The exact length is asserted in `Order::findByKey()`, which is where it has
// to be anyway — the key is a credential and nothing may look one up without
// that check, route or no route.
$router->addRoute('GET',  '/order/{key:[a-f0-9]+}',   [ShopController::class, 'orderReceived']);

// -----------------------------------------------------------------------
// Admin — auth
//
// THE SIGN-IN PAGE LIVES WHERE THE SETTING SAYS (Security → Login protection,
// `Security\LoginUrl`). /admin/login always has a route, because it is the
// page while it has not moved and the not-found page once it has — and that
// decision is made per request by `legacyLogin()`, not by leaving the address
// unrouted. The moved address is registered HERE, before every generated
// Content Type route, so nothing generated can shadow it.
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin',        [DashboardController::class, 'index']);
$router->addRoute('GET',  '/admin/login',  [AuthController::class,      'legacyLogin']);
$router->addRoute('POST', '/admin/login',  [AuthController::class,      'legacyLogin']);
if (\Cms\Security\LoginUrl::enabled()) {
    $router->addRoute('GET',  \Cms\Security\LoginUrl::path(), [AuthController::class, 'showLogin']);
    $router->addRoute('POST', \Cms\Security\LoginUrl::path(), [AuthController::class, 'handleLogin']);
}
$router->addRoute('GET',  '/admin/logout', [AuthController::class,      'logout']);
// Between a sign-in and everything else, for an account that owes a new
// password (migration 061) — `Auth::requireAuth()` sends it here.
$router->addRoute('GET',  '/admin/choose-password', [AuthController::class, 'choosePassword']);
$router->addRoute('POST', '/admin/choose-password', [AuthController::class, 'choosePassword']);
// Two-step sign-in, the signed-in half (migration 063).
// The second step itself is `?action=two-step` at the sign-in address.
use Cms\Controllers\Admin\TwoStepController;
$router->addRoute('GET',  '/admin/two-step',        [TwoStepController::class, 'start']);
$router->addRoute('GET',  '/admin/two-step/app',    [TwoStepController::class, 'app']);
$router->addRoute('POST', '/admin/two-step/app',    [TwoStepController::class, 'app']);
$router->addRoute('GET',  '/admin/two-step/email',  [TwoStepController::class, 'email']);
$router->addRoute('POST', '/admin/two-step/email',  [TwoStepController::class, 'email']);
$router->addRoute('POST', '/admin/two-step/codes',  [TwoStepController::class, 'codes']);
$router->addRoute('POST', '/admin/two-step/remove', [TwoStepController::class, 'remove']);
$router->addRoute('GET',  '/admin/confirm',         [TwoStepController::class, 'confirm']);
$router->addRoute('POST', '/admin/confirm',         [TwoStepController::class, 'confirm']);

// -----------------------------------------------------------------------
// Admin — pages (static, hierarchical — separate from the Content Type system)
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/pages',                  [PageController::class, 'index']);
$router->addRoute('GET',  '/admin/pages/new',              [PageController::class, 'showCreate']);
$router->addRoute('POST', '/admin/pages/new',              [PageController::class, 'handleCreate']);
$router->addRoute('GET',  '/admin/pages/trash',            [PageController::class, 'showTrash']);
$router->addRoute('GET',  '/admin/pages/{id:\d+}/edit',    [PageController::class, 'showEdit']);
$router->addRoute('POST', '/admin/pages/{id:\d+}/edit',    [PageController::class, 'handleUpdate']);
$router->addRoute('POST', '/admin/pages/{id:\d+}/trash',   [PageController::class, 'handleTrash']);
$router->addRoute('POST', '/admin/pages/{id:\d+}/restore', [PageController::class, 'handleRestore']);
$router->addRoute('POST', '/admin/pages/{id:\d+}/delete',  [PageController::class, 'handleDelete']);

// -----------------------------------------------------------------------
// Admin — media library (Files & Media)
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/media',     [AdminMediaController::class, 'index']);
$router->addRoute('GET',  '/admin/media/add', [AdminMediaController::class, 'add']);
// A FOLDER IS MADE FROM THE LIBRARY SCREEN, by a plain form posted here: the
// library offers a folder FILTER, and without this route no screen could add
// to its options. `MediaController::newFolder()` has the rest.
$router->addRoute('POST', '/admin/media/folders/new', [AdminMediaController::class, 'newFolder']);
// AND DELETING ONE: whatever the admin can make, it can also remove
// (`MediaFolder::delete()`). The files survive: `attachments.folder_id` is
// ON DELETE SET NULL.
$router->addRoute('POST', '/admin/media/folders/{id:\d+}/delete', [AdminMediaController::class, 'deleteFolder']);

// -----------------------------------------------------------------------
// Admin — Components: Galleries
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/galleries',                  [GalleryController::class, 'index']);
// ── ONE WORD FOR ONE ACT: `/new` ────────────────────────────────────────
// Content Types, pages, taxonomies, field groups and coupons create at `/new`,
// while galleries, forms, menus and users used `/create`. One act, two words,
// and the only way to know which a screen wanted was to read this file.
//
// `/new` wins on count — and on the fact that every Content Type's entry routes
// are generated with it, so half the admin could not move without changing the
// generator.
//
// THE OLD ADDRESS KEEPS WORKING. A bookmark, a link in a note, the assistant's
// stored URLs: none of them should break to tidy a spelling. Both are
// registered; `/new` is what every screen links to.
$router->addRoute('GET',  '/admin/galleries/new',           [GalleryController::class, 'showCreate']);
$router->addRoute('GET',  '/admin/galleries/create',           [GalleryController::class, 'showCreate']);
$router->addRoute('POST', '/admin/galleries/new',           [GalleryController::class, 'handleCreate']);
$router->addRoute('POST', '/admin/galleries/create',           [GalleryController::class, 'handleCreate']);
$router->addRoute('GET',  '/admin/galleries/{id:\d+}/edit',    [GalleryController::class, 'showEdit']);
$router->addRoute('POST', '/admin/galleries/{id:\d+}/edit',    [GalleryController::class, 'handleUpdate']);
$router->addRoute('POST', '/admin/galleries/{id:\d+}/delete',  [GalleryController::class, 'handleDelete']);

// -----------------------------------------------------------------------
// Admin — Commerce: orders
//
// The screen a shop owner actually lives on. Payment is offline in this
// version, so their whole day is: an order arrives, the money turns up, mark
// it Processing, post it, mark it Completed.
// -----------------------------------------------------------------------
use Cms\Controllers\Admin\OrderController;
$router->addRoute('GET',  '/admin/orders',                  [OrderController::class, 'index']);
$router->addRoute('GET',  '/admin/orders/{id:\d+}',          [OrderController::class, 'show']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/status',   [OrderController::class, 'handleStatus']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/note',     [OrderController::class, 'handleNote']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/delete',   [OrderController::class, 'handleDelete']);

// ── DISCOUNT CODES ──────────────────────────────────────────────────────
// `new` before `{id}` is not an accident of order here — FastRoute matches
// static segments first regardless — but the pair is kept together so the
// two halves of "create" read as one thing.
use Cms\Controllers\Admin\CouponController;
$router->addRoute('GET',  '/admin/coupons',                 [CouponController::class, 'index']);
$router->addRoute('GET',  '/admin/coupons/new',             [CouponController::class, 'create']);
$router->addRoute('POST', '/admin/coupons/new',             [CouponController::class, 'save']);
$router->addRoute('GET',  '/admin/coupons/{id:\d+}/edit',   [CouponController::class, 'edit']);
$router->addRoute('POST', '/admin/coupons/{id:\d+}/edit',   [CouponController::class, 'save']);
$router->addRoute('POST', '/admin/coupons/{id:\d+}/delete', [CouponController::class, 'delete']);

// -----------------------------------------------------------------------
// Admin — Components: Forms
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/forms',                                      [AdminFormController::class, 'index']);
$router->addRoute('GET',  '/admin/forms/new',                               [AdminFormController::class, 'showCreate']);
$router->addRoute('GET',  '/admin/forms/create',                               [AdminFormController::class, 'showCreate']);
$router->addRoute('POST', '/admin/forms/new',                               [AdminFormController::class, 'handleCreate']);
$router->addRoute('POST', '/admin/forms/create',                               [AdminFormController::class, 'handleCreate']);
$router->addRoute('GET',  '/admin/forms/submissions',                          [AdminFormController::class, 'allSubmissions']);
$router->addRoute('GET',  '/admin/forms/submissions.csv',                      [AdminFormController::class, 'exportAllCsv']);
$router->addRoute('GET',  '/admin/forms/submissions/{sid:\d+}',                [AdminFormController::class, 'showSubmission']);
$router->addRoute('POST', '/admin/forms/submissions/{sid:\d+}/delete',         [AdminFormController::class, 'handleDeleteSubmission']);
$router->addRoute('POST', '/admin/forms/submissions/{sid:\d+}/spam',           [AdminFormController::class, 'handleMarkSpam']);
$router->addRoute('POST', '/admin/forms/submissions/{sid:\d+}/not-spam',       [AdminFormController::class, 'handleNotSpam']);
$router->addRoute('POST', '/admin/forms/submissions/bulk',                     [AdminFormController::class, 'handleBulk']);
$router->addRoute('GET',  '/admin/forms/spam',                                 [AdminFormController::class, 'spam']);
$router->addRoute('POST', '/admin/forms/spam/empty',                           [AdminFormController::class, 'handleEmptySpam']);
$router->addRoute('POST', '/admin/forms/spam/settings',                        [AdminFormController::class, 'handleSpamSettings']);
$router->addRoute('GET',  '/admin/forms/{id:\d+}/edit',                        [AdminFormController::class, 'showEdit']);
$router->addRoute('POST', '/admin/forms/{id:\d+}/edit',                        [AdminFormController::class, 'handleUpdate']);
$router->addRoute('POST', '/admin/forms/{id:\d+}/delete',                      [AdminFormController::class, 'handleDelete']);
$router->addRoute('GET',  '/admin/forms/{id:\d+}/submissions',                 [AdminFormController::class, 'submissions']);
$router->addRoute('GET',  '/admin/forms/{id:\d+}/submissions.csv',             [AdminFormController::class, 'exportCsv']);
$router->addRoute('POST', '/admin/forms/{id:\d+}/mark-all-read',              [AdminFormController::class, 'handleMarkAllRead']);

// -----------------------------------------------------------------------
// Admin — Appearance
// -----------------------------------------------------------------------
// THEMES (Helpers\Theme): the list, the switch, and a theme's screenshot for
// its card. `/admin/appearance` itself sends you to the section's first row,
// Themes.
use Cms\Controllers\Admin\ThemeController;
$router->addRoute('GET',  '/admin/appearance',                        [ThemeController::class, 'section']);
$router->addRoute('GET',  '/admin/appearance/themes',                 [ThemeController::class, 'index']);
$router->addRoute('POST', '/admin/appearance/themes/activate',        [ThemeController::class, 'activate']);
$router->addRoute('POST', '/admin/appearance/themes/delete',          [ThemeController::class, 'delete']);
$router->addRoute('GET',  '/admin/appearance/themes/{key:[a-z0-9][a-z0-9_-]*}/screenshot', [ThemeController::class, 'screenshot']);
// BRANDING (Ui\Brand, Security\SignInPage): the Admin tab and the Sign-in tab,
// one form each.
use Cms\Controllers\Admin\BrandingController;
$router->addRoute('GET',  '/admin/appearance/branding',               [BrandingController::class, 'show']);
$router->addRoute('POST', '/admin/appearance/branding',               [BrandingController::class, 'saveAdmin']);
$router->addRoute('POST', '/admin/appearance/branding/sign-in',       [BrandingController::class, 'saveSignIn']);
$router->addRoute('GET',  '/admin/appearance/menus',                  [AdminMenuController::class, 'index']);
// MANAGE LOCATIONS — the second tab. Registered BEFORE the `{id:\d+}` routes
// only for readability; `locations` cannot match `\d+`, so the order is not
// load-bearing and nothing here depends on it being kept.
$router->addRoute('GET',  '/admin/appearance/menus/locations',        [AdminMenuController::class, 'showLocations']);
$router->addRoute('POST', '/admin/appearance/menus/locations',        [AdminMenuController::class, 'handleLocations']);
// THE SWITCHER'S TARGET. "Select a menu to edit" is a GET form so that nothing
// can be saved from it by accident and so it works with no script at all — this
// turns its `menu_id` into the edit URL.
$router->addRoute('GET',  '/admin/appearance/menus/select',           [AdminMenuController::class, 'selectMenu']);
$router->addRoute('GET',  '/admin/appearance/menus/new',           [AdminMenuController::class, 'showCreate']);
$router->addRoute('GET',  '/admin/appearance/menus/create',           [AdminMenuController::class, 'showCreate']);
$router->addRoute('POST', '/admin/appearance/menus/new',           [AdminMenuController::class, 'handleCreate']);
$router->addRoute('POST', '/admin/appearance/menus/create',           [AdminMenuController::class, 'handleCreate']);
$router->addRoute('GET',  '/admin/appearance/menus/{id:\d+}/edit',    [AdminMenuController::class, 'showEdit']);
$router->addRoute('POST', '/admin/appearance/menus/{id:\d+}/edit',    [AdminMenuController::class, 'handleUpdate']);
$router->addRoute('POST', '/admin/appearance/menus/{id:\d+}/delete',  [AdminMenuController::class, 'handleDelete']);
$router->addRoute('GET',  '/admin/appearance/theme-builder',          [PlaceholderController::class, 'themeBuilder']);
$router->addRoute('GET',  '/admin/appearance/customizer',             [PlaceholderController::class, 'customizer']);

// -----------------------------------------------------------------------
// Admin — Users: create + delete (list + edit already exist below)
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/users/new',            [UserController::class, 'showCreate']);
$router->addRoute('GET',  '/admin/users/create',            [UserController::class, 'showCreate']);
$router->addRoute('POST', '/admin/users/new',            [UserController::class, 'handleCreate']);
$router->addRoute('POST', '/admin/users/create',            [UserController::class, 'handleCreate']);
$router->addRoute('POST', '/admin/users/{id:\d+}/delete',   [UserController::class, 'handleDelete']);

// -----------------------------------------------------------------------
// Admin — Assistant (chat; reads and proposes, applying requires approval)
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/assistant',                        [\Cms\Controllers\Admin\AssistantController::class, 'index']);
$router->addRoute('GET',  '/admin/assistant/{id:\d+}',               [\Cms\Controllers\Admin\AssistantController::class, 'index']);
$router->addRoute('POST', '/admin/assistant/new',                    [\Cms\Controllers\Admin\AssistantController::class, 'create']);
// /send answers JSON and records progress for /assistant/progress to report;
// /send-blocking is the same turn with no progress, for JavaScript-off browsers.
$router->addRoute('POST', '/admin/assistant/send',                   [\Cms\Controllers\Admin\AssistantController::class, 'send']);
$router->addRoute('POST', '/admin/assistant/{id:\d+}/send',          [\Cms\Controllers\Admin\AssistantController::class, 'send']);
$router->addRoute('POST', '/admin/assistant/send-blocking',          [\Cms\Controllers\Admin\AssistantController::class, 'sendBlocking']);
$router->addRoute('POST', '/admin/assistant/{id:\d+}/send-blocking', [\Cms\Controllers\Admin\AssistantController::class, 'sendBlocking']);
$router->addRoute('GET',  '/admin/assistant/progress',               [\Cms\Controllers\Admin\AssistantController::class, 'progress']);
$router->addRoute('POST', '/admin/assistant/{id:\d+}/rename',        [\Cms\Controllers\Admin\AssistantController::class, 'rename']);
$router->addRoute('POST', '/admin/assistant/{id:\d+}/delete',        [\Cms\Controllers\Admin\AssistantController::class, 'delete']);
$router->addRoute('POST', '/admin/assistant/message/{id:\d+}/apply',   [\Cms\Controllers\Admin\AssistantController::class, 'apply']);
$router->addRoute('POST', '/admin/assistant/message/{id:\d+}/discard', [\Cms\Controllers\Admin\AssistantController::class, 'discard']);
$router->addRoute('POST', '/admin/assistant/message/{id:\d+}/undo',    [\Cms\Controllers\Admin\AssistantController::class, 'undo']);

// -----------------------------------------------------------------------
// Admin — Revisions (restore a post or page to an earlier version)
// -----------------------------------------------------------------------
$router->addRoute('POST', '/admin/revisions/{id:\d+}/restore', [\Cms\Controllers\Admin\RevisionController::class, 'handleRestore']);

// -----------------------------------------------------------------------
// Admin — Tools
// -----------------------------------------------------------------------
$router->addRoute('GET', '/admin/tools',                [ToolsController::class, 'index']);
$router->addRoute('GET', '/admin/tools/import-export', [ToolsController::class, 'importExport']);
// IMPORT IS TWO ROUTES BECAUSE IT IS TWO STEPS: one reads the file and shows
// what it would do, the other does it. `Content\Import`'s header has the
// argument — an import that reads a file and starts writing is a destructive
// operation with no confirm step, run against content nobody can get back.
$router->addRoute('POST', '/admin/tools/import',       [ImportController::class, 'plan']);
$router->addRoute('POST', '/admin/tools/import/apply', [ImportController::class, 'apply']);
$router->addRoute('GET', '/admin/tools/system-health', [ToolsController::class, 'systemHealth']);

// Redirects — a tool of its own, not a tab on Settings → SEO;
// `RedirectsController`'s header has the reasoning. The old address,
// `/admin/settings/seo?tab=redirects`, forwards here, in showSeo().
$router->addRoute('GET',  '/admin/tools/redirects',                    [RedirectsController::class, 'index']);
$router->addRoute('POST', '/admin/tools/redirects',                    [RedirectsController::class, 'store']);
$router->addRoute('POST', '/admin/tools/redirects/{id:\d+}',           [RedirectsController::class, 'update']);
$router->addRoute('POST', '/admin/tools/redirects/{id:\d+}/delete',    [RedirectsController::class, 'delete']);

// -----------------------------------------------------------------------
// Admin — Security
// -----------------------------------------------------------------------
use Cms\Controllers\Admin\SecurityController;
$router->addRoute('GET',  '/admin/security',                          [SecurityController::class, 'overview']);
$router->addRoute('GET',  '/admin/security/login-protection',         [SecurityController::class, 'loginProtection']);
$router->addRoute('POST', '/admin/security/login-protection',         [SecurityController::class, 'saveLoginProtection']);
$router->addRoute('GET',  '/admin/security/activity',                 [SecurityController::class, 'activity']);
$router->addRoute('POST', '/admin/security/lockouts/{id:\d+}/lift',   [SecurityController::class, 'lift']);
$router->addRoute('POST', '/admin/security/notifications',            [SecurityController::class, 'saveNotifications']);
$router->addRoute('POST', '/admin/security/notifications/test',       [SecurityController::class, 'testNotification']);
$router->addRoute('POST', '/admin/security/passwords/reset-all',     [SecurityController::class, 'resetAllPasswords']);
$router->addRoute('GET',  '/admin/security/two-step',                 [SecurityController::class, 'twoStep']);
$router->addRoute('POST', '/admin/security/two-step',                 [SecurityController::class, 'saveTwoStep']);
// Signing in without a password (`Security\Passwordless`).
$router->addRoute('GET',  '/admin/security/passwordless',             [SecurityController::class, 'passwordless']);
$router->addRoute('POST', '/admin/security/passwordless',             [SecurityController::class, 'savePasswordless']);
$router->addRoute('GET',  '/admin/security/temporary-access',         [SecurityController::class, 'temporaryAccess']);
$router->addRoute('POST', '/admin/security/temporary-access',         [SecurityController::class, 'saveTemporaryAccess']);
$router->addRoute('GET',  '/admin/security/https',                    [SecurityController::class, 'https']);
$router->addRoute('POST', '/admin/security/https',                    [SecurityController::class, 'saveHttps']);
$router->addRoute('GET',  '/admin/security/blocking',                 [SecurityController::class, 'blocking']);
$router->addRoute('POST', '/admin/security/blocking',                 [SecurityController::class, 'saveBlocking']);
// The CMS's own files against what shipped (`Security\FileCheck`).
$router->addRoute('GET',  '/admin/security/files',                    [SecurityController::class, 'files']);

// -----------------------------------------------------------------------
// Admin — Settings
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/settings',            [SettingsController::class, 'index']);
$router->addRoute('GET',  '/admin/settings/general',    [SettingsController::class, 'showGeneral']);
$router->addRoute('POST', '/admin/settings/general',    [SettingsController::class, 'handleGeneral']);
$router->addRoute('GET',  '/admin/settings/seo',        [SettingsController::class, 'showSeo']);
$router->addRoute('POST', '/admin/settings/seo',        [SettingsController::class, 'handleSeo']);
$router->addRoute('GET',  '/admin/settings/permalinks', [SettingsController::class, 'showPermalinks']);
// ONE KEY: HOW AN ADDRESS ENDS. The structure is not offered — see
// `SettingsController::showPermalinks()` — because the router does not read
// one. The slash has readers: `Url::page()`, and the 301 in `Url::settle()`.
$router->addRoute('POST', '/admin/settings/permalinks', [SettingsController::class, 'handlePermalinks']);
$router->addRoute('GET',  '/admin/settings/email',      [SettingsController::class, 'showEmail']);
$router->addRoute('POST', '/admin/settings/email',      [SettingsController::class, 'handleEmail']);
$router->addRoute('GET',  '/admin/settings/commerce',   [SettingsController::class, 'showCommerce']);
$router->addRoute('POST', '/admin/settings/commerce',   [SettingsController::class, 'handleCommerce']);

// -----------------------------------------------------------------------
// API — media
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/api/v1/media',                          [ApiMediaController::class, 'list']);
$router->addRoute('POST', '/api/v1/media/upload',                   [ApiMediaController::class, 'upload']);
$router->addRoute('POST', '/api/v1/media/{id:\d+}/update',          [ApiMediaController::class, 'update']);
$router->addRoute('POST', '/api/v1/media/{id:\d+}/replace',         [ApiMediaController::class, 'replace']);
$router->addRoute('GET',  '/api/v1/media/{id:\d+}/versions',        [ApiMediaController::class, 'versions']);
$router->addRoute('POST', '/api/v1/media/{id:\d+}/delete',          [ApiMediaController::class, 'destroy']);
$router->addRoute('GET',  '/api/v1/media/folders',                   [ApiMediaController::class, 'listFolders']);
$router->addRoute('POST', '/api/v1/media/folders',                   [ApiMediaController::class, 'createFolder']);
$router->addRoute('POST', '/api/v1/media/folders/{id:\d+}/update',  [ApiMediaController::class, 'updateFolder']);
$router->addRoute('POST', '/api/v1/media/folders/{id:\d+}/delete',  [ApiMediaController::class, 'deleteFolder']);

// -----------------------------------------------------------------------
// API — export (JSON downloads)
// -----------------------------------------------------------------------
$router->addRoute('GET', '/api/v1/export/all',                  [ExportController::class, 'all']);
$router->addRoute('GET', '/api/v1/export/pages',                [ExportController::class, 'pages']);
$router->addRoute('GET', '/api/v1/export/settings',             [ExportController::class, 'settings']);
$router->addRoute('GET', '/api/v1/export/content-type/{key}',   [ExportController::class, 'contentType']);

// -----------------------------------------------------------------------
// API — menus
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/api/v1/menus/{id:\d+}/items',     [ApiMenuController::class, 'items']);
$router->addRoute('POST', '/api/v1/menus/{id:\d+}/items',     [ApiMenuController::class, 'saveItems']);
$router->addRoute('GET',  '/api/v1/menu-sources/pages',       [ApiMenuController::class, 'sourcePages']);
$router->addRoute('GET',  '/api/v1/menu-sources/content-types', [ApiMenuController::class, 'sourceContentTypes']);
$router->addRoute('GET',  '/api/v1/menu-sources/terms',         [ApiMenuController::class, 'sourceTerms']);

// -----------------------------------------------------------------------
// Admin — users + profile
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/users',               [UserController::class, 'index']);
$router->addRoute('GET',  '/admin/users/{id:\d+}/edit', [UserController::class, 'showEdit']);
$router->addRoute('POST', '/admin/users/{id:\d+}/edit', [UserController::class, 'handleUpdate']);
// Where an account is signed in (migration 062) — one browser, or all the others.
$router->addRoute('POST', '/admin/users/{id:\d+}/sessions/{sid:\d+}/end', [UserController::class, 'endSession']);
$router->addRoute('POST', '/admin/users/{id:\d+}/sessions/end-all',      [UserController::class, 'endAllSessions']);
// Its two-step sign-in (migration 063): remembered browsers, and — for an
// administrator, on someone else's account — turning it off.
$router->addRoute('POST', '/admin/users/{id:\d+}/two-step/browsers/{bid:\d+}/forget', [TwoStepController::class, 'forgetBrowser']);
$router->addRoute('POST', '/admin/users/{id:\d+}/two-step/browsers/forget-all',       [TwoStepController::class, 'forgetBrowsers']);
$router->addRoute('POST', '/admin/users/{id:\d+}/two-step/off',                        [TwoStepController::class, 'turnOff']);
// Suspended or restored (migration 067, `Security\Accounts`) — by an administrator.
$router->addRoute('POST', '/admin/users/{id:\d+}/suspend', [UserController::class, 'suspend']);
$router->addRoute('POST', '/admin/users/{id:\d+}/restore', [UserController::class, 'restore']);

// -----------------------------------------------------------------------
// Admin — Content Types (UI manager for content type definitions)
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/content-types',                 [ContentTypeDefinitionController::class, 'index']);
$router->addRoute('GET',  '/admin/content-types/new',             [ContentTypeDefinitionController::class, 'showCreate']);
$router->addRoute('POST', '/admin/content-types/new',             [ContentTypeDefinitionController::class, 'handleCreate']);
$router->addRoute('GET',  '/admin/content-types/{id:\d+}/edit',   [ContentTypeDefinitionController::class, 'showEdit']);
$router->addRoute('POST', '/admin/content-types/{id:\d+}/edit',   [ContentTypeDefinitionController::class, 'handleUpdate']);
$router->addRoute('POST', '/admin/content-types/{id:\d+}/delete', [ContentTypeDefinitionController::class, 'handleDelete']);

// -----------------------------------------------------------------------
// Admin — Field Groups
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/field-groups',                 [FieldGroupController::class, 'index']);
$router->addRoute('GET',  '/admin/field-groups/new',             [FieldGroupController::class, 'showCreate']);
$router->addRoute('POST', '/admin/field-groups/new',             [FieldGroupController::class, 'handleCreate']);
$router->addRoute('GET',  '/admin/field-groups/{id:\d+}/edit',   [FieldGroupController::class, 'showEdit']);
$router->addRoute('POST', '/admin/field-groups/{id:\d+}/edit',   [FieldGroupController::class, 'handleUpdate']);
$router->addRoute('POST', '/admin/field-groups/{id:\d+}/delete', [FieldGroupController::class, 'handleDelete']);

// -----------------------------------------------------------------------
// Admin — Taxonomies (UI manager for taxonomy definitions)
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/taxonomies',                 [TaxonomyController::class, 'index']);
$router->addRoute('GET',  '/admin/taxonomies/new',             [TaxonomyController::class, 'showCreate']);
$router->addRoute('POST', '/admin/taxonomies/new',             [TaxonomyController::class, 'handleCreate']);
$router->addRoute('GET',  '/admin/taxonomies/{id:\d+}/edit',   [TaxonomyController::class, 'showEdit']);
$router->addRoute('POST', '/admin/taxonomies/{id:\d+}/edit',   [TaxonomyController::class, 'handleUpdate']);
$router->addRoute('POST', '/admin/taxonomies/{id:\d+}/delete', [TaxonomyController::class, 'handleDelete']);

// -----------------------------------------------------------------------
// API — Taxonomy terms (typeahead search, post/term assignments)
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/api/v1/taxonomies/{key}/terms',            [ApiTaxonomyController::class, 'listTerms']);
$router->addRoute('POST', '/api/v1/taxonomies/{key}/terms',            [ApiTaxonomyController::class, 'createTerm']);
$router->addRoute('GET',  '/api/v1/posts/{postId:\d+}/terms/{key}',    [ApiTaxonomyController::class, 'getPostTerms']);
$router->addRoute('POST', '/api/v1/posts/{postId:\d+}/terms/{key}',    [ApiTaxonomyController::class, 'setPostTerms']);

// -----------------------------------------------------------------------
// Term routes — auto-generated from TaxonomyRegistry.
// Each taxonomy gets 7 admin routes for term CRUD.
// Handler: [class, method, taxonomyKey] — 3rd element passed to TermController constructor.
// -----------------------------------------------------------------------
foreach (TaxonomyRegistry::all() as $taxKey => $tax) {
    $s = $tax['admin_slug']; // e.g. 'event-categories'

    $router->addRoute('GET',  "/admin/taxonomies/{$s}/terms",                  [TermController::class, 'index',        $taxKey]);
    $router->addRoute('GET',  "/admin/taxonomies/{$s}/terms/new",              [TermController::class, 'showCreate',   $taxKey]);
    $router->addRoute('POST', "/admin/taxonomies/{$s}/terms/new",              [TermController::class, 'handleCreate', $taxKey]);
    $router->addRoute('GET',  "/admin/taxonomies/{$s}/terms/{id:\\d+}/edit",   [TermController::class, 'showEdit',     $taxKey]);
    $router->addRoute('POST', "/admin/taxonomies/{$s}/terms/{id:\\d+}/edit",   [TermController::class, 'handleUpdate', $taxKey]);
    $router->addRoute('POST', "/admin/taxonomies/{$s}/terms/{id:\\d+}/delete", [TermController::class, 'handleDelete', $taxKey]);
    // Several terms at once — delete, or merge into one.
    $router->addRoute('POST', "/admin/taxonomies/{$s}/terms/bulk",             [TermController::class, 'handleBulk',   $taxKey]);
}

// -----------------------------------------------------------------------
// Content Type routes — auto-generated from the registry.
// Each Content Type gets 10 admin routes + 3 public routes.
// Handler: [class, method, contentTypeKey] — 3rd element passed to constructor.
// -----------------------------------------------------------------------
foreach (Registry::all() as $contentTypeKey => $contentType) {
    $s = $contentType['admin_slug'];  // admin URL segment, e.g. 'news'
    $p = $contentType['public_slug']; // public URL segment, e.g. 'news' — null = no public routes

    if ($p !== null && $p !== '') {
        // Public archive (listing) page
        $router->addRoute('GET', "/{$p}",        [PublicContentTypeController::class,  'archive', $contentTypeKey]);

        // Public single-item view.
        //
        // POST goes to the SAME handler because a password-protected entry's
        // gate posts back to its own URL — `PostController::show()` reads
        // `$_POST['content_password']`, verifies it and sets the session key.
        // Without this route, submitting the gate would return a bare
        // **405 Method Not Allowed** with no chrome, and a password-protected
        // entry could never be opened by anyone.
        $router->addRoute('GET',  "/{$p}/{slug}", [PublicPostController::class, 'show', $contentTypeKey]);
        $router->addRoute('POST', "/{$p}/{slug}", [PublicPostController::class, 'show', $contentTypeKey]);
    }

    // Admin CRUD
    $router->addRoute('GET',  "/admin/{$s}",                  [ContentTypeController::class, 'index',         $contentTypeKey]);
    $router->addRoute('GET',  "/admin/{$s}/new",              [ContentTypeController::class, 'showCreate',    $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/new",              [ContentTypeController::class, 'handleCreate',  $contentTypeKey]);
    $router->addRoute('GET',  "/admin/{$s}/trash",            [ContentTypeController::class, 'showTrash',     $contentTypeKey]);
    $router->addRoute('GET',  "/admin/{$s}/{id:\\d+}/edit",   [ContentTypeController::class, 'showEdit',      $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/edit",   [ContentTypeController::class, 'handleUpdate',  $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/trash",  [ContentTypeController::class, 'handleTrash',   $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/restore",[ContentTypeController::class, 'handleRestore', $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/delete", [ContentTypeController::class, 'handleDelete',  $contentTypeKey]);
    // One action on the entries ticked in the list or the trash.
    $router->addRoute('POST', "/admin/{$s}/bulk",             [ContentTypeController::class, 'handleBulk',    $contentTypeKey]);
}

// -----------------------------------------------------------------------
// Public — a term's archive. Migration 048.
//
// `/{taxonomy}/{term}` is the SAME TWO-SEGMENT SHAPE as `/{type}/{entry}`, so
// these are registered after the Content Type routes above and a type wins any
// tie. `TaxonomyController` refuses a public slug that a content type already
// uses, which is where the collision gets explained rather than silently
// resolved by route order.
// -----------------------------------------------------------------------
foreach (TaxonomyRegistry::all() as $taxKey => $taxonomy) {
    $tp = (string) ($taxonomy['public_slug'] ?? '');
    if ($tp === '') {
        continue;   // null means the owner does not want public pages for it
    }

    $router->addRoute('GET', "/{$tp}/{term}", [PublicTermController::class, 'archive', $taxKey]);
}

// -----------------------------------------------------------------------
// Public — pages (catch-all, registered after CT routes so CT archives win)
// -----------------------------------------------------------------------
// POST for the same reason as the single-entry route above: the password gate
// posts back to the page's own URL and PageController::show() handles it.
$router->addRoute('GET',  '/{slug}', [PublicPageController::class, 'show']);
$router->addRoute('POST', '/{slug}', [PublicPageController::class, 'show']);

// A switched-off module registers no routes: its addresses 404 (Helpers\Modules).
$router->keepOnly([\Cms\Helpers\Modules::class, 'allowsRoute']);

// ----------------------------------------------------------------
// Redirect check — runs before dispatch
// ----------------------------------------------------------------

$requestPath = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
$basePath    = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath));
}
$requestPath = '/' . ltrim($requestPath, '/');
if ($requestPath !== '/') {
    $requestPath = rtrim($requestPath, '/');
}

// THE ADMIN IS NEVER REDIRECTED, and a rule — exact, or a `/*` covering a whole
// section (`Redirects::forRequest()`) — carries
// the visitor's query string along.
$redirect = ($requestPath === '/admin' || str_starts_with($requestPath, '/admin/'))
    ? null
    : Redirects::forRequest($requestPath, (string) ($_SERVER['QUERY_STRING'] ?? ''));

// A RULE THAT POINTS AT THE PAGE IT IS ON IS NOT FOLLOWED. `/about` → `/about/`
// is the commonest rule a site moved from another CMS brings with it, and the
// lookup above trims the request's slash before it looks — so the destination
// would match its own rule again, and the browser would give up on the loop.
// The page answers instead, and its controller settles the ending
// (`Url::settle()`). `Redirects::pageOf()` is the same test the Redirects
// screen refuses a new rule with.
// AND THE SIGN-IN PAGE IS NEVER REDIRECTED AWAY. Every screen that can create
// a redirect refuses its address (`Slug::reservedFirstSegments()`), but a rule
// that arrives another way — an import, a row edited by hand — must not be able
// to take the only way into the admin.
if ($redirect !== null
    && $requestPath !== \Cms\Security\LoginUrl::path()
    && Redirects::pageOf((string) $redirect['to']) !== $requestPath) {
    Redirects::incrementHitCount((int) $redirect['id']);
    http_response_code((int) $redirect['type']);
    // THROUGH Url::resolve, NOT RAW. The lookup above strips BASE_PATH from the
    // request, so the Location header has to put it back: on an install in a
    // subdirectory every stored path — the shape the admin recommends — would
    // otherwise send visitors to the same path at the DOMAIN root, off the
    // site. `resolve()` prefixes a site path with BASE_URL and leaves a full
    // address alone, which is the same rule the admin's own link fields follow.
    header('Location: ' . $redirect['location']);
    exit;
}

// ----------------------------------------------------------------
// Security headers — the admin and the sign-in page (`Security\Door`)
//
// These go on the admin and the sign-in page and nowhere else: the public site
// is the owner's to frame and cache as they like; the admin is not. `Door`
// holds the list, because a site's own tools (`Site\Tools`) leave before this
// point and must send the same one.
// ----------------------------------------------------------------

// Every admin route is written literally as `/admin/…` (the slug changes
// generated links only), so both prefixes are the admin's.
$isAdminPath = static function (string $path): bool {
    foreach (array_unique(['admin', ADMIN_URL_SLUG]) as $slug) {
        if ($path === '/' . $slug || str_starts_with($path, '/' . $slug . '/')) {
            return true;
        }
    }
    return false;
};

// Who changed what (`Security\Audit`): a POST to the admin or the API that
// writes anything but bookkeeping is one row in the security log, at shutdown.
// FIRST, before anything below can record an event: an event queues the
// notification channels' look (`Alerts::poke()`), shutdown runs in the order
// registered, and the look writes its own bookkeeping — which, run first,
// would be counted as this request's change. Its row is theirs to see.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($isAdminPath($requestPath) || str_starts_with($requestPath, '/api/'))) {
    \Cms\Security\Audit::watch($requestPath);
}

\Cms\Security\Door::everywhere();
if ($isAdminPath($requestPath) || $requestPath === \Cms\Security\LoginUrl::path()) {
    \Cms\Security\Door::adminHeaders();
    // Before the form token or the router. The public site is not affected.
    \Cms\Security\Door::checkAddress();
}

// ----------------------------------------------------------------
// Someone looking for usernames — in the places WordPress keeps them, on the
// public site (`Security\Enumeration`). Written down, then answered exactly as
// before: there is nothing at any of them to find.
// ----------------------------------------------------------------

if (!$isAdminPath($requestPath)) {
    \Cms\Security\Enumeration::watch($requestPath, $_GET);
}

// The notification channels look at the end of every request — how the tail of
// a burst goes out once its quiet window ends, with no cron (`Security\Alerts`).
// One settings read when nothing is due; when something is, it is sent after
// the page has gone, and nobody waits on it.
// Once a day, the CMS's files against what shipped (`Security\FileCheck`) —
// before the channels look, so a finding is theirs to send in the same breath.
\Cms\Security\FileCheck::poke();
\Cms\Security\Alerts::poke();

// ----------------------------------------------------------------
// CSRF protection — validate token on all admin/API POST requests
// ----------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfExempt = ['/admin/login', '/forms/'];
    $needsCsrf  = str_starts_with($requestPath, '/admin/') || str_starts_with($requestPath, '/api/');

    foreach ($csrfExempt as $exempt) {
        if (str_starts_with($requestPath, $exempt)) {
            $needsCsrf = false;
            break;
        }
    }

    if ($needsCsrf && !\Cms\Security\Csrf::validate()) {
        // A signed-out POST has no token by definition, and "Invalid CSRF
        // token" would confirm the address to somebody the moved sign-in page
        // is meant to be hiding it from. Signed out, the API gets what
        // `Auth::requireAuth()` gives it — a 401 it can read — and everything
        // else the old-address rule.
        if (!\Cms\Auth\Auth::check()) {
            if (str_starts_with($requestPath, '/api/')) {
                \Cms\Auth\Auth::denyApi(401, 'Not signed in.');
            }
            if (\Cms\Security\LoginUrl::enabled()) {
                \Cms\Security\LoginUrl::turnAway();
            }
        }

        http_response_code(403);
        if (str_starts_with($requestPath, '/api/')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.']);
        } else {
            echo 'Invalid or missing CSRF token. Please go back and try again.';
        }
        exit;
    }
}

// ----------------------------------------------------------------
// Dispatch
// ----------------------------------------------------------------

$result = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

switch ($result[0]) {
    case Dispatcher::FOUND:
        [$status, $handler, $vars] = $result;

        // Handler is [class, method] or [class, method, contentTypeKey].
        // ContentTypeController and TermController receive the 3rd element via constructor.
        $controllerClass  = $handler[0];
        $method           = $handler[1];
        $contentTypeKey   = $handler[2] ?? null;

        $controller = $contentTypeKey !== null
            ? new $controllerClass($contentTypeKey)
            : new $controllerClass();
        $controller->$method($vars);
        break;

    case Dispatcher::NOT_FOUND:
        // A mistyped admin URL must not render the *public* site's 404 —
        // masthead, marketing footer, "Back to home" — inside the admin, which
        // reads as the CMS having thrown the user out. Admin URLs get an admin
        // 404.
        //
        // BUT ONLY FOR SOMEONE SIGNED IN. To anyone else the admin's own 404
        // announces the admin — and once the sign-in page has moved, the answer
        // for a signed-out visitor at ANY admin address, real or not, is the
        // same old-address rule (`LoginUrl::turnAway()`), or real routes could
        // be told from invented ones by how they fail.
        if ($isAdminPath($requestPath)) {
            if (\Cms\Auth\Auth::check()) {
                http_response_code(404);
                require ROOT_PATH . '/admin/templates/404.php';
                break;
            }
            if (\Cms\Security\LoginUrl::enabled()) {
                \Cms\Security\LoginUrl::turnAway();
            }
        }

        // Set basic SEO for 404 page. The page itself is the whole-page
        // theme's, if it has one — `Template::notFound()`, which the hidden
        // sign-in page's 404 calls too, so the two stay identical.
        \Cms\SEO\Head::set([
            'title'  => 'Page Not Found | ' . \Cms\SEO\Head::globalTokens()['site_name'],
            'robots' => 'noindex, follow',
        ]);
        \Cms\Helpers\Template::notFound();
        break;

    case Dispatcher::METHOD_NOT_ALLOWED:
        // "Method not allowed" says the address exists. Signed out, with the
        // sign-in page moved, an admin address says nothing of the kind.
        if ($isAdminPath($requestPath) && !\Cms\Auth\Auth::check() && \Cms\Security\LoginUrl::enabled()) {
            \Cms\Security\LoginUrl::turnAway();
        }

        http_response_code(405);
        header('Allow: ' . implode(', ', $result[1]));
        echo '405 Method Not Allowed';
        break;
}
