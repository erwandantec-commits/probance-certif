<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';

$pdo = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  $stmt = $pdo->prepare("SELECT id,email,password_hash,name,role FROM users WHERE email=?");
  $stmt->execute([$email]);
  $u = $stmt->fetch();

  if (!$u || !password_verify($password, $u['password_hash'])) {
    $error = "Email ou mot de passe incorrect.";
  } else {
    // stock minimal en session
    $_SESSION['user'] = [
      'id' => (int)$u['id'],
      'email' => $u['email'],
      'name' => $u['name'],
      'role' => $u['role'],
    ];
    header("Location: /dashboard.php");
    exit;
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Connexion</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
</head>
<body>
<div class="container">
  <div class="card">
    <h2 class="h1">Connexion</h2>
    <p class="sub">Accède à ton espace certifications.</p>

    <?php if ($error): ?>
      <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="post">
      <label class="label">Email</label>
      <input class="input" name="email" type="email" required>

      <div style="height:10px"></div>

      <label class="label">Mot de passe</label>
      <input class="input" name="password" type="password" required>

      <div style="height:14px"></div>

      <button class="btn" type="submit">Se connecter</button>

      <p class="small" style="margin-top:10px;">
        <a href="/forgot-password.php">Mot de passe oublié ?</a>
      </p>
      <p class="small" style="margin-top:8px;">
        Pas de compte ? <a href="/register.php">Créer un compte</a>
      </p>
    </form>
  </div>
</div>
</body>
</html>
