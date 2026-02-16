<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pwd = $_POST['password'] ?? '';
  if (hash_equals(ADMIN_PASSWORD, $pwd)) {
    $_SESSION['is_admin'] = true;
    header("Location: /admin/index.php");
    exit;
  } else {
    $error = "Mot de passe incorrect";
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin Login</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>

<body>
  <div class="container">
    <div class="card">

      <div class="header">
        <div>
          <h2 class="h1">Admin — Connexion</h2>
          <p class="sub">Accès réservé Probance</p>
        </div>
        <a class="btn ghost" href="/start.php">← Retour</a>
      </div>

      <?php if (!empty($error)): ?>
        <p class="error"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post">
        <label class="label">Mot de passe</label>
        <input class="input" type="password" name="password" required autofocus>

        <br><br>

        <button class="btn">Se connecter</button>
      </form>

    </div>
  </div>
</body>
</html>
