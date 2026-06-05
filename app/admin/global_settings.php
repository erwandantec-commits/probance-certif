<?php
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin();
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
if (empty($_SESSION['admin_global_settings_csrf']) || !is_string($_SESSION['admin_global_settings_csrf'])) {
  $_SESSION['admin_global_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['admin_global_settings_csrf'];
$saved = ((string)($_GET['saved'] ?? '') === '1');
$error = trim((string)($_GET['error'] ?? ''));
$programRows = auth_accessible_programs($pdo, $adminUser);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedToken = (string)($_POST['csrf_token'] ?? '');
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    header('Location: /admin/global_settings.php?error=' . urlencode('Token de securite invalide.'));
    exit;
  }

  $emailControlEnabled = ((string)($_POST['user_email_control_enabled'] ?? '0') === '1') ? '1' : '0';
  $selectedProgramIds = array_map(static fn(int $id): string => (string)$id, auth_parse_id_list(implode(',', (array)($_POST['user_email_control_program_ids'] ?? []))));
  $allowedDomains = implode("\n", auth_parse_domain_list((string)($_POST['user_email_control_allowed_domains'] ?? '')));
  auth_set_global_setting($pdo, 'user_email_control_enabled', $emailControlEnabled, (int)($adminUser['id'] ?? 0));
  auth_set_global_setting($pdo, 'user_email_control_program_ids', implode(',', $selectedProgramIds), (int)($adminUser['id'] ?? 0));
  auth_set_global_setting($pdo, 'user_email_control_allowed_domains', $allowedDomains, (int)($adminUser['id'] ?? 0));
  header('Location: /admin/global_settings.php?saved=1');
  exit;
}

$emailControlEnabled = auth_user_email_control_enabled($pdo);
$emailControlProgramIds = auth_user_email_control_program_ids($pdo);
$emailControlAllowedDomains = implode("\n", auth_user_email_control_allowed_domains($pdo));
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('admin.settings.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card admin-page-shell">
      <div class="admin-head admin-page-hero">
        <div class="admin-head-copy">
          <p class="admin-page-eyebrow"><?= h(t('admin.nav.group_admin', [], $lang)) ?></p>
          <h2 class="h1"><?= h(t('admin.settings.title', [], $lang)) ?></h2>
          <p class="sub"><?= h(t('admin.settings.subtitle', [], $lang)) ?></p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('global_settings'); ?>
        </div>
      </div>

      <?php if ($saved): ?>
        <div class="admin-notice is-ok"><?= h(t('admin.settings.saved', [], $lang)) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="admin-notice is-bad"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="admin-page-layout">
        <section class="admin-section-panel">
          <div class="section-head admin-section-head">
            <div>
              <h3 class="h1">Controle des emails utilisateurs</h3>
              <p class="sub">Verifie que l'email d'un compte utilisateur respecte les domaines autorises.</p>
            </div>
          </div>

          <form method="post" class="users-create-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="admin-global-setting-row">
              <label class="admin-inline-checkbox" for="user-email-control-enabled">
                <input
                  id="user-email-control-enabled"
                  type="checkbox"
                  name="user_email_control_enabled"
                  value="1"
                  <?= $emailControlEnabled ? 'checked' : '' ?>
                >
                <span>Activer la verification des emails utilisateurs</span>
              </label>
              <span class="pill <?= $emailControlEnabled ? 'success' : 'warning' ?>">
                <?= $emailControlEnabled ? 'Actif' : 'Inactif' ?>
              </span>
            </div>
            <p class="sub" style="margin-top:12px;">
              Quand ce controle est actif, un email doit utiliser l'un des domaines autorises definis ci-dessous.
              Si aucun programme n'est selectionne ci-dessous, le controle s'applique a tous les programmes.
              Si aucun domaine n'est renseigne, aucune restriction de domaine n'est appliquee.
              Un administrateur peut definir une exception sur un compte utilisateur.
            </p>
            <div class="admin-global-setting-stack" style="margin-top:16px;">
              <div>
                <span class="label">Programmes concernes</span>
                <div class="users-checkbox-list" style="margin-top:8px;">
                  <?php if ($programRows): ?>
                    <?php foreach ($programRows as $programRow): ?>
                      <?php $programId = (int)($programRow['id'] ?? 0); ?>
                      <label class="users-checkbox-item">
                        <input
                          type="checkbox"
                          name="user_email_control_program_ids[]"
                          value="<?= $programId ?>"
                          <?= in_array($programId, $emailControlProgramIds, true) ? 'checked' : '' ?>
                        >
                        <span><?= h((string)($programRow['name'] ?? 'Programme')) ?></span>
                      </label>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <p class="sub" style="margin:0;">Aucun programme disponible.</p>
                  <?php endif; ?>
                </div>
              </div>
              <div>
                <label class="label" for="user-email-control-allowed-domains">Domaines autorises</label>
                <textarea
                  class="input"
                  id="user-email-control-allowed-domains"
                  name="user_email_control_allowed_domains"
                  rows="6"
                  placeholder="exemple.fr&#10;filiale.exemple.fr"
                ><?= h($emailControlAllowedDomains) ?></textarea>
                <p class="sub" style="margin-top:8px;">
                  Un domaine par ligne. Exemple : `entreprise.fr`.
                </p>
              </div>
            </div>
            <div class="users-create-actions">
              <button class="btn" type="submit"><?= h(t('admin.common.save', [], $lang)) ?></button>
            </div>
          </form>
        </section>
      </div>
    </div>
  </div>
</body>
</html>
