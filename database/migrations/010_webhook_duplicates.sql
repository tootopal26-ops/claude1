-- ============================================================================
-- Webhook deliveries: remember WHEN the last repeated delivery of an event arrived.
--
-- `webhook_events` already holds one row per event (unique `dedupe_key`) and `attempts` counts every delivery of it, so a repeated delivery is
-- acknowledged, not processed again, and is visible as attempts > 1. What was missing for the audit trail ("keep a record of ignored
-- duplicates") is when the most recent repeat came in; the payment-checks screen shows it next to the delivery count.
--
-- Additive and nullable: nothing existing changes.
--
-- Rollback:
--   ALTER TABLE `webhook_events` DROP COLUMN `last_attempt_at`;
--   DELETE FROM `schema_migrations` WHERE `version` = '010_webhook_duplicates';
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE `webhook_events`
  ADD COLUMN `last_attempt_at` DATETIME NULL AFTER `attempts`;
