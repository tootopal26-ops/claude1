-- ============================================================================
-- Profit Intelligence: the daily roll-up learns the costs that migration 005 made recordable.
--
-- analytics_daily is the persisted copy of App\Services\Analytics::computeDays (one SQL implementation feeds the live dashboard and the stored
-- rows, so they cannot disagree). Until now a day knew revenue, provider (SMM) cost and advertising only. It now also keeps, per business day
-- (the day the payment was verified, Asia/Kolkata):
--
--   fulfil_cost_paise / payment_fee_paise / other_cost_paise   the order cost lines (order_costs) of the orders paid that day
--   chargebacks_paise                                          chargebacks recorded that day (expenses.category = chargeback)
--   expenses_paise                                             other business expenses recorded that day (tools, hosting, other)
--   ads_actual_paise / ads_estimated_paise / ads_actual_day    advertising: what was actually spent, what the CPC model estimates, and which
--                                                              of the two ads_spend_paise carries (actual replaces the estimate for a day that has
--                                                              actual spend — the two are never added together)
--   orders_cost_estimated / orders_cost_incomplete             how many orders paid that day have an estimated / an unknown cost
--
-- Nothing is lost: existing columns keep their names; for a day with no cost lines, no expenses and no actual ad spend every figure is exactly what it
-- was before. Every stored day is marked dirty so it is recomputed with the new columns the next time it is read.
--
-- Rollback (only needed to go back to the previous release; the columns are derived data):
--   ALTER TABLE `analytics_daily` DROP COLUMN `orders_cost_incomplete`, DROP COLUMN `orders_cost_estimated`, DROP COLUMN `ads_actual_day`,
--     DROP COLUMN `ads_estimated_paise`, DROP COLUMN `ads_actual_paise`, DROP COLUMN `expenses_paise`, DROP COLUMN `chargebacks_paise`,
--     DROP COLUMN `other_cost_paise`, DROP COLUMN `payment_fee_paise`, DROP COLUMN `fulfil_cost_paise`;
--   DELETE FROM `schema_migrations` WHERE `version` = '011_finance_rollups';
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE `analytics_daily`
  ADD COLUMN `fulfil_cost_paise`      BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `smm_spend_paise`,
  ADD COLUMN `payment_fee_paise`      BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `fulfil_cost_paise`,
  ADD COLUMN `other_cost_paise`       BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `payment_fee_paise`,
  ADD COLUMN `chargebacks_paise`      BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `other_cost_paise`,
  ADD COLUMN `expenses_paise`         BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `chargebacks_paise`,
  ADD COLUMN `ads_actual_paise`       BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `ads_spend_paise`,
  ADD COLUMN `ads_estimated_paise`    BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `ads_actual_paise`,
  ADD COLUMN `ads_actual_day`         TINYINT(1) NOT NULL DEFAULT 0 AFTER `ads_estimated_paise`,
  ADD COLUMN `orders_cost_estimated`  INT UNSIGNED NOT NULL DEFAULT 0 AFTER `orders_completed`,
  ADD COLUMN `orders_cost_incomplete` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `orders_cost_estimated`;

UPDATE `analytics_daily` SET `dirty` = 1;
