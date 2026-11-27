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
    // A SITE NEVER SET UP opens the set-up at /install (`Install\Installer`).
    if (\Cms\Install\Installer::neverSetUp()) {
        \Cms\Install\Installer::handle();
    }
    // ONE THAT LOST ITS config.php GETS A BARE 500: the file's path and the
    // fix go to the error log, where whoever runs it looks — not to every
    // visitor, and never a way to point the site at another database. It
    // names no product: the CMS is white-labelled per install.
    error_log('CMS: ' . $configPath . ' not found. Copy config.example.php beside it and fill in values.');
    http_response_code(500);
    exit('Not configured.');
}
// READ QUIETLY (`Site\ConfigFile::read()`): a blank line or a byte-order
// mark before its `<?php` is dropped, not printed ahead of the page's headers.
$config = \Cms\Site\ConfigFile::read($configPath);
// A site that has run with a config.php is never offered the set-up again,
// should it lose that file (`Install\Installer::markSetUp()`).
\Cms\Install\Installer::markSetUp();

// MODULE SWITCHES: which optional parts of the CMS this install runs, from
// config.php's `modules` — every one on unless switched off. A switched-off
// module's routes are dropped once they are all registered, below
// (`Helpers\Modules`).
\Cms\Helpers\Modules::configure(is_array($config['modules'] ?? null) ? $config['modules'] : []);

// PRODUCTION REALLY MEANS PRODUCTION: outside development, an error is logged
// and never shown, and an uncaught exception is a bare 500 that names no file,
// query or product (`Helpers\ErrorPage`). Development is left as the server
// sets it. `app_env` is read as everything reads it (`Site\Environment`).
if (\Cms\Site\Environment::read($config['app_env'] ?? null) !== \Cms\Site\Environment::DEVELOPMENT) {
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
// protection → Sign-in address (`Security\LoginUrl`). A fixed admin
// address in config.php (`admin_url_slug`) is no longer read; the updater
// moves an install that had one onto the setting.
define('ADMIN_URL_SLUG', 'admin');
define('ADMIN_URL', BASE_URL . '/' . ADMIN_URL_SLUG);

// ----------------------------------------------------------------
// Database — init and run pending migrations
// ----------------------------------------------------------------

Database::init($config);
// ANOTHER PROCESS MIGRATING, an update's run or a visit that came first, and
// holding the lock longer than a visit waits (`Migrator::run()`): not a fault
// but a moment, answered as the update's flag above answers, so a visitor
// tries again and a search engine comes back instead of noting an error.
try {
    Database::runMigrations(ROOT_PATH . '/core/Database/migrations', ROOT_PATH . '/site/migrations');
} catch (\Cms\Database\MigrationsBusy $e) {
    error_log('CMS: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 30');
    \Cms\Http\Cache::noStore();
    header('Content-Type: text/plain; charset=utf-8');
    exit("This site is being updated. Please try again in a minute.\n");
}

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
// A PUBLIC PAGE ANSWERED TO A SIGNED-IN ACCOUNT may carry its way into the
// admin (`Site\EditBar`), so no browser or proxy keeps it for anyone else.
\Cms\Site\EditBar::begin();

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

\Cms\Site\ConfigFile::read(ROOT_PATH . '/config/menus.php');

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
// THE PRODUCTS, for Google Merchant Center and Meta's catalogue to fetch
// (`Commerce\ProductFeed`).
$router->addRoute('GET', '/feed/products.xml',    [\Cms\Controllers\Public\FeedController::class, 'products']);
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
// A page left open long renews its form's token here (form.js).
$router->addRoute('POST', '/forms/{id:\d+}/token', [PublicFormController::class, 'handleToken']);

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
$router->addRoute('POST', '/shop/notify',             [ShopController::class, 'notifyWhenBack']);
// A CUSTOMER'S ORDERS, behind a link to their inbox (`Commerce\CustomerAccount`).
$router->addRoute('GET',  '/account',                          [\Cms\Controllers\Public\AccountController::class, 'show']);
$router->addRoute('POST', '/account',                          [\Cms\Controllers\Public\AccountController::class, 'requestLink']);
$router->addRoute('GET',  '/account/enter/{token:[a-f0-9]{64}}', [\Cms\Controllers\Public\AccountController::class, 'enterPage']);
$router->addRoute('POST', '/account/enter/{token:[a-f0-9]{64}}', [\Cms\Controllers\Public\AccountController::class, 'enter']);
$router->addRoute('POST', '/account/leave',                    [\Cms\Controllers\Public\AccountController::class, 'leave']);
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
$router->addRoute('POST', '/admin/welcome/hide', [DashboardController::class, 'hideWelcome']);
// FIND ANYTHING BY ITS NAME (`Ui\Find`): the top bar's box, sent without a
// script or before its list has come, opens the first thing found.
$router->addRoute('GET', '/admin/find', [\Cms\Controllers\Admin\FindController::class, 'open']);
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
$router->addRoute('POST', '/admin/pages/{id:\d+}/copy',    [PageController::class, 'handleCopy']);
// Its version in the second language (`Content\Translation`).
$router->addRoute('POST', '/admin/pages/{id:\d+}/translate', [PageController::class, 'handleTranslate']);
$router->addRoute('POST', '/admin/pages/{id:\d+}/share',   [PageController::class, 'handleShare']);
$router->addRoute('POST', '/admin/pages/{id:\d+}/share/stop', [PageController::class, 'handleStopShare']);
$router->addRoute('POST', '/admin/pages/{id:\d+}/trash',   [PageController::class, 'handleTrash']);
$router->addRoute('POST', '/admin/pages/{id:\d+}/restore', [PageController::class, 'handleRestore']);
$router->addRoute('POST', '/admin/pages/{id:\d+}/delete',  [PageController::class, 'handleDelete']);

// -----------------------------------------------------------------------
// Admin — media library (Files & Media)
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/media',     [AdminMediaController::class, 'index']);
$router->addRoute('GET',  '/admin/media/add', [AdminMediaController::class, 'add']);
// FOLDERS ARE MANAGED ON A SCREEN OF THEIR OWN (Files and media → Manage
// folders): made, renamed and deleted there by plain forms posted here, while
// the library's folder row only chooses one. `MediaController` has the rest.
$router->addRoute('GET',  '/admin/media/folders',     [AdminMediaController::class, 'folders']);
$router->addRoute('POST', '/admin/media/folders/new', [AdminMediaController::class, 'newFolder']);
$router->addRoute('POST', '/admin/media/folders/{id:\d+}/rename', [AdminMediaController::class, 'renameFolder']);
// AND DELETING ONE: whatever the admin can make, it can also remove
// (`MediaFolder::delete()`). The files survive: `attachments.folder_id` is
// ON DELETE SET NULL.
$router->addRoute('POST', '/admin/media/folders/{id:\d+}/delete', [AdminMediaController::class, 'deleteFolder']);

// -----------------------------------------------------------------------
// Admin — Components: Galleries
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/admin/galleries',                  [GalleryController::class, 'index']);
// A site's own settings pages (`Site\SettingsPages`), for the roles each names.
$router->addRoute('GET',  '/admin/site-settings/{key:[a-z0-9][a-z0-9-]*}', [\Cms\Controllers\Admin\SettingsPageController::class, 'show']);
$router->addRoute('POST', '/admin/site-settings/{key:[a-z0-9][a-z0-9-]*}', [\Cms\Controllers\Admin\SettingsPageController::class, 'handle']);
// Files and media → Picture places (`Models\PicturePlace`).
$router->addRoute('GET',  '/admin/picture-places',             [\Cms\Controllers\Admin\PicturePlaceController::class, 'show']);
$router->addRoute('POST', '/admin/picture-places',             [\Cms\Controllers\Admin\PicturePlaceController::class, 'handle']);
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
$router->addRoute('GET',  '/admin/orders/new',              [OrderController::class, 'create']);
$router->addRoute('POST', '/admin/orders/new',              [OrderController::class, 'handleCreate']);
$router->addRoute('POST', '/admin/orders/bulk',             [OrderController::class, 'handleBulk']);
$router->addRoute('GET',  '/admin/reports',                 [\Cms\Controllers\Admin\ReportController::class, 'index']);
$router->addRoute('GET',  '/admin/reports/export',          [\Cms\Controllers\Admin\ReportController::class, 'export']);
$router->addRoute('GET',  '/admin/customers',               [\Cms\Controllers\Admin\CustomerController::class, 'index']);
$router->addRoute('GET',  '/admin/customers/{key:[0-9a-f]{16}}', [\Cms\Controllers\Admin\CustomerController::class, 'show']);
$router->addRoute('GET',  '/admin/customers/{key:[0-9a-f]{16}}/data',  [\Cms\Controllers\Admin\CustomerController::class, 'data']);
$router->addRoute('POST', '/admin/customers/{key:[0-9a-f]{16}}/erase', [\Cms\Controllers\Admin\CustomerController::class, 'erase']);
$router->addRoute('GET',  '/admin/stock',                   [\Cms\Controllers\Admin\StockController::class, 'index']);
$router->addRoute('POST', '/admin/stock',                   [\Cms\Controllers\Admin\StockController::class, 'handleSave']);
$router->addRoute('GET',  '/admin/stock/{id:\d+}',          [\Cms\Controllers\Admin\StockController::class, 'history']);
$router->addRoute('GET',  '/admin/orders/export.csv',       [OrderController::class, 'export']);
$router->addRoute('GET',  '/admin/orders/print',            [OrderController::class, 'printMany']);
$router->addRoute('GET',  '/admin/orders/{id:\d+}/print',   [OrderController::class, 'printOne']);
$router->addRoute('GET',  '/admin/orders/{id:\d+}',          [OrderController::class, 'show']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/status',   [OrderController::class, 'handleStatus']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/note',     [OrderController::class, 'handleNote']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/payment',  [OrderController::class, 'handlePayment']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/tracking', [OrderController::class, 'handleTracking']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/email',    [OrderController::class, 'handleEmail']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/refund',   [OrderController::class, 'handleRefund']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/addresses', [OrderController::class, 'handleAddresses']);
$router->addRoute('POST', '/admin/orders/{id:\d+}/exchange', [OrderController::class, 'handleExchange']);
$router->addRoute('GET',  '/admin/orders/{id:\d+}/refund/quote', [OrderController::class, 'refundQuote']);
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
$router->addRoute('POST', '/admin/forms/submissions/{sid:\d+}/resend',         [AdminFormController::class, 'handleResend']);
$router->addRoute('POST', '/admin/forms/submissions/bulk',                     [AdminFormController::class, 'handleBulk']);
$router->addRoute('GET',  '/admin/forms/spam',                                 [AdminFormController::class, 'spam']);
$router->addRoute('POST', '/admin/forms/spam/empty',                           [AdminFormController::class, 'handleEmptySpam']);
$router->addRoute('POST', '/admin/forms/spam/settings',                        [AdminFormController::class, 'handleSpamSettings']);
$router->addRoute('GET',  '/admin/forms/{id:\d+}/edit',                        [AdminFormController::class, 'showEdit']);
$router->addRoute('POST', '/admin/forms/{id:\d+}/edit',                        [AdminFormController::class, 'handleUpdate']);
$router->addRoute('POST', '/admin/forms/{id:\d+}/copy',                        [AdminFormController::class, 'handleCopy']);
$router->addRoute('POST', '/admin/forms/{id:\d+}/delete',                      [AdminFormController::class, 'handleDelete']);
$router->addRoute('GET',  '/admin/forms/{id:\d+}/submissions',                 [AdminFormController::class, 'submissions']);
$router->addRoute('GET',  '/admin/forms/{id:\d+}/submissions.csv',             [AdminFormController::class, 'exportCsv']);
$router->addRoute('POST', '/admin/forms/{id:\d+}/mark-all-read',              [AdminFormController::class, 'handleMarkAllRead']);

// -----------------------------------------------------------------------
// Admin — Appearance
// -----------------------------------------------------------------------
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
// THE EDITOR'S IMPORT MARKDOWN: a document in, the body it makes out. Saves nothing.
$router->addRoute('POST', '/admin/markdown', [\Cms\Controllers\Admin\MarkdownController::class, 'convert']);
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
// A WORDPRESS EXPORT's plan again with other choices, then the report of its
// import, and its media library's files fetched a step at a time
// (`Content\WordPressImport`).
$router->addRoute('POST', '/admin/tools/import/wordpress',                         [ImportController::class, 'replan']);
$router->addRoute('GET',  '/admin/tools/import/wordpress/{token:[a-f0-9]{24}}',      [ImportController::class, 'report']);
$router->addRoute('POST', '/admin/tools/import/wordpress/{token:[a-f0-9]{24}}/step', [ImportController::class, 'step']);
$router->addRoute('GET', '/admin/tools/system-health', [ToolsController::class, 'systemHealth']);
// Every sample entry to the Trash, from the list System health shows (`Models\Post::SAMPLE`).
$router->addRoute('POST', '/admin/tools/system-health/samples', [ToolsController::class, 'trashSamples']);
// Every earlier address of the site replaced with its own (`Site\OldAddresses`).
$router->addRoute('POST', '/admin/tools/system-health/addresses', [ToolsController::class, 'replaceAddresses']);

// Redirects — a tool of its own, not a tab on Settings → SEO;
// `RedirectsController`'s header has the reasoning. The old address,
// `/admin/settings/seo?tab=redirects`, forwards here, in showSeo().
$router->addRoute('GET',  '/admin/tools/redirects',                    [RedirectsController::class, 'index']);
$router->addRoute('POST', '/admin/tools/redirects',                    [RedirectsController::class, 'store']);
$router->addRoute('POST', '/admin/tools/redirects/{id:\d+}',           [RedirectsController::class, 'update']);
$router->addRoute('POST', '/admin/tools/redirects/{id:\d+}/delete',    [RedirectsController::class, 'delete']);
// The addresses visitors did not find (`SEO\NotFound`), listed on the same screen.
$router->addRoute('POST', '/admin/tools/redirects/not-found/{id:\d+}/dismiss', [RedirectsController::class, 'dismissNotFound']);
$router->addRoute('POST', '/admin/tools/redirects/not-found/clear',           [RedirectsController::class, 'clearNotFound']);

// A SITE'S OWN TOOL DRAWN IN THE ADMIN'S FRAME (`Site\Tools`, `'frame' => true`):
// every address under it, answered once the admin has loaded. A tool without
// the frame was handed over near the top of this file and never reaches here.
foreach (array_keys(\Cms\Site\Tools::framed()) as $toolPath) {
    foreach (['GET', 'POST'] as $toolMethod) {
        $router->addRoute($toolMethod, '/admin/tools/' . $toolPath . '[/{rest:.+}]', [\Cms\Controllers\Admin\SiteToolController::class, 'handle', $toolPath]);
    }
}

// A STATIC COPY OF THE SITE (`Export\StaticCopy`): the screen, the start, one
// polled step of the job, and its archive. The id's exact shape is checked by
// `StaticCopy::open()`, as an order's key is by `Order::findByKey()`.
use Cms\Controllers\Admin\StaticCopyController;
// Tools → Backups (`Site\Backups`): the timetable, the list, and one made now.
$router->addRoute('GET',  '/admin/tools/backups',                               [\Cms\Controllers\Admin\BackupsController::class, 'index']);
$router->addRoute('POST', '/admin/tools/backups',                               [\Cms\Controllers\Admin\BackupsController::class, 'save']);
$router->addRoute('POST', '/admin/tools/backups/now',                           [\Cms\Controllers\Admin\BackupsController::class, 'now']);
$router->addRoute('POST', '/admin/tools/backups/{id:[0-9a-f-]+}/step',          [\Cms\Controllers\Admin\BackupsController::class, 'step']);
$router->addRoute('GET',  '/admin/tools/backups/{id:[0-9a-f-]+}/download',      [\Cms\Controllers\Admin\BackupsController::class, 'download']);
$router->addRoute('POST', '/admin/tools/backups/{id:[0-9a-f-]+}/delete',        [\Cms\Controllers\Admin\BackupsController::class, 'delete']);
// Tools → Broken links: the copy's crawl, checking the site's links and writing nothing.
$router->addRoute('GET',  '/admin/tools/broken-links',                          [\Cms\Controllers\Admin\BrokenLinksController::class, 'index']);
$router->addRoute('POST', '/admin/tools/broken-links',                          [\Cms\Controllers\Admin\BrokenLinksController::class, 'start']);
$router->addRoute('POST', '/admin/tools/broken-links/{id:[0-9a-f-]+}/step',     [\Cms\Controllers\Admin\BrokenLinksController::class, 'step']);
$router->addRoute('GET',  '/admin/tools/static-copy',                         [StaticCopyController::class, 'index']);
$router->addRoute('POST', '/admin/tools/static-copy',                         [StaticCopyController::class, 'start']);
$router->addRoute('POST', '/admin/tools/static-copy/{id:[0-9a-f-]+}/step',    [StaticCopyController::class, 'step']);
$router->addRoute('GET',  '/admin/tools/static-copy/{id:[0-9a-f-]+}/download', [StaticCopyController::class, 'download']);

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
// THE THEME'S OWN WORDS, in each language the site has (`Site\Words`).
$router->addRoute('GET',  '/admin/settings/branding',   [SettingsController::class, 'showBranding']);
$router->addRoute('POST', '/admin/settings/branding',   [SettingsController::class, 'handleBranding']);
$router->addRoute('GET',  '/admin/settings/words',      [SettingsController::class, 'showWords']);
$router->addRoute('POST', '/admin/settings/words',      [SettingsController::class, 'handleWords']);
$router->addRoute('GET',  '/admin/settings/email',      [SettingsController::class, 'showEmail']);
$router->addRoute('POST', '/admin/settings/email',      [SettingsController::class, 'handleEmail']);
$router->addRoute('POST', '/admin/settings/email/test', [SettingsController::class, 'handleEmailTest']);
$router->addRoute('GET',  '/admin/settings/business',   [\Cms\Controllers\Admin\BusinessController::class, 'show']);
$router->addRoute('POST', '/admin/settings/business',   [\Cms\Controllers\Admin\BusinessController::class, 'save']);
$router->addRoute('GET',  '/admin/settings/commerce',   [SettingsController::class, 'showCommerce']);
$router->addRoute('POST', '/admin/settings/commerce',   [SettingsController::class, 'handleCommerce']);
$router->addRoute('GET',  '/admin/settings/api',                       [\Cms\Controllers\Admin\ApiSettingsController::class, 'show']);
$router->addRoute('POST', '/admin/settings/api/keys',                  [\Cms\Controllers\Admin\ApiSettingsController::class, 'makeKey']);
$router->addRoute('POST', '/admin/settings/api/keys/{id:\d+}/revoke',  [\Cms\Controllers\Admin\ApiSettingsController::class, 'revokeKey']);
$router->addRoute('POST', '/admin/settings/api/webhooks',              [\Cms\Controllers\Admin\ApiSettingsController::class, 'addHook']);
$router->addRoute('POST', '/admin/settings/api/webhooks/{id:\d+}/remove', [\Cms\Controllers\Admin\ApiSettingsController::class, 'removeHook']);

// -----------------------------------------------------------------------
// API — still signed in? The page and entry editors ask while somebody
// writes, which keeps the sign-in (`Api\SessionController`).
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/api/v1/session',                        [\Cms\Controllers\Api\SessionController::class, 'show']);
$router->addRoute('POST', '/api/v1/session/leave',                  [\Cms\Controllers\Api\SessionController::class, 'leave']);

// -----------------------------------------------------------------------
// API — media
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/api/v1/media',                          [ApiMediaController::class, 'list']);
$router->addRoute('POST', '/api/v1/media/upload',                   [ApiMediaController::class, 'upload']);
// A file larger than one request, a piece at a time (`Media\Pieces`).
$router->addRoute('POST', '/api/v1/media/upload/piece',             [ApiMediaController::class, 'uploadPiece']);
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
$router->addRoute('GET', '/api/v1/export/products/{key}',       [ExportController::class, 'products']);
// THE SHOP'S API, for another program with a key (`Controllers\Api\ShopApiController`).
$router->addRoute('GET',  '/api/v1/products',                 [\Cms\Controllers\Api\ShopApiController::class, 'products']);
$router->addRoute('GET',  '/api/v1/orders',                   [\Cms\Controllers\Api\ShopApiController::class, 'orders']);
$router->addRoute('GET',  '/api/v1/orders/{id:\d+}',         [\Cms\Controllers\Api\ShopApiController::class, 'order']);
$router->addRoute('POST', '/api/v1/orders/{id:\d+}/status',  [\Cms\Controllers\Api\ShopApiController::class, 'setStatus']);
$router->addRoute('POST', '/api/v1/stock',                    [\Cms\Controllers\Api\ShopApiController::class, 'setStock']);

// -----------------------------------------------------------------------
// API — menus
// -----------------------------------------------------------------------
$router->addRoute('GET',  '/api/v1/menus/{id:\d+}/items',     [ApiMenuController::class, 'items']);
$router->addRoute('POST', '/api/v1/menus/{id:\d+}/items',     [ApiMenuController::class, 'saveItems']);
// What the top bar's box offers as one types (`Ui\Find`).
$router->addRoute('GET',  '/api/v1/find',                       [\Cms\Controllers\Api\FindController::class, 'results']);
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
    // Its public URL segment, e.g. 'news', or null when its entries have no
    // page of their own (`Registry::hasPages()`): then no public route at all.
    $p = Registry::publicSlug((string) $contentTypeKey);

    if ($p !== null) {
        // Public archive (listing) page
        $router->addRoute('GET', "/{$p}",        [PublicContentTypeController::class,  'archive', $contentTypeKey]);

        // ITS NEWEST ENTRIES AS A FEED, unless it sells (`Content\Feed`).
        // BEFORE THE ENTRY'S PATTERN: the router refuses a fixed address that
        // a pattern added earlier would already match.
        if (\Cms\Content\Feed::has($contentTypeKey)) {
            $router->addRoute('GET', "/{$p}/feed.xml", [\Cms\Controllers\Public\EntryFeedController::class, 'show', $contentTypeKey]);
        }
        // AN EVENTS TYPE IN A CALENDAR (`Content\Calendar`): all of it to
        // subscribe to, then one event to add. Before the entry's pattern too,
        // and the fixed address before the event's own.
        if (\Cms\Content\Calendar::has($contentTypeKey)) {
            $router->addRoute('GET', "/{$p}/calendar.ics", [\Cms\Controllers\Public\CalendarController::class, 'type', $contentTypeKey]);
            $router->addRoute('GET', "/{$p}/{slug:[a-z0-9-]+}.ics", [\Cms\Controllers\Public\CalendarController::class, 'event', $contentTypeKey]);
        }

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
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/copy",   [ContentTypeController::class, 'handleCopy',    $contentTypeKey]);
    // Its version in the second language (`Content\Translation`).
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/translate", [ContentTypeController::class, 'handleTranslate', $contentTypeKey]);
    // A link that shows an entry not yet on the site (`Content\\Share`).
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/share",  [ContentTypeController::class, 'handleShare',   $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/share/stop", [ContentTypeController::class, 'handleStopShare', $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/restore",[ContentTypeController::class, 'handleRestore', $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/delete", [ContentTypeController::class, 'handleDelete',  $contentTypeKey]);
    // One action on the entries ticked in the list or the trash.
    $router->addRoute('POST', "/admin/{$s}/bulk",             [ContentTypeController::class, 'handleBulk',    $contentTypeKey]);
    // ITS ROWS ON ITS PARENT'S EDITOR (`ContentType\Children`): one added, one
    // saved, one to the trash, and their order within one parent.
    $router->addRoute('POST', "/admin/{$s}/rows",             [ContentTypeController::class, 'handleRowCreate', $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/order",            [ContentTypeController::class, 'handleOrder',     $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/row",    [ContentTypeController::class, 'handleRowSave',   $contentTypeKey]);
    $router->addRoute('POST', "/admin/{$s}/{id:\\d+}/row/trash", [ContentTypeController::class, 'handleRowTrash', $contentTypeKey]);
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
// Public — the second language (Settings → General, `Site\Language`).
//
// Its front page, its pages, the archives, entries, feeds and calendars of
// the types that do not sell (`Content\Translation::translates()`), the term
// pages and search, each under its prefix (/fr, /fr/a-propos, /fr/news/…) and
// drawn in it: the handler's 4th element, given to `Language::draw()` before
// the controller is made. Nothing else: the shop, forms, the account pages,
// the sitemaps and the admin have one address each. Before the pages'
// catch-all, which would otherwise answer /fr.
// -----------------------------------------------------------------------
$second = \Cms\Site\Language::prefix();
if ($second !== '') {
    $l = '/' . $second;
    $router->addRoute('GET', "{$l}",        [HomeController::class,         'index', null, $second]);
    $router->addRoute('GET', "{$l}/search", [PublicSearchController::class, 'index', null, $second]);
    foreach (Registry::all() as $contentTypeKey => $contentType) {
        $p = (string) Registry::publicSlug((string) $contentTypeKey);
        if ($p === '' || !\Cms\Content\Translation::translates((string) $contentTypeKey)) {
            continue;
        }
        $router->addRoute('GET', "{$l}/{$p}", [PublicContentTypeController::class, 'archive', $contentTypeKey, $second]);
        if (\Cms\Content\Feed::has($contentTypeKey)) {
            $router->addRoute('GET', "{$l}/{$p}/feed.xml", [\Cms\Controllers\Public\EntryFeedController::class, 'show', $contentTypeKey, $second]);
        }
        if (\Cms\Content\Calendar::has($contentTypeKey)) {
            $router->addRoute('GET', "{$l}/{$p}/calendar.ics", [\Cms\Controllers\Public\CalendarController::class, 'type', $contentTypeKey, $second]);
            $router->addRoute('GET', "{$l}/{$p}/{slug:[a-z0-9-]+}.ics", [\Cms\Controllers\Public\CalendarController::class, 'event', $contentTypeKey, $second]);
        }
        $router->addRoute('GET',  "{$l}/{$p}/{slug}", [PublicPostController::class, 'show', $contentTypeKey, $second]);
        $router->addRoute('POST', "{$l}/{$p}/{slug}", [PublicPostController::class, 'show', $contentTypeKey, $second]);
    }
    foreach (TaxonomyRegistry::all() as $taxKey => $taxonomy) {
        $tp = (string) ($taxonomy['public_slug'] ?? '');
        if ($tp !== '' && \Cms\Content\Translation::taxonomyTranslates((string) $taxKey)) {
            $router->addRoute('GET', "{$l}/{$tp}/{term}", [PublicTermController::class, 'archive', $taxKey, $second]);
        }
    }
    $router->addRoute('GET',  "{$l}/{slug}", [PublicPageController::class, 'show', null, $second]);
    $router->addRoute('POST', "{$l}/{slug}", [PublicPageController::class, 'show', null, $second]);
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

// A PAGE DRAWN FOR A STATIC COPY (`Export\StaticCopy`, through
// `Export/render-page.php`, which alone defines CMS_EXPORT and runs only from
// the command line) is drawn and nothing else: none of what a visit also does
// below (a redirect's count, the username watch, the daily file check, the
// notification channels, due orders) happens once per page of an export.
$exporting = defined('CMS_EXPORT');

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

// A RULE — exact, or a `/*` covering a whole section — carries the visitor's
// query string along. Never the admin, never away from the sign-in page, and
// never a rule that points at the page it is on (`Redirects::followed()`,
// which a site with its own router in front asks too, through
// `Site\Front::owns()`, so the two cannot differ).
$redirect = Redirects::followed($requestPath, (string) ($_SERVER['QUERY_STRING'] ?? ''));

if ($redirect !== null) {
    if (!$exporting) {
        Redirects::incrementHitCount((int) $redirect['id']);
    }
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

if (!$isAdminPath($requestPath) && !$exporting) {
    \Cms\Security\Enumeration::watch($requestPath, $_GET);
}

// The notification channels look at the end of every request — how the tail of
// a burst goes out once its quiet window ends, with no cron (`Security\Alerts`).
// One settings read when nothing is due; when something is, it is sent after
// the page has gone, and nobody waits on it.
// Once a day, the CMS's files against what shipped (`Security\FileCheck`) —
// before the channels look, so a finding is theirs to send in the same breath.
if (!$exporting) {
    \Cms\Security\FileCheck::poke();
    \Cms\Security\Alerts::poke();
}

// ----------------------------------------------------------------
// CSRF protection — validate token on all admin/API POST requests
// ----------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfExempt = ['/admin/login', '/forms/'];
    $needsCsrf  = str_starts_with($requestPath, '/admin/') || str_starts_with($requestPath, '/api/');
    // A PROGRAM SENDING AN API KEY carries no cookie for another site's form to
    // ride on, and no form can send that header: its write is its key's, and
    // the controller checks the key (`Security\ApiKeys`).
    if (str_starts_with($requestPath, '/api/v1/') && \Cms\Security\ApiKeys::sent() !== null) {
        $needsCsrf = false;
    }

    foreach ($csrfExempt as $exempt) {
        if (str_starts_with($requestPath, $exempt)) {
            $needsCsrf = false;
            break;
        }
    }
    // A SITE TOOL'S ROW THAT SAYS ANOTHER SERVICE POSTS TO IT, `'csrf' => false`,
    // such as a webhook (`Site\Tools`): a framed one's POST reaches here.
    if ($needsCsrf && str_starts_with($requestPath, '/' . ADMIN_URL_SLUG . '/tools/') && \Cms\Site\Tools::csrfExempt($requestPath)) {
        $needsCsrf = false;
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
            \Cms\Http\Cache::noStore();
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

// ── DUE WORK, ON A VISIT ─────────────────────────────────────────────
// Unpaid orders past the shop's days are cancelled at most every ten
// minutes, whoever's visit it is, after their page (`Commerce\Lapse`):
// shared hosting has no cron to rely on.
if (!$exporting) {
    \Cms\Commerce\Lapse::onVisit();
    // And a backup on its timetable, the same way (`Site\Backups`).
    \Cms\Site\Backups::onVisit();
    // And the site's own scheduled tasks (`Site\Tasks`, admin/config/tasks.php).
    \Cms\Site\Tasks::onVisit();
    // A visit through a proxy config.php does not name, noted for System
    // health (`ClientIp::noteUnnamed()`): free unless one comes.
    \Cms\Security\ClientIp::noteUnnamed($_SERVER);
}

$result = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

switch ($result[0]) {
    case Dispatcher::FOUND:
        [$status, $handler, $vars] = $result;

        // Handler is [class, method] or [class, method, contentTypeKey], and a
        // second language's route adds its language: [class, method, key or
        // null, language]. ContentTypeController and TermController receive
        // the 3rd element via constructor.
        $controllerClass  = $handler[0];
        $method           = $handler[1];
        $contentTypeKey   = $handler[2] ?? null;
        // THE LANGUAGE THE PAGE IS DRAWN IN (`Site\Language`): the site's own,
        // unless the route is the second language's.
        \Cms\Site\Language::draw((string) ($handler[3] ?? ''),
            str_starts_with(ltrim((string) $controllerClass, '\\'), 'Cms\\Controllers\\Public\\'));

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

        // AN ADDRESS UNDER THE SECOND LANGUAGE'S PREFIX is not found in that
        // language: its not-found page is drawn in it.
        $secondPrefix = \Cms\Site\Language::prefix();
        if ($secondPrefix !== '' && ($requestPath === '/' . $secondPrefix || str_starts_with($requestPath, '/' . $secondPrefix . '/'))) {
            \Cms\Site\Language::draw($secondPrefix, true);
        }

        // Set basic SEO for 404 page. The page itself is the whole-page
        // theme's, if it has one — `Template::notFound()`, which the hidden
        // sign-in page's 404 calls too, so the two stay identical.
        \Cms\SEO\Head::set([
            'title'  => \Cms\Site\Words::of('not_found_title') . ' | ' . \Cms\SEO\Head::globalTokens()['site_name'],
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
