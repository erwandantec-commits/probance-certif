-- initdb/01_schema.sql

-- (Optionnel) pour relancer proprement en dev
-- SET FOREIGN_KEY_CHECKS=0;
-- DROP TABLE IF EXISTS answer_options;
-- DROP TABLE IF EXISTS question_options;
-- DROP TABLE IF EXISTS answers;
-- DROP TABLE IF EXISTS session_questions;
-- DROP TABLE IF EXISTS sessions;
-- DROP TABLE IF EXISTS questions;
-- DROP TABLE IF EXISTS package_rules;
-- DROP TABLE IF EXISTS packages;
-- DROP TABLE IF EXISTS contacts;
-- SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(190) NULL,
  role ENUM('USER','ADMIN') NOT NULL DEFAULT 'USER',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  name_color_hex CHAR(7) NULL,
  profile VARCHAR(255) NULL,
  display_order INT NOT NULL DEFAULT 100,
  badge_image_filename VARCHAR(255) NULL,
  pass_threshold_percent INT NOT NULL DEFAULT 80,
  duration_limit_minutes INT NOT NULL DEFAULT 120,
  selection_count INT NOT NULL DEFAULT 10,
  anti_repeat_sessions INT NOT NULL DEFAULT 4,
  selection_mode ENUM('COUNT','PERCENT') NOT NULL DEFAULT 'COUNT',
  selection_percent INT NULL,
  CHECK (
    (selection_mode = 'COUNT' AND selection_percent IS NULL) OR
    (selection_mode = 'PERCENT' AND selection_percent BETWEEN 1 AND 100)
  ),
  is_active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE package_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  need ENUM('PONE','PHM','PPM') NOT NULL,
  min_level TINYINT NOT NULL,
  max_level TINYINT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CHECK (min_level BETWEEN 1 AND 3),
  CHECK (max_level BETWEEN 1 AND 3),
  CHECK (min_level <= max_level),

  INDEX idx_package_rules_package_active (package_id, is_active),
  INDEX idx_package_rules_need_level (need, min_level, max_level),

  CONSTRAINT fk_package_rules_package
    FOREIGN KEY (package_id) REFERENCES packages(id)
    ON DELETE CASCADE
);

-- Questions: uniquement l'énoncé + métadonnées
CREATE TABLE questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  external_id BIGINT NULL,
  package_id INT NULL,
  text TEXT NOT NULL,
  theme VARCHAR(255) NULL,
  category VARCHAR(255) NULL,
  profile VARCHAR(255) NULL,
  need ENUM('PONE','PHM','PPM') NOT NULL DEFAULT 'PONE',
  knowledge_required_csv VARCHAR(64) NULL,
  level TINYINT NOT NULL DEFAULT 1,

  -- SINGLE: 1 choix max
  -- MULTI: plusieurs choix possibles
  -- TRUE_FALSE: généralement 2 choix (Vrai/Faux), mais géré via options aussi
  question_type ENUM('SINGLE','MULTI','TRUE_FALSE') NOT NULL DEFAULT 'SINGLE',

  -- L'utilisateur peut soumettre sans sélectionner (score 0)
  allow_skip TINYINT(1) NOT NULL DEFAULT 1,
  open_to_client TINYINT(1) NOT NULL DEFAULT 0,
  explanation TEXT NULL,
  meta_json LONGTEXT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_questions_external_id (external_id),

  CONSTRAINT fk_questions_package
    FOREIGN KEY (package_id) REFERENCES packages(id)
    ON DELETE CASCADE
);

-- Options de réponse (2 à 6, et plus si un jour tu changes)
-- score_value te permet:
--  - bonne réponse: +1
--  - mauvaise: -1
--  - NSP: 0
CREATE TABLE question_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  label CHAR(1) NOT NULL,               -- A..F (ou 1..6 si tu préfères)
  option_text VARCHAR(500) NOT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  score_value SMALLINT NOT NULL DEFAULT 0,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_qo_question (question_id),
  UNIQUE KEY uq_qo_question_label (question_id, label),

  CONSTRAINT fk_qo_question
    FOREIGN KEY (question_id) REFERENCES questions(id)
    ON DELETE CASCADE
);

CREATE TABLE sessions (
  id CHAR(36) PRIMARY KEY,
  contact_id INT NOT NULL,
  user_id INT NULL,
  package_id INT NOT NULL,
  session_type ENUM('EXAM','TRAINING') NOT NULL DEFAULT 'EXAM',
  language CHAR(2) NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  submitted_at DATETIME NULL,
  termination_type ENUM('MANUAL','TIMEOUT') NULL,
  paused_remaining_seconds INT NULL,
  status ENUM('ACTIVE','TERMINATED','EXPIRED') NOT NULL DEFAULT 'ACTIVE',
  score_percent DECIMAL(5,2) NULL,
  passed TINYINT(1) NULL,

  CONSTRAINT fk_sessions_contact
    FOREIGN KEY (contact_id) REFERENCES contacts(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_sessions_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE SET NULL,

  CONSTRAINT fk_sessions_package
    FOREIGN KEY (package_id) REFERENCES packages(id)
    ON DELETE CASCADE
);

CREATE TABLE session_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id CHAR(36) NOT NULL,
  question_id INT NOT NULL,
  position INT NOT NULL,

  UNIQUE KEY uq_sq_session_position (session_id, position),
  UNIQUE KEY uq_sq_session_question (session_id, question_id),

  CONSTRAINT fk_sq_session
    FOREIGN KEY (session_id) REFERENCES sessions(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_sq_question
    FOREIGN KEY (question_id) REFERENCES questions(id)
    ON DELETE CASCADE
);

-- Réponses sélectionnées (multi choix)
CREATE TABLE answer_options (
  session_id CHAR(36) NOT NULL,
  question_id INT NOT NULL,
  option_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (session_id, question_id, option_id),
  INDEX idx_ao_session_question (session_id, question_id),

  CONSTRAINT fk_ao_session
    FOREIGN KEY (session_id) REFERENCES sessions(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_ao_question
    FOREIGN KEY (question_id) REFERENCES questions(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_ao_option
    FOREIGN KEY (option_id) REFERENCES question_options(id)
    ON DELETE CASCADE
);

-- (Optionnel) Legacy: ancien modèle 1 réponse A/B/C/D
-- Tu peux le supprimer quand tout est migré.
CREATE TABLE answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id CHAR(36) NOT NULL,
  question_id INT NOT NULL,
  answer CHAR(1) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_answers_session_question (session_id, question_id),

  CONSTRAINT fk_answers_session
    FOREIGN KEY (session_id) REFERENCES sessions(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_answers_question
    FOREIGN KEY (question_id) REFERENCES questions(id)
    ON DELETE CASCADE
);

CREATE TABLE password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_pr_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
);
