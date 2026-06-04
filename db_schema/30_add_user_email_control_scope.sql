INSERT INTO global_settings (setting_key, setting_value)
VALUES
  ('user_email_control_program_ids', ''),
  ('user_email_control_allowed_domains', '')
ON DUPLICATE KEY UPDATE
  setting_value = COALESCE(global_settings.setting_value, VALUES(setting_value));
