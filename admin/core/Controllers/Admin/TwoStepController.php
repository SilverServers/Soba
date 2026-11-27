<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Helpers\Url;
use Cms\Mail\Mailer;
use Cms\Models\User;
use Cms\SEO\Head;
use Cms\Security\ClientIp;
use Cms\Security\Factors;
use Cms\Security\Qr;
use Cms\Security\Sealed;
use Cms\Security\SecurityLog;
use Cms\Security\Throttle;
use Cms\Security\Totp;
use Cms\Security\TrustedBrowsers;
use Cms\Security\TwoStep;
use Cms\Http\Cache;

/**
 * SETTING UP TWO-STEP SIGN-IN — the signed-in half of `Security\TwoStep`:
 *
 *   /admin/two-step          set it up: the methods the site offers, and — for an
 *                            account the policy requires it of — why, and how
 *                            long is left
 *   /admin/two-step/app      a new app: the QR code, the key, one code to prove it
 *   /admin/two-step/email    email codes: one sent, one typed, to prove the mail arrives
 *   /admin/two-step/codes    ten new backup codes, shown once
 *   /admin/confirm           the password again, before any of the above when it
 *                            was not given in the last ten minutes
 *
 * No shell, like the pages either side of a sign-in: an account the policy
 * stops here has nowhere else to go, and a sidebar would say otherwise. What
 * an account HAS — its methods, codes left, remembered browsers, and the
 * buttons to remove or renew them — is a card on its own account screen,
 * beside its password and the browsers it is signed in on.
 *
 * ── A NEW METHOD IS PROVED BEFORE IT COUNTS ─────────────────────────────────
 * The app's secret waits in this session until the app shows the right code;
 * the email method waits until a code sent to the address comes back. A method
 * that was never proved is a lock-out waiting for the next sign-in.
 *
 * ── AND EVERY CHANGE SIGNS THE OTHER BROWSERS OUT ───────────────────────────
 * They signed in under the old arrangement. This browser stays signed in
 * (`Auth::endSessions()`).
 */
class TwoStepController
{
    private const SETUP_KEY = 'cms_two_step_setup';

    /** GET /admin/two-step — choose what to set up. */
    public function start(array $vars = []): void
    {
        Auth::requireAuth();
        TwoStep::requireConfirmed('/admin/two-step');

        $me = (array) Auth::user();
        $this->page('two-step-setup/start.php', [
            'factors'  => Factors::of((int) $me['id']),
            'owed'     => TwoStep::owed(),
            'graceEnd' => TwoStep::graceEnds((int) $me['id']),
            'settings' => TwoStep::settings(),
            'ready'    => TwoStep::ready(),
            'hasEmail' => trim((string) (User::find((int) $me['id'])['email'] ?? '')) !== '',
            'me'       => $me,
        ]);
    }

    /** GET/POST /admin/two-step/app */
    public function app(array $vars = []): void
    {
        Auth::requireAuth();
        TwoStep::requireConfirmed('/admin/two-step/app');
        $me = (array) Auth::user();
        if (!TwoStep::ready() || !TwoStep::settings()['app']) {
            $this->back();
        }

        $secret = (string) ($_SESSION[self::SETUP_KEY]['app'] ?? '');
        if ($secret === '') {
            $secret = Totp::newSecret();
            $_SESSION[self::SETUP_KEY]['app'] = $secret;
        }

        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $step = Totp::verify($secret, (string) ($_POST['code'] ?? ''));
            if ($step !== null) {
                unset($_SESSION[self::SETUP_KEY]['app']);
                Factors::saveApp((int) $me['id'], $secret, $step);
                $this->changed($me, 'authenticator app set up');
                $this->afterFirstMethod($me, 'app-on');
            }
            $error = 'That is not the code the app shows now. Check the app is set up with this site, and try the newest code.';
        }

        $site = (string) Head::globalTokens()['site_name'];
        $uri  = Totp::uri($site, (string) $me['username'], $secret);
        $this->page('two-step-setup/app.php', [
            'qr'      => Qr::svg($uri),
            'uri'     => $uri,
            'key'     => Totp::grouped($secret),
            'account' => (string) $me['username'],
            'site'    => $site,
            'error'   => $error,
        ], $error === null ? 200 : 422);
    }

    /** GET/POST /admin/two-step/email */
    public function email(array $vars = []): void
    {
        Auth::requireAuth();
        TwoStep::requireConfirmed('/admin/two-step/email');
        $me = (array) Auth::user();
        if (!TwoStep::ready() || !TwoStep::settings()['email']) {
            $this->back();
        }

        $address = trim((string) (User::find((int) $me['id'])['email'] ?? ''));
        $setup   = (array) ($_SESSION[self::SETUP_KEY]['email'] ?? []);
        $error   = null;
        $notice  = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $address !== '') {
            if (isset($_POST['send'])) {
                if (time() - (int) ($setup['sent_at'] ?? 0) < TwoStep::RESEND_SECONDS) {
                    $error = 'A code went out less than a minute ago. Check your email, or wait a moment and ask again.';
                } else {
                    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $setup = [
                        'code'    => Sealed::hmac('setup code:' . $me['id'] . ':' . $code),
                        'until'   => time() + TwoStep::settings()['code_minutes'] * 60,
                        'sent_at' => time(),
                    ];
                    $_SESSION[self::SETUP_KEY]['email'] = $setup;
                    $site = (string) Head::globalTokens()['site_name'];
                    Mailer::send([$address], $code . ' is your code for ' . $site,
                        "Type this code on {$site} to turn on sign-in codes by email:\n\n    {$code}\n\n"
                        . 'It works for ' . TwoStep::settings()['code_minutes'] . " minutes.\n");
                    $notice = 'The code is on its way.';
                }
            } else {
                $code = (string) preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));
                $ok   = isset($setup['code']) && time() <= (int) ($setup['until'] ?? 0)
                    && preg_match('/^\d{6}$/', $code) === 1
                    && hash_equals((string) $setup['code'], Sealed::hmac('setup code:' . $me['id'] . ':' . $code));
                if ($ok) {
                    unset($_SESSION[self::SETUP_KEY]['email']);
                    Factors::saveEmail((int) $me['id']);
                    $this->changed($me, 'email codes set up');
                    $this->afterFirstMethod($me, 'email-on');
                }
                $error = 'That is not the code we sent, or it is more than ' . TwoStep::settings()['code_minutes'] . ' minutes old.';
            }
        }

        $this->page('two-step-setup/email.php', [
            'address' => $address,
            'sent'    => isset($setup['code']),
            'minutes' => TwoStep::settings()['code_minutes'],
            'error'   => $error,
            'notice'  => $notice,
        ], $error === null ? 200 : 422);
    }

    /** POST /admin/two-step/codes — ten new backup codes, replacing the old. */
    public function codes(array $vars = []): void
    {
        Auth::requireAuth();
        TwoStep::requireConfirmed('/admin/users/' . (int) Auth::user()['id'] . '/edit');
        $me = (array) Auth::user();
        if (!Factors::any((int) $me['id'])) {
            $this->back();
        }

        $codes = Factors::newBackupCodes((int) $me['id']);
        SecurityLog::record(SecurityLog::TWO_STEP_CHANGED, (string) $me['username'], (int) $me['id'], 'new backup codes');
        $this->showCodes($codes, false);
    }

    /** POST /admin/two-step/remove — one method (`kind`). */
    public function remove(array $vars = []): void
    {
        Auth::requireAuth();
        TwoStep::requireConfirmed('/admin/users/' . (int) Auth::user()['id'] . '/edit');
        $me   = (array) Auth::user();
        $kind = (string) ($_POST['kind'] ?? '');
        if (!in_array($kind, [Factors::APP, Factors::EMAIL], true)) {
            $this->back();
        }

        Factors::remove((int) $me['id'], $kind);
        if (!Factors::any((int) $me['id'])) {
            TrustedBrowsers::forgetAll((int) $me['id']);
            TwoStep::noNewGrace((int) $me['id']);
        }
        $this->changed($me, ($kind === Factors::APP ? 'authenticator app' : 'email codes') . ' removed');

        $this->back($kind . '-off');
    }

    /** POST /admin/users/{id}/two-step/browsers/{bid}/forget */
    public function forgetBrowser(array $vars = []): void
    {
        Auth::requireAuth();
        $targetId = $this->mayManage((int) $vars['id']);
        TrustedBrowsers::forget($targetId, (int) $vars['bid']);

        $this->backTo($targetId, 'forgot');
    }

    /** POST /admin/users/{id}/two-step/browsers/forget-all */
    public function forgetBrowsers(array $vars = []): void
    {
        Auth::requireAuth();
        $targetId = $this->mayManage((int) $vars['id']);
        TrustedBrowsers::forgetAll($targetId);

        $this->backTo($targetId, 'forgot');
    }

    /**
     * POST /admin/users/{id}/two-step/off — an administrator turns SOMEONE
     * ELSE's two-step sign-in off: a lost phone and no backup codes. Every
     * method, code and remembered browser goes, and every browser of that
     * account is signed out. Their own is `remove()`, one method at a time.
     */
    public function turnOff(array $vars = []): void
    {
        Auth::requireRole('admin');
        $targetId = (int) $vars['id'];
        TwoStep::requireConfirmed('/admin/users/' . $targetId . '/edit');
        $me = (array) Auth::user();
        if ($targetId === (int) $me['id'] || User::find($targetId) === null) {
            $this->backTo($targetId, '');
        }

        Factors::removeAll($targetId);
        TrustedBrowsers::forgetAll($targetId);
        TwoStep::noNewGrace($targetId);
        Auth::endSessions($targetId);
        SecurityLog::record(SecurityLog::TWO_STEP_CHANGED, (string) (User::find($targetId)['username'] ?? ''), $targetId,
            'turned off by ' . $me['username']);

        $this->backTo($targetId, 'two-step-off');
    }

    /**
     * GET/POST /admin/confirm — the password again. Wrong answers count against
     * this address like any failed sign-in: a browser left signed in must not
     * be a place to guess the password from.
     */
    public function confirm(array $vars = []): void
    {
        Auth::requireAuth();
        $me     = (array) Auth::user();
        $return = TwoStep::safeReturn((string) ($_GET['return'] ?? ''));
        $error  = null;
        $status = 200;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $key     = ClientIp::key();
            $lockout = Throttle::lockoutFor($key);
            if ($lockout !== null) {
                Throttle::refuse($lockout);
                [$error, $status] = [Throttle::message($lockout), 429];
            } elseif (User::verifyPassword((int) $me['id'], (string) ($_POST['password'] ?? ''))) {
                TwoStep::markConfirmed();
                header('Location: ' . rtrim(BASE_URL, '/') . $return, true, 303);
                exit;
            } else {
                SecurityLog::record(SecurityLog::SIGN_IN_FAILED, (string) $me['username'], (int) $me['id'], 'wrong password (confirm)');
                $lockout = Throttle::fail($key, (string) $me['username']);
                [$error, $status] = $lockout !== null
                    ? [Throttle::message($lockout), 429]
                    : ['That is not this account\'s password.', 422];
            }
        }

        $this->page('confirm.php', ['return' => $return, 'error' => $error, 'account' => (string) $me['username']], $status);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * A method was added or removed: log it, and sign every other browser of
     * the account out.
     *
     * @param array<string, mixed> $me
     */
    private function changed(array $me, string $what): void
    {
        SecurityLog::record(SecurityLog::TWO_STEP_CHANGED, (string) $me['username'], (int) $me['id'], $what);
        Auth::endSessions((int) $me['id']);
    }

    /**
     * The first method gets the backup codes with it — shown now, the one
     * time they can be. A later method goes back to the list.
     *
     * @param array<string, mixed> $me
     */
    private function afterFirstMethod(array $me, string $done): never
    {
        if (Factors::backupCodesLeft((int) $me['id']) === 0) {
            $this->showCodes(Factors::newBackupCodes((int) $me['id']), true);
        }
        $this->back($done);
    }

    /** @param list<string> $codes */
    private function showCodes(array $codes, bool $first): never
    {
        $this->page('two-step-setup/codes.php', [
            'codes' => $codes,
            'first' => $first,
            'site'  => (string) Head::globalTokens()['site_name'],
        ]);
        exit;
    }

    /** Back to the signed-in account's own screen, to its two-step card. */
    private function back(string $done = ''): never
    {
        $this->backTo((int) Auth::user()['id'], $done);
    }

    /** Back to an account's screen, to its two-step card. */
    private function backTo(int $userId, string $done): never
    {
        header('Location: ' . ADMIN_URL . '/users/' . $userId . '/edit'
            . ($done !== '' ? '?done=' . rawurlencode($done) : '') . '#two-step', true, 303);
        exit;
    }

    /** Yourself, or anyone when you are an administrator. */
    private function mayManage(int $targetId): int
    {
        $me = (array) Auth::user();
        if ($me['role'] !== 'admin' && (int) $me['id'] !== $targetId) {
            header('Location: ' . ADMIN_URL . '/users/' . $me['id'] . '/edit');
            exit;
        }

        return $targetId;
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data, int $status = 200): void
    {
        http_response_code($status);
        Cache::noStore();
        extract($data, EXTR_SKIP);
        require ROOT_PATH . '/admin/templates/' . $template;
    }
}
