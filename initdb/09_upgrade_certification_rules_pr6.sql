-- Upgrade PR6:
-- - rename VERMEIL package to GOLD
-- - align certification selection rules with product requirements

UPDATE packages
SET name = 'GOLD'
WHERE name = 'VERMEIL';

-- Ensure GOLD exists even if no VERMEIL row was present.
INSERT INTO packages (name, pass_threshold_percent, duration_limit_minutes, selection_count, selection_mode, selection_percent, is_active)
SELECT 'GOLD', 80, 10, 50, 'COUNT', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'GOLD');

-- GREEN: up to 10 PONE level >=2, then fill to 40 with PONE level 1.
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 40,
  selection_rules_json = JSON_OBJECT(
    'max', 40,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2, 3), 'take', 10),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 40)
    )
  )
WHERE name = 'GREEN';

-- BLUE: up to 30 PONE level >=2, then fill to 40 with PONE level 1.
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 40,
  selection_rules_json = JSON_OBJECT(
    'max', 40,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 40)
    )
  )
WHERE name = 'BLUE';

-- RED: 40 PHM L1 then fill with PONE L3/L2/L1.
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

-- BLACK: 30 PHM L>=2, then up to 40 with PHM L1, then fill with PONE L3/L2/L1.
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 50,
  selection_rules_json = JSON_OBJECT(
    'max', 50,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(1), 'take', 40),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(3), 'take', 3),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2), 'take', 3),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'BLACK';

-- SILVER: 40 PPM L1, then fill with PHM/PONE as defined.
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

-- GOLD: 30 PPM L>=2, then up to 40 with PPM L1, then fill with PHM/PONE as defined.
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 50,
  selection_rules_json = JSON_OBJECT(
    'max', 50,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PPM', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PPM', 'levels', JSON_ARRAY(1), 'take', 40),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(3), 'take', 2),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(3), 'take', 2),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(2), 'take', 2),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2), 'take', 2),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(1), 'take', 5),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'GOLD';

