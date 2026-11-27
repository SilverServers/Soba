<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

class User
{

    /** No 0/o, no 1/i/l, as on backup codes: an issued password is read off a terminal and typed. */
    private const ISSUED_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * What migration 002 seeds in place of a password. It is not a hash, so
     * password_verify() refuses it: the account cannot sign in until
     * `issueFirstPassword()` gives it one.
     */
    public const NO_PASSWORD = '!first-sign-in';

    /**
     * Return all users, ordered by username. Never returns password_hash.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            'SELECT id, username, display_name, person_key, email, role, theme_preset, created_at, updated_at,
                    last_seen_at, suspended_at, suspended_why, suspended_by,
                    EXISTS (SELECT 1 FROM temporary_access t WHERE t.user_id = users.id) AS temporary
             FROM users
             ORDER BY username ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * THE ONE RULE for the name the ADMIN shows beside an entry: the display
     * name, else the username. As SQL for a SELECT list — `$alias` is a literal
     * the caller writes, never request data. Fourteen queries spelled
     * `u.username AS author_name` out by hand; they all ask one of these two
     * now. The PUBLIC byline asks `bylineSql()`, which never falls back to a
     * sign-in name.
     */
    public static function nameSql(string $alias = 'u'): string
    {
        return "COALESCE({$alias}.display_name, {$alias}.username)";
    }

    /**
     * THE AUTHOR'S NAME AS THE PUBLIC SITE MAY PRINT IT — never a sign-in name.
     *
     * `nameSql()` falls back to the USERNAME, which is right in the admin (an
     * editor looking for a colleague by sign-in name must find them) and wrong
     * everywhere the public can read it: every article by an author with no
     * display name would print "By admin" and put `"author": {"name": "admin"}`
     * in its JSON-LD — half of the credential the sign-in page asks for, on
     * every page of the site. It is the author-archive leak that user
     * enumeration protection exists to plug.
     *
     * So a public query asks for this instead: the display name when there is
     * one AND it is not the username wearing a costume (compared ignoring case
     * and spaces), otherwise the Site title — the byline credits the site, the
     * way an unsigned newspaper article credits the paper. Never empty, so a
     * theme printing `By <?= author_name ?>` needs no change and cannot leak.
     */
    public static function bylineSql(string $alias = 'u'): string
    {
        $site = \Cms\Database\Database::getInstance()->quote(
            (string) \Cms\SEO\Head::globalTokens()['site_name']
        );

        return "CASE WHEN {$alias}.display_name IS NOT NULL
                       AND TRIM({$alias}.display_name) <> ''
                       AND LOWER(TRIM({$alias}.display_name)) <> LOWER(TRIM({$alias}.username))
                     THEN {$alias}.display_name
                     ELSE {$site} END";
    }

    /** The same rule for a user row already in hand. */
    public static function displayName(array $row): string
    {
        $name = trim((string) ($row['display_name'] ?? ''));
        return $name !== '' ? $name : (string) ($row['username'] ?? '');
    }

    /** Empty is NULL, so "has a display name" is one test (see nameSql()). */
    public static function cleanName(mixed $value): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($value ?? '')));
        return $name === '' ? null : mb_substr($name, 0, 100);
    }

    /**
     * Everyone an entry can be credited to, by the name the byline would show.
     * Every role can write, so every user is offered.
     *
     * @return list<array<string, mixed>>
     */
    public static function authors(): array
    {
        $rows = self::all();
        usort($rows, static fn (array $a, array $b): int =>
            strcasecmp(self::displayName($a), self::displayName($b)));
        return $rows;
    }

    /**
     * Find a single user by ID. Returns null if not found. Never returns password_hash.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, username, display_name, person_key, email, role, theme_preset, radius_pref, created_at, updated_at,
                    last_seen_at, suspended_at, suspended_why, suspended_by
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * The account linked to one of the site's people (`Helpers\People`),
     * or null. One person is one account.
     *
     * @return array<string, mixed>|null
     */
    public static function byPerson(string $key): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT id FROM users WHERE person_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $id = $stmt->fetchColumn();
        return $id !== false ? self::find((int) $id) : null;
    }

    /**
     * Every linked account, by its person's key — for a screen that says
     * which person is whose.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function personHolders(): array
    {
        $rows = Database::getInstance()
            ->query('SELECT id, username, display_name, person_key FROM users WHERE person_key IS NOT NULL')
            ->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['person_key']] = $row;
        }
        return $out;
    }

    /**
     * Link an account to one of the site's people, or unlink it (null). Its
     * own setter, as setAuthor() is: update() writes what a form posts, and
     * this is an administrator's decision the caller has already checked
     * (UserController::personProblem()). The unique key refuses a second
     * account even so.
     */
    public static function linkPerson(int $id, ?string $key): void
    {
        $key = $key !== null && trim($key) !== '' ? trim($key) : null;
        Database::getInstance()
            ->prepare('UPDATE users SET person_key = :key WHERE id = :id')
            ->execute([':key' => $key, ':id' => $id]);
    }

    /**
     * Create a new user. Returns the new user ID.
     *
     * @param array{username: string, email: string, password: string, role?: string} $data
     */
    public static function create(array $data): int
    {
        $pdo = Database::getInstance();

        $role = $data['role'] ?? 'author';
        if (!isset(\Cms\Auth\Auth::ROLE_LEVELS[$role])) {
            $role = 'author';
        }

        // `must_change_password` (migration 061): 'admin' from Users → Add, so
        // the person the account is for chooses their own password at their
        // first sign-in. Other callers (an import) pass none.
        // `person_key` (migration 093): one of the site's people,
        // in the same INSERT — the unique key then refuses a person another
        // account took a moment ago, and no account is left half-made.
        $person = trim((string) ($data['person_key'] ?? ''));
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, display_name, email, password_hash, role, must_change_password, password_changed_at, person_key)
             VALUES (:username, :display_name, :email, :password_hash, :role, :must, UTC_TIMESTAMP(), :person)'
        );
        $stmt->execute([
            ':username'      => $data['username'],
            ':display_name'  => self::cleanName($data['display_name'] ?? null),
            ':email'         => $data['email'],
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':role'          => $role,
            ':must'          => $data['must_change_password'] ?? null,
            ':person'        => $person !== '' ? $person : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Update a user's profile fields.
     *
     * Accepts: theme_preset, radius_pref, email, role, display_name.
     * Password is updated separately via updatePassword().
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): bool
    {
        $sets   = [];
        $params = [':id' => $id];

        // THE ACCOUNT'S ADMIN THEME (`Ui\Theme`): a theme's key, or NULL for the
        // default. The caller has checked that the key names a theme.
        if (array_key_exists('theme_preset', $data)) {
            $sets[]                  = 'theme_preset = :theme_preset';
            $params[':theme_preset'] = $data['theme_preset'] === null ? null : (string) $data['theme_preset'];
        }

        // ITS CORNERS (`Ui\Theme::CORNERS`): 'modern' for Rounded, or NULL for
        // Square, the default. The caller has checked the value.
        if (array_key_exists('radius_pref', $data)) {
            $sets[]                 = 'radius_pref = :radius_pref';
            $params[':radius_pref'] = $data['radius_pref'] === null ? null : (string) $data['radius_pref'];
        }

        if (isset($data['email']) && $data['email'] !== '') {
            $sets[]            = 'email = :email';
            $params[':email']  = $data['email'];
        }

        if (isset($data['role']) && isset(\Cms\Auth\Auth::ROLE_LEVELS[$data['role']])) {
            $sets[]           = 'role = :role';
            $params[':role']  = $data['role'];
        }

        // Migration 055. Present means "set it" — to NULL when emptied, which
        // makes the PUBLIC byline credit the site (bylineSql) — the admin's
        // own lists still show the username (nameSql).
        if (array_key_exists('display_name', $data)) {
            $sets[]                  = 'display_name = :display_name';
            $params[':display_name'] = self::cleanName($data['display_name']);
        }

        if (empty($sets)) {
            return false;
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        return $stmt->execute($params);
    }

    /**
     * Set a user's password — and say whether its owner still has to choose
     * their own (migration 061's `must_change_password`).
     *
     * `$mustChange` is NULL when the owner chose it; `'admin'` when somebody
     * else did (Add user, or an administrator changing another account), so
     * the owner is asked for their own at their next sign-in.
     */
    public static function updatePassword(int $id, string $newPassword, ?string $mustChange = null): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            // …and any reset link still out (migration 062) is spent: a link
            // mailed before this change must not be able to undo it.
            'UPDATE users
                SET password_hash = :hash, password_changed_at = UTC_TIMESTAMP(), must_change_password = :must,
                    reset_token_hash = NULL, reset_expires_at = NULL
              WHERE id = :id'
        );
        return $stmt->execute([
            ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':must' => $mustChange,
            ':id'   => $id,
        ]);
    }

    /**
     * A PASSWORD NOBODY CHOSE, set on the account and returned once, for
     * whoever holds the server to pass on: four groups of five from
     * ISSUED_ALPHABET, about 99 bits. `$reason` asks its owner to choose their
     * own at the next sign-in, as `updatePassword()` records it.
     */
    public static function issuePassword(int $id, string $reason = 'admin'): string
    {
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 5; $i++) {
                $group .= self::ISSUED_ALPHABET[random_int(0, strlen(self::ISSUED_ALPHABET) - 1)];
            }
            $groups[] = $group;
        }
        $password = implode('-', $groups);
        if (!self::updatePassword($id, $password, $reason)) {
            throw new \RuntimeException("The password for account {$id} could not be saved.");
        }

        return $password;
    }

    /**
     * A NEW SITE'S FIRST PASSWORD, for the account migration 002 seeded with
     * NO_PASSWORD. Its owner is asked to choose their own name and password at
     * the first sign-in ('seed'). Null when no account is waiting for one,
     * which is every run after the first.
     *
     * @return array{id: int, username: string, password: string}|null
     */
    public static function issueFirstPassword(?string $username = null, ?string $email = null): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT id, username FROM users WHERE password_hash = :none ORDER BY id LIMIT 1');
        $stmt->execute([':none' => self::NO_PASSWORD]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        // NAMED BY WHOEVER INSTALLS IT (`migrate.php run --admin-user=
        // --admin-email=`), as the browser set-up names it, rather than the
        // seeded `admin`; the caller has checked both (`Installer::accountProblems()`).
        if ($username !== null && $email !== null) {
            Database::getInstance()
                ->prepare('UPDATE users SET username = :u, email = :e WHERE id = :id')
                ->execute([':u' => $username, ':e' => $email, ':id' => (int) $row['id']]);
            $row['username'] = $username;
        }

        return ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'password' => self::issuePassword((int) $row['id'], 'seed')];
    }

    /**
     * Ask an account to choose a new password at its next sign-in, and say
     * why: 'weak' (it broke the password rule when it was typed), 'admin',
     * 'all'. See migration 061.
     */
    public static function flagPasswordChange(int $id, string $reason): void
    {
        Database::getInstance()
            ->prepare('UPDATE users SET must_change_password = :why WHERE id = :id')
            ->execute([':why' => $reason, ':id' => $id]);
    }

    /**
     * Every account except `$exceptId` must choose a new password — and every
     * one of their sessions ends now. Returns how many accounts it reached.
     */
    public static function flagEveryonePasswordChange(int $exceptId): int
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE users
                SET must_change_password = 'all', session_version = session_version + 1
              WHERE id <> :me"
        );
        $stmt->execute([':me' => $exceptId]);

        return $stmt->rowCount();
    }

    /** How many accounts still have to choose a new password, by reason. */
    public static function pendingPasswordChanges(): array
    {
        return Database::getInstance()->query(
            'SELECT must_change_password AS reason, COUNT(*) AS n FROM users
              WHERE must_change_password IS NOT NULL GROUP BY must_change_password'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Change an account's sign-in name. The caller has checked it is free and allowed. */
    public static function rename(int $id, string $username): void
    {
        Database::getInstance()
            ->prepare('UPDATE users SET username = :name WHERE id = :id')
            ->execute([':name' => $username, ':id' => $id]);
    }

    /**
     * Verify a user's current password. Used for self-service password changes.
     */
    public static function verifyPassword(int $id, string $password): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $hash = $stmt->fetchColumn();

        return $hash !== false && password_verify($password, $hash);
    }

    /**
     * Delete a user. Reassigns their posts and pages to $reassignToId first.
     *
     * Returns false if: user not found, trying to delete self, or last admin.
     */
    public static function delete(int $id, int $reassignToId): bool
    {
        $pdo = Database::getInstance();

        // Prevent deleting the last admin
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND id != :id"
        );
        $stmt->execute([':id' => $id]);
        $user = self::find($id);
        if ($user === null) {
            return false;
        }
        if ($user['role'] === 'admin' && (int) $stmt->fetchColumn() === 0) {
            return false;
        }

        // Reassign posts and pages (FK is ON DELETE RESTRICT). `updated_at` is
        // named so it is KEPT: both columns are ON UPDATE CURRENT_TIMESTAMP, and
        // removing a colleague is not an edit to every article they wrote — a
        // bare reassignment stamped all of them "modified now", in the sitemap
        // and in each Article's dateModified.
        $stmt = $pdo->prepare('UPDATE posts SET author_id = :new, updated_at = updated_at WHERE author_id = :old');
        $stmt->execute([':new' => $reassignToId, ':old' => $id]);

        $stmt = $pdo->prepare('UPDATE pages SET author_id = :new, updated_at = updated_at WHERE author_id = :old');
        $stmt->execute([':new' => $reassignToId, ':old' => $id]);

        // Delete the user
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Check if a username already exists (optionally excluding an ID for updates).
     */
    public static function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $pdo = Database::getInstance();
        if ($excludeId !== null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u AND id != :id');
            $stmt->execute([':u' => $username, ':id' => $excludeId]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
            $stmt->execute([':u' => $username]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Check if an email already exists (optionally excluding an ID for updates).
     */
    public static function emailExists(string $email, ?int $excludeId = null): bool
    {
        $pdo = Database::getInstance();
        if ($excludeId !== null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :e AND id != :id');
            $stmt->execute([':e' => $email, ':id' => $excludeId]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :e');
            $stmt->execute([':e' => $email]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Count all users.
     */
    public static function count(): int
    {
        $pdo  = Database::getInstance();
        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
