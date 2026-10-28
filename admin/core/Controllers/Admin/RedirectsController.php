<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Helpers\Slug;
use Cms\SEO\Redirects;

/**
 * TOOLS → REDIRECTS.
 *
 * ── WHY THIS IS A CONTROLLER AND NOT A TAB ──────────────────────────────────
 * It was a tab on Settings → SEO, and the sidebar's Tools → Redirects link was
 * a `header('Location: …/settings/seo?tab=redirects')` — so the one entry the
 * menu offered navigated AWAY from the section it lived in, landing on a
 * different sidebar group with a different item highlighted. A menu item that
 * cannot be landed on is the same failure shape as a row that cannot be acted
 * on: the screen offers something and then refuses it.
 *
 * The old address still answers: `SettingsController::showSeo()` sends
 * `?tab=redirects` here, which is the previous redirect turned around, so
 * bookmarks and the assistant's stored links keep working.
 *
 * Tools RUNS things, Settings ADJUSTS them (`Ui\Sidebar`'s own note). A
 * redirect is a rule that acts on every request; it is not a preference.
 *
 * ── WHAT THE VALIDATION IS FOR ──────────────────────────────────────────────
 * The tab wrote whatever was typed straight into the table, and the router
 * matches `from_path` EXACTLY (`www/index.php`: query stripped, leading slash
 * forced, trailing slash trimmed). So `old-page`, `/old-page/` and
 * `https://site.test/old-page?ref=x` all stored rules that could never fire —
 * silently, because a rule that never matches looks exactly like a rule that
 * has not been visited yet. Every address is normalised the way the router
 * normalises the request, so what is stored is what can match.
 *
 * Three of the checks below exist because the redirect table is consulted
 * BEFORE routing, which makes it the one screen in the admin that can take the
 * site away from its owner:
 *
 *   from `/`                a redirect on the homepage sends every visitor away
 *   from under the admin    locks the owner out of the CMS, including this page
 *   destination scheme      `to_url` goes into a `Location:` header verbatim
 *
 * ── THE CSRF TOKEN IS CHECKED, AND NOT HERE ────────────────────────────────
 * All three forms print `Csrf::field()` and no method below compares it, which
 * reads on the way past as a screen sending a token nobody validates. It is
 * not: `www/index.php` validates EVERY `/admin/` POST before a route is
 * dispatched, `/admin/login` and `/forms/` excepted — a tokenless POST to this
 * address answers 403.
 *
 * `CouponController` and `OrderController` carry their own four-line copy on
 * top of that. Theirs is belt-and-braces and not the guard; a third copy here
 * would be a fourth place to keep one rule, so this says where the rule lives.
 *
 * The duplicate check exists for a quieter reason: `Redirects::create()`
 * UPDATES a rule when `from_path` already exists. Without the check, adding
 * `/a → /c` where `/a → /b` is already listed would silently rewrite the old
 * rule and report "saved", so the screen's own list would contradict what you
 * had just typed.
 */
class RedirectsController
{
    /** GET /admin/tools/redirects */
    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        $editing = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $this->render($editing > 0 ? Redirects::find($editing) : null);
    }

    /** POST /admin/tools/redirects — add a rule. */
    public function store(array $vars = []): void
    {
        Auth::requireRole('admin');

        $from   = self::normalisePath((string) ($_POST['redirect_from'] ?? ''));
        $to     = self::normaliseTarget((string) ($_POST['redirect_to'] ?? ''));
        $type   = self::normaliseType($_POST['redirect_type'] ?? null);
        $errors = self::check((string) ($_POST['redirect_from'] ?? ''), $from, (string) ($_POST['redirect_to'] ?? ''), $to, null);

        if ($errors !== []) {
            // The typed values come back, not the empty form — retyping an
            // address to fix a slash is how a second wrong one gets made.
            $this->render(
                ['id' => 0, 'from_path' => $from, 'to_url' => $to, 'type' => $type],
                $errors,
                false
            );
            return;
        }

        Redirects::create($from, $to, $type);
        $this->render(null, [], true);
    }

    /** POST /admin/tools/redirects/{id} — save an edit. */
    public function update(array $vars = []): void
    {
        Auth::requireRole('admin');

        $id       = (int) ($vars['id'] ?? 0);
        $existing = Redirects::find($id);

        if ($existing === null) {
            // Deleted in another tab, or another session — say so on the screen
            // it happened on rather than saving a new rule under a dead id.
            $this->render(null, ['That redirect no longer exists — it was deleted while you were editing it.'], false);
            return;
        }

        $from   = self::normalisePath((string) ($_POST['redirect_from'] ?? ''));
        $to     = self::normaliseTarget((string) ($_POST['redirect_to'] ?? ''));
        $type   = self::normaliseType($_POST['redirect_type'] ?? null);
        $errors = self::check((string) ($_POST['redirect_from'] ?? ''), $from, (string) ($_POST['redirect_to'] ?? ''), $to, $id);

        if ($errors !== []) {
            $this->render(
                ['id' => $id, 'from_path' => $from, 'to_url' => $to, 'type' => $type],
                $errors,
                false
            );
            return;
        }

        Redirects::update($id, $from, $to, $type);
        $this->render(null, [], true);
    }

    /** POST /admin/tools/redirects/{id}/delete */
    public function delete(array $vars = []): void
    {
        Auth::requireRole('admin');

        Redirects::delete((int) ($vars['id'] ?? 0));
        header('Location: ' . ADMIN_URL . '/tools/redirects');
        exit;
    }

    // ----------------------------------------------------------------
    // Rendering
    // ----------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $form  the rule the form is holding — an
     *                                         existing rule being edited, or a
     *                                         rejected submission handed back
     * @param list<string>              $errors
     */
    private function render(?array $form, array $errors = [], bool $saved = false): void
    {
        $user      = Auth::user();
        $redirects = Redirects::all();

        $pageTitle   = 'Redirects';
        $currentPage = 'redirects';

        require ROOT_PATH . '/admin/templates/tools/redirects.php';
    }

    // ----------------------------------------------------------------
    // Normalising and checking
    // ----------------------------------------------------------------

    /**
     * The address as the ROUTER will see it — see the class docblock. Anything
     * that cannot appear in `$requestPath` is stripped here rather than stored
     * and left to never match: the origin off a pasted full URL, a query
     * string, a fragment, and control characters.
     */
    public static function normalisePath(string $raw): string
    {
        $path = trim(self::stripControl($raw));

        if (preg_match('#^https?://#i', $path) === 1) {
            $parsed = parse_url($path);
            $path   = (string) ($parsed['path'] ?? '/');
        }

        $path = explode('?', $path)[0];
        $path = explode('#', $path)[0];
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * The destination, left as typed apart from control characters and
     * surrounding space. A site path keeps its query and fragment — those are
     * meaningful in a destination even though they are not in a source — and a
     * full address is not rewritten, because the host is the point of it.
     */
    public static function normaliseTarget(string $raw): string
    {
        $to = trim(self::stripControl($raw));

        if ($to === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $to) === 1) {
            return $to;
        }

        return '/' . ltrim($to, '/');
    }

    /**
     * A site path, or a full http(s) address. Nothing else.
     *
     * THE `//` CASE IS THE WHOLE REASON THIS IS A METHOD. A check written as
     * `str_starts_with($to, '/') || ^https?://` lets `//example.com/x` through
     * on its first clause — a protocol-relative URL begins with a slash, so an
     * off-site destination would walk through a guard meant to allow only
     * on-site ones and be saved, with nothing on the screen looking wrong.
     */
    private static function isAllowedTarget(string $to): bool
    {
        if (preg_match('#^https?://#i', $to) === 1) {
            return true;
        }

        return str_starts_with($to, '/') && !str_starts_with($to, '//');
    }

    /**
     * A destination on THIS site as the router will see it — `/about/?x=1` →
     * `/about`, `https://www.this-site/about/` → `/about` — or null for another
     * site. The host is compared without `www.` and without its scheme.
     */
    private static function sitePath(string $to): ?string
    {
        if (preg_match('#^https?://#i', $to) === 1) {
            $host = preg_replace('/^www\./', '', strtolower((string) parse_url($to, PHP_URL_HOST)));
            $own  = preg_replace('/^www\./', '', strtolower((string) parse_url(defined('BASE_URL') ? (string) BASE_URL : '', PHP_URL_HOST)));
            if ($host === '' || $host !== $own) {
                return null;
            }
            $to = (string) (parse_url($to, PHP_URL_PATH) ?? '/');
        }

        return self::normalisePath($to);
    }

    /**
     * A destination that is the sign-in page moved by Security → Login
     * protection — the address an install keeps private.
     */
    private static function isSignInAddress(string $to): bool
    {
        $path = self::sitePath($to);

        return $path !== null && \Cms\Security\LoginUrl::enabled()
            && strcasecmp(explode('/', ltrim($path, '/'))[0], \Cms\Security\LoginUrl::slug()) === 0;
    }

    /** A `Location:` header is one line. Anything that could split it goes. */
    private static function stripControl(string $value): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    }

    private static function normaliseType(mixed $raw): int
    {
        return (int) $raw === 302 ? 302 : 301;
    }

    /**
     * What is wrong with a rule, as the screen would say it — public, so a
     * site's own migration tool can check the rules it writes the
     * same way the screen does.
     *
     * @return list<string>
     */
    public static function check(string $rawFrom, string $from, string $rawTo, string $to, ?int $ignoreId): array
    {
        $errors = [];
        // WHOSE ADDRESS IT IS is its first segment, and a `*` means one thing:
        // everything after the last slash of a section, `/old-section/*`. The
        // screen refuses the rules the runtime cannot honour, an endless chain
        // among them, rather than saving them.
        $first = explode('/', ltrim($from, '/'))[0];
        $wild  = str_ends_with($from, '/*');

        if (trim($rawFrom) === '') {
            $errors[] = 'Enter the old address to redirect from.';
        } elseif ($from === '/' || $from === '/*') {
            $errors[] = $from === '/'
                ? 'The homepage cannot redirect. This rule is checked before anything else is served, so every visitor to the site would be sent away.'
                : 'A rule for /* would send every page on the site away. Name the section: /old-section/*.';
        } elseif (strcasecmp($first, 'admin') === 0) {
            // The front controller never redirects the admin, so a rule there
            // could only sit in the list doing nothing.
            $errors[] = 'That address is part of the admin, and redirects never apply there.';
        } elseif (in_array(strtolower($first), Slug::reservedFirstSegments(), true)) {
            $errors[] = 'That address belongs to the CMS itself (/' . $first . '/…). A redirect there would take over one of its own routes.';
        } elseif (str_contains($from, '*') && (!$wild || substr_count($from, '*') > 1)) {
            $errors[] = 'A * can only stand for everything after the last slash, like /old-section/*.';
        }

        if (trim($rawTo) === '') {
            $errors[] = 'Enter the address to send visitors to.';
        } elseif (!self::isAllowedTarget($to)) {
            // Not a taste rule: `to_url` is written straight into a `Location:`
            // header, so `javascript:` and `mailto:` are somebody else's idea of
            // what this control does — and `//other-host` is an off-site jump
            // WEARING the shape of a site path.
            $errors[] = 'The destination must be a path on this site, like /new-address, or a full web address starting https://.';
        } elseif (str_contains($to, '*') && !$wild) {
            $errors[] = 'Only a rule whose old address ends in /* can use * in the destination, where it stands for the rest of that old address.';
        } elseif (substr_count($to, '*') > 1) {
            $errors[] = 'Use * once in the destination.';
        } elseif (self::isSignInAddress($to)) {
            $errors[] = 'That destination is the sign-in page\'s address. The redirect would show it to anyone who visits the old one, which undoes keeping it private.';
        }

        // ITS OWN PAGE, however the destination ends: `/about` → `/about/` is
        // the same loop as `/about` → `/about` — `Redirects::pageOf()`. The
        // ending is Settings → Permalinks' job, so the message points there.
        if ($errors === [] && ($from === $to || Redirects::pageOf($to) === $from)) {
            $errors[] = $from === $to
                ? 'That address redirects to itself, which is a loop the browser will refuse.'
                : 'That is the same page as ' . $from . ', which is a loop the browser will refuse. Whether addresses end with a slash is set under Settings → Permalinks.';
        }

        // AND AS `match()` ASKS: its collation ignores case, and a full address
        // on this site — with or without www., either scheme — is a path here.
        // A `/*` rule whose destination is UNDER its own section catches its
        // own destination, and the next, without end. The bare section is not
        // under it (a `/*` rule needs something after the slash), so
        // `/old/*` → `/old/` is not a chain.
        if ($errors === []) {
            $target = self::sitePath($to);
            if ($target !== null && !$wild && strcasecmp($target, $from) === 0) {
                $errors[] = 'That address redirects to itself, which is a loop the browser will refuse.';
            } elseif ($target !== null && $wild && stripos($target, substr($from, 0, -1)) === 0) {
                $errors[] = 'That rule sends visitors back under the address it redirects from, so they would be redirected again and again.';
            }
        }

        if ($errors === []) {
            $clash = Redirects::match($from);
            if ($clash !== null && (int) $clash['id'] !== $ignoreId) {
                $errors[] = 'There is already a redirect from ' . $from . ' — edit that one rather than adding a second.';
            }
        }

        return $errors;
    }
}
