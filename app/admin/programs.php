<?php
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin();
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
ensure_program_source_language_schema($pdo);
$created = ((string)($_GET['created'] ?? '') === '1');
$updated = ((string)($_GET['updated'] ?? '') === '1');
$deleted = ((string)($_GET['deleted'] ?? '') === '1');
$toggled = ((string)($_GET['toggled'] ?? '') === '1');
$error = trim((string)($_GET['error'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'create_program') {
    if (!user_can_manage_program_catalog($adminUser)) {
      header('Location: /admin/programs.php?error=' . urlencode('Action reservee aux admins.'));
      exit;
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $sourceLang = question_translation_normalize_lang((string)($_POST['source_lang'] ?? 'fr'));

    if ($name === '') {
      header('Location: /admin/programs.php?error=' . urlencode('Nom de programme obligatoire.'));
      exit;
    }

    $slugBase = auth_slugify_label($name);
    if ($slugBase === '') {
      $slugBase = 'programme';
    }

    $slug = $slugBase;
    $suffix = 2;
    while (true) {
      $check = $pdo->prepare("SELECT id FROM programs WHERE slug = ? LIMIT 1");
      $check->execute([$slug]);
      if (!$check->fetch()) {
        break;
      }
      $slug = $slugBase . '-' . $suffix++;
    }

    $insert = $pdo->prepare("
      INSERT INTO programs(name, slug, description, source_lang, is_default, is_active, display_order)
      VALUES(?, ?, ?, ?, ?, 1, ?)
    ");
    $nextOrder = (int)($pdo->query("SELECT COALESCE(MAX(display_order), 0) + 10 FROM programs")->fetchColumn() ?: 10);
    $insert->execute([$name, $slug, $description !== '' ? $description : null, $sourceLang, 0, $nextOrder]);

    header('Location: /admin/programs.php?created=1');
    exit;
  }

  if ($action === 'toggle_program') {
    if (!user_can_manage_program_catalog($adminUser)) {
      header('Location: /admin/programs.php?error=' . urlencode('Action reservee aux admins.'));
      exit;
    }
    $programId = (int)($_POST['id'] ?? 0);
    if ($programId <= 0) {
      header('Location: /admin/programs.php?error=' . urlencode('Programme invalide.'));
      exit;
    }
    $toggle = $pdo->prepare("
      UPDATE programs
      SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
      WHERE id = ?
    ");
    $toggle->execute([$programId]);
    header('Location: /admin/programs.php?toggled=1');
    exit;
  }

  if ($action === 'delete_program') {
    if (!user_can_manage_program_catalog($adminUser)) {
      header('Location: /admin/programs.php?error=' . urlencode('Action reservee aux admins.'));
      exit;
    }
    $programId = (int)($_POST['id'] ?? 0);
    if ($programId <= 0) {
      header('Location: /admin/programs.php?error=' . urlencode('Programme invalide.'));
      exit;
    }

    $packageCountJoin = auth_table_exists($pdo, 'program_package_links')
      ? "LEFT JOIN program_package_links ppl ON ppl.program_id = p.id"
      : "LEFT JOIN packages pk ON pk.program_id = p.id";
    $packageCountExpr = auth_table_exists($pdo, 'program_package_links')
      ? "COUNT(DISTINCT ppl.package_id)"
      : "COUNT(pk.id)";
    $check = $pdo->prepare("
      SELECT
        p.id,
        $packageCountExpr AS package_count
      FROM programs p
      $packageCountJoin
      WHERE p.id = ?
      GROUP BY p.id
      LIMIT 1
    ");
    $check->execute([$programId]);
    $program = $check->fetch();

    if (!$program) {
      header('Location: /admin/programs.php?error=' . urlencode('Programme introuvable.'));
      exit;
    }

    if ((int)($program['package_count'] ?? 0) > 0) {
      header('Location: /admin/programs.php?error=' . urlencode('Suppression impossible: ce programme est encore utilise par un ou plusieurs packs.'));
      exit;
    }

    $pdo->prepare("DELETE FROM user_program_access WHERE program_id = ?")->execute([$programId]);
    $pdo->prepare("DELETE FROM programs WHERE id = ?")->execute([$programId]);

    header('Location: /admin/programs.php?deleted=1');
    exit;
  }
}

$rows = $pdo->query("
  SELECT
    p.*,
    " . (auth_table_exists($pdo, 'program_package_links')
      ? "COUNT(DISTINCT ppl.package_id)"
      : "COUNT(pk.id)") . " AS package_count,
    COUNT(DISTINCT upa.user_id) AS user_count
  FROM programs p
  " . (auth_table_exists($pdo, 'program_package_links')
    ? "LEFT JOIN program_package_links ppl ON ppl.program_id = p.id"
    : "LEFT JOIN packages pk ON pk.program_id = p.id") . "
  LEFT JOIN user_program_access upa ON upa.program_id = p.id
  GROUP BY p.id
  ORDER BY p.display_order ASC, p.id ASC
")->fetchAll() ?: [];
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('admin.programs.title', [], $lang)) ?></title>
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
          <h2 class="h1"><?= h(t('admin.programs.title', [], $lang)) ?></h2>
          <p class="sub"><?= h(t('admin.programs.subtitle', [], $lang)) ?></p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('programs'); ?>
        </div>
      </div>

      <?php if ($created): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Programme cree avec succes.</p>
      <?php endif; ?>
      <?php if ($updated): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Programme mis a jour avec succes.</p>
      <?php endif; ?>
      <?php if ($deleted): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Programme supprime avec succes.</p>
      <?php endif; ?>
      <?php if ($toggled): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Statut du programme mis a jour.</p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="error" style="margin:0 0 12px;"><?= h($error) ?></p>
      <?php endif; ?>

      <div class="admin-page-layout">
        <?php if (user_can_manage_program_catalog($adminUser)): ?>
          <section class="admin-section-panel admin-section-panel-accent">
            <div class="section-head admin-section-head">
              <div>
                <h3 class="h1"><?= h(t('admin.programs.create_title', [], $lang)) ?></h3>
              </div>
            </div>

            <form method="post" class="users-create-form">
              <input type="hidden" name="action" value="create_program">
              <div class="users-create-grid">
                <div>
                  <label class="label" for="program-name">Nom</label>
                  <input class="input" id="program-name" type="text" name="name" required>
                </div>
                <div>
                  <label class="label" for="program-description">Description</label>
                  <input class="input" id="program-description" type="text" name="description">
                </div>
                <div>
                  <label class="label" for="program-source-lang">Langue source questions</label>
                  <select class="input" id="program-source-lang" name="source_lang">
                    <?php foreach (question_translation_lang_labels() as $langCode => $langLabel): ?>
                      <option value="<?= h($langCode) ?>" <?= $langCode === 'fr' ? 'selected' : '' ?>><?= h($langLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="users-create-actions">
                <button class="btn" type="submit">Creer programme</button>
              </div>
            </form>
          </section>
        <?php endif; ?>

        <section class="admin-section-panel">
          <div class="section-head admin-section-head">
            <div>
              <h3 class="h1"><?= h(t('admin.programs.list_title', [], $lang)) ?></h3>
            </div>
          </div>

          <div class="table-wrap admin-table-panel">
            <?php if (!$rows): ?>
              <p class="empty-state"><?= h(t('admin.programs.none', [], $lang)) ?></p>
            <?php else: ?>
              <table class="table questions-table packages-table">
                <thead>
                  <tr>
                    <th>Nom</th>
                    <th><?= h(t('admin.programs.col_source', [], $lang)) ?></th>
                    <th><?= h(t('admin.programs.col_packs', [], $lang)) ?></th>
                    <th><?= h(t('admin.programs.col_users', [], $lang)) ?></th>
                    <th><?= h(t('admin.common.status', [], $lang)) ?></th>
                    <th><?= h(t('admin.common.actions', [], $lang)) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $row): ?>
                    <tr>
                      <td><?= h((string)($row['name'] ?? '')) ?></td>
                      <td><?= h(question_translation_lang_label((string)($row['source_lang'] ?? 'fr'))) ?></td>
                      <td><?= (int)($row['package_count'] ?? 0) ?></td>
                      <td><?= (int)($row['user_count'] ?? 0) ?></td>
                      <td><span class="<?= (int)($row['is_active'] ?? 0) === 1 ? 'pill success' : 'pill danger' ?>"><?= (int)($row['is_active'] ?? 0) === 1 ? 'Actif' : 'Inactif' ?></span></td>
                      <td class="actions-cell">
                        <?php $isActive = ((int)($row['is_active'] ?? 0) === 1); ?>
                        <a class="btn ghost icon-btn" href="/admin/program_edit.php?id=<?= (int)($row['id'] ?? 0) ?>" aria-label="Modifier ce programme" title="Modifier ce programme">
                          <svg class="icon-edit" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm2.92 2.33H5v-.92l8.06-8.06.92.92L5.92 19.58zM20.71 7.04a1.003 1.003 0 0 0 0-1.42l-2.34-2.34a1.003 1.003 0 0 0-1.42 0l-1.13 1.13 3.75 3.75 1.14-1.12z"/>
                          </svg>
                        </a>
                        <form method="post" class="inline-action-form">
                          <input type="hidden" name="action" value="toggle_program">
                          <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
                          <button class="btn ghost icon-btn <?= $isActive ? 'warning-soft' : 'success-soft' ?>" type="submit" aria-label="<?= $isActive ? 'Rendre le programme inactif' : 'Rendre le programme actif' ?>" title="<?= $isActive ? 'Rendre le programme inactif' : 'Rendre le programme actif' ?>">
                            <svg class="icon-power" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                              <path d="M11 3h2v9h-2zM7.05 5.64 8.46 7.05A7 7 0 1 0 15.54 7.05l1.41-1.41A9 9 0 1 1 7.05 5.64z"/>
                            </svg>
                          </button>
                        </form>
                        <form method="post" class="inline-action-form" onsubmit="return confirm('Supprimer ce programme ?');">
                          <input type="hidden" name="action" value="delete_program">
                          <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
                          <button class="btn ghost icon-btn danger" type="submit" aria-label="Supprimer ce programme" title="Supprimer">
                            <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                              <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                            </svg>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
  </div>
</body>
</html>
