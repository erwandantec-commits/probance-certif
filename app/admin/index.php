<?php
require_once __DIR__ . '/_auth.php';
require_admin();

require_once __DIR__ . '/../i18n.php';
$lang = get_lang();

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
  $stmt->bindValue($i++, $v); // strings OK
}

// 2) bind LIMIT/OFFSET en INT (important pour MariaDB)
$stmt->bindValue($i++, (int)$limit, PDO::PARAM_INT);
$stmt->bindValue($i++, (int)$offset, PDO::PARAM_INT);

$stmt->execute();
$sessions = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === '1') {

  // Requête SANS pagination
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

/* A — Profils certifiés (EXAM réussi uniquement) */
$profiles = $pdo->query("
  SELECT 
    c.email,
    MAX(s.submitted_at) AS last_cert_date,
    pk.name AS package_name
  FROM sessions s
  JOIN contacts c ON c.id = s.contact_id
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.status='TERMINATED'
    AND s.passed=1
    AND s.session_type='EXAM'
  GROUP BY c.email, pk.name
  ORDER BY last_cert_date DESC
  LIMIT 200
")->fetchAll();

/* B — dashboard admin (stats) */
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
<html>
<head>
  <meta charset="utf-8">
  <title>Admin</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="container">
    <div class="card">
      <h2><?= h(t('admin.sessions')) ?></h2>

      <p>
        <a href="/start.php">Start</a> |
        <a href="/admin/packages.php">Packages</a> |
        <a href="/admin/questions.php">Questions</a> |
        <a href="/admin/logout.php">Déconnexion</a>
      </p>

      <?php $qs = $_GET; ?>
      <p class="sub" style="margin-top:6px;">
        <a href="/admin/index.php?<?= h(http_build_query(array_merge($qs,['lang'=>'fr']))) ?>">FR</a> |
        <a href="/admin/index.php?<?= h(http_build_query(array_merge($qs,['lang'=>'en']))) ?>">EN</a> |
        <a href="/admin/index.php?<?= h(http_build_query(array_merge($qs,['lang'=>'ja']))) ?>">日本語</a>
      </p>

      <form method="get"
            style="margin:12px 0; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">

        <select class="input" name="type" style="width:auto; min-width:140px; flex:0 0 auto;">
          <option value="ALL" <?= $type==='ALL'?'selected':'' ?>>Type</option>
          <option value="EXAM" <?= $type==='EXAM'?'selected':'' ?>>EXAM</option>
          <option value="TRAINING" <?= $type==='TRAINING'?'selected':'' ?>>TRAINING</option>
        </select>

        <select class="input" name="status" style="width:auto; min-width:170px; flex:0 0 auto;">
          <option value="ALL" <?= $status==='ALL'?'selected':'' ?>>Statut</option>
          <option value="ACTIVE" <?= $status==='ACTIVE'?'selected':'' ?>>ACTIVE</option>
          <option value="TERMINATED" <?= $status==='TERMINATED'?'selected':'' ?>>TERMINATED</option>
          <option value="EXPIRED" <?= $status==='EXPIRED'?'selected':'' ?>>EXPIRED</option>
        </select>

        <select class="input" name="result" style="width:auto; min-width:150px; flex:0 0 auto;">
          <option value="ALL" <?= $result==='ALL'?'selected':'' ?>>Résultat</option>
          <option value="PASSED" <?= $result==='PASSED'?'selected':'' ?>>Réussi</option>
          <option value="FAILED" <?= $result==='FAILED'?'selected':'' ?>>Échoué</option>
        </select>

        <input class="input"
              type="text"
              name="search"
              value="<?= h($search) ?>"
              placeholder="<?= h(t('filters.email')) ?>"
              style="width:260px; flex:0 0 auto;">

        <button class="btn" type="submit"><?= h(t('btn.filter')) ?></button>

        <button class="btn ghost" type="submit" name="export" value="1">
          <?= h(t('btn.export')) ?>
        </button>

        <a class="btn ghost" href="/admin/index.php"><?= h(t('btn.reset')) ?></a>

      </form>

      <div class="row" style="margin:12px 0;">
        <span class="badge">Actives: <?= (int)$stats['active_count'] ?></span>
        <span class="badge ok">EXAM réussies: <?= (int)$stats['passed_exam_count'] ?></span>
        <span class="badge">Terminées: <?= (int)$stats['terminated_count'] ?></span>
        <span class="badge bad">Expirées: <?= (int)$stats['expired_count'] ?></span>
      </div>

      <p class="sub" style="margin:8px 0 12px;">
        Page <?= (int)$page ?> (<?= count($sessions) ?> résultats)
      </p>

      <table class="table">
        <tr>
          <th>
            <?php
              $qs = $_GET;
              $qs['sort'] = 'started_at';

              if ($sort !== 'started_at') {
                $qs['dir'] = 'DESC'; // premier clic sur Date = DESC
              } else {
                $qs['dir'] = ($dir === 'DESC') ? 'ASC' : 'DESC';
              }

              unset($qs['page']);
              $url = '/admin/index.php?' . http_build_query($qs);
            ?>
            <a href="<?= h($url) ?>" style="text-decoration:none; color:inherit;">
            <?= h(t('th.started_at')) ?>
              <?php if ($sort === 'started_at'): ?>
                <?= $dir === 'DESC' ? '↓' : '↑' ?>
              <?php endif; ?>
            </a>
          </th>
          <th>Email</th>
          <th>Type</th>
          <th>Package</th>
          <th><?= h(t('th.status')) ?></th>
          <th>
            <?php
              $qs = $_GET;
              $qs['sort'] = 'score_percent';

              // Si on clique pour la première fois sur Score → DESC
              if ($sort !== 'score_percent') {
                $qs['dir'] = 'DESC';
              } else {
                $qs['dir'] = ($dir === 'DESC') ? 'ASC' : 'DESC';
              }

              unset($qs['page']);
              $url = '/admin/index.php?' . http_build_query($qs);
            ?>
            <a href="<?= h($url) ?>" style="text-decoration:none; color:inherit;">
              Score
              <?php if ($sort === 'score_percent'): ?>
                <?= $dir === 'DESC' ? '↓' : '↑' ?>
              <?php endif; ?>
            </a>
          </th>
          <th><?= h(t('th.result')) ?></th>
          <th></th>
        </tr>

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
                <span class="badge ok"><?= h(t('badge.done')) ?></span>
              <?php elseif ($s['status'] === 'EXPIRED'): ?>
                <span class="badge bad"><?= h(t('badge.expired')) ?></span>
              <?php else: ?>
                <span class="badge"><?= h(t('badge.active')) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?>
            </td>

            <td>
              <?php if ($s['session_type'] === 'EXAM' && $s['status'] === 'TERMINATED'): ?>
                <?php if ((int)$s['passed'] === 1): ?>
                  <span class="badge ok"><?= h(t('badge.passed')) ?></span>
                <?php else: ?>
                  <span class="badge bad"><?= h(t('badge.failed')) ?></span>
                <?php endif; ?>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>

            <td>
              <a class="btn ghost" href="/admin/session.php?sid=<?= h($s['id']) ?>">Voir</a>
            </td>

          </tr>
        <?php endforeach; ?>
      </table>

      <?php
        // reconstruit l'URL en gardant les filtres
        $qs = $_GET;
        unset($qs['page']);
        $base = '/admin/index.php';
        $common = $qs ? ('?' . http_build_query($qs)) : '';
        $sep = $common ? '&' : '?';
        ?>

        <div style="margin-top:12px; display:flex; gap:8px;">
          
          <?php if ($page > 1): ?>
            <a class="btn ghost"
              href="<?= h($base . $common . $sep . 'page=' . ($page - 1)) ?>">
              ← Page précédente
            </a>
          <?php endif; ?>

          <?php if (count($sessions) === $limit): ?>
            <a class="btn ghost"
              href="<?= h($base . $common . $sep . 'page=' . ($page + 1)) ?>">
              Page suivante →
            </a>
          <?php endif; ?>

        </div>


      <!-- Section Profils certifiés -->
      <h2 class="h1" style="margin-top:18px;">Profils — Certifications valides (&lt; 1 an)</h2>
      <p class="sub">Basé sur les sessions EXAM terminées et réussies.</p>

      <table class="table">
        <tr>
          <th>Email</th>
          <th>Certification</th>
          <th>Date</th>
          <th>Statut</th>
        </tr>

        <?php foreach ($profiles as $p):
          $ts = strtotime($p['last_cert_date']);
          $valid = $ts && ($ts >= time() - 365*24*3600);
        ?>
          <tr>
            <td>
              <a href="/admin/contact.php?email=<?= urlencode($p['email']) ?>">
                <?= h($p['email']) ?>
              </a>
            </td>
            <td><?= h($p['package_name']) ?></td>
            <td><?= h($p['last_cert_date']) ?></td>
            <td>
              <?php if ($valid): ?>
                <span class="badge ok">Certifié</span>
              <?php else: ?>
                <span class="badge bad">Expiré</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>

    </div>
  </div>
</body>
</html>
