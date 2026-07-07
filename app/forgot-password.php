<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/i18n.php';

header('Content-Type: text/html; charset=UTF-8');

$pdo = db();
$lang = get_lang();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $lang = get_lang();
  $email = trim($_POST['email'] ?? '');

  // Rate limit: max 3 requests per email per hour
  $rateSt = $pdo->prepare("
    SELECT COUNT(*) FROM password_resets pr
    JOIN users u ON u.id = pr.user_id
    WHERE u.email = ? AND pr.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
  ");
  $rateSt->execute([$email]);
  $recentCount = (int)$rateSt->fetchColumn();

  $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
  $stmt->execute([$email]);
  $user = $stmt->fetch();

  if ($user && $recentCount < 3) {

    $userId = (int)$user['id'];
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

    $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);

    $ins = $pdo->prepare("
      INSERT INTO password_resets(user_id, token_hash, expires_at)
      VALUES(?,?,?)
    ");
    $ins->execute([$userId, $tokenHash, $expires]);

    $resetLink = app_build_url('/reset-password.php?token=' . urlencode($token) . '&lang=' . urlencode($lang));
    $subject = t('forgot.email_subject', [], $lang);
    $signature = t('forgot.email_signature', ['name' => SMTP_FROM_NAME], $lang);
    $body = '<p>' . h(t('forgot.email_greeting', [], $lang)) . '</p>'
      . '<p>' . h(t('forgot.email_intro', [], $lang)) . '</p>'
      . '<p><a href="' . h($resetLink) . '">' . h(t('forgot.email_cta', [], $lang)) . '</a></p>'
      . '<p>' . h(t('forgot.email_ignore', [], $lang)) . '</p>'
      . '<p>' . h(t('forgot.email_closing', [], $lang)) . '<br>' . h($signature) . '</p>';

    if (!smtp_send_mail($email, $subject, $body)) {
      error_log('[forgot-password] Unable to send reset email to ' . $email);
    }
  }

  $message = t('forgot.sent_message', [], $lang);
}
?>

<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('forgot.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container">
  <div class="card login-card">
    <div class="login-topbar">
      <?php render_flag_lang_picker($lang, "'/forgot-password.php?lang={lang}'"); ?>
    </div>

    <div class="login-brand">
      <img class="dashboard-candidate-logo login-brand-logo" src="/assets/logo-candidat.svg" alt="Logo candidat">
      <div class="login-brand-text">
        <h2 class="h1"><?= h(t('forgot.title', [], $lang)) ?></h2>
        <p class="sub"><?= h(t('forgot.subtitle', [], $lang)) ?></p>
      </div>
    </div>

    <?php if ($message): ?>
      <p><?= h($message) ?></p>
      <p class="small" style="margin-top:10px;">
        <a href="/login.php?lang=<?= h(urlencode($lang)) ?>"><?= h(t('forgot.back_to_login', [], $lang)) ?></a>
      </p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="lang" value="<?= h($lang) ?>">
        <label class="label"><?= h(t('forgot.email', [], $lang)) ?></label>
        <input class="input" type="email" name="email" required>
        <br><br>
        <button class="btn"><?= h(t('forgot.submit', [], $lang)) ?></button>
      </form>
      <p class="small" style="margin-top:10px;">
        <a href="/login.php?lang=<?= h(urlencode($lang)) ?>"><?= h(t('forgot.back_to_login', [], $lang)) ?></a>
      </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
