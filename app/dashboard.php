<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = db();
$user = require_auth();
$lang = get_lang();
$uid = (int)$user['id'];
$requestedProgramId = (int)($_GET['program_id'] ?? 0);
$accessiblePrograms = auth_accessible_programs($pdo, $user);
$activeProgramId = auth_candidate_program_context($pdo, $user, $requestedProgramId > 0 ? $requestedProgramId : null);
$restrictToNoProgramAccess = auth_table_exists($pdo, 'programs') && auth_table_exists($pdo, 'user_program_access') && !$accessiblePrograms;


$hasPackageProfileColumn = table_column_exists($pdo, 'packages', 'profile');
$hasPackageDisplayOrderColumn = table_column_exists($pdo, 'packages', 'display_order');
$hasPackageBadgeImageColumn = table_column_exists($pdo, 'packages', 'badge_image_filename');
$hasPackageCertValidityDaysColumn = table_column_exists($pdo, 'packages', 'cert_validity_days');
$hasPackageFailedCooldownDaysColumn = table_column_exists($pdo, 'packages', 'failed_cooldown_days');
$hasPackageProgramColumn = table_column_exists($pdo, 'packages', 'program_id');
$hasPackageSelectionRulesColumn = table_column_exists($pdo, 'packages', 'selection_rules_json');
$hasProgramPackageLinksTable = auth_program_package_links_enabled($pdo);

$errKey = trim((string)($_GET['err_key'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));

$pkgCols = "id,name,name_color_hex,duration_limit_minutes,selection_count" . ($hasPackageSelectionRulesColumn ? ",selection_rules_json" : "");
if ($hasPackageProfileColumn) {
  $pkgCols .= ", profile";
}
if ($hasPackageDisplayOrderColumn) {
  $pkgCols .= ", display_order";
}
if ($hasPackageFailedCooldownDaysColumn) {
  $pkgCols .= ", failed_cooldown_days";
}
$pkgWhere = [];
if ($hasProgramPackageLinksTable && $activeProgramId > 0) {
  $pkgWhere[] = auth_program_package_scope_sql($pdo, $activeProgramId, 'pk');
}
if ($restrictToNoProgramAccess) {
  $pkgWhere[] = "1 = 0";
} elseif (!$hasProgramPackageLinksTable && $hasPackageProgramColumn && $activeProgramId > 0) {
  $pkgWhere[] = "program_id = " . (int)$activeProgramId;
}
$pkgWhereSql = $pkgWhere ? ('WHERE ' . implode(' AND ', $pkgWhere)) : '';
$pkgOrder = $hasPackageDisplayOrderColumn ? "ORDER BY display_order ASC, id ASC" : "ORDER BY id DESC";
$pkgStmt = $pdo->query("SELECT $pkgCols FROM packages pk $pkgWhereSql $pkgOrder");
$packages = $pkgStmt->fetchAll();
$accessiblePackageIds = array_values(array_map(static fn(array $p): int => (int)($p['id'] ?? 0), $packages));
$restrictToEmptyProgram = $restrictToNoProgramAccess || ($hasPackageProgramColumn && $activeProgramId > 0 && !$accessiblePackageIds);

$packageIdList = array_map(static fn(array $p): int => (int)$p['id'], $packages);
$latestCert = (int)($_GET['latest_cert'] ?? 0);
if ($latestCert > 0 && !in_array($latestCert, $packageIdList, true)) {
  $latestCert = 0;
}
$latestType = strtoupper(trim((string)($_GET['latest_type'] ?? '')));
if (!in_array($latestType, ['EXAM', 'TRAINING'], true)) {
  $latestType = '';
}
$latestResult = strtoupper(trim((string)($_GET['latest_result'] ?? '')));
if (!in_array($latestResult, ['PASSED', 'FAILED'], true)) {
  $latestResult = '';
}
$latestStatus = strtoupper(trim((string)($_GET['latest_status'] ?? '')));
if (!in_array($latestStatus, ['ACTIVE', 'TERMINATED', 'TIMEOUT', 'ABANDONED'], true)) {
  $latestStatus = '';
}
$hasTerminationType = sessions_column_exists($pdo, 'termination_type');

$lastConds = ["s.user_id=?"];
$lastParams = [$uid];
if ($restrictToEmptyProgram) {
  $lastConds[] = "1 = 0";
} elseif ($accessiblePackageIds) {
  $lastConds[] = "s.package_id IN (" . implode(',', array_fill(0, count($accessiblePackageIds), '?')) . ")";
  $lastParams = array_merge($lastParams, $accessiblePackageIds);
}
if ($latestCert > 0) {
  $lastConds[] = "s.package_id=?";
  $lastParams[] = $latestCert;
}
if ($latestType !== '') {
  $lastConds[] = "s.session_type=?";
  $lastParams[] = $latestType;
}
if ($latestResult === 'PASSED') {
  $lastConds[] = "s.status='TERMINATED' AND s.passed=1";
} elseif ($latestResult === 'FAILED') {
  $lastConds[] = "s.status='TERMINATED' AND s.passed=0";
}
if ($latestStatus === 'ACTIVE') {
  $lastConds[] = "s.status='ACTIVE'";
} elseif ($latestStatus === 'TERMINATED') {
  $lastConds[] = "s.status='TERMINATED' AND s.termination_type='MANUAL'";
} elseif ($latestStatus === 'TIMEOUT') {
  $lastConds[] = "s.status='TERMINATED' AND s.termination_type='TIMEOUT'";
} elseif ($latestStatus === 'ABANDONED') {
  $lastConds[] = "s.status='TERMINATED' AND s.termination_type='ABANDONED'";
}

$lastWhere = implode(' AND ', $lastConds);
$lastTerminationTypeSelect = $hasTerminationType
  ? ", s.termination_type"
  : ", 'MANUAL' AS termination_type";
$lastStmt = $pdo->prepare("
  SELECT s.id, s.status, s.session_type, s.started_at, s.submitted_at, s.score_percent, s.passed,
         pk.name as package_name, pk.name_color_hex AS package_color_hex, pk.pass_threshold_percent
    $lastTerminationTypeSelect
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE $lastWhere
  ORDER BY s.started_at DESC
  LIMIT 10
");
$lastStmt->execute($lastParams);
$last = $lastStmt->fetchAll();

$certProfileSelect = $hasPackageProfileColumn ? ", pk.profile AS package_profile" : ", NULL AS package_profile";
$certDisplayOrderSelect = $hasPackageDisplayOrderColumn ? ", pk.display_order AS package_display_order" : ", 100 AS package_display_order";
$certBadgeImageSelect = $hasPackageBadgeImageColumn ? ", pk.badge_image_filename AS package_badge_image" : ", NULL AS package_badge_image";
$certValidityDaysSelect = $hasPackageCertValidityDaysColumn ? ", pk.cert_validity_days AS package_cert_validity_days" : ", 365 AS package_cert_validity_days";
$certSuccessStmt = $pdo->prepare("
  SELECT s.id, s.contact_id, s.package_id, s.started_at, s.submitted_at, pk.name AS package_name, pk.name_color_hex AS package_color_hex
    $certProfileSelect
    $certDisplayOrderSelect
    $certBadgeImageSelect
    $certValidityDaysSelect
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.user_id=?
    AND s.session_type='EXAM'
    AND s.status='TERMINATED'
    AND s.passed=1
    " . ($restrictToEmptyProgram
      ? "AND 1 = 0"
      : ($accessiblePackageIds ? ("AND s.package_id IN (" . implode(',', array_fill(0, count($accessiblePackageIds), '?')) . ")") : "")
    ) . "
  ORDER BY s.started_at DESC
");
$certSuccessParams = [$uid];
if ($accessiblePackageIds) {
  $certSuccessParams = array_merge($certSuccessParams, $accessiblePackageIds);
}
$certSuccessStmt->execute($certSuccessParams);
$certCards = [];
$revokedMap = [];
$hasRevocationsTable = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'certification_revocations'
")->fetchColumn();

if ($hasRevocationsTable) {
  $revokedRows = $pdo->query("
    SELECT contact_id, package_id, revoked_at
    FROM certification_revocations
  ")->fetchAll();
  foreach ($revokedRows as $rv) {
    $k = (int)$rv['contact_id'] . ':' . (int)$rv['package_id'];
    $revokedMap[$k] = (string)$rv['revoked_at'];
  }
}

$badgeVersion = urlencode((string)time());
$badgeByPackage = [
  'GREEN' => '/assets/badges/user-badge-green.png?v=' . $badgeVersion,
  'BLUE' => '/assets/badges/user-badge-blue.png?v=' . $badgeVersion,
  'RED' => '/assets/badges/user-badge-red.png?v=' . $badgeVersion,
  'BLACK' => '/assets/badges/user-badge-black.png?v=' . $badgeVersion,
  'SILVER' => '/assets/badges/user-badge-silver.png?v=' . $badgeVersion,
  'GOLD' => '/assets/badges/user-badge-gold.png?v=' . $badgeVersion,
  'VERMEIL' => '/assets/badges/user-badge-gold.png?v=' . $badgeVersion,
];
$today = new DateTimeImmutable('today');
foreach ($certSuccessStmt->fetchAll() as $row) {
  $pkgId = (int)$row['package_id'];
  $contactId = (int)$row['contact_id'];
  $revocationKey = $contactId . ':' . $pkgId;
  if (isset($certCards[$pkgId])) {
    continue;
  }
  $baseDate = (string)($row['submitted_at'] ?: $row['started_at']);
  $statusInfo = certification_status_from_last_success(
    $baseDate,
    null,
    (int)($row['package_cert_validity_days'] ?? 365),
    $lang
  );
  $pkgName = strtoupper(trim((string)$row['package_name']));
  $customBadgeFile = trim((string)($row['package_badge_image'] ?? ''));
  $badgePath = ($customBadgeFile !== '')
    ? '/assets/badges/' . rawurlencode($customBadgeFile) . '?v=' . $badgeVersion
    : ($badgeByPackage[$pkgName] ?? ('/assets/badges/user-badge-blue.png?v=' . $badgeVersion));
  $isRevoked = false;
  if (isset($revokedMap[$revocationKey])) {
    $revokedAtRaw = trim((string)$revokedMap[$revocationKey]);
    if ($revokedAtRaw !== '' && $baseDate !== '') {
      try {
        $revokedAt = new DateTimeImmutable($revokedAtRaw);
        $lastSuccessAt = new DateTimeImmutable($baseDate);
        $isRevoked = $revokedAt >= $lastSuccessAt;
      } catch (Throwable $e) {
        $isRevoked = true;
      }
    } else {
      $isRevoked = true;
    }
  }

  $certCards[$pkgId] = [
    'sid' => (string)$row['id'],
    'package_name' => (string)$row['package_name'],
    'package_profile' => (string)($row['package_profile'] ?? ''),
    'badge' => $badgePath,
    'tone' => package_color_hex((string)$row['package_name'], (string)($row['package_color_hex'] ?? '')),
    'display_order' => (int)($row['package_display_order'] ?? 100),
    'started_at' => (string)$row['started_at'],
    'expires_at' => $statusInfo['expires_at'] instanceof DateTimeImmutable ? $statusInfo['expires_at']->format('d/m/Y') : '',
    'days_remaining' => null,
    'days_remaining_urgent' => false,
    'status_key' => $isRevoked ? 'REVOKED' : (string)$statusInfo['status_key'],
  ];
  if (!$isRevoked && $statusInfo['expires_at'] instanceof DateTimeImmutable) {
    $daysRemaining = (int)$today->diff($statusInfo['expires_at'])->format('%r%a');
    if ($daysRemaining >= 0) {
      $certCards[$pkgId]['days_remaining'] = $daysRemaining;
      $certCards[$pkgId]['days_remaining_urgent'] = ($daysRemaining < 30);
    }
  }
  if ($isRevoked) {
    $certCards[$pkgId]['expires_at'] = '';
  }
}
if (!empty($certCards)) {
  uasort($certCards, static function (array $a, array $b): int {
    $ai = (int)($a['display_order'] ?? 100);
    $bi = (int)($b['display_order'] ?? 100);
    if ($ai === $bi) {
      return strcmp(
        (string)($a['package_name'] ?? ''),
        (string)($b['package_name'] ?? '')
      );
    }
    return $ai <=> $bi;
  });
}
$examBlockedByPackage = [];
$examBlockReasonByPackage = [];
foreach ($certCards as $pkgId => $card) {
  $statusKey = (string)($card['status_key'] ?? '');
  $isBlocked = in_array($statusKey, ['CERTIFIED', 'SOON'], true);
  $examBlockedByPackage[(int)$pkgId] = $isBlocked;
  if ($isBlocked) {
    $examBlockReasonByPackage[(int)$pkgId] = ['reason' => 'certified', 'date' => (string)($card['expires_at'] ?? '')];
  }
}

$activeOverrideByPackage = [];
if (table_exists($pdo, 'exam_cooldown_overrides')) {
  $overrideListStmt = $pdo->prepare("
    SELECT package_id, expires_at
    FROM exam_cooldown_overrides
    WHERE user_id=?
      AND is_active=1
      AND used_at IS NULL
      AND (expires_at IS NULL OR expires_at >= NOW())
  ");
  $overrideListStmt->execute([$uid]);
  $overrideRows = $overrideListStmt->fetchAll() ?: [];
  foreach ($overrideRows as $r) {
    $pid = (int)($r['package_id'] ?? 0);
    if ($pid > 0) {
      $activeOverrideByPackage[$pid] = ['expires_at' => $r['expires_at'] ?? null];
    }
  }
}

if ($hasPackageFailedCooldownDaysColumn) {
  $lastFailedByPackage = [];
  $lastFailedStmt = $pdo->prepare("
    SELECT s.package_id, MAX(COALESCE(s.ended_at, s.submitted_at, s.started_at)) AS last_failed_at
    FROM sessions s
    WHERE s.user_id=?
      AND s.session_type='EXAM'
      AND s.status='TERMINATED'
      AND s.passed=0
    GROUP BY s.package_id
  ");
  $lastFailedStmt->execute([$uid]);
  $lastFailedRows = $lastFailedStmt->fetchAll() ?: [];
  foreach ($lastFailedRows as $r) {
    $lastFailedByPackage[(int)($r['package_id'] ?? 0)] = (string)($r['last_failed_at'] ?? '');
  }

  foreach ($packages as $pk) {
    $pkgId = (int)($pk['id'] ?? 0);
    if ($pkgId <= 0) {
      continue;
    }

    $blocked = (bool)($examBlockedByPackage[$pkgId] ?? false);
    $cooldownDays = (int)($pk['failed_cooldown_days'] ?? 0);
    $lastFailedAt = $lastFailedByPackage[$pkgId] ?? null;

    $cooldownStatus = failed_exam_cooldown_status_from_last_failure(
      is_string($lastFailedAt) && trim($lastFailedAt) !== '' ? $lastFailedAt : null,
      null,
      $cooldownDays
    );

    if (($cooldownStatus['status_key'] ?? 'AVAILABLE') === 'COOLDOWN') {
      $blocked = true;
      $availAt = $cooldownStatus['available_at'] ?? null;
      $examBlockReasonByPackage[$pkgId] = [
        'reason' => 'cooldown',
        'date'   => ($availAt instanceof DateTimeImmutable) ? $availAt->format('d/m/Y') : '',
      ];
    }

    if (!empty($activeOverrideByPackage[$pkgId])) {
      $blocked = false;
    }

    $examBlockedByPackage[$pkgId] = $blocked;
  }
}

foreach ($packages as $pk) {
  $pkgId = (int)($pk['id'] ?? 0);
  if ($pkgId <= 0) {
    continue;
  }
  if (!empty($activeOverrideByPackage[$pkgId])) {
    $examBlockedByPackage[$pkgId] = false;
  } elseif (!array_key_exists($pkgId, $examBlockedByPackage)) {
    $examBlockedByPackage[$pkgId] = false;
  }
}

foreach ($activeOverrideByPackage as $ovPid => $ovData) {
  $ovExpiresAt = is_array($ovData) ? ($ovData['expires_at'] ?? null) : null;
  $ovDateFmt = '';
  if ($ovExpiresAt) {
    try { $ovDateFmt = (new DateTimeImmutable($ovExpiresAt))->format('d/m/Y'); } catch (Exception $e) {}
  }
  $examBlockReasonByPackage[$ovPid] = ['reason' => 'override', 'date' => $ovDateFmt];
}

$packNotEnoughQuestions = [];
if ($packageIdList) {
  // Mirror admin compute_availability logic:
  // - packs WITH bucket rules → count via program_question_links (modern schema)
  // - packs WITHOUT bucket rules → count via questions.package_id (legacy, same as admin legacyCounts)
  $bucketPkgIds = [];
  $legacyPkgIds = [];
  foreach ($packages as $pk) {
    $raw = $hasPackageSelectionRulesColumn ? trim((string)($pk['selection_rules_json'] ?? '')) : '';
    $rules = $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($rules) && !empty($rules['buckets'])) {
      $bucketPkgIds[] = (int)$pk['id'];
    } else {
      $legacyPkgIds[] = (int)$pk['id'];
    }
  }
  $qCountByPkg = [];
  if ($bucketPkgIds && $hasProgramPackageLinksTable && table_exists($pdo, 'program_question_links')) {
    $bList = implode(',', array_fill(0, count($bucketPkgIds), '?'));
    $qcStmt = $pdo->prepare("
      SELECT ppl.package_id, COUNT(DISTINCT pql.question_id) AS cnt
      FROM program_package_links ppl
      JOIN program_question_links pql ON pql.program_id = ppl.program_id
      WHERE ppl.package_id IN ($bList)
      GROUP BY ppl.package_id
    ");
    $qcStmt->execute($bucketPkgIds);
    foreach ($qcStmt->fetchAll() as $r) {
      $qCountByPkg[(int)$r['package_id']] = (int)$r['cnt'];
    }
  }
  if ($legacyPkgIds) {
    $lList = implode(',', array_fill(0, count($legacyPkgIds), '?'));
    $qcStmt = $pdo->prepare("SELECT package_id, COUNT(*) AS cnt FROM questions WHERE package_id IN ($lList) GROUP BY package_id");
    $qcStmt->execute($legacyPkgIds);
    foreach ($qcStmt->fetchAll() as $r) {
      $qCountByPkg[(int)$r['package_id']] = (int)$r['cnt'];
    }
  }
  foreach ($packages as $pk) {
    $pkgId = (int)($pk['id'] ?? 0);
    $required = (int)($pk['selection_count'] ?? 0);
    $available = (int)($qCountByPkg[$pkgId] ?? 0);
    if ($required > 0 && $available < $required) {
      $packNotEnoughQuestions[$pkgId] = true;
    }
  }
}

$activePausedSelect = sessions_column_exists($pdo, 'paused_remaining_seconds')
  ? ", s.paused_remaining_seconds"
  : ", NULL AS paused_remaining_seconds";
$activeStmt = $pdo->prepare("
  SELECT s.id, s.package_id, s.session_type, s.started_at, pk.name AS package_name, pk.name_color_hex AS package_color_hex, pk.duration_limit_minutes
    $activePausedSelect
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE user_id=? AND status='ACTIVE'
  " . ($restrictToEmptyProgram
    ? "AND 1 = 0"
    : ($accessiblePackageIds ? ("AND s.package_id IN (" . implode(',', array_fill(0, count($accessiblePackageIds), '?')) . ")") : "")
  ) . "
  ORDER BY s.started_at DESC
");
$activeParams = [$uid];
if ($accessiblePackageIds) {
  $activeParams = array_merge($activeParams, $accessiblePackageIds);
}
$activeStmt->execute($activeParams);
$activeRows = $activeStmt->fetchAll();
$activeByPackage = [];
$resumePosBySession = [];
$activeHighlights = [];

$resumePosStmt = $pdo->prepare("
  SELECT MIN(sq.position) AS resume_pos
  FROM session_questions sq
  WHERE sq.session_id=?
    AND NOT EXISTS (
      SELECT 1
      FROM answer_options ao
      WHERE ao.session_id = sq.session_id
        AND ao.question_id = sq.question_id
    )
");
$totalPosStmt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM session_questions
  WHERE session_id=?
");

foreach ($activeRows as $row) {
  $sid = (string)$row['id'];
  $pkgId = (int)$row['package_id'];
  $stype = strtoupper(trim((string)($row['session_type'] ?? 'EXAM')));
  if (!in_array($stype, ['EXAM', 'TRAINING'], true)) {
    $stype = 'EXAM';
  }

  $resumePosStmt->execute([$sid]);
  $resumePos = (int)($resumePosStmt->fetch()['resume_pos'] ?? 0);
  if ($resumePos < 1) {
    $totalPosStmt->execute([$sid]);
    $resumePos = max(1, (int)($totalPosStmt->fetch()['c'] ?? 1));
  }
  $resumePosBySession[$sid] = $resumePos;
  $pausedRemaining = $row['paused_remaining_seconds'] ?? null;
  if ($pausedRemaining !== null && $pausedRemaining !== '') {
    $remainingSeconds = max(0, (int)$pausedRemaining);
  } else {
    $limitMinutes = max(1, (int)($row['duration_limit_minutes'] ?? 1));
    $expiresAt = strtotime((string)$row['started_at']) + ($limitMinutes * 60);
    $remainingSeconds = max(0, $expiresAt - time());
  }
  $activeHighlights[] = [
    'id' => $sid,
    'package_name' => (string)($row['package_name'] ?? ''),
    'package_color_hex' => (string)($row['package_color_hex'] ?? ''),
    'session_type' => $stype,
    'started_at' => (string)$row['started_at'],
    'resume_p' => $resumePos,
    'remaining_seconds' => $remainingSeconds,
  ];

  if (!isset($activeByPackage[$pkgId])) {
    $activeByPackage[$pkgId] = [];
  }
  if (!isset($activeByPackage[$pkgId][$stype])) {
    $activeByPackage[$pkgId][$stype] = [
      'id' => $sid,
      'resume_p' => $resumePos,
      'started_at' => (string)$row['started_at'],
    ];
  }
}

function dash_is_timeout_session(array $s): bool {
  return (string)($s['status'] ?? '') === 'EXPIRED'
    || (
      (string)($s['status'] ?? '') === 'TERMINATED'
      && strtoupper(trim((string)($s['termination_type'] ?? 'MANUAL'))) === 'TIMEOUT'
    );
}

function dash_is_abandoned_session(array $s): bool {
  return (string)($s['status'] ?? '') === 'TERMINATED'
    && strtoupper(trim((string)($s['termination_type'] ?? ''))) === 'ABANDONED';
}

function dash_display_status(array $s): string {
  if (dash_is_abandoned_session($s)) {
    return 'ABANDONED';
  }
  if (dash_is_timeout_session($s)) {
    return 'EXPIRED';
  }
  return (string)($s['status'] ?? '');
}

function dash_status_label(string $status, string $lang): string {
  return match ($status) {
    'TERMINATED' => t('dash.status.terminated', [], $lang),
    'ACTIVE' => t('dash.status.active', [], $lang),
    'EXPIRED' => t('dash.status.expired', [], $lang),
    'ABANDONED' => t('dash.status.abandoned', [], $lang),
    default => $status,
  };
}

function dash_status_badge_class(string $status): string {
  return match ($status) {
    'TERMINATED' => 'pill success',
    'ACTIVE' => 'pill info',
    'EXPIRED' => 'pill warning',
    'ABANDONED' => 'pill danger',
    default => 'pill',
  };
}

function dash_score_fmt($v, string $lang): string {
  if ($v === null || $v === '') {
    return t('dash.na', [], $lang);
  }
  return number_format((float)$v, 2, '.', '') . '%';
}

function dash_result_label(array $s, string $lang): string {
  $passed = dash_session_passed($s);
  if ($passed === null) {
    return t('dash.na', [], $lang);
  }
  return $passed ? t('dash.result.passed', [], $lang) : t('dash.result.failed', [], $lang);
}

function dash_result_badge_class(array $s): string {
  $passed = dash_session_passed($s);
  if ($passed === null) {
    return 'pill';
  }
  return $passed ? 'pill success' : 'pill danger';
}

function dash_session_passed(array $s): ?bool {
  if ((string)dash_display_status($s) === 'ACTIVE') {
    return null;
  }

  if ($s['score_percent'] !== null && $s['score_percent'] !== '' && isset($s['pass_threshold_percent'])) {
    return round((float)$s['score_percent'], 2) >= (float)$s['pass_threshold_percent'];
  }

  if ($s['passed'] === null || $s['passed'] === '') {
    return null;
  }

  return (int)$s['passed'] === 1;
}

function dash_session_type_label(string $type, string $lang): string {
  return match ($type) {
    'EXAM' => t('dash.session_type.exam', [], $lang),
    'TRAINING' => t('dash.session_type.training', [], $lang),
    default => $type,
  };
}

function dash_cert_status_label(string $statusKey, string $lang): string {
  return match ($statusKey) {
    'CERTIFIED' => t('dash.cert_status.certified', [], $lang),
    'SOON' => t('dash.cert_status.soon', [], $lang),
    'EXPIRED' => t('dash.cert_status.expired', [], $lang),
    'REVOKED' => t('dash.cert_status.revoked', [], $lang),
    default => t('dash.na', [], $lang),
  };
}

function dash_cert_status_class(string $statusKey): string {
  return match ($statusKey) {
    'CERTIFIED' => 'pill success',
    'SOON' => 'pill warning',
    'EXPIRED' => 'pill danger',
    'REVOKED' => 'pill danger',
    default => 'pill',
  };
}

function dash_remaining_label(int $seconds): string {
  if ($seconds <= 0) {
    return '00:00';
  }
  $h = intdiv($seconds, 3600);
  $m = intdiv($seconds % 3600, 60);
  $s = $seconds % 60;
  if ($h > 0) {
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
  }
  return sprintf('%02d:%02d', $m, $s);
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('dash.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container dashboard-container">
  <div class="dashboard-sticky-top">
  <div class="dashboard-head">
    <div class="dashboard-head-copy">
      <img class="dashboard-candidate-logo" src="/assets/logo-candidat.svg" alt="Logo candidat">
      <div class="dashboard-head-copy-text">
        <h2 class="h1"><?= h(t('dash.hello', ['name' => ($user['name'] ?: $user['email'])], $lang)) ?></h2>
        <p class="sub"><?= h(t('dash.subtitle', [], $lang)) ?></p>
      </div>
    </div>

      <div class="dashboard-head-actions">
        <div class="dashboard-toolbar">
          <?php if (count($accessiblePrograms) > 1): ?>
            <form method="get" class="dashboard-program-switcher">
              <input type="hidden" name="lang" value="<?= h($lang) ?>">
              <div class="dashboard-program-select-wrap">
                <svg class="dashboard-program-select-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                  <path d="M3 4h18v12H3z"/>
                  <path d="M10 16h4v3h4v2H6v-2h4z"/>
                </svg>
                <select class="input dashboard-program-switcher-select" id="dashboard-program-id" name="program_id" onchange="this.form.submit()">
                  <?php foreach ($accessiblePrograms as $program): ?>
                    <?php $programId = (int)($program['id'] ?? 0); ?>
                    <option value="<?= $programId ?>" <?= $programId === $activeProgramId ? 'selected' : '' ?>>
                      <?= h((string)($program['name'] ?? '')) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </form>
          <?php endif; ?>
          <div class="lang-switch">
            <?php render_flag_lang_picker($lang, "'/dashboard.php?lang={lang}" . ($activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '') . "'"); ?>
          </div>
          <?php if (user_can_access_reporting_area($user)): ?>
            <a class="btn ghost dashboard-admin-btn" href="/admin/">
              <?= h(t(user_has_role($user, 'OWNER') && !user_has_role($user, 'ADMIN') ? 'dash.manager' : 'dash.admin', [], $lang)) ?>
            </a>
          <?php endif; ?>
          <a class="btn ghost dashboard-help-btn" href="/help.php?lang=<?= h(urlencode($lang)) ?>" aria-label="Aide" title="Aide">
            <svg class="help-inline-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <circle cx="12" cy="12" r="8.3"/>
              <path d="M9.4 9.35a2.6 2.6 0 1 1 5.05.87c0 1.72-2.45 2.47-2.45 4.08"/>
              <path d="M12 16.95h.01"/>
            </svg>
          </a>
          <a class="btn ghost dashboard-logout-btn" href="/logout.php" aria-label="<?= h(t('dash.logout', [], $lang)) ?>" title="<?= h(t('dash.logout', [], $lang)) ?>">
            <svg class="logout-inline-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 16l4-4-4-4M19 12H9"/>
            </svg>
          </a>
        </div>
      </div>
    </div>

  <?php if ($errKey || $err): ?>
    <div class="card dashboard-alert">
      <p class="error dashboard-alert-text">
        <?= h($errKey !== '' ? t($errKey, [], $lang) : $err) ?>
      </p>
    </div>
  <?php endif; ?>

  <?php if ($restrictToNoProgramAccess): ?>
  </div>
    <div class="card dashboard-card dashboard-empty-program">
      <div class="dashboard-empty-program-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false">
          <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v13a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 18.5v-13Z"/>
          <path d="M8 8h8M8 12h5M8 16h3"/>
        </svg>
      </div>
      <div class="dashboard-empty-program-copy">
        <h3 class="dashboard-section-title"><?= h(t('dash.no_program.title', [], $lang)) ?></h3>
        <p class="sub"><?= h(t('dash.no_program.body', [], $lang)) ?></p>
      </div>
      <div class="dashboard-empty-program-actions">
        <a class="btn" href="/dashboard.php?lang=<?= h(urlencode($lang)) ?>"><?= h(t('dash.no_program.refresh', [], $lang)) ?></a>
        <?php if (user_can_access_reporting_area($user)): ?>
          <a class="btn ghost" href="/admin/"><?= h(t('dash.admin', [], $lang)) ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>

  <nav class="dashboard-jump" aria-label="Dashboard sections">
    <a class="dashboard-jump-link" href="#sec-certifications"><?= h(t('dash.certifications.title', [], $lang)) ?></a>
    <a class="dashboard-jump-link" href="#sec-start"><?= h(t('dash.start_cert', [], $lang)) ?></a>
    <a class="dashboard-jump-link" href="#sec-active"><?= h(t('dash.active_sessions.title', [], $lang)) ?></a>
    <a class="dashboard-jump-link" href="#sec-latest"><?= h(t('dash.last_sessions', [], $lang)) ?></a>
  </nav>
  </div>

  <div class="card dashboard-card" id="sec-certifications">
    <div class="section-head dashboard-section-head">
      <div>
        <h3 class="dashboard-section-title"><?= h(t('dash.certifications.title', [], $lang)) ?></h3>
      </div>
      <span class="badge"><?= count($certCards) ?></span>
    </div>

    <?php if (!$certCards): ?>
      <p class="small"><?= h(t('dash.certifications.none', [], $lang)) ?></p>
    <?php else: ?>
      <div class="dashboard-certifications-grid">
        <?php foreach ($certCards as $card): ?>
          <?php $cardPkgClass = 'pkg-' . strtolower((string)$card['package_name']); ?>
          <a class="dashboard-cert-card dashboard-cert-card-link" href="/result.php?sid=<?= h((string)$card['sid']) ?>&lang=<?= h($lang) ?>">
            <img class="dashboard-cert-card-badge" src="<?= h($card['badge']) ?>" alt="<?= h(localize_text((string)$card['package_name'], $lang)) ?>">
            <div class="dashboard-cert-card-name <?= h($cardPkgClass) ?>" style="color:<?= h($card['tone']) ?>;">
              <?= h(localize_text((string)$card['package_name'], $lang)) ?>
            </div>
            <?php if ((string)($card['package_profile'] ?? '') !== ''): ?>
              <div class="dashboard-cert-card-meta"><b><?= h(t('dash.certifications.profile', [], $lang)) ?>:</b> <?= h(localize_text((string)$card['package_profile'], $lang)) ?></div>
            <?php endif; ?>
            <div class="dashboard-cert-card-meta"><?= h(t('dash.certifications.obtained_on', [], $lang)) ?>: <?= h(date('d/m/Y', strtotime((string)$card['started_at']))) ?></div>
            <?php if ((string)$card['expires_at'] !== ''): ?>
              <div class="dashboard-cert-card-meta<?= !empty($card['days_remaining_urgent']) ? ' dashboard-cert-card-meta-urgent' : '' ?>">
                <?= h(t('dash.certifications.valid_until', [], $lang)) ?>: <?= h((string)$card['expires_at']) ?>
                <?php if (($card['days_remaining'] ?? null) !== null): ?>
                  (<?= h(t('dash.certifications.days_left', ['days' => (string)((int)$card['days_remaining'])], $lang)) ?>)
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="dashboard-cert-card-status">
              <span class="<?= h(dash_cert_status_class((string)$card['status_key'])) ?> dashboard-cert-status-badge <?= h((string)$card['status_key'] === 'CERTIFIED' ? 'is-valid' : '') ?>">
                <?= h(dash_cert_status_label((string)$card['status_key'], $lang)) ?>
              </span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card dashboard-card" id="sec-start">
    <div class="section-head dashboard-section-head">
      <div>
        <h3 class="dashboard-section-title"><?= h(t('dash.start_cert', [], $lang)) ?></h3>
      </div>
    </div>

	    <form method="post" action="/start.php" class="dashboard-start-form">
	      <input type="hidden" name="lang" value="<?= h($lang) ?>">
	      <input type="hidden" name="program_id" value="<?= (int)$activeProgramId ?>">
	      <input id="dash-package-input" type="hidden" name="package_id" value="">

      <!-- Étape 1 : choisir le pack -->
      <div class="dash-step is-active" id="dash-step-1">
        <label class="label"><?= h(t('dash.cert', [], $lang)) ?></label>
        <?php if ($packages): ?>
          <input type="text" id="dash-pack-search" class="input dash-pack-search" placeholder="<?= h(match($lang) { 'en' => 'Search…', 'es' => 'Buscar…', 'jp' => '検索…', default => 'Rechercher…' }) ?>" autocomplete="off">
          <div class="dash-cert-grid" id="dash-cert-grid">
            <?php foreach ($packages as $pk): ?>
              <?php
                $pkName      = localize_text((string)$pk['name'], $lang);
                $pkProfile   = trim(localize_text((string)($pk['profile'] ?? ''), $lang));
                $pkCode      = strtoupper(trim((string)$pk['name']));
                $pkTone      = package_color_hex((string)$pk['name'], (string)($pk['name_color_hex'] ?? ''));
                $pkDuration  = (int)$pk['duration_limit_minutes'];
                $isExamLocked = !empty($examBlockedByPackage[(int)$pk['id']]);
                $blockReason  = $examBlockReasonByPackage[(int)$pk['id']] ?? null;
                $isIncomplete = !empty($packNotEnoughQuestions[(int)$pk['id']]);
              ?>
              <button
                type="button"
                class="dash-cert-tile<?= $pkCode === 'BLACK' ? ' pkg-black' : '' ?>"
                data-package-value="<?= (int)$pk['id'] ?>"
                data-package-name="<?= h($pkName) ?>"
                data-package-profile="<?= h($pkProfile) ?>"
                data-active-sid-exam="<?= h((string)($activeByPackage[(int)$pk['id']]['EXAM']['id'] ?? '')) ?>"
                data-active-p-exam="<?= (int)($activeByPackage[(int)$pk['id']]['EXAM']['resume_p'] ?? 1) ?>"
                data-active-sid-training="<?= h((string)($activeByPackage[(int)$pk['id']]['TRAINING']['id'] ?? '')) ?>"
                data-active-p-training="<?= (int)($activeByPackage[(int)$pk['id']]['TRAINING']['resume_p'] ?? 1) ?>"
                data-package-exam-locked="<?= $isExamLocked ? '1' : '0' ?>"
                data-package-incomplete="<?= $isIncomplete ? '1' : '0' ?>"
                data-block-reason="<?= h($blockReason['reason'] ?? '') ?>"
                data-block-date="<?= h($blockReason['date'] ?? '') ?>"
                data-package-duration="<?= (int)$pkDuration ?>"
                style="--cert-tone: <?= h($pkTone) ?>;"
              >
                <span class="dash-cert-tile-name"><?= h($pkName) ?></span>
                <?php if ($pkProfile !== ''): ?>
                  <span class="dash-cert-tile-profile"><?= h($pkProfile) ?></span>
                <?php endif; ?>
                <span class="dash-cert-tile-time"><?= (int)$pkDuration ?> <?= h(t('dash.minutes_short', [], $lang)) ?></span>
                <?php if ($isIncomplete): ?>
                  <span class="dash-cert-tile-badge dash-cert-tile-badge--incomplete">
                    <?= h(match($lang) { 'en' => 'Incomplete', 'es' => 'Incompleto', 'jp' => '未完了', default => 'Incomplet' }) ?>
                  </span>
                <?php elseif ($blockReason): ?>
                  <span class="dash-cert-tile-badge dash-cert-tile-badge--<?= h($blockReason['reason']) ?>">
                    <?php if ($blockReason['reason'] === 'certified'): ?>
                      ✓ <?= h(match($lang) { 'en' => 'Valid · exp. ', 'es' => 'Válida · exp. ', 'jp' => '有効 · ', default => 'Valide · ' }) . h($blockReason['date']) ?>
                    <?php elseif ($blockReason['reason'] === 'override'): ?>
                      🔓 <?php if ($blockReason['date']): ?>
                        <?= h(match($lang) { 'en' => 'Unlocked until ', 'es' => 'Desbloqueado hasta ', 'jp' => '解除済 ', default => 'Débloqué jusqu\'au ' }) . h($blockReason['date']) ?>
                      <?php else: ?>
                        <?= h(match($lang) { 'en' => 'Unlocked', 'es' => 'Desbloqueado', 'jp' => '解除済', default => 'Débloqué' }) ?>
                      <?php endif; ?>
                    <?php else: ?>
                      ⏳ <?= h(match($lang) { 'en' => 'Retry ', 'es' => 'Reintento ', 'jp' => '再試験 ', default => 'Réessai ' }) . h($blockReason['date']) ?>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="dash-cert-empty"><?= h(t('dash.no_active_packages', [], $lang)) ?></div>
        <?php endif; ?>
      </div>

      <!-- Étape 2 : choisir le mode + démarrer -->
      <div class="dash-step" id="dash-step-2">
        <div class="dash-step2-head">
          <button type="button" class="btn ghost dash-back-btn" id="dash-back-btn">
            ← <?= h(match($lang) { 'en' => 'Change pack', 'es' => 'Cambiar pack', 'jp' => 'パックを変更', default => 'Changer de pack' }) ?>
          </button>
          <span class="dash-step2-packname" id="dash-step2-packname"></span>
        </div>
        <div class="dashboard-mode-group" role="radiogroup" aria-label="<?= h(t('dash.session_type', [], $lang)) ?>">

          <label class="dashboard-mode-option">
            <input type="radio" name="session_type" value="TRAINING" checked>
            <span class="dashboard-mode-content">
              <span class="dashboard-mode-header">
                <span class="dashboard-mode-icon" aria-hidden="true">
                  <svg class="dashboard-mode-icon-training" viewBox="0 0 24 24" focusable="false">
                    <circle cx="12" cy="4" r="2.2"/>
                    <path d="M4.2 7.5h1.4v4h-1.4zM6.1 6.8h1v5.4h-1zM16.9 6.8h1v5.4h-1zM18.4 7.5h1.4v4h-1.4z"/>
                    <path d="M7.1 8.5h9.8v1.6H7.1z"/>
                    <path d="M10.1 11.2h3.8v2.9l1.6 2.2v4.2h-2.1v-3.4L12 15.5l-1.3 1.6v3.4H8.6v-4.2l1.5-2.2z"/>
                  </svg>
                </span>
                <span class="dashboard-mode-title"><?= h(t('dash.session_type.training', [], $lang)) ?></span>
              </span>
              <span class="dashboard-mode-duration" id="dash-training-duration-display" style="display:none;"></span>
              <ul class="dashboard-mode-features">
                <?php foreach (match($lang) {
                  'en' => [
                    'Immediate feedback after each question',
                    'Ability to review each question at the end of the training',
                    'Can be paused and resumed at any time',
                    'A stopped training session can be restarted',
                  ],
                  'es' => [
                    'Corrección inmediata tras cada pregunta',
                    'Posibilidad de revisar cada pregunta al final del entrenamiento',
                    'Se puede pausar y reanudar en cualquier momento',
                    'Es posible reiniciar un entrenamiento detenido',
                  ],
                  'jp' => [
                    '各問題後に即時フィードバック',
                    'トレーニング終了後に各問題を見直すことができます',
                    'いつでも一時停止・再開が可能',
                    '中断したトレーニングを再開できます',
                  ],
                  default => [
                    'Correction immédiate après chaque question',
                    'Possibilité de revoir chaque question à la fin de l\'entraînement',
                    'Possibilité de mettre en pause et de reprendre à tout moment',
                    'Possible de relancer un entraînement stoppé',
                  ],
                } as $feat): ?>
                  <li><?= h($feat) ?></li>
                <?php endforeach; ?>
              </ul>
            </span>
          </label>

          <label class="dashboard-mode-option">
            <input type="radio" name="session_type" value="EXAM">
            <span class="dashboard-mode-content">
              <span class="dashboard-mode-header">
                <span class="dashboard-mode-icon" aria-hidden="true">
                  <svg class="dashboard-mode-icon-cert" viewBox="0 0 24 24" focusable="false">
                    <path d="m12 3.35 1.42.96 1.7.02.8 1.5 1.55.68.02 1.7 1.1 1.28-.48 1.63.48 1.63-1.1 1.28-.02 1.7-1.55.68-.8 1.5-1.7.02-1.42.96-1.42-.96-1.7-.02-.8-1.5-1.55-.68-.02-1.7-1.1-1.28.48-1.63-.48-1.63 1.1-1.28.02-1.7 1.55-.68.8-1.5 1.7-.02L12 3.35Z"/>
                    <circle cx="12" cy="10.8" r="4.15"/>
                    <path d="m10.1 10.8 1.38 1.4 2.72-2.73"/>
                    <path d="m8.35 16.45-1.1 4.2 2-.95 1.15 1.75 1.1-3.95"/>
                    <path d="m15.65 16.45 1.1 4.2-2-.95-1.15 1.75-1.1-3.95"/>
                  </svg>
                </span>
                <span class="dashboard-mode-title"><?= h(t('dash.session_type.exam', [], $lang)) ?></span>
              </span>
              <span class="dashboard-mode-duration" id="dash-exam-duration-display" style="display:none;"></span>
              <ul class="dashboard-mode-features">
                <?php foreach (match($lang) {
                  'en' => [
                    'No feedback during the session',
                    'Score displayed at the end without the answers',
                    'Cannot be paused',
                    'Quitting or abandoning invalidates the exam',
                  ],
                  'es' => [
                    'Sin corrección durante la sesión',
                    'Puntuación mostrada al final sin las respuestas',
                    'No se puede pausar',
                    'Salir o abandonar invalida el examen',
                  ],
                  'jp' => [
                    'セッション中はフィードバックなし',
                    'スコアは回答なしで最後に表示',
                    '一時停止不可',
                    '中断または放棄すると試験が無効になります',
                  ],
                  default => [
                    'Aucune correction pendant la session',
                    'Le score est affiché à la fin sans les réponses',
                    'Impossible de mettre en pause',
                    'Quitter ou abandonner rend l\'examen invalide',
                  ],
                } as $feat): ?>
                  <li><?= h($feat) ?></li>
                <?php endforeach; ?>
              </ul>
            </span>
          </label>

        </div>
        <div class="dash-exam-block-info" id="dash-exam-block-info" style="display:none;">
          <span class="dash-exam-block-icon" id="dash-exam-block-icon"></span>
          <span id="dash-exam-block-msg"></span>
        </div>
        <div class="dashboard-start-foot">
          <div class="dashboard-start-action">
            <button id="dash-start-btn" class="btn" type="submit" disabled><?= h(t('dash.start', [], $lang)) ?></button>
            <a id="dash-continue-btn" class="btn ghost" href="#" style="display:none;"><?= h(t('dash.continue_current', [], $lang)) ?></a>
          </div>
        </div>
      </div>
		    </form>
  </div>

  <div class="card dashboard-card" id="sec-active">
    <div class="section-head dashboard-section-head">
      <div>
        <h3 class="dashboard-section-title"><?= h(t('dash.active_sessions.title', [], $lang)) ?></h3>
      </div>
      <span class="badge"><?= count($activeHighlights) ?></span>
    </div>
    <?php if (!$activeHighlights): ?>
      <p class="small"><?= h(t('dash.active_sessions.none', [], $lang)) ?></p>
    <?php else: ?>
      <div class="dashboard-active-grid">
        <?php foreach ($activeHighlights as $a): ?>
          <article class="dashboard-active-item">
            <div class="dashboard-active-item-head">
              <span style="<?= h(package_label_style((string)$a['package_name'], (string)($a['package_color_hex'] ?? ''))) ?>"><?= h(localize_text((string)$a['package_name'], $lang)) ?></span>
              <span class="pill info"><?= h(dash_session_type_label((string)$a['session_type'], $lang)) ?></span>
            </div>
            <div class="dashboard-active-item-meta">
              <?= h(t('dash.active_sessions.started', [], $lang)) ?>: <?= h(date('d/m/Y H:i', strtotime((string)$a['started_at']))) ?>
            </div>
            <div class="dashboard-active-item-meta">
              <?= h(t('dash.active_sessions.remaining', [], $lang)) ?>: <b><?= h(dash_remaining_label((int)$a['remaining_seconds'])) ?></b>
            </div>
            <div class="dashboard-active-item-actions">
              <?php if ((string)$a['session_type'] === 'TRAINING'): ?>
                <a class="btn" href="/exam.php?sid=<?= h((string)$a['id']) ?>&p=<?= (int)$a['resume_p'] ?>&lang=<?= h($lang) ?>">
                  <?= h(t('dash.continue_current_training', [], $lang)) ?>
                </a>
              <?php endif; ?>
              <a class="btn ghost icon-btn danger"
                 href="/session_delete.php?sid=<?= h((string)$a['id']) ?>&lang=<?= h($lang) ?>"
                 aria-label="<?= h(t('dash.delete_active', [], $lang)) ?>"
                 title="<?= h(t('dash.delete_active', [], $lang)) ?>"
                 onclick="return confirm('<?= h(t('dash.delete_confirm', [], $lang)) ?>');">
                <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                  <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                </svg>
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card dashboard-card" id="sec-latest">
    <div class="section-head dashboard-section-head">
      <div>
        <h3 class="dashboard-section-title"><?= h(t('dash.last_sessions', [], $lang)) ?></h3>
      </div>
      <span class="badge"><?= count($last) ?></span>
    </div>

    <form method="get" class="filters-grid" style="margin-bottom:12px; grid-template-columns: repeat(4, minmax(0,1fr)) auto;">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <?php if ($activeProgramId > 0): ?>
        <input type="hidden" name="program_id" value="<?= (int)$activeProgramId ?>">
      <?php endif; ?>
      <div>
        <label class="label"><?= h(t('dash.col.cert', [], $lang)) ?></label>
        <select name="latest_cert" onchange="this.form.submit()">
          <option value="">--</option>
          <?php foreach ($packages as $pk): ?>
            <?php $pkId = (int)$pk['id']; ?>
            <option value="<?= $pkId ?>" <?= $latestCert === $pkId ? 'selected' : '' ?>>
              <?= h(localize_text((string)$pk['name'], $lang)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label"><?= h(t('dash.col.type', [], $lang)) ?></label>
        <select name="latest_type" onchange="this.form.submit()">
          <option value="">--</option>
          <option value="EXAM" <?= $latestType === 'EXAM' ? 'selected' : '' ?>><?= h(t('dash.session_type.exam', [], $lang)) ?></option>
          <option value="TRAINING" <?= $latestType === 'TRAINING' ? 'selected' : '' ?>><?= h(t('dash.session_type.training', [], $lang)) ?></option>
        </select>
      </div>
      <div>
        <label class="label"><?= h(t('dash.col.result', [], $lang)) ?></label>
        <select name="latest_result" onchange="this.form.submit()">
          <option value="">--</option>
          <option value="PASSED" <?= $latestResult === 'PASSED' ? 'selected' : '' ?>><?= h(t('dash.result.passed', [], $lang)) ?></option>
          <option value="FAILED" <?= $latestResult === 'FAILED' ? 'selected' : '' ?>><?= h(t('dash.result.failed', [], $lang)) ?></option>
        </select>
      </div>
      <div>
        <label class="label"><?= h(t('dash.col.status', [], $lang)) ?></label>
        <select name="latest_status" onchange="this.form.submit()">
          <option value="">--</option>
          <option value="ACTIVE" <?= $latestStatus === 'ACTIVE' ? 'selected' : '' ?>><?= h(t('dash.status.active', [], $lang)) ?></option>
          <option value="TERMINATED" <?= $latestStatus === 'TERMINATED' ? 'selected' : '' ?>><?= h(t('dash.status.terminated', [], $lang)) ?></option>
          <option value="TIMEOUT" <?= $latestStatus === 'TIMEOUT' ? 'selected' : '' ?>><?= h(t('dash.status.timeout', [], $lang)) ?></option>
          <option value="ABANDONED" <?= $latestStatus === 'ABANDONED' ? 'selected' : '' ?>><?= h(t('dash.status.abandoned', [], $lang)) ?></option>
        </select>
      </div>
      <div class="filters-actions" style="grid-column: auto; margin-bottom: 0;">
        <a class="btn ghost" href="/dashboard.php?lang=<?= h(urlencode($lang)) ?><?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>#sec-latest"><?= h(t('dash.filters_reset', [], $lang)) ?></a>
      </div>
    </form>

    <?php if (!$last): ?>
      <p class="small"><?= h(t('dash.none', [], $lang)) ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table dashboard-table">
        <thead>
	          <tr>
	            <th><?= h(t('dash.col.cert', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.type', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.started', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.status', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.score', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.result', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.action', [], $lang)) ?></th>
	          </tr>
	        </thead>
        <tbody>
        <?php foreach ($last as $s): ?>
          <?php $displayStatus = dash_display_status($s); ?>
          <tr>
	            <td><span style="<?= h(package_label_style((string)$s['package_name'], (string)($s['package_color_hex'] ?? ''))) ?>"><?= h(localize_text((string)$s['package_name'], $lang)) ?></span></td>
            <td><?= h(dash_session_type_label((string)$s['session_type'], $lang)) ?></td>
            <td><?= date('d/m/Y H:i', strtotime($s['started_at'])) ?></td>
            <td>
              <span class="<?= h(dash_status_badge_class($displayStatus)) ?>">
                <?= h(dash_status_label($displayStatus, $lang)) ?>
              </span>
            </td>
            <td><?= h(dash_score_fmt($s['score_percent'], $lang)) ?></td>
            <td>
              <span class="<?= h(dash_result_badge_class($s)) ?>">
                <?= h(dash_result_label($s, $lang)) ?>
              </span>
            </td>
	            <td>
                <div class="dashboard-table-actions">
	                <?php if ($s['status'] === 'ACTIVE' && (string)($s['session_type'] ?? '') === 'TRAINING'): ?>
	                  <a class="btn ghost" href="/exam.php?sid=<?= h($s['id']) ?>&p=<?= (int)($resumePosBySession[(string)$s['id']] ?? 1) ?>&lang=<?= h($lang) ?>"><?= h(t('dash.resume', [], $lang)) ?></a>
                    <a class="btn ghost icon-btn danger"
                       href="/session_delete.php?sid=<?= h($s['id']) ?>&lang=<?= h($lang) ?>"
                       aria-label="<?= h(t('dash.delete_active', [], $lang)) ?>"
                       title="<?= h(t('dash.delete_active', [], $lang)) ?>"
                       onclick="return confirm('<?= h(t('dash.delete_confirm', [], $lang)) ?>');">
                      <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                      </svg>
                    </a>
	                <?php elseif ($s['status'] === 'ACTIVE'): ?>
                    <a class="btn ghost icon-btn danger"
                       href="/session_delete.php?sid=<?= h($s['id']) ?>&lang=<?= h($lang) ?>"
                       aria-label="<?= h(t('dash.delete_active', [], $lang)) ?>"
                       title="<?= h(t('dash.delete_active', [], $lang)) ?>"
                       onclick="return confirm('<?= h(t('dash.delete_confirm', [], $lang)) ?>');">
                      <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                      </svg>
                    </a>
	                <?php else: ?>
	                  <a class="btn ghost icon-btn" href="/result.php?sid=<?= h($s['id']) ?>&lang=<?= h($lang) ?>" aria-label="<?= h(t('dash.view', [], $lang)) ?>" title="<?= h(t('dash.view', [], $lang)) ?>">
                      <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 5c5.5 0 9.5 4.6 10.8 6.3a1.2 1.2 0 0 1 0 1.4C21.5 14.4 17.5 19 12 19S2.5 14.4 1.2 12.7a1.2 1.2 0 0 1 0-1.4C2.5 9.6 6.5 5 12 5zm0 2C8 7 4.9 10.3 3.3 12 4.9 13.7 8 17 12 17s7.1-3.3 8.7-5C19.1 10.3 16 7 12 7zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5z"/>
                      </svg>
                    </a>
	                <?php endif; ?>
                </div>
	            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<script src="/assets/package-colors.js"></script>
<script>
  (function () {
    var form        = document.querySelector('.dashboard-start-form');
    var input       = document.getElementById('dash-package-input');
    var step1       = document.getElementById('dash-step-1');
    var step2       = document.getElementById('dash-step-2');
    var backBtn     = document.getElementById('dash-back-btn');
    var packNameEl  = document.getElementById('dash-step2-packname');
    var startBtn    = document.getElementById('dash-start-btn');
    var continueBtn = document.getElementById('dash-continue-btn');
    var searchInput = document.getElementById('dash-pack-search');
    var blockInfoEl = document.getElementById('dash-exam-block-info');
    var blockMsgEl  = document.getElementById('dash-exam-block-msg');
    var blockIconEl = document.getElementById('dash-exam-block-icon');
    var tiles       = document.querySelectorAll('.dash-cert-tile');

    var certifiedMsg = <?= json_encode(match($lang) {
      'en' => 'Your certification is valid until {date}. You cannot retake the exam before this date. Training remains available.',
      'es' => 'Tu certificación es válida hasta el {date}. No puedes volver a presentarte antes de esa fecha. El entrenamiento sigue disponible.',
      'jp' => '認定は{date}まで有効です。それ以前に再受験することはできません。トレーニングは引き続き利用できます。',
      default => 'Votre certification est valide jusqu\'au {date}. Vous ne pouvez pas repasser l\'examen avant cette date. L\'entraînement reste disponible.',
    }) ?>;
    var cooldownMsg = <?= json_encode(match($lang) {
      'en' => 'You can retake the exam from {date}. Training remains available.',
      'es' => 'Podrás volver a presentarte al examen a partir del {date}. El entrenamiento sigue disponible.',
      'jp' => '{date}から再受験できます。トレーニングは引き続き利用できます。',
      default => 'Vous pourrez repasser l\'examen à partir du {date}. L\'entraînement reste disponible.',
    }) ?>;
    var overrideMsg = <?= json_encode(match($lang) {
      'en' => 'Your access has been unlocked until {date}. You can take the exam now.',
      'es' => 'Tu acceso ha sido desbloqueado hasta el {date}. Puedes realizar el examen ahora.',
      'jp' => '{date}までアクセスが解除されています。今すぐ試験を受けることができます。',
      default => 'Votre accès a été débloqué jusqu\'au {date}. Vous pouvez passer l\'examen dès maintenant.',
    }) ?>;
    var overrideMsgPermanent = <?= json_encode(match($lang) {
      'en' => 'Your access has been unlocked. You can take the exam now.',
      'es' => 'Tu acceso ha sido desbloqueado. Puedes realizar el examen ahora.',
      'jp' => 'アクセスが解除されています。今すぐ試験を受けることができます。',
      default => 'Votre accès a été débloqué. Vous pouvez passer l\'examen dès maintenant.',
    }) ?>;
    var incompleteMsg = <?= json_encode(match($lang) {
      'en' => 'This pack is not yet ready — not enough questions have been configured. Contact an administrator.',
      'es' => 'Este pack aún no está listo — no hay suficientes preguntas configuradas. Contacta con un administrador.',
      'jp' => 'このパックはまだ準備できていません — 問題数が不足しています。管理者に連絡してください。',
      default => 'Ce pack n\'est pas encore prêt — pas assez de questions configurées. Contactez un administrateur.',
    }) ?>;

    var startDefaultLabel      = <?= json_encode(t('dash.start', [], $lang)) ?>;
    var startNewExamLabel      = <?= json_encode(t('dash.start_new_exam', [], $lang)) ?>;
    var startNewTrainingLabel  = <?= json_encode(t('dash.start_new_training', [], $lang)) ?>;
    var continueTrainingLabel  = <?= json_encode(t('dash.continue_current_training', [], $lang)) ?>;
    var overwriteConfirmExam   = <?= json_encode(t('dash.confirm_overwrite_session_exam', [], $lang)) ?>;
    var overwriteConfirmTrain  = <?= json_encode(t('dash.confirm_overwrite_session_training', [], $lang)) ?>;
    var minutesShortLabel      = <?= json_encode(t('dash.minutes_short', [], $lang)) ?>;

    var selectedTile      = null;
    var selectedActiveSid = '';
    var selectedMode      = 'EXAM';

    function getMode() {
      var checked = document.querySelector('input[name="session_type"]:checked');
      return (checked && checked.value === 'TRAINING') ? 'TRAINING' : 'EXAM';
    }

    function updateStartBtn() {
      if (!selectedTile) return;
      selectedMode = getMode();
      var examLocked = selectedTile.getAttribute('data-package-exam-locked') === '1';
      var incomplete = selectedTile.getAttribute('data-package-incomplete') === '1';
      var activeSid  = selectedMode === 'TRAINING'
        ? (selectedTile.getAttribute('data-active-sid-training') || '')
        : (selectedTile.getAttribute('data-active-sid-exam') || '');
      var activeP = selectedMode === 'TRAINING'
        ? (selectedTile.getAttribute('data-active-p-training') || '1')
        : (selectedTile.getAttribute('data-active-p-exam') || '1');

      if (startBtn) {
        if (incomplete) {
          startBtn.textContent = startDefaultLabel;
          startBtn.disabled = true;
        } else if (selectedMode === 'EXAM' && examLocked) {
          startBtn.textContent = startDefaultLabel;
          startBtn.disabled = true;
        } else {
          startBtn.textContent = activeSid
            ? (selectedMode === 'TRAINING' ? startNewTrainingLabel : startNewExamLabel)
            : startDefaultLabel;
          startBtn.disabled = false;
        }
      }
      if (continueBtn) {
        if (!incomplete && activeSid && selectedMode === 'TRAINING') {
          continueBtn.style.display = '';
          continueBtn.textContent = continueTrainingLabel;
          continueBtn.setAttribute('href', '/exam.php?sid=' + encodeURIComponent(activeSid) + '&p=' + encodeURIComponent(activeP) + '&lang=' + encodeURIComponent(<?= json_encode($lang) ?>));
        } else {
          continueBtn.style.display = 'none';
          continueBtn.setAttribute('href', '#');
        }
      }
      selectedActiveSid = activeSid;
    }

    function goToStep2(tile) {
      selectedTile = tile;
      input.value  = tile.getAttribute('data-package-value');
      if (packNameEl) packNameEl.textContent = tile.getAttribute('data-package-name');

      var dur = parseInt(tile.getAttribute('data-package-duration') || '0', 10);
      var durationEl = document.getElementById('dash-exam-duration-display');
      var durationTrainEl = document.getElementById('dash-training-duration-display');
      [durationEl, durationTrainEl].forEach(function (el) {
        if (!el) return;
        if (dur > 0) { el.textContent = '⏱ ' + dur + ' ' + minutesShortLabel; el.style.display = ''; }
        else { el.style.display = 'none'; }
      });

      var blockReason = tile.getAttribute('data-block-reason') || '';
      var blockDate   = tile.getAttribute('data-block-date') || '';
      var incomplete  = tile.getAttribute('data-package-incomplete') === '1';
      if (blockInfoEl && blockMsgEl) {
        if (incomplete) {
          blockInfoEl.className = 'dash-exam-block-info dash-exam-block-info--incomplete';
          blockMsgEl.textContent = incompleteMsg;
          if (blockIconEl) blockIconEl.textContent = '⚠';
          blockInfoEl.style.display = '';
        } else if (blockReason === 'certified') {
          blockInfoEl.className = 'dash-exam-block-info dash-exam-block-info--certified';
          blockMsgEl.textContent = certifiedMsg.replace('{date}', blockDate);
          if (blockIconEl) blockIconEl.textContent = '✓';
          blockInfoEl.style.display = '';
        } else if (blockReason === 'cooldown') {
          blockInfoEl.className = 'dash-exam-block-info dash-exam-block-info--cooldown';
          blockMsgEl.textContent = cooldownMsg.replace('{date}', blockDate);
          if (blockIconEl) blockIconEl.textContent = '⏳';
          blockInfoEl.style.display = '';
        } else if (blockReason === 'override') {
          blockInfoEl.className = 'dash-exam-block-info dash-exam-block-info--override';
          blockMsgEl.textContent = blockDate ? overrideMsg.replace('{date}', blockDate) : overrideMsgPermanent;
          if (blockIconEl) blockIconEl.textContent = '🔓';
          blockInfoEl.style.display = '';
        } else {
          blockInfoEl.style.display = 'none';
        }
      }

      step1.classList.remove('is-active');
      step2.classList.add('is-active');
      updateStartBtn();
    }

    function goToStep1() {
      step2.classList.remove('is-active');
      step1.classList.add('is-active');
    }

    tiles.forEach(function (tile) {
      tile.addEventListener('click', function () { goToStep2(tile); });
    });

    if (backBtn) backBtn.addEventListener('click', goToStep1);

    document.querySelectorAll('input[name="session_type"]').forEach(function (r) {
      r.addEventListener('change', updateStartBtn);
    });

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        var q = searchInput.value.toLowerCase();
        tiles.forEach(function (tile) {
          var haystack = [
            tile.getAttribute('data-package-name') || '',
            tile.getAttribute('data-package-profile') || '',
          ].join(' ').toLowerCase();
          tile.style.display = haystack.includes(q) ? '' : 'none';
        });
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        if (!selectedActiveSid) return;
        var msg = selectedMode === 'TRAINING' ? overwriteConfirmTrain : overwriteConfirmExam;
        if (!window.confirm(msg)) e.preventDefault();
      });
    }
  })();
</script>
<script>
  (function () {
    var stickyTop = document.querySelector('.dashboard-sticky-top');
    if (!stickyTop) return;
    function updateStickyOffset() {
      document.documentElement.style.setProperty('--dashboard-sticky-offset', (stickyTop.offsetHeight + 16) + 'px');
    }
    updateStickyOffset();
    window.addEventListener('resize', updateStickyOffset);
  })();
</script>
</body>
</html>

