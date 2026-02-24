<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';

$pdo = db();

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';
  $password2 = $_POST['password2'] ?? '';

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "Email invalide.";
  } elseif (strlen($password) < 8) {
    $error = "Mot de passe trop court (min 8 caractères).";
  } elseif ($password !== $password2) {
    $error = "Les mots de passe ne correspondent pas.";
  } else {
    // email déjà utilisé ?
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
      $error = "Un compte existe déjà avec cet email.";
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);

      $ins = $pdo->prepare("INSERT INTO users(email, password_hash, role) VALUES(?, ?, 'USER')");
      $ins->execute([$email, $hash]);

      $uid = (int)$pdo->lastInsertId();

      // login auto
      $_SESSION['user'] = [
        'id' => $uid,
        'email' => $email,
        'name' => null,
        'role' => 'USER',
      ];

      header("Location: /dashboard.php");
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Créer un compte</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1" defer></script>
</head>
<body>
<div class="container">
  <div class="card">
    <h2 class="h1">Créer un compte</h2>
    <p class="sub">Inscris-toi pour accéder à tes certifications.</p>

    <?php if ($error): ?>
      <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="post">
      <label class="label">Email</label>
      <input class="input" name="email" type="email" required value="<?= h($email) ?>">

      <div style="height:10px"></div>

      <label class="label">Mot de passe</label>
      <input class="input" name="password" type="password" required minlength="8">

      <div style="height:10px"></div>

      <label class="label">Confirmer le mot de passe</label>
      <input class="input" name="password2" type="password" required minlength="8">

      <div style="height:14px"></div>

      <button class="btn" type="submit">Créer mon compte</button>

      <p class="small" style="margin-top:10px;">
        Déjà un compte ? <a href="/login.php">Se connecter</a>
      </p>
    </form>
  </div>
</div>
</body>
</html>
