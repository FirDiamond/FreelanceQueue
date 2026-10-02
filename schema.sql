-- FreelanceQueue schema (MySQL / MariaDB).
--
-- Conventions:
--   * every *_at column stores DATETIME in UTC (see includes/timeutil.php),
--     written with gmdate('Y-m-d H:i:s')
--   * queue_date and business_hours times are LOCAL wall clock
--   * booleans are TINYINT(1) 0/1 with a CHECK
--   * case-insensitive email/name comparison comes from the utf8mb4_unicode_ci
--     collation, which replaces SQLite's COLLATE NOCASE

CREATE TABLE users (
    id         INT          NOT NULL AUTO_INCREMENT,
    email      VARCHAR(255) NOT NULL,
    full_name  VARCHAR(255) NOT NULL,
    role       VARCHAR(16)  NOT NULL CHECK (role IN ('freelancer', 'client')),
    -- bcrypt hash from password_hash($pw, PASSWORD_BCRYPT); method, salt and
    -- digest live in the one string, so there is no separate salt column.
    pwd_hash   VARCHAR(255) NOT NULL,
    headline   VARCHAR(255) NULL,
    queue_open TINYINT(1)   NOT NULL DEFAULT 0 CHECK (queue_open IN (0, 1)),
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
    id            INT          NOT NULL AUTO_INCREMENT,
    freelancer_id INT          NOT NULL,
    name          VARCHAR(255) NOT NULL,
    price         DOUBLE       NOT NULL CHECK (price >= 0),
    est_minutes   INT          NOT NULL CHECK (est_minutes > 0),
    is_active     TINYINT(1)   NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_services_name (freelancer_id, name),
    CONSTRAINT fk_services_freelancer FOREIGN KEY (freelancer_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE business_hours (
    freelancer_id INT         NOT NULL,
    weekday       INT         NOT NULL CHECK (weekday BETWEEN 0 AND 6), -- 0 = Monday
    is_open       TINYINT(1)  NOT NULL DEFAULT 0 CHECK (is_open IN (0, 1)),
    open_time     VARCHAR(5)  NULL,                                     -- 'HH:MM' local
    close_time    VARCHAR(5)  NULL,
    PRIMARY KEY (freelancer_id, weekday),
    CONSTRAINT fk_hours_freelancer FOREIGN KEY (freelancer_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT ck_hours_window CHECK (
        is_open = 0
        OR (open_time IS NOT NULL AND close_time IS NOT NULL AND open_time < close_time)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE queue_entries (
    id            INT          NOT NULL AUTO_INCREMENT,
    freelancer_id INT          NOT NULL,
    client_id     INT          NOT NULL,
    service_id    INT          NOT NULL,
    queue_date    DATE         NOT NULL,  -- local 'YYYY-MM-DD'; scopes ticket numbers
    ticket_no     VARCHAR(16)  NOT NULL,  -- 'A-014'
    seq           INT          NOT NULL,  -- daily counter behind ticket_no
    -- Gap-based ordering: reordering writes one midpoint value instead of
    -- renumbering rows, so concurrent requests cannot fight over a counter.
    sort_key      DOUBLE       NOT NULL,
    status        VARCHAR(16)  NOT NULL CHECK (
                      status IN ('waiting', 'serving', 'done',
                                 'skipped', 'no_show', 'cancelled')),
    note          VARCHAR(280) NULL,
    price_charged DOUBLE       NULL,      -- snapshotted at completion
    joined_at     DATETIME     NOT NULL,
    started_at    DATETIME     NULL,
    ended_at      DATETIME     NULL,
    -- Set in the same transaction as the send, so re-evaluating the queue
    -- cannot email the same client twice.
    notified_soon TINYINT(1)   NOT NULL DEFAULT 0 CHECK (notified_soon IN (0, 1)),
    -- Emulates SQLite's partial unique index "one serving per freelancer": the
    -- generated key is the freelancer_id only while serving, NULL otherwise,
    -- and NULLs never collide in a UNIQUE index. A double-clicked "Call next"
    -- hits this instead of corrupting state.
    serving_key   INT AS (IF(status = 'serving', freelancer_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_queue_seq (freelancer_id, queue_date, seq),
    UNIQUE KEY uq_queue_one_serving (serving_key),
    CONSTRAINT fk_queue_freelancer FOREIGN KEY (freelancer_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_queue_client FOREIGN KEY (client_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_queue_service FOREIGN KEY (service_id)
        REFERENCES services (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_log (
    id         INT          NOT NULL AUTO_INCREMENT,
    entry_id   INT          NULL,
    to_email   VARCHAR(255) NOT NULL,
    event      VARCHAR(48)  NOT NULL,
    subject    VARCHAR(255) NOT NULL,
    status     VARCHAR(16)  NOT NULL CHECK (status IN ('queued', 'sent', 'failed')),
    attempts   INT          NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    created_at DATETIME     NOT NULL,
    sent_at    DATETIME     NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_email_entry FOREIGN KEY (entry_id)
        REFERENCES queue_entries (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id           INT          NOT NULL AUTO_INCREMENT,
    email        VARCHAR(255) NOT NULL,
    ip           VARCHAR(64)  NULL,
    attempted_at DATETIME     NOT NULL,
    success      TINYINT(1)   NOT NULL CHECK (success IN (0, 1)),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_queue_active   ON queue_entries (freelancer_id, status, sort_key);
CREATE INDEX idx_queue_client   ON queue_entries (client_id, status);
CREATE INDEX idx_queue_reports  ON queue_entries (freelancer_id, ended_at);
CREATE INDEX idx_services_freelancer ON services (freelancer_id, is_active);
CREATE INDEX idx_email_log_status    ON email_log (status, created_at);
CREATE INDEX idx_login_attempts      ON login_attempts (email, attempted_at);
