<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';

$pdo = db();
$user = require_auth();
$err = $_GET['err'] ?? '';
$uid = (int)$user['id'];

// stats
$statsStmt = $pdo->prepare("
  SELECT
    COUNT(*) as total_attempts,
    SUM(CASE WHEN status='SUBMITTED' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status IN ('ACTIVE') THEN 1 ELSE 0 END) as in_progress
  FROM sessions
  WHERE user_id=?
");
$statsStmt->execute([$uid]);
$stats = $statsStmt->fetch() ?: ['total_attempts'=>0,'completed'=>0,'in_progress'=>0];

// certifs dispo
$pkgStmt = $pdo->query("SELECT id,name,duration_limit_minutes FROM packages ORDER BY id DESC");
$packages = $pkgStmt->fetchAll();

// derniers résultats (si tu as score dans sessions, adapte)
$lastStmt = $pdo->prepare("
  SELECT s.id, s.status, s.started_at, pk.name as package_name
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.user_id=?
  ORDER BY s.started_at DESC
  LIMIT 10
");
$lastStmt->execute([$uid]);
$last = $lastStmt->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Mon espace</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
</head>
<body>
<div class="container">
  <div class="header" style="margin-bottom:18px;">
    <div>
      <h2 class="h1">Salut <?= h($user['name'] ?: $user['email']) ?></h2>
      <p class="sub">Ton espace certifications</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <?php if (($user['role'] ?? 'USER') === 'ADMIN'): ?>
        <a class="btn ghost" href="/admin/">Admin</a>
      <?php endif; ?>
      <a class="btn ghost" href="/logout.php">Déconnexion</a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="card" style="border-color: rgba(220,38,38,.25); background: rgba(220,38,38,.05); margin-bottom:14px;">
      <p class="error" style="margin:0;"><?= h($err) ?></p>
    </div>
  <?php endif; ?>

  <div class="row">
    <div class="card" style="flex:1; min-width:220px;">
      <div class="sub">Tentatives</div>
      <div style="font-size:28px; font-weight:800;"><?= (int)$stats['total_attempts'] ?></div>
    </div>
    <div class="card" style="flex:1; min-width:220px;">
      <div class="sub">Terminées</div>
      <div style="font-size:28px; font-weight:800;"><?= (int)$stats['completed'] ?></div>
    </div>
    <div class="card" style="flex:1; min-width:220px;">
      <div class="sub">En cours</div>
      <div style="font-size:28px; font-weight:800;"><?= (int)$stats['in_progress'] ?></div>
    </div>
  </div>

  <div style="height:16px"></div>

  <div class="card">
    <h3 style="margin:0 0 10px 0;">Passer une certification</h3>

    <form method="post" action="/start.php" class="row" style="align-items:end;">
      <div style="flex:2; min-width:240px;">
        <label class="label">Certification</label>
        <select name="package_id" required>
          <?php foreach ($packages as $pk): ?>
            <option value="<?= (int)$pk['id'] ?>">
              <?= h($pk['name']) ?> (<?= (int)$pk['duration_limit_minutes'] ?> min)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="flex:1; min-width:200px;">
        <label class="label">Email</label>
        <input class="input" name="email" type="email" value="<?= h($user['email']) ?>" readonly>
      </div>

      <div>
        <button class="btn" type="submit">Démarrer</button>
      </div>
    </form>
  </div>

  <div style="height:16px"></div>

  <div class="card">
    <h3 style="margin:0 0 10px 0;">Dernières sessions</h3>

    <?php if (!$last): ?>
      <p class="small">Aucune session pour le moment.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Certification</th>
            <th>Statut</th>
            <th>Démarrée</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($last as $s): ?>
          <tr>
            <td><?= h($s['package_name']) ?></td>
            <td><?= h($s['status']) ?></td>
            <td><?= h($s['started_at']) ?></td>
            <td>
              <?php if ($s['status'] === 'ACTIVE'): ?>
                <a class="btn ghost" href="/exam.php?sid=<?= h($s['id']) ?>&p=1">Reprendre</a>
              <?php else: ?>
                <a class="btn ghost" href="/result.php?sid=<?= h($s['id']) ?>">Voir</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
