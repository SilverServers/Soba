<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Helpers\Paging;
use Cms\Helpers\Slug;
use Cms\Helpers\Url;
use Cms\Models\Option;
use Cms\Models\Page;
use Cms\Models\User;
use Cms\Security\AccountLock;
use Cms\Security\Accounts;
use Cms\Security\AddressRules;
use Cms\Security\Alerts;
use Cms\Security\Challenge;
use Cms\Security\ClientIp;
use Cms\Security\Enumeration;
use Cms\Security\FileCheck;
use Cms\Security\Hsts;
use Cms\Security\LoginRedirection;
use Cms\Security\LoginUrl;
use Cms\Security\Passwordless;
use Cms\Security\PasswordPolicy;
use Cms\Security\SecurityLog;
use Cms\Security\Sessions;
use Cms\Security\TemporaryAccess;
use Cms\Security\Throttle;
use Cms\Security\TwoStep;
use Cms\Security\Usernames;

/**
 * SECURITY — the screens and their tabs, among them:
 *
 *   /admin/security                    Overview
 *   /admin/security/login-protection   Login protection
 *        ?tab=address                    Sign-in address
 *        ?tab=attempts                   Attempt limit
 *        ?tab=usernames                  Common usernames
 *        ?tab=passwords                  Password strength
 *                                        (+ "ask everyone for a new password")
 *   /admin/security/activity           Activity log
 *        ?tab=lockouts                   Lockouts
 *        ?tab=failed                     Failed sign-ins
 *        ?tab=sign-ins                   Sign-ins
 *
 * "Overview" and not "Dashboard": the admin's first row is already called
 * Dashboard, and two rows with one name is a column that cannot say where you
 * are. Administrators only, like every screen that changes how the site is
 * reached.
 */
final class SecurityController
{
    /** @var array<string, string> tab => label, in the order shown */
    public const LOGIN_TABS = [
        'address'   => 'Sign-in address',
        // Where an account goes after it signs in or out (`LoginRedirection`).
        'redirection' => 'Redirection',
        'attempts'  => 'Attempt limit',
        'usernames' => 'Common usernames',
        'passwords' => 'Password strength',
        // Accounts suspended when unused (`Accounts`).
        'inactive'  => 'Inactive accounts',
    ];

    /** @var array<string, string> tab => label — two-step sign-in's tabs, in the order shown */
    public const TWO_STEP_TABS = [
        'methods'  => 'Authentication methods',
        'policies' => 'Policies',
        'advanced' => 'Advanced settings',
    ];

    /** @var array<string, string> tab => label — signing in without a password, in the order shown */
    public const PASSWORDLESS_TABS = [
        'code' => 'Code by email',
        'link' => 'Sign-in link',
    ];

    /**
     * @var array<string, string> tab => label, in the order shown. Named
     * "Blocking": the whole group is Security, and two rows with one name is a
     * column that cannot say where you are (the same reason the Overview is
     * not called Dashboard).
     */
    public const BLOCKING_TABS = [
        'captcha'     => 'CAPTCHA',
        'addresses'   => 'Addresses',
        'enumeration' => 'User enumeration',
    ];

    /** @var array<string, string> tab => label, in the order shown */
    public const LOG_TABS = [
        'lockouts'      => 'Lockouts',
        'failed'        => 'Failed sign-ins',
        'sign-ins'      => 'Sign-ins',
        'enumeration'   => 'Enumeration',
        // Who changed what (`Audit`).
        'changes'       => 'Changes',
        // Who is told about the door's events, and how (`Alerts`).
        'notifications' => 'Notifications',
    ];

    /** @var array<string, string> period => label — the Overview's filter */
    public const PERIODS = [
        'today'      => 'Today',
        '7'          => 'Last 7 days',
        '14'         => 'Last 14 days',
        'last-month' => 'Last month',
    ];

    // ── Overview ────────────────────────────────────────────────────────────

    public function overview(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user = Auth::user();
        // Before anything is read, so a suspension the inactivity rule is due
        // is in the counts and the recent list alike (`Accounts`).
        Accounts::sweep();

        $period = (string) ($_GET['period'] ?? '7');
        if (!isset(self::PERIODS[$period])) {
            $period = '7';
        }

        // Days are the OWNER's days (Settings → General's time zone), so
        // "Today" starts at their midnight, not at UTC's.
        $zone  = \Cms\Helpers\Dates::zone();
        $utc   = static fn (string $when): string
            => gmdate('Y-m-d H:i:s', (new \DateTimeImmutable($when, $zone))->getTimestamp());
        $until = null;
        $since = match ($period) {
            'today' => $utc('today'),
            '14'    => gmdate('Y-m-d H:i:s', time() - 14 * 86400),
            'last-month' => $utc('first day of last month midnight'),
            default => gmdate('Y-m-d H:i:s', time() - 7 * 86400),
        };
        if ($period === 'last-month') {
            $until = $utc('first day of this month midnight');
        }

        $counts = [
            'failed'   => SecurityLog::count([SecurityLog::SIGN_IN_FAILED], $since, $until),
            'lockouts' => SecurityLog::count([SecurityLog::LOCKOUT, SecurityLog::ACCOUNT_LOCKED], $since, $until),
            'sign-ins' => SecurityLog::count([SecurityLog::SIGN_IN], $since, $until),
            'active'   => Throttle::activeCount(),
        ];

        $recent = SecurityLog::page(
            [SecurityLog::SIGN_IN, SecurityLog::SIGN_IN_FAILED, SecurityLog::LOCKOUT, SecurityLog::LOCKOUT_LIFTED,
             SecurityLog::ACCOUNT_LOCKED, SecurityLog::ACCOUNT_UNLOCKED,
             SecurityLog::PASSWORD_CHANGED, SecurityLog::PASSWORDS_RESET, SecurityLog::SIGN_IN_MOVED,
             SecurityLog::PASSWORD_RESET, SecurityLog::TWO_STEP_CHANGED,
             SecurityLog::ACCOUNT_SUSPENDED, SecurityLog::ACCOUNT_RESTORED, SecurityLog::FILES_CHANGED, SecurityLog::ACCESS_GIVEN],
            1,
            8
        )['items'];

        $limit     = Throttle::settings();
        $moved     = LoginUrl::enabled();
        $signInUrl = LoginUrl::url();
        $usernames = Usernames::settings();
        $pending   = User::pendingPasswordChanges();
        $rule      = PasswordPolicy::summary();
        $twoStep   = ['policy' => TwoStep::policySummary(), 'adoption' => TwoStep::adoption(), 'ready' => TwoStep::ready()];
        $blocking  = ['captcha' => Challenge::mode(), 'addresses' => AddressRules::settings()];
        $account   = AccountLock::settings();
        $inactive       = Accounts::settings();
        $suspendedCount = count(Accounts::suspended());
        $passwordless = Passwordless::settings();
        // Temporary access still running — swept above, with the
        // inactivity rule.
        $temporaryRunning = count(array_filter(TemporaryAccess::all(),
            static fn (array $a): bool => in_array(TemporaryAccess::state($a, time()), ['active', 'used'], true)));
        // The CMS's files, compared now — about 60 ms.
        $files = FileCheck::compare();
        FileCheck::note($files);

        $this->render('security/overview.php', 'Security', compact(
            'user', 'period', 'counts', 'recent', 'limit', 'account', 'moved', 'signInUrl', 'usernames', 'pending', 'rule',
            'twoStep', 'blocking', 'inactive', 'suspendedCount', 'files', 'passwordless', 'temporaryRunning'
        ) + ['emailSends' => Alerts::emailSends()]);
    }

    // ── Passwordless ────────────────────────────────────────────────────────

    public function passwordless(array $vars = []): void
    {
        Auth::requireRole('admin');
        $tab = (string) ($_GET['tab'] ?? 'code');
        $tab = isset(self::PASSWORDLESS_TABS[$tab]) ? $tab : 'code';
        TwoStep::requireConfirmed('/admin/security/passwordless?tab=' . $tab);

        $this->renderPasswordlessSettings($tab, [], false);
    }

    /**
     * One way at a time — each tab is its own form. On failure nothing is
     * written, and the form comes back as typed.
     */
    public function savePasswordless(array $vars = []): void
    {
        Auth::requireRole('admin');
        $tab = (string) ($_POST['_tab'] ?? '');
        $tab = isset(self::PASSWORDLESS_TABS[$tab]) ? $tab : 'code';
        TwoStep::requireConfirmed('/admin/security/passwordless?tab=' . $tab);

        $on     = ($_POST['enabled'] ?? '') === '1';
        $raw    = trim((string) ($_POST['minutes'] ?? ''));
        $roles  = Passwordless::parseRoles(implode(',', array_map('strval', (array) ($_POST['roles'] ?? []))));
        $posted = ['enabled' => $on, 'minutes' => $raw, 'roles' => $roles];
        $errors = [];

        [$min, $max] = Passwordless::LIMITS[$tab];
        if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
            $errors[] = sprintf('Minutes must be a whole number from %d to %d.', $min, $max);
        }
        if ($on && $roles === []) {
            $errors[] = 'Choose who may sign in this way, or switch it off.';
        }
        if ($errors === []) {
            Option::setMany($tab === 'code'
                ? [Passwordless::CODE_KEY => $on ? '1' : '0', Passwordless::CODE_MINUTES_KEY => (string) (int) $raw, Passwordless::CODE_ROLES_KEY => implode(',', $roles)]
                : [Passwordless::LINK_KEY => $on ? '1' : '0', Passwordless::LINK_MINUTES_KEY => (string) (int) $raw, Passwordless::LINK_ROLES_KEY => implode(',', $roles)]);
        }

        $this->renderPasswordlessSettings($tab, $errors, $errors === [], $errors === [] ? [] : $posted);
    }

    /** @param array<string, mixed> $posted  what was typed, after a refused save */
    private function renderPasswordlessSettings(string $tab, array $errors, bool $saved, array $posted = []): void
    {
        $s    = Passwordless::settings();
        $form = $posted !== []
            ? $posted
            : ['enabled' => $s[$tab], 'minutes' => (string) $s[$tab . '_minutes'], 'roles' => $s[$tab . '_roles']];

        $this->render('security/passwordless.php', 'Passwordless', [
            'user'       => Auth::user(),
            'tab'        => $tab,
            'errors'     => $errors,
            'saved'      => $saved,
            'form'       => $form,
            'settings'   => $s,
            'smtpHost'   => trim((string) Option::get('smtp_host', '')),
            'emailSends' => Alerts::emailSends(),
        ]);
    }

    // ── Temporary access ────────────────────────────────────────────────────

    /**
     * Security → Temporary access: the accounts given it, and the form that
     * gives it. `?renew=ID` turns the form into that account's renewal.
     */
    public function temporaryAccess(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/temporary-access');
        // Whatever has run out is ended before it is listed (`TemporaryAccess::sweep()`).
        Accounts::sweep();

        $renew = (int) ($_GET['renew'] ?? 0);
        $this->renderTemporaryAccess([], null, null, $renew > 0 && TemporaryAccess::of($renew) !== null ? $renew : null, []);
    }

    /**
     * Give, renew, a new link, or revoke — `_do` says which. A link is shown
     * on the page this returns, and never again: only its hash is kept.
     */
    public function saveTemporaryAccess(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/temporary-access');
        Accounts::sweep();

        $do     = (string) ($_POST['_do'] ?? 'give');
        $id     = (int) ($_POST['user_id'] ?? 0);
        $by     = (int) (Auth::user()['id'] ?? 0);
        $access = $id > 0 ? TemporaryAccess::of($id) : null;

        if ($do === 'revoke' && $access !== null) {
            $ended = TemporaryAccess::end($id, $by);
            $this->renderTemporaryAccess($ended ? [] : ['That access had already ended.'], $ended ? 'Revoked. Its browsers are signed out, and its link no longer works.' : null, null, null, []);
            return;
        }
        if ($do === 'link' && $access !== null) {
            $token = TemporaryAccess::newLink($id);
            $this->renderTemporaryAccess($token === null ? ['That access is used up or has ended. Renew it to make a new link.'] : [], null,
                $token === null ? null : ['user_id' => $id, 'link' => TemporaryAccess::link($token), 'how' => 'replaced'], null, []);
            return;
        }

        // Give, or renew: the same terms. Renewing keeps the account's own
        // address, name and role.
        $renew  = $do === 'renew' && $access !== null ? $id : null;
        $posted = [
            'email' => trim((string) ($_POST['email'] ?? '')),
            'name'  => trim((string) ($_POST['name'] ?? '')),
            'role'  => (string) ($_POST['role'] ?? ''),
            'count' => trim((string) ($_POST['count'] ?? '')),
            'unit'  => (string) ($_POST['unit'] ?? ''),
            'uses'  => trim((string) ($_POST['uses'] ?? '')),
        ];
        $errors  = [];
        $seconds = TemporaryAccess::lifespan($posted['count'], $posted['unit']);
        $uses    = TemporaryAccess::uses($posted['uses']);
        if ($renew === null) {
            if (!filter_var($posted['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter the email address of the person it is for.';
            } elseif (User::emailExists($posted['email'])) {
                $errors[] = 'An account already has that email address.';
            }
            if (mb_strlen($posted['name']) > 100) {
                $errors[] = 'The name can be at most 100 characters.';
            }
            if (!isset(TemporaryAccess::ROLES[$posted['role']])) {
                $errors[] = 'Choose Editor or Author.';
            }
        }
        if ($seconds === null) {
            $errors[] = 'It lasts from an hour to 365 days.';
        }
        if ($uses === null) {
            $errors[] = sprintf('Uses must be a whole number from %d to %d.', TemporaryAccess::USES[0], TemporaryAccess::USES[1]);
        }
        if ($errors !== []) {
            $this->renderTemporaryAccess($errors, null, null, $renew, $posted);
            return;
        }

        if ($renew !== null) {
            $made = ['user_id' => $renew, 'link' => TemporaryAccess::link((string) TemporaryAccess::renew($renew, (int) $seconds, (int) $uses, $by)), 'how' => 'renewed'];
        } else {
            $new  = TemporaryAccess::create($posted['email'], $posted['name'], $posted['role'], (int) $seconds, (int) $uses, $by);
            $made = ['user_id' => $new['user_id'], 'link' => TemporaryAccess::link($new['token']), 'how' => 'given'];
        }
        $this->renderTemporaryAccess([], null, $made, null, []);
    }

    /**
     * @param list<string>              $errors
     * @param array<string, mixed>|null $made    a link just made — shown this once
     * @param array<string, string>     $posted  what was typed, after a refused save
     */
    private function renderTemporaryAccess(array $errors, ?string $notice, ?array $made, ?int $renew, array $posted): void
    {
        if ($made !== null) {
            $made += ['access' => TemporaryAccess::of((int) $made['user_id']), 'account' => User::find((int) $made['user_id'])];
        }
        $renewing = $renew !== null ? ['access' => TemporaryAccess::of($renew), 'account' => User::find($renew)] : null;
        $form     = $posted !== [] ? $posted : [
            'email' => '', 'name' => '', 'role' => 'editor',
            'count' => (string) TemporaryAccess::DEFAULT_DAYS, 'unit' => 'days', 'uses' => (string) TemporaryAccess::DEFAULT_USES,
        ];

        $this->render('security/temporary-access.php', 'Temporary access', [
            'user'     => Auth::user(),
            'errors'   => $errors,
            'notice'   => $notice,
            'made'     => $made,
            'renewing' => $renewing,
            'form'     => $form,
            'accesses' => TemporaryAccess::all(),
            'now'      => time(),
        ]);
    }

    // ── HTTPS ───────────────────────────────────────────────────────────────

    /** Security → HTTPS: HTTP Strict Transport Security, an owner's decision. */
    public function https(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/https');

        $this->renderHttps([], false, null);
    }

    public function saveHttps(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/https');

        $posted = [
            'on'         => ($_POST['enabled'] ?? '') === '1',
            'max_age'    => (int) ($_POST['max_age'] ?? 0),
            'subdomains' => ($_POST['subdomains'] ?? '') === '1',
            'preload'    => ($_POST['preload'] ?? '') === '1',
        ];
        $errors = [];
        if (!isset(Hsts::AGES[$posted['max_age']])) {
            $errors[] = 'Choose how long browsers remember it.';
        }
        if ($posted['on'] && !str_starts_with(strtolower((string) BASE_URL), 'https://')) {
            $errors[] = 'This site\'s address is not HTTPS (config.php, base_url), so there is nothing to keep browsers on.';
        }
        if ($posted['preload'] && (!$posted['subdomains'] || $posted['max_age'] < Hsts::PRELOAD_MIN_AGE)) {
            $errors[] = 'A preload list takes only a year or more, with every subdomain.';
        }
        if ($errors === []) {
            Option::setMany([
                Hsts::ON_KEY => $posted['on'] ? '1' : '0', Hsts::MAX_AGE_KEY => (string) $posted['max_age'],
                Hsts::SUBDOMAINS_KEY => $posted['subdomains'] ? '1' : '0', Hsts::PRELOAD_KEY => $posted['preload'] ? '1' : '0',
            ]);
        }

        $this->renderHttps($errors, $errors === [], $errors === [] ? null : $posted);
    }

    /** @param array<string, mixed>|null $posted  what was chosen, after a refused save */
    private function renderHttps(array $errors, bool $saved, ?array $posted): void
    {
        $form = $posted ?? Hsts::settings();

        $this->render('security/https.php', 'HTTPS', [
            'user'   => Auth::user(),
            'errors' => $errors,
            'saved'  => $saved,
            'form'   => $form,
            'https'  => str_starts_with(strtolower((string) BASE_URL), 'https://'),
            'header' => Hsts::value(Hsts::settings(), (string) BASE_URL),
        ]);
    }

    // ── Files ───────────────────────────────────────────────────────────────

    /**
     * Security → Files: the CMS's files against what their release shipped,
     * compared now (`FileCheck`) — and, like any check, a new set of
     * differences is logged here.
     */
    public function files(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/files');

        $result = FileCheck::compare();
        FileCheck::note($result);
        $last = FileCheck::last();

        $this->render('security/files.php', 'Files', compact('result', 'last'));
    }

    // ── Login protection ────────────────────────────────────────────────────

    public function loginProtection(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/login-protection?tab=' . preg_replace('/[^a-z-]/', '', (string) ($_GET['tab'] ?? 'address')));

        $tab = (string) ($_GET['tab'] ?? 'address');
        $this->renderLoginProtection(isset(self::LOGIN_TABS[$tab]) ? $tab : 'address', [], false);
    }

    public function saveLoginProtection(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/login-protection?tab=' . preg_replace('/[^a-z-]/', '', (string) ($_POST['_tab'] ?? 'address')));

        $tab    = (string) ($_POST['_tab'] ?? '');
        $errors = [];
        $posted = [];

        if ($tab === 'address') {
            [$errors, $posted] = $this->saveAddress();
        } elseif ($tab === 'attempts') {
            [$errors, $posted] = $this->saveAttempts();
        } elseif ($tab === 'usernames') {
            [$errors, $posted] = $this->saveUsernames();
        } elseif ($tab === 'passwords') {
            [$errors, $posted] = $this->savePasswords();
        } elseif ($tab === 'inactive') {
            [$errors, $posted] = $this->saveInactive();
        } elseif ($tab === 'redirection') {
            [$errors, $posted] = $this->saveRedirection();
        } else {
            $tab = 'address';
        }

        $this->renderLoginProtection($tab, $errors, $errors === [], $posted);
    }

    /**
     * Sign-in address. On failure nothing is written — the form comes back
     * with what was typed, so a refused address can be corrected in place.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function saveAddress(): array
    {
        // AN ADDRESS IN THE BOX MOVES THE SIGN-IN PAGE THERE; AN EMPTY BOX
        // LEAVES IT AT /admin/login. There is no other switch. A tick box
        // beside the address, saying whether the address counted, was a
        // second control for one decision, and nobody ticked it.
        $typed       = trim((string) ($_POST['slug'] ?? ''));
        $slug        = Slug::clean((string) ($_POST['slug'] ?? ''));
        $enabled     = $slug !== '';
        $redirectRaw = trim((string) ($_POST['redirect'] ?? ''));
        $redirect    = $redirectRaw === '/' ? '/' : Slug::cleanPath($redirectRaw);
        $redirect    = ($redirect === '' || $redirect === '/') ? $redirect : '/' . $redirect;

        $posted = ['slug' => $enabled ? $slug : $typed, 'redirect' => $redirect];
        $errors = [];

        // Something typed that is no address at all ("!!!") is refused, not
        // read as an empty box: the owner meant to move the page.
        $problem = $enabled ? LoginUrl::problem($slug) : ($typed !== '' ? LoginUrl::problem($typed) : null);
        if ($problem !== null) {
            $errors[] = $problem;
        }

        if ($redirect !== '' && $redirect !== '/') {
            $first = explode('/', trim($redirect, '/'))[0];
            if ($first === 'admin' || $first === trim((string) ADMIN_URL_SLUG, '/') || $first === $slug) {
                // Sending a turned-away visitor to the admin loops them, and
                // sending them to the sign-in page hands them the address.
                $errors[] = 'Old addresses cannot be sent into the admin or to the sign-in page.';
            }
        }

        if ($errors !== []) {
            return [$errors, $posted];
        }

        $wasEnabled = LoginUrl::enabled();
        $wasSlug    = LoginUrl::slug();

        Option::setMany([
            LoginUrl::ENABLED_KEY  => $enabled ? '1' : '0',
            LoginUrl::SLUG_KEY     => $slug,
            LoginUrl::REDIRECT_KEY => $redirect,
        ]);
        LoginUrl::forget();

        // Into the log, so "who moved the door, and when" has an answer. The
        // address itself stays out of it: the log is a list anybody reviewing
        // attacks will read, and the address is the one thing being hidden.
        $me = (int) (Auth::user()['id'] ?? 0);
        if ($enabled && (!$wasEnabled || $wasSlug !== $slug)) {
            SecurityLog::record(SecurityLog::SIGN_IN_MOVED, null, $me, 'sign-in page moved');
        } elseif (!$enabled && $wasEnabled) {
            SecurityLog::record(SecurityLog::SIGN_IN_MOVED, null, $me, 'sign-in page back at /admin/login');
        }

        return [[], []];
    }

    /** @return array{0: list<string>, 1: array<string, string>} */
    private function saveAttempts(): array
    {
        $fields = [
            Throttle::ATTEMPTS_KEY       => 'attempts',
            Throttle::MINUTES_KEY        => 'minutes',
            Throttle::ESCALATE_AFTER_KEY => 'escalate_after',
            Throttle::ESCALATE_HOURS_KEY => 'escalate_hours',
        ];
        $labels = [
            'attempts'       => 'Attempts',
            'minutes'        => 'Lockout',
            'escalate_after' => 'Longer lockout after',
            'escalate_hours' => 'Longer lockout',
        ];

        $posted = ['enabled' => ($_POST['enabled'] ?? '') === '1' ? '1' : '0'];
        $errors = [];
        $save   = [Throttle::ENABLED_KEY => $posted['enabled']];

        foreach ($fields as $key => $name) {
            $raw           = trim((string) ($_POST[$name] ?? ''));
            $posted[$name] = $raw;
            [$min, $max]   = Throttle::LIMITS[$key];
            if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
                $errors[] = sprintf('%s must be a whole number from %d to %d.', $labels[$name], $min, $max);
                continue;
            }
            $save[$key] = (string) (int) $raw;
        }

        // Per username, from every address (`AccountLock`).
        $posted['account_enabled'] = ($_POST['account_enabled'] ?? '') === '1' ? '1' : '0';
        $save[AccountLock::ENABLED_KEY] = $posted['account_enabled'];
        foreach ([AccountLock::ATTEMPTS_KEY => ['account_attempts', 'Attempts on one username'],
                  AccountLock::MINUTES_KEY  => ['account_minutes', 'The username lock']] as $key => [$name, $label]) {
            $raw           = trim((string) ($_POST[$name] ?? ''));
            $posted[$name] = $raw;
            [$min, $max]   = AccountLock::LIMITS[$key];
            if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
                $errors[] = sprintf('%s must be a whole number from %d to %d.', $label, $min, $max);
                continue;
            }
            $save[$key] = (string) (int) $raw;
        }

        $message           = trim((string) ($_POST['message'] ?? ''));
        $posted['message'] = $message;
        if (mb_strlen($message) > 200) {
            $errors[] = 'The lockout message can be at most 200 characters.';
        }
        $save[Throttle::MESSAGE_KEY] = $message;

        if ($errors !== []) {
            return [$errors, $posted];
        }

        Option::setMany($save);
        Throttle::forget();
        AccountLock::forget();

        return [[], []];
    }

    /** @return array{0: list<string>, 1: array<string, string>} */
    private function saveUsernames(): array
    {
        $enabled = ($_POST['enabled'] ?? '') === '1';
        $list    = Usernames::parse((string) ($_POST['list'] ?? ''));

        Option::setMany([
            Usernames::ENABLED_KEY => $enabled ? '1' : '0',
            Usernames::LIST_KEY    => implode("\n", $list),
            Usernames::TRAP_KEY    => ($_POST['trap'] ?? '') === '1' ? '1' : '0',
        ]);
        Usernames::forget();

        return [[], []];
    }

    /**
     * Inactive accounts (`Accounts`). On failure nothing is
     * written; the form comes back as typed.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    /**
     * Redirection: the switch and the fallback, one rule, or a rule
     * deleted — `_part` says which. On failure nothing is written, and the
     * form comes back as typed.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function saveRedirection(): array
    {
        $s    = LoginRedirection::settings();
        $part = (string) ($_POST['_part'] ?? '');

        if ($part === 'settings') {
            $on       = ($_POST['enabled'] ?? '') === '1';
            $fallback = ($_POST['fallback'] ?? '') === 'url' ? 'url' : 'dashboard';
            $url      = trim((string) ($_POST['fallback_url'] ?? ''));
            $posted   = ['part' => 'settings', 'on' => $on, 'fallback' => $fallback, 'fallback_url' => $url];
            if ($fallback === 'url' && LoginRedirection::sitePath($url) === null) {
                return [['The fallback must be an address on this site, beginning with /.'], $posted];
            }
            Option::setMany([LoginRedirection::ON_KEY => $on ? '1' : '0', LoginRedirection::FALLBACK_KEY => $fallback,
                             LoginRedirection::FALLBACK_URL_KEY => $fallback === 'url' ? $url : '']);
            return [[], []];
        }

        if ($part === 'delete') {
            $id    = (int) ($_POST['rule'] ?? 0);
            $rules = array_values(array_filter($s['rules'], static fn (array $r): bool => (int) ($r['id'] ?? 0) !== $id));
            Option::set(LoginRedirection::RULES_KEY, (string) json_encode($rules, JSON_UNESCAPED_SLASHES));
            return [[], []];
        }

        // One rule, new or edited.
        $id     = (int) ($_POST['rule'] ?? 0);
        $who    = (string) ($_POST['who'] ?? '');
        $roles  = array_values(array_intersect(array_keys(LoginRedirection::ROLES), array_map('strval', (array) ($_POST['roles'] ?? []))));
        $known  = array_map(static fn (array $u): int => (int) $u['id'], User::all());
        $users  = array_values(array_intersect($known, array_map('intval', (array) ($_POST['users'] ?? []))));
        $target = static fn (string $event): array => [
            'type'  => (string) ($_POST[$event . '_type'] ?? ''),
            'value' => ($_POST[$event . '_type'] ?? '') === 'page' ? (string) (int) ($_POST[$event . '_page'] ?? 0) : trim((string) ($_POST[$event . '_url'] ?? '')),
        ];
        $rule   = ['id' => $id, 'who' => $who, 'roles' => $roles, 'users' => $users,
                   'login' => $target('login'), 'logout' => $target('logout'), 'order' => trim((string) ($_POST['order'] ?? '0'))];
        $posted = ['part' => 'rule', 'rule' => $rule];
        $errors = [];

        if (!isset(LoginRedirection::WHO[$who])) {
            $errors[] = 'Choose who the rule is for.';
        } elseif ($who === 'role' && $roles === []) {
            $errors[] = 'Choose at least one role.';
        } elseif ($who === 'user' && $users === []) {
            $errors[] = 'Choose at least one account.';
        }
        foreach (['login' => 'signing in', 'logout' => 'signing out'] as $event => $words) {
            $t = $rule[$event];
            if ($event === 'logout' && ($t['type'] === '' || $t['type'] === 'none')) {
                $rule['logout'] = ['type' => 'none', 'value' => ''];
                continue;
            }
            if ($t['type'] === 'page') {
                $page = Page::find((int) $t['value']);
                if ($page === null || ($page['status'] ?? '') !== 'published') {
                    $errors[] = 'Choose a published page for after ' . $words . '.';
                }
            } elseif ($t['type'] === 'url') {
                // Placeholders are filled at sign-in; the rest must already be a path on this site.
                if (LoginRedirection::sitePath(str_replace(LoginRedirection::PLACEHOLDERS, 'x', (string) $t['value'])) === null) {
                    $errors[] = 'The address after ' . $words . ' must be on this site, beginning with /.';
                }
            } else {
                $errors[] = 'Choose where to go after ' . $words . '.';
            }
        }
        if (!ctype_digit((string) $rule['order']) || (int) $rule['order'] > 999) {
            $errors[] = 'The order is a whole number from 0 to 999.';
        }
        $posted['rule'] = $rule;
        if ($errors !== []) {
            return [$errors, $posted];
        }

        $rule['order'] = (int) $rule['order'];
        $rule['roles'] = $who === 'role' ? $roles : [];
        $rule['users'] = $who === 'user' ? $users : [];
        $rules = $s['rules'];
        $found = false;
        foreach ($rules as $i => $r) {
            if ($id > 0 && (int) ($r['id'] ?? 0) === $id) {
                $rules[$i] = $rule;
                $found     = true;
            }
        }
        if (!$found) {
            $rule['id'] = max([0, ...array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rules)]) + 1;
            $rules[]    = $rule;
        }
        Option::set(LoginRedirection::RULES_KEY, (string) json_encode(array_values($rules), JSON_UNESCAPED_SLASHES));

        return [[], []];
    }

    private function saveInactive(): array
    {
        $enabled = ($_POST['enabled'] ?? '') === '1';
        $raw     = trim((string) ($_POST['days'] ?? ''));
        $roles   = Accounts::parseRoles(implode(',', array_map('strval', (array) ($_POST['roles'] ?? []))));
        $posted  = ['enabled' => $enabled ? '1' : '0', 'days' => $raw, 'roles' => $roles];

        [$min, $max] = Accounts::DAYS_LIMITS;
        if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
            return [[sprintf('Days must be a whole number from %d to %d.', $min, $max)], $posted];
        }
        if ($enabled && $roles === []) {
            return [['Choose who the rule covers, or switch it off.'], $posted];
        }

        $save = [
            Accounts::ENABLED_KEY => $posted['enabled'],
            Accounts::DAYS_KEY    => (string) (int) $raw,
            Accounts::ROLES_KEY   => implode(',', $roles),
        ];
        // SWITCHED ON, THE CLOCK STARTS NOW: nobody is suspended for the
        // months that passed while the rule was off.
        if ($enabled && !Accounts::settings()['enabled']) {
            $save[Accounts::SINCE_KEY] = gmdate('Y-m-d H:i:s');
        }
        Option::setMany($save);

        return [[], []];
    }

    /** @return array{0: list<string>, 1: array<string, string>} */
    private function savePasswords(): array
    {
        $posted = ['min' => trim((string) ($_POST['min'] ?? ''))];
        [$lo, $hi] = PasswordPolicy::MIN_RANGE;
        if (!ctype_digit($posted['min']) || (int) $posted['min'] < $lo || (int) $posted['min'] > $hi) {
            return [[sprintf('The minimum length must be a whole number from %d to %d.', $lo, $hi)], $posted];
        }

        $flag = static fn (string $name): string => ($_POST[$name] ?? '') === '1' ? '1' : '0';
        Option::setMany([
            PasswordPolicy::MIN_KEY      => (string) (int) $posted['min'],
            PasswordPolicy::CASE_KEY     => $flag('mixed_case'),
            PasswordPolicy::DIGIT_KEY    => $flag('digit'),
            PasswordPolicy::SYMBOL_KEY   => $flag('symbol'),
            PasswordPolicy::COMMON_KEY   => $flag('common'),
            PasswordPolicy::PERSONAL_KEY => $flag('personal'),
            PasswordPolicy::EXISTING_KEY => $flag('existing'),
        ]);
        PasswordPolicy::forget();

        return [[], []];
    }

    /**
     * POST — every other account must choose a new password, and is signed out
     * now. The one pressing it is exempt: they are here, signed in, and just
     * proved it.
     */
    public function resetAllPasswords(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/login-protection?tab=passwords');
        $me = (int) (Auth::user()['id'] ?? 0);

        $n = User::flagEveryonePasswordChange($me);
        // The version bump above already ends those sessions; their rows go
        // too, or each account's "Where this account is signed in" would go on
        // listing browsers that are no longer signed in.
        Sessions::revokeEveryoneBut($me);
        SecurityLog::record(SecurityLog::PASSWORDS_RESET, null, $me, $n . ' accounts asked for a new password');

        header('Location: ' . Url::admin('security/login-protection') . '?tab=passwords&asked=' . $n, true, 303);
        exit;
    }

    // ── Blocking ────────────────────────────────────────────────────────────

    /** GET /admin/security/blocking — CAPTCHA, addresses and user enumeration. */
    public function blocking(array $vars = []): void
    {
        Auth::requireRole('admin');
        $tab = (string) ($_GET['tab'] ?? 'captcha');
        $tab = isset(self::BLOCKING_TABS[$tab]) ? $tab : 'captcha';
        TwoStep::requireConfirmed('/admin/security/blocking?tab=' . $tab);

        $this->renderBlocking($tab, [], false);
    }

    /**
     * POST — one tab. Refused, nothing is written and the form comes back as
     * typed. The refusal that matters: a list that would turn away the very
     * address saving it — the owner's door closed on the owner, from the
     * screen (`AddressRules` has the way back from the server).
     */
    public function saveBlocking(array $vars = []): void
    {
        Auth::requireRole('admin');
        $tab = (string) ($_POST['_tab'] ?? 'captcha');
        $tab = isset(self::BLOCKING_TABS[$tab]) ? $tab : 'captcha';
        TwoStep::requireConfirmed('/admin/security/blocking?tab=' . $tab);

        $errors = [];
        if ($tab === 'captcha') {
            $mode = (string) ($_POST['mode'] ?? '');
            if (!isset(Challenge::MODES[$mode])) {
                $errors[] = 'Choose when the question is asked.';
            } else {
                Option::set(Challenge::MODE_KEY, $mode);
                Challenge::forget();
            }
        } elseif ($tab === 'enumeration') {
            Option::set(Enumeration::LOG_KEY, ($_POST['log'] ?? '') === '1' ? '1' : '0');
        } else {
            $mode    = (string) ($_POST['mode'] ?? '');
            $typed   = (string) ($_POST['list'] ?? '');
            $message = trim((string) ($_POST['message'] ?? ''));
            [$list, $bad] = AddressRules::parse($typed);

            if (!isset(AddressRules::MODES[$mode])) {
                $errors[] = 'Choose what the list means.';
            }
            if ($bad !== []) {
                $errors[] = 'Not an address or a range: ' . implode(', ', array_slice($bad, 0, 5))
                    . (count($bad) > 5 ? ' and ' . (count($bad) - 5) . ' more' : '') . '.';
            }
            if (count($list) > AddressRules::MAX_ENTRIES) {
                $errors[] = 'A list holds at most ' . AddressRules::MAX_ENTRIES . ' addresses and ranges.';
            }
            if (mb_strlen($message) > 255) {
                $errors[] = 'The message can be at most 255 characters.';
            }
            if ($mode === 'allow' && $list === []) {
                $errors[] = 'Letting in only the addresses on an empty list would turn everyone away. Add an address first.';
            }
            $mine = ClientIp::get();
            if ($errors === [] && AddressRules::refuses($mine, ['mode' => $mode, 'list' => $list, 'message' => ''])) {
                $errors[] = $mode === 'block'
                    ? 'Your own address, ' . ClientIp::display($mine) . ', is on the list. Saving would turn you away.'
                    : 'Your own address, ' . ClientIp::display($mine) . ', is not on the list. Saving would turn you away; add it first.';
            }

            if ($errors === []) {
                Option::setMany([
                    AddressRules::MODE_KEY    => $mode,
                    AddressRules::LIST_KEY    => implode("\n", $list),
                    AddressRules::MESSAGE_KEY => $message,
                ]);
                AddressRules::forget();
            }
        }

        $this->renderBlocking($tab, $errors, $errors === [], $errors === [] ? [] : $_POST);
    }

    /**
     * @param list<string>         $errors
     * @param array<string, mixed> $posted  what was sent, when a save was refused
     */
    private function renderBlocking(string $tab, array $errors, bool $saved, array $posted = []): void
    {
        $addresses = AddressRules::settings();
        $captcha   = Challenge::mode();
        if ($posted !== []) {
            if ($tab === 'captcha') {
                $captcha = (string) ($posted['mode'] ?? $captcha);
            } else {
                $addresses = [
                    'mode'    => (string) ($posted['mode'] ?? $addresses['mode']),
                    'list'    => preg_split('/\R/', trim((string) ($posted['list'] ?? ''))) ?: [],
                    'message' => trim((string) ($posted['message'] ?? '')),
                ];
            }
        }

        $this->render('security/blocking.php', 'Blocking', [
            'tab'       => $tab,
            'errors'    => $errors,
            'saved'     => $saved,
            'captcha'   => $captcha,
            'addresses' => $addresses,
            'mine'      => ClientIp::display(ClientIp::get()),
            'logging'   => Enumeration::logging(),
        ]);
    }

    // ── Two-step sign-in ────────────────────────────────────────────────────

    /** GET /admin/security/two-step — two-step sign-in's settings. */
    public function twoStep(array $vars = []): void
    {
        Auth::requireRole('admin');
        $tab = (string) ($_GET['tab'] ?? 'methods');
        $tab = isset(self::TWO_STEP_TABS[$tab]) ? $tab : 'methods';
        TwoStep::requireConfirmed('/admin/security/two-step?tab=' . $tab);

        $this->renderTwoStep($tab, [], false);
    }

    /**
     * POST — one tab's settings. Refused, nothing is written and the form comes
     * back as typed. The two refusals that matter: no method left while the
     * policy requires one, and a policy with no method to meet it — either
     * would stop every account it covers at a setup screen offering nothing.
     */
    public function saveTwoStep(array $vars = []): void
    {
        Auth::requireRole('admin');
        $tab = (string) ($_POST['_tab'] ?? 'methods');
        $tab = isset(self::TWO_STEP_TABS[$tab]) ? $tab : 'methods';
        TwoStep::requireConfirmed('/admin/security/two-step?tab=' . $tab);

        $now    = TwoStep::settings();
        $flag   = static fn (string $name): string => ($_POST[$name] ?? '') === '1' ? '1' : '0';
        $errors = [];

        if ($tab === 'methods') {
            $set = [TwoStep::APP_KEY => $flag('app'), TwoStep::EMAIL_KEY => $flag('email'), TwoStep::REMEMBER_KEY => $flag('remember')];
            if ($set[TwoStep::APP_KEY] === '0' && $set[TwoStep::EMAIL_KEY] === '0' && $now['roles'] !== []) {
                $errors[] = 'Keep a method on while the policy requires two-step sign-in. Clear the roles under Policies first.';
            }
        } elseif ($tab === 'policies') {
            $roles = array_values(array_intersect(array_keys(TwoStep::ROLE_NAMES), array_map('strval', (array) ($_POST['roles'] ?? []))));
            $grace = trim((string) ($_POST['grace_days'] ?? ''));
            [$lo, $hi] = TwoStep::GRACE_RANGE;
            if (!ctype_digit($grace) || (int) $grace < $lo || (int) $grace > $hi) {
                $errors[] = sprintf('The grace period must be a whole number of days from %d to %d.', $lo, $hi);
            }
            if ($roles !== [] && !$now['app'] && !$now['email']) {
                $errors[] = 'Turn a method on first, under Authentication methods: with none, nobody could set it up.';
            }
            $set = [TwoStep::ROLES_KEY => implode(',', $roles), TwoStep::GRACE_KEY => (string) (int) $grace];
        } else {
            $days    = (string) ($_POST['remember_days'] ?? '');
            $minutes = trim((string) ($_POST['code_minutes'] ?? ''));
            [$lo, $hi] = TwoStep::CODE_MINUTES_RANGE;
            if (!in_array((int) $days, TwoStep::REMEMBER_CHOICES, true)) {
                $errors[] = 'Choose how long a browser is remembered.';
            }
            if (!ctype_digit($minutes) || (int) $minutes < $lo || (int) $minutes > $hi) {
                $errors[] = sprintf('An emailed code must work for a whole number of minutes from %d to %d.', $lo, $hi);
            }
            $set = [TwoStep::REMEMBER_DAYS_KEY => (string) (int) $days, TwoStep::CODE_MINUTES_KEY => (string) (int) $minutes];
        }

        if ($errors === []) {
            Option::setMany($set);
            TwoStep::forget();
        }

        $this->renderTwoStep($tab, $errors, $errors === [], $errors === [] ? [] : $_POST);
    }

    /**
     * @param list<string>         $errors
     * @param array<string, mixed> $posted  what was sent, when a save was refused
     */
    private function renderTwoStep(string $tab, array $errors, bool $saved, array $posted = []): void
    {
        $settings = TwoStep::settings();
        if ($posted !== []) {
            // A refused save comes back as typed, not as stored.
            $settings = array_merge($settings, match ($tab) {
                'methods'  => ['app' => ($posted['app'] ?? '') === '1', 'email' => ($posted['email'] ?? '') === '1',
                               'remember' => ($posted['remember'] ?? '') === '1'],
                'policies' => ['roles' => array_values(array_map('strval', (array) ($posted['roles'] ?? []))),
                               'grace_days' => (string) ($posted['grace_days'] ?? '')],
                default    => ['remember_days' => (int) ($posted['remember_days'] ?? 0),
                               'code_minutes' => (string) ($posted['code_minutes'] ?? '')],
            });
        }

        $this->render('security/two-step.php', 'Two-step sign-in', [
            'tab'      => $tab,
            'errors'   => $errors,
            'saved'    => $saved,
            'settings' => $settings,
            'adoption' => TwoStep::adoption(),
            'ready'    => TwoStep::ready(),
            'enforced' => Auth::enforcesPasswordChanges(),
        ]);
    }

    /**
     * @param list<string>          $errors
     * @param array<string, string> $posted  what was typed, when a save was refused
     */
    private function renderLoginProtection(string $tab, array $errors, bool $saved, array $posted = []): void
    {
        $user = Auth::user();

        $address = [
            // THE ADDRESS IN FORCE, or what a refused save typed. One stored
            // while the page was not moved (an earlier release's switch left
            // off, `security.php login-url off`) is not shown: an address in
            // the box says the sign-in page is there.
            'slug'     => $posted['slug'] ?? (LoginUrl::enabled() ? LoginUrl::slug() : ''),
            'redirect' => $posted['redirect'] ?? LoginUrl::redirect(),
        ];
        $limit = $posted !== [] && $tab === 'attempts'
            ? $posted
            : (static function (): array {
                $s = Throttle::settings();
                $a = AccountLock::settings();
                return [
                    'enabled'          => $s['enabled'] ? '1' : '0',
                    'attempts'         => (string) $s['attempts'],
                    'minutes'          => (string) $s['minutes'],
                    'escalate_after'   => (string) $s['escalate_after'],
                    'escalate_hours'   => (string) $s['escalate_hours'],
                    'account_enabled'  => $a['enabled'] ? '1' : '0',
                    'account_attempts' => (string) $a['attempts'],
                    'account_minutes'  => (string) $a['minutes'],
                    'message'          => $s['message'],
                ];
            })();

        $moved     = LoginUrl::enabled();
        $signInUrl = LoginUrl::url();
        $siteUrl   = rtrim(Url::public(), '/') . '/';

        $usernames = Usernames::settings();
        $passwords = PasswordPolicy::settings();
        if ($posted !== [] && $tab === 'passwords') {
            $passwords['min'] = $posted['min'];
        }
        $pending  = User::pendingPasswordChanges();
        $asked    = isset($_GET['asked']) && ctype_digit((string) $_GET['asked']) ? (int) $_GET['asked'] : null;
        $enforced = Auth::enforcesPasswordChanges();

        // Inactive accounts: the rule as stored or as typed, and —
        // swept first, so the list is true — every suspended account.
        $inactive = Accounts::settings();
        if ($posted !== [] && $tab === 'inactive') {
            $inactive = ['enabled' => $posted['enabled'] === '1', 'days' => $posted['days'], 'roles' => $posted['roles'], 'since' => $inactive['since']];
        }
        $suspended = [];
        if ($tab === 'inactive') {
            Accounts::sweep();
            $suspended = Accounts::suspended();
        }
        $restored = isset($_GET['restored']);

        // Redirection: the rules, the pages and accounts a rule may
        // name, and the rule being edited — as typed after a refused save, or
        // `?rule=ID`.
        $redirection = LoginRedirection::settings();
        if ($posted !== [] && $tab === 'redirection' && ($posted['part'] ?? '') === 'settings') {
            $redirection = ['on' => $posted['on'], 'fallback' => $posted['fallback'], 'fallback_url' => $posted['fallback_url']] + $redirection;
        }
        $editing = null;
        if ($tab === 'redirection') {
            $editing = ($posted['part'] ?? '') === 'rule' ? $posted['rule'] : null;
            $wanted  = (int) ($_GET['rule'] ?? 0);
            foreach ($editing === null && $wanted > 0 ? $redirection['rules'] : [] as $r) {
                $editing = (int) ($r['id'] ?? 0) === $wanted ? $r : $editing;
            }
        }
        $redirectPages = $tab === 'redirection'
            ? array_values(array_filter(Page::all(), static fn (array $p): bool => ($p['status'] ?? '') === 'published'))
            : [];
        $redirectUsers = $tab === 'redirection' ? User::all() : [];

        $this->render('security/login-protection.php', 'Login protection', compact(
            'user', 'tab', 'errors', 'saved', 'address', 'limit', 'moved', 'signInUrl', 'siteUrl',
            'usernames', 'passwords', 'pending', 'asked', 'enforced', 'inactive', 'suspended', 'restored',
            'redirection', 'editing', 'redirectPages', 'redirectUsers'
        ));
    }

    // ── Activity log ────────────────────────────────────────────────────────

    public function activity(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user = Auth::user();

        $tab = (string) ($_GET['tab'] ?? 'lockouts');
        if (!isset(self::LOG_TABS[$tab])) {
            $tab = 'lockouts';
        }
        if ($tab === 'notifications') {
            // Settings, not a log: asked for again like every other
            // Security settings screen.
            TwoStep::requireConfirmed('/admin/security/activity?tab=notifications');
            $this->renderNotifications([], false);
            return;
        }

        // An admin list: `page`, whatever the site calls its own lists'.
        $page    = Paging::current('page');
        $perPage = 50;
        $result  = match ($tab) {
            'lockouts'    => Throttle::page($page, $perPage),
            'failed'      => SecurityLog::page([SecurityLog::SIGN_IN_FAILED], $page, $perPage),
            'enumeration' => SecurityLog::page([SecurityLog::ENUMERATION], $page, $perPage),
            'changes'     => SecurityLog::page([SecurityLog::CHANGE], $page, $perPage),
            default       => SecurityLog::page([SecurityLog::SIGN_IN], $page, $perPage),
        };
        $rows       = $result['items'];
        $total      = $result['total'];
        $totalPages = max(1, (int) ceil($total / $perPage));
        $lifted     = in_array($_GET['lifted'] ?? '', ['address', 'name'], true) ? (string) $_GET['lifted'] : '';

        $this->render('security/activity.php', 'Activity log', compact(
            'user', 'tab', 'rows', 'total', 'page', 'totalPages', 'lifted'
        ));
    }

    /**
     * POST — the Notifications tab. Refused, nothing is written and the form
     * comes back as typed. A channel switched on starts reading the log at its
     * end: switching Slack on must not post a year of history.
     */
    public function saveNotifications(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/activity?tab=notifications');

        $before = Alerts::settings();
        $save   = [Alerts::NEW_DEVICE_KEY => ($_POST['new_device'] ?? '') === '1' ? '1' : '0'];
        $errors = [];
        $end    = (string) (int) \Cms\Database\Database::getInstance()->query('SELECT COALESCE(MAX(id), 0) FROM security_log')->fetchColumn();

        foreach (Alerts::CHANNELS as $ch => $label) {
            $on     = ($_POST[$ch . '_on'] ?? '') === '1';
            $events = Alerts::eventList(implode(',', array_map('strval', (array) ($_POST[$ch . '_events'] ?? []))));
            $save[Alerts::key($ch, 'on')]     = $on ? '1' : '0';
            $save[Alerts::key($ch, 'events')] = implode(',', $events);
            if ($on && $events === []) {
                $errors[] = $label . ': choose what to send, or switch it off.';
            }

            if ($ch === 'email') {
                $typed = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($_POST['email_to'] ?? '')) ?: []), 'strlen'));
                $bad   = array_values(array_filter($typed, static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) === false));
                if ($bad !== []) {
                    $errors[] = 'Email: not an address — ' . implode(', ', array_slice($bad, 0, 5)) . '.';
                }
                $save[Alerts::key($ch, 'to')] = implode(', ', array_unique($typed));
            } else {
                $url = trim((string) ($_POST[$ch . '_url'] ?? ''));
                if (($on || $url !== '') && ($problem = Alerts::urlProblem($url)) !== null) {
                    $errors[] = $label . ': ' . $problem;
                }
                $save[Alerts::key($ch, 'url')] = $url;
            }

            if ($ch === 'webhook') {
                $secret = (string) ($_POST['webhook_secret'] ?? '');
                if (($_POST['webhook_secret_clear'] ?? '') === '1') {
                    $save[Alerts::key($ch, 'secret')] = '';
                } elseif ($secret !== '') {
                    if (strlen($secret) < 16 || strlen($secret) > 255) {
                        $errors[] = 'Webhook: a signing secret is 16 to 255 characters.';
                    }
                    $save[Alerts::key($ch, 'secret')] = $secret;
                }
            }

            if ($on && !$before[$ch]['on']) {
                $save[Alerts::key($ch, 'cursor')] = $end;
            }
        }

        if ($errors === []) {
            Option::setMany($save);
        }
        $this->renderNotifications($errors, $errors === [], $errors === [] ? [] : $_POST);
    }

    /** POST — one test message on one channel, with what is saved. Post / redirect / get. */
    public function testNotification(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/security/activity?tab=notifications');

        $channel = (string) ($_POST['channel'] ?? '');
        if (isset(Alerts::CHANNELS[$channel])) {
            Alerts::sendTest($channel);
        }

        header('Location: ' . Url::admin('security/activity') . '?tab=notifications&tested=' . rawurlencode($channel), true, 303);
        exit;
    }

    /**
     * @param list<string>         $errors
     * @param array<string, mixed> $posted  what was sent, when a save was refused
     */
    private function renderNotifications(array $errors, bool $saved, array $posted = []): void
    {
        $tested = (string) ($_GET['tested'] ?? '');
        $this->render('security/activity.php', 'Activity log', [
            'user'       => Auth::user(),
            'tab'        => 'notifications',
            'rows'       => [],
            'total'      => 0,
            'page'       => 1,
            'totalPages' => 1,
            'lifted'     => '',
            'alerts'     => Alerts::settings(),
            'newDevice'  => Alerts::newDeviceMail(),
            'errors'     => $errors,
            'saved'      => $saved,
            'posted'     => $posted,
            'tested'     => isset(Alerts::CHANNELS[$tested]) ? $tested : '',
            'admins'     => Alerts::recipients(''),
            'smtpHost'   => trim((string) Option::get('smtp_host', '')),
            'emailSends' => Alerts::emailSends(),
        ]);
    }

    /** POST — let a locked-out address, or a locked name, back in early. Post / redirect / get. */
    public function lift(array $vars = []): void
    {
        Auth::requireRole('admin');

        $row = Throttle::find((int) ($vars['id'] ?? 0));
        $url = Url::admin('security/activity') . '?tab=lockouts';
        if ($row !== null && Throttle::lift((int) $row['id'], (int) (Auth::user()['id'] ?? 0))) {
            $url .= '&lifted=' . ($row['username'] !== null ? 'name' : 'address');
        }

        header('Location: ' . $url, true, 303);
        exit;
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function render(string $template, string $pageTitle, array $data): void
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require ROOT_PATH . '/admin/templates/' . $template;
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }
}
