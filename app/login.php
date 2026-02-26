<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

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
      'role' => $u['role'],
    ];
    header("Location: /dashboard.php?lang=" . urlencode($lang));
    exit;
  }
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <meta charset="utf-8">
  <title><?= h(t('login.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container">
  <div class="card">
    <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:8px;">
      <select id="login-lang" class="input lang-select"
              onchange="window.location.href='/login.php?lang=' + encodeURIComponent(this.value);">
        <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>><?= h(t('lang.fr', [], $lang)) ?></option>
        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>><?= h(t('lang.en', [], $lang)) ?></option>
        <option value="es" <?= $lang === 'es' ? 'selected' : '' ?>><?= h(t('lang.es', [], $lang)) ?></option>
        <option value="jp" <?= $lang === 'jp' ? 'selected' : '' ?>><?= h(t('lang.jp', [], $lang)) ?></option>
      </select>
    </div>

    <h2 class="h1"><?= h(t('login.title', [], $lang)) ?></h2>
    <p class="sub"><?= h(t('login.subtitle', [], $lang)) ?></p>

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
        <a href="/forgot-password.php"><?= h(t('login.forgot', [], $lang)) ?></a>
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

