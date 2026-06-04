-- Ensure program question banks are isolated.
-- A question row may belong to one program only. If an existing question is
-- linked to several programs, keep the lowest program_id on the original row
-- and clone the question/options/translations for the other programs.

CREATE TABLE IF NOT EXISTS question_translations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  lang VARCHAR(5) NOT NULL,
  question_text TEXT NOT NULL,
  explanation TEXT NULL,
  source_updated_at DATETIME NULL,
  status_override VARCHAR(16) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_question_lang (question_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_option_translations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  option_id INT NOT NULL,
  lang VARCHAR(5) NOT NULL,
  option_text TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_option_lang (option_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE question_translations
  ADD COLUMN IF NOT EXISTS source_updated_at DATETIME NULL AFTER explanation,
  ADD COLUMN IF NOT EXISTS status_override VARCHAR(16) NULL AFTER source_updated_at;

SET @has_uq_external_id := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name = 'questions'
    AND index_name = 'uq_questions_external_id'
);
SET @sql_drop_uq_external_id := IF(
  @has_uq_external_id > 0,
  'ALTER TABLE questions DROP INDEX uq_questions_external_id',
  'DO 0'
);
PREPARE stmt_drop_uq_external_id FROM @sql_drop_uq_external_id;
EXECUTE stmt_drop_uq_external_id;
DEALLOCATE PREPARE stmt_drop_uq_external_id;

SET @has_idx_external_id := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name = 'questions'
    AND index_name = 'idx_questions_external_id'
);
SET @sql_idx_external_id := IF(
  @has_idx_external_id = 0,
  'ALTER TABLE questions ADD KEY idx_questions_external_id (external_id)',
  'DO 0'
);
PREPARE stmt_idx_external_id FROM @sql_idx_external_id;
EXECUTE stmt_idx_external_id;
DEALLOCATE PREPARE stmt_idx_external_id;

DROP PROCEDURE IF EXISTS isolate_shared_program_questions;
DELIMITER //
CREATE PROCEDURE isolate_shared_program_questions()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_program_id INT;
  DECLARE v_old_question_id INT;
  DECLARE v_new_question_id INT;

  DECLARE shared_cursor CURSOR FOR
    SELECT pql.program_id, pql.question_id
    FROM program_question_links pql
    JOIN (
      SELECT question_id, MIN(program_id) AS keep_program_id, COUNT(*) AS link_count
      FROM program_question_links
      GROUP BY question_id
      HAVING link_count > 1
    ) shared ON shared.question_id = pql.question_id
    WHERE pql.program_id <> shared.keep_program_id
    ORDER BY pql.question_id ASC, pql.program_id ASC;

  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN shared_cursor;
  read_loop: LOOP
    FETCH shared_cursor INTO v_program_id, v_old_question_id;
    IF done = 1 THEN
      LEAVE read_loop;
    END IF;

    INSERT INTO questions(
      external_id,
      package_id,
      text,
      theme,
      category,
      profile,
      need,
      knowledge_required_csv,
      level,
      question_type,
      allow_skip,
      open_to_client,
      explanation,
      meta_json,
      created_at,
      updated_at
    )
    SELECT
      external_id,
      package_id,
      text,
      theme,
      category,
      profile,
      need,
      knowledge_required_csv,
      level,
      question_type,
      allow_skip,
      open_to_client,
      explanation,
      meta_json,
      created_at,
      updated_at
    FROM questions
    WHERE id = v_old_question_id;

    SET v_new_question_id = LAST_INSERT_ID();

    INSERT INTO question_options(
      question_id,
      label,
      option_text,
      is_correct,
      score_value,
      created_at
    )
    SELECT
      v_new_question_id,
      label,
      option_text,
      is_correct,
      score_value,
      created_at
    FROM question_options
    WHERE question_id = v_old_question_id
    ORDER BY label ASC;

    UPDATE program_question_links
    SET question_id = v_new_question_id
    WHERE program_id = v_program_id
      AND question_id = v_old_question_id;
  END LOOP;
  CLOSE shared_cursor;
END//
DELIMITER ;

CALL isolate_shared_program_questions();
DROP PROCEDURE IF EXISTS isolate_shared_program_questions;

SET @has_uq_program_question_single_owner := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name = 'program_question_links'
    AND index_name = 'uq_program_question_single_owner'
);
SET @sql_uq_program_question_single_owner := IF(
  @has_uq_program_question_single_owner = 0,
  'ALTER TABLE program_question_links ADD UNIQUE KEY uq_program_question_single_owner (question_id)',
  'DO 0'
);
PREPARE stmt_uq_program_question_single_owner FROM @sql_uq_program_question_single_owner;
EXECUTE stmt_uq_program_question_single_owner;
DEALLOCATE PREPARE stmt_uq_program_question_single_owner;
