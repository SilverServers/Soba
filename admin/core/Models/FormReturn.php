<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;

/**
 * A REFUSED FORM'S ANSWERS, CARRIED BACK TO ITS PAGE (migration 123).
 *
 * A form the CMS draws posts the page it is on (`RETURN_FIELD`). Sent without
 * JavaScript and refused, it is answered with a 303 back to that page, at the
 * form, and what was typed and what is wrong go back with it: kept here under
 * a random key, the key in a cookie of its own (`COOKIE`), for minutes
 * (`MINUTES`). The page's form takes it once (`take()`), draws every answer as
 * typed and each message under its field, and the row is gone.
 *
 * Nothing personal goes into an address, and no session is needed. A browser
 * that keeps no cookie gets the page with its form as drawn, and the visitor
 * types again, as before there was this.
 */
final class FormReturn
{
    /** The cookie holding the key, host-only, for the visitor's own host. */
    public const COOKIE = 'cms_form_return';

    /** How long what goes back is kept. */
    public const MINUTES = 10;

    /** The hidden field in which a form the CMS draws posts its page's address. */
    public const RETURN_FIELD = 'cms_return';

    /** The most text one answer carries back. */
    private const MOST = 5000;

    /**
     * Keep what goes back, and give the browser its key. False when it cannot
     * go (the answer's headers are already out), for the caller to answer as
     * it did before.
     *
     * @param array<string, mixed>  $answers name => what was typed: text, or a list of it
     * @param array<string, string> $errors  name => its message; '' => the form's own
     */
    public static function keep(int $formId, array $answers, array $errors): bool
    {
        if (headers_sent()) {
            return false;
        }
        $key = bin2hex(random_bytes(16));
        $now = time();
        $pdo = Database::getInstance();
        // WHAT LAPSED GOES as each new one is kept, so the table holds minutes
        // of refusals and never more.
        $pdo->prepare('DELETE FROM form_returns WHERE created_at < :before')
            ->execute([':before' => gmdate('Y-m-d H:i:s', $now - self::MINUTES * 60)]);
        $pdo->prepare(
            'INSERT INTO form_returns (token_hash, form_id, answers, errors, created_at) VALUES (:h, :f, :a, :e, :at)'
        )->execute([
            ':h'  => hash('sha256', $key),
            ':f'  => $formId,
            ':a'  => json_encode(self::cut($answers), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE),
            ':e'  => json_encode(array_map(static fn ($m): string => mb_substr((string) $m, 0, 500), $errors), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE),
            ':at' => gmdate('Y-m-d H:i:s', $now),
        ]);
        setcookie(self::COOKIE, $key, [
            'expires'  => $now + self::MINUTES * 60,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return true;
    }

    /**
     * What a refused post of this form carried back to its page, taken once:
     * answers and errors as kept, or null when there is none, it lapsed, or it
     * is another form's.
     *
     * @return array{answers: array<string, mixed>, errors: array<string, string>}|null
     */
    public static function take(int $formId): ?array
    {
        $key = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^[0-9a-f]{32}$/', $key) !== 1) {
            return null;
        }
        $pdo  = Database::getInstance();
        $hash = hash('sha256', $key);
        $stmt = $pdo->prepare(
            'SELECT answers, errors FROM form_returns WHERE token_hash = :h AND form_id = :f AND created_at >= :since'
        );
        $stmt->execute([':h' => $hash, ':f' => $formId, ':since' => gmdate('Y-m-d H:i:s', time() - self::MINUTES * 60)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        // ONCE: whichever drawing deletes it is the one that shows it.
        $gone = $pdo->prepare('DELETE FROM form_returns WHERE token_hash = :h');
        $gone->execute([':h' => $hash]);
        if ($gone->rowCount() !== 1) {
            return null;
        }
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        }
        $answers = json_decode((string) $row['answers'], true);
        $errors  = json_decode((string) $row['errors'], true);

        return [
            'answers' => is_array($answers) ? $answers : [],
            'errors'  => is_array($errors) ? array_map('strval', $errors) : [],
        ];
    }

    /**
     * Text cut to what goes back: each answer, and each item of a list.
     *
     * @param  array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private static function cut(array $answers): array
    {
        $out = [];
        foreach ($answers as $name => $value) {
            if (is_array($value)) {
                $out[(string) $name] = array_map(static fn ($v): string => is_scalar($v) ? mb_substr((string) $v, 0, self::MOST) : '', $value);
            } elseif (is_scalar($value)) {
                $out[(string) $name] = mb_substr((string) $value, 0, self::MOST);
            }
        }
        return $out;
    }
}
