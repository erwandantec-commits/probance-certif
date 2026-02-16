-- Upgrade PR2: package selection mode + package rules
ALTER TABLE packages
  ADD COLUMN IF NOT EXISTS selection_mode ENUM('COUNT','PERCENT') NOT NULL DEFAULT 'COUNT' AFTER selection_count,
  ADD COLUMN IF NOT EXISTS selection_percent INT NULL AFTER selection_mode;

CREATE TABLE IF NOT EXISTS package_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  need ENUM('PONE','PHM','PPM') NOT NULL,
  min_level TINYINT NOT NULL,
  max_level TINYINT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_package_rules_package
    FOREIGN KEY (package_id) REFERENCES packages(id)
    ON DELETE CASCADE,

  INDEX idx_package_rules_package_active (package_id, is_active),
  INDEX idx_package_rules_need_level (need, min_level, max_level)
);
