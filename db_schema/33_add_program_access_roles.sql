ALTER TABLE user_program_access
  ADD COLUMN IF NOT EXISTS access_role ENUM('USER','OWNER') NOT NULL DEFAULT 'USER' AFTER program_id;

UPDATE user_program_access upa
JOIN users u ON u.id = upa.user_id
SET upa.access_role = 'OWNER'
WHERE u.role IN ('OWNER', 'ADMIN');
