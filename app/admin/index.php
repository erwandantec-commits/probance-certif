<?php
require_once __DIR__ . '/_auth.php';
require_team_reporting();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();
$adminUser = current_user() ?? ['role' => 'USER'];
$activeProgramId = auth_admin_program_context($pdo, $adminUser, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
$hasProgramIdColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'program_id'
")->fetchColumn();

// Filtres (GET)
$type   = strtoupper(trim($_GET['type'] ?? 'ALL'));
$status = strtoupper(trim($_GET['status'] ?? 'ALL'));
$search = trim($_GET['search'] ?? '');
$package = trim($_GET['package'] ?? 'ALL');

$result = strtoupper(trim($_GET['result'] ?? 'ALL'));
$allowedResults = ['ALL', 'PASSED', 'FAILED'];
if (!in_array($result, $allowedResults, true)) $result = 'ALL';

$sort = $_GET['sort'] ?? 'started_at';
$dir  = strtoupper($_GET['dir'] ?? 'DESC');

$allowedSort = ['started_at', 'score_percent'];
$allowedDir  = ['ASC', 'DESC'];

if (!in_array($sort, $allowedSort, true)) $sort = 'started_at';
if (!in_array($dir, $allowedDir, true)) $dir = 'DESC';

$allowedTypes = ['ALL', 'EXAM', 'TRAINING'];
$allowedStatus = ['ALL', 'ACTIVE', 'TERMINATED', 'EXPIRED'];

if (!in_array($type, $allowedTypes, true)) $type = 'ALL';
if (!in_array($status, $allowedStatus, true)) $status = 'ALL';

$packagesWhere = ($activeProgramId > 0)
  ? ('WHERE ' . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false))
  : '';
$packagesStmt = $pdo->query("SELECT pk.id, pk.name FROM packages pk $packagesWhere ORDER BY pk.name ASC");
$packages = $packagesStmt->fetchAll() ?: [];
$packageIds = array_map(fn($pkg) => (string)$pkg['id'], $packages);
if ($package !== 'ALL' && !in_array($package, $packageIds, true)) {
  $package = 'ALL';
}

function admin_session_type_label(string $type): string {
  return match ($type) {
    'EXAM' => 'Exam',
    'TRAINING' => 'Test',
    default => $type,
  };
}

function admin_session_has_result(array $session): bool {
  return in_array((string)($session['status'] ?? ''), ['TERMINATED', 'EXPIRED'], true)
    && $session['passed'] !== null
    && $session['passed'] !== '';
}

// Construction WHERE + params
$where = [];
$params = [];
if ($activeProgramId > 0) {
  $where[] = auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false);
}

if ($type !== 'ALL') {
  $where[] = "s.session_type = ?";
  $params[] = $type;
}
if ($status !== 'ALL') {
  $where[] = "s.status = ?";
  $params[] = $status;
}
if ($search !== '') {
  $where[] = "c.email LIKE ?";
  $params[] = '%' . $search . '%';
}
if ($package !== 'ALL') {
  $where[] = "s.package_id = ?";
  $params[] = (int)$package;
}
if ($result === 'PASSED') {
  $where[] = "s.status IN ('TERMINATED', 'EXPIRED') AND s.passed=1";
} elseif ($result === 'FAILED') {
  $where[] = "s.status IN ('TERMINATED', 'EXPIRED') AND s.passed=0";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Pagination
$limit = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$countSql = "
  SELECT COUNT(*)
  FROM sessions s
  JOIN contacts c ON c.id = s.contact_id
  JOIN packages pk ON pk.id = s.package_id
  $whereSql
";
$countStmt = $pdo->prepare($countSql);
$i = 1;
foreach ($params as $v) {
  $countStmt->bindValue($i++, $v);
}
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $limit;

$sql = "
  SELECT s.id, s.started_at, s.submitted_at, s.status, s.termination_type, s.score_percent, s.passed,
         c.email, pk.name AS package_name, pk.name_color_hex AS package_color_hex, s.session_type
  FROM sessions s
  JOIN contacts c ON c.id = s.contact_id
  JOIN packages pk ON pk.id = s.package_id
  $whereSql
  ORDER BY s.$sort $dir
  LIMIT ? OFFSET ?
";

$stmt = $pdo->prepare($sql);

// 1) bind des filtres (type/status)
$i = 1;
foreach ($params as $v) {
  $stmt->bindValue($i++, $v);
}

// 2) bind LIMIT/OFFSET en INT (important pour MariaDB)
$stmt->bindValue($i++, (int)$limit, PDO::PARAM_INT);
$stmt->bindValue($i++, (int)$offset, PDO::PARAM_INT);

$stmt->execute();
$sessions = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === '1') {

  // Requete SANS pagination
  $exportSql = "
    SELECT s.started_at, c.email, s.session_type,
           pk.name AS package_name, s.status, s.score_percent, s.passed
    FROM sessions s
    JOIN contacts c ON c.id = s.contact_id
    JOIN packages pk ON pk.id = s.package_id
    $whereSql
    ORDER BY s.$sort $dir
  ";

  $exportStmt = $pdo->prepare($exportSql);

  // bind des filtres uniquement (pas de LIMIT/OFFSET)
  $i = 1;
  foreach ($params as $v) {
    $exportStmt->bindValue($i++, $v);
  }

  $exportStmt->execute();
  $rows = $exportStmt->fetchAll();

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="sessions_export.csv"');

  $out = fopen('php://output', 'w');
  // UTF-8 BOM for Excel compatibility.
  fwrite($out, "\xEF\xBB\xBF");

  fputcsv($out, ['started_at','email','session_type','package','status','score_percent','result']);

  foreach ($rows as $r) {
    $resultLabel = 'NA';
    if (admin_session_has_result($r)) {
      $resultLabel = ((int)$r['passed'] === 1) ? 'PASSED' : 'FAILED';
    }

    fputcsv($out, [
      $r['started_at'],
      $r['email'],
      $r['session_type'],
      $r['package_name'],
      $r['status'],
      $r['score_percent'],
      $resultLabel
    ]);
  }

  fclose($out);
  exit;
}

/* B - dashboard admin (stats) */
$statsWhere = $activeProgramId > 0
  ? ('WHERE ' . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false))
  : '';
$stats = $pdo->query("
  SELECT
    SUM(s.status='ACTIVE') AS active_count,
    SUM(s.status='TERMINATED') AS terminated_count,
    SUM(s.status='EXPIRED') AS expired_count,
    SUM(s.passed=1 AND s.status='TERMINATED' AND s.session_type='EXAM') AS passed_exam_count
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  $statsWhere
")->fetch();

?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('admin.sessions.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card admin-page-shell">
      <div class="admin-head admin-page-hero">
        <div class="admin-head-copy">
          <p class="admin-page-eyebrow">Administration</p>
          <h2 class="h1"><?= h(t('admin.sessions.title', [], $lang)) ?></h2>
          <p class="sub"><?= h(t('admin.sessions.subtitle', [], $lang)) ?></p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('sessions'); ?>
        </div>
      </div>

      <div class="admin-stats-grid">
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h(t('admin.sessions.stat_active', [], $lang)) ?></span>
          <strong class="admin-stat-value"><?= (int)$stats['active_count'] ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h(t('admin.sessions.stat_passed', [], $lang)) ?></span>
          <strong class="admin-stat-value"><?= (int)$stats['passed_exam_count'] ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h(t('admin.sessions.stat_terminated', [], $lang)) ?></span>
          <strong class="admin-stat-value"><?= (int)$stats['terminated_count'] ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h(t('admin.sessions.stat_expired', [], $lang)) ?></span>
          <strong class="admin-stat-value"><?= (int)$stats['expired_count'] ?></strong>
        </article>
      </div>

      <div class="admin-page-layout">
      <section class="admin-section-panel admin-section-panel-accent">
      <div class="section-head admin-section-head">
        <div>
          <h3 class="h1"><?= h(t('admin.sessions.filters_title', [], $lang)) ?></h3>
          <p class="sub"><?= h(t('admin.sessions.filters_subtitle', [], $lang)) ?></p>
        </div>
      </div>

      <form method="get" class="filters-grid sessions-filters admin-panel-surface">
        <div>
          <label class="label" for="search"><?= h(t('admin.common.email', [], $lang)) ?></label>
          <input class="input" id="search" type="text" name="search" value="<?= h($search) ?>" placeholder="Email...">
        </div>

        <div>
          <label class="label" for="type"><?= h(t('admin.sessions.filter_type', [], $lang)) ?></label>
          <select class="input" id="type" name="type">
            <option value="ALL" <?= $type==='ALL'?'selected':'' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
            <option value="EXAM" <?= $type==='EXAM'?'selected':'' ?>><?= h(t('admin.sessions.type_exam', [], $lang)) ?></option>
            <option value="TRAINING" <?= $type==='TRAINING'?'selected':'' ?>><?= h(t('admin.sessions.type_training', [], $lang)) ?></option>
          </select>
        </div>

        <div>
          <label class="label" for="package"><?= h(t('admin.sessions.filter_pack', [], $lang)) ?></label>
          <select class="input" id="package" name="package">
            <option value="ALL" <?= $package==='ALL'?'selected':'' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
            <?php foreach ($packages as $pkg): ?>
              <option value="<?= (int)$pkg['id'] ?>" <?= $package===(string)$pkg['id']?'selected':'' ?>>
                <?= h($pkg['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label" for="status"><?= h(t('admin.common.status', [], $lang)) ?></label>
          <select class="input" id="status" name="status">
            <option value="ALL" <?= $status==='ALL'?'selected':'' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
            <option value="ACTIVE" <?= $status==='ACTIVE'?'selected':'' ?>><?= h(t('admin.status.active', [], $lang)) ?></option>
            <option value="TERMINATED" <?= $status==='TERMINATED'?'selected':'' ?>><?= h(t('admin.status.terminated', [], $lang)) ?></option>
            <option value="EXPIRED" <?= $status==='EXPIRED'?'selected':'' ?>><?= h(t('admin.status.expired', [], $lang)) ?></option>
          </select>
        </div>

        <div>
          <label class="label" for="result"><?= h(t('admin.sessions.filter_result', [], $lang)) ?></label>
          <select class="input" id="result" name="result">
            <option value="ALL" <?= $result==='ALL'?'selected':'' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
            <option value="PASSED" <?= $result==='PASSED'?'selected':'' ?>><?= h(t('admin.common.passed', [], $lang)) ?></option>
            <option value="FAILED" <?= $result==='FAILED'?'selected':'' ?>><?= h(t('admin.common.failed', [], $lang)) ?></option>
          </select>
        </div>

      <div class="filters-actions">
          <button class="btn" type="submit"><?= h(t('admin.common.filter', [], $lang)) ?></button>
          <a class="btn ghost" href="/admin/index.php<?= $activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId : '' ?>"><?= h(t('admin.common.reset', [], $lang)) ?></a>
          <button class="btn ghost" type="submit" name="export" value="1"><?= h(t('admin.common.export_csv', [], $lang)) ?></button>
        </div>
      </form>
      </section>

      <section class="admin-section-panel">
      <div class="section-head admin-section-head">
        <div>
          <h3 class="h1"><?= h(t('admin.sessions.list_title', [], $lang)) ?></h3>
          <p class="sub sessions-meta"><?= h(t('admin.common.page_of', ['page' => $page, 'total' => $totalPages, 'count' => $totalRows], $lang)) ?></p>
        </div>
      </div>

      <div class="table-wrap admin-table-panel">
        <?php if (!$sessions): ?>
          <p class="empty-state"><?= h(t('admin.sessions.none', [], $lang)) ?></p>
        <?php else: ?>
          <table class="table questions-table sessions-table">
            <thead>
	              <tr>
                <th>
                  <?php
                    $qs = $_GET;
                    $qs['sort'] = 'started_at';
                    if ($sort !== 'started_at') { $qs['dir'] = 'DESC'; } else { $qs['dir'] = ($dir === 'DESC') ? 'ASC' : 'DESC'; }
                    unset($qs['page']);
                    $url = '/admin/index.php?' . http_build_query($qs);
                  ?>
                  <a class="sort-link" href="<?= h($url) ?>">
                    <?= h(t('admin.sessions.col_started', [], $lang)) ?>
                    <?php if ($sort === 'started_at'): ?><span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
	                </th>
	                <th><?= h(t('admin.sessions.col_ended', [], $lang)) ?></th>
	                <th><?= h(t('admin.common.email', [], $lang)) ?></th>
	                <th><?= h(t('admin.common.type', [], $lang)) ?></th>
                <th><?= h(t('admin.common.pack', [], $lang)) ?></th>
                <th><?= h(t('admin.common.status', [], $lang)) ?></th>
                <th>
                  <?php
                    $qs = $_GET;
                    $qs['sort'] = 'score_percent';
                    if ($sort !== 'score_percent') { $qs['dir'] = 'DESC'; } else { $qs['dir'] = ($dir === 'DESC') ? 'ASC' : 'DESC'; }
                    unset($qs['page']);
                    $url = '/admin/index.php?' . http_build_query($qs);
                  ?>
                  <a class="sort-link" href="<?= h($url) ?>">
                    <?= h(t('admin.common.score', [], $lang)) ?>
                    <?php if ($sort === 'score_percent'): ?><span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th><?= h(t('admin.common.result', [], $lang)) ?></th>
                <th><?= h(t('admin.common.action', [], $lang)) ?></th>
              </tr>
            </thead>
            <tbody>
	              <?php foreach ($sessions as $s): ?>
	                <tr>
	                  <td><?= h($s['started_at']) ?></td>
                    <td><?= $s['status'] === 'ACTIVE' ? '-' : h((string)($s['submitted_at'] ?? '-')) ?></td>
	                  <td>
	                    <a href="/admin/contact.php?email=<?= urlencode($s['email']) ?><?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>">
                      <?= h($s['email']) ?>
                    </a>
                  </td>
                  <td><?= h(admin_session_type_label((string)$s['session_type'])) ?></td>
	                  <td><span style="<?= h(package_label_style((string)$s['package_name'], (string)($s['package_color_hex'] ?? ''))) ?>"><?= h($s['package_name']) ?></span></td>
	                  <td>
                      <?php $isTimeout = strtoupper(trim((string)($s['termination_type'] ?? ''))) === 'TIMEOUT'; ?>
	                    <?php if ($s['status'] === 'TERMINATED' && !$isTimeout): ?>
	                      <span class="badge ok"><?= h(t('admin.status.terminated', [], $lang)) ?></span>
	                    <?php elseif ($s['status'] === 'EXPIRED' || $isTimeout): ?>
	                      <span class="badge bad"><?= h(t('admin.status.expired', [], $lang)) ?></span>
	                    <?php else: ?>
	                      <span class="badge"><?= h(t('admin.status.active', [], $lang)) ?></span>
	                    <?php endif; ?>
	                  </td>
                  <td><?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?></td>
                  <td>
                    <?php if (admin_session_has_result($s)): ?>
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
                    <a class="btn ghost icon-btn" href="/admin/session.php?sid=<?= h($s['id']) ?><?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>&return=<?= h(urlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/index.php'))) ?>" aria-label="<?= h(t('admin.common.view_detail', [], $lang)) ?>" title="<?= h(t('admin.common.view_detail', [], $lang)) ?>">
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

      <?php
        $qs = $_GET;
        unset($qs['page']);
        $base = '/admin/index.php';
        $common = $qs ? ('?' . http_build_query($qs)) : '';
        $sep = $common ? '&' : '?';
      ?>

      <div class="sessions-pagination">
        <?php if ($page > 1): ?>
          <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page - 1)) ?>">&larr;</a>
        <?php else: ?>
          <button class="btn ghost" disabled>&larr;</button>
        <?php endif; ?>

        <?php if ($totalPages <= 7): ?>
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
        <?php else: ?>
          <a class="btn <?= $page === 1 ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=1') ?>">1</a>

          <?php if ($page <= 4): ?>
            <?php for ($p = 2; $p <= 5; $p++): ?>
              <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php elseif ($page >= ($totalPages - 3)): ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php for ($p = $totalPages - 4; $p <= $totalPages - 1; $p++): ?>
              <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
          <?php else: ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php for ($p = $page - 1; $p <= $page + 1; $p++): ?>
              <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php endif; ?>

          <a class="btn <?= $totalPages === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $totalPages) ?>"><?= (int)$totalPages ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page + 1)) ?>">&rarr;</a>
        <?php else: ?>
          <button class="btn ghost" disabled>&rarr;</button>
        <?php endif; ?>
      </div>
      </section>
      </div>
    </div>
  </div>
</body>
</html>
