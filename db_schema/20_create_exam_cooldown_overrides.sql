CREATE TABLE IF NOT EXISTS exam_cooldown_overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  package_id INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  reason VARCHAR(255) NULL,
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME NULL,
  expires_at DATETIME NULL,

  INDEX idx_eco_lookup (user_id, package_id, is_active, used_at, expires_at, created_at),

  CONSTRAINT fk_eco_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_eco_package
    FOREIGN KEY (package_id) REFERENCES packages(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_eco_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
);
