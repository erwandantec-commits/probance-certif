<?php
require_once __DIR__ . '/_auth.php';
require_admin();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

$email = trim($_GET['email'] ?? '');
if ($email === '') { http_response_code(400); echo "Missing email"; exit; }

// Tri historique
$hsort = $_GET['hsort'] ?? 'started_at';
$hdir  = strtoupper($_GET['hdir'] ?? 'DESC');

$allowedSort = ['started_at', 'score_percent'];
$allowedDir  = ['ASC', 'DESC'];

if (!in_array($hsort, $allowedSort, true)) $hsort = 'started_at';
if (!in_array($hdir, $allowedDir, true)) $hdir = 'DESC';

$hresult = strtoupper(trim($_GET['hresult'] ?? 'ALL'));
$allowedResults = ['ALL', 'PASSED', 'FAILED'];
if (!in_array($hresult, $allowedResults, true)) $hresult = 'ALL';

// Récupérer le contact
$stmt = $pdo->prepare("SELECT * FROM contacts WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$contact = $stmt->fetch();

if (!$contact) {
  http_response_code(404);
  echo "Contact not found";
  exit;
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

// Certifs (dernière EXAM réussie par package)
$certs = $pdo->prepare("
  SELECT
    pk.name AS package_name,
    MAX(s.submitted_at) AS last_cert_date
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.contact_id = ?
    AND s.status = 'TERMINATED'
    AND s.passed = 1
    AND s.session_type = 'EXAM'
  GROUP BY pk.name
  ORDER BY last_cert_date DESC
");
$certs->execute([(int)$contact['id']]);
$certs = $certs->fetchAll();

// Historique sessions
$hist = $pdo->prepare("
  SELECT s.id, s.started_at, s.submitted_at, s.status, s.session_type, s.score_percent, s.passed,
         pk.name AS package_name
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
$hist->execute([(int)$contact['id'], $hresult, $hresult, $hresult]);
$hist = $hist->fetchAll();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Profil candidat</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="container">
    <div class="card">
      <div class="header">
        <div>
          <h2 class="h1">Profil candidat</h2>
          <p class="sub"><?= h($contact['email']) ?></p>
        </div>
        <a class="btn ghost" href="/admin/index.php">← Retour</a>
      </div>

      <div class="row" style="margin:12px 0;">
        <span class="badge">Sessions: <?= (int)$summary['total_sessions'] ?></span>
        <span class="badge">Dernière activité: <?= h($summary['last_activity'] ?: '-') ?></span>
        <span class="badge ok">EXAM réussis: <?= (int)$summary['passed_exam_count'] ?></span>
        <span class="badge">
          Score moyen EXAM:
          <?= $summary['avg_exam_score'] !== null
              ? h($summary['avg_exam_score']).'%'
              : '-' ?>
        </span>
      </div>

      <h3 class="h1" style="margin-top:14px;">Certifications</h3>
      <p class="sub">Dernière session EXAM terminée et réussie par certification.</p>

      <table class="table">
        <tr>
          <th>Certification</th>
          <th>Dernière réussite</th>
          <th>Statut</th>
        </tr>
        <?php if (!$certs): ?>
          <tr><td colspan="3">Aucune certification réussie.</td></tr>
        <?php endif; ?>

        <?php foreach ($certs as $c):
          $ts = strtotime($c['last_cert_date']);
          $valid = $ts && ($ts >= time() - 365*24*3600);
        ?>
          <tr>
            <td><?= h($c['package_name']) ?></td>
            <td><?= h($c['last_cert_date']) ?></td>
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

      <h3 class="h1" style="margin-top:18px;">Historique des sessions</h3>

      <form method="get" style="margin:10px 0; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="email" value="<?= h($contact['email']) ?>">
        <input type="hidden" name="hsort" value="<?= h($hsort) ?>">
        <input type="hidden" name="hdir" value="<?= h($hdir) ?>">

        <select class="input" name="hresult" style="width:auto; min-width:150px; flex:0 0 auto;">
          <option value="ALL" <?= $hresult==='ALL'?'selected':'' ?>>Résultat</option>
          <option value="PASSED" <?= $hresult==='PASSED'?'selected':'' ?>>Réussi</option>
          <option value="FAILED" <?= $hresult==='FAILED'?'selected':'' ?>>Échoué</option>
        </select>

        <button class="btn" type="submit">Filtrer</button>

        <a class="btn ghost"
          href="/admin/contact.php?email=<?= urlencode($contact['email']) ?>"
          style="flex:0 0 auto;">
          Reset
        </a>
      </form>

      <table class="table">
        <tr>
          <th>
            <?php
              $qs = $_GET;
              $qs['hsort'] = 'started_at';

              if ($hsort !== 'started_at') {
                $qs['hdir'] = 'DESC';
              } else {
                $qs['hdir'] = ($hdir === 'DESC') ? 'ASC' : 'DESC';
              }

              $url = '/admin/contact.php?' . http_build_query($qs);
            ?>
            <a href="<?= h($url) ?>" style="text-decoration:none; color:inherit;">
              Date début
              <?php if ($hsort === 'started_at'): ?>
                <?= $hdir === 'DESC' ? '↓' : '↑' ?>
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

              if ($hsort !== 'score_percent') {
                $qs['hdir'] = 'DESC';
              } else {
                $qs['hdir'] = ($hdir === 'DESC') ? 'ASC' : 'DESC';
              }

              $url = '/admin/contact.php?' . http_build_query($qs);
            ?>
            <a href="<?= h($url) ?>" style="text-decoration:none; color:inherit;">
              Score
              <?php if ($hsort === 'score_percent'): ?>
                <?= $hdir === 'DESC' ? '↓' : '↑' ?>
              <?php endif; ?>
            </a>
          </th>
          <th>Résultat</th>
          <th></th>
        </tr>

        <?php foreach ($hist as $s): ?>
          <tr>
            <td><?= h($s['started_at']) ?></td>
            <td><?= h($s['session_type']) ?></td>
            <td><?= h($s['package_name']) ?></td>
            <td>
              <?php if ($s['status'] === 'TERMINATED'): ?>
                <span class="badge ok">Terminé</span>
              <?php elseif ($s['status'] === 'EXPIRED'): ?>
                <span class="badge bad">Expiré</span>
              <?php else: ?>
                <span class="badge">Actif</span>
              <?php endif; ?>
            </td>
            <td>
              <?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?>
            </td>
            <td>
              <?php if ($s['session_type'] === 'EXAM' && $s['status'] === 'TERMINATED'): ?>
                <?php if ($s['passed']): ?>
                  <span class="badge ok">Réussi</span>
                <?php else: ?>
                  <span class="badge bad">Échoué</span>
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

    </div>
  </div>
</body>
</html>
