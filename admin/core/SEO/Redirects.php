<?php

declare(strict_types=1);

namespace Cms\SEO;

use Cms\Database\Database;
use PDO;

/**
 * Redirects — manages 301/302 redirect rules.
 *
 * Checked in `www/index.php` before any route is dispatched, against a request
 * path with its query string cut, a leading slash forced and a trailing one
 * trimmed. The match is EXACT — or, for an old address ending in `/*`, a
 * prefix (`forRequest()`) — which is why `RedirectsController` normalises
 * every address it stores the same way: a rule the router can never look up
 * looks identical, on the screen, to one that has simply not been visited.
 *
 * A RENAMED ADDRESS WRITES ITS OWN RULE — `moved()`, below: renaming a live
 * entry, page or public term leaves a 301 behind it, and the rule is listed on
 * Tools → Redirects like any other.
 */
class Redirects
{
    /**
     * Look up a redirect rule for a given path.
     * Returns the rule array or null if no match.
     *
     * @return array{id: int, from_path: string, to_url: string, type: int}|null
     */
    public static function match(string $path): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, from_path, to_url, type FROM redirects WHERE from_path = :path LIMIT 1'
        );
        $stmt->execute([':path' => $path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * The rule a visitor's request matches, and the address it sends them to.
     * For a site moved here from elsewhere these rules are its legacy
     * redirects, and a miss is a silent 404.
     *
     * ── EXACT FIRST, THEN "/*" ─────────────────────────────────────────────
     * An old address ending in `/*` covers everything under that path — at
     * least one character after the slash, so the section's own address is
     * left to a rule of its own — and a `*` in the destination is replaced by
     * whatever the `*` covered: with the destination `/articles/*`, the rule
     * `/old-news/*` sends `/old-news/a-post` to `/articles/a-post`. A site that
     * moved a whole section otherwise needs one row per page it ever had, and
     * loses every page nobody thought to list. An exact rule beats any `/*`
     * rule; of two `/*` rules the longer wins. Case is ignored, as the exact
     * lookup's collation ignores it; the part carried across keeps its own.
     *
     * ── THE QUERY STRING COMES ALONG ───────────────────────────────────────
     * A redirect that drops `?utm_source=…` loses the campaign the link was
     * tagged with. The visitor's query is kept unless the destination has one
     * of its own, which replaces it — Apache's rule, so moving a site's rewrite
     * rules into this table changes nothing a link carries.
     *
     * The path is matched DECODED, as Apache matches it: `%3A` and `:` are one
     * address to a visitor, and a rule is typed the readable way.
     *
     * A destination on this site lands in the site's own shape — `Url::pagePath()`,
     * with the slash Settings → Permalinks chose — so `/new-page` is one hop,
     * not the rule's and then the ending's. A file, and another site's address,
     * are left as typed.
     *
     * @param string $path  the request path shaped as the router shapes it —
     *                      leading slash, no trailing one — still encoded
     * @param string $query the request's query string, without its `?`
     * @return array{id: int, type: int, to: string, location: string}|null
     *         `to` the destination before it is made absolute (for `pageOf()`)
     */
    public static function forRequest(string $path, string $query = ''): ?array
    {
        $path = rawurldecode($path);
        $stmt = Database::getInstance()->prepare(
            "SELECT id, from_path, to_url, type, from_path = :exact AS is_exact
               FROM redirects WHERE from_path = :path OR from_path LIKE '%/*'
              ORDER BY is_exact DESC, CHAR_LENGTH(from_path) DESC"
        );
        $stmt->execute([':exact' => $path, ':path' => $path]);

        $rule = null;
        $rest = '';
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['is_exact'] === 1) {
                $rule = $row;
                break;
            }
            $prefix = substr((string) $row['from_path'], 0, -1);          // keeps the slash
            if ($prefix !== '/' && strlen($path) > strlen($prefix) && strncasecmp($path, $prefix, strlen($prefix)) === 0) {
                $rule = $row;
                $rest = substr($path, strlen($prefix));
                break;                                                     // longest first
            }
        }
        if ($rule === null) {
            return null;
        }

        $to = (string) $rule['to_url'];
        if ($rest !== '') {
            // Re-escaped for the header, leaving what a path may carry as it is
            // — `:` stays `:`, as Apache writes it — and escaping the rest.
            $to = str_replace('*', (string) preg_replace_callback(
                "~[^A-Za-z0-9\\-._\\~!$&'()*+,;=:@/]~",
                static fn (array $m): string => rawurlencode($m[0]),
                $rest
            ), $to);
        }
        $to = self::inSiteShape($to);
        if ($query !== '' && !str_contains($to, '?')) {
            [$base, $fragment] = array_pad(explode('#', $to, 2), 2, null);
            $to = $base . '?' . $query . ($fragment !== null ? '#' . $fragment : '');
        }

        return [
            'id'       => (int) $rule['id'],
            'type'     => (int) $rule['type'],
            'to'       => $to,
            'location' => \Cms\Helpers\Url::resolve($to),
        ];
    }

    /**
     * The rule a request FOLLOWS: `forRequest()`'s, but never for the admin,
     * never away from the sign-in page, and never one that points at the page
     * it is on. The front controller follows what this returns, and
     * `Site\Front::owns()` asks it too, so a site with its own router in front
     * hands the CMS exactly the addresses whose rules it follows.
     *
     * A RULE THAT POINTS AT THE PAGE IT IS ON IS NOT FOLLOWED. `/about` →
     * `/about/` is the commonest rule a site moved from another CMS brings with
     * it, and the request's slash is trimmed before the lookup, so the
     * destination would match its own rule again and the browser would give up
     * on the loop. The page answers instead, and its controller settles the
     * ending (`Url::settle()`). `pageOf()` is the same test the Redirects
     * screen refuses a new rule with.
     *
     * AND THE SIGN-IN PAGE IS NEVER REDIRECTED AWAY. Every screen that can
     * create a redirect refuses its address (`Slug::reservedFirstSegments()`),
     * but a rule that arrives another way, an import or a row edited by hand,
     * must not be able to take the only way into the admin.
     *
     * @param string $path  the request path shaped as the router shapes it
     * @param string $query the request's query string, without its `?`
     * @return array{id: int, type: int, to: string, location: string}|null
     */
    public static function followed(string $path, string $query = ''): ?array
    {
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return null;
        }
        $rule = self::forRequest($path, $query);
        if ($rule === null || $path === \Cms\Security\LoginUrl::path() || self::pageOf((string) $rule['to']) === $path) {
            return null;
        }

        return $rule;
    }

    /** A site path given the site's own ending (`Url::pagePath()`), its query and fragment kept; anything else as typed. */
    private static function inSiteShape(string $to): string
    {
        $path = self::sitePath($to);
        if ($path === null) {
            return $to;
        }
        $cut  = strcspn($path, '?#');
        $page = substr($path, 0, $cut);

        return \Cms\Helpers\Url::pagePath($page) . substr($path, $cut);
    }

    /**
     * A destination on THIS site, as a path, query and fragment kept:
     * `/about/?x=1` as given, `https://this-site/about/` → `/about/`. Null for
     * anywhere else.
     */
    public static function sitePath(string $to): ?string
    {
        if (str_starts_with($to, '/') && !str_starts_with($to, '//')) {
            return $to;
        }
        if (defined('BASE_URL') && BASE_URL !== '' && str_starts_with($to, BASE_URL . '/')) {
            return substr($to, strlen(BASE_URL));
        }
        return null;
    }

    /**
     * WHICH PAGE a destination is, the way `match()` is asked: `/about/?x=1` →
     * `/about`. Null off the site.
     *
     * The request's trailing slash is trimmed before the lookup, so a rule
     * whose destination is its own source by this measure — `/about` →
     * `/about/`, `/about` → `/about?ref=x` — sends the visitor to an address
     * that matches the same rule again. The Redirects screen refuses one, and
     * `www/index.php` declines to follow one already stored.
     */
    public static function pageOf(string $to): ?string
    {
        $path = self::sitePath($to);
        return $path === null ? null : '/' . trim(substr($path, 0, strcspn($path, '?#')), '/');
    }

    /**
     * Record a hit: the counter, and WHEN — migration 039's reasoning. One
     * statement, because two would double the write on a path that runs before
     * anything is served.
     */
    public static function incrementHitCount(int $id): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE redirects SET hit_count = hit_count + 1, last_accessed_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    /**
     * Create a rule — or UPDATE the one that is already forwarding this address.
     *
     * THE UPDATE-ON-EXISTING IS WHY `RedirectsController` CHECKS FIRST. Adding
     * `/a → /c` where `/a → /b` was already listed would silently rewrite the
     * old rule and report success, so the screen's own list would contradict
     * what was just typed. Kept for `moved()`, which relies on it; refused at
     * the screen instead.
     */
    public static function create(string $fromPath, string $toUrl, int $type = 301): int
    {
        $pdo  = Database::getInstance();

        // If a rule for this from_path already exists, update it
        $existing = self::match($fromPath);
        if ($existing !== null) {
            $stmt = $pdo->prepare(
                'UPDATE redirects SET to_url = :to_url, type = :type WHERE id = :id'
            );
            $stmt->execute([':to_url' => $toUrl, ':type' => $type, ':id' => $existing['id']]);
            return $existing['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO redirects (from_path, to_url, type) VALUES (:from_path, :to_url, :type)'
        );
        $stmt->execute([
            ':from_path' => $fromPath,
            ':to_url'    => $toUrl,
            ':type'      => $type,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * An address MOVED: what lived at `$from` now lives at `$to`. The rule every
     * slug change writes.
     *
     * Three steps, in this order:
     *   1. No rule may START at `$to`. The thing lives there now, and rules are
     *      read before any route — a leftover `/b → /a` from an earlier rename
     *      would send every visitor away from the live page.
     *   2. Rules that ENDED at `$from` now end at `$to`. Otherwise each old link
     *      takes two hops.
     *   3. `$from` → `$to`, 301 (create() updates a rule that already starts
     *      there).
     *
     * Both are site paths shaped the way the router shapes a request: a
     * leading slash, no trailing one. A move that would put a rule on an
     * address a ROUTE owns is refused silently, because the rule would win:
     * the root, the admin, the API, search, and a content type's own archive.
     *
     * THE RULE POINTS AT THE ADDRESS AS THE SITE WRITES IT — `Url::pagePath()`,
     * with the slash when Settings → Permalinks says so — so an old link lands
     * in one hop, not the rule's and then the ending's. Step 2 matches a rule
     * ending at `$from` in either form, because the setting may have changed
     * since that rule was written.
     */
    public static function moved(string $from, string $to): void
    {
        $from = '/' . trim($from, '/');
        $to   = '/' . trim($to, '/');

        if ($from === $to || $from === '/' || $to === '/') {
            return;
        }

        // THE SECOND LANGUAGE'S ADDRESSES (`Site\Language`) follow the same
        // rules under its prefix: /fr is its front page, as / is the site's,
        // and what follows the prefix is checked as an address of the site's
        // own language is. Its prefix is a reserved first segment, so without
        // this every rename of a page in it would leave no rule behind.
        $second = \Cms\Site\Language::prefix();
        $own    = $from;
        if ($second !== '' && ($from === '/' . $second || str_starts_with($from, '/' . $second . '/'))) {
            if ($from === '/' . $second || $to === '/' . $second) {
                return;
            }
            $own = substr($from, strlen('/' . $second));
        }

        // THE ROUTER'S OWN FIRST SEGMENTS, from the one list of them — a
        // second list is one that falls behind the first.
        $first = explode('/', ltrim($own, '/'))[0];
        if (in_array($first, \Cms\Helpers\Slug::reservedFirstSegments(), true)) {
            return;
        }
        if (!str_contains(ltrim($own, '/'), '/')) {
            foreach (\Cms\ContentType\Registry::all() as $typeKey => $type) {
                if ((string) \Cms\ContentType\Registry::publicSlug((string) $typeKey) === $first) {
                    return;
                }
            }
        }

        $target = \Cms\Helpers\Url::pagePath($to);
        $base   = defined('BASE_URL') ? BASE_URL : '';

        $pdo = Database::getInstance();
        $pdo->prepare('DELETE FROM redirects WHERE from_path = :to')->execute([':to' => $to]);
        $pdo->prepare('UPDATE redirects SET to_url = :to WHERE to_url IN (:from, :fromSlash, :fromAbs, :fromAbsSlash)')
            ->execute([
                ':to'           => $target,
                ':from'         => $from,
                ':fromSlash'    => $from . '/',
                ':fromAbs'      => $base . $from,
                ':fromAbsSlash' => $base . $from . '/',
            ]);
        self::create($from, $target, 301);
    }

    /**
     * Get all redirect rules, ordered by creation date.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $pdo  = Database::getInstance();
        // `id DESC` IS NOT DECORATION. `created_at` has one-second resolution,
        // and four rules added in one sitting share a value — so the sort would
        // be a tie and MySQL would return them in whatever order it liked. Two
        // loads of the same unchanged list could come back in different orders,
        // which on a screen you scan for one address reads as the list having
        // changed.
        $stmt = $pdo->query('SELECT * FROM redirects ORDER BY created_at DESC, id DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a redirect by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM redirects WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Update a redirect rule.
     */
    public static function update(int $id, string $fromPath, string $toUrl, int $type): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE redirects SET from_path = :from_path, to_url = :to_url, type = :type WHERE id = :id'
        );
        $stmt->execute([
            ':from_path' => $fromPath,
            ':to_url'    => $toUrl,
            ':type'      => $type,
            ':id'        => $id,
        ]);
    }

    /**
     * Delete a redirect rule.
     */
    public static function delete(int $id): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM redirects WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
