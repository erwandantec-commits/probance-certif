<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

header('Content-Type: text/html; charset=UTF-8');

$pdo = db();
$lang = get_lang();
$errorKey = '';
$alreadyLogged = current_user();
if ($alreadyLogged) {
  header("Location: /dashboard.php?lang=" . urlencode($lang));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $lang = get_lang();
  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  $stmt = $pdo->prepare("SELECT id,email,password_hash,name,role FROM users WHERE email=?");
  $stmt->execute([$email]);
  $u = $stmt->fetch();

  if (!$u || !password_verify($password, $u['password_hash'])) {
    $errorKey = 'login.bad_credentials';
  } else {
    $_SESSION['user'] = [
      'id' => (int)$u['id'],
      'email' => $u['email'],
      'name' => $u['name'],
      'role' => normalize_user_role((string)($u['role'] ?? 'USER')),
    ];
    header("Location: /dashboard.php?lang=" . urlencode($lang));
    exit;
  }
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('login.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container">
  <div class="card login-card">
    <div class="login-topbar">
      <?php render_flag_lang_picker($lang, "'/login.php?lang={lang}'"); ?>
    </div>

    <div class="login-brand">
      <img class="dashboard-candidate-logo login-brand-logo" src="/assets/logo-candidat.svg" alt="Logo candidat">
      <div class="login-brand-text">
        <h2 class="h1"><?= h(t('login.title', [], $lang)) ?></h2>
        <p class="sub"><?= h(t('login.subtitle', [], $lang)) ?></p>
      </div>
    </div>

    <?php if ($errorKey): ?>
      <p class="error"><?= h(t($errorKey, [], $lang)) ?></p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">

      <label class="label"><?= h(t('login.email', [], $lang)) ?></label>
      <input class="input" name="email" type="email" required>

      <div style="height:10px"></div>

      <label class="label"><?= h(t('login.password', [], $lang)) ?></label>
      <input class="input" name="password" type="password" required>

      <div style="height:14px"></div>

      <button class="btn" type="submit"><?= h(t('login.submit', [], $lang)) ?></button>

      <p class="small" style="margin-top:10px;">
        <a href="/forgot-password.php?lang=<?= h(urlencode($lang)) ?>"><?= h(t('login.forgot', [], $lang)) ?></a>
      </p>
      <p class="small" style="margin-top:8px;">
        <?= h(t('login.no_account', [], $lang)) ?>
        <a href="/register.php"><?= h(t('login.create_account', [], $lang)) ?></a>
      </p>
    </form>
  </div>
</div>
</body>
</html>

