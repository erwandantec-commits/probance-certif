-- Add ABANDONED as a valid termination_type for manually stopped sessions
ALTER TABLE sessions
  MODIFY COLUMN termination_type ENUM('MANUAL','TIMEOUT','ABANDONED') NULL;
