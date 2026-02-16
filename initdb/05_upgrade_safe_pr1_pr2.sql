-- Safe upgrade pack: PR1 + PR2
-- Compatible with existing environments (idempotent statements).

-- PR1: sessions metadata
ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS language CHAR(2) NULL AFTER session_type,
  ADD COLUMN IF NOT EXISTS ended_at DATETIME NULL AFTER started_at,
  ADD COLUMN IF NOT EXISTS termination_type ENUM('MANUAL','TIMEOUT') NULL AFTER submitted_at;

-- PR2: package selection mode
ALTER TABLE packages
  ADD COLUMN IF NOT EXISTS selection_mode ENUM('COUNT','PERCENT') NOT NULL DEFAULT 'COUNT' AFTER selection_count,
  ADD COLUMN IF NOT EXISTS selection_percent INT NULL AFTER selection_mode;

-- PR2: package rules table
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
