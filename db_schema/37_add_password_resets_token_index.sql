-- Migration 37: index on password_resets.token_hash for direct lookup
-- Also adds index on sessions(user_id, package_id) used by session selection queries

ALTER TABLE password_resets
  MODIFY COLUMN token_hash VARCHAR(64) NOT NULL;

ALTER TABLE password_resets
  ADD INDEX IF NOT EXISTS idx_pr_token_hash (token_hash),
  ADD INDEX IF NOT EXISTS idx_pr_expires (expires_at);

ALTER TABLE sessions
  ADD INDEX IF NOT EXISTS idx_sessions_user_package (user_id, package_id),
  ADD INDEX IF NOT EXISTS idx_sessions_status_type (status, session_type);
