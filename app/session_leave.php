<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services/session_service.php';

$user = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

$sid = trim((string)($_POST['sid'] ?? ''));
if ($sid === '') {
  http_response_code(400);
  exit;
}

$pdo = db();
$stmt = $pdo->prepare("
  SELECT s.id, s.session_type
  FROM sessions s
  WHERE s.id=? AND s.user_id=? AND s.status='ACTIVE'
  LIMIT 1
");
$stmt->execute([$sid, (int)$user['id']]);
$sess = $stmt->fetch();

if (!$sess) {
  http_response_code(204);
  exit;
}

if (strtoupper(trim((string)($sess['session_type'] ?? 'EXAM'))) !== 'EXAM') {
  http_response_code(204);
  exit;
}

$scoreSnapshot = compute_session_score_snapshot($pdo, $sid);
$score = (float)($scoreSnapshot['score_percent'] ?? 0.0);
mark_session_terminated($pdo, $sid, round($score, 2), 0, 'MANUAL');

http_response_code(204);
exit;
