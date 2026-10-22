ALTER TABLE users ADD COLUMN is_platform_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER session_version;
ALTER TABLE privacy_requests ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER status;
ALTER TABLE privacy_requests ADD COLUMN resolution_note VARCHAR(1500) NULL AFTER reviewed_by;
CREATE TABLE IF NOT EXISTS maintenance_runs (
 job_name VARCHAR(70) PRIMARY KEY,
 last_started_at DATETIME NULL,
 last_succeeded_at DATETIME NULL,
 last_error VARCHAR(400) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
