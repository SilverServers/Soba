<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Database\Database;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Mail\Mailer;
use Cms\Models\Option;
use Cms\Models\Post;
use Cms\Models\User;
use Cms\Security\ClientIp;
use Cms\Security\TwoStep;
use PDO;

/**
 * WHAT GOING TO PRODUCTION CHANGES, as System health's rows (`Site\Health`).
 *
 * Several things change when config.php's `app_env` becomes production
 * (`Site\Environment`): an account that owes a new password is asked for it
 * at its next sign-in (`Auth::mustChange()`), the two-step policy starts
 * stopping people (`TwoStep::enforces()`), Google's tags start sending
 * (`Site\Google`), errors stop showing, and a sample entry leaves the site
 * (`Post::SAMPLE`). On a copy that is not production, `rows()` says what will
 * happen, each a fact. In production it warns of what a live site should not
 * be left with: accounts, mail, backups, a proxy nobody named, old addresses,
 * Business details typed out, and sample entries.
 *
 * Every row is asked of the code that decides it, never worked out again
 * here: the policy of `TwoStep`, the transport of `Mailer`, the timetable of
 * `Backups`.
 */
final class GoingLive
{
    /** When a cron last ran `admin/bin/run-due.php`, which notes it. */
    public const CRON_KEY = 'cron_ran_at';

    /** A day, and the hour a timetable is allowed to run late. */
    private const DAY   = 86400;
    private const LEEWAY = 3600;

    /** How long a forwarded request is news. */
    private const PROXY_DAYS = 7;

    /** The section's heading, for this copy. */
    public static function title(): string
    {
        return Environment::isProduction() ? 'Going to production' : 'When this copy goes to production';
    }

    /**
     * The section's rows: what will change on a copy, what to see to in
     * production. Each row: label, value, status, hint, and the `items` it
     * names, each a line with the address of its screen.
     *
     * @return array<string, array{label: string, value: string, status: string, hint: string, items?: list<array{text: string, url: string}>}>
     */
    public static function rows(): array
    {
        return Environment::isProduction() ? self::inProduction() : self::onACopy();
    }

    // ----------------------------------------------------------------
    // On a copy
    // ----------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private static function onACopy(): array
    {
        $rows = [];

        $owed = self::owesPassword();
        $rows['passwords'] = self::named('Asked for a new password', $owed, 'info', 'None',
            'At its next sign-in, each is asked to choose one only its owner knows.');

        $twoStep = TwoStep::settings();
        if (TwoStep::ready() && ($twoStep['app'] || $twoStep['email']) && $twoStep['roles'] !== []) {
            $rows['two_step'] = self::named('Asked to set up two-step sign-in', self::withoutTwoStep(), 'info', 'None',
                TwoStep::policySummary() . ' (Security → Two-step sign-in): each is asked at its next sign-in.');
        } else {
            $rows['two_step'] = ['label' => 'Asked to set up two-step sign-in', 'value' => 'Nobody: ' . TwoStep::policySummary(),
                                 'status' => 'info', 'hint' => ''];
        }

        $tags = Google::entered();
        $rows['google'] = [
            'label'  => "Google's tags",
            'value'  => $tags === [] ? 'None entered' : 'Start sending: ' . implode(', ', array_map(
                static fn (string $name, string $id): string => $name . ' ' . $id, array_keys($tags), $tags)),
            'status' => 'info',
            'hint'   => $tags === [] ? '' : 'Settings → SEO → Google. Here they print inside a comment, unsent.',
        ];

        $rows['mail'] = ['label' => 'Mail', 'value' => 'Goes out through ' . Mailer::transportName(), 'status' => 'info', 'hint' => ''];

        return $rows + self::samples(true);
    }

    // ----------------------------------------------------------------
    // In production
    // ----------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private static function inProduction(): array
    {
        $rows = [];

        // ── ACCOUNTS ────────────────────────────────────────────────────
        $never = array_map(static fn (array $u): array => self::account($u), Database::getInstance()->query(
            "SELECT u.id, u.username, u.display_name FROM users u
              WHERE u.role = 'admin' AND u.last_seen_at IS NULL AND u.suspended_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM temporary_access t WHERE t.user_id = u.id)
              ORDER BY u.username"
        )->fetchAll(PDO::FETCH_ASSOC));
        $rows['never_signed_in'] = self::named('Administrators who never signed in', $never, 'warning', 'None',
            'An account nobody uses is a way in nobody watches: sign in with it, or remove it (Users).');

        $seeded = array_values(array_filter(self::owesPassword(), static fn (array $i): bool => in_array($i['why'], ['seed', 'admin'], true)));
        $rows['seeded'] = self::named('Accounts on a first or one-time password', $seeded, 'warning', 'None',
            'Each is asked for a new one at its next sign-in; until then, whoever has the password has the account.');

        if (TwoStep::enforces()) {
            $rows['two_step'] = self::named('Without two-step sign-in, though it is required', self::withoutTwoStep(), 'warning', 'None',
                TwoStep::policySummary() . ' (Security → Two-step sign-in).');
        }

        $temporary = array_map(static fn (array $t): array => [
            'text' => User::displayName($t) . ', until ' . self::when((int) strtotime((string) $t['expires_at'] . ' UTC')),
            'url'  => Url::admin('users/' . (int) $t['user_id'] . '/edit'),
        ], Database::getInstance()->query(
            'SELECT t.user_id, t.expires_at, u.username, u.display_name FROM temporary_access t JOIN users u ON u.id = t.user_id
              WHERE t.ended_at IS NULL AND t.expires_at > UTC_TIMESTAMP() AND t.uses < t.max_uses ORDER BY t.expires_at'
        )->fetchAll(PDO::FETCH_ASSOC));
        $rows['temporary'] = self::named('Temporary access still running', $temporary, 'warning', 'None',
            'End it on the account when the work it was for is done.');

        // ── MAIL ────────────────────────────────────────────────────────
        $transport = Mailer::transport();
        $rows['mail'] = match (true) {
            $transport === 'log' => ['label' => 'Mail', 'value' => 'Kept in ' . Mailer::transportName() . ': nothing is sent', 'status' => 'warning',
                                     'hint' => "config.php's mail_transport keeps every message in a file. Take it out to send them."],
            $transport === 'brevo' && !Mailer::brevoHasKey() => ['label' => 'Mail', 'value' => 'Brevo, with no key', 'status' => 'warning',
                                     'hint' => "config.php names Brevo and gives it no API key, so nothing can be sent."],
            default => ['label' => 'Mail', 'value' => 'Goes out through ' . Mailer::transportName(), 'status' => 'ok', 'hint' => ''],
        };
        $from = trim((string) Option::get('smtp_from_email', ''));
        $rows['from'] = $from !== ''
            ? ['label' => 'Sent from', 'value' => $from, 'status' => 'ok', 'hint' => '']
            : ['label' => 'Sent from', 'value' => Mailer::defaultFrom() !== '' ? Mailer::defaultFrom() . ', for want of one' : 'No address',
               'status' => 'warning', 'hint' => 'Settings → Email names no From address. A message from an address nobody chose is more often taken for spam.'];
        $last = Mailer::lastSent();
        $rows['last_mail'] = match (true) {
            $last === null  => ['label' => 'The last message', 'value' => 'None sent yet', 'status' => 'info',
                                'hint' => 'Settings → Email sends a test.'],
            $last['sent']   => ['label' => 'The last message', 'value' => 'Sent through ' . self::via($last['via']) . ', ' . self::when($last['at']),
                                'status' => 'ok', 'hint' => ''],
            default         => ['label' => 'The last message', 'value' => 'Not sent, ' . self::when($last['at']), 'status' => 'warning',
                                'hint' => $last['error'] !== '' ? ucfirst($last['error']) . '.' : ''],
        };

        // ── BACKUPS ─────────────────────────────────────────────────────
        $every  = Backups::every();
        $newest = Backups::all()[0] ?? null;
        $rows['backups'] = match (true) {
            $every === 'never' => ['label' => 'Backups', 'value' => 'Switched off', 'status' => 'warning', 'hint' => 'Tools → Backups.'],
            $newest === null   => ['label' => 'Backups', 'value' => 'None made yet', 'status' => 'warning', 'hint' => 'Tools → Backups makes one now.'],
            time() - $newest['finished'] > Backups::EVERY[$every] + self::LEEWAY
                               => ['label' => 'Backups', 'value' => 'The newest is from ' . self::when($newest['finished']), 'status' => 'warning',
                                   'hint' => 'One is due every ' . $every . '.'],
            default            => ['label' => 'Backups', 'value' => 'The newest is from ' . self::when($newest['finished']), 'status' => 'ok', 'hint' => ''],
        };
        $cron = (int) (Option::get(self::CRON_KEY, '0') ?? '0');
        $rows['cron'] = $cron > time() - self::DAY - self::LEEWAY
            ? ['label' => 'Cron', 'value' => 'Ran ' . self::when($cron), 'status' => 'ok', 'hint' => '']
            : ['label' => 'Cron', 'value' => $cron > 0 ? 'Last ran ' . self::when($cron) : 'Has not run', 'status' => 'warning',
               'hint' => 'No cron job has run php admin/bin/run-due.php in the last day. Without one, visits make the backups, so a site nobody visits goes without them.'];
        $rows['kept'] = self::kept($newest);

        // ── A PROXY NOBODY NAMED ────────────────────────────────────────
        $proxies = array_filter(ClientIp::unnamed(), static fn (array $r): bool => $r['at'] > time() - self::PROXY_DAYS * self::DAY);
        $rows['proxy'] = $proxies === []
            ? ['label' => 'A proxy', 'value' => 'None unnamed', 'status' => 'ok', 'hint' => '']
            : ['label' => 'A proxy', 'value' => 'Requests from ' . implode(', ', array_map(
                    static fn (string $ip, array $r): string => ClientIp::display($ip) . ' carry ' . $r['header'] . ' (' . self::when($r['at']) . ')',
                    array_keys($proxies), $proxies)),
               'status' => 'warning',
               'hint' => "config.php's trusted_proxies does not name it. Behind a proxy it does not name, every visitor has the proxy's address,"
                   . ' so the sign-in limit locks everyone out at once. Name it in trusted_proxies, with trusted_proxy_header for the header it writes.'];

        // ── OLD ADDRESSES ───────────────────────────────────────────────
        $old = OldAddresses::found();
        $rows['old_addresses'] = $old === []
            ? ['label' => 'Old addresses', 'value' => 'None named', 'status' => 'ok', 'hint' => '']
            : ['label' => 'Old addresses', 'value' => implode('; ', array_map(
                    static fn (string $address, array $where): string => $address . ' in ' . implode(', ', array_keys($where)),
                    array_keys($old), $old)),
               'status' => 'warning', 'hint' => 'Each link to it breaks once it is taken down. Replace makes each one ' . OldAddresses::current() . '.'];

        // ── BUSINESS DETAILS TYPED OUT ──────────────────────────────────
        $rows['typed'] = self::named('Business details typed out', TypedDetails::found(), 'warning', 'None',
            'A number or email typed out stays as it is when Business details changes it. Use its shortcode, such as [phone], or in a search title its variable, such as {phone}.');

        return $rows + self::samples(false);
    }

    /**
     * Where the backups are kept: on this server only, or here and in the
     * storage config.php names (`BackupCopy`), as the newest backup's copy
     * went.
     *
     * @param array<string, mixed>|null $newest
     * @return array<string, mixed>
     */
    private static function kept(?array $newest): array
    {
        $label = 'Where backups are kept';
        if (BackupCopy::config() === null || BackupCopy::problem() !== null) {
            return ['label' => $label, 'value' => 'Only on this server', 'status' => 'warning',
                    'hint' => BackupCopy::problem() !== null ? (string) BackupCopy::problem() . '.'
                        : "A copy kept somewhere else is the one that survives the server: config.php's backup_copy sends one of each to S3-compatible storage."];
        }
        $to   = BackupCopy::label();
        $copy = is_array($newest['copy'] ?? null) ? $newest['copy'] : null;

        return match ((string) ($copy['state'] ?? '')) {
            'copied'           => ['label' => $label, 'value' => 'Here and in ' . $to, 'status' => 'ok',
                                   'hint' => (string) ($copy['note'] ?? '') !== '' ? ucfirst((string) $copy['note']) . '.' : ''],
            'failed'           => ['label' => $label, 'value' => 'The newest is only here: its copy to ' . $to . ' did not go', 'status' => 'warning',
                                   'hint' => ucfirst((string) ($copy['error'] ?? '')) . '.'],
            'waiting', 'sending' => ['label' => $label, 'value' => 'Here, and being copied to ' . $to, 'status' => 'info', 'hint' => ''],
            default            => ['label' => $label, 'value' => 'Here; copies go to ' . $to . ' from the next backup', 'status' => 'info', 'hint' => ''],
        };
    }

    // ----------------------------------------------------------------
    // Both
    // ----------------------------------------------------------------

    /**
     * Sample entries (`Post::SAMPLE`), listed under the section by System
     * health with their own button.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function samples(bool $shown): array
    {
        $samples = Post::samples();

        return ['samples' => [
            'label'  => 'Sample entries',
            'value'  => $samples === [] ? 'None' : count($samples) . ($shown ? ', shown on this copy' : ' left'),
            'status' => $samples === [] ? 'ok' : ($shown ? 'info' : 'warning'),
            'hint'   => $samples === [] ? '' : ($shown
                ? 'Never shown in production, where this screen lists any left.'
                : 'Never shown in production. Publish any to keep, and move the rest to the Trash below.'),
        ]];
    }

    /**
     * Every account that owes a new password, with why: 'seed', 'admin', 'all' or 'weak' (`Auth::mustChange()`).
     *
     * @return list<array{text: string, url: string, why: string}>
     */
    private static function owesPassword(): array
    {
        $why = ['seed' => 'the password the site was installed with', 'admin' => 'a password somebody else set',
                'all' => 'everyone was asked', 'weak' => 'below the password rule'];

        return array_map(static fn (array $u): array => [
            'text' => User::displayName($u) . ' (' . ($why[(string) $u['must_change_password']] ?? 'asked') . ')',
            'url'  => Url::admin('users/' . (int) $u['id'] . '/edit'),
            'why'  => (string) $u['must_change_password'],
        ], Database::getInstance()->query(
            "SELECT id, username, display_name, must_change_password FROM users
              WHERE must_change_password IS NOT NULL AND must_change_password <> '' AND suspended_at IS NULL ORDER BY username"
        )->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Every account the two-step policy requires it of (`TwoStep::requiredFor()`)
     * that has none set up.
     *
     * @return list<array{text: string, url: string}>
     */
    private static function withoutTwoStep(): array
    {
        $out = [];
        foreach (Database::getInstance()->query(
            'SELECT u.id, u.username, u.display_name, u.role FROM users u
              WHERE u.suspended_at IS NULL AND NOT EXISTS (SELECT 1 FROM user_factors f WHERE f.user_id = u.id) ORDER BY u.username'
        )->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (TwoStep::requiredFor($u)) {
                $out[] = self::account($u);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $u  @return array{text: string, url: string} */
    private static function account(array $u): array
    {
        return ['text' => User::displayName($u), 'url' => Url::admin('users/' . (int) $u['id'] . '/edit')];
    }

    /**
     * A row that names things: how many, and each, or `$none` with nothing
     * to see to.
     *
     * @param list<array{text: string, url: string}> $items
     * @return array<string, mixed>
     */
    private static function named(string $label, array $items, string $status, string $none, string $hint): array
    {
        if ($items === []) {
            return ['label' => $label, 'value' => $none, 'status' => 'ok', 'hint' => ''];
        }

        return ['label' => $label, 'value' => (string) count($items), 'status' => $status, 'hint' => $hint,
                'items' => array_map(static fn (array $i): array => ['text' => $i['text'], 'url' => $i['url']], $items)];
    }

    /** A transport's name for a sentence. */
    private static function via(string $via): string
    {
        return match ($via) {
            'brevo' => 'Brevo',
            'smtp'  => 'the SMTP server',
            'log'   => 'the mail log',
            default => "the server's own mail program",
        };
    }

    /** A moment, in the site's time. */
    private static function when(int $at): string
    {
        return Dates::stamp($at);
    }
}
