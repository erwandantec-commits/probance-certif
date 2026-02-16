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
if ($result === 'PASSED') {
  $where[] = "s.session_type='EXAM' AND s.status='TERMINATED' AND s.passed=1";
} elseif ($result === 'FAILED') {
  $where[] = "s.session_type='EXAM' AND s.status='TERMINATED' AND s.passed=0";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Pagination
$limit = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sql = "
  SELECT s.id, s.started_at, s.submitted_at, s.status, s.score_percent, s.passed,
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
            <option value="EXAM" <?= $type==='EXAM'?'selected':'' ?>>EXAM</option>
            <option value="TRAINING" <?= $type==='TRAINING'?'selected':'' ?>>TRAINING</option>
          </select>
        </div>

        <div>
          <label class="label" for="status">Statut</label>
          <select class="input" id="status" name="status">
            <option value="ALL" <?= $status==='ALL'?'selected':'' ?>>Tous</option>
            <option value="ACTIVE" <?= $status==='ACTIVE'?'selected':'' ?>>ACTIVE</option>
            <option value="TERMINATED" <?= $status==='TERMINATED'?'selected':'' ?>>TERMINATED</option>
            <option value="EXPIRED" <?= $status==='EXPIRED'?'selected':'' ?>>EXPIRED</option>
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

      <p class="sub sessions-meta">Page <?= (int)$page ?> (<?= count($sessions) ?> r&eacute;sultats)</p>

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
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sessions as $s): ?>
                <tr>
                  <td><?= h($s['started_at']) ?></td>
                  <td>
                    <a href="/admin/contact.php?email=<?= urlencode($s['email']) ?>">
                      <?= h($s['email']) ?>
                    </a>
                  </td>
                  <td><?= h($s['session_type']) ?></td>
                  <td><?= h($s['package_name']) ?></td>
                  <td>
                    <?php if ($s['status'] === 'TERMINATED'): ?>
                      <span class="badge ok">Termin&eacute;</span>
                    <?php elseif ($s['status'] === 'EXPIRED'): ?>
                      <span class="badge bad">Expir&eacute;</span>
                    <?php else: ?>
                      <span class="badge">Actif</span>
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
                    <a class="btn ghost" href="/admin/session.php?sid=<?= h($s['id']) ?>">Voir</a>
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
          <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page - 1)) ?>">&larr; Page pr&eacute;c&eacute;dente</a>
        <?php endif; ?>

        <?php if (count($sessions) === $limit): ?>
          <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page + 1)) ?>">Page suivante &rarr;</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
