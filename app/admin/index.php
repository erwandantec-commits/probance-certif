<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

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

$packagesStmt = $pdo->query("SELECT id, name FROM packages ORDER BY name ASC");
$packages = $packagesStmt->fetchAll() ?: [];
$packageIds = array_map(fn($pkg) => (string)$pkg['id'], $packages);
if ($package !== 'ALL' && !in_array($package, $packageIds, true)) {
  $package = 'ALL';
}

function admin_session_type_label(string $type): string {
  return match ($type) {
    'EXAM' => 'Certification',
    'TRAINING' => 'Test',
    default => $type,
  };
}

// Construction WHERE + params
$where = [];
$params = [];

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
  $where[] = "s.session_type='EXAM' AND s.status='TERMINATED' AND s.passed=1";
} elseif ($result === 'FAILED') {
  $where[] = "s.session_type='EXAM' AND s.status='TERMINATED' AND s.passed=0";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Pagination
$limit = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$countSql = "
  SELECT COUNT(*)
  FROM sessions s
  JOIN contacts c ON c.id = s.contact_id
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
         c.email, pk.name AS package_name, s.session_type
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

  fputcsv($out, ['started_at','email','session_type','package','status','score_percent','result']);

  foreach ($rows as $r) {
    $resultLabel = 'NA';
    if ($r['session_type'] === 'EXAM' && $r['status'] === 'TERMINATED') {
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
$stats = $pdo->query("
  SELECT
    SUM(status='ACTIVE') AS active_count,
    SUM(status='TERMINATED') AS terminated_count,
    SUM(status='EXPIRED') AS expired_count,
    SUM(passed=1 AND status='TERMINATED' AND session_type='EXAM') AS passed_exam_count
  FROM sessions
")->fetch();

?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Sessions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <script src="/assets/theme-toggle.js?v=1" defer></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Sessions</h2>
          <p class="sub">Pilotage des sessions et certifications</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('sessions'); ?>
        </div>
      </div>

      <hr class="separator">

      <form method="get" class="filters-grid sessions-filters">
        <div>
          <label class="label" for="type">Type</label>
          <select class="input" id="type" name="type">
            <option value="ALL" <?= $type==='ALL'?'selected':'' ?>>Tous</option>
            <option value="EXAM" <?= $type==='EXAM'?'selected':'' ?>>Certification</option>
            <option value="TRAINING" <?= $type==='TRAINING'?'selected':'' ?>>Test</option>
          </select>
        </div>

        <div>
          <label class="label" for="package">Package</label>
          <select class="input" id="package" name="package">
            <option value="ALL" <?= $package==='ALL'?'selected':'' ?>>Tous</option>
            <?php foreach ($packages as $pkg): ?>
              <option value="<?= (int)$pkg['id'] ?>" <?= $package===(string)$pkg['id']?'selected':'' ?>>
                <?= h($pkg['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label" for="status">Statut</label>
          <select class="input" id="status" name="status">
            <option value="ALL" <?= $status==='ALL'?'selected':'' ?>>Tous</option>
            <option value="ACTIVE" <?= $status==='ACTIVE'?'selected':'' ?>>En cours</option>
            <option value="TERMINATED" <?= $status==='TERMINATED'?'selected':'' ?>>Termin&eacute;</option>
            <option value="EXPIRED" <?= $status==='EXPIRED'?'selected':'' ?>>Expir&eacute;</option>
          </select>
        </div>

        <div>
          <label class="label" for="result">R&eacute;sultat</label>
          <select class="input" id="result" name="result">
            <option value="ALL" <?= $result==='ALL'?'selected':'' ?>>Tous</option>
            <option value="PASSED" <?= $result==='PASSED'?'selected':'' ?>>R&eacute;ussi</option>
            <option value="FAILED" <?= $result==='FAILED'?'selected':'' ?>>Echou&eacute;</option>
          </select>
        </div>

        <div>
          <label class="label" for="search">Email</label>
          <input class="input" id="search" type="text" name="search" value="<?= h($search) ?>" placeholder="Email...">
        </div>

        <div class="filters-actions">
          <button class="btn" type="submit">Filtrer</button>
          <a class="btn ghost" href="/admin/index.php">Reset</a>
          <button class="btn ghost" type="submit" name="export" value="1">Exporter CSV</button>
        </div>
      </form>

      <div class="row sessions-stats">
        <span class="badge">Actives: <?= (int)$stats['active_count'] ?></span>
        <span class="badge ok">EXAM r&eacute;ussies: <?= (int)$stats['passed_exam_count'] ?></span>
        <span class="badge">Termin&eacute;es: <?= (int)$stats['terminated_count'] ?></span>
        <span class="badge bad">Expir&eacute;es: <?= (int)$stats['expired_count'] ?></span>
      </div>

      <p class="sub sessions-meta">Page <?= (int)$page ?> / <?= (int)$totalPages ?> (<?= (int)$totalRows ?> r&eacute;sultats)</p>

      <div class="table-wrap">
        <?php if (!$sessions): ?>
          <p class="empty-state">Aucune session trouv&eacute;e.</p>
        <?php else: ?>
          <table class="table questions-table sessions-table">
            <thead>
	              <tr>
                <th>
                  <?php
                    $qs = $_GET;
                    $qs['sort'] = 'started_at';

                    if ($sort !== 'started_at') {
                      $qs['dir'] = 'DESC';
                    } else {
                      $qs['dir'] = ($dir === 'DESC') ? 'ASC' : 'DESC';
                    }

                    unset($qs['page']);
                    $url = '/admin/index.php?' . http_build_query($qs);
                  ?>
                  <a class="sort-link" href="<?= h($url) ?>">
                    Date d&eacute;but
                    <?php if ($sort === 'started_at'): ?>
                      <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span>
                    <?php endif; ?>
                  </a>
	                </th>
	                <th>Date fin</th>
	                <th>Email</th>
	                <th>Type</th>
                <th>Package</th>
                <th>Statut</th>
                <th>
                  <?php
                    $qs = $_GET;
                    $qs['sort'] = 'score_percent';

                    if ($sort !== 'score_percent') {
                      $qs['dir'] = 'DESC';
                    } else {
                      $qs['dir'] = ($dir === 'DESC') ? 'ASC' : 'DESC';
                    }

                    unset($qs['page']);
                    $url = '/admin/index.php?' . http_build_query($qs);
                  ?>
                  <a class="sort-link" href="<?= h($url) ?>">
                    Score
                    <?php if ($sort === 'score_percent'): ?>
                      <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span>
                    <?php endif; ?>
                  </a>
                </th>
                <th>R&eacute;sultat</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
	              <?php foreach ($sessions as $s): ?>
	                <tr>
	                  <td><?= h($s['started_at']) ?></td>
                    <td><?= $s['status'] === 'ACTIVE' ? '-' : h((string)($s['submitted_at'] ?? '-')) ?></td>
	                  <td>
	                    <a href="/admin/contact.php?email=<?= urlencode($s['email']) ?>">
                      <?= h($s['email']) ?>
                    </a>
                  </td>
                  <td><?= h(admin_session_type_label((string)$s['session_type'])) ?></td>
	                  <td><span style="<?= h(package_label_style((string)$s['package_name'])) ?>"><?= h($s['package_name']) ?></span></td>
	                  <td>
                      <?php $isTimeout = strtoupper(trim((string)($s['termination_type'] ?? ''))) === 'TIMEOUT'; ?>
	                    <?php if ($s['status'] === 'TERMINATED' && !$isTimeout): ?>
	                      <span class="badge ok">Termin&eacute;</span>
	                    <?php elseif ($s['status'] === 'EXPIRED' || $isTimeout): ?>
	                      <span class="badge bad">Expir&eacute;e</span>
	                    <?php else: ?>
	                      <span class="badge">En cours</span>
	                    <?php endif; ?>
	                  </td>
                  <td><?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?></td>
                  <td>
                    <?php if ($s['session_type'] === 'EXAM' && $s['status'] === 'TERMINATED'): ?>
                      <?php if ((int)$s['passed'] === 1): ?>
                        <span class="badge ok">R&eacute;ussi</span>
                      <?php else: ?>
                        <span class="badge bad">Echou&eacute;</span>
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
    </div>
  </div>
</body>
</html>
