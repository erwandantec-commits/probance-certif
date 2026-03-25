ALTER TABLE session_questions
  MODIFY COLUMN question_id INT NULL,
  ADD COLUMN question_external_id_snapshot BIGINT NULL AFTER position,
  ADD COLUMN question_text_snapshot TEXT NULL AFTER question_external_id_snapshot,
  ADD COLUMN question_explanation_snapshot TEXT NULL AFTER question_text_snapshot,
  ADD COLUMN question_type_snapshot ENUM('SINGLE','MULTI','TRUE_FALSE') NULL AFTER question_explanation_snapshot,
  ADD COLUMN allow_skip_snapshot TINYINT(1) NULL AFTER question_type_snapshot,
  ADD COLUMN correct_option_labels_snapshot VARCHAR(255) NULL AFTER allow_skip_snapshot,
  ADD COLUMN question_updated_at_snapshot DATETIME NULL AFTER correct_option_labels_snapshot,
  ADD COLUMN answer_status_snapshot ENUM('OK','KO','UNANSWERED') NULL AFTER question_updated_at_snapshot;

ALTER TABLE answer_options
  DROP PRIMARY KEY,
  ADD COLUMN id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
  MODIFY COLUMN question_id INT NULL,
  MODIFY COLUMN option_id INT NULL,
  ADD COLUMN session_question_id INT NULL AFTER session_id,
  ADD COLUMN option_label_snapshot CHAR(8) NULL AFTER option_id,
  ADD COLUMN option_text_snapshot VARCHAR(500) NULL AFTER option_label_snapshot,
  ADD INDEX idx_ao_session_question_row (session_question_id);

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
WHERE sq.question_id IS NOT NULL;

UPDATE answer_options ao
JOIN session_questions sq
  ON sq.session_id = ao.session_id
 AND sq.question_id = ao.question_id
LEFT JOIN question_options qo ON qo.id = ao.option_id
SET
  ao.session_question_id = sq.id,
  ao.option_label_snapshot = qo.label,
  ao.option_text_snapshot = qo.option_text
WHERE ao.session_question_id IS NULL;

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
END;

ALTER TABLE session_questions
  DROP FOREIGN KEY fk_sq_question,
  ADD CONSTRAINT fk_sq_question
    FOREIGN KEY (question_id) REFERENCES questions(id)
    ON DELETE SET NULL;

ALTER TABLE answer_options
  DROP FOREIGN KEY fk_ao_question,
  DROP FOREIGN KEY fk_ao_option,
  ADD CONSTRAINT fk_ao_session_question
    FOREIGN KEY (session_question_id) REFERENCES session_questions(id)
    ON DELETE CASCADE,
  ADD CONSTRAINT fk_ao_question
    FOREIGN KEY (question_id) REFERENCES questions(id)
    ON DELETE SET NULL,
  ADD CONSTRAINT fk_ao_option
    FOREIGN KEY (option_id) REFERENCES question_options(id)
    ON DELETE SET NULL;
