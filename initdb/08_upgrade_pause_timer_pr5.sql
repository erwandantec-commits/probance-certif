-- Upgrade PR5: persistent pause timer support
ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS paused_remaining_seconds INT NULL AFTER termination_type;

