<?php
require_once __DIR__ . '/_auth.php';
require_admin();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../services/session_service.php';
$pdo = db();

$email = trim((string)($_GET['email'] ?? ''));
$packageId = (int)($_GET['package_id'] ?? 0);
$certStatus = strtoupper(trim((string)($_GET['cert_status'] ?? 'ALL')));
if (!in_array($certStatus, ['ALL', 'CERTIFIED', 'SOON', 'EXPIRED'], true)) {
  $certStatus = 'ALL';
}

$packages = $pdo->query("SELECT id, name FROM packages ORDER BY id DESC")->fetchAll();
$sessionEndExpr = sessions_column_exists($pdo, 'ended_at')
  ? "COALESCE(s.ended_at, s.submitted_at, s.started_at)"
  : "COALESCE(s.submitted_at, s.started_at)";

$where = [
  "s.session_type='EXAM'",
  "s.status='TERMINATED'",
  "s.passed=1",
];
$params = [];

if ($email !== '') {
  $where[] = "c.email LIKE ?";
  $params[] = '%' . $email . '%';
}
if ($packageId > 0) {
  $where[] = "s.package_id = ?";
  $params[] = $packageId;
}

$sql = "
  SELECT
    c.email,
    s.package_id,
    pk.name AS package_name,
    MAX($sessionEndExpr) AS last_cert_date
  FROM sessions s
  JOIN contacts c ON c.id = s.contact_id
  JOIN packages pk ON pk.id = s.package_id
  WHERE " . implode(' AND ', $where) . "
  GROUP BY c.email, s.package_id, pk.name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawRows = $stmt->fetchAll();

$stats = [
  'CERTIFIED' => 0,
  'SOON' => 0,
  'EXPIRED' => 0,
];
$rows = [];

foreach ($rawRows as $r) {
  $status = certification_status_from_last_success((string)$r['last_cert_date']);
  $statusKey = (string)$status['status_key'];
  $statusLabel = (string)$status['status_label'];
  $statusClass = (string)$status['status_class'];
  $expires = $status['expires_at'];

  if (isset($stats[$statusKey])) {
    $stats[$statusKey]++;
  }

  if ($certStatus !== 'ALL' && $certStatus !== $statusKey) {
    continue;
  }

  $rows[] = [
    'email' => (string)$r['email'],
    'package_name' => (string)$r['package_name'],
    'last_cert_date' => (string)$r['last_cert_date'],
    'expires_at' => $expires instanceof DateTimeImmutable ? $expires->format('Y-m-d') : '-',
    'status_key' => $statusKey,
    'status_label' => $statusLabel,
    'status_class' => $statusClass,
  ];
}

usort($rows, static function (array $a, array $b): int {
  return strcmp($a['expires_at'], $b['expires_at']);
});

if (isset($_GET['export']) && $_GET['export'] === '1') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="certifications_export.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['email', 'certification', 'last_cert_date', 'expires_at', 'status']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['email'],
      $r['package_name'],
      $r['last_cert_date'],
      $r['expires_at'],
      $r['status_label'],
    ]);
  }
  fclose($out);
  exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Certifications</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Certifications</h2>
          <p class="sub">Profils certifies EXAM et dates d'expiration</p>
        </div>
        <div class="admin-head-actions">
          <a class="btn ghost" href="/dashboard.php">Espace candidat</a>
          <a class="btn ghost" href="/admin/index.php">Sessions</a>
          <a class="btn ghost" href="/admin/questions.php">Questions</a>
          <a class="btn ghost admin-logout-btn" href="/logout.php">Deconnexion</a>
        </div>
      </div>

      <hr class="separator">

      <form method="get" class="filters-grid">
        <div>
          <label class="label" for="email">Email</label>
          <input class="input" id="email" name="email" type="text" value="<?= h($email) ?>" placeholder="Email...">
        </div>

        <div>
          <label class="label" for="package_id">Certification</label>
          <select id="package_id" name="package_id">
            <option value="0">Toutes</option>
            <?php foreach ($packages as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $packageId === (int)$p['id'] ? 'selected' : '' ?>>
                <?= h($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label" for="cert_status">Statut</label>
          <select id="cert_status" name="cert_status">
            <option value="ALL" <?= $certStatus === 'ALL' ? 'selected' : '' ?>>Tous</option>
            <option value="CERTIFIED" <?= $certStatus === 'CERTIFIED' ? 'selected' : '' ?>>Certifies</option>
            <option value="SOON" <?= $certStatus === 'SOON' ? 'selected' : '' ?>>Expire bientot</option>
            <option value="EXPIRED" <?= $certStatus === 'EXPIRED' ? 'selected' : '' ?>>Expires</option>
          </select>
        </div>

        <div class="filters-actions">
          <button class="btn" type="submit">Filtrer</button>
          <a class="btn ghost" href="/admin/certifications.php">Reset</a>
          <button class="btn ghost" type="submit" name="export" value="1">Exporter CSV</button>
        </div>
      </form>

      <div class="row sessions-stats">
        <span class="badge ok">Certifies: <?= (int)$stats['CERTIFIED'] ?></span>
        <span class="badge">Expire bientot: <?= (int)$stats['SOON'] ?></span>
        <span class="badge bad">Expires: <?= (int)$stats['EXPIRED'] ?></span>
      </div>

      <p class="sub sessions-meta"><?= count($rows) ?> resultat(s)</p>

      <div class="table-wrap">
        <?php if (!$rows): ?>
          <p class="empty-state">Aucune certification trouvee.</p>
        <?php else: ?>
          <table class="table questions-table">
            <thead>
              <tr>
                <th>Email</th>
                <th>Certification</th>
                <th>Derniere reussite</th>
                <th>Expire le</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td>
                    <a href="/admin/contact.php?email=<?= urlencode($r['email']) ?>">
                      <?= h($r['email']) ?>
                    </a>
                  </td>
                  <td><?= h($r['package_name']) ?></td>
                  <td><?= h($r['last_cert_date']) ?></td>
                  <td><?= h($r['expires_at']) ?></td>
                  <td><span class="<?= h($r['status_class']) ?>"><?= h($r['status_label']) ?></span></td>
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
