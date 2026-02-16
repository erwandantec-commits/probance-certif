-- Upgrade PR4: certification tracks (GREEN/BLUE/RED/BLACK/SILVER/VERMEIL)
-- Adds JSON-based selection rules and question taxonomy columns when missing.

ALTER TABLE packages
  ADD COLUMN IF NOT EXISTS selection_rules_json LONGTEXT NULL AFTER selection_percent;

ALTER TABLE questions
  ADD COLUMN IF NOT EXISTS need ENUM('PONE','PHM','PPM') NOT NULL DEFAULT 'PONE' AFTER text,
  ADD COLUMN IF NOT EXISTS level TINYINT NOT NULL DEFAULT 1 AFTER need;

-- Ensure legacy rows have valid defaults.
UPDATE questions
SET need = 'PONE'
WHERE need IS NULL OR need NOT IN ('PONE', 'PHM', 'PPM');

UPDATE questions
SET level = 1
WHERE level IS NULL OR level < 1 OR level > 3;

-- Remove legacy demo package from existing databases.
DELETE FROM packages
WHERE name = 'Certification Package Red';

-- Create the 6 certification packages when missing.
INSERT INTO packages (name, pass_threshold_percent, duration_limit_minutes, selection_count, selection_mode, selection_percent, is_active)
SELECT 'GREEN', 80, 10, 40, 'COUNT', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'GREEN');

INSERT INTO packages (name, pass_threshold_percent, duration_limit_minutes, selection_count, selection_mode, selection_percent, is_active)
SELECT 'BLUE', 80, 10, 40, 'COUNT', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'BLUE');

INSERT INTO packages (name, pass_threshold_percent, duration_limit_minutes, selection_count, selection_mode, selection_percent, is_active)
SELECT 'RED', 80, 10, 50, 'COUNT', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'RED');

INSERT INTO packages (name, pass_threshold_percent, duration_limit_minutes, selection_count, selection_mode, selection_percent, is_active)
SELECT 'BLACK', 80, 10, 50, 'COUNT', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'BLACK');

INSERT INTO packages (name, pass_threshold_percent, duration_limit_minutes, selection_count, selection_mode, selection_percent, is_active)
SELECT 'SILVER', 80, 10, 50, 'COUNT', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'SILVER');

INSERT INTO packages (name, pass_threshold_percent, duration_limit_minutes, selection_count, selection_mode, selection_percent, is_active)
SELECT 'VERMEIL', 80, 10, 50, 'COUNT', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'VERMEIL');

-- GREEN: Operational
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 40,
  selection_rules_json = JSON_OBJECT(
    'max', 40,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'GREEN';

-- BLUE: Advanced
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 40,
  selection_rules_json = JSON_OBJECT(
    'max', 40,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'BLUE';

-- RED: Expert Operational
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 50,
  selection_rules_json = JSON_OBJECT(
    'max', 50,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(1), 'take', 40),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(3), 'take', 3),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2), 'take', 3),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'RED';

-- BLACK: Expert Confirmed
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 50,
  selection_rules_json = JSON_OBJECT(
    'max', 50,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(1), 'take', 10),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(3), 'take', 3),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2), 'take', 3),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'BLACK';

-- SILVER: Senior Expert
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 50,
  selection_rules_json = JSON_OBJECT(
    'max', 50,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PPM', 'levels', JSON_ARRAY(1), 'take', 40),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(3), 'take', 2),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(3), 'take', 2),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(2), 'take', 2),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2), 'take', 2),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(1), 'take', 5),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'SILVER';

-- VERMEIL: Master
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 50,
  selection_rules_json = JSON_OBJECT(
    'max', 50,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PPM', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PPM', 'levels', JSON_ARRAY(1), 'take', 10),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(3), 'take', 2),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(3), 'take', 2),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(2), 'take', 2),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2), 'take', 2),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(1), 'take', 5),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'VERMEIL';
