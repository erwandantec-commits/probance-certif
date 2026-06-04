ALTER TABLE packages
  ADD COLUMN IF NOT EXISTS badge_image_filename VARCHAR(255) NULL AFTER display_order;

UPDATE packages
SET badge_image_filename = CASE UPPER(TRIM(name))
  WHEN 'GREEN' THEN 'user-badge-green.png'
  WHEN 'BLUE' THEN 'user-badge-blue.png'
  WHEN 'RED' THEN 'user-badge-red.png'
  WHEN 'BLACK' THEN 'user-badge-black.png'
  WHEN 'SILVER' THEN 'user-badge-silver.png'
  WHEN 'GOLD' THEN 'user-badge-gold.png'
  WHEN 'VERMEIL' THEN 'user-badge-gold.png'
  ELSE badge_image_filename
END
WHERE COALESCE(TRIM(badge_image_filename), '') = '';
