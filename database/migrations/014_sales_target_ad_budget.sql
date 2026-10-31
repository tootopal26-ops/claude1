-- ============================================================================
-- Priority 3: Sales Target & Ad Budget Intelligence.
--
--   sales_targets   a periodic revenue goal (e.g. "₹50,000 this month") the admin sets,
--                   against which App\Services\SalesTarget computes real pacing (actual
--                   revenue so far, projected finish, required daily pace) from the same
--                   analytics_daily rollup the dashboard already uses — never a separate
--                   revenue computation. Append-only / audited like every other settings
--                   history table here: setting a new target that overlaps an existing
--                   one's period revokes the old one (`revoked_at`), it is never deleted
--                   or overwritten in place, so the history stays reconstructable.
--
-- ad_campaign_settings already exists (migration 009_adaptive_pricing) and has stayed
-- unused schema until now — App\Services\AdBudget starts writing to it: `kind` = 'default'
-- (the standing daily/monthly ad budget) or 'override' (a `period_start`..`period_end`
-- scoped budget, e.g. a sale). `settings_json` holds {daily_budget_paise, monthly_budget_paise}
-- (either may be null = no cap, matching today's uncapped behaviour exactly). No column
-- change needed there.
--
-- Rollback:
--   DROP TABLE `sales_targets`;
--   DELETE FROM schema_migrations WHERE version = '014_sales_target_ad_budget';
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `sales_targets` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_start` DATE         NOT NULL,
  `period_end`   DATE         NOT NULL,
  `target_paise` INT UNSIGNED NOT NULL,
  `note`         VARCHAR(255) NULL,
  `set_by`       INT UNSIGNED NULL,
  `created_at`   DATETIME     NOT NULL,
  `revoked_at`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sales_targets_period` (`period_start`, `period_end`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
