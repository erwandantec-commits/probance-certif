ALTER TABLE packages
  ALTER COLUMN failed_cooldown_days SET DEFAULT 365;

UPDATE packages
SET failed_cooldown_days = 365
WHERE failed_cooldown_days = 0;
