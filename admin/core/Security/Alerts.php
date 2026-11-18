<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Auth\Auth;
use Cms\Database\Database;
use Cms\Helpers\Dates;
use Cms\Mail\Mailer;
use Cms\Models\Option;
use Cms\SEO\Head;
use Cms\Update\Release;
use PDO;

/**
 * TELLING SOMEONE — Activity log › Notifications.
 *
 * ── THE CHANNELS ────────────────────────────────────────────────────────────
 * Slack (an incoming-webhook address), a Webhook (any address, sent the JSON
 * `payload()` builds), and Email: to every administrator, or to the addresses
 * typed. Each has its own switch and its own events.
 *
 * ── THE LOG IS THE QUEUE ────────────────────────────────────────────────────
 * Every event is already a `security_log` row. A channel keeps a cursor — the
 * last row it reported — and a quiet window. When the window has passed, ONE
 * message reports every row of its events since the cursor, and the cursor
 * moves on. Five hundred failed sign-ins in a burst are one message, not five
 * hundred — a digest, by construction — and nobody can make this site post
 * five hundred times by asking.
 *
 * ── NEVER WHILE SOMEONE WAITS ───────────────────────────────────────────────
 * A post made while the sign-in page waits holds the page for as long as the
 * far end takes. Here every request asks, at shutdown, whether anything is
 * due — one read of these settings, and the log's last id only when a
 * channel's window has passed. That is how the tail of a burst goes out once
 * its window ends, with no cron: on the next visit, whoever's. Only a request
 * with something to send hands its page back first (`fastcgi_finish_request()`,
 * the session written before it) and then sends, five seconds at most per
 * channel, one flush at a time (a lock).
 *
 * ── EMAIL ON A DEVELOPMENT TREE ─────────────────────────────────────────────
 * Recorded, never sent: email leaves only a production install. A development
 * tree is signed into by script all day. Slack and the webhook are addresses
 * an owner typed in, so they send everywhere.
 *
 * ── AND A DELIVERY IS SHOWN, NOT ASSUMED ────────────────────────────────────
 * Each channel keeps its last result — the HTTP status, or whether the mail was
 * handed over — for its card on the Notifications tab. A broken address shows
 * as broken instead of as silence. A failed delivery still moves the cursor:
 * the Activity log holds every event, and a dead endpoint is not hammered.
 */
final class Alerts
{
    public const CHANNELS = ['email' => 'Email', 'slack' => 'Slack', 'webhook' => 'Webhook'];

    /** event key => [what the switch says, the log rows it is]. */
    public const EVENTS = [
        'lockout'       => 'An address is locked out',
        'name_locked'   => 'A username is locked',
        'failed'        => 'Failed sign-ins',
        'enumeration'   => 'Someone looks for usernames',
        'admin_sign_in' => 'An administrator signs in',
        // The CMS's own files differ from what shipped (`FileCheck`).
        'files'         => 'The core files change',
    ];

    /** The defaults: lockouts on — and a locked name is a lockout — as are core file changes; the rest off. */
    public const DEFAULT_EVENTS = ['lockout', 'name_locked', 'files'];

    public const QUIET_MINUTES = 10;

    /** How long one post may take — after the page has gone, but a worker is still a worker. */
    public const TIMEOUT_SECONDS = 5;

    /** Mail an account that signs in from a browser and address it has not used. On by default. */
    public const NEW_DEVICE_KEY = 'security_alerts_new_device';

    /** How many rows a message lists by name; the rest are counted. */
    public const LISTED = 8;

    private static bool $flushQueued = false;

    /** @var list<array{0: string, 1: string, 2: string}> mails waiting for the flush: [to, subject, body] */
    private static array $mailLater = [];

    /** @var (callable(string, string, string, array<string, string>): string)|null  in the network's place, when set (`useWire()`) */
    private static $wire = null;

    // ── Settings ────────────────────────────────────────────────────────────

    /** The option key for a channel's setting or state. */
    public static function key(string $channel, string $what): string
    {
        return 'security_alerts_' . $channel . '_' . $what;
    }

    /**
     * Every channel's settings and state, read in one query.
     *
     * `sent_at` is the last real message — the quiet window counts from it.
     * `tried_at` is the last attempt of any kind, tests included — the card
     * shows it beside `result`.
     *
     * @return array<string, array{on: bool, url: string, secret: string, to: string, events: list<string>, cursor: int, sent_at: string, tried_at: string, result: string}>
     */
    public static function settings(): array
    {
        $defaults = [self::NEW_DEVICE_KEY => '1'];
        foreach (array_keys(self::CHANNELS) as $ch) {
            $defaults += [
                self::key($ch, 'on')      => $ch === 'email' ? '1' : '0',
                self::key($ch, 'url')     => '',
                self::key($ch, 'secret')  => '',
                self::key($ch, 'to')      => '',
                self::key($ch, 'events')  => implode(',', self::DEFAULT_EVENTS),
                self::key($ch, 'cursor')  => '0',
                self::key($ch, 'sent_at')  => '',
                self::key($ch, 'tried_at') => '',
                self::key($ch, 'result')   => '',
            ];
        }
        $raw = Option::getMany($defaults);

        $out = [];
        foreach (array_keys(self::CHANNELS) as $ch) {
            $out[$ch] = [
                'on'      => ($raw[self::key($ch, 'on')] ?? '0') === '1',
                'url'     => (string) ($raw[self::key($ch, 'url')] ?? ''),
                'secret'  => (string) ($raw[self::key($ch, 'secret')] ?? ''),
                'to'      => (string) ($raw[self::key($ch, 'to')] ?? ''),
                'events'  => self::eventList((string) ($raw[self::key($ch, 'events')] ?? '')),
                'cursor'  => (int) ($raw[self::key($ch, 'cursor')] ?? 0),
                'sent_at'  => (string) ($raw[self::key($ch, 'sent_at')] ?? ''),
                'tried_at' => (string) ($raw[self::key($ch, 'tried_at')] ?? ''),
                'result'   => (string) ($raw[self::key($ch, 'result')] ?? ''),
            ];
        }

        return $out;
    }

    /** @return list<string> the known event keys in a stored list, in EVENTS order */
    public static function eventList(string $stored): array
    {
        $picked = array_map('trim', explode(',', $stored));

        return array_values(array_filter(array_keys(self::EVENTS), static fn (string $e): bool => in_array($e, $picked, true)));
    }

    public static function newDeviceMail(): bool
    {
        return Option::get(self::NEW_DEVICE_KEY, '1') === '1';
    }

    /** Email leaves this site only on a production install. */
    public static function emailSends(): bool
    {
        return Auth::enforcesPasswordChanges();
    }

    /**
     * WHAT IS WRONG WITH A CHANNEL ADDRESS, or null. HTTPS only: what crosses the
     * internet here names accounts and addresses. The one exception is a host
     * the SERVER names in config.php (`alerts_insecure_hosts`) — a test catcher
     * on 127.0.0.1, say — never one typed into the admin.
     */
    public static function urlProblem(string $url, ?array $insecureHosts = null): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return 'An address is needed.';
        }
        if (strlen($url) > 2000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'That is not a web address.';
        }
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return 'That is not a web address.';
        }
        if ($scheme === 'https') {
            return null;
        }
        $allowed = $insecureHosts ?? self::insecureHosts();
        if ($scheme === 'http' && in_array($host, $allowed, true)) {
            return null;
        }

        return 'Use an https:// address: these messages name accounts and addresses.';
    }

    /**
     * config.php's `alerts_insecure_hosts`, read the way `ClientIp` reads its
     * proxies — the server's word, never the admin's.
     *
     * @return list<string>
     */
    private static function insecureHosts(): array
    {
        $config = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
        $hosts  = is_array($config) ? ($config['alerts_insecure_hosts'] ?? []) : [];

        return array_values(array_filter(array_map(static fn ($h): string => strtolower(trim((string) $h)), (array) $hosts), 'strlen'));
    }

    // ── Triggers ────────────────────────────────────────────────────────────

    /**
     * Something happened, or an administrator is looking: flush at shutdown, at
     * most once a request. Costs nothing now.
     */
    public static function poke(): void
    {
        // The command line records events too (`security.php`), and has no
        // page to hand back; the next request reports them.
        if (self::$flushQueued || PHP_SAPI === 'cli') {
            return;
        }
        self::$flushQueued = true;
        register_shutdown_function([self::class, 'flush']);
    }

    /**
     * Send what is due — after handing the page back. Never throws: a broken
     * channel must not become a broken page.
     */
    public static function flush(): void
    {
        try {
            if (self::$mailLater === [] && self::due() === []) {
                return;                         // the common case: two cheap reads, nothing held up
            }
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();      // the next click must see this one's session
                }
                fastcgi_finish_request();
            }
            self::sendLaterMail();
            self::deliverDue();
        } catch (\Throwable $e) {
            error_log('CMS alerts: ' . $e->getMessage());
        }
    }

    /**
     * The channels with something to send: switched on, past their window, and
     * the log has grown past their cursor. One settings read; the log's last id
     * only when some channel's window has passed.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function due(): array
    {
        $ready = [];
        foreach (self::settings() as $ch => $s) {
            if (!$s['on'] || $s['events'] === [] || ($ch === 'email' && !self::emailSends())) {
                continue;
            }
            if ($s['sent_at'] !== '' && strtotime($s['sent_at'] . ' UTC') > time() - self::QUIET_MINUTES * 60) {
                continue;
            }
            $ready[$ch] = $s;
        }
        if ($ready === []) {
            return [];
        }

        $last = (int) Database::getInstance()->query('SELECT COALESCE(MAX(id), 0) FROM security_log')->fetchColumn();

        return array_filter($ready, static fn (array $s): bool => $last > (int) $s['cursor']);
    }

    /**
     * Every channel whose window has passed and which has rows of its events
     * after its cursor gets one message. Returns what was sent, for the checks.
     *
     * @return array<string, string>  channel => result
     */
    public static function deliverDue(): array
    {
        $pdo  = Database::getInstance();
        $lock = 'cms_alerts_' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            return [];
        }

        try {
            $sent = [];
            // Pinned: rows logged while this runs belong to the next message.
            $last = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM security_log')->fetchColumn();
            // Asked again INSIDE the lock: another worker may have just sent.
            foreach (self::due() as $ch => $s) {
                $counts = self::counts((int) $s['cursor'], $last, $s['events']);
                if ($counts !== []) {
                    $rows       = self::rows((int) $s['cursor'], $last, $s['events']);
                    $sent[$ch]  = self::deliver($ch, $s, $rows, false, $counts);
                }
                // The cursor moves whether or not anything of this channel's
                // was there: rows it does not send are not news to it, and a
                // cursor left behind would make every request ask again.
                Option::setMany($counts === []
                    ? [self::key($ch, 'cursor') => (string) $last]
                    : [
                        self::key($ch, 'cursor')   => (string) $last,
                        self::key($ch, 'sent_at')  => gmdate('Y-m-d H:i:s'),
                        self::key($ch, 'tried_at') => gmdate('Y-m-d H:i:s'),
                        self::key($ch, 'result')   => $sent[$ch],
                    ]);
            }

            return $sent;
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
        }
    }

    /** The SQL that picks one event's rows — `u` is the account, for "an administrator". */
    private const WHERE = [
        'lockout'       => "l.event = 'lockout'",
        'name_locked'   => "l.event = 'account_locked'",
        'failed'        => "l.event = 'sign_in_failed'",
        'enumeration'   => "l.event = 'enumeration'",
        'admin_sign_in' => "(l.event = 'sign_in' AND u.role = 'admin')",
        'files'         => "l.event = 'files_changed'",
    ];

    private const KIND = "CASE l.event WHEN 'lockout' THEN 'lockout' WHEN 'account_locked' THEN 'name_locked'
                         WHEN 'sign_in_failed' THEN 'failed' WHEN 'enumeration' THEN 'enumeration'
                         WHEN 'files_changed' THEN 'files' ELSE 'admin_sign_in' END";

    /** @param list<string> $events */
    private static function where(array $events): string
    {
        return '(' . implode(' OR ', array_values(array_intersect_key(self::WHERE, array_flip($events)))) . ')';
    }

    /**
     * How many of each event between two log ids, counted by the database —
     * a burst of fifty thousand is counted, not loaded.
     *
     * @param list<string> $events
     * @return array<string, int>  in EVENTS order; empty when there were none
     */
    public static function counts(int $after, int $upTo, array $events): array
    {
        if (array_intersect(array_keys(self::WHERE), $events) === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT ' . self::KIND . ' AS kind, COUNT(*) AS n
               FROM security_log l LEFT JOIN users u ON u.id = l.user_id
              WHERE l.id > :after AND l.id <= :upto AND ' . self::where($events) . '
              GROUP BY kind'
        );
        $stmt->execute([':after' => $after, ':upto' => $upTo]);
        $found = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $counts = [];
        foreach (array_keys(self::EVENTS) as $kind) {
            if (isset($found[$kind]) && (int) $found[$kind] > 0) {
                $counts[$kind] = (int) $found[$kind];
            }
        }

        return $counts;
    }

    /**
     * The LATEST rows of `$events` between two log ids, oldest first, each with
     * its event key — the few a message names, and the first one's time.
     *
     * @param list<string> $events
     * @return list<array<string, mixed>>
     */
    public static function rows(int $after, int $upTo, array $events, int $cap = 50): array
    {
        if (array_intersect(array_keys(self::WHERE), $events) === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT l.id, l.event, l.username, l.ip, l.user_agent, l.detail, l.created_at, ' . self::KIND . ' AS kind
               FROM security_log l LEFT JOIN users u ON u.id = l.user_id
              WHERE l.id > :after AND l.id <= :upto AND ' . self::where($events) . '
              ORDER BY l.id DESC LIMIT ' . (int) $cap
        );
        $stmt->execute([':after' => $after, ':upto' => $upTo]);

        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── Messages ────────────────────────────────────────────────────────────

    /**
     * One message: a headline, a count per kind, the latest few by name.
     * Pure. `$counts` are the totals
     * (`counts()`); without them, the rows are counted.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, int>|null    $counts
     * @return array{subject: string, text: string, counts: array<string, int>}
     */
    public static function compose(array $rows, string $site, bool $test = false, ?array $counts = null): array
    {
        if ($test) {
            return [
                'subject' => 'A test from ' . $site,
                'text'    => 'A test from the Notifications tab of ' . $site . '. When something happens at its sign-in page, it will be reported here.',
                'counts'  => [],
            ];
        }

        if ($counts === null) {
            $counts = [];
            foreach ($rows as $row) {
                $counts[(string) $row['kind']] = ($counts[(string) $row['kind']] ?? 0) + 1;
            }
        }
        $total = array_sum($counts);

        $said = [
            'lockout'       => static fn (int $n): string => $n === 1 ? '1 address locked out' : $n . ' addresses locked out',
            'name_locked'   => static fn (int $n): string => $n === 1 ? '1 username locked' : $n . ' usernames locked',
            'failed'        => static fn (int $n): string => $n === 1 ? '1 failed sign-in' : $n . ' failed sign-ins',
            'enumeration'   => static fn (int $n): string => $n === 1 ? '1 address looked for usernames' : $n . ' addresses looked for usernames',
            'admin_sign_in' => static fn (int $n): string => $n === 1 ? '1 administrator sign-in' : $n . ' administrator sign-ins',
            'files'         => static fn (int $n): string => $n === 1 ? 'The core files changed' : "The core files changed, {$n} times",
        ];
        $lines = [];
        foreach (array_keys(self::EVENTS) as $kind) {
            if (isset($counts[$kind])) {
                $lines[] = $said[$kind]($counts[$kind]);
            }
        }

        $subject = $total === 1
            ? ucfirst($lines[0]) . ' on ' . $site
            : $total . ' security events on ' . $site;

        $latest = [];
        foreach (array_slice(array_reverse($rows), 0, self::LISTED) as $row) {
            [, $label] = SecurityLog::describe($row);
            $latest[] = (string) ($row['event'] ?? '') === SecurityLog::FILES_CHANGED
                // The site's own finding: what changed, not who asked.
                ? sprintf('%s  %s  %s', Dates::stamp((string) $row['created_at']), $label, (string) ($row['detail'] ?? ''))
                : sprintf('%s  %s  %s%s',
                    Dates::stamp((string) $row['created_at']),
                    $label,
                    trim((string) ($row['username'] ?? '')) !== '' ? (string) $row['username'] . ' · ' : '',
                    ClientIp::display((string) $row['ip']));
        }

        // Said where it happened: the sign-in page, or — once files are
        // among it — the site.
        $text = (isset($counts['files']) ? 'On ' : 'At the sign-in page of ') . $site . ":\n\n"
            . implode("\n", array_map(static fn (string $l): string => '  ' . $l, $lines))
            . "\n\nThe latest:\n\n"
            . implode("\n", array_map(static fn (string $l): string => '  ' . $l, $latest))
            . ($total > self::LISTED ? "\n  … and " . ($total - self::LISTED) . ' more' : '')
            // Where to look: the Activity log has the door's events; the core
            // files are listed on Security → Files, not in the log's tabs.
            . "\n\n" . implode("\n", array_filter([
                array_diff(array_keys($counts), ['files']) !== []
                    ? 'Everything is in the Activity log: ' . rtrim(\Cms\Helpers\Url::admin('security/activity'), '/') : null,
                isset($counts['files'])
                    ? 'Every file is listed on Security → Files: ' . rtrim(\Cms\Helpers\Url::admin('security/files'), '/') : null,
            ])) . "\n";

        return ['subject' => $subject, 'text' => $text, 'counts' => $counts];
    }

    /**
     * The webhook body — `source, version, event, time, site, context` — with
     * the events themselves in `context`. `event` is the kind when there is one
     * kind, `digest` when there are several.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, int>         $counts
     */
    public static function payload(array $rows, array $counts, bool $test = false): string
    {
        $events = [];
        foreach (array_slice($rows, -50) as $row) {
            $events[] = [
                'id'       => (int) $row['id'],
                'event'    => (string) $row['kind'],
                'time'     => gmdate('c', (int) strtotime((string) $row['created_at'] . ' UTC')),
                'username' => $row['username'] !== null ? (string) $row['username'] : null,
                'ip'       => ClientIp::display((string) $row['ip']),
                'detail'   => $row['detail'] !== null ? (string) $row['detail'] : null,
            ];
        }

        return (string) json_encode([
            'source'  => 'security-notifications',
            'version' => Release::version(),
            'event'   => $test ? 'test' : (count($counts) === 1 ? (string) array_key_first($counts) : 'digest'),
            'time'    => gmdate('c'),
            'site'    => ['name' => (string) Head::globalTokens()['site_name'], 'url' => rtrim(\Cms\Helpers\Url::public(), '/')],
            'context' => ['counts' => (object) $counts, 'events' => $events, 'more' => max(0, array_sum($counts) - count($events))],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** `sha256=<hex>`: the body signed with the webhook's secret, for the receiver to check. */
    public static function signature(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /** Slack's own escaping: &, < and > are its markup. */
    public static function slackText(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    // ── Delivery ────────────────────────────────────────────────────────────

    /** Send a test on one channel, now, and keep its result. */
    public static function sendTest(string $channel): string
    {
        $s = self::settings()[$channel] ?? null;
        if ($s === null) {
            return 'No such channel.';
        }
        $result = self::deliver($channel, $s, [], true);
        Option::setMany([
            self::key($channel, 'tried_at') => gmdate('Y-m-d H:i:s'),
            self::key($channel, 'result')   => 'A test: ' . $result,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed>       $s
     * @param list<array<string, mixed>> $rows
     * @param array<string, int>|null    $counts
     */
    private static function deliver(string $channel, array $s, array $rows, bool $test, ?array $counts = null): string
    {
        $site    = (string) Head::globalTokens()['site_name'];
        $message = self::compose($rows, $site, $test, $counts);

        if ($channel === 'email') {
            if (!self::emailSends()) {
                return 'Not sent: a development install sends no email.';
            }
            $to = self::recipients((string) $s['to']);
            if ($to === []) {
                return 'Not sent: no administrator has an email address.';
            }

            return Mailer::send($to, '[' . $site . '] ' . $message['subject'], $message['text'])
                ? 'Handed to ' . match (Mailer::transport()) {
                    'log'   => 'the mail log (admin/logs/' . \Cms\Mail\MailLog::FILE . ')',
                    'brevo' => 'Brevo',
                    'smtp'  => trim((string) Option::get('smtp_host', '')),
                    default => "this host's own mail",
                } . ' for ' . count($to) . (count($to) === 1 ? ' address' : ' addresses')
                : 'The mail could not be sent (Settings → Email).';
        }

        if (self::urlProblem((string) $s['url']) !== null) {
            return 'Not sent: ' . lcfirst((string) self::urlProblem((string) $s['url']));
        }

        if ($channel === 'slack') {
            $body = (string) json_encode(['text' => self::slackText($message['subject'] . "\n\n" . $message['text'])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            return self::post((string) $s['url'], $body, []);
        }

        $body    = self::payload($rows, $message['counts'], $test);
        $headers = (string) $s['secret'] !== '' ? ['X-Webhook-Signature' => self::signature($body, (string) $s['secret'])] : [];

        return self::post((string) $s['url'], $body, $headers);
    }

    /**
     * Every administrator's address — or the typed list, when there is one.
     *
     * @return list<string>
     */
    public static function recipients(string $typed): array
    {
        $typed = trim($typed);
        if ($typed !== '') {
            return array_values(array_unique(array_filter(
                array_map('trim', preg_split('/[\s,;]+/', $typed) ?: []),
                static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false
            )));
        }

        return array_values(array_unique(array_filter(array_map('trim', Database::getInstance()
            ->query("SELECT email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email <> '' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN)),
            static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false)));
    }

    /**
     * One POST of JSON, five seconds at most, no redirects followed. The result
     * is what the card shows: `200 OK`, `HTTP 404`, or why it never connected.
     *
     * @param array<string, string> $headers
     */
    private static function post(string $url, string $body, array $headers): string
    {
        if (self::$wire !== null) {
            return (self::$wire)('POST', $url, $body, $headers);
        }

        $head = "Content-Type: application/json; charset=utf-8\r\nUser-Agent: Security-Notifications/" . Release::version() . "\r\n";
        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => $head,
                'content'         => $body,
                'timeout'         => self::TIMEOUT_SECONDS,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        // PHP 8.4 added http_get_last_response_headers(), and 8.5 deprecates
        // reading $http_response_header, which is all 8.2 and 8.3 have.
        $lines    = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status   = isset($lines[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $lines[0], $m) === 1 ? (int) $m[1] : 0;
        if ($status === 0) {
            $err = error_get_last();
            return 'Could not connect' . ($err !== null ? ': ' . mb_substr(preg_replace('/^file_get_contents\([^)]*\): /', '', (string) $err['message']) ?? '', 0, 120) : '');
        }

        return $status >= 200 && $status < 300 ? $status . ' OK' : 'HTTP ' . $status;
    }

    /** Put something in the network's place — a recorder, say. Null puts the network back. */
    public static function useWire(?callable $wire): void
    {
        self::$wire = $wire;
    }

    // ── A new browser, a new place ──────────────────────────────────────────

    /**
     * Has this account signed in before — but never from this address with this
     * browser? Asked at a sign-in, BEFORE it is logged. An account's first ever
     * sign-in is not "new": there is nothing to compare it with.
     */
    public static function isNewDevice(int $userId, string $key, ?string $agent): bool
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT ip, user_agent FROM security_log WHERE event = 'sign_in' AND user_id = :id ORDER BY id DESC LIMIT 200"
        );
        $stmt->execute([':id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return false;
        }

        $browser = SecurityLog::browser($agent);
        $prefix  = rtrim(ClientIp::likePattern($key), '%');
        foreach ($rows as $row) {
            $sameAddress = str_ends_with($key, '::/64')
                ? str_starts_with((string) $row['ip'], $prefix)
                : (string) $row['ip'] === $key;
            if ($sameAddress && SecurityLog::browser($row['user_agent'] ?? null) === $browser) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mail the account about this sign-in — after the page has gone. Only on a
     * production install, only with the switch on, only to an address
     * the account has.
     *
     * @param array<string, mixed> $user
     */
    public static function mailNewDevice(array $user, string $ip, ?string $agent): bool
    {
        if (!self::newDeviceMail() || !self::emailSends()) {
            return false;
        }
        $account = \Cms\Models\User::find((int) $user['id']);
        $to      = trim((string) ($account['email'] ?? ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $site = (string) Head::globalTokens()['site_name'];
        self::$mailLater[] = [
            $to,
            'New sign-in to your account on ' . $site,
            "Your account \"{$account['username']}\" on {$site} was just signed in to from somewhere it has not been before:\n\n"
            . '  ' . SecurityLog::browser($agent) . ' · ' . ClientIp::display($ip) . ' · ' . Dates::stamp(gmdate('Y-m-d H:i:s')) . "\n\n"
            . "If that was you, there is nothing to do.\n\n"
            . "If it was not, choose a new password now. It signs every other browser out:\n"
            . LoginUrl::url() . "?action=lostpassword\n",
        ];
        self::poke();

        return true;
    }

    private static function sendLaterMail(): void
    {
        foreach (self::$mailLater as [$to, $subject, $body]) {
            Mailer::send([$to], $subject, $body);
        }
        self::$mailLater = [];
    }
}
