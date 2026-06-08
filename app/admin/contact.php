<?php
require_once __DIR__ . '/_auth.php';
$adminUser = require_team_reporting();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../services/session_service.php';
require_once __DIR__ . '/_nav.php';
$pdo = db();
$hasEmailControlBypassColumn = auth_column_exists($pdo, 'users', 'email_control_bypass');

if (empty($_SESSION['admin_contact_csrf']) || !is_string($_SESSION['admin_contact_csrf'])) {
  $_SESSION['admin_contact_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['admin_contact_csrf'];
if (empty($_SESSION['admin_users_csrf']) || !is_string($_SESSION['admin_users_csrf'])) {
  $_SESSION['admin_users_csrf'] = bin2hex(random_bytes(32));
}
$userEditCsrfToken = (string)$_SESSION['admin_users_csrf'];

function admin_contact_format_cert_expiry(?DateTimeImmutable $expiresAt, bool $isRevoked, string $lang = 'fr'): string {
  if (!$expiresAt) {
    return '-';
  }

  $dateLabel = $expiresAt->format('Y-m-d');
  if ($isRevoked) {
    return $dateLabel . ' ' . t('admin.contact.cert_expiry_revoked', [], $lang);
  }

  $today = new DateTimeImmutable('today');
  if ($expiresAt < $today) {
    return $dateLabel . ' ' . t('admin.contact.cert_expiry_expired', [], $lang);
  }

  $remainingDays = (int)$today->diff($expiresAt)->format('%a');
  if ($remainingDays < 1) {
    $remainingDays = 1;
  }

  return $dateLabel . ' ' . t('admin.contact.cert_expiry_days', ['count' => $remainingDays], $lang);
}

function admin_contact_guess_first_last(?string $fullName): array {
  $fullName = trim((string)$fullName);
  if ($fullName === '') {
    return ['', ''];
  }
  $parts = preg_split('/\s+/', $fullName) ?: [];
  if (count($parts) <= 1) {
    return [$fullName, ''];
  }
  $first = (string)array_shift($parts);
  $last = trim(implode(' ', $parts));
  return [$first, $last];
}

function admin_contact_role_label(string $role): string {
  return match (normalize_user_role($role)) {
    'ADMIN' => 'ADMIN',
    'OWNER' => 'OWNER',
    default => 'USER',
  };
}

function admin_contact_assignable_roles(array $actor, ?string $currentRole = null): array {
  $roles = [];
  foreach (['USER', 'OWNER', 'ADMIN'] as $role) {
    if (user_can_assign_role($actor, $role)) {
      $roles[] = $role;
    }
  }
  $currentRole = normalize_user_role($currentRole);
  if ($currentRole !== '' && !in_array($currentRole, $roles, true)) {
    $roles[] = $currentRole;
  }
  return $roles;
}

function admin_contact_normalize_ids(mixed $raw): array {
  if (!is_array($raw)) {
    return [];
  }
  $ids = [];
  foreach ($raw as $value) {
    $id = (int)$value;
    if ($id > 0) {
      $ids[$id] = $id;
    }
  }
  return array_values($ids);
}

function admin_contact_normalize_program_roles(mixed $raw, array $allowedProgramIds, array $actor): array {
  if (!is_array($raw)) {
    return [];
  }
  $allowed = array_fill_keys($allowedProgramIds, true);
  $roles = [];
  foreach ($raw as $programIdRaw => $roleRaw) {
    $programId = (int)$programIdRaw;
    if ($programId <= 0 || !isset($allowed[$programId])) {
      continue;
    }
    $role = strtoupper(trim((string)$roleRaw));
    if ($role === 'OWNER' && user_has_role($actor, ['ADMIN', 'OWNER'])) {
      $roles[$programId] = 'OWNER';
    } elseif ($role === 'USER') {
      $roles[$programId] = 'USER';
    }
  }
  return $roles;
}

$activeProgramId = auth_admin_program_context($pdo, $adminUser, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
$canMutateReporting = user_can_access_admin_area($adminUser);
$packagesWhereSql = $activeProgramId > 0
  ? (' AND ' . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false))
  : '';

$email = trim($_GET['email'] ?? '');
if ($email === '') { http_response_code(400); echo "Missing email"; exit; }

$sessionEndExpr = sessions_column_exists($pdo, 'ended_at')
  ? "COALESCE(s.ended_at, s.submitted_at, s.started_at)"
  : "COALESCE(s.submitted_at, s.started_at)";
$hasCertValidityDaysColumn = table_column_exists($pdo, 'packages', 'cert_validity_days');
$certValidityDaysSelect = $hasCertValidityDaysColumn
  ? "pk.cert_validity_days AS cert_validity_days"
  : "365 AS cert_validity_days";
$certValidityDaysGroup = $hasCertValidityDaysColumn ? ", pk.cert_validity_days" : "";

$hsort = $_GET['hsort'] ?? 'started_at';
$hdir  = strtoupper($_GET['hdir'] ?? 'DESC');
$htype = strtoupper(trim((string)($_GET['htype'] ?? 'ALL')));
$hstatus = strtoupper(trim((string)($_GET['hstatus'] ?? 'ALL')));
$hpackage = trim((string)($_GET['hpackage'] ?? 'ALL'));

$allowedSort = ['started_at', 'score_percent'];
$allowedDir  = ['ASC', 'DESC'];
if (!in_array($htype, ['ALL', 'EXAM', 'TRAINING'], true)) $htype = 'ALL';
if (!in_array($hstatus, ['ALL', 'ACTIVE', 'TERMINATED', 'EXPIRED'], true)) $hstatus = 'ALL';
if (!in_array($hsort, $allowedSort, true)) $hsort = 'started_at';
if (!in_array($hdir, $allowedDir, true)) $hdir = 'DESC';

$hresult = strtoupper(trim($_GET['hresult'] ?? 'ALL'));
$allowedResults = ['ALL', 'PASSED', 'FAILED'];
if (!in_array($hresult, $allowedResults, true)) $hresult = 'ALL';
$unlockDays = max(1, min(3650, (int)($_GET['unlock_days'] ?? 7)));
$unlockError = trim((string)($_GET['unlock_err'] ?? ''));
$unlockOk = trim((string)($_GET['unlock_ok'] ?? ''));
$reblockError = trim((string)($_GET['reblock_err'] ?? ''));
$reblockOk = trim((string)($_GET['reblock_ok'] ?? ''));
$overridePage = max(1, (int)($_GET['opage'] ?? 1));
$historyPage = max(1, (int)($_GET['hpage'] ?? 1));
$overrideLimit = 10;
$historyLimit = 10;

function admin_session_type_label(string $type, string $lang = 'fr'): string {
  return match ($type) {
    'EXAM' => t('admin.sessions.type_exam', [], $lang),
    'TRAINING' => t('admin.sessions.type_training', [], $lang),
    default => $type,
  };
}

function admin_contact_session_has_result(array $session): bool {
  return in_array((string)($session['status'] ?? ''), ['TERMINATED', 'EXPIRED'], true)
    && $session['passed'] !== null
    && $session['passed'] !== '';
}

$stmt = $pdo->prepare("SELECT * FROM contacts WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$contact = $stmt->fetch();
if (!$contact) { http_response_code(404); echo "Contact not found"; exit; }

$linkedUserEmailControlSelect = $hasEmailControlBypassColumn ? ', u.email_control_bypass' : ', 0 AS email_control_bypass';
$linkedUserStmt = $pdo->prepare("
  SELECT u.id, u.email, u.name, u.role $linkedUserEmailControlSelect
  FROM users u
  WHERE u.email = ?
  LIMIT 1
");
$linkedUserStmt->execute([$email]);
$linkedUser = $linkedUserStmt->fetch();
$linkedUserId = (int)($linkedUser['id'] ?? 0);
$canManageLinkedUser = $linkedUserId > 0 && (
  $linkedUserId === (int)($adminUser['id'] ?? 0)
  || (
    user_can_manage_target_role($adminUser, (string)($linkedUser['role'] ?? 'USER'))
    && (
      user_has_role($adminUser, 'ADMIN')
      || auth_users_share_program_scope($pdo, (int)($adminUser['id'] ?? 0), $linkedUserId)
    )
  )
);
$contactNotice = $_SESSION['admin_users_notice'] ?? null;
unset($_SESSION['admin_users_notice']);
$contactEditFormSession = $_SESSION['admin_users_edit_form'] ?? null;
unset($_SESSION['admin_users_edit_form']);
$contactEditForm = [];
$contactEditFormUserId = 0;
if (is_array($contactEditFormSession)) {
  $contactEditFormUserId = (int)($contactEditFormSession['user_id'] ?? 0);
  if (is_array($contactEditFormSession['data'] ?? null)) {
    $contactEditForm = $contactEditFormSession['data'];
  }
}

$programRows = user_has_role($adminUser, 'ADMIN') ? auth_accessible_programs($pdo, $adminUser) : auth_manageable_programs($pdo, $adminUser);
$programLabelsById = [];
foreach ($programRows as $programRow) {
  $programId = (int)($programRow['id'] ?? 0);
  if ($programId > 0) {
    $programLabelsById[$programId] = trim((string)($programRow['name'] ?? 'Programme'));
  }
}
$linkedUserProgramIds = [];
$linkedUserProgramRoles = [];
if ($linkedUserId > 0 && auth_table_exists($pdo, 'user_program_access')) {
  $stPrograms = $pdo->prepare("SELECT program_id, " . auth_program_access_role_expr($pdo, 'user_program_access') . " AS access_role FROM user_program_access WHERE user_id = ? ORDER BY program_id ASC");
  $stPrograms->execute([$linkedUserId]);
  foreach ($stPrograms->fetchAll() ?: [] as $row) {
    $programId = (int)($row['program_id'] ?? 0);
    if ($programId > 0) {
      $linkedUserProgramIds[] = $programId;
      $linkedUserProgramRoles[$programId] = auth_normalize_program_access_role((string)($row['access_role'] ?? 'USER'));
    }
  }
}
$hasDisplayOrderColumn = table_column_exists($pdo, 'packages', 'display_order');
$packageOrder = $hasDisplayOrderColumn ? "ORDER BY display_order ASC, id ASC" : "ORDER BY id ASC";
$packagesStmt = $pdo->query("SELECT pk.id, pk.name, pk.name_color_hex FROM packages pk WHERE pk.is_active=1 $packagesWhereSql $packageOrder");
$packages = $packagesStmt->fetchAll() ?: [];
$packageIds = array_map(fn($pkg) => (string)$pkg['id'], $packages);
if ($hpackage !== 'ALL' && !in_array($hpackage, $packageIds, true)) {
  $hpackage = 'ALL';
}

$activeOverrides = [];
$overrideTotalRows = 0;
$overrideTotalPages = 1;
if ($linkedUserId > 0 && table_exists($pdo, 'exam_cooldown_overrides')) {
  $activeOverridesCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM exam_cooldown_overrides o
    JOIN packages pk ON pk.id = o.package_id
    WHERE o.user_id = ?
      " . ($activeProgramId > 0 ? "AND " . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false) : "") . "
  ");
  $activeOverridesCountStmt->execute([$linkedUserId]);
  $overrideTotalRows = (int)$activeOverridesCountStmt->fetchColumn();
  $overrideTotalPages = max(1, (int)ceil($overrideTotalRows / $overrideLimit));
  if ($overridePage > $overrideTotalPages) {
    $overridePage = $overrideTotalPages;
  }
  $overrideOffset = ($overridePage - 1) * $overrideLimit;

  $activeOverridesStmt = $pdo->prepare("
    SELECT
      o.id,
      o.package_id,
      o.is_active,
      o.created_at,
      o.expires_at,
      o.used_at,
      o.reason,
      pk.name AS package_name,
      pk.name_color_hex AS package_color_hex,
      cu.email AS created_by_email
    FROM exam_cooldown_overrides o
    JOIN packages pk ON pk.id = o.package_id
    LEFT JOIN users cu ON cu.id = o.created_by_user_id
    WHERE o.user_id = ?
      " . ($activeProgramId > 0 ? "AND " . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false) : "") . "
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT ? OFFSET ?
  ");
  $activeOverridesStmt->bindValue(1, $linkedUserId, PDO::PARAM_INT);
  $activeOverridesStmt->bindValue(2, $overrideLimit, PDO::PARAM_INT);
  $activeOverridesStmt->bindValue(3, $overrideOffset, PDO::PARAM_INT);
  $activeOverridesStmt->execute();
  $activeOverrides = $activeOverridesStmt->fetchAll() ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canMutateReporting) {
    header('Location: /admin/contact.php?email=' . urlencode($email) . ($activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '') . '&unlock_err=' . urlencode('Action reservee aux owners et admins.'));
    exit;
  }
  $action = (string)($_POST['action'] ?? '');
  $postedToken = (string)($_POST['csrf_token'] ?? '');
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    $qs = http_build_query([
      'email' => $email,
      'hsort' => $hsort,
      'hdir' => $hdir,
      'hresult' => $hresult,
      'unlock_err' => 'Token de securite invalide.',
    ]);
    header('Location: /admin/contact.php?' . $qs);
    exit;
  }

  if ($action === 'unlock_exam') {
    $packageId = (int)($_POST['unlock_package_id'] ?? 0);
    $unlockDays = (int)($_POST['unlock_days'] ?? 7);
    $unlockDays = max(1, min(3650, $unlockDays));
    $reason = trim((string)($_POST['unlock_reason'] ?? ''));

    if (!table_exists($pdo, 'exam_cooldown_overrides')) {
      $err = 'Schema non migre: table exam_cooldown_overrides absente.';
    } elseif ($linkedUserId <= 0) {
      $err = 'Aucun compte utilisateur lie a cet email.';
    } elseif ($packageId <= 0) {
      $err = 'Certification invalide.';
    } else {
      $checkPkg = $pdo->prepare("SELECT id FROM packages WHERE id=? LIMIT 1");
      $checkPkg->execute([$packageId]);
      if (!$checkPkg->fetch()) {
        $err = 'Certification introuvable.';
      } else {
        $insOverride = $pdo->prepare("
          INSERT INTO exam_cooldown_overrides(
            user_id, package_id, is_active, reason, created_by_user_id, expires_at
          )
          VALUES(?, ?, 1, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
        ");
        $insOverride->execute([
          $linkedUserId,
          $packageId,
          ($reason !== '' ? $reason : null),
          (int)($adminUser['id'] ?? 0) ?: null,
          $unlockDays,
        ]);
        $qs = http_build_query([
          'email' => $email,
          'hsort' => $hsort,
          'hdir' => $hdir,
          'hresult' => $hresult,
          'unlock_ok' => '1',
        ]);
        header('Location: /admin/contact.php?' . $qs);
        exit;
      }
    }

    $qs = http_build_query([
      'email' => $email,
      'hsort' => $hsort,
      'hdir' => $hdir,
      'hresult' => $hresult,
      'unlock_days' => $unlockDays,
      'unlock_err' => $err ?? 'Erreur de deblocage.',
    ]);
    header('Location: /admin/contact.php?' . $qs);
    exit;
  }

  if ($action === 'reblock_exam') {
    $packageId = (int)($_POST['reblock_package_id'] ?? 0);

    if (!table_exists($pdo, 'exam_cooldown_overrides')) {
      $err = 'Schema non migre: table exam_cooldown_overrides absente.';
    } elseif ($linkedUserId <= 0) {
      $err = 'Aucun compte utilisateur lie a cet email.';
    } elseif ($packageId <= 0) {
      $err = 'Certification invalide.';
    } else {
      $checkPkg = $pdo->prepare("SELECT id FROM packages WHERE id=? LIMIT 1");
      $checkPkg->execute([$packageId]);
      if (!$checkPkg->fetch()) {
        $err = 'Certification introuvable.';
      } else {
        $disableOverrides = $pdo->prepare("
          UPDATE exam_cooldown_overrides
          SET is_active = 0
          WHERE user_id = ?
            AND package_id = ?
            AND is_active = 1
            AND used_at IS NULL
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $disableOverrides->execute([$linkedUserId, $packageId]);
        if ((int)$disableOverrides->rowCount() > 0) {
          $qs = http_build_query([
            'email' => $email,
            'hsort' => $hsort,
            'hdir' => $hdir,
            'hresult' => $hresult,
            'reblock_ok' => '1',
          ]);
          header('Location: /admin/contact.php?' . $qs);
          exit;
        }
        $err = 'Aucun deblocage actif a rebloquer pour cette certification.';
      }
    }

    $qs = http_build_query([
      'email' => $email,
      'hsort' => $hsort,
      'hdir' => $hdir,
      'hresult' => $hresult,
      'reblock_err' => $err ?? 'Erreur de rebloquage.',
    ]);
    header('Location: /admin/contact.php?' . $qs);
    exit;
  }
}

$summaryStmt = $pdo->prepare("
  SELECT
    COUNT(*) AS total_sessions,
    MAX(s.started_at) AS last_activity,
    SUM(s.session_type='EXAM' AND s.status='TERMINATED' AND s.passed=1) AS passed_exam_count,
    ROUND(AVG(CASE WHEN s.session_type='EXAM' AND s.status='TERMINATED' AND s.score_percent IS NOT NULL
                   THEN s.score_percent END), 1) AS avg_exam_score
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.contact_id = ?
    " . ($activeProgramId > 0 ? "AND " . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false) : "") . "
");
$summaryStmt->execute([(int)$contact['id']]);
$summary = $summaryStmt->fetch();

$certsStmt = $pdo->prepare("
  SELECT
    s.package_id,
    pk.name AS package_name,
    pk.name_color_hex AS package_color_hex,
    $certValidityDaysSelect,
    MAX(s.started_at) AS last_started_at,
    MAX($sessionEndExpr) AS last_cert_date
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.contact_id = ?
    AND s.status = 'TERMINATED'
    AND s.passed = 1
    AND s.session_type = 'EXAM'
    " . ($activeProgramId > 0 ? "AND " . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false) : "") . "
  GROUP BY s.package_id, pk.name, pk.name_color_hex$certValidityDaysGroup
  ORDER BY last_cert_date DESC
");
$certsStmt->execute([(int)$contact['id']]);
$certs = $certsStmt->fetchAll();

$latestCertSessionStmt = $pdo->prepare("
  SELECT s.id
  FROM sessions s
  WHERE s.contact_id = ?
    AND s.package_id = ?
    AND s.session_type = 'EXAM'
    AND s.status = 'TERMINATED'
    AND s.passed = 1
  ORDER BY
    EXISTS(SELECT 1 FROM session_questions sq WHERE sq.session_id = s.id) DESC,
    $sessionEndExpr DESC,
    s.id DESC
  LIMIT 1
");

$hasRevocationsTable = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'certification_revocations'
")->fetchColumn();
$revokedMap = [];
if ($hasRevocationsTable) {
  $revokedRowsStmt = $pdo->prepare("
    SELECT package_id, revoked_at
    FROM certification_revocations
    WHERE contact_id = ?
  ");
  $revokedRowsStmt->execute([(int)$contact['id']]);
  foreach (($revokedRowsStmt->fetchAll() ?: []) as $rv) {
    $revokedMap[(int)$rv['package_id']] = (string)$rv['revoked_at'];
  }
}
foreach ($certs as &$certRow) {
  $latestCertSessionStmt->execute([(int)$contact['id'], (int)($certRow['package_id'] ?? 0)]);
  $certRow['last_session_id'] = (string)($latestCertSessionStmt->fetchColumn() ?: '');
}
unset($certRow);

$histWhere = [];
$histParams = [(int)$contact['id']];
if ($htype !== 'ALL') {
  $histWhere[] = "s.session_type = ?";
  $histParams[] = $htype;
}
if ($hstatus !== 'ALL') {
  $histWhere[] = "s.status = ?";
  $histParams[] = $hstatus;
}
if ($hpackage !== 'ALL') {
  $histWhere[] = "s.package_id = ?";
  $histParams[] = (int)$hpackage;
}
if ($activeProgramId > 0) {
  $histWhere[] = auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false);
}
$histWhere[] = "(
  ? = 'ALL'
  OR (? = 'PASSED' AND s.status IN ('TERMINATED', 'EXPIRED') AND s.passed=1)
  OR (? = 'FAILED' AND s.status IN ('TERMINATED', 'EXPIRED') AND s.passed=0)
)";
$histParams[] = $hresult;
$histParams[] = $hresult;
$histParams[] = $hresult;
$histWhereSql = implode("\n    AND ", $histWhere);

$histCountStmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.contact_id = ?
    AND $histWhereSql
");
$histCountStmt->execute($histParams);
$histTotalRows = (int)$histCountStmt->fetchColumn();
$histTotalPages = max(1, (int)ceil($histTotalRows / $historyLimit));
if ($historyPage > $histTotalPages) {
  $historyPage = $histTotalPages;
}
$historyOffset = ($historyPage - 1) * $historyLimit;

$histStmt = $pdo->prepare("
  SELECT
    s.id,
    s.started_at,
    s.submitted_at,
    s.status,
    s.session_type,
    s.score_percent,
    s.passed,
    pk.name AS package_name,
    pk.name_color_hex AS package_color_hex
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.contact_id = ?
    AND $histWhereSql
  ORDER BY s.$hsort $hdir
  LIMIT ? OFFSET ?
");
$histBindIndex = 1;
foreach ($histParams as $param) {
  $histStmt->bindValue($histBindIndex++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$histStmt->bindValue($histBindIndex++, $historyLimit, PDO::PARAM_INT);
$histStmt->bindValue($histBindIndex++, $historyOffset, PDO::PARAM_INT);
$histStmt->execute();
$hist = $histStmt->fetchAll();

$linkedFirstName = trim((string)($contact['first_name'] ?? ''));
$linkedLastName = trim((string)($contact['last_name'] ?? ''));
if (($linkedFirstName === '' || $linkedLastName === '') && $linkedUser) {
  [$firstGuess, $lastGuess] = admin_contact_guess_first_last((string)($linkedUser['name'] ?? ''));
  if ($linkedFirstName === '') {
    $linkedFirstName = $firstGuess;
  }
  if ($linkedLastName === '') {
    $linkedLastName = $lastGuess;
  }
}
$editFirstName = ($contactEditFormUserId === $linkedUserId) ? trim((string)($contactEditForm['first_name'] ?? $linkedFirstName)) : $linkedFirstName;
$editLastName = ($contactEditFormUserId === $linkedUserId) ? trim((string)($contactEditForm['last_name'] ?? $linkedLastName)) : $linkedLastName;
$editEmail = ($contactEditFormUserId === $linkedUserId) ? trim((string)($contactEditForm['email'] ?? $email)) : $email;
$editRole = ($contactEditFormUserId === $linkedUserId)
  ? normalize_user_role((string)($contactEditForm['role'] ?? (string)($linkedUser['role'] ?? 'USER')))
  : normalize_user_role((string)($linkedUser['role'] ?? 'USER'));
$editProgramRoles = ($contactEditFormUserId === $linkedUserId && is_array($contactEditForm['program_roles'] ?? null))
  ? admin_contact_normalize_program_roles($contactEditForm['program_roles'], array_keys($programLabelsById), $adminUser)
  : $linkedUserProgramRoles;
$editEmailControlBypass = ($contactEditFormUserId === $linkedUserId)
  ? (int)($contactEditForm['email_control_bypass'] ?? (int)($linkedUser['email_control_bypass'] ?? 0))
  : (int)($linkedUser['email_control_bypass'] ?? 0);
$editRoleOptions = admin_contact_assignable_roles($adminUser, (string)($linkedUser['role'] ?? 'USER'));
$editIsGlobalAdmin = $editRole === 'ADMIN';
$returnTo = (string)($_SERVER['REQUEST_URI'] ?? ('/admin/contact.php?email=' . urlencode($email)));
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('admin.contact.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card candidate-profile-page">
      <div class="admin-head candidate-profile-hero">
        <div class="admin-head-copy">
          <p class="candidate-profile-eyebrow"><?= h(t('admin.contact.eyebrow', [], $lang)) ?></p>
          <h2 class="h1"><?= h(t('admin.contact.title', [], $lang)) ?></h2>
          <p class="sub"><?= h($contact['email']) ?></p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('users'); ?>
          <a class="btn ghost back-nav-btn" href="/admin/users.php"><?= h(t('admin.common.back', [], $lang)) ?></a>
        </div>
      </div>

      <?php if (is_array($contactNotice) && isset($contactNotice['type'], $contactNotice['text'])): ?>
        <div class="admin-notice <?= ((string)$contactNotice['type'] === 'ok') ? 'is-ok' : 'is-bad' ?>">
          <?= h((string)$contactNotice['text']) ?>
        </div>
      <?php endif; ?>

      <div class="candidate-stats-grid">
        <article class="candidate-stat-card">
          <span class="candidate-stat-label"><?= h(t('admin.contact.stat_sessions', [], $lang)) ?></span>
          <strong class="candidate-stat-value"><?= (int)$summary['total_sessions'] ?></strong>
        </article>
        <article class="candidate-stat-card">
          <span class="candidate-stat-label"><?= h(t('admin.contact.stat_last_activity', [], $lang)) ?></span>
          <strong class="candidate-stat-value candidate-stat-value-sm"><?= h($summary['last_activity'] ?: '-') ?></strong>
        </article>
        <article class="candidate-stat-card">
          <span class="candidate-stat-label"><?= h(t('admin.contact.stat_certs', [], $lang)) ?></span>
          <strong class="candidate-stat-value"><?= (int)$summary['passed_exam_count'] ?></strong>
        </article>
        <article class="candidate-stat-card">
          <span class="candidate-stat-label"><?= h(t('admin.contact.stat_avg_score', [], $lang)) ?></span>
          <strong class="candidate-stat-value"><?= $summary['avg_exam_score'] !== null ? h($summary['avg_exam_score']).'%' : '-' ?></strong>
        </article>
      </div>

      <section class="candidate-section candidate-account-section">
        <div class="section-head candidate-section-head">
          <div>
            <h2 class="h1"><?= h(t('admin.contact.account_title', [], $lang)) ?></h2>
            <p class="sub"><?= h(t('admin.contact.account_subtitle', [], $lang)) ?></p>
          </div>
        </div>

        <?php if ($linkedUserId <= 0): ?>
          <p class="empty-state"><?= h(t('admin.contact.no_account', [], $lang)) ?></p>
        <?php else: ?>
          <div class="candidate-account-summary">
            <span class="pill info"><?= h(admin_contact_role_label((string)($linkedUser['role'] ?? 'USER'))) ?></span>
            <?php
              $progOwnerCount = count(array_filter($editProgramRoles, fn($r) => $r === 'OWNER'));
              $progUserCount  = count(array_filter($editProgramRoles, fn($r) => $r !== 'OWNER'));
            ?>
            <?php if ($progOwnerCount > 0): ?>
              <span class="candidate-account-summary-item"><?= h(t('admin.contact.programs_owner_label', ['count' => $progOwnerCount], $lang)) ?></span>
            <?php endif; ?>
            <?php if ($progUserCount > 0): ?>
              <span class="candidate-account-summary-item"><?= h(t('admin.contact.programs_user_label', ['count' => $progUserCount], $lang)) ?></span>
            <?php endif; ?>
            <?php if ($progOwnerCount === 0 && $progUserCount === 0): ?>
              <span class="candidate-account-summary-item"><?= h(t('admin.contact.programs_none_label', [], $lang)) ?></span>
            <?php endif; ?>
            <?php if (user_has_role($adminUser, 'ADMIN') && $hasEmailControlBypassColumn && $editEmailControlBypass === 1): ?>
              <span class="candidate-account-summary-item"><?= h(t('admin.contact.email_ctrl_disabled', [], $lang)) ?></span>
            <?php endif; ?>
          </div>

          <?php if ($canManageLinkedUser): ?>
            <form method="post" action="/admin/users.php" class="users-edit-form candidate-account-form">
              <input type="hidden" name="csrf_token" value="<?= h($userEditCsrfToken) ?>">
              <input type="hidden" name="action" value="update_user">
              <input type="hidden" name="user_id" value="<?= (int)$linkedUserId ?>">
              <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
              <?php if (!user_has_role($adminUser, 'ADMIN')): ?>
                <input type="hidden" name="role" value="<?= h($editRole) ?>">
              <?php endif; ?>
              <div class="candidate-account-layout">
                <section class="candidate-account-card">
                  <div class="candidate-account-card-head">
                    <h3 class="candidate-account-card-title"><?= h(t('admin.contact.identity_title', [], $lang)) ?></h3>
                    <p class="sub"><?= h(t('admin.contact.identity_subtitle', [], $lang)) ?></p>
                  </div>
                  <div class="users-edit-grid">
                    <div>
                      <label class="label" for="contact-edit-first-name"><?= h(t('admin.users.firstname', [], $lang)) ?></label>
                      <input class="input" id="contact-edit-first-name" name="first_name" type="text" maxlength="100" required value="<?= h($editFirstName) ?>">
                    </div>
                    <div>
                      <label class="label" for="contact-edit-last-name"><?= h(t('admin.users.lastname', [], $lang)) ?></label>
                      <input class="input" id="contact-edit-last-name" name="last_name" type="text" maxlength="100" required value="<?= h($editLastName) ?>">
                    </div>
                    <div class="candidate-account-field-wide">
                      <label class="label" for="contact-edit-email"><?= h(t('admin.common.email', [], $lang)) ?></label>
                      <input class="input" id="contact-edit-email" name="email" type="email" required value="<?= h($editEmail) ?>">
                    </div>
                  </div>
                </section>

                <section class="candidate-account-card">
                  <div class="candidate-account-card-head">
                    <h3 class="candidate-account-card-title"><?= h(t('admin.contact.security_title', [], $lang)) ?></h3>
                    <p class="sub"><?= h(t('admin.contact.security_subtitle', [], $lang)) ?></p>
                  </div>
                  <div class="users-edit-grid candidate-account-compact-grid">
                    <div>
                      <label class="label" for="contact-edit-pass"><?= h(t('admin.users.password_new', [], $lang)) ?></label>
                      <input class="input" id="contact-edit-pass" name="new_password" type="password" minlength="8" autocomplete="new-password">
                    </div>
                    <div>
                      <label class="label" for="contact-edit-pass2"><?= h(t('admin.users.password_new_confirm', [], $lang)) ?></label>
                      <input class="input" id="contact-edit-pass2" name="new_password2" type="password" minlength="8" autocomplete="new-password">
                    </div>
                  </div>
                </section>

                <section class="candidate-account-card candidate-account-card-full">
                  <div class="candidate-account-card-head">
                    <h3 class="candidate-account-card-title"><?= h(t('admin.contact.permissions_title', [], $lang)) ?></h3>

                  </div>
                  <div class="candidate-account-checklists">
                    <?php if (user_has_role($adminUser, 'ADMIN')): ?>
                      <div class="users-multiselect candidate-account-checklist">
                        <span class="label"><?= h(t('admin.contact.global_admin_label', [], $lang)) ?></span>
                        <label class="admin-inline-checkbox">
                          <input type="checkbox" name="role" value="ADMIN" <?= $editIsGlobalAdmin ? 'checked' : '' ?>>
                          <span><?= h(t('admin.contact.global_admin_checkbox', [], $lang)) ?></span>
                        </label>
                        <p class="sub" style="margin:8px 0 0;"><?= h(t('admin.contact.global_admin_hint', [], $lang)) ?></p>
                      </div>
                    <?php endif; ?>
                    <div class="users-multiselect candidate-account-checklist">
                      <span class="label"><?= h(t('admin.contact.program_access_label', [], $lang)) ?></span>
                      <div class="users-checkbox-list">
                        <?php foreach ($programRows as $programRow): ?>
                          <?php $programIdOption = (int)($programRow['id'] ?? 0); ?>
                          <?php $selectedProgramRole = auth_normalize_program_access_role((string)($editProgramRoles[$programIdOption] ?? '')); ?>
                          <?php $selectedProgramRole = isset($editProgramRoles[$programIdOption]) ? $selectedProgramRole : 'NONE'; ?>
                          <label class="users-checkbox-item users-program-role-item">
                            <span><?= h((string)($programLabelsById[$programIdOption] ?? ($programRow['name'] ?? 'Programme'))) ?></span>
                            <select class="input users-program-role-select" name="program_roles[<?= $programIdOption ?>]">
                              <option value="NONE" <?= $selectedProgramRole === 'NONE' ? 'selected' : '' ?>><?= h(t('admin.users.role_none', [], $lang)) ?></option>
                              <option value="USER" <?= $selectedProgramRole === 'USER' ? 'selected' : '' ?>><?= h(t('admin.users.role_user', [], $lang)) ?></option>
                              <?php if (user_has_role($adminUser, ['ADMIN', 'OWNER'])): ?>
                                <option value="OWNER" <?= $selectedProgramRole === 'OWNER' ? 'selected' : '' ?>><?= h(t('admin.users.role_owner', [], $lang)) ?></option>
                              <?php endif; ?>
                            </select>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <?php if (user_has_role($adminUser, 'ADMIN') && $hasEmailControlBypassColumn): ?>
                      <div class="users-multiselect candidate-account-checklist">
                        <span class="label"><?= h(t('admin.users.email_bypass', [], $lang)) ?></span>
                        <label class="admin-inline-checkbox">
                          <input type="checkbox" name="email_control_bypass" value="1" <?= $editEmailControlBypass === 1 ? 'checked' : '' ?>>
                          <span><?= h(t('admin.users.email_bypass_hint', [], $lang)) ?></span>
                        </label>
                      </div>
                    <?php endif; ?>
                  </div>
                </section>
              </div>
              <div class="users-edit-actions candidate-account-actions">
                <button class="btn" type="submit"><?= h(t('admin.contact.save_account', [], $lang)) ?></button>
              </div>
            </form>
          <?php else: ?>
            <p class="empty-state"><?= h(t('admin.contact.read_only_notice', [], $lang)) ?></p>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <div class="candidate-profile-layout">
      <section class="candidate-section candidate-section-accent">
      <div class="section-head candidate-section-head">
        <div>
          <h2 class="h1"><?= h(t('admin.contact.unblock_title', [], $lang)) ?></h2>
          <p class="sub"><?= h(t('admin.contact.unblock_subtitle', [], $lang)) ?></p>
        </div>
      </div>

      <?php if ($unlockOk === '1'): ?>
        <p class="success"><?= h(t('admin.contact.unlock_ok_msg', [], $lang)) ?></p>
      <?php endif; ?>
      <?php if ($unlockError !== ''): ?>
        <p class="error"><?= h($unlockError) ?></p>
      <?php endif; ?>
      <?php if ($reblockOk === '1'): ?>
        <p class="success"><?= h(t('admin.contact.reblock_ok_msg', [], $lang)) ?></p>
      <?php endif; ?>
      <?php if ($reblockError !== ''): ?>
        <p class="error"><?= h($reblockError) ?></p>
      <?php endif; ?>

      <?php if ($linkedUserId <= 0): ?>
        <p class="error"><?= h(t('admin.contact.unlock_no_account', [], $lang)) ?></p>
      <?php elseif (!$canMutateReporting): ?>
        <p class="sub"><?= h(t('admin.contact.unlock_read_only', [], $lang)) ?></p>
      <?php elseif (!$packages): ?>
        <p class="error"><?= h(t('admin.contact.unlock_no_certs', [], $lang)) ?></p>
      <?php else: ?>
        <form method="post" class="filters-grid candidate-inline-form">
          <input type="hidden" name="action" value="unlock_exam">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

          <div>
            <label class="label" for="unlock-package-id"><?= h(t('admin.contact.unlock_cert_label', [], $lang)) ?></label>
            <select class="input" id="unlock-package-id" name="unlock_package_id" required>
              <?php foreach ($packages as $pkg): ?>
                <option value="<?= (int)$pkg['id'] ?>">
                  <?= h(localize_text((string)$pkg['name'], 'fr')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="label" for="unlock-days"><?= h(t('admin.contact.unlock_days_label', [], $lang)) ?></label>
            <input class="input" id="unlock-days" name="unlock_days" type="number" min="1" max="3650" value="<?= (int)$unlockDays ?>" required>
          </div>

          <div>
            <label class="label" for="unlock-reason"><?= h(t('admin.contact.unlock_reason_label', [], $lang)) ?></label>
            <input class="input" id="unlock-reason" name="unlock_reason" type="text" maxlength="255" placeholder="<?= h(t('admin.contact.unlock_reason_placeholder', [], $lang)) ?>">
          </div>

          <div class="filters-actions">
            <button class="btn" type="submit"><?= h(t('admin.contact.unblock_btn', [], $lang)) ?></button>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($activeOverrides): ?>
        <div class="candidate-subsection-head">
          <div>
            <h3 class="candidate-subsection-title"><?= h(t('admin.contact.unblock_history', [], $lang)) ?></h3>
            <p class="sub sessions-meta"><?= h(t('admin.common.page_of', ['page' => $overridePage, 'total' => $overrideTotalPages, 'count' => $overrideTotalRows], $lang)) ?></p>
          </div>
        </div>
        <div class="table-wrap candidate-table-wrap">
          <table class="table questions-table overrides-table">
            <thead>
              <tr>
                <th><?= h(t('admin.contact.col_cert', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_state', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_created_at', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_expires', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_used_at', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_reason', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_created_by', [], $lang)) ?></th>
                <th><?= h(t('admin.common.action', [], $lang)) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($activeOverrides as $ov):
                $state = t('admin.contact.status_inactive', [], $lang);
                $stateClass = 'badge';
                $canReblock = false;
                if ((int)($ov['is_active'] ?? 1) === 1 && empty($ov['used_at'])) {
                  $expired = !empty($ov['expires_at']) && strtotime((string)$ov['expires_at']) < time();
                  if ($expired) {
                    $state = t('admin.contact.status_expired', [], $lang);
                    $stateClass = 'badge bad';
                  } else {
                    $state = t('admin.contact.status_active', [], $lang);
                    $stateClass = 'badge ok';
                    $canReblock = true;
                  }
                } elseif (!empty($ov['used_at'])) {
                  $state = t('admin.contact.status_used', [], $lang);
                  $stateClass = 'badge';
                }
              ?>
                <tr>
                  <td><span style="<?= h(package_label_style((string)$ov['package_name'], (string)($ov['package_color_hex'] ?? ''))) ?>"><?= h(localize_text((string)$ov['package_name'], 'fr')) ?></span></td>
                  <td><span class="<?= h($stateClass) ?>"><?= h($state) ?></span></td>
                  <td><?= h((string)$ov['created_at']) ?></td>
                  <td><?= h((string)($ov['expires_at'] ?? '-')) ?></td>
                  <td><?= h((string)($ov['used_at'] ?? '-')) ?></td>
                  <td><?= h((string)($ov['reason'] ?? '-')) ?></td>
                  <td><?= h((string)($ov['created_by_email'] ?? '-')) ?></td>
                  <td class="actions-cell">
                    <?php if ($canReblock): ?>
                      <form method="post" class="inline-action-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="reblock_exam">
                        <input type="hidden" name="reblock_package_id" value="<?= (int)($ov['package_id'] ?? 0) ?>">
                        <button
                          class="btn ghost icon-btn danger"
                          type="submit"
                          aria-label="<?= h(t('admin.contact.reblock_btn', [], $lang)) ?>"
                          title="<?= h(t('admin.contact.reblock_btn', [], $lang)) ?>"
                          onclick="return confirm('<?= h(t('admin.contact.reblock_confirm', [], $lang)) ?>');"
                        >
                          <svg class="icon-close" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6.7 5.3 12 10.6l5.3-5.3 1.4 1.4L13.4 12l5.3 5.3-1.4 1.4L12 13.4l-5.3 5.3-1.4-1.4L10.6 12 5.3 6.7z"/>
                          </svg>
                        </button>
                      </form>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php
          $overrideQs = $_GET;
          $overrideQs['email'] = $contact['email'];
          unset($overrideQs['opage']);
          $overrideBase = '/admin/contact.php';
          $overrideCommon = '?' . http_build_query($overrideQs);
        ?>
        <div class="sessions-pagination">
          <?php if ($overridePage > 1): ?>
            <a class="btn ghost" href="<?= h($overrideBase . $overrideCommon . '&opage=' . ($overridePage - 1)) ?>">&larr;</a>
          <?php else: ?>
            <button class="btn ghost" disabled>&larr;</button>
          <?php endif; ?>

          <?php if ($overrideTotalPages <= 7): ?>
            <?php for ($p = 1; $p <= $overrideTotalPages; $p++): ?>
              <a class="btn <?= $p === $overridePage ? '' : 'ghost' ?>" href="<?= h($overrideBase . $overrideCommon . '&opage=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
          <?php else: ?>
            <a class="btn <?= $overridePage === 1 ? '' : 'ghost' ?>" href="<?= h($overrideBase . $overrideCommon . '&opage=1') ?>">1</a>

            <?php if ($overridePage <= 4): ?>
              <?php for ($p = 2; $p <= 5; $p++): ?>
                <a class="btn <?= $p === $overridePage ? '' : 'ghost' ?>" href="<?= h($overrideBase . $overrideCommon . '&opage=' . $p) ?>"><?= (int)$p ?></a>
              <?php endfor; ?>
              <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php elseif ($overridePage >= ($overrideTotalPages - 3)): ?>
              <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
              <?php for ($p = $overrideTotalPages - 4; $p <= $overrideTotalPages - 1; $p++): ?>
                <a class="btn <?= $p === $overridePage ? '' : 'ghost' ?>" href="<?= h($overrideBase . $overrideCommon . '&opage=' . $p) ?>"><?= (int)$p ?></a>
              <?php endfor; ?>
            <?php else: ?>
              <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
              <?php for ($p = $overridePage - 1; $p <= $overridePage + 1; $p++): ?>
                <a class="btn <?= $p === $overridePage ? '' : 'ghost' ?>" href="<?= h($overrideBase . $overrideCommon . '&opage=' . $p) ?>"><?= (int)$p ?></a>
              <?php endfor; ?>
              <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php endif; ?>

            <a class="btn <?= $overrideTotalPages === $overridePage ? '' : 'ghost' ?>" href="<?= h($overrideBase . $overrideCommon . '&opage=' . $overrideTotalPages) ?>"><?= (int)$overrideTotalPages ?></a>
          <?php endif; ?>

          <?php if ($overridePage < $overrideTotalPages): ?>
            <a class="btn ghost" href="<?= h($overrideBase . $overrideCommon . '&opage=' . ($overridePage + 1)) ?>">&rarr;</a>
          <?php else: ?>
            <button class="btn ghost" disabled>&rarr;</button>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      </section>

      <section class="candidate-section">
      <div class="section-head candidate-section-head">
        <div>
          <h2 class="h1"><?= h(t('admin.contact.certs_title', [], $lang)) ?></h2>
          <p class="sub"><?= h(t('admin.contact.certs_subtitle', [], $lang)) ?></p>
        </div>
      </div>

      <div class="table-wrap candidate-table-wrap">
        <?php if (!$certs): ?>
          <p class="empty-state"><?= h(t('admin.contact.certs_none', [], $lang)) ?></p>
        <?php else: ?>
          <table class="table questions-table certifications-table">
            <thead>
              <tr>
                <th><?= h(t('admin.contact.col_cert', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_start', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_end', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_expires', [], $lang)) ?></th>
                <th><?= h(t('admin.common.status', [], $lang)) ?></th>
                <th><?= h(t('admin.common.action', [], $lang)) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($certs as $c):
                $certStatus = certification_status_from_last_success(
                  (string)$c['last_cert_date'],
                  null,
                  (int)($c['cert_validity_days'] ?? 365),
                  $lang
                );
                $packageId = (int)($c['package_id'] ?? 0);
                $isRevoked = false;
                if (isset($revokedMap[$packageId])) {
                  $revokedAtRaw = trim((string)$revokedMap[$packageId]);
                  $lastSuccessRaw = trim((string)($c['last_cert_date'] ?? ''));
                  if ($revokedAtRaw !== '' && $lastSuccessRaw !== '') {
                    try {
                      $revokedAt = new DateTimeImmutable($revokedAtRaw);
                      $lastSuccessAt = new DateTimeImmutable($lastSuccessRaw);
                      $isRevoked = $revokedAt >= $lastSuccessAt;
                    } catch (Throwable $e) {
                      $isRevoked = true;
                    }
                  } else {
                    $isRevoked = true;
                  }
                }
                if ($isRevoked) {
                  $certStatus = [
                    'status_key' => 'REVOKED',
                    'status_label' => t('admin.certs.status_revoked', [], $lang),
                    'status_class' => 'pill danger',
                    'expires_at' => $certStatus['expires_at'] ?? null,
                  ];
                }
                $expiresAt = $certStatus['expires_at'] ?? null;
                if (!$expiresAt instanceof DateTimeImmutable) {
                  $expiresAt = null;
                }
                $sessionDetailUrl = (string)($c['last_session_id'] ?? '') !== ''
                  ? '/admin/session.php?sid=' . urlencode((string)$c['last_session_id']) . ($activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '') . '&return=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/contact.php?email=' . $contact['email']))
                  : '';
                $returnUrl = '/admin/contact.php?' . http_build_query([
                  'email' => (string)$contact['email'],
                  'htype' => $htype,
                  'hpackage' => $hpackage,
                  'hstatus' => $hstatus,
                  'hsort' => $hsort,
                  'hdir' => $hdir,
                  'hresult' => $hresult,
                  'opage' => $overridePage,
                  'hpage' => $historyPage,
                ]);
              ?>
                <tr>
	                  <td><span style="<?= h(package_label_style((string)$c['package_name'], (string)($c['package_color_hex'] ?? ''))) ?>"><?= h($c['package_name']) ?></span></td>
                  <td><?= h((string)($c['last_started_at'] ?? '-')) ?></td>
                  <td><?= h($c['last_cert_date']) ?></td>
                  <td><?= h(admin_contact_format_cert_expiry($expiresAt, $isRevoked, $lang)) ?></td>
                  <td><span class="<?= h((string)$certStatus['status_class']) ?>"><?= h((string)$certStatus['status_label']) ?></span></td>
                  <td class="actions-cell">
                    <?php if ($sessionDetailUrl !== ''): ?>
                      <a class="btn ghost icon-btn" href="<?= h($sessionDetailUrl) ?>" aria-label="<?= h(t('admin.common.view_detail', [], $lang)) ?>" title="<?= h(t('admin.common.view_detail', [], $lang)) ?>">
                        <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                          <path d="M12 5c5.5 0 9.5 4.6 10.8 6.3a1.2 1.2 0 0 1 0 1.4C21.5 14.4 17.5 19 12 19S2.5 14.4 1.2 12.7a1.2 1.2 0 0 1 0-1.4C2.5 9.6 6.5 5 12 5zm0 2C8 7 4.9 10.3 3.3 12 4.9 13.7 8 17 12 17s7.1-3.3 8.7-5C19.1 10.3 16 7 12 7zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5z"/>
                        </svg>
                      </a>
                    <?php endif; ?>
                    <?php if ($hasRevocationsTable): ?>
                    <?php if (!$canMutateReporting): ?>
                      -
                    <?php elseif ((string)($certStatus['status_key'] ?? '') === 'REVOKED'): ?>
                        <a class="btn ghost cert-action-restore" href="/admin/certification_revoke.php?action=undo&contact_id=<?= (int)$contact['id'] ?>&package_id=<?= $packageId ?>&return=<?= h(urlencode($returnUrl)) ?>"
                           onclick="return confirm('<?= h(t('admin.contact.restore_confirm', [], $lang)) ?>');"><?= h(t('admin.certs.restore', [], $lang)) ?></a>
                      <?php else: ?>
                        <a class="btn ghost icon-btn danger" href="/admin/certification_revoke.php?action=revoke&contact_id=<?= (int)$contact['id'] ?>&package_id=<?= $packageId ?>&return=<?= h(urlencode($returnUrl)) ?>"
                           aria-label="<?= h(t('admin.certs.revoke', [], $lang)) ?>" title="<?= h(t('admin.certs.revoke', [], $lang)) ?>"
                           onclick="return confirm('<?= h(t('admin.contact.revoke_confirm', [], $lang)) ?>');"
                          <svg class="icon-close" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6.7 5.3 12 10.6l5.3-5.3 1.4 1.4L13.4 12l5.3 5.3-1.4 1.4L12 13.4l-5.3 5.3-1.4-1.4L10.6 12 5.3 6.7z"/>
                          </svg>
                        </a>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      </section>

      <section class="candidate-section candidate-section-wide" id="history-sessions">
      <div class="section-head candidate-section-head">
        <div>
          <h2 class="h1"><?= h(t('admin.contact.history_title', [], $lang)) ?></h2>
          <p class="sub"><?= h(t('admin.contact.history_subtitle', [], $lang)) ?></p>
        </div>
      </div>

      <form method="get" class="filters-grid candidate-history-filters" style="grid-template-columns: repeat(4, minmax(0, 1fr)) auto; align-items:end; gap:12px;">
        <input type="hidden" name="email" value="<?= h($contact['email']) ?>">
        <input type="hidden" name="hsort" value="<?= h($hsort) ?>">
        <input type="hidden" name="hdir" value="<?= h($hdir) ?>">

        <div>
          <label class="label" for="htype"><?= h(t('admin.sessions.filter_type', [], $lang)) ?></label>
          <select class="input" id="htype" name="htype">
            <option value="ALL" <?= $htype==='ALL'?'selected':'' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
            <option value="EXAM" <?= $htype==='EXAM'?'selected':'' ?>><?= h(t('admin.sessions.type_exam', [], $lang)) ?></option>
            <option value="TRAINING" <?= $htype==='TRAINING'?'selected':'' ?>><?= h(t('admin.sessions.type_training', [], $lang)) ?></option>
          </select>
        </div>

        <div>
          <label class="label" for="hpackage"><?= h(t('admin.contact.col_package', [], $lang)) ?></label>
          <select class="input" id="hpackage" name="hpackage">
            <option value="ALL" <?= $hpackage==='ALL'?'selected':'' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
            <?php foreach ($packages as $pkg): ?>
              <option value="<?= (int)$pkg['id'] ?>" <?= $hpackage===(string)$pkg['id']?'selected':'' ?>>
                <?= h(localize_text((string)$pkg['name'], 'fr')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label" for="hstatus"><?= h(t('admin.common.status', [], $lang)) ?></label>
          <select class="input" id="hstatus" name="hstatus">
            <option value="ALL" <?= $hstatus==='ALL'?'selected':'' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
            <option value="ACTIVE" <?= $hstatus==='ACTIVE'?'selected':'' ?>><?= h(t('admin.common.active', [], $lang)) ?></option>
            <option value="TERMINATED" <?= $hstatus==='TERMINATED'?'selected':'' ?>><?= h(t('admin.status.terminated', [], $lang)) ?></option>
            <option value="EXPIRED" <?= $hstatus==='EXPIRED'?'selected':'' ?>><?= h(t('admin.status.expired', [], $lang)) ?></option>
          </select>
        </div>

        <div>
          <label class="label" for="hresult"><?= h(t('admin.sessions.filter_result', [], $lang)) ?></label>
          <select class="input" id="hresult" name="hresult">
            <option value="ALL" <?= $hresult==='ALL'?'selected':'' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
            <option value="PASSED" <?= $hresult==='PASSED'?'selected':'' ?>><?= h(t('admin.common.passed', [], $lang)) ?></option>
            <option value="FAILED" <?= $hresult==='FAILED'?'selected':'' ?>><?= h(t('admin.common.failed', [], $lang)) ?></option>
          </select>
        </div>

        <div class="filters-actions">
          <button class="btn" type="submit"><?= h(t('admin.common.filter', [], $lang)) ?></button>
          <a class="btn ghost" href="/admin/contact.php?email=<?= urlencode($contact['email']) ?><?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>"><?= h(t('admin.common.reset', [], $lang)) ?></a>
        </div>
      </form>

      <div class="table-wrap candidate-table-wrap">
        <?php if (!$hist): ?>
          <p class="empty-state"><?= h(t('admin.contact.history_none', [], $lang)) ?></p>
        <?php else: ?>
          <p class="sub sessions-meta"><?= h(t('admin.common.page_of', ['page' => $historyPage, 'total' => $histTotalPages, 'count' => $histTotalRows], $lang)) ?></p>
          <table class="table questions-table">
            <thead>
              <tr>
                <th>
                  <?php
                    $qs = $_GET;
                    $qs['hsort'] = 'started_at';
                    $qs['hdir'] = ($hsort !== 'started_at') ? 'DESC' : (($hdir === 'DESC') ? 'ASC' : 'DESC');
                    $url = '/admin/contact.php?' . http_build_query($qs);
                  ?>
                  <a class="sort-link" href="<?= h($url) ?>">
                    <?= h(t('admin.contact.col_start', [], $lang)) ?>
                    <?php if ($hsort === 'started_at'): ?>
                      <span><?= $hdir === 'DESC' ? '&darr;' : '&uarr;' ?></span>
                    <?php endif; ?>
                  </a>
                </th>
                <th><?= h(t('admin.sessions.filter_type', [], $lang)) ?></th>
                <th><?= h(t('admin.contact.col_package', [], $lang)) ?></th>
                <th><?= h(t('admin.common.status', [], $lang)) ?></th>
                <th>
                  <?php
                    $qs = $_GET;
                    $qs['hsort'] = 'score_percent';
                    $qs['hdir'] = ($hsort !== 'score_percent') ? 'DESC' : (($hdir === 'DESC') ? 'ASC' : 'DESC');
                    $url = '/admin/contact.php?' . http_build_query($qs);
                  ?>
                  <a class="sort-link" href="<?= h($url) ?>">
                    <?= h(t('admin.common.score', [], $lang)) ?>
                    <?php if ($hsort === 'score_percent'): ?>
                      <span><?= $hdir === 'DESC' ? '&darr;' : '&uarr;' ?></span>
                    <?php endif; ?>
                  </a>
                </th>
                <th><?= h(t('admin.sessions.filter_result', [], $lang)) ?></th>
                <th><?= h(t('admin.common.action', [], $lang)) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($hist as $s): ?>
                <tr>
                  <td><?= h($s['started_at']) ?></td>
                  <td><?= h(admin_session_type_label((string)$s['session_type'], $lang)) ?></td>
	                  <td><span style="<?= h(package_label_style((string)$s['package_name'], (string)($s['package_color_hex'] ?? ''))) ?>"><?= h($s['package_name']) ?></span></td>
                  <td>
                    <?php if ($s['status'] === 'TERMINATED'): ?>
                      <span class="badge ok"><?= h(t('admin.status.terminated', [], $lang)) ?></span>
                    <?php elseif ($s['status'] === 'EXPIRED'): ?>
                      <span class="badge bad"><?= h(t('admin.status.expired', [], $lang)) ?></span>
                    <?php else: ?>
                      <span class="badge"><?= h(t('admin.common.active', [], $lang)) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?></td>
                  <td>
                    <?php if (admin_contact_session_has_result($s)): ?>
                      <?php if ((int)$s['passed'] === 1): ?>
                        <span class="badge ok"><?= h(t('admin.common.passed', [], $lang)) ?></span>
                      <?php else: ?>
                        <span class="badge bad"><?= h(t('admin.common.failed', [], $lang)) ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <td class="actions-cell">
                    <a class="btn ghost icon-btn" href="/admin/session.php?sid=<?= h($s['id']) ?><?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>&return=<?= h(urlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/contact.php?email=' . $contact['email']))) ?>" aria-label="<?= h(t('admin.common.view_detail', [], $lang)) ?>" title="<?= h(t('admin.common.view_detail', [], $lang)) ?>">
                      <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 5c5.5 0 9.5 4.6 10.8 6.3a1.2 1.2 0 0 1 0 1.4C21.5 14.4 17.5 19 12 19S2.5 14.4 1.2 12.7a1.2 1.2 0 0 1 0-1.4C2.5 9.6 6.5 5 12 5zm0 2C8 7 4.9 10.3 3.3 12 4.9 13.7 8 17 12 17s7.1-3.3 8.7-5C19.1 10.3 16 7 12 7zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5z"/>
                      </svg>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <?php if ($hist): ?>
        <?php
          $historyQs = $_GET;
          $historyQs['email'] = $contact['email'];
          unset($historyQs['hpage']);
          $historyBase = '/admin/contact.php';
          $historyCommon = '?' . http_build_query($historyQs);
        ?>
        <div class="sessions-pagination">
          <?php if ($historyPage > 1): ?>
            <a class="btn ghost" href="<?= h($historyBase . $historyCommon . '&hpage=' . ($historyPage - 1) . '#history-sessions') ?>">&larr;</a>
          <?php else: ?>
            <button class="btn ghost" disabled>&larr;</button>
          <?php endif; ?>

          <?php if ($histTotalPages <= 7): ?>
            <?php for ($p = 1; $p <= $histTotalPages; $p++): ?>
              <a class="btn <?= $p === $historyPage ? '' : 'ghost' ?>" href="<?= h($historyBase . $historyCommon . '&hpage=' . $p . '#history-sessions') ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
          <?php else: ?>
            <a class="btn <?= $historyPage === 1 ? '' : 'ghost' ?>" href="<?= h($historyBase . $historyCommon . '&hpage=1#history-sessions') ?>">1</a>

            <?php if ($historyPage <= 4): ?>
              <?php for ($p = 2; $p <= 5; $p++): ?>
                <a class="btn <?= $p === $historyPage ? '' : 'ghost' ?>" href="<?= h($historyBase . $historyCommon . '&hpage=' . $p . '#history-sessions') ?>"><?= (int)$p ?></a>
              <?php endfor; ?>
              <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php elseif ($historyPage >= ($histTotalPages - 3)): ?>
              <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
              <?php for ($p = $histTotalPages - 4; $p <= $histTotalPages - 1; $p++): ?>
                <a class="btn <?= $p === $historyPage ? '' : 'ghost' ?>" href="<?= h($historyBase . $historyCommon . '&hpage=' . $p . '#history-sessions') ?>"><?= (int)$p ?></a>
              <?php endfor; ?>
            <?php else: ?>
              <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
              <?php for ($p = $historyPage - 1; $p <= $historyPage + 1; $p++): ?>
                <a class="btn <?= $p === $historyPage ? '' : 'ghost' ?>" href="<?= h($historyBase . $historyCommon . '&hpage=' . $p . '#history-sessions') ?>"><?= (int)$p ?></a>
              <?php endfor; ?>
              <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php endif; ?>

            <a class="btn <?= $histTotalPages === $historyPage ? '' : 'ghost' ?>" href="<?= h($historyBase . $historyCommon . '&hpage=' . $histTotalPages . '#history-sessions') ?>"><?= (int)$histTotalPages ?></a>
          <?php endif; ?>

          <?php if ($historyPage < $histTotalPages): ?>
            <a class="btn ghost" href="<?= h($historyBase . $historyCommon . '&hpage=' . ($historyPage + 1) . '#history-sessions') ?>">&rarr;</a>
          <?php else: ?>
            <button class="btn ghost" disabled>&rarr;</button>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      </section>
      </div>
    </div>
  </div>
</body>
</html>
