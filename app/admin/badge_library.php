<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/../utils.php';

function badge_library_return_path(string $raw): string {
  $raw = trim($raw);
  if ($raw === '' || $raw[0] !== '/') {
    return '/admin/packages.php';
  }
  if (!str_starts_with($raw, '/admin/')) {
    return '/admin/packages.php';
  }
  return $raw;
}

function badge_library_options(): array {
  $baseDir = realpath(__DIR__ . '/../assets/badges');
  if ($baseDir === false) {
    return [];
  }
  $files = glob($baseDir . DIRECTORY_SEPARATOR . '*.{png,jpg,jpeg,webp,gif}', GLOB_BRACE) ?: [];
  $out = [];
  foreach ($files as $path) {
    $name = basename((string)$path);
    if ($name === '') {
      continue;
    }
    $out[] = $name;
  }
  natcasesort($out);
  return array_values(array_unique($out));
}

function badge_library_append_query(string $url, array $params): string {
  $join = (str_contains($url, '?')) ? '&' : '?';
  return $url . $join . http_build_query($params);
}

$returnPath = badge_library_return_path((string)($_GET['return'] ?? '/admin/packages.php'));
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $returnPath = badge_library_return_path((string)($_POST['return'] ?? $returnPath));

  if (isset($_POST['pick_filename'])) {
    $picked = trim((string)($_POST['pick_filename'] ?? ''));
    $options = badge_library_options();
    if ($picked !== '' && in_array($picked, $options, true)) {
      header('Location: ' . badge_library_append_query($returnPath, ['badge_selected' => $picked]));
      exit;
    }
    $error = 'Image selectionnee invalide.';
  } elseif (isset($_POST['upload_badge'])) {
    if (!isset($_FILES['badge_file']) || !is_array($_FILES['badge_file'])) {
      $error = 'Aucun fichier recu.';
    } else {
      $file = $_FILES['badge_file'];
      $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($uploadError !== UPLOAD_ERR_OK) {
        $error = 'Echec du televersement.';
      } else {
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $origName = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowedExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        if (!in_array($ext, $allowedExt, true)) {
          $error = 'Extension non autorisee.';
        } elseif ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
          $error = 'Fichier upload invalide.';
        } else {
          $imgInfo = @getimagesize($tmpPath);
          if ($imgInfo === false) {
            $error = 'Le fichier n est pas une image valide.';
          } else {
            $baseDir = realpath(__DIR__ . '/../assets/badges');
            if ($baseDir === false) {
              $error = 'Dossier badges introuvable.';
            } else {
              try {
                $rand = bin2hex(random_bytes(4));
              } catch (Throwable $e) {
                $rand = dechex(mt_rand(100000, 99999999));
              }
              $safeBase = 'custom-badge-' . date('Ymd-His') . '-' . $rand;
              $newName = $safeBase . '.' . $ext;
              $destPath = $baseDir . DIRECTORY_SEPARATOR . $newName;
              if (!@move_uploaded_file($tmpPath, $destPath)) {
                $error = 'Impossible d enregistrer le fichier.';
              } else {
                header('Location: ' . badge_library_append_query($returnPath, ['badge_selected' => $newName]));
                exit;
              }
            }
          }
        }
      }
    }
  }
}

$options = badge_library_options();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Bibliotheque badges</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Bibliotheque badges</h2>
          <p class="sub">Choisir une image existante ou televerser une nouvelle image.</p>
        </div>
      </div>

      <hr class="separator">

      <?php if ($error !== ''): ?>
        <p class="error"><?= h($error) ?></p>
      <?php endif; ?>
      <?php if ($notice !== ''): ?>
        <p class="small" style="color:var(--ok); font-weight:700;"><?= h($notice) ?></p>
      <?php endif; ?>

      <div class="badge-library-grid">
        <?php if (!$options): ?>
          <p class="small">Aucune image disponible pour le moment.</p>
        <?php else: ?>
          <?php foreach ($options as $fileName): ?>
            <form method="post" class="badge-library-item">
              <input type="hidden" name="return" value="<?= htmlspecialchars((string)$returnPath, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="pick_filename" value="<?= htmlspecialchars((string)$fileName, ENT_QUOTES, 'UTF-8') ?>">
              <button type="submit" class="badge-library-pick-btn">
                <img src="/assets/badges/<?= h(rawurlencode($fileName)) ?>" alt="<?= h($fileName) ?>" loading="lazy">
                <span><?= h($fileName) ?></span>
              </button>
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <hr class="separator">

      <h3 class="distribution-title">Televerser une nouvelle image</h3>
      <form method="post" enctype="multipart/form-data" class="users-create-form">
        <input type="hidden" name="return" value="<?= htmlspecialchars((string)$returnPath, ENT_QUOTES, 'UTF-8') ?>">
        <div class="users-create-grid">
          <div class="badge-upload-field">
            <label class="label" for="badge-file">Image (png, jpg, jpeg, webp, gif)</label>
            <input id="badge-file" class="input" type="file" name="badge_file" accept=".png,.jpg,.jpeg,.webp,.gif" required>
          </div>
        </div>
        <div class="users-create-actions">
          <button class="btn" type="submit" name="upload_badge" value="1">Televerser et utiliser</button>
          <a class="btn ghost" href="<?= htmlspecialchars((string)$returnPath, ENT_QUOTES, 'UTF-8') ?>">Retour</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
