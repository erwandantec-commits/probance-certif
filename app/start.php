<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

$user = require_auth();
$lang = get_lang();
$uid = (int)$user['id'];
$email = $user['email'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: /dashboard.php?lang=" . urlencode($lang));
  exit;
}

$pdo = db();
$package_id = (int)($_POST['package_id'] ?? 0);
$session_type = strtoupper(trim((string)($_POST['session_type'] ?? 'EXAM')));
if (!in_array($session_type, ['EXAM', 'TRAINING'], true)) {
  $session_type = 'EXAM';
}

if ($package_id <= 0) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.invalid_package'));
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1");
$stmt->execute([$package_id]);
$pkg = $stmt->fetch();

if (!$pkg) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.package_not_found'));
  exit;
}

if ($session_type === 'EXAM') {
  $hasRevocationsTable = (bool)$pdo->query("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'certification_revocations'
  ")->fetchColumn();

  $revocationJoin = $hasRevocationsTable
    ? "LEFT JOIN certification_revocations cr ON cr.contact_id = s.contact_id AND cr.package_id = s.package_id"
    : "";
  $revocationWhere = $hasRevocationsTable
    ? "AND (cr.contact_id IS NULL OR cr.revoked_at < COALESCE(s.ended_at, s.submitted_at, s.started_at))"
    : "";

  $validCertStmt = $pdo->prepare("
    SELECT COALESCE(s.ended_at, s.submitted_at, s.started_at) AS last_success_at
    FROM sessions s
    $revocationJoin
    WHERE s.user_id=?
      AND s.package_id=?
      AND s.session_type='EXAM'
      AND s.status='TERMINATED'
      AND s.passed=1
      $revocationWhere
    ORDER BY COALESCE(s.ended_at, s.submitted_at, s.started_at) DESC
    LIMIT 1
  ");
  $validCertStmt->execute([$uid, $package_id]);
  $lastSuccessAt = $validCertStmt->fetchColumn();
  $certStatus = certification_status_from_last_success(
    is_string($lastSuccessAt) ? $lastSuccessAt : null
  );

  if (($certStatus['status_key'] ?? 'NONE') === 'CERTIFIED' || ($certStatus['status_key'] ?? 'NONE') === 'SOON') {
    header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.cert_already_valid'));
    exit;
  }
}

$selection = select_questions_for_package($pdo, $pkg, $uid);
$qids = $selection['ids'] ?? [];
if (($selection['error_key'] ?? null) !== null) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode((string)$selection['error_key']));
  exit;
}

$pdo->beginTransaction();
try {
  $stmt = $pdo->prepare("SELECT id FROM contacts WHERE email=?");
  $stmt->execute([$email]);
  $contact = $stmt->fetch();

  if (!$contact) {
    $ins = $pdo->prepare("INSERT INTO contacts(email) VALUES(?)");
    $ins->execute([$email]);
    $contact_id = (int)$pdo->lastInsertId();
  } else {
    $contact_id = (int)$contact['id'];
  }

  // If a session for the same certification is paused/active, replace it.
  // Deleting the session cascades to linked questions/answers.
  $dropActive = $pdo->prepare("
    DELETE FROM sessions
    WHERE user_id=? AND package_id=? AND session_type=? AND status='ACTIVE'
  ");
  $dropActive->execute([$uid, $package_id, $session_type]);

  $session_id = uuidv4();

  create_session_record($pdo, $session_id, $contact_id, $uid, $package_id, $session_type, $lang);

  $pos = 1;
  $insq = $pdo->prepare("INSERT INTO session_questions(session_id, question_id, position) VALUES(?,?,?)");
  foreach ($qids as $qid) {
    $insq->execute([$session_id, (int)$qid, $pos++]);
  }

  $pdo->commit();

  header("Location: /exam.php?sid=" . urlencode($session_id) . "&p=1&lang=" . urlencode($lang));
  exit;
} catch (Throwable $e) {
  $pdo->rollBack();
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.create_failed'));
  exit;
}
