CREATE TABLE IF NOT EXISTS program_question_links (
  program_id INT NOT NULL,
  question_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (program_id, question_id),
  KEY idx_program_question_links_question (question_id),
  CONSTRAINT fk_program_question_links_program
    FOREIGN KEY (program_id) REFERENCES programs(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_program_question_links_question
    FOREIGN KEY (question_id) REFERENCES questions(id)
    ON DELETE CASCADE
);

INSERT IGNORE INTO program_question_links (program_id, question_id)
SELECT p.id, q.id
FROM programs p
JOIN questions q
WHERE p.is_default = 1;
