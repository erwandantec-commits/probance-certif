<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

$user = require_auth();
$lang = get_lang();
$uid = (int)$user['id'];
$sid = trim((string)($_GET['sid'] ?? ''));

if ($sid === '') {
  header("Location: /dashboard.php?lang=" . urlencode($lang));
  exit;
}

$pdo = db();

$stmt = $pdo->prepare("
  SELECT id
  FROM sessions
  WHERE id=? AND user_id=? AND status='ACTIVE'
");
$stmt->execute([$sid, $uid]);
$row = $stmt->fetch();

if ($row) {
  $del = $pdo->prepare("DELETE FROM sessions WHERE id=? AND user_id=? AND status='ACTIVE'");
  $del->execute([$sid, $uid]);
}

header("Location: /dashboard.php?lang=" . urlencode($lang));
exit;

