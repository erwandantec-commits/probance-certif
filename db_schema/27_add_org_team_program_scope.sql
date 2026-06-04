CREATE TABLE IF NOT EXISTS organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  primary_domain VARCHAR(190) NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_organizations_slug (slug),
  UNIQUE KEY uq_organizations_primary_domain (primary_domain)
);

CREATE TABLE IF NOT EXISTS teams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_teams_org_slug (organization_id, slug),
  CONSTRAINT fk_teams_organization
    FOREIGN KEY (organization_id) REFERENCES organizations(id)
    ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS programs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  code VARCHAR(64) NULL,
  description TEXT NULL,
  source_lang VARCHAR(5) NOT NULL DEFAULT 'fr',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_programs_slug (slug),
  UNIQUE KEY uq_programs_code (code)
);

CREATE TABLE IF NOT EXISTS user_program_access (
  user_id INT NOT NULL,
  program_id INT NOT NULL,
  granted_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, program_id),
  CONSTRAINT fk_user_program_access_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_user_program_access_program
    FOREIGN KEY (program_id) REFERENCES programs(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_user_program_access_granted_by
    FOREIGN KEY (granted_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS team_manager_teams (
  manager_user_id INT NOT NULL,
  team_id INT NOT NULL,
  granted_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (manager_user_id, team_id),
  CONSTRAINT fk_team_manager_teams_manager
    FOREIGN KEY (manager_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_team_manager_teams_team
    FOREIGN KEY (team_id) REFERENCES teams(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_team_manager_teams_granted_by
    FOREIGN KEY (granted_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
);

INSERT INTO organizations (name, slug, primary_domain, is_internal)
SELECT 'Probance', 'probance', 'probance.com', 1
WHERE NOT EXISTS (
  SELECT 1 FROM organizations WHERE slug = 'probance'
);

INSERT INTO programs (name, slug, code, description, source_lang, is_default, is_active, display_order)
SELECT 'Produit Probance', 'produit-probance', 'PROBANCE_PRODUCT', 'Programme par defaut des certifications Probance', 'fr', 1, 1, 10
WHERE NOT EXISTS (
  SELECT 1 FROM programs WHERE slug = 'produit-probance'
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

DROP PROCEDURE IF EXISTS add_fk_if_missing;
DELIMITER $$
CREATE PROCEDURE add_fk_if_missing(
  IN p_table_name VARCHAR(64),
  IN p_constraint_name VARCHAR(64),
  IN p_foreign_key_sql TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table_name
      AND CONSTRAINT_NAME = p_constraint_name
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
  ) THEN
    SET @sql = CONCAT(
      'ALTER TABLE `', p_table_name, '` ADD CONSTRAINT `',
      p_constraint_name, '` ', p_foreign_key_sql
    );
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL add_column_if_missing('users', 'organization_id', 'organization_id INT NULL AFTER role');
CALL add_column_if_missing('users', 'team_id', 'team_id INT NULL AFTER organization_id');
CALL add_column_if_missing('packages', 'program_id', 'program_id INT NULL AFTER id');

ALTER TABLE users
  MODIFY COLUMN role ENUM('USER','PROGRAM_MANAGER','TEAM_MANAGER','OWNER','ADMIN') NOT NULL DEFAULT 'USER';

UPDATE users
SET role = 'OWNER'
WHERE role = 'PROGRAM_MANAGER';

UPDATE users
SET organization_id = (
  SELECT id FROM organizations WHERE slug = 'probance' LIMIT 1
)
WHERE organization_id IS NULL;

UPDATE packages
SET program_id = (
  SELECT id FROM programs WHERE slug = 'produit-probance' LIMIT 1
)
WHERE program_id IS NULL;

INSERT IGNORE INTO user_program_access (user_id, program_id)
SELECT u.id, p.id
FROM users u
JOIN programs p ON p.slug = 'produit-probance';

ALTER TABLE users
  MODIFY COLUMN role ENUM('USER','TEAM_MANAGER','OWNER','ADMIN') NOT NULL DEFAULT 'USER',
  MODIFY COLUMN organization_id INT NOT NULL;

ALTER TABLE packages
  MODIFY COLUMN program_id INT NOT NULL;

CALL add_fk_if_missing(
  'users',
  'fk_users_organization',
  'FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT'
);

CALL add_fk_if_missing(
  'users',
  'fk_users_team',
  'FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL'
);

CALL add_fk_if_missing(
  'packages',
  'fk_packages_program',
  'FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT'
);

DROP PROCEDURE IF EXISTS add_fk_if_missing;
DROP PROCEDURE IF EXISTS add_column_if_missing;
