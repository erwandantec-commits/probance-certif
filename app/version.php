<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$payload = [
  'app_version' => APP_VERSION,
  'schema_version' => null,
  'db_status' => 'ok',
];

try {
  $pdo = db();
  $tableExists = (int)$pdo->query("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_version'
  ")->fetchColumn() > 0;

  if ($tableExists) {
    $version = $pdo->query("SELECT version FROM schema_version WHERE id = 1 LIMIT 1")->fetchColumn();
    if ($version !== false && $version !== null && $version !== '') {
      $payload['schema_version'] = (int)$version;
    }
  }
} catch (Throwable $e) {
  http_response_code(503);
  $payload['db_status'] = 'unavailable';
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
