-- ============================================================================
-- TRIAL PACK is now automatic.
--
-- The storefront shows the red TRIAL PACK badge on every service whose selling price is exactly 5.00 INR (see App\Services\Catalog::isTrial)
-- and takes it away as soon as the price changes. The badge is derived from the price on every render, so a stored copy of the same text
-- is redundant, and worse: it would keep showing on a service after its price is changed. Clear it. Any other badge text is left alone.
-- ============================================================================

UPDATE `services`
   SET `badge` = NULL, `badge_color` = NULL
 WHERE UPPER(TRIM(`badge`)) = 'TRIAL PACK';
