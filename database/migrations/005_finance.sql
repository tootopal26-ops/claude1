-- ============================================================================
-- Finance: costs that were not recorded before, so profit stops assuming they are zero.
--
-- Until now a MANUAL order counted as 100 % gross profit and no payment fee, chargeback or other expense could be recorded. This adds:
--
--   services.fulfil_cost_paise   what delivering ONE purchase of a MANUAL service costs us directly (staff time, a supplier, ...).
--                                NULL = not stated yet  → profit for those orders is labelled "incomplete", never silently ₹0.
--                                0    = stated: it costs nothing directly.
--   order_costs                  per-order cost lines, APPEND-ONLY: a correction ends the old row (superseded_at) and adds a new one, so a
--                                later change of a service's cost never rewrites an old order. Types: fulfilment, payment_fee, other.
--                                The provider (SMM panel) cost stays where it is (orders.provider_cost_paise + provider_cost_final), so
--                                it can never be subtracted twice.
--   expenses                     business expenses and chargebacks by IST day (advertising lives in ad_daily_spend).
--
-- All money is integer paise. Nothing existing is changed or removed.
--
-- Rollback (only if nothing has been recorded yet; the tables are new):
--   DROP TABLE `expenses`; DROP TABLE `order_costs`; ALTER TABLE `services` DROP COLUMN `fulfil_cost_paise`;
--   DELETE FROM `schema_migrations` WHERE `version` = '005_finance';
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE `services`
  ADD COLUMN `fulfil_cost_paise` INT UNSIGNED NULL AFTER `smm_cost_paise`;

CREATE TABLE IF NOT EXISTS `order_costs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      BIGINT UNSIGNED NOT NULL,
  `cost_type`     VARCHAR(16)  NOT NULL,                 -- fulfilment | payment_fee | other
  `amount_paise`  INT UNSIGNED NOT NULL,
  `source`        VARCHAR(16)  NOT NULL,                 -- service_default | rate | admin
  `is_estimate`   TINYINT(1)   NOT NULL DEFAULT 1,       -- 1 = configured or estimated, 0 = a recorded actual
  `note`          VARCHAR(255) NULL,
  `recorded_by`   INT UNSIGNED NULL,
  `effective_at`  DATETIME     NOT NULL,
  `created_at`    DATETIME     NOT NULL,
  `superseded_at` DATETIME     NULL,                     -- set when a correction replaces this row; the row itself is never edited
  `active_key`    VARCHAR(40)  NULL,                     -- "<order>:<type>" while active (fulfilment, payment_fee), NULL otherwise → one live row per order and type
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_costs_active` (`active_key`),
  KEY `idx_order_costs_order` (`order_id`, `cost_type`),
  KEY `idx_order_costs_effective` (`effective_at`),
  CONSTRAINT `fk_order_costs_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_day`   DATE         NOT NULL,                 -- the Asia/Kolkata business day the cost belongs to
  `category`      VARCHAR(24)  NOT NULL,                 -- tools | hosting | payment_fee | chargeback | other
  `amount_paise`  INT UNSIGNED NOT NULL,
  `order_id`      BIGINT UNSIGNED NULL,                  -- a chargeback or an order-specific cost may name its order
  `description`   VARCHAR(255) NULL,
  `reference`     VARCHAR(120) NULL,
  `source`        VARCHAR(16)  NOT NULL DEFAULT 'manual',
  `created_by`    INT UNSIGNED NULL,
  `created_at`    DATETIME     NOT NULL,
  `voided_at`     DATETIME     NULL,                     -- a wrong entry is voided (with a reason), never deleted
  `voided_by`     INT UNSIGNED NULL,
  `void_reason`   VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_day` (`expense_day`, `category`),
  KEY `idx_expenses_order` (`order_id`),
  CONSTRAINT `fk_expenses_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
