<?php
require_once __DIR__ . '/_auth.php';
require_admin();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

$packages = $pdo->query("SELECT * FROM packages ORDER BY id")->fetchAll();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin — Packages</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="container">
    <div class="card">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
      <div>
        <h2 class="h1" style="margin:0;">Admin — Packages</h2>
        <p class="sub" style="margin:6px 0 0;">Paramètres de certification</p>
      </div>
      <div style="display:flex; gap:10px;">
        <a class="btn ghost" href="/admin/index.php">← Retour</a>
        <a class="btn ghost" href="/logout.php">Déconnexion</a>
      </div>
    </div>
      <table class="table">
        <tr>
          <th>Nom</th>
          <th>Seuil (%)</th>
          <th>Durée (min)</th>
          <th>#Questions</th>
          <th></th>
        </tr>

        <?php foreach ($packages as $pk): ?>
          <tr>
            <td><?= h($pk['name']) ?></td>
            <td><?= (int)$pk['pass_threshold_percent'] ?></td>
            <td><?= (int)$pk['duration_limit_minutes'] ?></td>
            <td><?= (int)$pk['selection_count'] ?></td>
            <td>
              <a class="btn ghost" href="/admin/package_edit.php?id=<?= (int)$pk['id'] ?>">
                Modifier
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>

    </div>
  </div>
</body>
</html>
