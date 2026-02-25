<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

$error = '';
$name = '';
$threshold = 80;
$duration = 120;
$count = 10;
$isActive = 1;
$nameColorHex = '#334155';

$hasNameColorColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'name_color_hex'
")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim((string)($_POST['name'] ?? ''));
  $threshold = (int)($_POST['pass_threshold_percent'] ?? 80);
  $duration = (int)($_POST['duration_limit_minutes'] ?? 120);
  $count = (int)($_POST['selection_count'] ?? 10);
  $isActive = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;
  $postedColor = trim((string)($_POST['name_color_hex'] ?? ''));
  $normalizedColor = normalize_hex_color($postedColor);
  if ($normalizedColor !== null) {
    $nameColorHex = $normalizedColor;
  }

  $nameLen = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
  if ($name === '') {
    $error = 'Le nom du pack est obligatoire.';
  } elseif ($nameLen > 255) {
    $error = 'Nom de pack trop long (max 255 caracteres).';
  } elseif ($threshold < 0 || $threshold > 100) {
    $error = 'Seuil invalide (0 a 100).';
  } elseif ($duration < 1 || $duration > 600) {
    $error = 'Duree invalide (1 a 600 minutes).';
  } elseif ($count < 1 || $count > 200) {
    $error = 'Nombre de questions invalide (1 a 200).';
  } elseif ($hasNameColorColumn && $normalizedColor === null) {
    $error = 'Couleur invalide.';
  } else {
    $existsStmt = $pdo->prepare("SELECT id FROM packages WHERE UPPER(name)=UPPER(?) LIMIT 1");
    $existsStmt->execute([$name]);
    if ($existsStmt->fetch()) {
      $error = 'Un pack avec ce nom existe deja.';
    } else {
      if ($hasNameColorColumn) {
        $ins = $pdo->prepare("
          INSERT INTO packages(name, name_color_hex, pass_threshold_percent, duration_limit_minutes, selection_count, is_active)
          VALUES(?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$name, $nameColorHex, $threshold, $duration, $count, $isActive]);
      } else {
        $ins = $pdo->prepare("
          INSERT INTO packages(name, pass_threshold_percent, duration_limit_minutes, selection_count, is_active)
          VALUES(?, ?, ?, ?, ?)
        ");
        $ins->execute([$name, $threshold, $duration, $count, $isActive]);
      }
      header('Location: /admin/packages.php?created=1');
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Creer un pack</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1" defer></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Creer un pack</h2>
          <p class="sub">Ajout d'un nouveau pack de certification.</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('packages'); ?>
        </div>
      </div>

      <hr class="separator">

      <?php if ($error !== ''): ?>
        <p class="error" style="margin:0 0 10px;"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post" class="users-create-form">
        <div class="users-create-grid">
          <div>
            <label class="label" for="create-pack-name">Nom du pack</label>
            <input class="input" id="create-pack-name" name="name" type="text" maxlength="255" required value="<?= h($name) ?>">
          </div>
          <div>
            <label class="label" for="create-pack-threshold">Seuil (%)</label>
            <input class="input" id="create-pack-threshold" name="pass_threshold_percent" type="number" min="0" max="100" required value="<?= (int)$threshold ?>">
          </div>
          <div>
            <label class="label" for="create-pack-duration">Duree (minutes)</label>
            <input class="input" id="create-pack-duration" name="duration_limit_minutes" type="number" min="1" max="600" required value="<?= (int)$duration ?>">
          </div>
          <div>
            <label class="label" for="create-pack-count">Questions</label>
            <input class="input" id="create-pack-count" name="selection_count" type="number" min="1" max="200" required value="<?= (int)$count ?>">
          </div>
          <?php if ($hasNameColorColumn): ?>
            <div>
              <label class="label" for="create-pack-color">Couleur du nom</label>
              <input class="input" id="create-pack-color" name="name_color_hex" type="color" value="<?= h($nameColorHex) ?>">
            </div>
          <?php endif; ?>
          <div>
            <label class="label" for="create-pack-active">Statut</label>
            <select class="input" id="create-pack-active" name="is_active">
              <option value="1" <?= $isActive === 1 ? 'selected' : '' ?>>Actif</option>
              <option value="0" <?= $isActive === 0 ? 'selected' : '' ?>>Inactif</option>
            </select>
          </div>
        </div>
        <div class="users-create-actions">
          <button class="btn" type="submit">Creer le pack</button>
          <a class="btn ghost" href="/admin/packages.php">Annuler</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
