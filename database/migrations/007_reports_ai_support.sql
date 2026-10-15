-- ============================================================================
-- Daily reports, AI usage and the customer support assistant.
--
--   daily_reports          one row per Asia/Kolkata calendar day (UNIQUE report_date → a repeated run updates the row, never adds a second).
--                          `snapshot_json` holds the DETERMINISTIC numbers computed from the database; `ai_summary` is optional commentary
--                          from an AI provider, stored apart from the numbers so it can never overwrite them.
--   ai_usage_logs          every call to an AI provider: feature, provider, model, status, token counts, latency, error category.
--                          No prompt, no answer, no secret is stored here.
--   support_conversations  a customer chat (a random key in the customer's own browser; the visitor id when known). Rows past
--                          `expires_at` are deleted by the cleanup job (retention is configurable, default 30 days).
--   support_messages       what was said — kept short and only as long as the conversation exists.
--
-- Rollback (all four tables are new): DROP TABLE `support_messages`; DROP TABLE `support_conversations`; DROP TABLE `ai_usage_logs`;
--   DROP TABLE `daily_reports`; DELETE FROM `schema_migrations` WHERE `version` = '007_reports_ai_support';
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `daily_reports` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_date`       DATE         NOT NULL,                 -- the IST day the numbers describe
  `calc_version`      VARCHAR(12)  NOT NULL,
  `snapshot_json`     MEDIUMTEXT   NOT NULL,
  `generated_at`      DATETIME     NOT NULL,
  `generated_by`      VARCHAR(8)   NOT NULL DEFAULT 'cron',  -- cron | admin
  `regenerated_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `ai_status`         VARCHAR(12)  NOT NULL DEFAULT 'none',  -- none | ok | unavailable | invalid
  `ai_provider`       VARCHAR(24)  NULL,
  `ai_model`          VARCHAR(60)  NULL,
  `ai_summary`        TEXT NULL,
  `ai_generated_at`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_daily_reports_date` (`report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `feature`        VARCHAR(24)  NOT NULL,                    -- report | support | pricing
  `provider`       VARCHAR(24)  NOT NULL,
  `model`          VARCHAR(60)  NULL,
  `status`         VARCHAR(12)  NOT NULL,                    -- ok | error | invalid | limited
  `http_status`    SMALLINT UNSIGNED NULL,
  `input_tokens`   INT UNSIGNED NULL,
  `output_tokens`  INT UNSIGNED NULL,
  `latency_ms`     INT UNSIGNED NULL,
  `error_category` VARCHAR(16)  NULL,                        -- network | timeout | auth | rate_limit | server | format
  `created_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ai_usage_created` (`created_at`),
  KEY `idx_ai_usage_feature` (`feature`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_conversations` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_key`      CHAR(32)     NOT NULL,                   -- random, kept in the customer's browser
  `visitor_id`      BIGINT UNSIGNED NULL,
  `status`          VARCHAR(8)   NOT NULL DEFAULT 'open',
  `message_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `ai_messages`     INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`      DATETIME     NOT NULL,
  `last_message_at` DATETIME     NOT NULL,
  `expires_at`      DATETIME     NOT NULL,                   -- retention: the cleanup job deletes the conversation after this
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_conv_key` (`public_key`),
  KEY `idx_support_conv_last` (`last_message_at`),
  KEY `idx_support_conv_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `role`            VARCHAR(10)  NOT NULL,                   -- customer | assistant
  `body`            VARCHAR(1200) NOT NULL,
  `intent`          VARCHAR(24)  NULL,
  `order_id`        BIGINT UNSIGNED NULL,                    -- the order a verified answer was about
  `mode`            VARCHAR(8)   NOT NULL DEFAULT 'rules',   -- rules | ai
  `created_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_support_msg_conv` (`conversation_id`, `id`),
  CONSTRAINT `fk_support_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `support_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
