<?php
require_once __DIR__ . '/db.php';
$pdo = db();

$sid = $_GET['sid'] ?? '';
if (!$sid) { http_response_code(400); echo "Missing sid"; exit; }

// Load session + package
$stmt = $pdo->prepare("
  SELECT s.*, pk.pass_threshold_percent, pk.duration_limit_minutes
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.id=?
");
$stmt->execute([$sid]);
$sess = $stmt->fetch();
if (!$sess) { http_response_code(404); echo "Session not found"; exit; }

if ($sess['status'] !== 'ACTIVE') {
  header("Location: /result.php?sid=" . urlencode($sid));
  exit;
}

// Max possible points = count of correct options across session questions
$maxStmt = $pdo->prepare("
  SELECT COALESCE(SUM(CASE WHEN qo.is_correct=1 THEN 1 ELSE 0 END), 0) AS max_points
  FROM session_questions sq
  JOIN question_options qo ON qo.question_id = sq.question_id
  WHERE sq.session_id=?
");
$maxStmt->execute([$sid]);
$maxPoints = (int)$maxStmt->fetch()['max_points'];

// Raw score = sum(score_value) of selected options
$rawStmt = $pdo->prepare("
  SELECT COALESCE(SUM(qo.score_value), 0) AS raw_score
  FROM answer_options ao
  JOIN question_options qo ON qo.id = ao.option_id
  WHERE ao.session_id=?
");
$rawStmt->execute([$sid]);
$rawScore = (int)$rawStmt->fetch()['raw_score'];

// Percent = clamp(raw/maxPoints, 0..1) * 100
if ($maxPoints <= 0) {
  $score = 0.0;
} else {
  $ratio = $rawScore / $maxPoints;
  if ($ratio < 0) $ratio = 0;
  if ($ratio > 1) $ratio = 1;
  $score = $ratio * 100.0;
}

$threshold = (int)$sess['pass_threshold_percent'];
$passed = ($score >= $threshold) ? 1 : 0;

// Update session
$upd = $pdo->prepare("
  UPDATE sessions
  SET status='TERMINATED', submitted_at=NOW(), score_percent=?, passed=?
  WHERE id=?
");
$upd->execute([round($score, 2), $passed, $sid]);

header("Location: /result.php?sid=" . urlencode($sid));
exit;

