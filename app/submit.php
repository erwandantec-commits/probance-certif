<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

$pdo = db();
$lang = get_lang();

$sid = $_GET['sid'] ?? '';
if (!$sid) {
  http_response_code(400);
  echo "Missing sid";
  exit;
}

$stmt = $pdo->prepare("
  SELECT s.*, pk.pass_threshold_percent, pk.duration_limit_minutes
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.id=?
");
$stmt->execute([$sid]);
$sess = $stmt->fetch();
if (!$sess) {
  http_response_code(404);
  echo "Session not found";
  exit;
}

if ($sess['status'] !== 'ACTIVE') {
  header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
  exit;
}

if (session_is_expired($sess)) {
  mark_session_expired($pdo, $sid);
  header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
  exit;
}

$scoreSnapshot = compute_session_score_snapshot($pdo, $sid);
$score = (float)($scoreSnapshot['score_percent'] ?? 0.0);

$threshold = (int)$sess['pass_threshold_percent'];
$passed = ($score >= $threshold) ? 1 : 0;

mark_session_terminated($pdo, $sid, round($score, 2), $passed);

header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
exit;
