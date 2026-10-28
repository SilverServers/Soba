-- Migration 032: Assistant chat sessions.
--
-- The assistant was holding its conversation in $_SESSION, which meant one
-- conversation at a time, lost on logout, and a proposal that vanished if the
-- user navigated away. Chat UIs are expected to keep history; this stores it.
--
-- Design notes:
--
--  * A proposal is stored ON the message that produced it, not in PHP session
--    state. It is part of the transcript — the record of what was suggested
--    survives whether or not it was applied, which is what makes the assistant
--    auditable after the fact.
--
--  * `change_set_status` distinguishes a proposal still awaiting review from one
--    already applied or discarded, so a reloaded conversation shows each proposal
--    in the right state rather than offering to apply something twice.
--
--  * Sessions are per user and cascade on user deletion: one person's assistant
--    conversations are not another's to read.

CREATE TABLE assistant_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(255) NOT NULL DEFAULT 'New conversation',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_assistant_sessions_user (user_id, updated_at),

    CONSTRAINT fk_assistant_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assistant_messages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    role       ENUM('user','assistant') NOT NULL,
    content    LONGTEXT NULL,

    -- Which read-only tools ran for this turn, comma separated. Shown under the
    -- message so the user can see what the assistant actually looked at.
    tools_used VARCHAR(500) NULL,

    -- The proposal this turn produced, as the JSON a ChangeSet round-trips to.
    change_set        LONGTEXT NULL,
    change_set_id     VARCHAR(64) NULL,
    change_set_status ENUM('pending','applied','discarded','invalid') NULL,

    -- Validation errors at propose time, so a reloaded conversation can explain
    -- why a proposal was never applicable.
    change_set_errors LONGTEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_assistant_messages_session (session_id, id),

    CONSTRAINT fk_assistant_messages_session
        FOREIGN KEY (session_id) REFERENCES assistant_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
