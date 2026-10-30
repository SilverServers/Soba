<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Helpers\Url;
use Cms\Models\Option;
use Cms\Models\Page;

/**
 * LOGIN REDIRECTION — rules for where an account goes after it signs in, and
 * after it signs out. Off until switched on.
 *
 * A RULE says who it is for — all users, the accounts with a role, or
 * particular accounts — where they go after signing in (a page, or an address
 * on this site), where after signing out (nowhere special, a page, or an
 * address), and an order.
 *
 * ── WHICH RULE ──────────────────────────────────────────────────────────────
 *   - A rule for particular accounts outranks one for a role, which outranks
 *     one for all users.
 *   - Within that, the lower order wins (1, 2, 3…). Order 0 is "no priority",
 *     and if ANY rule on the site has an order, rules without one are never
 *     used.
 *   - The top rule decides both destinations. Its sign-out destination is
 *     never taken from a lower rule; "nowhere special" is the usual sign-in
 *     page.
 *
 * ── WHERE TO — never off this site ─────────────────────────────────────────
 * A destination is a page or an address on this site (a path, `{{username}}`
 * and `{{user_id}}` filled in): a sign-in page that sends people wherever a
 * setting says is an open redirect, one setting away from a phishing page
 * that looks like the next step of signing in.
 *
 * A page that no longer exists, or is no longer published, is not a
 * destination: the FALLBACK (the dashboard, or an address) is used instead —
 * not a lower rule. A destination is never tested by fetching it: nothing
 * here fetches at sign-in.
 *
 * The sign-in's own stops come first: a password the account owes, then the
 * two-step setup the site asks of it (`AuthController::signIn()`).
 */
final class LoginRedirection
{
    public const ON_KEY           = 'security_redirect_on';
    public const RULES_KEY        = 'security_redirect_rules';
    public const FALLBACK_KEY     = 'security_redirect_fallback';
    public const FALLBACK_URL_KEY = 'security_redirect_fallback_url';

    /** Who a rule is for, in the order offered. */
    public const WHO = ['all' => 'All users', 'role' => 'A role', 'user' => 'Particular accounts'];

    /** The roles a rule may name, in the Users screen's order. */
    public const ROLES = ['admin' => 'Administrators', 'shop_manager' => 'Shop managers', 'editor' => 'Editors', 'author' => 'Authors'];

    /** Where to, after signing in; after signing out, also "none". */
    public const TARGETS = ['page' => 'A page', 'url' => 'An address on this site'];

    public const PLACEHOLDERS = ['{{username}}', '{{user_id}}'];

    /** @return array{on: bool, rules: list<array<string, mixed>>, fallback: string, fallback_url: string} */
    public static function settings(): array
    {
        $raw   = Option::getMany([self::ON_KEY => '0', self::RULES_KEY => '[]', self::FALLBACK_KEY => 'dashboard', self::FALLBACK_URL_KEY => '']);
        $rules = json_decode((string) $raw[self::RULES_KEY], true);

        return [
            'on'           => $raw[self::ON_KEY] === '1',
            'rules'        => is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [],
            'fallback'     => $raw[self::FALLBACK_KEY] === 'url' ? 'url' : 'dashboard',
            'fallback_url' => (string) $raw[self::FALLBACK_URL_KEY],
        ];
    }

    /**
     * THE RULE FOR THIS ACCOUNT — or null. Pure: the checks hand it the rules
     * and the account.
     *
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed>       $user   id, role
     * @return array<string, mixed>|null
     */
    public static function pick(array $rules, array $user): ?array
    {
        $numbered = static fn (array $r): bool => (int) ($r['order'] ?? 0) >= 1;
        $matching = array_values(array_filter($rules, static fn (array $r): bool => self::matches($r, $user)));
        if (array_filter($rules, $numbered) !== []) {
            $matching = array_values(array_filter($matching, $numbered));
        }
        return self::ordered($matching)[0] ?? null;
    }

    /**
     * Rules in the order they are decided: particular accounts, a role, all
     * users; within each, order 1, 2, 3… and then no order; then the oldest.
     *
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    public static function ordered(array $rules): array
    {
        $rank = ['user' => 0, 'role' => 1, 'all' => 2];
        $key  = static fn (array $r): array => [$rank[(string) ($r['who'] ?? 'all')] ?? 3, (int) ($r['order'] ?? 0) ?: PHP_INT_MAX, (int) ($r['id'] ?? 0)];
        usort($rules, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $rules;
    }

    /** Is this rule for this account? */
    public static function matches(array $rule, array $user): bool
    {
        return match ((string) ($rule['who'] ?? '')) {
            'all'   => true,
            'role'  => in_array((string) ($user['role'] ?? ''), (array) ($rule['roles'] ?? []), true),
            'user'  => in_array((int) ($user['id'] ?? 0), array_map('intval', (array) ($rule['users'] ?? [])), true),
            default => false,
        };
    }

    /**
     * Where this account goes after signing in — or null for the dashboard.
     *
     * @param array<string, mixed> $user  id, username, role
     */
    public static function afterSignIn(array $user): ?string
    {
        return self::destination($user, 'login');
    }

    /** Where after signing out — or null for the sign-in page. */
    public static function afterSignOut(array $user): ?string
    {
        return self::destination($user, 'logout');
    }

    /**
     * An address on this site, as typed: a path, nothing that leaves the
     * site. The cleaned path, or null when it is not one.
     */
    public static function sitePath(string $typed): ?string
    {
        $path = trim($typed);
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F\s]/', $path) === 1 || preg_match('#^/[^/?]*:#', $path) === 1) {
            return null;
        }

        return $path;
    }

    /**
     * A target as a URL, the account's placeholders filled — or null when it
     * leads nowhere: a page gone or unpublished, an address that would leave
     * the site.
     *
     * @param array{type?: string, value?: mixed} $target
     * @param array<string, mixed>                $user
     */
    public static function resolve(array $target, array $user, ?callable $page = null): ?string
    {
        $value = (string) ($target['value'] ?? '');
        if (($target['type'] ?? '') === 'page') {
            $row = ($page ?? static fn (int $id): ?array => Page::find($id))((int) $value);

            // Its address as the menus write it (`MenuItem::resolveUrl()`).
            return $row !== null && ($row['status'] ?? '') === 'published' ? Url::pagePath((string) $row['slug']) : null;
        }
        if (($target['type'] ?? '') !== 'url') {
            return null;
        }
        $filled = strtr($value, [
            '{{username}}' => rawurlencode((string) ($user['username'] ?? '')),
            '{{user_id}}'  => (string) (int) ($user['id'] ?? 0),
        ]);

        return self::sitePath($filled);
    }

    /** @param array<string, mixed> $user */
    private static function destination(array $user, string $event): ?string
    {
        $s = self::settings();
        if (!$s['on']) {
            return null;
        }
        $rule = self::pick($s['rules'], $user);
        if ($rule === null) {
            return null;
        }
        $target = (array) ($rule[$event] ?? []);
        if ($event === 'logout' && ($target['type'] ?? 'none') === 'none') {
            return null;
        }
        $url = self::resolve($target, $user);
        if ($url !== null) {
            return $url;
        }

        return $s['fallback'] === 'url' ? self::resolve(['type' => 'url', 'value' => $s['fallback_url']], $user) : null;
    }
}
