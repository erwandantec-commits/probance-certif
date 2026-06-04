CREATE TABLE IF NOT EXISTS program_package_links (
  program_id INT NOT NULL,
  package_id INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (program_id, package_id),
  KEY idx_program_package_links_package (package_id),
  KEY idx_program_package_links_program_active (program_id, is_active),
  CONSTRAINT fk_program_package_links_program
    FOREIGN KEY (program_id) REFERENCES programs(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_program_package_links_package
    FOREIGN KEY (package_id) REFERENCES packages(id)
    ON DELETE CASCADE
);

INSERT IGNORE INTO program_package_links (program_id, package_id, is_active)
SELECT program_id, id, is_active
FROM packages
WHERE program_id IS NOT NULL;
