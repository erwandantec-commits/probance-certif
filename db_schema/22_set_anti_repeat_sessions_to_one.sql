ALTER TABLE packages
  ALTER COLUMN anti_repeat_sessions SET DEFAULT 1;

UPDATE packages
SET anti_repeat_sessions = 1
WHERE anti_repeat_sessions <> 1;
