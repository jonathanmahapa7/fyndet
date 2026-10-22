CREATE TABLE IF NOT EXISTS organisations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_owner(owner_user_id),
 FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS organisation_members (
 organisation_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 role ENUM('owner','admin','analyst') NOT NULL DEFAULT 'analyst',
 joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(organisation_id,user_id),
 INDEX idx_user(user_id),
 FOREIGN KEY(organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS organisation_invites (
 token_hash CHAR(64) PRIMARY KEY,
 organisation_id BIGINT UNSIGNED NOT NULL,
 invited_email VARCHAR(254) NOT NULL,
 role ENUM('admin','analyst') NOT NULL DEFAULT 'analyst',
 invited_by BIGINT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_org(organisation_id),
 FOREIGN KEY(organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
 FOREIGN KEY(invited_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS privacy_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 request_type ENUM('access','correction','deletion','objection') NOT NULL,
 details VARCHAR(1500) NOT NULL,
 status ENUM('received','in_review','resolved','rejected') NOT NULL DEFAULT 'received',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 closed_at DATETIME NULL,
 INDEX idx_user(user_id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_user_id BIGINT UNSIGNED NULL,
 organisation_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(90) NOT NULL,
 subject_ref VARCHAR(100) DEFAULT NULL,
 occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_actor_time(actor_user_id,occurred_at),
 INDEX idx_org_time(organisation_id,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS api_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(90) NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 revoked_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_user(user_id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
