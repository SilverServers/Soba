<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * Assistant chat sessions and their messages.
 *
 * Every query is scoped by user_id. One person's assistant conversations are not
 * another's to read, and scoping at the model rather than the controller means a
 * missed check cannot leak them.
 */
class AssistantSession
{
    /** Sessions for a user, most recently used first. */
    public static function allFor(int $userId, int $limit = 50): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT s.*, (SELECT COUNT(*) FROM assistant_messages m WHERE m.session_id = s.id) AS message_count
               FROM assistant_sessions s
              WHERE s.user_id = :uid
           ORDER BY s.updated_at DESC, s.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One session, or null if it does not exist or belongs to someone else. */
    public static function find(int $id, int $userId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT * FROM assistant_sessions WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** The user's most recent session, or null when they have none yet. */
    public static function latestFor(int $userId): ?array
    {
        return self::allFor($userId, 1)[0] ?? null;
    }

    public static function create(int $userId, string $title = 'New conversation'): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO assistant_sessions (user_id, title) VALUES (:uid, :title)'
        );
        $stmt->execute([':uid' => $userId, ':title' => self::normaliseTitle($title)]);
        return (int) $pdo->lastInsertId();
    }

    public static function rename(int $id, int $userId, string $title): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE assistant_sessions SET title = :title WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':title' => self::normaliseTitle($title), ':id' => $id, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /** Messages cascade via FK. */
    public static function delete(int $id, int $userId): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM assistant_sessions WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /** Bump updated_at so the session rises to the top of the rail. */
    public static function touch(int $id): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare('UPDATE assistant_sessions SET updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $id]);
    }

    // ----------------------------------------------------------------
    // Messages
    // ----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> Oldest first — chat order. */
    public static function messages(int $sessionId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT * FROM assistant_messages WHERE session_id = :sid ORDER BY id ASC'
        );
        $stmt->execute([':sid' => $sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Append a message.
     *
     * @param array<int, string>|null   $tools     Read-only tools that ran this turn
     * @param array<string, mixed>|null $changeSet Proposal produced this turn
     * @param array<int, string>        $errors    Validation errors at propose time
     */
    public static function addMessage(
        int $sessionId,
        string $role,
        string $content,
        ?array $tools = null,
        ?array $changeSet = null,
        array $errors = [],
        array $usage = []
    ): int {
        $status = null;
        if ($changeSet !== null) {
            $status = $errors === [] ? 'pending' : 'invalid';
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO assistant_messages
                 (session_id, role, content, tools_used, input_tokens, output_tokens,
                  change_set, change_set_id, change_set_status, change_set_errors)
             VALUES
                 (:sid, :role, :content, :tools, :in, :out, :cs, :csid, :status, :errors)'
        );
        $stmt->execute([
            ':sid'     => $sessionId,
            ':role'    => $role,
            ':content' => $content,
            ':tools'   => $tools ? implode(',', array_unique($tools)) : null,
            ':in'      => (int) ($usage['input'] ?? 0),
            ':out'     => (int) ($usage['output'] ?? 0),
            ':cs'      => $changeSet !== null ? json_encode($changeSet, JSON_UNESCAPED_UNICODE) : null,
            ':csid'    => $changeSet['id'] ?? null,
            ':status'  => $status,
            ':errors'  => $errors !== [] ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
        ]);

        // Read the id BEFORE touch(). MySQL resets the connection's insert id on
        // any subsequent statement that has no auto-increment of its own, so
        // touch()'s UPDATE would turn this into 0.
        $id = (int) $pdo->lastInsertId();

        self::touch($sessionId);

        return $id;
    }

    /** One message, scoped through its session to the owning user. */
    public static function findMessage(int $messageId, int $userId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT m.* FROM assistant_messages m
               JOIN assistant_sessions s ON s.id = m.session_id
              WHERE m.id = :id AND s.user_id = :uid
              LIMIT 1'
        );
        $stmt->execute([':id' => $messageId, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** Mark a proposal applied or discarded so it is not offered twice. */
    public static function setChangeSetStatus(int $messageId, string $status): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare('UPDATE assistant_messages SET change_set_status = :s WHERE id = :id')
            ->execute([':s' => $status, ':id' => $messageId]);
    }

    /** Decode a message's stored proposal. */
    public static function changeSetOf(array $message): ?array
    {
        if (empty($message['change_set'])) {
            return null;
        }
        $decoded = json_decode((string) $message['change_set'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The proposal in this conversation still waiting on the owner, if any.
     *
     * Newest first, because only the newest can be pending — proposing again
     * supersedes whatever came before (see supersedePending()).
     *
     * @return array{message_id: int, summary: string, operations: array}|null
     */
    public static function pendingProposal(int $sessionId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, change_set FROM assistant_messages
              WHERE session_id = :sid AND change_set_status = "pending"
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':sid' => $sessionId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $changeSet = self::changeSetOf($row);

        return $changeSet === null ? null : [
            'message_id' => (int) $row['id'],
            'summary'    => (string) ($changeSet['summary'] ?? ''),
            'operations' => (array) ($changeSet['operations'] ?? []),
        ];
    }

    /**
     * Retire every pending proposal in a conversation except `$keep`.
     *
     * Called when a new proposal lands: a refinement replaces what it refines, so
     * the owner is never shown two competing diffs with no way to tell which one
     * the assistant currently means.
     */
    public static function supersedePending(int $sessionId, int $keep): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare(
            'UPDATE assistant_messages
                SET change_set_status = "superseded"
              WHERE session_id = :sid AND change_set_status = "pending" AND id <> :keep'
        )->execute([':sid' => $sessionId, ':keep' => $keep]);
    }


    /**
     * Record what applying a change set actually did (migration 039).
     *
     * The proposal says what was asked for; this says what happened — the ids of
     * rows that were created, the previous contents of files that were rewritten.
     * Undo needs the second, not the first.
     *
     * @param array<int, array<string, mixed>> $results
     */
    public static function recordApplyResults(int $messageId, array $results): void
    {
        Database::getInstance()
            ->prepare('UPDATE assistant_messages SET apply_results = :r WHERE id = :id')
            ->execute([
                ':r'  => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => $messageId,
            ]);
    }

    /**
     * What applying this message's change set did. Empty when it was never
     * applied, or was applied before migration 039.
     *
     * @param array<string, mixed> $message
     * @return array<int, array<string, mixed>>
     */
    public static function applyResultsOf(array $message): array
    {
        if (empty($message['apply_results'])) {
            return [];
        }

        $decoded = json_decode((string) $message['apply_results'], true);

        return is_array($decoded) ? $decoded : [];
    }
    /** @return array<int, string> */
    public static function errorsOf(array $message): array
    {
        if (empty($message['change_set_errors'])) {
            return [];
        }
        $decoded = json_decode((string) $message['change_set_errors'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Prior turns as plain role/text pairs for the model.
     *
     * Tool blocks are deliberately not replayed — the API rejects tool_use blocks
     * without their matching results, and reconstructing them faithfully across a
     * page load is more fragile than simply letting the assistant re-read. Each
     * turn re-reads the content it needs anyway.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public static function historyFor(int $sessionId, int $maxTurns = 12): array
    {
        $messages = self::messages($sessionId);
        $messages = array_slice($messages, -$maxTurns);

        $history = [];
        foreach ($messages as $message) {
            $text = trim((string) $message['content']);
            if ($text === '') {
                continue;
            }
            $history[] = ['role' => $message['role'], 'content' => $text];
        }

        // The API requires the transcript to start with a user turn.
        while ($history !== [] && $history[0]['role'] !== 'user') {
            array_shift($history);
        }

        return $history;
    }

    /** First user message makes a better session name than "New conversation". */
    public static function titleFromMessage(string $message): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        return $clean === '' ? 'New conversation' : self::normaliseTitle($clean);
    }

    private static function normaliseTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return 'New conversation';
        }
        return mb_strlen($title) > 60 ? mb_substr($title, 0, 57) . '…' : $title;
    }
}
