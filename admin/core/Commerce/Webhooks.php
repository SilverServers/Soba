<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Models\Order;
use PDO;

/**
 * TELLING ANOTHER PROGRAM WHEN AN ORDER COMES IN OR MOVES: bookkeeping that
 * books each sale, a shipping service that wants the parcels to send.
 *
 * ── TO THE ADDRESSES THE OWNER SETS ─────────────────────────────────────────
 * Settings → API takes an address and the events it wants: an order placed,
 * paid, completed, cancelled or refunded. Each is POSTed the order, as the API
 * gives it (`ShopApi::order()`), in JSON, signed with the address's own secret:
 * `X-CMS-Signature: sha256=` the HMAC of the body, which the other program
 * checks with the same secret, shown once when the address is added. Nothing
 * is fetched from anywhere: the shop sends, as the security alerts' webhook
 * does, to an address an owner typed.
 *
 * ── SENT WHEN THE REQUEST ENDS, AND ONLY WHAT STOOD ─────────────────────────
 * Held until the request ends, then sent after the page is handed back, as
 * stock alerts are (`StockAlert`): a checkout does not wait on another
 * program. An order gone by then, or no longer what the event says because
 * its transaction was undone, sends nothing.
 *
 * ── AND THE RESULT KEPT ─────────────────────────────────────────────────────
 * Each address keeps its last result, `200 OK`, `HTTP 500` or why it never
 * connected, for Settings → API to show. Five seconds at most, no redirect
 * followed, and a failure is not retried: the API is how the other program
 * catches up.
 */
final class Webhooks
{
    public const EVENTS = [
        'order.created'   => 'An order is placed',
        'order.paid'      => 'An order is paid',
        'order.completed' => 'An order is completed',
        'order.cancelled' => 'An order is cancelled',
        'order.refunded'  => 'An order is refunded',
    ];

    private const TIMEOUT_SECONDS = 5;

    /** @var list<array{0: string, 1: int}> events held until the request ends */
    private static array $held = [];

    private static bool $flushQueued = false;

    /** @var (callable(string, string, array<string, string>): string)|null a recorder in the network's place */
    private static $wire = null;

    /** An order's event, held until the request ends. */
    public static function emit(string $event, int $orderId): void
    {
        if (!isset(self::EVENTS[$event])) {
            return;
        }
        self::$held[] = [$event, $orderId];
        if (!self::$flushQueued) {
            self::$flushQueued = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /** When the request ends: each held event to each address that asked for it. Never throws. */
    public static function flush(): void
    {
        $held       = self::$held;
        self::$held = [];
        if ($held === []) {
            return;
        }
        try {
            $db = Database::getInstance();
            if ($db->inTransaction()) {
                return;
            }
            $hooks = self::all();
            $send  = [];
            foreach ($held as [$event, $orderId]) {
                $order = Order::find($orderId);
                $want  = array_filter($hooks, static fn (array $h): bool => in_array($event, explode(',', (string) $h['events']), true));
                if ($order === null || $want === [] || !self::stands($event, $order)) {
                    continue;
                }
                $body = (string) json_encode(['event' => $event, 'sent_at' => gmdate('c'), 'order' => ShopApi::order($order)],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                foreach ($want as $h) {
                    $send[] = [$h, $event, $body];
                }
            }
            if ($send === []) {
                return;
            }
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                fastcgi_finish_request();
            }
            $note = $db->prepare('UPDATE webhooks SET last_status = :s, last_at = NOW() WHERE id = :id');
            foreach ($send as [$h, $event, $body]) {
                $status = self::post((string) $h['url'], $body, [
                    'X-CMS-Event'     => $event,
                    'X-CMS-Signature' => 'sha256=' . hash_hmac('sha256', $body, (string) $h['secret']),
                ]);
                $note->execute([':s' => mb_substr($status, 0, 160), ':id' => (int) $h['id']]);
            }
        } catch (\Throwable $e) {
            error_log('CMS webhooks: ' . $e->getMessage());
        }
    }

    /**
     * Whether the order is still what its event says. The transaction that moved
     * it may have been undone after the event was held, and the order is there
     * either way: a change set that failed part way told nobody it was sent.
     */
    private static function stands(string $event, array $order): bool
    {
        $status = (string) $order['status'];

        return match ($event) {
            'order.paid'      => in_array($status, Order::PAID, true),
            'order.completed' => $status === 'completed',
            'order.cancelled' => $status === 'cancelled',
            'order.refunded'  => $status === 'refunded',
            default           => true,
        };
    }

    /** @return list<array<string, mixed>> every address, oldest first */
    public static function all(): array
    {
        return Database::getInstance()->query('SELECT * FROM webhooks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Why an address and its events cannot be a webhook, in a sentence, or null
     * when they can.
     *
     * @param list<string> $events
     */
    public static function problem(string $url, array $events): ?string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false || mb_strlen($url) > 500) {
            return 'A webhook is an address that starts with https://.';
        }
        if (array_intersect(array_keys(self::EVENTS), $events) === []) {
            return 'Choose at least one thing to tell it about.';
        }

        return null;
    }

    /**
     * A new address: the events it wants, and a secret of its own, returned to
     * be shown once, as a key is. It is kept, because each message is signed
     * with it, but no screen shows it again: a lost one is a webhook removed and
     * added again.
     *
     * @param list<string> $events
     */
    public static function add(string $url, array $events): string
    {
        $problem = self::problem($url, $events);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        $secret = bin2hex(random_bytes(32));
        $stmt   = Database::getInstance()->prepare('INSERT INTO webhooks (url, events, secret) VALUES (:u, :e, :s)');
        $stmt->execute([':u' => trim($url), ':e' => implode(',', array_values(array_intersect(array_keys(self::EVENTS), $events))), ':s' => $secret]);

        return $secret;
    }

    public static function remove(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM webhooks WHERE id = :id')->execute([':id' => $id]);
    }

    /** Put something in the network's place — a recorder, say. Null puts the network back. */
    public static function useWire(?callable $wire): void
    {
        self::$wire = $wire;
    }

    /**
     * One POST of JSON, as the security alerts send theirs: five seconds at
     * most, no redirects followed, and the result in words.
     *
     * @param array<string, string> $headers
     */
    private static function post(string $url, string $body, array $headers): string
    {
        if (self::$wire !== null) {
            return (self::$wire)($url, $body, $headers);
        }
        $head = "Content-Type: application/json; charset=utf-8\r\nUser-Agent: Shop-Webhooks/" . \Cms\Update\Release::version() . "\r\n";
        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => $head, 'content' => $body,
            'timeout' => self::TIMEOUT_SECONDS, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        @file_get_contents($url, false, $ctx);
        $lines  = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = isset($lines[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $lines[0], $m) === 1 ? (int) $m[1] : 0;
        if ($status === 0) {
            $err = error_get_last();
            return 'Could not connect' . ($err !== null ? ': ' . mb_substr(preg_replace('/^file_get_contents\([^)]*\): /', '', (string) $err['message']) ?? '', 0, 120) : '');
        }

        return $status >= 200 && $status < 300 ? $status . ' OK' : 'HTTP ' . $status;
    }
}
