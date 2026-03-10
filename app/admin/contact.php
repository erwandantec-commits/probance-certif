<?php
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../services/session_service.php';
require_once __DIR__ . '/_nav.php';
$pdo = db();

if (empty($_SESSION['admin_contact_csrf']) || !is_string($_SESSION['admin_contact_csrf'])) {
  $_SESSION['admin_contact_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['admin_contact_csrf'];

function admin_contact_package_column_exists(PDO $pdo, string $column): bool {
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

$email = trim($_GET['email'] ?? '');
if ($email === '') { http_response_code(400); echo "Missing email"; exit; }

$sessionEndExpr = sessions_column_exists($pdo, 'ended_at')
  ? "COALESCE(s.ended_at, s.submitted_at, s.started_at)"
  : "COALESCE(s.submitted_at, s.started_at)";
$hasCertValidityDaysColumn = admin_contact_package_column_exists($pdo, 'cert_validity_days');
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

function admin_session_type_label(string $type): string {
  return match ($type) {
    'EXAM' => 'Certification',
    'TRAINING' => 'Test',
    default => $type,
  };
}

$stmt = $pdo->prepare("SELECT * FROM contacts WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$contact = $stmt->fetch();
if (!$contact) { http_response_code(404); echo "Contact not found"; exit; }

$linkedUserStmt = $pdo->prepare("SELECT id, email, role FROM users WHERE email = ? LIMIT 1");
$linkedUserStmt->execute([$email]);
$linkedUser = $linkedUserStmt->fetch();
$linkedUserId = (int)($linkedUser['id'] ?? 0);

$hasDisplayOrderColumn = admin_contact_package_column_exists($pdo, 'display_order');
$packageOrder = $hasDisplayOrderColumn ? "ORDER BY display_order ASC, id ASC" : "ORDER BY id ASC";
$packagesStmt = $pdo->query("SELECT id, name, name_color_hex FROM packages WHERE is_active=1 $packageOrder");
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
    WHERE o.user_id = ?
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
  WHERE s.contact_id = ?
");
$summaryStmt->execute([(int)$contact['id']]);
$summary = $summaryStmt->fetch();

$certsStmt = $pdo->prepare("
  SELECT
    pk.name AS package_name,
    pk.name_color_hex AS package_color_hex,
    $certValidityDaysSelect,
    MAX($sessionEndExpr) AS last_cert_date
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.contact_id = ?
    AND s.status = 'TERMINATED'
    AND s.passed = 1
    AND s.session_type = 'EXAM'
  GROUP BY pk.name, pk.name_color_hex$certValidityDaysGroup
  ORDER BY last_cert_date DESC
");
$certsStmt->execute([(int)$contact['id']]);
$certs = $certsStmt->fetchAll();

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
$histWhere[] = "(
  ? = 'ALL'
  OR (? = 'PASSED' AND s.session_type='EXAM' AND s.status='TERMINATED' AND s.passed=1)
  OR (? = 'FAILED' AND s.session_type='EXAM' AND s.status='TERMINATED' AND s.passed=0)
)";
$histParams[] = $hresult;
$histParams[] = $hresult;
$histParams[] = $hresult;
$histWhereSql = implode("\n    AND ", $histWhere);

$histCountStmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM sessions s
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
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Profil candidat</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Profil candidat</h2>
          <p class="sub"><?= h($contact['email']) ?></p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs(); ?>
        </div>
      </div>

      <hr class="separator">

      <div class="row sessions-stats">
        <span class="badge">Sessions: <?= (int)$summary['total_sessions'] ?></span>
        <span class="badge">Derniere activite: <?= h($summary['last_activity'] ?: '-') ?></span>
        <span class="badge ok">Certifications reussies: <?= (int)$summary['passed_exam_count'] ?></span>
        <span class="badge">Score moyen Certification: <?= $summary['avg_exam_score'] !== null ? h($summary['avg_exam_score']).'%' : '-' ?></span>
      </div>

      <div class="section-head">
        <div>
          <h2 class="h1">Deblocage exam</h2>
          <p class="sub">Rendre une certification de nouveau disponible pour ce candidat (un prochain lancement).</p>
        </div>
      </div>

      <?php if ($unlockOk === '1'): ?>
        <p class="success">Deblocage enregistre. Le prochain lancement d'exam sur ce pack est autorise.</p>
      <?php endif; ?>
      <?php if ($unlockError !== ''): ?>
        <p class="error"><?= h($unlockError) ?></p>
      <?php endif; ?>
      <?php if ($reblockOk === '1'): ?>
        <p class="success">Rebloquage enregistre. Le deblocage actif a ete retire.</p>
      <?php endif; ?>
      <?php if ($reblockError !== ''): ?>
        <p class="error"><?= h($reblockError) ?></p>
      <?php endif; ?>

      <?php if ($linkedUserId <= 0): ?>
        <p class="error">Aucun compte utilisateur n'est lie a cet email. Deblocage impossible.</p>
      <?php elseif (!$packages): ?>
        <p class="error">Aucune certification active disponible.</p>
      <?php else: ?>
        <form method="post" class="filters-grid">
          <input type="hidden" name="action" value="unlock_exam">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

          <div>
            <label class="label" for="unlock-package-id">Certification</label>
            <select class="input" id="unlock-package-id" name="unlock_package_id" required>
              <?php foreach ($packages as $pkg): ?>
                <option value="<?= (int)$pkg['id'] ?>">
                  <?= h(localize_text((string)$pkg['name'], 'fr')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="label" for="unlock-days">Validite du deblocage (jours)</label>
            <input class="input" id="unlock-days" name="unlock_days" type="number" min="1" max="3650" value="<?= (int)$unlockDays ?>" required>
          </div>

          <div>
            <label class="label" for="unlock-reason">Motif (optionnel)</label>
            <input class="input" id="unlock-reason" name="unlock_reason" type="text" maxlength="255" placeholder="Ex: demande support">
          </div>

          <div class="filters-actions">
            <button class="btn" type="submit">Debloquer</button>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($activeOverrides): ?>
        <p class="sub sessions-meta">Page <?= (int)$overridePage ?> / <?= (int)$overrideTotalPages ?> (<?= (int)$overrideTotalRows ?> resultats)</p>
        <div class="table-wrap" style="margin-top:10px;">
          <table class="table questions-table">
            <thead>
              <tr>
                <th>Certification</th>
                <th>Etat</th>
                <th>Cree le</th>
                <th>Expire le</th>
                <th>Utilisé le</th>
                <th>Motif</th>
                <th>Cree par</th>
                <th>Resultat</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($activeOverrides as $ov):
                $state = 'Inactif';
                $stateClass = 'badge';
                $canReblock = false;
                if ((int)($ov['is_active'] ?? 1) === 1 && empty($ov['used_at'])) {
                  $expired = !empty($ov['expires_at']) && strtotime((string)$ov['expires_at']) < time();
                  if ($expired) {
                    $state = 'Expire';
                    $stateClass = 'badge bad';
                  } else {
                    $state = 'Actif';
                    $stateClass = 'badge ok';
                    $canReblock = true;
                  }
                } elseif (!empty($ov['used_at'])) {
                  $state = 'Utilisé';
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
                          class="btn ghost"
                          type="submit"
                          onclick="return confirm('Rebloquer cette certification pour ce candidat ?');"
                        >Rebloquer</button>
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

      <div class="section-head">
        <div>
          <h2 class="h1">Certifications</h2>
          <p class="sub">Derniere session certification reussie par certification.</p>
        </div>
      </div>

      <div class="table-wrap">
        <?php if (!$certs): ?>
          <p class="empty-state">Aucune certification reussie.</p>
        <?php else: ?>
          <table class="table questions-table">
            <thead>
              <tr>
                <th>Certification</th>
                <th>Derniere reussite</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($certs as $c):
                $certStatus = certification_status_from_last_success(
                  (string)$c['last_cert_date'],
                  null,
                  (int)($c['cert_validity_days'] ?? 365)
                );
              ?>
                <tr>
	                  <td><span style="<?= h(package_label_style((string)$c['package_name'], (string)($c['package_color_hex'] ?? ''))) ?>"><?= h($c['package_name']) ?></span></td>
                  <td><?= h($c['last_cert_date']) ?></td>
                  <td><span class="<?= h((string)$certStatus['status_class']) ?>"><?= h((string)$certStatus['status_label']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <hr class="separator">

      <div id="history-sessions"></div>
      <div class="section-head">
        <div>
          <h2 class="h1">Historique des sessions</h2>
        </div>
      </div>

      <form method="get" class="filters-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr)) auto; align-items:end; gap:12px;">
        <input type="hidden" name="email" value="<?= h($contact['email']) ?>">
        <input type="hidden" name="hsort" value="<?= h($hsort) ?>">
        <input type="hidden" name="hdir" value="<?= h($hdir) ?>">

        <div>
          <label class="label" for="htype">Type</label>
          <select class="input" id="htype" name="htype">
            <option value="ALL" <?= $htype==='ALL'?'selected':'' ?>>Tous</option>
            <option value="EXAM" <?= $htype==='EXAM'?'selected':'' ?>>Certification</option>
            <option value="TRAINING" <?= $htype==='TRAINING'?'selected':'' ?>>Test</option>
          </select>
        </div>

        <div>
          <label class="label" for="hpackage">Package</label>
          <select class="input" id="hpackage" name="hpackage">
            <option value="ALL" <?= $hpackage==='ALL'?'selected':'' ?>>Tous</option>
            <?php foreach ($packages as $pkg): ?>
              <option value="<?= (int)$pkg['id'] ?>" <?= $hpackage===(string)$pkg['id']?'selected':'' ?>>
                <?= h(localize_text((string)$pkg['name'], 'fr')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label" for="hstatus">Statut</label>
          <select class="input" id="hstatus" name="hstatus">
            <option value="ALL" <?= $hstatus==='ALL'?'selected':'' ?>>Tous</option>
            <option value="ACTIVE" <?= $hstatus==='ACTIVE'?'selected':'' ?>>Actif</option>
            <option value="TERMINATED" <?= $hstatus==='TERMINATED'?'selected':'' ?>>Termine</option>
            <option value="EXPIRED" <?= $hstatus==='EXPIRED'?'selected':'' ?>>Expire</option>
          </select>
        </div>

        <div>
          <label class="label" for="hresult">Resultat</label>
          <select class="input" id="hresult" name="hresult">
            <option value="ALL" <?= $hresult==='ALL'?'selected':'' ?>>Tous</option>
            <option value="PASSED" <?= $hresult==='PASSED'?'selected':'' ?>>Reussi</option>
            <option value="FAILED" <?= $hresult==='FAILED'?'selected':'' ?>>Echoue</option>
          </select>
        </div>

        <div class="filters-actions">
          <button class="btn" type="submit">Filtrer</button>
          <a class="btn ghost" href="/admin/contact.php?email=<?= urlencode($contact['email']) ?>">Reset</a>
        </div>
      </form>

      <div class="table-wrap">
        <?php if (!$hist): ?>
          <p class="empty-state">Aucune session.</p>
        <?php else: ?>
          <p class="sub sessions-meta">Page <?= (int)$historyPage ?> / <?= (int)$histTotalPages ?> (<?= (int)$histTotalRows ?> resultats)</p>
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
                    Date debut
                    <?php if ($hsort === 'started_at'): ?>
                      <span><?= $hdir === 'DESC' ? '&darr;' : '&uarr;' ?></span>
                    <?php endif; ?>
                  </a>
                </th>
                <th>Type</th>
                <th>Package</th>
                <th>Statut</th>
                <th>
                  <?php
                    $qs = $_GET;
                    $qs['hsort'] = 'score_percent';
                    $qs['hdir'] = ($hsort !== 'score_percent') ? 'DESC' : (($hdir === 'DESC') ? 'ASC' : 'DESC');
                    $url = '/admin/contact.php?' . http_build_query($qs);
                  ?>
                  <a class="sort-link" href="<?= h($url) ?>">
                    Score
                    <?php if ($hsort === 'score_percent'): ?>
                      <span><?= $hdir === 'DESC' ? '&darr;' : '&uarr;' ?></span>
                    <?php endif; ?>
                  </a>
                </th>
                <th>Resultat</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($hist as $s): ?>
                <tr>
                  <td><?= h($s['started_at']) ?></td>
                  <td><?= h(admin_session_type_label((string)$s['session_type'])) ?></td>
	                  <td><span style="<?= h(package_label_style((string)$s['package_name'], (string)($s['package_color_hex'] ?? ''))) ?>"><?= h($s['package_name']) ?></span></td>
                  <td>
                    <?php if ($s['status'] === 'TERMINATED'): ?>
                      <span class="badge ok">Termine</span>
                    <?php elseif ($s['status'] === 'EXPIRED'): ?>
                      <span class="badge bad">Expire</span>
                    <?php else: ?>
                      <span class="badge">Actif</span>
                    <?php endif; ?>
                  </td>
                  <td><?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?></td>
                  <td>
                    <?php if ($s['session_type'] === 'EXAM' && $s['status'] === 'TERMINATED'): ?>
                      <?php if ((int)$s['passed'] === 1): ?>
                        <span class="badge ok">Reussi</span>
                      <?php else: ?>
                        <span class="badge bad">Echoue</span>
                      <?php endif; ?>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <td class="actions-cell">
                    <a class="btn ghost icon-btn" href="/admin/session.php?sid=<?= h($s['id']) ?>" aria-label="Voir le detail" title="Voir le detail">
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
    </div>
  </div>
</body>
</html>
