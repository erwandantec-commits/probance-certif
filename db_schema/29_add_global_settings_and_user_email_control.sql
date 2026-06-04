CREATE TABLE IF NOT EXISTS global_settings (
  setting_key VARCHAR(190) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by_user_id INT NULL,
  CONSTRAINT fk_global_settings_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
);

DROP PROCEDURE IF EXISTS add_column_if_missing;
DELIMITER $$
CREATE PROCEDURE add_column_if_missing(
  IN p_table_name VARCHAR(64),
  IN p_column_name VARCHAR(64),
  IN p_column_definition TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table_name
      AND COLUMN_NAME = p_column_name
  ) THEN
    SET @sql = CONCAT(
      'ALTER TABLE `', p_table_name, '` ADD COLUMN ',
      p_column_definition
    );
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL add_column_if_missing(
  'users',
  'email_control_bypass',
  'email_control_bypass TINYINT(1) NOT NULL DEFAULT 0 AFTER team_id'
);

INSERT INTO global_settings (setting_key, setting_value)
VALUES ('user_email_control_enabled', '1')
ON DUPLICATE KEY UPDATE setting_value = COALESCE(setting_value, '1');

DROP PROCEDURE IF EXISTS add_column_if_missing;
