-- ============================================================================
-- Centralized order calculation: an explicit, per-service pricing-unit model so a
-- customer's requested quantity and the provider's billing unit are never confused
-- with each other or with the rupee price — see App\Services\OrderCalculator.
--
-- Pricing models (services.pricing_model):
--   fixed_package  one pack of `quantity` units for `price_paise`. The customer cannot
--                   change how many are bought — today's existing, unchanged behaviour.
--   per_unit        `price_paise` buys `pricing_unit_qty` units; the customer may request
--                   any positive whole multiple of `pricing_unit_qty` (this also covers
--                   "buy N packs of a fixed size" — the maths is identical).
--
-- Provider quantity: `services.provider_qty_divisor` converts the customer-facing unit
-- count into whatever unit the SMM provider bills in (normally 1 — same units). If the
-- requested quantity does not divide evenly by it, OrderCalculator REJECTS the order
-- rather than rounding, and the service is flagged for admin review.
--
-- `services.config_version` is bumped whenever a pricing-relevant field changes
-- (price, quantity, min/max, pricing model, unit size, provider divisor); every order
-- snapshots the version it was calculated against, so a later edit to a service can
-- never silently re-price or re-interpret an order that has already been placed.
--
-- Rollback:
--   ALTER TABLE `orders` DROP COLUMN `provider_quantity`, DROP COLUMN `pricing_model`, DROP COLUMN `config_version`;
--   ALTER TABLE `services` DROP COLUMN `pricing_model`, DROP COLUMN `pricing_unit_qty`, DROP COLUMN `provider_qty_divisor`, DROP COLUMN `config_version`;
--   DELETE FROM schema_migrations WHERE version = '013_order_calculator';
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE `services`
  ADD COLUMN `pricing_model`        VARCHAR(16)  NOT NULL DEFAULT 'fixed_package' AFTER `quantity`,
  ADD COLUMN `pricing_unit_qty`     INT UNSIGNED NULL AFTER `pricing_model`,
  ADD COLUMN `provider_qty_divisor` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `smm_cost_paise`,
  ADD COLUMN `config_version`       INT UNSIGNED NOT NULL DEFAULT 1 AFTER `updated_at`;

ALTER TABLE `orders`
  ADD COLUMN `provider_quantity` INT UNSIGNED NULL AFTER `quantity`,                       -- exact quantity submitted to the provider; NULL until computed
  ADD COLUMN `pricing_model`     VARCHAR(16)  NOT NULL DEFAULT 'fixed_package' AFTER `provider_quantity`,
  ADD COLUMN `config_version`    INT UNSIGNED NOT NULL DEFAULT 1 AFTER `pricing_model`;    -- services.config_version this order was validated against
