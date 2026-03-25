-- Upgrade v25: preserve session question history even after question edits/deletions
-- This migration is intentionally defensive so it can finish cleanly on databases
-- that were partially updated manually before the scripted rollout.

ALTER TABLE session_questions
  MODIFY COLUMN question_id INT NULL,
  ADD COLUMN IF NOT EXISTS question_external_id_snapshot BIGINT NULL AFTER position,
  ADD COLUMN IF NOT EXISTS question_text_snapshot TEXT NULL AFTER question_external_id_snapshot,
  ADD COLUMN IF NOT EXISTS question_explanation_snapshot TEXT NULL AFTER question_text_snapshot,
  ADD COLUMN IF NOT EXISTS question_type_snapshot ENUM('SINGLE','MULTI','TRUE_FALSE') NULL AFTER question_explanation_snapshot,
  ADD COLUMN IF NOT EXISTS allow_skip_snapshot TINYINT(1) NULL AFTER question_type_snapshot,
  ADD COLUMN IF NOT EXISTS correct_option_labels_snapshot VARCHAR(255) NULL AFTER allow_skip_snapshot,
  ADD COLUMN IF NOT EXISTS question_updated_at_snapshot DATETIME NULL AFTER correct_option_labels_snapshot,
  ADD COLUMN IF NOT EXISTS answer_status_snapshot ENUM('OK','KO','UNANSWERED') NULL AFTER question_updated_at_snapshot;

SET @has_answer_options_id := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'answer_options'
    AND column_name = 'id'
);
SET @has_answer_options_primary := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'answer_options'
    AND constraint_type = 'PRIMARY KEY'
);
SET @has_answer_options_primary_on_id := (
  SELECT COUNT(*)
  FROM information_schema.key_column_usage
  WHERE table_schema = DATABASE()
    AND table_name = 'answer_options'
    AND constraint_name = 'PRIMARY'
    AND column_name = 'id'
);
SET @sql_answer_options_primary := IF(
  @has_answer_options_primary_on_id > 0,
  'SELECT 1',
  IF(
    @has_answer_options_id = 0,
    IF(
      @has_answer_options_primary > 0,
      'ALTER TABLE answer_options DROP PRIMARY KEY, ADD COLUMN id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST',
      'ALTER TABLE answer_options ADD COLUMN id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST'
    ),
    IF(
      @has_answer_options_primary > 0,
      'ALTER TABLE answer_options DROP PRIMARY KEY, MODIFY COLUMN id BIGINT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)',
      'ALTER TABLE answer_options MODIFY COLUMN id BIGINT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)'
    )
  )
);
PREPARE stmt_answer_options_primary FROM @sql_answer_options_primary;
EXECUTE stmt_answer_options_primary;
DEALLOCATE PREPARE stmt_answer_options_primary;

ALTER TABLE answer_options
  MODIFY COLUMN question_id INT NULL,
  MODIFY COLUMN option_id INT NULL,
  ADD COLUMN IF NOT EXISTS session_question_id INT NULL AFTER session_id,
  ADD COLUMN IF NOT EXISTS option_label_snapshot CHAR(8) NULL AFTER option_id,
  ADD COLUMN IF NOT EXISTS option_text_snapshot VARCHAR(500) NULL AFTER option_label_snapshot;

SET @has_idx_ao_session_question_row := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'answer_options'
    AND index_name = 'idx_ao_session_question_row'
);
SET @sql_idx_ao_session_question_row := IF(
  @has_idx_ao_session_question_row = 0,
  'ALTER TABLE answer_options ADD INDEX idx_ao_session_question_row (session_question_id)',
  'SELECT 1'
);
PREPARE stmt_idx_ao_session_question_row FROM @sql_idx_ao_session_question_row;
EXECUTE stmt_idx_ao_session_question_row;
DEALLOCATE PREPARE stmt_idx_ao_session_question_row;

UPDATE session_questions sq
LEFT JOIN questions q ON q.id = sq.question_id
SET
  sq.question_external_id_snapshot = q.external_id,
  sq.question_text_snapshot = q.text,
  sq.question_explanation_snapshot = q.explanation,
  sq.question_type_snapshot = q.question_type,
  sq.allow_skip_snapshot = q.allow_skip,
  sq.question_updated_at_snapshot = q.updated_at,
  sq.correct_option_labels_snapshot = (
    SELECT GROUP_CONCAT(qo.label ORDER BY qo.label SEPARATOR ',')
    FROM question_options qo
    WHERE qo.question_id = sq.question_id
      AND qo.is_correct = 1
  )
WHERE sq.question_id IS NOT NULL
  AND (
    sq.question_external_id_snapshot IS NULL
    OR sq.question_text_snapshot IS NULL
    OR sq.question_explanation_snapshot IS NULL
    OR sq.question_type_snapshot IS NULL
    OR sq.allow_skip_snapshot IS NULL
    OR sq.question_updated_at_snapshot IS NULL
    OR sq.correct_option_labels_snapshot IS NULL
  );

UPDATE answer_options ao
JOIN session_questions sq
  ON sq.session_id = ao.session_id
 AND sq.question_id = ao.question_id
LEFT JOIN question_options qo ON qo.id = ao.option_id
SET
  ao.session_question_id = COALESCE(ao.session_question_id, sq.id),
  ao.option_label_snapshot = COALESCE(ao.option_label_snapshot, qo.label),
  ao.option_text_snapshot = COALESCE(ao.option_text_snapshot, qo.option_text)
WHERE ao.session_question_id IS NULL
   OR ao.option_label_snapshot IS NULL
   OR ao.option_text_snapshot IS NULL;

UPDATE session_questions sq
SET sq.answer_status_snapshot = CASE
  WHEN COALESCE((
    SELECT GROUP_CONCAT(
      COALESCE(NULLIF(TRIM(ao.option_label_snapshot), ''), qo.label)
      ORDER BY COALESCE(NULLIF(TRIM(ao.option_label_snapshot), ''), qo.label)
      SEPARATOR ','
    )
    FROM answer_options ao
    LEFT JOIN question_options qo ON qo.id = ao.option_id
    WHERE ao.session_question_id = sq.id
       OR (ao.session_question_id IS NULL AND ao.session_id = sq.session_id AND ao.question_id = sq.question_id)
  ), '') = '' THEN 'UNANSWERED'
  WHEN COALESCE((
    SELECT GROUP_CONCAT(
      COALESCE(NULLIF(TRIM(ao.option_label_snapshot), ''), qo.label)
      ORDER BY COALESCE(NULLIF(TRIM(ao.option_label_snapshot), ''), qo.label)
      SEPARATOR ','
    )
    FROM answer_options ao
    LEFT JOIN question_options qo ON qo.id = ao.option_id
    WHERE ao.session_question_id = sq.id
       OR (ao.session_question_id IS NULL AND ao.session_id = sq.session_id AND ao.question_id = sq.question_id)
  ), '') = COALESCE(sq.correct_option_labels_snapshot, '') THEN 'OK'
  ELSE 'KO'
END
WHERE sq.answer_status_snapshot IS NULL
   OR sq.answer_status_snapshot NOT IN ('OK', 'KO', 'UNANSWERED');

SET @has_fk_sq_question := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'session_questions'
    AND constraint_name = 'fk_sq_question'
    AND constraint_type = 'FOREIGN KEY'
);
SET @sql_drop_fk_sq_question := IF(
  @has_fk_sq_question > 0,
  'ALTER TABLE session_questions DROP FOREIGN KEY fk_sq_question',
  'SELECT 1'
);
PREPARE stmt_drop_fk_sq_question FROM @sql_drop_fk_sq_question;
EXECUTE stmt_drop_fk_sq_question;
DEALLOCATE PREPARE stmt_drop_fk_sq_question;

ALTER TABLE session_questions
  ADD CONSTRAINT fk_sq_question
    FOREIGN KEY (question_id) REFERENCES questions(id)
    ON DELETE SET NULL;

SET @has_fk_ao_session_question := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'answer_options'
    AND constraint_name = 'fk_ao_session_question'
    AND constraint_type = 'FOREIGN KEY'
);
SET @has_fk_ao_question := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'answer_options'
    AND constraint_name = 'fk_ao_question'
    AND constraint_type = 'FOREIGN KEY'
);
SET @has_fk_ao_option := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'answer_options'
    AND constraint_name = 'fk_ao_option'
    AND constraint_type = 'FOREIGN KEY'
);

SET @sql_drop_fk_ao_session_question := IF(
  @has_fk_ao_session_question > 0,
  'ALTER TABLE answer_options DROP FOREIGN KEY fk_ao_session_question',
  'SELECT 1'
);
PREPARE stmt_drop_fk_ao_session_question FROM @sql_drop_fk_ao_session_question;
EXECUTE stmt_drop_fk_ao_session_question;
DEALLOCATE PREPARE stmt_drop_fk_ao_session_question;

SET @sql_drop_fk_ao_question := IF(
  @has_fk_ao_question > 0,
  'ALTER TABLE answer_options DROP FOREIGN KEY fk_ao_question',
  'SELECT 1'
);
PREPARE stmt_drop_fk_ao_question FROM @sql_drop_fk_ao_question;
EXECUTE stmt_drop_fk_ao_question;
DEALLOCATE PREPARE stmt_drop_fk_ao_question;

SET @sql_drop_fk_ao_option := IF(
  @has_fk_ao_option > 0,
  'ALTER TABLE answer_options DROP FOREIGN KEY fk_ao_option',
  'SELECT 1'
);
PREPARE stmt_drop_fk_ao_option FROM @sql_drop_fk_ao_option;
EXECUTE stmt_drop_fk_ao_option;
DEALLOCATE PREPARE stmt_drop_fk_ao_option;

ALTER TABLE answer_options
  ADD CONSTRAINT fk_ao_session_question
    FOREIGN KEY (session_question_id) REFERENCES session_questions(id)
    ON DELETE CASCADE,
  ADD CONSTRAINT fk_ao_question
    FOREIGN KEY (question_id) REFERENCES questions(id)
    ON DELETE SET NULL,
  ADD CONSTRAINT fk_ao_option
    FOREIGN KEY (option_id) REFERENCES question_options(id)
    ON DELETE SET NULL;
