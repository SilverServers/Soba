-- Migration 134: each run of a site's own scheduled task (`Site\Tasks`):
-- when it started and finished, who ran it (the cron, a visit or a person),
-- how it ended and what it said. The newest fifty of each task are kept.

CREATE TABLE IF NOT EXISTS task_runs (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    task        VARCHAR(64)   NOT NULL,
    started_at  DATETIME      NOT NULL,
    finished_at DATETIME      NULL,
    seconds     DECIMAL(10,2) NULL,
    outcome     VARCHAR(16)   NOT NULL,
    ran_by      VARCHAR(8)    NOT NULL,
    message     TEXT          NULL,
    PRIMARY KEY (id),
    KEY idx_task_runs_task (task, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
