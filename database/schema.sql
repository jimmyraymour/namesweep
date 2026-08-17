-- NameSweep schema — MySQL 8
-- Database: namesweep · Charset: utf8mb4 · Collation: utf8mb4_0900_ai_ci
--
-- Create the database first:
--   mysql -u root -p -e "CREATE DATABASE namesweep CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
-- Then load this file:
--   mysql -u root -p namesweep < database/schema.sql

-- ---------------------------------------------------------------------------
-- REST API keys. Plaintext is shown once at creation and never stored.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(64)  NOT NULL,                          -- human label, e.g. "coding-agent"
    key_hash      CHAR(64)     NOT NULL,                          -- sha256 hex of the plaintext key
    key_prefix    CHAR(8)      NOT NULL,                          -- first 8 chars of plaintext, for identification
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME     NULL,
    revoked_at    DATETIME     NULL,
    UNIQUE KEY uniq_key_hash (key_hash),
    KEY idx_prefix (key_prefix)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Result cache. One row per (name, tld, module, platform).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS checks (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(63)     NOT NULL,                       -- bare name without TLD
    tld           VARCHAR(64)     NOT NULL DEFAULT '',            -- empty for non-domain modules
    module        ENUM('domain','marketplace','social','trademark') NOT NULL,
    platform      VARCHAR(32)     NOT NULL DEFAULT '',            -- social/trademark provider key
    status        ENUM('available','registered','for_sale','parked','uncertain') NOT NULL,
    source        VARCHAR(64)     NOT NULL,                       -- which provider produced this
    detail        JSON            NULL,                           -- provider-specific notes
    price         DECIMAL(10,2)   NULL,                           -- marketplace only
    url           VARCHAR(512)    NULL,                           -- landing page if any
    checked_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME        NOT NULL,                       -- now() + TTL for that module
    UNIQUE KEY uniq_lookup (name, tld, module, platform),
    KEY idx_expires (expires_at),
    KEY idx_checked (checked_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Suggest mode: one row per run.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suggest_sessions (
    id             CHAR(36)        NOT NULL PRIMARY KEY,          -- UUIDv4
    description    TEXT            NOT NULL,
    model          VARCHAR(64)     NOT NULL,                      -- 'ollama:llama3.1:8b' or 'openrouter:...'
    tld_filter     JSON            NULL,                          -- ['com','net','io',...]
    modules_filter JSON            NULL,                          -- ['domain','social',...]
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalized_at   DATETIME        NULL,
    KEY idx_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Suggest mode: every generated name (kept or rejected), per iteration.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suggest_candidates (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36)        NOT NULL,
    name       VARCHAR(63)     NOT NULL,
    iteration  INT UNSIGNED    NOT NULL DEFAULT 1,
    kept       TINYINT(1)      NOT NULL DEFAULT 0,                -- 1 = cleared all filters
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_session (session_id),
    KEY idx_name (name),
    CONSTRAINT fk_suggest_session FOREIGN KEY (session_id)
        REFERENCES suggest_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- LLM one-line summary at the top of a report.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS report_summaries (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(63)     NOT NULL,
    summary    VARCHAR(280)    NOT NULL,                          -- tweet-length cap
    model      VARCHAR(64)     NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_name (name)
) ENGINE=InnoDB;
