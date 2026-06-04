<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

$pdo = db();
$user = require_auth();
$lang = get_lang();

$sid = $_GET['sid'] ?? '';
if (!$sid) {
  render_error_page(400, 'Paramètre manquant', 'Identifiant de session manquant.', '/dashboard.php');
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
  render_error_page(404, 'Session introuvable', 'Cette session n\'existe pas.', '/dashboard.php');
}

if ((int)($sess['user_id'] ?? 0) !== (int)$user['id']) {
  render_error_page(403, 'Accès refusé', 'Vous n\'êtes pas autorisé à soumettre cette session.', '/dashboard.php');
}

if ($sess['status'] !== 'ACTIVE') {
  header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
  exit;
}

$scoreSnapshot = compute_session_score_snapshot($pdo, $sid);
$score = (float)($scoreSnapshot['score_percent'] ?? 0.0);
$roundedScore = round($score, 2);

$threshold = (int)$sess['pass_threshold_percent'];
$passed = ($roundedScore >= $threshold) ? 1 : 0;
$terminationType = session_is_expired($sess) ? 'TIMEOUT' : 'MANUAL';

mark_session_terminated($pdo, $sid, $roundedScore, $passed, $terminationType);

header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
exit;
