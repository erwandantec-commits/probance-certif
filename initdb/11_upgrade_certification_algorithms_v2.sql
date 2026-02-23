-- Upgrade v2: align certification selection algorithms with product rules
-- Adds target_total on "fill up to N" buckets.

-- GREEN
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 40,
  selection_rules_json = JSON_OBJECT(
    'max', 40,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2, 3), 'take', 10),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 40, 'target_total', 40)
    )
  )
WHERE name = 'GREEN';

-- BLUE
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 40,
  selection_rules_json = JSON_OBJECT(
    'max', 40,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 40, 'target_total', 40)
    )
  )
WHERE name = 'BLUE';

-- RED
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

-- BLACK
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 50,
  selection_rules_json = JSON_OBJECT(
    'max', 50,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(1), 'take', 40, 'target_total', 40),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(3), 'take', 3),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2), 'take', 3),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'BLACK';

-- SILVER
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

-- GOLD
UPDATE packages
SET
  selection_mode = 'COUNT',
  selection_percent = NULL,
  selection_count = 50,
  selection_rules_json = JSON_OBJECT(
    'max', 50,
    'buckets', JSON_ARRAY(
      JSON_OBJECT('need', 'PPM', 'levels', JSON_ARRAY(2, 3), 'take', 30),
      JSON_OBJECT('need', 'PPM', 'levels', JSON_ARRAY(1), 'take', 40, 'target_total', 40),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(3), 'take', 2),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(3), 'take', 2),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(2), 'take', 2),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(2), 'take', 2),
      JSON_OBJECT('need', 'PHM', 'levels', JSON_ARRAY(1), 'take', 5),
      JSON_OBJECT('need', 'PONE', 'levels', JSON_ARRAY(1), 'take', 10)
    )
  )
WHERE name = 'GOLD';
