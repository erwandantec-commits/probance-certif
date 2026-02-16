<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=?");
$stmt->execute([$id]);
$pk = $stmt->fetch();

if (!$pk) {
  http_response_code(404);
  echo "Not found";
  exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $threshold = (int)($_POST['pass_threshold_percent'] ?? 80);
  $duration = (int)($_POST['duration_limit_minutes'] ?? 120);
  $count = (int)($_POST['selection_count'] ?? 5);

  if ($threshold < 0 || $threshold > 100) {
    $error = "Seuil invalide";
  } elseif ($duration < 1 || $duration > 600) {
    $error = "Duree invalide";
  } elseif ($count < 1 || $count > 200) {
    $error = "Nombre de questions invalide";
  } else {
    $update = $pdo->prepare("
      UPDATE packages
      SET pass_threshold_percent=?,
          duration_limit_minutes=?,
          selection_count=?
      WHERE id=?
    ");

    $update->execute([$threshold, $duration, $count, $id]);
    header("Location: /admin/packages.php");
    exit;
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Modifier package</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Modifier package</h2>
          <p class="sub"><?= h($pk['name']) ?></p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('packages'); ?>
        </div>
      </div>

      <hr class="separator">

      <?php if ($error): ?>
        <p class="error"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post">
        <label class="label">Seuil de reussite (%)</label>
        <input
          class="input"
          type="number"
          name="pass_threshold_percent"
          min="0"
          max="100"
          value="<?= (int)$pk['pass_threshold_percent'] ?>"
          required
        >

        <br><br>

        <label class="label">Duree max (minutes)</label>
        <input
          class="input"
          type="number"
          name="duration_limit_minutes"
          min="1"
          max="600"
          value="<?= (int)$pk['duration_limit_minutes'] ?>"
          required
        >

        <br><br>

        <label class="label">Nombre de questions tirees</label>
        <input
          class="input"
          type="number"
          name="selection_count"
          min="1"
          max="200"
          value="<?= (int)$pk['selection_count'] ?>"
          required
        >

        <br><br>

        <div style="margin-top:14px; display:flex; gap:10px;">
          <button class="btn" type="submit">Enregistrer</button>
          <a class="btn ghost" href="/admin/packages.php">Annuler</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
