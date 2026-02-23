-- Upgrade v1.1: import questions by external_id (without package-based dedupe)
-- - Adds questions.external_id as unique business key
-- - Makes questions.package_id nullable for import
-- - Adds import metadata columns used by admin mapping import

ALTER TABLE questions
  MODIFY COLUMN package_id INT NULL;

ALTER TABLE questions
  ADD COLUMN IF NOT EXISTS external_id BIGINT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS theme VARCHAR(255) NULL AFTER text,
  ADD COLUMN IF NOT EXISTS category VARCHAR(255) NULL AFTER theme,
  ADD COLUMN IF NOT EXISTS profile VARCHAR(255) NULL AFTER category,
  ADD COLUMN IF NOT EXISTS knowledge_required_csv VARCHAR(64) NULL AFTER need,
  ADD COLUMN IF NOT EXISTS explanation TEXT NULL AFTER allow_skip,
  ADD COLUMN IF NOT EXISTS meta_json LONGTEXT NULL AFTER explanation,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

SET @has_uq_external_id := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'questions'
    AND index_name = 'uq_questions_external_id'
);
SET @sql_uq_external_id := IF(
  @has_uq_external_id = 0,
  'ALTER TABLE questions ADD UNIQUE KEY uq_questions_external_id (external_id)',
  'SELECT 1'
);
PREPARE stmt_uq_external_id FROM @sql_uq_external_id;
EXECUTE stmt_uq_external_id;
DEALLOCATE PREPARE stmt_uq_external_id;
