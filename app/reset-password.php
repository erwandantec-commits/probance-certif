<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

$pdo = db();
$token = $_GET['token'] ?? '';
$error = '';
$valid_user_id = null;

if ($token) {
  $stmt = $pdo->query("SELECT * FROM password_resets WHERE expires_at > NOW()");
  foreach ($stmt as $row) {
    if (password_verify($token, $row['token_hash'])) {
      $valid_user_id = $row['user_id'];
      break;
    }
  }
}

if (!$valid_user_id) {
  die("Lien invalide ou expiré.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $password = $_POST['password'] ?? '';

  if (strlen($password) < 6) {
    $error = "Mot de passe trop court.";
  } else {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $upd = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $upd->execute([$hash, $valid_user_id]);

    $pdo->prepare("DELETE FROM password_resets WHERE user_id=?")
        ->execute([$valid_user_id]);

    header("Location: /login.php");
    exit;
  }
}
?>

<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <link rel="stylesheet" href="/assets/style.css">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Nouveau mot de passe</h2>

    <?php if ($error): ?>
      <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="post">
      <label class="label">Nouveau mot de passe</label>
      <input class="input" type="password" name="password" required>
      <br><br>
      <button class="btn">Réinitialiser</button>
    </form>
  </div>
</div>
</body>
</html>
