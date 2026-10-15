-- ============================================================================
-- Operations and payment risk: scheduled-job history, business alerts, payment flags, order recovery fields.
--
--   job_runs          one row per run of a scheduled job (recovery, payments, health, daily-report, cleanup, pricing-…): when it started and
--                     finished, the result, and an error CATEGORY (never a stack trace). Shown in Admin → System / Watchdog.
--   business_alerts   what the Business Watchdog found. A problem that is still happening updates ONE alert (occurrences, last detected) instead of
--                     creating a new one each time: `active_key` holds the dedupe key while the alert is open or acknowledged and is NULL once it
--                     is resolved, so a unique index enforces "one live alert per problem" and a later recurrence opens a fresh alert.
--   payment_flags     suspicious-payment findings (amount / currency mismatch, unknown reference, duplicate bank reference, bad signatures,
--                     repeated failures, late payment, …). Same one-live-row-per-problem rule. A flag is a signal for a human, never a punishment.
--   orders.*          recovery bookkeeping: when the provider was last tried, whether the order is under automatic retry, needs review or was
--                     escalated, and by whom.
--
-- Nothing existing is changed or removed.
--
-- Rollback (the tables are new; the order columns are nullable):
--   DROP TABLE `payment_flags`; DROP TABLE `business_alerts`; DROP TABLE `job_runs`;
--   ALTER TABLE `orders` DROP COLUMN `escalated_by`, DROP COLUMN `escalated_at`, DROP COLUMN `recovery_status`, DROP COLUMN `last_attempt_at`;
--   DELETE FROM `schema_migrations` WHERE `version` = '006_operations_risk';
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `job_runs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job`            VARCHAR(40)  NOT NULL,
  `trigger_source` VARCHAR(8)   NOT NULL DEFAULT 'cron',     -- cron | admin | web
  `dry_run`        TINYINT(1)   NOT NULL DEFAULT 0,
  `status`         VARCHAR(10)  NOT NULL DEFAULT 'running',  -- running | ok | failed | timeout | abandoned
  `started_at`     DATETIME     NOT NULL,
  `finished_at`    DATETIME     NULL,
  `duration_ms`    INT UNSIGNED NULL,
  `result_json`    TEXT NULL,                                -- small counters only
  `error_category` VARCHAR(16)  NULL,                        -- database | network | timeout | logic
  `error_message`  VARCHAR(300) NULL,                        -- sanitised: no paths, no secrets
  PRIMARY KEY (`id`),
  KEY `idx_job_runs_job` (`job`, `started_at`),
  KEY `idx_job_runs_status` (`status`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_alerts` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_type`       VARCHAR(40)  NOT NULL,
  `severity`         VARCHAR(10)  NOT NULL DEFAULT 'warning', -- info | warning | critical
  `title`            VARCHAR(160) NOT NULL,
  `description`      VARCHAR(1000) NOT NULL,                  -- sanitised: numbers and ids, no secrets, no stack traces
  `entity_type`      VARCHAR(24)  NULL,
  `entity_id`        VARCHAR(40)  NULL,
  `dedupe_key`       VARCHAR(120) NOT NULL,
  `active_key`       VARCHAR(120) NULL,                       -- = dedupe_key while open / acknowledged, NULL when resolved
  `status`           VARCHAR(14)  NOT NULL DEFAULT 'open',    -- open | acknowledged | resolved
  `occurrences`      INT UNSIGNED NOT NULL DEFAULT 1,
  `first_detected_at` DATETIME    NOT NULL,
  `last_detected_at` DATETIME     NOT NULL,
  `acknowledged_by`  INT UNSIGNED NULL,
  `acknowledged_at`  DATETIME     NULL,
  `resolved_at`      DATETIME     NULL,
  `resolved_by`      INT UNSIGNED NULL,
  `resolution`       VARCHAR(8)   NULL,                       -- auto | admin
  `last_notified_at` DATETIME     NULL,
  `details_json`     TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alerts_active` (`active_key`),
  KEY `idx_alerts_status` (`status`, `severity`, `last_detected_at`),
  KEY `idx_alerts_type` (`alert_type`, `last_detected_at`),
  KEY `idx_alerts_dedupe` (`dedupe_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_flags` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flag_type`        VARCHAR(32)  NOT NULL,
  `severity`         VARCHAR(8)   NOT NULL DEFAULT 'medium',  -- low | medium | high
  `payment_id`       BIGINT UNSIGNED NULL,
  `order_id`         BIGINT UNSIGNED NULL,
  `gateway_id`       INT UNSIGNED NULL,
  `webhook_event_id` BIGINT UNSIGNED NULL,
  `dedupe_key`       VARCHAR(160) NOT NULL,
  `active_key`       VARCHAR(160) NULL,                       -- = dedupe_key while open, NULL once reviewed / dismissed / resolved
  `status`           VARCHAR(10)  NOT NULL DEFAULT 'open',    -- open | reviewed | dismissed | resolved
  `occurrences`      INT UNSIGNED NOT NULL DEFAULT 1,
  `first_seen_at`    DATETIME     NOT NULL,
  `last_seen_at`     DATETIME     NOT NULL,
  `details_json`     TEXT NULL,
  `reviewed_by`      INT UNSIGNED NULL,
  `reviewed_at`      DATETIME     NULL,
  `review_note`      VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_flags_active` (`active_key`),
  KEY `idx_payment_flags_status` (`status`, `severity`, `last_seen_at`),
  KEY `idx_payment_flags_payment` (`payment_id`),
  KEY `idx_payment_flags_order` (`order_id`),
  KEY `idx_payment_flags_type` (`flag_type`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `orders`
  ADD COLUMN `last_attempt_at` DATETIME NULL AFTER `next_attempt_at`,
  ADD COLUMN `recovery_status` VARCHAR(16) NULL AFTER `failure_kind`,     -- NULL | auto_retry | needs_review | escalated | resolved
  ADD COLUMN `escalated_at`    DATETIME NULL AFTER `recovery_status`,
  ADD COLUMN `escalated_by`    INT UNSIGNED NULL AFTER `escalated_at`,
  ADD KEY `idx_orders_recovery` (`recovery_status`, `status`);
