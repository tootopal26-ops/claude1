-- ============================================================================
-- Ads Intelligence: which campaign a visitor-day belongs to.
--
-- visitor_days is the unit the CPC estimate is billed on (one row per visitor per business day) and the unit the dashboard counts visitors in. To
-- split visitors, qualified visitors, checkout starts and the estimated spend BY CAMPAIGN so that the campaign rows add up exactly to the dashboard's
-- totals, every visitor-day needs one campaign key:
--
--   visitor_days.utm_campaign   the campaign tag of the FIRST campaign-tagged session the visitor had that business day (NULL = none: the visitor
--                               came directly, from a site, or without a tag). Written by the visitor tracker; never changed afterwards.
--
-- Existing rows are filled in best effort from the session rows that still exist (the first tagged session of the same visitor on the same day).
-- Rows whose sessions were already purged stay NULL = "no campaign": older history is not invented.
--
-- Rollback: ALTER TABLE `visitor_days` DROP KEY `idx_vdays_campaign`, DROP COLUMN `utm_campaign`;
--   DELETE FROM `schema_migrations` WHERE `version` = '012_visitor_day_campaign';
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE `visitor_days`
  ADD COLUMN `utm_campaign` VARCHAR(80) NULL AFTER `device_type`,
  ADD KEY `idx_vdays_campaign` (`day`, `utm_campaign`);

UPDATE `visitor_days` vd
   SET vd.`utm_campaign` = (
         SELECT s.`utm_campaign` FROM `visitor_sessions` s
          WHERE s.`visitor_id` = vd.`visitor_id` AND s.`day` = vd.`day` AND s.`utm_campaign` IS NOT NULL
          ORDER BY s.`started_at`, s.`id` LIMIT 1)
 WHERE vd.`utm_campaign` IS NULL;
