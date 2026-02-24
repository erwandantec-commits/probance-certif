-- Add optional client visibility flag for questions import/mapping.

ALTER TABLE questions
  ADD COLUMN IF NOT EXISTS open_to_client TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_skip;
