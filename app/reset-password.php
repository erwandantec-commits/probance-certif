<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/i18n.php';

header('Content-Type: text/html; charset=UTF-8');

$pdo = db();
$lang = get_lang();
$token = $_GET['token'] ?? '';
$error = '';
$valid_user_id = null;

if ($token !== '') {
  $tokenHash = hash('sha256', $token);
  $stmt = $pdo->prepare("SELECT user_id FROM password_resets WHERE token_hash = ? AND expires_at > NOW() LIMIT 1");
  $stmt->execute([$tokenHash]);
  $row = $stmt->fetch();
  if ($row) {
    $valid_user_id = $row['user_id'];
  }
}

if (!$valid_user_id) {
  render_error_page(400, t('reset.invalid_title', [], $lang), t('reset.invalid_message', [], $lang), '/forgot-password.php?lang=' . urlencode($lang));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $lang = get_lang();
  $password = $_POST['password'] ?? '';

  if (strlen($password) < 6) {
    $error = t('reset.error_short', [], $lang);
  } else {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $upd = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $upd->execute([$hash, $valid_user_id]);

    $pdo->prepare("DELETE FROM password_resets WHERE user_id=?")
        ->execute([$valid_user_id]);

    header("Location: /login.php?lang=" . urlencode($lang));
    exit;
  }
}
?>

<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('reset.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container">
  <div class="card login-card">
    <div class="login-topbar">
      <?php render_flag_lang_picker($lang, "'/reset-password.php?token=" . h(urlencode($token)) . "&lang={lang}'"); ?>
    </div>

    <div class="login-brand">
      <img class="dashboard-candidate-logo login-brand-logo" src="/assets/logo-candidat.svg" alt="Logo candidat">
      <div class="login-brand-text">
        <h2 class="h1"><?= h(t('reset.title', [], $lang)) ?></h2>
        <p class="sub"><?= h(t('reset.subtitle', [], $lang)) ?></p>
      </div>
    </div>

    <?php if ($error): ?>
      <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <label class="label"><?= h(t('reset.password_label', [], $lang)) ?></label>
      <input class="input" type="password" name="password" required>
      <br><br>
      <button class="btn"><?= h(t('reset.submit', [], $lang)) ?></button>
    </form>
  </div>
</div>
</body>
</html>
