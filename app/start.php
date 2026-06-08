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
$requestedProgramId = (int)($_POST['program_id'] ?? 0);
$cooldownOverrideId = 0;
$session_type = strtoupper(trim((string)($_POST['session_type'] ?? 'EXAM')));
if (!in_array($session_type, ['EXAM', 'TRAINING'], true)) {
  $session_type = 'EXAM';
}

if ($package_id <= 0) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.invalid_package'));
  exit;
}

if (!auth_user_can_access_package($pdo, $user, $package_id)) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.package_not_found'));
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1");
$stmt->execute([$package_id]);
$pkg = $stmt->fetch();

if (!$pkg) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.package_not_found'));
  exit;
}

$packageProgramIds = auth_package_program_ids($pdo, $package_id, true);
$activeProgramId = $requestedProgramId > 0 ? auth_candidate_program_context($pdo, $user, $requestedProgramId) : auth_candidate_program_context($pdo, $user);
if ($packageProgramIds && !in_array($activeProgramId, $packageProgramIds, true)) {
  $activeProgramId = (int)$packageProgramIds[0];
}
$programSourceLang = package_program_source_lang($pdo, $package_id, $activeProgramId);

if ($session_type === 'EXAM') {
  if (table_exists($pdo, 'exam_cooldown_overrides')) {
    $overrideStmt = $pdo->prepare("
      SELECT id
      FROM exam_cooldown_overrides
      WHERE user_id=?
        AND package_id=?
        AND is_active=1
        AND used_at IS NULL
        AND (expires_at IS NULL OR expires_at >= NOW())
      ORDER BY created_at ASC, id ASC
      LIMIT 1
    ");
    $overrideStmt->execute([$uid, $package_id]);
    $cooldownOverrideId = (int)($overrideStmt->fetchColumn() ?: 0);
  }

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
    is_string($lastSuccessAt) ? $lastSuccessAt : null,
    null,
    (int)($pkg['cert_validity_days'] ?? 365),
    $lang
  );

  if (
    $cooldownOverrideId <= 0
    && (($certStatus['status_key'] ?? 'NONE') === 'CERTIFIED' || ($certStatus['status_key'] ?? 'NONE') === 'SOON')
  ) {
    header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.cert_already_valid'));
    exit;
  }

  $failedCooldownDays = 0;
  if (table_column_exists($pdo, 'packages', 'failed_cooldown_days')) {
    $failedCooldownDays = (int)($pkg['failed_cooldown_days'] ?? 0);
    if ($failedCooldownDays < 0) {
      $failedCooldownDays = 0;
    } elseif ($failedCooldownDays > 3650) {
      $failedCooldownDays = 3650;
    }
  }

  if ($failedCooldownDays > 0 && $cooldownOverrideId <= 0) {
    $lastFailedStmt = $pdo->prepare("
      SELECT COALESCE(s.ended_at, s.submitted_at, s.started_at) AS last_failed_at
      FROM sessions s
      WHERE s.user_id=?
        AND s.package_id=?
        AND s.session_type='EXAM'
        AND s.status='TERMINATED'
        AND s.passed=0
      ORDER BY COALESCE(s.ended_at, s.submitted_at, s.started_at) DESC
      LIMIT 1
    ");
    $lastFailedStmt->execute([$uid, $package_id]);
    $lastFailedAt = $lastFailedStmt->fetchColumn();

    $cooldownStatus = failed_exam_cooldown_status_from_last_failure(
      is_string($lastFailedAt) ? $lastFailedAt : null,
      null,
      $failedCooldownDays
    );

    if (($cooldownStatus['status_key'] ?? 'AVAILABLE') === 'COOLDOWN') {
      $remainingDays = max(1, (int)($cooldownStatus['remaining_days'] ?? 1));
      $availableAt = $cooldownStatus['available_at'] ?? null;
      $availableDate = ($availableAt instanceof DateTimeImmutable)
        ? $availableAt->format('Y-m-d')
        : '';
      header(
        "Location: /dashboard.php?lang=" . urlencode($lang)
        . "&err=" . urlencode(t('start.err.failed_exam_cooldown', [
          'days' => $remainingDays,
          'date' => $availableDate,
        ], $lang))
      );
      exit;
    }
  }
}

$selection = select_questions_for_package($pdo, $pkg, $uid);
$qids = $selection['ids'] ?? [];
if (($selection['error_key'] ?? null) !== null) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode((string)$selection['error_key']));
  exit;
}

if (question_translation_normalize_lang($lang) !== $programSourceLang) {
  $blockingMissing = [];
  foreach ($qids as $questionId) {
    $translationStatus = question_translation_status($pdo, (int)$questionId, $lang, $programSourceLang);
    if ($translationStatus === 'missing') {
      $blockingMissing[] = (int)$questionId;
    }
  }
  if ($blockingMissing !== []) {
    header(
      "Location: /dashboard.php?lang=" . urlencode($lang)
      . "&err=" . urlencode(t('start.err.exam_language_incomplete', [
        'lang' => t('lang.' . question_translation_normalize_lang($lang), [], $lang),
      ], $lang))
    );
    exit;
  }
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
  foreach ($qids as $qid) {
    create_session_question($pdo, $session_id, (int)$qid, $pos++);
  }

  if ($cooldownOverrideId > 0 && table_exists($pdo, 'exam_cooldown_overrides')) {
    $consumeOverride = $pdo->prepare("
      UPDATE exam_cooldown_overrides
      SET is_active=0, used_at=NOW()
      WHERE id=?
        AND is_active=1
        AND used_at IS NULL
    ");
    $consumeOverride->execute([$cooldownOverrideId]);
  }

  $pdo->commit();

  header("Location: /exam.php?sid=" . urlencode($session_id) . "&p=1&lang=" . urlencode($lang));
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log(
    '[start.php] session creation failed user_id=' . $uid
    . ' package_id=' . $package_id
    . ' session_type=' . $session_type
    . ' message=' . $e->getMessage()
  );
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.create_failed'));
  exit;
}
