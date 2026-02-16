<?php
require_once __DIR__ . '/_auth.php';
require_admin();

require_once __DIR__ . '/../db.php';
$pdo = db();

$action = strtolower(trim((string)($_GET['action'] ?? 'revoke')));
$contactId = (int)($_GET['contact_id'] ?? 0);
$packageId = (int)($_GET['package_id'] ?? 0);

if ($contactId <= 0 || $packageId <= 0) {
  http_response_code(400);
  echo "Missing contact_id or package_id";
  exit;
}

$hasRevocationsTable = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'certification_revocations'
")->fetchColumn();

if (!$hasRevocationsTable) {
  http_response_code(500);
  echo "Missing table certification_revocations";
  exit;
}

$adminUserId = null;
if (isset($_SESSION['user']) && isset($_SESSION['user']['id'])) {
  $adminUserId = (int)$_SESSION['user']['id'];
}

if ($action === 'undo') {
  $st = $pdo->prepare("DELETE FROM certification_revocations WHERE contact_id=? AND package_id=?");
  $st->execute([$contactId, $packageId]);
} else {
  $st = $pdo->prepare("
    INSERT INTO certification_revocations(contact_id, package_id, revoked_by_user_id, revoked_at)
    VALUES(?,?,?,NOW())
    ON DUPLICATE KEY UPDATE
      revoked_by_user_id = VALUES(revoked_by_user_id),
      revoked_at = VALUES(revoked_at)
  ");
  $st->execute([$contactId, $packageId, $adminUserId]);
}

header('Location: /admin/certifications.php');
exit;
