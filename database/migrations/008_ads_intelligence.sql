-- ============================================================================
-- Ads Intelligence: more precise visitor measurement, ACTUAL ad spend, and campaign attribution of verified orders.
--
--   visitor_sessions.*   utm_content / utm_term; page_views (page loads in the session, counted from this migration on — older rows are 0 =
--                        "not measured"); service_views (a service card was opened); engaged_at (stayed the qualifying time AND opened a
--                        service); returning_visitor (the visitor had an earlier session).
--   ad_daily_spend       what was actually spent (entered by an admin or imported), per IST day and campaign, pre-tax amount and GST apart.
--                        The CPC-based figure (qualified visitors × CPC, visitor_days.cpc_paise) is an ESTIMATE and stays where it is; for a
--                        day that has actual spend the estimate is NOT added on top (see App\Services\AdSpend).
--   campaign_attribution one row per verified order: which campaign gets the credit, under which model and window, and when the touch happened.
--                        Written once when the payment is verified; never overwritten by anything the browser sends afterwards.
--
-- Rollback: DROP TABLE `campaign_attribution`; DROP TABLE `ad_daily_spend`;
--   ALTER TABLE `visitor_sessions` DROP COLUMN `returning_visitor`, DROP COLUMN `engaged_at`, DROP COLUMN `service_views`, DROP COLUMN `page_views`,
--   DROP COLUMN `utm_term`, DROP COLUMN `utm_content`; DELETE FROM `schema_migrations` WHERE `version` = '008_ads_intelligence';
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE `visitor_sessions`
  ADD COLUMN `utm_content`       VARCHAR(80) NULL AFTER `utm_campaign`,
  ADD COLUMN `utm_term`          VARCHAR(80) NULL AFTER `utm_content`,
  ADD COLUMN `page_views`        SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `active_seconds`,
  ADD COLUMN `service_views`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `page_views`,
  ADD COLUMN `engaged_at`        DATETIME NULL AFTER `qualified_at`,
  ADD COLUMN `returning_visitor` TINYINT(1) NOT NULL DEFAULT 0 AFTER `engaged_at`,
  ADD KEY `idx_vsessions_campaign` (`utm_campaign`, `started_at`);

CREATE TABLE IF NOT EXISTS `ad_daily_spend` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `spend_day`    DATE         NOT NULL,                      -- the Asia/Kolkata day the spend belongs to
  `campaign`     VARCHAR(80)  NOT NULL DEFAULT '',           -- '' = not split by campaign (the day's total)
  `platform`     VARCHAR(24)  NOT NULL DEFAULT '',           -- the advertising platform (free text, e.g. meta, google); '' = unspecified
  `amount_paise` BIGINT UNSIGNED NOT NULL,                   -- pre-tax spend
  `gst_paise`    BIGINT UNSIGNED NOT NULL DEFAULT 0,         -- the tax component, kept apart (its accounting treatment is a setting)
  `currency`     CHAR(3)      NOT NULL DEFAULT 'INR',
  `clicks`       INT UNSIGNED NULL,                          -- billable clicks the platform reported, when known
  `impressions`  INT UNSIGNED NULL,
  `source`       VARCHAR(8)   NOT NULL DEFAULT 'manual',     -- manual | import
  `note`         VARCHAR(255) NULL,
  `entered_by`   INT UNSIGNED NULL,
  `imported_at`  DATETIME     NOT NULL,
  `voided_at`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ad_spend_day` (`spend_day`, `campaign`, `platform`),
  KEY `idx_ad_spend_campaign` (`campaign`, `spend_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `campaign_attribution` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`           BIGINT UNSIGNED NOT NULL,
  `visitor_session_id` BIGINT UNSIGNED NULL,
  `model`              VARCHAR(24)  NOT NULL,                -- last_non_direct
  `window_days`        TINYINT UNSIGNED NOT NULL,
  `touch_kind`         VARCHAR(10)  NOT NULL,                -- campaign | source | direct
  `utm_source`         VARCHAR(80)  NULL,
  `utm_medium`         VARCHAR(80)  NULL,
  `utm_campaign`       VARCHAR(80)  NULL,
  `utm_content`        VARCHAR(80)  NULL,
  `utm_term`           VARCHAR(80)  NULL,
  `source`             VARCHAR(16)  NOT NULL DEFAULT 'direct',
  `touch_at`           DATETIME     NULL,
  `attributed_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attribution_order` (`order_id`),
  KEY `idx_attribution_campaign` (`utm_campaign`, `attributed_at`),
  CONSTRAINT `fk_attribution_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
