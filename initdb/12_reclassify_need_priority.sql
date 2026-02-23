-- Reclassify primary need with strict priority: PPM > PHM > PONE
-- This makes questions exclusive for package targeting based on q.need.

UPDATE questions
SET need = CASE
  WHEN FIND_IN_SET('PPM', UPPER(COALESCE(knowledge_required_csv, ''))) > 0 THEN 'PPM'
  WHEN FIND_IN_SET('PHM', UPPER(COALESCE(knowledge_required_csv, ''))) > 0 THEN 'PHM'
  WHEN FIND_IN_SET('PONE', UPPER(COALESCE(knowledge_required_csv, ''))) > 0 THEN 'PONE'
  ELSE need
END
WHERE COALESCE(knowledge_required_csv, '') <> '';
