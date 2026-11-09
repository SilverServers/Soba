<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Helpers\Url;
use Cms\Mail\Mailer;
use Cms\Security\ClientIp;
use Cms\Security\Session;
use PDO;

/**
 * A CUSTOMER'S ACCOUNT: their orders and their addresses, behind a link sent
 * to the address they ordered with.
 *
 * ── NO PASSWORD ─────────────────────────────────────────────────────────────
 * A customer is the address their orders were placed with (`Customers`), so
 * proving the inbox is proving who they are. They ask at /account, and that
 * address is sent a link: kept here only as its hash, good once, for half an
 * hour (migration 105). There is no account to make, and none to forget the
 * password of.
 *
 * ── THE SAME ANSWER FOR EVERYONE ────────────────────────────────────────────
 * Asking answers the same whatever was typed, an address that has ordered or
 * not, sent or held back by the limits below, as the admin's own sign-in link
 * does (`Security\Passwordless`): the page never says who has shopped here.
 *
 * ── A LINK IS SPENT BY A BUTTON, NOT BY OPENING IT ─────────────────────────
 * A mail scanner that opens links before the customer does would otherwise
 * spend it, and be signed in. The link opens a page with one button; only
 * that POST spends it.
 *
 * ── HOW OFTEN ───────────────────────────────────────────────────────────────
 * Three links an hour to one address and five from one place, counted from
 * the links themselves: the reset link's numbers.
 */
final class CustomerAccount
{
    public const MINUTES     = 30;
    public const PER_EMAIL   = 3;
    public const PER_ADDRESS = 5;

    private const SESSION = 'cms_customer';

    /** The customer signed in on this visit, by their address; null for nobody. */
    public static function email(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $who = $_SESSION[self::SESSION]['email'] ?? null;

        return is_string($who) && $who !== '' ? $who : null;
    }

    /**
     * A link to sign in, sent to this address if it has ordered here and the
     * limits allow. Answers nothing either way.
     */
    public static function sendLink(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $db  = Database::getInstance();
        $ip  = ClientIp::key();
        $has = $db->prepare("SELECT COUNT(*) FROM orders WHERE LOWER(email) = :e");
        $has->execute([':e' => $email]);
        if ((int) $has->fetchColumn() === 0) {
            return;
        }
        $recent = $db->prepare(
            'SELECT SUM(email = :e), SUM(ip_key = :ip) FROM customer_links WHERE created_at > NOW() - INTERVAL 1 HOUR'
        );
        $recent->execute([':e' => $email, ':ip' => $ip]);
        [$byEmail, $byIp] = array_map('intval', (array) $recent->fetch(PDO::FETCH_NUM));
        if ($byEmail >= self::PER_EMAIL || $byIp >= self::PER_ADDRESS) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $stmt  = $db->prepare(
            'INSERT INTO customer_links (email, token_hash, ip_key, expires_at)
             VALUES (:e, :h, :ip, NOW() + INTERVAL ' . self::MINUTES . ' MINUTE)'
        );
        $stmt->execute([':e' => $email, ':h' => hash('sha256', $token), ':ip' => $ip]);

        $site = \Cms\SEO\Head::globalTokens()['site_name'];
        Mailer::send(
            [$email],
            $site . ' — your link to sign in',
            'Follow this link to see your orders with ' . $site . ':' . "\n\n"
                . Url::page('account/enter/' . $token) . "\n\n"
                . 'It works once, for ' . self::MINUTES . ' minutes. If you did not ask for it, you can ignore this email:'
                . ' nobody can sign in without it.' . "\n\n"
                . (Business::lines() !== [] ? implode("\n", Business::lines()) : $site) . "\n",
            ['reply_to' => Mailer::adminAddress()]
        );
    }

    /** Whether a link is still good, without spending it: for the page its button is on. */
    public static function linkIsGood(string $token): bool
    {
        return self::linkRow($token) !== null;
    }

    /** The link spent, and its customer signed in. False when it is not good. */
    public static function enter(string $token): bool
    {
        $row = self::linkRow($token);
        if ($row === null) {
            return false;
        }
        $stmt = Database::getInstance()->prepare(
            'UPDATE customer_links SET used_at = NOW() WHERE id = :id AND used_at IS NULL'
        );
        $stmt->execute([':id' => (int) $row['id']]);
        if ($stmt->rowCount() !== 1 || !Session::ensure()) {
            return false;
        }
        // A NEW SESSION ID for a new person, so one set before the link was
        // followed cannot ride along into the account.
        session_regenerate_id(true);
        $_SESSION[self::SESSION] = ['email' => (string) $row['email'], 'since' => time()];

        return true;
    }

    /** Signed out: the account forgotten on this visit, the basket kept. */
    public static function leave(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION]);
            session_regenerate_id(true);
        }
    }

    /**
     * The addresses their newest order was billed and sent to, as the checkout
     * names its fields: where the next checkout starts.
     *
     * @return array<string, string>
     */
    public static function lastAddresses(string $email): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM orders WHERE LOWER(email) = :e ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':e' => mb_strtolower($email)]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($o === false) {
            return [];
        }
        $out = ['email' => (string) $o['email'], 'phone' => (string) $o['phone']];
        foreach (['billing', 'shipping'] as $kind) {
            foreach (['name', 'line1', 'line2', 'city', 'region', 'post', 'country'] as $part) {
                $out[$kind . '_' . $part] = (string) $o[$kind . '_' . $part];
            }
        }

        return $out;
    }

    /** The unspent, unexpired link a token names, or null. */
    private static function linkRow(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id, email FROM customer_links WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([':h' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
