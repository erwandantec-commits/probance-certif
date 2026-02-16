<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
$pdo = db();

$sid = $_GET['sid'] ?? '';
if (!$sid) { http_response_code(400); echo "Missing sid"; exit; }

$stmt = $pdo->prepare("
  SELECT s.*, c.email, pk.name AS package_name, pk.pass_threshold_percent, pk.duration_limit_minutes
  FROM sessions s
  JOIN contacts c ON c.id = s.contact_id
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.id=?
");
$stmt->execute([$sid]);
$s = $stmt->fetch();
if (!$s) { http_response_code(404); echo "Not found"; exit; }

// Expiration serveur (robuste) : si ACTIVE et délai dépassé => EXPIRED
if ($s['status'] === 'ACTIVE') {
  $started = strtotime($s['started_at']);
  $limitMinutes = (int)$s['duration_limit_minutes'];
  $expiresAt = $started + ($limitMinutes * 60);

  if (time() > $expiresAt) {
    $u = $pdo->prepare("UPDATE sessions SET status='EXPIRED' WHERE id=?");
    $u->execute([$sid]);

    // recharger la session
    $stmt = $pdo->prepare("
      SELECT s.*, c.email, pk.name AS package_name, pk.pass_threshold_percent, pk.duration_limit_minutes
      FROM sessions s
      JOIN contacts c ON c.id = s.contact_id
      JOIN packages pk ON pk.id = s.package_id
      WHERE s.id=?
    ");
    $stmt->execute([$sid]);
    $s = $stmt->fetch();
  }
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Résultat</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>

<body>
  <div class="container">
    <div class="card">

      <div class="header">
        <div>
          <h2 class="h1">Résultat</h2>
          <p class="sub"><?= h($s['package_name']) ?> — <?= h($s['email']) ?></p>
        </div>

        <?php if ($s['status'] === 'TERMINATED'): ?>
          <?php if ((int)$s['passed'] === 1): ?>
            <span class="badge ok">✅ Réussi</span>
          <?php else: ?>
            <span class="badge bad">❌ Échec</span>
          <?php endif; ?>
        <?php elseif ($s['status'] === 'EXPIRED'): ?>
          <span class="badge bad">⏱ Expirée</span>
        <?php else: ?>
          <span class="badge">En cours</span>
        <?php endif; ?>
      </div>

      <div class="row" style="margin-bottom:12px;">
        <span class="badge">Statut: <?= h($s['status']) ?></span>
        <?php if (!empty($s['started_at'])): ?>
          <span class="badge">Début: <?= h($s['started_at']) ?></span>
        <?php endif; ?>
        <?php if (!empty($s['submitted_at'])): ?>
          <span class="badge">Fin: <?= h($s['submitted_at']) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($s['status'] === 'TERMINATED'): ?>
        <div class="card" style="box-shadow:none; border-radius:12px; border:1px solid var(--border);">
          <p style="margin:0; font-size:16px;">
            <b>Score :</b> <?= h($s['score_percent']) ?>%
            <span class="small">(seuil <?= (int)$s['pass_threshold_percent'] ?>%)</span>
          </p>
        </div>

        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <a class="btn" href="/start.php">Recommencer</a>
          <a class="btn ghost" href="/admin/index.php">Voir dans l’admin</a>
        </div>

      <?php elseif ($s['status'] === 'EXPIRED'): ?>
        <p class="error">⏱ La session a expiré (temps dépassé).</p>

        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <a class="btn" href="/start.php">Recommencer</a>
          <a class="btn ghost" href="/admin/index.php">Admin</a>
        </div>

      <?php else: ?>
        <p>Session en cours…</p>
        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <a class="btn" href="/exam.php?sid=<?= h($sid) ?>&p=1">Reprendre</a>
          <a class="btn ghost" href="/start.php">Retour</a>
        </div>
      <?php endif; ?>

    </div>

    <p class="small" style="margin-top:14px;">Démo interne — les réponses ne sont visibles que côté admin.</p>
  </div>
</body>
</html>
