ALTER TABLE questions
  ALTER COLUMN allow_skip SET DEFAULT 0;

UPDATE questions
SET allow_skip = 0
WHERE allow_skip <> 0;
