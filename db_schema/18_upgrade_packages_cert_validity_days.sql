ALTER TABLE packages
  ADD COLUMN IF NOT EXISTS cert_validity_days INT NOT NULL DEFAULT 365 AFTER pass_threshold_percent;
