<?php

declare(strict_types=1);

/**
 * The way back in, from the command line — for whoever holds the server.
 *
 *   php admin/bin/security.php [status]        the sign-in address, the attempt
 *                                              limit, and who is locked out
 *   php admin/bin/security.php login-url       print the sign-in address
 *   php admin/bin/security.php login-url off   put the sign-in page back at
 *                                              /admin/login, its address
 *                                              forgotten
 *   php admin/bin/security.php unlock <addr>   lift the lockouts on one address
 *   php admin/bin/security.php unlock <name>   let a locked name in from anywhere
 *   php admin/bin/security.php unlock all      lift every lockout in force
 *   php admin/bin/security.php addresses off   stop turning any address away
 *                                              (Security → Blocking → Addresses)
 *   php admin/bin/security.php two-step off <user>
 *                                              an account's two-step sign-in
 *                                              off: a lost phone, no backup codes
 *   php admin/bin/security.php restore <user>  let a suspended account sign in
 *                                              again (an administrator suspended
 *                                              by another, or by mistake)
 *   php admin/bin/security.php suspend <user>  shut an account out until it is
 *                                              restored
 *   php admin/bin/security.php password <user>
 *                                              a new password, printed once, for
 *                                              an account whose owner cannot sign
 *                                              in; they choose their own at sign-in
 *   php admin/bin/security.php files           the CMS's files against what
 *                                              their release shipped; exit 3
 *                                              when any differ
 *
 *   --config=<path>   a config.php other than admin/config/config.php
 *
 * Exit status: 0 done, 1 the database could not be reached, 2 bad usage,
 * 3 files differ (`files`).
 *
 * ── WHY THIS SHIPS ──────────────────────────────────────────────────────────
 * Moving the sign-in page (`Security\LoginUrl`) invites exactly one disaster:
 * nobody remembers where it went. This script is the way back in, and it asks
 * for the one thing an attacker at the sign-in page does not have, which is the
 * server. The third operator script, beside migrate.php and update.php
 * (`admin/core/Update/ownership.php`).
 *
 * Lifts are logged like one made from the Activity log, with no account
 * against them: the server did it, not a person signed in to the admin.
 */

use Cms\Auth\Auth;
use Cms\Database\Database;
use Cms\Models\Option;
use Cms\Models\User;
use Cms\Security\AccountLock;
use Cms\Security\Accounts;
use Cms\Security\AddressRules;
use Cms\Security\ClientIp;
use Cms\Security\Factors;
use Cms\Security\FileCheck;
use Cms\Security\LoginUrl;
use Cms\Security\SecurityLog;
use Cms\Security\Throttle;
use Cms\Security\TrustedBrowsers;
use Cms\Security\TwoStep;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$admin = dirname(__DIR__);
require $admin . '/vendor/autoload.php';

$usage  = "Usage: php admin/bin/security.php [status | login-url [off] | unlock <address>|<name>|all | addresses off | two-step off <user> | suspend|restore <user> | password <user> | files] [--config=<path>]\n";
$config = $admin . '/config/config.php';
$args   = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $config = substr($arg, strlen('--config='));
    } else {
        $args[] = $arg;
    }
}
$command = $args[0] ?? 'status';

if (!is_file($config)) {
    fwrite(STDERR, "No config at {$config}.\n");
    exit(1);
}
$settings = require $config;

// What LoginUrl needs to build an address: the site's own, from its config.
define('ROOT_PATH', $admin);
define('BASE_URL', rtrim((string) ($settings['base_url'] ?? ''), '/'));
define('ADMIN_URL_SLUG', 'admin');
define('ADMIN_URL', BASE_URL . '/' . ADMIN_URL_SLUG);

try {
    Database::init($settings);
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    fwrite(STDERR, 'Database: ' . $e->getMessage() . "\n");
    exit(1);
}

switch ($command) {
    case 'status':
        echo 'Sign-in page   ' . LoginUrl::url() . (LoginUrl::enabled() ? '   (moved)' : '') . "\n";

        $s = Throttle::settings();
        echo 'Attempt limit  ' . ($s['enabled']
            ? sprintf('%d attempts, then %s; %s',
                $s['attempts'], Throttle::duration($s['minutes']),
                $s['escalate_after'] > 0
                    ? Throttle::duration($s['escalate_hours'] * 60) . ' after ' . $s['escalate_after'] . ' lockouts in a day'
                    : 'no longer lockout')
            : 'off') . "\n";
        $account = AccountLock::settings();
        echo 'Per username   ' . ($account['enabled']
            ? sprintf('%d attempts from anywhere, then %s', $account['attempts'], Throttle::duration($account['minutes']))
            : 'off') . "\n";

        $active = $pdo->query(
            'SELECT ip, username, attempts, refused, until_at FROM security_lockouts
              WHERE lifted_at IS NULL AND until_at > UTC_TIMESTAMP() ORDER BY until_at'
        )->fetchAll(PDO::FETCH_ASSOC);
        $adoption = TwoStep::adoption();
        echo 'Two-step       ' . TwoStep::policySummary() . '; ' . $adoption['on'] . ' of ' . $adoption['total'] . " accounts use it\n";

        $rules = AddressRules::settings();
        echo 'Addresses      ' . match ($rules['mode']) {
            'block' => count($rules['list']) . ' turned away',
            'allow' => 'only ' . count($rules['list']) . ' let in',
            default => 'off',
        } . "\n";

        Accounts::sweep();
        $inactive  = Accounts::settings();
        $suspended = Accounts::suspended();
        echo 'Inactive       ' . ($inactive['enabled'] && $inactive['roles'] !== []
            ? strtolower(implode(' and ', array_intersect_key(Accounts::ROLES, array_flip($inactive['roles'])))) . ' suspended after ' . $inactive['days'] . ' days unused'
            : 'off') . "\n";
        echo 'Suspended      ' . ($suspended === [] ? 'nobody' : count($suspended)) . "\n";
        foreach ($suspended as $row) {
            printf("               %-42s since %s UTC  (%s)\n", $row['username'], $row['suspended_at'], lcfirst(Accounts::why($row)));
        }

        echo 'Locked out     ' . ($active === [] ? 'nobody' : count($active)) . "\n";
        foreach ($active as $row) {
            printf("               %-42s until %s UTC  (%d attempts, %d more refused)\n",
                $row['username'] !== null ? 'the name ' . $row['username'] : ClientIp::display((string) $row['ip']),
                $row['until_at'], $row['attempts'], $row['refused']);
        }
        exit(0);

    case 'login-url':
        if (($args[1] ?? '') === 'off') {
            // THE ADDRESS GOES WITH THE SWITCH: the screen's box is the
            // setting, and an address left stored would read as in force.
            Option::setMany([LoginUrl::ENABLED_KEY => '0', LoginUrl::SLUG_KEY => '']);
            LoginUrl::forget();
            echo 'The sign-in page is back at ' . LoginUrl::url() . "\n";
            exit(0);
        }
        if (isset($args[1])) {
            fwrite(STDERR, $usage);
            exit(2);
        }
        echo LoginUrl::url() . "\n";
        exit(0);

    case 'unlock':
        $target = (string) ($args[1] ?? '');
        if ($target === '') {
            fwrite(STDERR, $usage);
            exit(2);
        }
        if ($target === 'all') {
            // One row per ADDRESS and one per NAME: a locked name's row carries
            // the address of its last attempt, which must not fold it into
            // that address's own lockout.
            $ids = $pdo->query(
                "SELECT MIN(id) FROM security_lockouts
                  WHERE lifted_at IS NULL AND until_at > UTC_TIMESTAMP()
                  GROUP BY COALESCE(CONCAT('name:', username), CONCAT('ip:', ip))"
            )->fetchAll(PDO::FETCH_COLUMN);
        } elseif (($ip = ClientIp::normalise($target)) !== '') {
            $stmt = $pdo->prepare(
                'SELECT MIN(id) FROM security_lockouts
                  WHERE ip = :ip AND username IS NULL AND lifted_at IS NULL AND until_at > UTC_TIMESTAMP()'
            );
            $stmt->execute([':ip' => ClientIp::key($ip)]);
            $ids = array_filter($stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            // Not an address, so a NAME (`AccountLock`).
            $lifted = AccountLock::lift($target, null, 'lifted early') ? 1 : 0;
            echo $lifted === 0 ? "Nothing was locked out.\n" : "Lifted 1 lockout.\n";
            exit(0);
        }

        $lifted = 0;
        foreach ($ids as $id) {
            $lifted += Throttle::lift((int) $id, 0) ? 1 : 0;
        }
        echo $lifted === 0 ? "Nothing was locked out.\n" : "Lifted {$lifted} lockout" . ($lifted === 1 ? '' : 's') . ".\n";
        exit(0);

    case 'addresses':
        // THE OWNER'S OWN ADDRESS TURNED AWAY — an office whose address
        // changed, an allow-list that forgot the new one. The list is kept;
        // only its meaning is switched off.
        if (($args[1] ?? '') !== 'off') {
            fwrite(STDERR, $usage);
            exit(2);
        }
        Option::set(AddressRules::MODE_KEY, 'off');
        AddressRules::forget();
        echo "No address is turned away now. The list is kept: Security → Blocking → Addresses.\n";
        exit(0);

    case 'two-step':
        // A LOST PHONE AND NO BACKUP CODES. The admin can do this for another
        // account (its account screen); this is for the one who cannot sign in
        // to ask. It removes, it never sets up — setting up needs the phone.
        $who = (string) ($args[2] ?? '');
        if (($args[1] ?? '') !== 'off' || $who === '') {
            fwrite(STDERR, $usage);
            exit(2);
        }
        $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE username = :a OR email = :b LIMIT 1');
        $stmt->execute([':a' => $who, ':b' => $who]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            fwrite(STDERR, "No account is called {$who}.\n");
            exit(2);
        }

        $id = (int) $user['id'];
        Factors::removeAll($id);
        TrustedBrowsers::forgetAll($id);
        TwoStep::noNewGrace($id);
        Auth::endSessions($id);
        SecurityLog::record(SecurityLog::TWO_STEP_CHANGED, (string) $user['username'], $id, 'turned off from the command line');

        echo "Two-step sign-in is off for {$user['username']}: its methods, backup codes and remembered browsers are gone,\n"
            . "and it is signed out everywhere. It signs in with its password alone"
            . (TwoStep::requiredFor($user)
                ? ', and is asked to set two-step sign-in up again.' : '.') . "\n";
        exit(0);

    case 'suspend':
    case 'restore':
        // SUSPENDED (`Accounts`, migration 067). Restoring is the way back for
        // an administrator another one suspended — or the owner, if the
        // inactivity rule were ever given administrators.
        $who = (string) ($args[1] ?? '');
        if ($who === '' || isset($args[2])) {
            fwrite(STDERR, $usage);
            exit(2);
        }
        $stmt = $pdo->prepare('SELECT id, username FROM users WHERE username = :a OR email = :b LIMIT 1');
        $stmt->execute([':a' => $who, ':b' => $who]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            fwrite(STDERR, "No account is called {$who}.\n");
            exit(2);
        }

        $id = (int) $user['id'];
        if ($command === 'suspend') {
            echo Accounts::suspend($id, 'server', null)
                ? "{$user['username']} is suspended, and signed out everywhere. It cannot sign in until it is restored.\n"
                : "{$user['username']} was suspended already.\n";
        } else {
            echo Accounts::restore($id, null)
                ? "{$user['username']} is restored, and can sign in again.\n"
                : "{$user['username']} was not suspended.\n";
        }
        exit(0);

    case 'password':
        // A PASSWORD NOBODY CAN GET BACK: forgotten, the reset mail never
        // arrives, or a site's first one lost before it was used. The new one is
        // printed here and nowhere else, every browser signed in to the account
        // is signed out, and its owner chooses their own at the next sign-in.
        $who = (string) ($args[1] ?? '');
        if ($who === '' || isset($args[2])) {
            fwrite(STDERR, $usage);
            exit(2);
        }
        $stmt = $pdo->prepare('SELECT id, username, suspended_at FROM users WHERE username = :a OR email = :b LIMIT 1');
        $stmt->execute([':a' => $who, ':b' => $who]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            fwrite(STDERR, "No account is called {$who}.\n");
            exit(2);
        }

        $id       = (int) $user['id'];
        $name     = (string) $user['username'];
        $password = User::issuePassword($id);
        Auth::endSessions($id);
        SecurityLog::record(SecurityLog::PASSWORD_CHANGED, $name, $id, 'set from the command line');

        echo "New password for {$name}: {$password}\n"
            . 'Sign in at ' . LoginUrl::url()
            // Only where it is kept: a development install does not insist.
            . (Auth::enforcesPasswordChanges() ? '; it asks for a password of their own straight away' : '')
            . ". {$name} is signed out everywhere.\n";

        $locked = $pdo->prepare(
            'SELECT 1 FROM security_lockouts
              WHERE username = :u AND lifted_at IS NULL AND until_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $locked->execute([':u' => $name]);
        if ($locked->fetchColumn() !== false) {
            echo "{$name} is locked out for now: php admin/bin/security.php unlock {$name} lets it in at once.\n";
        }
        if (!empty($user['suspended_at'])) {
            echo "{$name} is suspended, so it cannot sign in until it is restored: php admin/bin/security.php restore {$name}\n";
        }
        exit(0);

    case 'files':
        // The check Security → Files makes, for whoever holds the server — and
        // for a cron, should someone want one: exit 3 when any file differs.
        $result = FileCheck::compare(dirname($admin));
        FileCheck::note($result);
        if ($result === null) {
            echo "No admin/manifest.json: this is not an installed package, so there is nothing to compare with.\n";
            exit(0);
        }
        $words = ['modified' => 'Modified', 'missing' => 'Missing ', 'unknown' => 'Added   '];
        $n     = count($result['modified']) + count($result['missing']) + count($result['unknown']);
        echo "Release {$result['version']}: " . FileCheck::summary($result) . ".\n";
        foreach ($words as $kind => $word) {
            foreach ($result[$kind] as $path) {
                echo "  {$word}  {$path}\n";
            }
        }
        exit($n === 0 ? 0 : 3);

    default:
        fwrite(STDERR, $usage);
        exit(2);
}
