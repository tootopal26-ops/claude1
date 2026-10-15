-- ============================================================================
-- Service tags & badges
--
-- A tag is a named, coloured pill (name, background colour, text colour, enabled flag) that an admin can put on any service card. The
-- storefront shows at most ONE per card: the automatic TRIAL PACK tag when the selling price is exactly 5.00 INR (see App\Services\Tags),
-- otherwise the tag assigned to the service, if that tag is enabled. Disabling a tag hides it without losing its assignments; deleting a custom
-- tag sets the assignment of every service that used it back to "no tag" (ON DELETE SET NULL).
--
--   service_tags    code      stable key of the 10 predefined tags (NULL for custom tags) — the automatic rule is bound to 'trial-pack',
--                             never to a name, so renaming the tag does not break it
--                   is_system predefined tags can be edited and disabled but not deleted
--   services.tag_id the assigned tag (NULL = no tag)
--
-- The old free-text columns services.badge / services.badge_color are no longer read. They stay in place (nothing is dropped), and their content is
-- converted below: text that names a predefined tag is assigned to it, any other text becomes a custom tag.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `service_tags` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(32)  NULL,
  `name`        VARCHAR(40)  NOT NULL,
  `bg_color`    CHAR(7)      NOT NULL,                       -- #RRGGBB, upper case
  `text_color`  CHAR(7)      NOT NULL DEFAULT '#FFFFFF',     -- #RRGGBB, upper case
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `is_system`   TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_tags_code` (`code`),
  UNIQUE KEY `uq_service_tags_name` (`name`),                -- case-insensitive (utf8mb4_unicode_ci): no two tags with the same name
  KEY `idx_service_tags_list` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `services`
  ADD COLUMN `tag_id` INT UNSIGNED NULL AFTER `badge_color`,
  ADD KEY `idx_services_tag` (`tag_id`),
  ADD CONSTRAINT `fk_services_tag` FOREIGN KEY (`tag_id`) REFERENCES `service_tags` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- The 10 predefined tags (white text). Keep in sync with App\Services\Tags::PREDEFINED (a test compares them).
INSERT IGNORE INTO `service_tags` (`code`, `name`, `bg_color`, `text_color`, `is_active`, `is_system`, `sort_order`, `created_at`, `updated_at`) VALUES
  ('trial-pack',    'TRIAL PACK',    '#DC2626', '#FFFFFF', 1, 1,  10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('best-seller',   'BEST SELLER',   '#7C3AED', '#FFFFFF', 1, 1,  20, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('popular',       'POPULAR',       '#D97706', '#FFFFFF', 1, 1,  30, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('best-value',    'BEST VALUE',    '#15803D', '#FFFFFF', 1, 1,  40, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('premium',       'PREMIUM',       '#0369A1', '#FFFFFF', 1, 1,  50, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('hot-deal',      'HOT DEAL',      '#BE185D', '#FFFFFF', 1, 1,  60, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('non-drop',      'NON DROP',      '#0F766E', '#FFFFFF', 1, 1,  70, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('instant',       'INSTANT',       '#4338CA', '#FFFFFF', 1, 1,  80, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('limited-offer', 'LIMITED OFFER', '#475569', '#FFFFFF', 1, 1,  90, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('vip',           'VIP',           '#A16207', '#FFFFFF', 1, 1, 100, UTC_TIMESTAMP(), UTC_TIMESTAMP());

-- Old free-text badges that are not one of the predefined names become custom tags (one per distinct text, whatever its case), coloured like the
-- old colour name of the first service that used the text.
INSERT INTO `service_tags` (`name`, `bg_color`, `text_color`, `is_active`, `is_system`, `sort_order`, `created_at`, `updated_at`)
SELECT x.name, x.bg_color, '#FFFFFF', 1, 0, 1000, UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM (
        SELECT MIN(TRIM(s.badge)) AS name,
               CASE SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(s.badge_color, 'red') ORDER BY s.id SEPARATOR ','), ',', 1)
                 WHEN 'green'  THEN '#15803D' WHEN 'blue'  THEN '#1D4ED8' WHEN 'amber' THEN '#B45309' WHEN 'pink' THEN '#C40D5B'
                 WHEN 'purple' THEN '#6D28D9' WHEN 'gray'  THEN '#3F4257' ELSE '#DC2626'
               END AS bg_color
          FROM `services` s
         WHERE s.badge IS NOT NULL AND TRIM(s.badge) <> ''
         GROUP BY UPPER(TRIM(s.badge))
       ) x
 WHERE NOT EXISTS (SELECT 1 FROM `service_tags` t WHERE t.name = x.name);

-- Assign every service to the tag that carries its old badge text (the automatic TRIAL PACK is never assigned by hand).
UPDATE `services` s
  JOIN `service_tags` t ON t.name = TRIM(s.badge)
   SET s.tag_id = t.id
 WHERE s.tag_id IS NULL AND s.badge IS NOT NULL AND TRIM(s.badge) <> '' AND (t.code IS NULL OR t.code <> 'trial-pack');
