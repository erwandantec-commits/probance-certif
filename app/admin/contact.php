<?php
require_once __DIR__ . '/_auth.php';
require_admin();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../services/session_service.php';
require_once __DIR__ . '/_nav.php';
$pdo = db();

$email = trim($_GET['email'] ?? '');
if ($email === '') { http_response_code(400); echo "Missing email"; exit; }

$sessionEndExpr = sessions_column_exists($pdo, 'ended_at')
  ? "COALESCE(s.ended_at, s.submitted_at, s.started_at)"
  : "COALESCE(s.submitted_at, s.started_at)";

$hsort = $_GET['hsort'] ?? 'started_at';
$hdir  = strtoupper($_GET['hdir'] ?? 'DESC');

$allowedSort = ['started_at', 'score_percent'];
$allowedDir  = ['ASC', 'DESC'];
if (!in_array($hsort, $allowedSort, true)) $hsort = 'started_at';
if (!in_array($hdir, $allowedDir, true)) $hdir = 'DESC';

$hresult = strtoupper(trim($_GET['hresult'] ?? 'ALL'));
$allowedResults = ['ALL', 'PASSED', 'FAILED'];
if (!in_array($hresult, $allowedResults, true)) $hresult = 'ALL';

function admin_session_type_label(string $type): string {
  return match ($type) {
    'EXAM' => 'Exam',
    'TRAINING' => 'Test',
    default => $type,
  };
}

$stmt = $pdo->prepare("SELECT * FROM contacts WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$contact = $stmt->fetch();
if (!$contact) { http_response_code(404); echo "Contact not found"; exit; }

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
    MAX($sessionEndExpr) AS last_cert_date
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.contact_id = ?
    AND s.status = 'TERMINATED'
    AND s.passed = 1
    AND s.session_type = 'EXAM'
  GROUP BY pk.name
  ORDER BY last_cert_date DESC
");
$certsStmt->execute([(int)$contact['id']]);
$certs = $certsStmt->fetchAll();

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
    AND (
      ? = 'ALL'
      OR (? = 'PASSED' AND s.session_type='EXAM' AND s.status='TERMINATED' AND s.passed=1)
      OR (? = 'FAILED' AND s.session_type='EXAM' AND s.status='TERMINATED' AND s.passed=0)
    )
  ORDER BY s.$hsort $hdir
  LIMIT 200
");
$histStmt->execute([(int)$contact['id'], $hresult, $hresult, $hresult]);
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
        <span class="badge ok">Exams reussis: <?= (int)$summary['passed_exam_count'] ?></span>
        <span class="badge">Score moyen Exam: <?= $summary['avg_exam_score'] !== null ? h($summary['avg_exam_score']).'%' : '-' ?></span>
      </div>

      <div class="section-head">
        <div>
          <h2 class="h1">Exams</h2>
          <p class="sub">Derniere session EXAM reussie par Exam.</p>
        </div>
      </div>

      <div class="table-wrap">
        <?php if (!$certs): ?>
          <p class="empty-state">Aucun Exam reussi.</p>
        <?php else: ?>
          <table class="table questions-table">
            <thead>
              <tr>
                <th>Exam</th>
                <th>Derniere reussite</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($certs as $c):
                $certStatus = certification_status_from_last_success((string)$c['last_cert_date']);
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

      <div class="section-head">
        <div>
          <h2 class="h1">Historique des sessions</h2>
        </div>
      </div>

      <form method="get" class="filters-grid">
        <input type="hidden" name="email" value="<?= h($contact['email']) ?>">
        <input type="hidden" name="hsort" value="<?= h($hsort) ?>">
        <input type="hidden" name="hdir" value="<?= h($hdir) ?>">

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
                <th></th>
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
                    <a class="btn ghost" href="/admin/session.php?sid=<?= h($s['id']) ?>">Voir</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
