-- Upgrade PR1: sessions metadata (language, end timestamp, termination type)
ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS language CHAR(2) NULL AFTER session_type,
  ADD COLUMN IF NOT EXISTS ended_at DATETIME NULL AFTER started_at,
  ADD COLUMN IF NOT EXISTS termination_type ENUM('MANUAL','TIMEOUT') NULL AFTER submitted_at;
