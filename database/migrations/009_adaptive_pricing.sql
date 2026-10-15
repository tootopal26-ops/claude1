-- ============================================================================
-- Adaptive pricing: price versions, controlled experiments, stable cohort assignments, decision and price audit logs.
--
-- Principles encoded here:
--   * `services.price_paise` stays THE default (control) price and the source of truth. An experiment shows a candidate price only to its
--     treatment cohort; a winning candidate becomes the default only through an audited, admin-approved change.
--   * Every order keeps the exact price it was sold at (`orders.amount_paise`, as before) plus which price version / experiment / cohort it was
--     sold under, so an old order is never re-priced and results can always be traced to real orders.
--   * An assignment key is a keyed hash of a first-party random cookie — never an IP address, never a name — and expires.
--   * Nothing here changes a price by itself: the engine starts in dry-run, a service must be switched on (`adaptive_enabled`), AUTO (API) services
--     are excluded unless an admin opts in, and a global pause + per-service pause exist (`pricing_paused`, setting `pricing.global_pause`).
--
--   ad_campaign_settings        append-only history of the advertising-intelligence settings: the DEFAULT row, and daily OVERRIDEs with an end day
--   service_price_versions      every price a service has been sold at (default / control / candidate / snapshot taken before a change)
--   pricing_experiments         one controlled experiment: control vs candidate price, policy snapshot, status, decision, metrics
--   pricing_experiment_cohorts  per-cohort rollups written by the evaluator (live counts are always re-derivable from orders)
--   pricing_assignments         which cohort a pseudonymous visitor is in, and the price it was shown
--   pricing_decision_logs       every evaluation (also dry runs) with its metrics and reason
--   price_audit_logs            every change of a service's price, from any source
--
-- Rollback (all tables are new; the columns are nullable / default off):
--   DROP TABLE price_audit_logs, pricing_decision_logs, pricing_assignments, pricing_experiment_cohorts, pricing_experiments,
--     service_price_versions, ad_campaign_settings;  (drop the foreign keys of orders first if you added experiment rows)
--   ALTER TABLE orders DROP COLUMN price_cohort, DROP COLUMN experiment_id, DROP COLUMN price_version_id;
--   ALTER TABLE visitor_sessions DROP COLUMN platform_pref;
--   ALTER TABLE services DROP COLUMN pricing_paused, DROP COLUMN adaptive_max_paise, DROP COLUMN adaptive_min_paise, DROP COLUMN adaptive_enabled;
--   DELETE FROM schema_migrations WHERE version = '009_adaptive_pricing';
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE `services`
  ADD COLUMN `adaptive_enabled`   TINYINT(1)   NOT NULL DEFAULT 0 AFTER `fulfil_cost_paise`,
  ADD COLUMN `adaptive_min_paise` INT UNSIGNED NULL AFTER `adaptive_enabled`,
  ADD COLUMN `adaptive_max_paise` INT UNSIGNED NULL AFTER `adaptive_min_paise`,
  ADD COLUMN `pricing_paused`     TINYINT(1)   NOT NULL DEFAULT 0 AFTER `adaptive_max_paise`;

ALTER TABLE `visitor_sessions`
  ADD COLUMN `platform_pref` VARCHAR(16) NULL AFTER `platform_viewed`;      -- the platform the visitor chose in the first-visit popup (analytics only)

CREATE TABLE IF NOT EXISTS `ad_campaign_settings` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`          VARCHAR(10)  NOT NULL,                     -- default | override
  `period_start`  DATE         NULL,                         -- override only
  `period_end`    DATE         NULL,                         -- override only: the last IST day it applies; it expires after that
  `settings_json` TEXT         NOT NULL,
  `note`          VARCHAR(255) NULL,
  `set_by`        INT UNSIGNED NULL,
  `created_at`    DATETIME     NOT NULL,
  `revoked_at`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ad_campaign_kind` (`kind`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_price_versions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id`    INT UNSIGNED NOT NULL,
  `price_paise`   INT UNSIGNED NOT NULL,
  `kind`          VARCHAR(10)  NOT NULL,                     -- default | control | candidate | snapshot
  `experiment_id` BIGINT UNSIGNED NULL,
  `note`          VARCHAR(255) NULL,
  `created_by`    INT UNSIGNED NULL,
  `created_at`    DATETIME     NOT NULL,
  `retired_at`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_price_versions_service` (`service_id`, `created_at`),
  KEY `idx_price_versions_experiment` (`experiment_id`),
  CONSTRAINT `fk_price_versions_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pricing_experiments` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id`            INT UNSIGNED NOT NULL,
  `status`                VARCHAR(12)  NOT NULL DEFAULT 'proposed',   -- proposed | approved | running | paused | concluded | rolled_back | cancelled
  `mode`                  VARCHAR(8)   NOT NULL DEFAULT 'dry_run',    -- dry_run | live
  `control_paise`         INT UNSIGNED NOT NULL,
  `candidate_paise`       INT UNSIGNED NOT NULL,
  `control_version_id`    BIGINT UNSIGNED NULL,
  `candidate_version_id`  BIGINT UNSIGNED NULL,
  `treatment_share_bps`   SMALLINT UNSIGNED NOT NULL DEFAULT 5000,    -- share of visitors who see the candidate price (5000 = 50 %)
  `min_sample_per_cohort` INT UNSIGNED NOT NULL,
  `max_duration_days`     SMALLINT UNSIGNED NOT NULL,
  `profit_floor_paise`    INT NULL,                                   -- minimum contribution per order at the candidate price (policy snapshot)
  `decision`              VARCHAR(18)  NULL,                          -- winner | loser | inconclusive | insufficient_data | rolled_back
  `decision_reason`       VARCHAR(500) NULL,
  `confidence_bps`        SMALLINT UNSIGNED NULL,                     -- confidence of the last evaluation, in basis points
  `metrics_json`          MEDIUMTEXT   NULL,
  `last_evaluated_at`     DATETIME     NULL,
  `approved_by`           INT UNSIGNED NULL,
  `approved_at`           DATETIME     NULL,
  `started_at`            DATETIME     NULL,
  `ends_at`               DATETIME     NULL,
  `ended_at`              DATETIME     NULL,
  `rolled_back_at`        DATETIME     NULL,
  `rollback_reason`       VARCHAR(255) NULL,
  `cooldown_until`        DATETIME     NULL,
  `created_by`            INT UNSIGNED NULL,
  `created_at`            DATETIME     NOT NULL,
  `updated_at`            DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_experiments_service` (`service_id`, `status`),
  KEY `idx_experiments_status` (`status`, `last_evaluated_at`),
  CONSTRAINT `fk_experiments_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pricing_experiment_cohorts` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `experiment_id`      BIGINT UNSIGNED NOT NULL,
  `cohort`             VARCHAR(10)  NOT NULL,                -- control | treatment
  `price_version_id`   BIGINT UNSIGNED NULL,
  `price_paise`        INT UNSIGNED NOT NULL,
  `assigned`           INT UNSIGNED NOT NULL DEFAULT 0,
  `qualified`          INT UNSIGNED NOT NULL DEFAULT 0,
  `service_views`      INT UNSIGNED NOT NULL DEFAULT 0,
  `checkout_starts`    INT UNSIGNED NOT NULL DEFAULT 0,
  `purchases`          INT UNSIGNED NOT NULL DEFAULT 0,
  `revenue_paise`      BIGINT       NOT NULL DEFAULT 0,
  `contribution_paise` BIGINT       NOT NULL DEFAULT 0,
  `computed_at`        DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_experiment_cohort` (`experiment_id`, `cohort`),
  CONSTRAINT `fk_cohorts_experiment` FOREIGN KEY (`experiment_id`) REFERENCES `pricing_experiments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pricing_assignments` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `experiment_id`    BIGINT UNSIGNED NOT NULL,
  `assignment_key`   CHAR(32)     NOT NULL,                  -- keyed hash of the first-party cookie: pseudonymous, never an IP address
  `cohort`           VARCHAR(10)  NOT NULL,
  `price_version_id` BIGINT UNSIGNED NULL,
  `price_paise`      INT UNSIGNED NOT NULL,                  -- the price this visitor is shown while the assignment lives
  `platform_pref`    VARCHAR(16)  NULL,
  `consent_state`    VARCHAR(12)  NOT NULL DEFAULT 'implied',
  `assigned_at`      DATETIME     NOT NULL,
  `expires_at`       DATETIME     NOT NULL,
  `qualified_at`     DATETIME     NULL,                      -- the same 3-second rule as visitors
  `service_viewed_at` DATETIME    NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment` (`experiment_id`, `assignment_key`),
  KEY `idx_assignment_expires` (`expires_at`),
  KEY `idx_assignment_cohort` (`experiment_id`, `cohort`),
  CONSTRAINT `fk_assignments_experiment` FOREIGN KEY (`experiment_id`) REFERENCES `pricing_experiments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pricing_decision_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `experiment_id` BIGINT UNSIGNED NULL,
  `service_id`    INT UNSIGNED NULL,
  `decision`      VARCHAR(24)  NOT NULL,
  `dry_run`       TINYINT(1)   NOT NULL DEFAULT 1,
  `reason`        VARCHAR(500) NULL,
  `metrics_json`  MEDIUMTEXT   NULL,
  `actor`         VARCHAR(8)   NOT NULL DEFAULT 'cron',      -- cron | admin | system
  `created_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_decision_experiment` (`experiment_id`, `created_at`),
  KEY `idx_decision_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `price_audit_logs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id`       INT UNSIGNED NOT NULL,
  `old_price_paise`  INT UNSIGNED NULL,
  `new_price_paise`  INT UNSIGNED NOT NULL,
  `reason`           VARCHAR(255) NULL,
  `actor_type`       VARCHAR(10)  NOT NULL,                  -- admin | experiment | rollback | system
  `actor_id`         INT UNSIGNED NULL,
  `experiment_id`    BIGINT UNSIGNED NULL,
  `price_version_id` BIGINT UNSIGNED NULL,
  `created_at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_price_audit_service` (`service_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `orders`
  ADD COLUMN `price_version_id` BIGINT UNSIGNED NULL AFTER `amount_paise`,
  ADD COLUMN `experiment_id`    BIGINT UNSIGNED NULL AFTER `price_version_id`,
  ADD COLUMN `price_cohort`     VARCHAR(10) NULL AFTER `experiment_id`,
  ADD KEY `idx_orders_experiment` (`experiment_id`, `price_cohort`);
