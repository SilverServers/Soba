<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\Auth\Auth;

/**
 * Who the assistant is acting as.
 *
 * Every tool receives this. The assistant must never exceed the authority of the
 * person talking to it, so authorisation is answered from the live session rather
 * than from anything the model produced — a role carried in tool input would be
 * model-controlled, and therefore worthless as a permission check.
 */
final class ToolContext
{
    /**
     * @param int    $userId Acting user
     * @param string $role   'author' | 'editor' | 'admin'
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
    ) {
    }

    /** Build from the current session. */
    public static function fromSession(): self
    {
        $user = Auth::user();

        if ($user === null) {
            throw new \RuntimeException('The assistant cannot run without an authenticated user.');
        }

        return new self((int) $user['id'], (string) ($user['role'] ?? 'author'));
    }

    /** Role hierarchy check: the one Auth::hasRole() makes. */
    public function hasRole(string $minimumRole): bool
    {
        return Auth::roleAllows($this->role, $minimumRole);
    }

    /**
     * Authors only ever see their own entries; editors and admins see everything.
     * Returns the author_id to filter by, or null for no filter — the same rule
     * ContentTypeController applies in the admin UI, so the assistant's view of
     * the site matches what the user would see by clicking around.
     */
    public function authorFilter(): ?int
    {
        return $this->hasRole('editor') ? null : $this->userId;
    }
}
