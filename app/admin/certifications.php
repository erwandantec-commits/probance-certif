<?php
require_once __DIR__ . '/_auth.php';
require_admin();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../services/session_service.php';
require_once __DIR__ . '/_nav.php';
$pdo = db();

$email = trim((string)($_GET['email'] ?? ''));
$packageId = (int)($_GET['package_id'] ?? 0);
$certStatus = strtoupper(trim((string)($_GET['cert_status'] ?? 'ALL')));
if (!in_array($certStatus, ['ALL', 'CERTIFIED', 'SOON', 'EXPIRED', 'REVOKED'], true)) {
  $certStatus = 'ALL';
}

$packages = $pdo->query("SELECT id, name, name_color_hex FROM packages ORDER BY id DESC")->fetchAll();
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
    c.id AS contact_id,
    c.email,
    s.package_id,
    pk.name AS package_name,
    pk.name_color_hex AS package_color_hex,
    MAX($sessionEndExpr) AS last_cert_date
  FROM sessions s
  JOIN contacts c ON c.id = s.contact_id
  JOIN packages pk ON pk.id = s.package_id
  WHERE " . implode(' AND ', $where) . "
  GROUP BY c.id, c.email, s.package_id, pk.name, pk.name_color_hex
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawRows = $stmt->fetchAll();

$latestSessionStmt = $pdo->prepare("
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

$stats = [
  'CERTIFIED' => 0,
  'SOON' => 0,
  'EXPIRED' => 0,
  'REVOKED' => 0,
];
$rows = [];

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

foreach ($rawRows as $r) {
  $contactId = (int)$r['contact_id'];
  $pkgId = (int)$r['package_id'];
  $key = $contactId . ':' . $pkgId;

  $status = certification_status_from_last_success((string)$r['last_cert_date']);
  $statusKey = (string)$status['status_key'];
  $statusLabel = (string)$status['status_label'];
  $statusClass = (string)$status['status_class'];
  $expires = $status['expires_at'];

  $isRevoked = false;
  if (isset($revokedMap[$key])) {
    $revokedAtRaw = trim((string)$revokedMap[$key]);
    $lastSuccessRaw = trim((string)$r['last_cert_date']);
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
    $statusKey = 'REVOKED';
    $statusLabel = 'Révoquée';
    $statusClass = 'pill danger';
    $expires = null;
  }

  if (isset($stats[$statusKey])) {
    $stats[$statusKey]++;
  }

  if ($certStatus !== 'ALL' && $certStatus !== $statusKey) {
    continue;
  }

  $rows[] = [
    'contact_id' => $contactId,
    'package_id' => $pkgId,
    'email' => (string)$r['email'],
    'package_name' => (string)$r['package_name'],
    'package_color_hex' => (string)($r['package_color_hex'] ?? ''),
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

foreach ($rows as &$row) {
  $latestSessionStmt->execute([(int)$row['contact_id'], (int)$row['package_id']]);
  $row['last_session_id'] = (string)($latestSessionStmt->fetchColumn() ?: '');
}
unset($row);

if (isset($_GET['export']) && $_GET['export'] === '1') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="certifications_export.csv"');

  $out = fopen('php://output', 'w');
  // UTF-8 BOM for Excel compatibility (prevents accent corruption like Revoquee).
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['email', 'Pack', 'last_cert_date', 'expires_at', 'status']);
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
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Certifications</h2>
          <p class="sub">Vue des Certifications et de leurs &eacute;ch&eacute;ances</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('certifications'); ?>
        </div>
      </div>

      <hr class="separator">

      <form method="get" class="filters-grid">
        <div>
          <label class="label" for="email">Email</label>
          <input class="input" id="email" name="email" type="text" value="<?= h($email) ?>" placeholder="Email...">
        </div>

        <div>
          <label class="label" for="package_id">Pack</label>
	          <select class="input" id="package_id" name="package_id" data-package-colors="off">
	            <option value="0" <?= $packageId === 0 ? 'selected' : '' ?>>Tous</option>
	            <?php foreach ($packages as $p): ?>
	              <option value="<?= (int)$p['id'] ?>" <?= $packageId === (int)$p['id'] ? 'selected' : '' ?>>
	                <?= h($p['name']) ?>
	              </option>
	            <?php endforeach; ?>
	          </select>
        </div>

        <div>
          <label class="label" for="cert_status">Statut</label>
          <select class="input" id="cert_status" name="cert_status">
            <option value="ALL" <?= $certStatus === 'ALL' ? 'selected' : '' ?>>Tous</option>
            <option value="CERTIFIED" <?= $certStatus === 'CERTIFIED' ? 'selected' : '' ?>>Certifi&eacute;s</option>
            <option value="SOON" <?= $certStatus === 'SOON' ? 'selected' : '' ?>>Expire bient&ocirc;t</option>
            <option value="EXPIRED" <?= $certStatus === 'EXPIRED' ? 'selected' : '' ?>>Expir&eacute;s</option>
            <option value="REVOKED" <?= $certStatus === 'REVOKED' ? 'selected' : '' ?>>R&eacute;voqu&eacute;es</option>
          </select>
        </div>

        <div class="filters-actions">
          <button class="btn" type="submit">Filtrer</button>
          <a class="btn ghost" href="/admin/certifications.php">Reset</a>
          <button class="btn ghost" type="submit" name="export" value="1">Exporter CSV</button>
        </div>
      </form>

      <div class="row sessions-stats">
        <span class="badge ok">Certifi&eacute;s: <?= (int)$stats['CERTIFIED'] ?></span>
        <span class="badge">Expire bient&ocirc;t: <?= (int)$stats['SOON'] ?></span>
        <span class="badge bad">Expir&eacute;s: <?= (int)$stats['EXPIRED'] ?></span>
        <span class="badge muted-dark">R&eacute;voqu&eacute;es: <?= (int)$stats['REVOKED'] ?></span>
      </div>

      <p class="sub sessions-meta"><?= count($rows) ?> r&eacute;sultat(s)</p>

      <div class="table-wrap">
        <?php if (!$rows): ?>
          <p class="empty-state">Aucune certification trouv&eacute;e.</p>
        <?php else: ?>
          <table class="table certifications-table">
            <colgroup>
              <col class="cert-col-email">
              <col class="cert-col-name">
              <col class="cert-col-last">
              <col class="cert-col-expire">
              <col class="cert-col-status">
              <col class="cert-col-action">
            </colgroup>
            <thead>
              <tr>
                <th>Email</th>
                <th>Pack</th>
                <th>Derni&egrave;re r&eacute;ussite</th>
                <th>Expire le</th>
                <th>Statut</th>
                <th>Action</th>
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
	                  <td><span style="<?= h(package_label_style((string)$r['package_name'], (string)($r['package_color_hex'] ?? ''))) ?>"><?= h($r['package_name']) ?></span></td>
                  <td><?= h($r['last_cert_date']) ?></td>
                  <td><?= h($r['expires_at']) ?></td>
                  <td><span class="<?= h($r['status_class']) ?>"><?= h($r['status_label']) ?></span></td>
                  <td class="actions-cell">
	                    <?php if ((string)($r['last_session_id'] ?? '') !== ''): ?>
	                      <a class="btn ghost icon-btn" href="/admin/session.php?sid=<?= urlencode((string)$r['last_session_id']) ?>" aria-label="Voir le detail" title="Voir le detail">
	                        <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
	                          <path d="M12 5c5.5 0 9.5 4.6 10.8 6.3a1.2 1.2 0 0 1 0 1.4C21.5 14.4 17.5 19 12 19S2.5 14.4 1.2 12.7a1.2 1.2 0 0 1 0-1.4C2.5 9.6 6.5 5 12 5zm0 2C8 7 4.9 10.3 3.3 12 4.9 13.7 8 17 12 17s7.1-3.3 8.7-5C19.1 10.3 16 7 12 7zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5z"/>
	                        </svg>
	                      </a>
	                    <?php endif; ?>
                    <?php if (!$hasRevocationsTable): ?>
                      <?php if ((string)($r['last_session_id'] ?? '') === ''): ?>-<?php endif; ?>
                    <?php elseif ($r['status_key'] === 'REVOKED'): ?>
                      <a class="btn ghost cert-action-restore" href="/admin/certification_revoke.php?action=undo&contact_id=<?= (int)$r['contact_id'] ?>&package_id=<?= (int)$r['package_id'] ?>"
                         onclick="return confirm('Retirer la r&eacute;vocation de cette certification ?');">R&eacute;tablir</a>
                    <?php else: ?>
                      <a class="btn ghost cert-action-revoke" href="/admin/certification_revoke.php?action=revoke&contact_id=<?= (int)$r['contact_id'] ?>&package_id=<?= (int)$r['package_id'] ?>"
                         onclick="return confirm('Revoquer cette certification ?');">Revoquer</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script src="/assets/package-colors.js"></script>
</body>
</html>
