<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

$pdo = db();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');

  $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
  $stmt->execute([$email]);
  $user = $stmt->fetch();

  if ($user) {
    $user_id = (int)$user['id'];

    $token = bin2hex(random_bytes(32));
    $token_hash = password_hash($token, PASSWORD_DEFAULT);

    $expires = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

    $ins = $pdo->prepare("
      INSERT INTO password_resets(user_id, token_hash, expires_at)
      VALUES(?,?,?)
    ");
    $ins->execute([$user_id, $token_hash, $expires]);

    // ⚠️ En prod → envoyer email
    $reset_link = "http://localhost:8080/reset-password.php?token=$token";

    $message = "Lien de réinitialisation : <br><a href='$reset_link'>$reset_link</a>";
  } else {
    $message = "Si cet email existe, un lien a été envoyé.";
  }
}
?>

<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <link rel="stylesheet" href="/assets/style.css">
  <script src="/assets/theme-toggle.js?v=1" defer></script>
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Mot de passe oublié</h2>

    <?php if ($message): ?>
      <p><?= $message ?></p>
    <?php else: ?>
      <form method="post">
        <label class="label">Email</label>
        <input class="input" type="email" name="email" required>
        <br><br>
        <button class="btn">Envoyer</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
