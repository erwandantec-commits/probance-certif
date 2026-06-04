ALTER TABLE packages
  ADD COLUMN IF NOT EXISTS anti_repeat_sessions INT NOT NULL DEFAULT 4 AFTER selection_count;
