<?php

if (!function_exists('config_env_value')) {
  function config_env_value(string $key, string $default = ''): string
  {
    $value = getenv($key);
    if ($value === false) {
      return $default;
    }
    $trimmed = trim((string)$value);
    return $trimmed !== '' ? $trimmed : $default;
  }
}

if (!function_exists('config_app_version_value')) {
  function config_app_version_value(): string
  {
    $versionFile = __DIR__ . '/version.txt';
    if (is_file($versionFile)) {
      $version = trim((string)file_get_contents($versionFile));
      if ($version !== '') {
        return $version;
      }
    }
    return 'dev';
  }
}

define('APP_VERSION', config_env_value('APP_VERSION', config_app_version_value()));
define('APP_BASE_URL', rtrim(config_env_value('APP_BASE_URL', 'http://localhost:8080'), '/'));
define('DB_HOST', config_env_value('DB_HOST', 'db'));
define('DB_NAME', config_env_value('DB_NAME', 'certif'));
define('DB_USER', config_env_value('DB_USER', 'certif_user'));
define('DB_PASS', config_env_value('DB_PASS', 'certif_pass'));
define('ADMIN_PASSWORD', config_env_value('ADMIN_PASSWORD', 'Probance123!'));
define('SMTP_HOST', config_env_value('SMTP_HOST', 'smtp-gw.company.lan'));
define('SMTP_PORT', (int)config_env_value('SMTP_PORT', '25'));
define('SMTP_FROM_EMAIL', config_env_value('SMTP_FROM_EMAIL', 'certif@company.tld'));
define('SMTP_FROM_NAME', config_env_value('SMTP_FROM_NAME', 'Probance Certif Tool'));
define('SMTP_USE_TLS', config_env_value('SMTP_USE_TLS', '0') === '1');
define('SMTP_USERNAME', config_env_value('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', config_env_value('SMTP_PASSWORD', ''));

