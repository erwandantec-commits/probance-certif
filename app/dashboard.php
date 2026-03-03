<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

$pdo = db();
$user = require_auth();
$lang = get_lang();
$uid = (int)$user['id'];

function dashboard_package_column_exists(PDO $pdo, string $column): bool {
  static $cache = [];
  if (isset($cache[$column])) {
    return $cache[$column];
  }
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'packages'
      AND COLUMN_NAME = ?
  ");
  $st->execute([$column]);
  $cache[$column] = ((int)$st->fetchColumn() > 0);
  return $cache[$column];
}

$hasPackageProfileColumn = dashboard_package_column_exists($pdo, 'profile');
$hasPackageDisplayOrderColumn = dashboard_package_column_exists($pdo, 'display_order');
$hasPackageBadgeImageColumn = dashboard_package_column_exists($pdo, 'badge_image_filename');

$errKey = trim((string)($_GET['err_key'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));

$pkgCols = "id,name,name_color_hex,duration_limit_minutes";
if ($hasPackageProfileColumn) {
  $pkgCols .= ", profile";
}
if ($hasPackageDisplayOrderColumn) {
  $pkgCols .= ", display_order";
}
$pkgOrder = $hasPackageDisplayOrderColumn ? "ORDER BY display_order ASC, id ASC" : "ORDER BY id DESC";
$pkgStmt = $pdo->query("SELECT $pkgCols FROM packages $pkgOrder");
$packages = $pkgStmt->fetchAll();

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
if (!in_array($latestResult, ['PASSED', 'FAILED', 'ACTIVE', 'EXPIRED'], true)) {
  $latestResult = '';
}
$hasTerminationType = sessions_column_exists($pdo, 'termination_type');

$lastConds = ["s.user_id=?"];
$lastParams = [$uid];
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
} elseif ($latestResult === 'ACTIVE') {
  $lastConds[] = "s.status='ACTIVE'";
} elseif ($latestResult === 'EXPIRED') {
  if ($hasTerminationType) {
    $lastConds[] = "(s.status='EXPIRED' OR (s.status='TERMINATED' AND s.termination_type='TIMEOUT'))";
  } else {
    $lastConds[] = "s.status='EXPIRED'";
  }
}

$lastWhere = implode(' AND ', $lastConds);
$lastTerminationTypeSelect = $hasTerminationType
  ? ", s.termination_type"
  : ", 'MANUAL' AS termination_type";
$lastStmt = $pdo->prepare("
  SELECT s.id, s.status, s.session_type, s.started_at, s.submitted_at, s.score_percent, s.passed, pk.name as package_name, pk.name_color_hex AS package_color_hex
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
$certSuccessStmt = $pdo->prepare("
  SELECT s.id, s.contact_id, s.package_id, s.started_at, s.submitted_at, pk.name AS package_name, pk.name_color_hex AS package_color_hex
    $certProfileSelect
    $certDisplayOrderSelect
    $certBadgeImageSelect
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.user_id=?
    AND s.session_type='EXAM'
    AND s.status='TERMINATED'
    AND s.passed=1
  ORDER BY s.started_at DESC
");
$certSuccessStmt->execute([$uid]);
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
  $statusInfo = certification_status_from_last_success($baseDate);
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
foreach ($certCards as $pkgId => $card) {
  $statusKey = (string)($card['status_key'] ?? '');
  $examBlockedByPackage[(int)$pkgId] = in_array($statusKey, ['CERTIFIED', 'SOON'], true);
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
  ORDER BY s.started_at DESC
");
$activeStmt->execute([$uid]);
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

function dash_display_status(array $s): string {
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
    default => $status,
  };
}

function dash_status_badge_class(string $status): string {
  return match ($status) {
    'TERMINATED' => 'pill success',
    'ACTIVE' => 'pill info',
    'EXPIRED' => 'pill warning',
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
  if ((string)dash_display_status($s) === 'ACTIVE' || $s['passed'] === null) {
    return t('dash.na', [], $lang);
  }
  return ((int)$s['passed'] === 1) ? t('dash.result.passed', [], $lang) : t('dash.result.failed', [], $lang);
}

function dash_result_badge_class(array $s): string {
  if ((string)dash_display_status($s) === 'ACTIVE' || $s['passed'] === null) {
    return 'pill';
  }
  return ((int)$s['passed'] === 1) ? 'pill success' : 'pill danger';
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
  <meta charset="utf-8">
  <title><?= h(t('dash.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container dashboard-container">
  <div class="dashboard-head">
    <div class="dashboard-head-copy">
      <img class="dashboard-candidate-logo" src="/assets/logo-candidat.svg" alt="Logo candidat">
      <div class="dashboard-head-copy-text">
        <h2 class="h1"><?= h(t('dash.hello', ['name' => ($user['name'] ?: $user['email'])], $lang)) ?></h2>
        <p class="sub"><?= h(t('dash.subtitle', [], $lang)) ?></p>
      </div>
    </div>

      <div class="dashboard-head-actions">
      <div class="lang-switch">
        <select id="dash-lang" class="input lang-select"
                onchange="window.location.href='/dashboard.php?lang=' + encodeURIComponent(this.value);">
          <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>><?= h(t('lang.fr', [], $lang)) ?></option>
          <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>><?= h(t('lang.en', [], $lang)) ?></option>
          <option value="es" <?= $lang === 'es' ? 'selected' : '' ?>><?= h(t('lang.es', [], $lang)) ?></option>
          <option value="jp" <?= $lang === 'jp' ? 'selected' : '' ?>><?= h(t('lang.jp', [], $lang)) ?></option>
        </select>
      </div>
      <div class="dashboard-main-actions">
        <?php if (($user['role'] ?? 'USER') === 'ADMIN'): ?>
          <a class="btn ghost dashboard-admin-btn" href="/admin/">
            <?= h(t('dash.admin', [], $lang)) ?>
          </a>
        <?php endif; ?>
        <a class="btn ghost dashboard-logout-btn" href="/logout.php">
          <svg class="logout-inline-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 16l4-4-4-4M19 12H9"/>
          </svg>
          <span><?= h(t('dash.logout', [], $lang)) ?></span>
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

  <nav class="dashboard-jump" aria-label="Dashboard sections">
    <a class="dashboard-jump-link" href="#sec-certifications"><?= h(t('dash.certifications.title', [], $lang)) ?></a>
    <a class="dashboard-jump-link" href="#sec-start"><?= h(t('dash.start_cert', [], $lang)) ?></a>
    <a class="dashboard-jump-link" href="#sec-active"><?= h(t('dash.active_sessions.title', [], $lang)) ?></a>
    <a class="dashboard-jump-link" href="#sec-latest"><?= h(t('dash.last_sessions', [], $lang)) ?></a>
  </nav>

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
              <div class="dashboard-cert-card-meta"><b>Profil:</b> <?= h(localize_text((string)$card['package_profile'], $lang)) ?></div>
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
	      <div class="dashboard-start-grid">
		        <div class="dashboard-field dashboard-cert-field">
		          <label class="label"><?= h(t('dash.cert', [], $lang)) ?></label>
		          <?php if ($packages): ?>
		            <?php $selectedPkg = $packages[0]; ?>
		            <input id="dash-package-input" type="hidden" name="package_id" value="<?= (int)$selectedPkg['id'] ?>">
		          <?php endif; ?>
		          <?php if ($packages): ?>
		            <?php
		              $firstPkg = $packages[0];
		            ?>
		            <div class="dash-cert-grid" id="dash-cert-grid">
		              <?php foreach ($packages as $pk): ?>
	                <?php
	                  $pkName = localize_text((string)$pk['name'], $lang);
	                  $pkCode = strtoupper(trim((string)$pk['name']));
	                  $pkTone = package_color_hex((string)$pk['name'], (string)($pk['name_color_hex'] ?? ''));
	                  $pkDuration = (int)$pk['duration_limit_minutes'];
	                  $isFirst = ((int)$pk['id'] === (int)$firstPkg['id']);
                    $isExamLocked = !empty($examBlockedByPackage[(int)$pk['id']]);
	                ?>
		                <button
		                  type="button"
		                  class="dash-cert-tile<?= $isFirst ? ' is-active' : '' ?><?= $isExamLocked ? ' is-exam-locked' : '' ?><?= $pkCode === 'BLACK' ? ' pkg-black' : '' ?>"
		                  data-package-value="<?= (int)$pk['id'] ?>"
		                  data-package-name="<?= h($pkName) ?>"
		                  data-active-sid-exam="<?= h((string)($activeByPackage[(int)$pk['id']]['EXAM']['id'] ?? '')) ?>"
		                  data-active-p-exam="<?= (int)($activeByPackage[(int)$pk['id']]['EXAM']['resume_p'] ?? 1) ?>"
		                  data-active-sid-training="<?= h((string)($activeByPackage[(int)$pk['id']]['TRAINING']['id'] ?? '')) ?>"
		                  data-active-p-training="<?= (int)($activeByPackage[(int)$pk['id']]['TRAINING']['resume_p'] ?? 1) ?>"
		                  data-package-exam-locked="<?= $isExamLocked ? '1' : '0' ?>"
		                  data-package-duration="<?= (int)$pkDuration ?>"
		                  data-package-tone="<?= h($pkTone) ?>"
		                  style="--cert-tone: <?= h($pkTone) ?>;"
		                >
	                  <span class="dash-cert-tile-name"><?= h($pkName) ?></span>
	                  <span class="dash-cert-tile-time"><?= (int)$pkDuration ?> min</span>
	                </button>
		              <?php endforeach; ?>
		            </div>
		          <?php else: ?>
		            <div class="dash-cert-empty"><?= h(t('dash.no_active_packages', [], $lang)) ?></div>
		          <?php endif; ?>
		        </div>

        <div class="dashboard-field dashboard-session-mode">
          <label class="label"><?= h(t('dash.session_type', [], $lang)) ?></label>
          <div class="dashboard-mode-group" role="radiogroup" aria-label="<?= h(t('dash.session_type', [], $lang)) ?>">
            <label class="dashboard-mode-option">
              <input type="radio" name="session_type" value="EXAM" checked>
              <span class="dashboard-mode-content">
                <span class="dashboard-mode-title"><?= h(t('dash.session_type.exam', [], $lang)) ?></span>
                <span class="dashboard-mode-hint"><?= h(t('dash.session_type.exam_hint', [], $lang)) ?></span>
                <span class="dashboard-mode-icon" aria-hidden="true">
                  <svg class="dashboard-mode-icon-cert" viewBox="0 0 24 24" focusable="false">
                    <path d="M12 2.8l1.5 1.1 1.8.2 1.1 1.5 1.7.7.2 1.8 1.1 1.5-.7 1.7.2 1.8-1.5 1.1-.7 1.7-1.8.2-1.5 1.1-1.7-.7-1.8.2-1.1-1.5-1.7-.7-.2-1.8-1.1-1.5.7-1.7-.2-1.8 1.5-1.1.7-1.7 1.8-.2L12 2.8z"/>
                    <circle cx="12" cy="10.9" r="4.3"/>
                    <path d="M10.1 10.9l1.3 1.3 2.6-2.6"/>
                    <path d="M7.2 15.8l-2 4 2.1-.5 1 2L10.3 17"/>
                    <path d="M16.8 15.8l2 4-2.1-.5-1 2L13.7 17"/>
                  </svg>
                </span>
              </span>
            </label>
            <label class="dashboard-mode-option">
              <input type="radio" name="session_type" value="TRAINING">
              <span class="dashboard-mode-content">
                <span class="dashboard-mode-title"><?= h(t('dash.session_type.training', [], $lang)) ?></span>
                <span class="dashboard-mode-hint"><?= h(t('dash.session_type.training_hint', [], $lang)) ?></span>
                <span class="dashboard-mode-icon" aria-hidden="true">
                  <svg class="dashboard-mode-icon-training" viewBox="0 0 24 24" focusable="false">
                    <circle cx="12" cy="4" r="2.2"/>
                    <path d="M4.2 7.5h1.4v4h-1.4zM6.1 6.8h1v5.4h-1zM16.9 6.8h1v5.4h-1zM18.4 7.5h1.4v4h-1.4z"/>
                    <path d="M7.1 8.5h9.8v1.6H7.1z"/>
                    <path d="M10.1 11.2h3.8v2.9l1.6 2.2v4.2h-2.1v-3.4L12 15.5l-1.3 1.6v3.4H8.6v-4.2l1.5-2.2z"/>
                  </svg>
                </span>
              </span>
            </label>
          </div>
        </div>
      </div>

		      <div class="dashboard-start-foot">
		        <div class="dashboard-start-action">
		          <button id="dash-start-btn" class="btn" type="submit" <?= $packages ? '' : 'disabled' ?>><?= h(t('dash.start', [], $lang)) ?></button>
              <a id="dash-continue-btn"
                 class="btn ghost"
                 href="#"
                 style="display:none;"
              ><?= h(t('dash.continue_current', [], $lang)) ?></a>
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
              <a class="btn" href="/exam.php?sid=<?= h((string)$a['id']) ?>&p=<?= (int)$a['resume_p'] ?>&lang=<?= h($lang) ?>">
                <?= h(((string)$a['session_type'] === 'TRAINING') ? t('dash.continue_current_training', [], $lang) : t('dash.continue_current_exam', [], $lang)) ?>
              </a>
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

    <form method="get" class="filters-grid" style="margin-bottom:12px;">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <div>
        <label class="label"><?= h(t('dash.col.cert', [], $lang)) ?></label>
        <select name="latest_cert">
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
        <select name="latest_type">
          <option value="">--</option>
          <option value="EXAM" <?= $latestType === 'EXAM' ? 'selected' : '' ?>><?= h(t('dash.session_type.exam', [], $lang)) ?></option>
          <option value="TRAINING" <?= $latestType === 'TRAINING' ? 'selected' : '' ?>><?= h(t('dash.session_type.training', [], $lang)) ?></option>
        </select>
      </div>
      <div>
        <label class="label"><?= h(t('dash.col.result', [], $lang)) ?></label>
        <select name="latest_result">
          <option value="">--</option>
          <option value="PASSED" <?= $latestResult === 'PASSED' ? 'selected' : '' ?>><?= h(t('dash.result.passed', [], $lang)) ?></option>
          <option value="FAILED" <?= $latestResult === 'FAILED' ? 'selected' : '' ?>><?= h(t('dash.result.failed', [], $lang)) ?></option>
          <option value="ACTIVE" <?= $latestResult === 'ACTIVE' ? 'selected' : '' ?>><?= h(t('dash.status.active', [], $lang)) ?></option>
          <option value="EXPIRED" <?= $latestResult === 'EXPIRED' ? 'selected' : '' ?>><?= h(t('dash.status.expired', [], $lang)) ?></option>
        </select>
      </div>
      <div class="filters-actions">
        <button class="btn ghost" type="submit">Filtrer</button>
        <a class="btn ghost" href="/dashboard.php?lang=<?= h(urlencode($lang)) ?>#sec-latest">Reinitialiser</a>
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
	                <?php if ($s['status'] === 'ACTIVE'): ?>
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
	                <?php else: ?>
	                  <a class="btn ghost" href="/result.php?sid=<?= h($s['id']) ?>&lang=<?= h($lang) ?>"><?= h(t('dash.view', [], $lang)) ?></a>
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
</div>
<script src="/assets/package-colors.js"></script>
<script>
  (function () {
    var form = document.querySelector('.dashboard-start-form');
    var input = document.getElementById('dash-package-input');
    var tiles = document.querySelectorAll('.dash-cert-tile');
    var startBtn = document.getElementById('dash-start-btn');
    var continueBtn = document.getElementById('dash-continue-btn');
    var startDefaultLabel = <?= json_encode(t('dash.start', [], $lang)) ?>;
    var startNewExamLabel = <?= json_encode(t('dash.start_new_exam', [], $lang)) ?>;
    var startNewTrainingLabel = <?= json_encode(t('dash.start_new_training', [], $lang)) ?>;
    var continueExamLabel = <?= json_encode(t('dash.continue_current_exam', [], $lang)) ?>;
    var continueTrainingLabel = <?= json_encode(t('dash.continue_current_training', [], $lang)) ?>;
    var overwriteConfirmExamText = <?= json_encode(t('dash.confirm_overwrite_session_exam', [], $lang)) ?>;
    var overwriteConfirmTrainingText = <?= json_encode(t('dash.confirm_overwrite_session_training', [], $lang)) ?>;
    var selectedActiveSid = '';
    var selectedMode = 'EXAM';
    if (!input || !tiles.length) return;

    function getMode() {
      var checked = document.querySelector('input[name="session_type"]:checked');
      var mode = checked ? checked.value : 'EXAM';
      return mode === 'TRAINING' ? 'TRAINING' : 'EXAM';
    }

    function setActive(value) {
      var activeSid = '';
      var activeP = '1';
      var selectedExamLocked = false;
      selectedMode = getMode();
      tiles.forEach(function (tile) {
        var active = (tile.getAttribute('data-package-value') === value);
        tile.classList.toggle('is-active', active);
        if (active) {
          selectedExamLocked = (tile.getAttribute('data-package-exam-locked') === '1');
          if (selectedMode === 'TRAINING') {
            activeSid = tile.getAttribute('data-active-sid-training') || '';
            activeP = tile.getAttribute('data-active-p-training') || '1';
          } else {
            activeSid = tile.getAttribute('data-active-sid-exam') || '';
            activeP = tile.getAttribute('data-active-p-exam') || '1';
          }
        }
      });

      if (startBtn) {
        if (selectedMode === 'EXAM' && selectedExamLocked) {
          startBtn.textContent = startDefaultLabel;
          startBtn.disabled = true;
        } else if (!activeSid) {
          startBtn.textContent = startDefaultLabel;
          startBtn.disabled = false;
        } else {
          startBtn.textContent = (selectedMode === 'TRAINING') ? startNewTrainingLabel : startNewExamLabel;
          startBtn.disabled = false;
        }
      }
      if (continueBtn) {
        if (activeSid && !(selectedMode === 'EXAM' && selectedExamLocked)) {
          continueBtn.style.display = '';
          continueBtn.textContent = (selectedMode === 'TRAINING') ? continueTrainingLabel : continueExamLabel;
          continueBtn.setAttribute('href', '/exam.php?sid=' + encodeURIComponent(activeSid) + '&p=' + encodeURIComponent(activeP) + '&lang=' + encodeURIComponent(<?= json_encode($lang) ?>));
        } else {
          continueBtn.style.display = 'none';
          continueBtn.setAttribute('href', '#');
        }
      }
      selectedActiveSid = activeSid;
    }

    tiles.forEach(function (tile) {
      tile.addEventListener('click', function () {
        var value = tile.getAttribute('data-package-value');
        if (!value) return;
        input.value = value;
        setActive(value);
      });
    });

    document.querySelectorAll('input[name="session_type"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        setActive(input.value);
      });
    });

    setActive(input.value);

    if (form) {
      form.addEventListener('submit', function (e) {
        if (!selectedActiveSid) return;
        var confirmText = (selectedMode === 'TRAINING') ? overwriteConfirmTrainingText : overwriteConfirmExamText;
        if (!window.confirm(confirmText)) {
          e.preventDefault();
        }
      });
    }
  })();
</script>
</body>
</html>

