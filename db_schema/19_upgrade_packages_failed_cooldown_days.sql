ALTER TABLE packages
  ADD COLUMN IF NOT EXISTS failed_cooldown_days INT NOT NULL DEFAULT 0 AFTER cert_validity_days;
