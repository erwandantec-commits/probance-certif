ALTER TABLE packages
  ADD COLUMN IF NOT EXISTS name_color_hex CHAR(7) NULL AFTER name;
