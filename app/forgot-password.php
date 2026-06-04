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
    $userId = (int)$user['id'];
    $token = bin2hex(random_bytes(32));
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    $expires = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

    $ins = $pdo->prepare("
      INSERT INTO password_resets(user_id, token_hash, expires_at)
      VALUES(?,?,?)
    ");
    $ins->execute([$userId, $tokenHash, $expires]);

    $resetLink = app_build_url('/reset-password.php?token=' . urlencode($token));
    $subject = 'Reinitialisation de votre mot de passe';
    $body = <<<HTML
<p>Bonjour,</p>
<p>Une demande de reinitialisation de mot de passe a ete enregistree pour votre compte.</p>
<p><a href="{$resetLink}">Reinitialiser mon mot de passe</a></p>
<p>Si vous n'etes pas a l'origine de cette demande, vous pouvez ignorer cet email.</p>
HTML;

    if (!smtp_send_mail($email, $subject, $body)) {
      error_log('[forgot-password] Unable to send reset email to ' . $email);
    }
  }

  $message = "Si cet email existe, un lien a ete envoye.";
}
?>

<!doctype html>
<html>
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <link rel="stylesheet" href="/assets/style.css">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Mot de passe oublie</h2>

    <?php if ($message): ?>
      <p><?= h($message) ?></p>
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
