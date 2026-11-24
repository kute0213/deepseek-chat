SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    status TINYINT NOT NULL DEFAULT 1,
    api_key TEXT NULL,
    api_key_mask VARCHAR(32) NULL,
    created_ms BIGINT NOT NULL,
    updated_ms BIGINT NOT NULL,
    last_login_ms BIGINT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_username (username),
    KEY idx_users_created (created_ms),
    KEY idx_users_last_login (last_login_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    csrf_token CHAR(64) NOT NULL,
    created_ms BIGINT NOT NULL,
    expires_ms BIGINT NOT NULL,
    last_used_ms BIGINT NULL,
    ip VARCHAR(45) NULL,
    ua VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tokens_hash (token_hash),
    KEY idx_tokens_user (user_id),
    KEY idx_tokens_expires (expires_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT '',
    root_message_id BIGINT UNSIGNED NULL,
    messages_count INT NOT NULL DEFAULT 0,
    input_tokens BIGINT NOT NULL DEFAULT 0,
    output_tokens BIGINT NOT NULL DEFAULT 0,
    data LONGTEXT NOT NULL,
    created_ms BIGINT NOT NULL,
    updated_ms BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sessions_user (user_id, updated_ms),
    KEY idx_sessions_created (created_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS files (
    user_id BIGINT UNSIGNED NOT NULL,
    id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    size BIGINT NOT NULL DEFAULT 0,
    is_image TINYINT NOT NULL DEFAULT 0,
    mime_type VARCHAR(128) NOT NULL DEFAULT '',
    server_id VARCHAR(128) NULL,
    image_size_checked TINYINT NOT NULL DEFAULT 0,
    created_ms BIGINT NOT NULL,
    PRIMARY KEY (user_id, id),
    KEY idx_files_created (created_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_files (
    user_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    file_id VARCHAR(64) NOT NULL,
    PRIMARY KEY (session_id, file_id),
    KEY idx_session_files_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staging_sessions (
    user_id BIGINT UNSIGNED NOT NULL,
    id VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT '',
    data LONGTEXT NOT NULL,
    created_ms BIGINT NOT NULL,
    PRIMARY KEY (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staging_files (
    user_id BIGINT UNSIGNED NOT NULL,
    id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    size BIGINT NOT NULL DEFAULT 0,
    is_image TINYINT NOT NULL DEFAULT 0,
    mime_type VARCHAR(128) NOT NULL DEFAULT '',
    created_ms BIGINT NOT NULL,
    PRIMARY KEY (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS id_sequence (
    id TINYINT NOT NULL,
    last_ms BIGINT NOT NULL DEFAULT 0,
    last_seq INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO id_sequence (id, last_ms, last_seq) VALUES (1, 0, 0)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS login_attempts (
    username VARCHAR(64) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    window_start_ms BIGINT NOT NULL,
    PRIMARY KEY (username, ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS captchas (
    id CHAR(32) NOT NULL,
    answer_hash CHAR(64) NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    created_ms BIGINT NOT NULL,
    expires_ms BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_captchas_expires (expires_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_logs (
    id BIGINT UNSIGNED NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(32) NOT NULL DEFAULT '',
    target_id VARCHAR(64) NOT NULL DEFAULT '',
    detail TEXT NULL,
    ip VARCHAR(45) NULL,
    created_ms BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_logs_admin (admin_id, created_ms),
    KEY idx_logs_created (created_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_settings (
    k VARCHAR(64) NOT NULL,
    v TEXT NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (k, v) VALUES
    ('site_name', 'DSW'),
    ('allow_register', '1'),
    ('api_base_url', 'https://api.deepseek.com'),
    ('announcement', ''),
    ('max_upload_mb', '64'),
    ('max_sessions_per_user', '0')
ON DUPLICATE KEY UPDATE v = v;
