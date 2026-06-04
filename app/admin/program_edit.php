<?php
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin();
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
ensure_program_source_language_schema($pdo);
if (!user_can_manage_program_catalog($adminUser)) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}
$programId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$error = trim((string)($_GET['error'] ?? ''));
$packDetached = ((string)($_GET['pack_detached'] ?? '') === '1');
$packAttached = ((string)($_GET['pack_attached'] ?? '') === '1');
$packToggled = ((string)($_GET['pack_toggled'] ?? '') === '1');

function admin_program_edit_redirect(string $url): void {
  header('Location: ' . $url);
  exit;
}

function admin_program_edit_package_column_exists(PDO $pdo, string $column): bool {
  return auth_column_exists($pdo, 'packages', $column);
}

function admin_program_edit_questions_column_exists(PDO $pdo, string $column): bool {
  return auth_column_exists($pdo, 'questions', $column);
}

function admin_program_edit_available_question_counts(PDO $pdo, int $programId): array {
  $counts = [];
  $where = [];
  if (admin_program_edit_questions_column_exists($pdo, 'is_active')) {
    $where[] = 'q.is_active = 1';
  }
  if ($programId > 0 && auth_program_question_links_enabled($pdo)) {
    $where[] = auth_program_question_scope_sql($pdo, $programId, 'q');
  }
  $activeWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
  $stmt = $pdo->query("
    SELECT q.need, q.level, COUNT(*) c
    FROM questions q
    $activeWhere
    GROUP BY q.need, q.level
  ");
  foreach (($stmt ? $stmt->fetchAll() : []) as $row) {
    $need = normalize_question_need((string)($row['need'] ?? ''));
    $level = (int)($row['level'] ?? 0);
    if ($need === '' || $level < 1) {
      continue;
    }
    if (!isset($counts[$need])) {
      $counts[$need] = [1 => 0, 2 => 0, 3 => 0];
    }
    $counts[$need][$level] = (int)($row['c'] ?? 0);
  }
  return $counts;
}

function admin_program_edit_compute_availability(array $package, array $counts, array $legacyCounts): array {
  $required = 0;
  $available = 0;

  $raw = $package['selection_rules_json'] ?? '';
  $raw = is_string($raw) ? trim($raw) : '';
  if ($raw !== '') {
    $rules = json_decode($raw, true);
    if (is_array($rules) && !empty($rules['buckets']) && is_array($rules['buckets'])) {
      $required = isset($rules['max']) ? (int)$rules['max'] : (int)($package['selection_count'] ?? 0);
      if ($required < 1) {
        $required = (int)($package['selection_count'] ?? 0);
      }

      $sumAvailable = 0;
      foreach ($rules['buckets'] as $bucket) {
        $need = normalize_question_need((string)($bucket['need'] ?? ''));
        $take = (int)($bucket['take'] ?? 0);
        $targetTotal = (int)($bucket['target_total'] ?? 0);
        $levels = $bucket['levels'] ?? [];
        if ($need === '' || !is_array($levels)) {
          continue;
        }

        $bucketAvailable = 0;
        foreach ($levels as $level) {
          $bucketAvailable += (int)($counts[$need][(int)$level] ?? 0);
        }

        $remainingRequired = $required - $sumAvailable;
        if ($remainingRequired <= 0) {
          break;
        }

        if ($take <= 0) {
          $take = $remainingRequired;
        }

        $canTake = min($take, $bucketAvailable, $remainingRequired);
        if ($targetTotal > 0) {
          $remainingTarget = $targetTotal - $sumAvailable;
          if ($remainingTarget <= 0) {
            continue;
          }
          $canTake = min($canTake, $remainingTarget);
        }

        if ($canTake <= 0) {
          continue;
        }

        $sumAvailable += $canTake;
      }

      $available = $sumAvailable;
      return [$available, $required, $available >= $required];
    }
  }

  $required = (int)($package['selection_count'] ?? 0);
  $available = (int)($legacyCounts[(int)($package['id'] ?? 0)] ?? 0);
  return [$available, $required, $available >= $required];
}

if ($programId <= 0) {
  admin_program_edit_redirect('/admin/programs.php?error=' . urlencode('Programme invalide.'));
}

$hasProfileColumn = admin_program_edit_package_column_exists($pdo, 'profile');
$hasNameColorColumn = admin_program_edit_package_column_exists($pdo, 'name_color_hex');
$hasSelectionRulesColumn = admin_program_edit_package_column_exists($pdo, 'selection_rules_json');
$hasProgramIdColumn = admin_program_edit_package_column_exists($pdo, 'program_id');
$hasProgramPackageLinksTable = auth_program_package_links_enabled($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'update_program');

  if ($action === 'attach_existing_package') {
    $packageId = (int)($_POST['package_id'] ?? 0);
    if ($packageId <= 0) {
      admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&error=' . urlencode('Pack invalide.'));
    }

    if ($hasProgramPackageLinksTable) {
      $stmt = $pdo->prepare("
        INSERT IGNORE INTO program_package_links(program_id, package_id, is_active)
        VALUES(?, ?, 1)
      ");
      $stmt->execute([$programId, $packageId]);
    } elseif ($hasProgramIdColumn) {
      $packageStmt = $pdo->prepare("SELECT id, program_id FROM packages WHERE id = ? LIMIT 1");
      $packageStmt->execute([$packageId]);
      $package = $packageStmt->fetch();
      if (!$package) {
        admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&error=' . urlencode('Pack introuvable.'));
      }
      if ((int)($package['program_id'] ?? 0) > 0) {
        admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&error=' . urlencode('Ce pack est deja rattache a un programme.'));
      }
      $stmt = $pdo->prepare("UPDATE packages SET program_id = ? WHERE id = ?");
      $stmt->execute([$programId, $packageId]);
    } else {
      admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&error=' . urlencode('La liaison programme/pack n\'est pas disponible.'));
    }

    admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&pack_attached=1');
  }

  if ($action === 'toggle_package_active') {
    $packageId = (int)($_POST['package_id'] ?? 0);
    if ($packageId <= 0) {
      admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&error=' . urlencode('Pack invalide.'));
    }

    if ($hasProgramPackageLinksTable) {
      $stmt = $pdo->prepare("
        UPDATE program_package_links
        SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
        WHERE program_id = ?
          AND package_id = ?
      ");
      $stmt->execute([$programId, $packageId]);
    } else {
      $stmt = $pdo->prepare("
        UPDATE packages
        SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
        WHERE id = ?
      ");
      $stmt->execute([$packageId]);
    }

    admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&pack_toggled=1');
  }

  if ($action === 'detach_package') {
    $packageId = (int)($_POST['package_id'] ?? 0);
    if ($packageId <= 0) {
      admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&error=' . urlencode('Pack invalide.'));
    }

    if ($hasProgramPackageLinksTable) {
      $stmt = $pdo->prepare("
        DELETE FROM program_package_links
        WHERE program_id = ?
          AND package_id = ?
      ");
      $stmt->execute([$programId, $packageId]);
    } elseif ($hasProgramIdColumn) {
      $packageStmt = $pdo->prepare("
        SELECT id
        FROM packages
        WHERE id = ? AND program_id = ?
        LIMIT 1
      ");
      $packageStmt->execute([$packageId, $programId]);
      if (!$packageStmt->fetch()) {
        admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&error=' . urlencode('Pack introuvable pour ce programme.'));
      }
      $stmt = $pdo->prepare("UPDATE packages SET program_id = NULL WHERE id = ?");
      $stmt->execute([$packageId]);
    } else {
      admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&error=' . urlencode('La liaison programme/pack n\'est pas disponible.'));
    }

    admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&pack_detached=1');
  }

  $name = trim((string)($_POST['name'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $sourceLang = question_translation_normalize_lang((string)($_POST['source_lang'] ?? 'fr'));
  $isActive = ((string)($_POST['is_active'] ?? '') === '1') ? 1 : 0;

  if ($name === '') {
    admin_program_edit_redirect('/admin/program_edit.php?id=' . $programId . '&error=' . urlencode('Nom de programme obligatoire.'));
  }

  $existing = $pdo->prepare("SELECT id FROM programs WHERE id = ? LIMIT 1");
  $existing->execute([$programId]);
  if (!$existing->fetch()) {
    admin_program_edit_redirect('/admin/programs.php?error=' . urlencode('Programme introuvable.'));
  }

  $update = $pdo->prepare("
    UPDATE programs
    SET name = ?, description = ?, source_lang = ?, is_active = ?
    WHERE id = ?
  ");
  $update->execute([$name, $description !== '' ? $description : null, $sourceLang, $isActive, $programId]);

  admin_program_edit_redirect('/admin/programs.php?updated=1');
}

$programPackageJoin = $hasProgramPackageLinksTable
  ? "LEFT JOIN program_package_links ppl ON ppl.program_id = p.id"
  : "LEFT JOIN packages pk ON pk.program_id = p.id";
$programPackageCount = $hasProgramPackageLinksTable
  ? "COUNT(DISTINCT ppl.package_id)"
  : "COUNT(DISTINCT pk.id)";
$programStmt = $pdo->prepare("
  SELECT
    p.*,
    $programPackageCount AS package_count,
    COUNT(DISTINCT upa.user_id) AS assigned_user_count
  FROM programs p
  $programPackageJoin
  LEFT JOIN user_program_access upa ON upa.program_id = p.id
  WHERE p.id = ?
  GROUP BY p.id
  LIMIT 1
");
$programStmt->execute([$programId]);
$program = $programStmt->fetch();

if (!$program) {
  admin_program_edit_redirect('/admin/programs.php?error=' . urlencode('Programme introuvable.'));
}

$packageSelects = [
  'pk.id',
  'pk.name',
  'pk.pass_threshold_percent',
  'pk.duration_limit_minutes',
  'pk.selection_count',
  'pk.is_active',
];
$packageSelects[] = $hasProfileColumn ? 'pk.profile' : 'NULL AS profile';
$packageSelects[] = $hasNameColorColumn ? 'pk.name_color_hex' : 'NULL AS name_color_hex';
$packageSelects[] = $hasSelectionRulesColumn ? 'pk.selection_rules_json' : 'NULL AS selection_rules_json';
if ($hasProgramPackageLinksTable) {
  $packageSelects[] = 'ppl.is_active AS program_link_is_active';
}

$packageFromSql = "FROM packages pk";
$packageWhereSql = '';
if ($hasProgramPackageLinksTable) {
  $packageFromSql .= "
    JOIN program_package_links ppl
      ON ppl.package_id = pk.id
     AND ppl.program_id = ?";
} else {
  $packageWhereSql = "WHERE pk.program_id = ?";
}

$packageStmt = $pdo->prepare("
  SELECT
    " . implode(",\n    ", $packageSelects) . ",
    COUNT(q.id) AS question_count
  $packageFromSql
  LEFT JOIN questions q ON q.package_id = pk.id
  $packageWhereSql
  GROUP BY pk.id" . ($hasProgramPackageLinksTable ? ", ppl.is_active" : '') . "
  ORDER BY pk.name ASC, pk.id ASC
");
$packageStmt->execute([$programId]);
$packages = $packageStmt->fetchAll() ?: [];

$availableQuestionCounts = admin_program_edit_available_question_counts($pdo, $programId);
$legacyQuestionScopeWhere = ($programId > 0 && auth_program_question_links_enabled($pdo))
  ? (" AND " . auth_program_question_scope_sql($pdo, $programId, 'questions'))
  : "";
$legacyCountsStmt = $pdo->query("
  SELECT package_id, COUNT(*) c
  FROM questions
  WHERE package_id IS NOT NULL
  $legacyQuestionScopeWhere
  GROUP BY package_id
");
$legacyCounts = [];
foreach (($legacyCountsStmt ? $legacyCountsStmt->fetchAll() : []) as $row) {
  $legacyCounts[(int)($row['package_id'] ?? 0)] = (int)($row['c'] ?? 0);
}

$unassignedPackageSelects = ['pk.id', 'pk.name'];
$unassignedPackageSelects[] = $hasProfileColumn ? 'pk.profile' : 'NULL AS profile';
$unassignedPackages = [];
if ($hasProgramPackageLinksTable) {
  $stmt = $pdo->prepare("
    SELECT " . implode(', ', $unassignedPackageSelects) . "
    FROM packages pk
    WHERE NOT EXISTS (
      SELECT 1
      FROM program_package_links ppl
      WHERE ppl.package_id = pk.id
        AND ppl.program_id = ?
    )
    ORDER BY pk.name ASC, pk.id ASC
  ");
  $stmt->execute([$programId]);
  $unassignedPackages = $stmt->fetchAll() ?: [];
} elseif ($hasProgramIdColumn) {
  $stmt = $pdo->query("
    SELECT " . implode(', ', $unassignedPackageSelects) . "
    FROM packages pk
    WHERE pk.program_id IS NULL
    ORDER BY pk.name ASC, pk.id ASC
  ");
  $unassignedPackages = $stmt ? ($stmt->fetchAll() ?: []) : [];
}
?>
<!doctype html>
<html lang="fr">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title>Admin &middot; Edition programme</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card admin-page-shell">
      <div class="admin-head admin-page-hero">
        <div class="admin-head-copy">
          <p class="admin-page-eyebrow">Programmes</p>
          <h2 class="h1">Programme &middot; <?= h((string)($program['name'] ?? 'Programme')) ?></h2>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('programs'); ?>
        </div>
      </div>

      <?php if ($error !== ''): ?>
        <div class="admin-notice is-bad"><?= h($error) ?></div>
      <?php endif; ?>
      <?php if ($packDetached): ?>
        <div class="admin-notice is-ok">Pack retire du programme.</div>
      <?php endif; ?>
      <?php if ($packAttached): ?>
        <div class="admin-notice is-ok">Pack ajoute au programme.</div>
      <?php endif; ?>
      <?php if ($packToggled): ?>
        <div class="admin-notice is-ok">Disponibilite du pack mise a jour pour ce programme.</div>
      <?php endif; ?>

      <div class="admin-stats-grid">
        <article class="admin-stat-card">
          <span class="admin-stat-label">Packs lies</span>
          <strong class="admin-stat-value"><?= (int)($program['package_count'] ?? 0) ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label">Acces utilisateurs</span>
          <strong class="admin-stat-value"><?= (int)($program['assigned_user_count'] ?? 0) ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label">Langue source</span>
          <strong class="admin-stat-value"><?= h(question_translation_lang_label((string)($program['source_lang'] ?? 'fr'))) ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label">Statut</span>
          <strong class="admin-stat-value"><?= (int)($program['is_active'] ?? 0) === 1 ? 'Actif' : 'Inactif' ?></strong>
        </article>
      </div>

      <div class="admin-page-layout">
        <section class="admin-section-panel admin-section-panel-accent">
          <div class="section-head admin-section-head">
            <div>
              <h3 class="h1">Modifier le programme</h3>
            </div>
            <div class="admin-head-actions">
              <a class="btn ghost" href="/admin/programs.php">Retour aux programmes</a>
            </div>
          </div>

          <form method="post" class="users-create-form">
            <input type="hidden" name="id" value="<?= (int)($program['id'] ?? 0) ?>">
            <div class="users-create-grid">
              <div>
                <label class="label" for="edit-program-name">Nom</label>
                <input class="input" id="edit-program-name" type="text" name="name" value="<?= h((string)($program['name'] ?? '')) ?>" required>
              </div>
              <div>
                <label class="label" for="edit-program-description">Description</label>
                <input class="input" id="edit-program-description" type="text" name="description" value="<?= h((string)($program['description'] ?? '')) ?>">
              </div>
              <div>
                <label class="label" for="edit-program-source-lang">Langue source questions</label>
                <select class="input" id="edit-program-source-lang" name="source_lang">
                  <?php $programSourceLang = question_translation_normalize_lang((string)($program['source_lang'] ?? 'fr')); ?>
                  <?php foreach (question_translation_lang_labels() as $langCode => $langLabel): ?>
                    <option value="<?= h($langCode) ?>" <?= $programSourceLang === $langCode ? 'selected' : '' ?>><?= h($langLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="label" for="edit-program-active">Statut</label>
                <select class="input" id="edit-program-active" name="is_active">
                  <option value="1" <?= (int)($program['is_active'] ?? 0) === 1 ? 'selected' : '' ?>>Actif</option>
                  <option value="0" <?= (int)($program['is_active'] ?? 0) === 0 ? 'selected' : '' ?>>Inactif</option>
                </select>
              </div>
            </div>
            <div class="users-create-actions">
              <button class="btn" type="submit">Enregistrer</button>
              <a class="btn ghost" href="/admin/programs.php">Annuler</a>
            </div>
          </form>
        </section>

        <section class="admin-section-panel">
          <div class="section-head admin-section-head">
            <div>
              <h3 class="h1">Packs associes</h3>
              <p class="sub sessions-meta"><?= (int)count($packages) ?> pack(s)</p>
            </div>
            <div class="admin-head-actions">
              <a class="btn" href="/admin/pack_create.php?program_id=<?= (int)$programId ?>">Ajouter un pack</a>
            </div>
          </div>

          <?php if ($hasProgramPackageLinksTable || $hasProgramIdColumn): ?>
            <form method="post" class="users-create-form" style="margin-bottom:16px;">
              <input type="hidden" name="action" value="attach_existing_package">
              <input type="hidden" name="id" value="<?= (int)$programId ?>">
              <div class="users-create-grid" style="grid-template-columns: minmax(0, 1fr) auto;">
                <div>
                  <label class="label" for="attach-package-id">Ajouter un pack existant</label>
                  <select class="input" id="attach-package-id" name="package_id" <?= !$unassignedPackages ? 'disabled' : '' ?>>
                    <?php if (!$unassignedPackages): ?>
                      <option value="0">Aucun pack disponible</option>
                    <?php else: ?>
                      <?php foreach ($unassignedPackages as $unassignedPackage): ?>
                        <option value="<?= (int)($unassignedPackage['id'] ?? 0) ?>">
                          <?= h((string)($unassignedPackage['name'] ?? 'Pack')) ?><?= $hasProfileColumn && trim((string)($unassignedPackage['profile'] ?? '')) !== '' ? ' - ' . h((string)$unassignedPackage['profile']) : '' ?>
                        </option>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </select>
                </div>
                <div class="users-create-actions" style="align-self:end;">
                  <button class="btn" type="submit" <?= !$unassignedPackages ? 'disabled' : '' ?>>Associer</button>
                </div>
              </div>
            </form>
          <?php endif; ?>

          <?php if (!$packages): ?>
            <p class="empty-state">Aucun pack n'est associe a ce programme.</p>
          <?php else: ?>
            <div class="table-wrap admin-table-panel">
              <table class="table questions-table packages-table">
                <thead>
                  <tr>
                    <th>Nom</th>
                    <?php if ($hasProfileColumn): ?><th>Profil</th><?php endif; ?>
                    <th>Seuil (%)</th>
                    <th>Duree (min)</th>
                    <th>Questions</th>
                    <th>Statut</th>
                    <th>
                      <span class="order-help-wrap">
                        <span>Disponibilite</span>
                        <span class="order-help-tip" tabindex="0" aria-label="Aide sur la disponibilite des packs">
                          i
                          <span class="order-help-bubble">Indique si le pack a suffisamment de questions configurees pour etre lance en examen.</span>
                        </span>
                      </span>
                    </th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($packages as $package): ?>
                    <?php
                      [$available, $required, $isReady] = admin_program_edit_compute_availability($package, $availableQuestionCounts, $legacyCounts);
                      $packageUrl = '/admin/package_edit.php?id=' . (int)($package['id'] ?? 0) . '&program_id=' . $programId;
                      $isProgramActive = $hasProgramPackageLinksTable
                        ? ((int)($package['program_link_is_active'] ?? 1) === 1)
                        : ((int)($package['is_active'] ?? 0) === 1);
                    ?>
                    <tr>
                      <td><span style="<?= h(package_label_style((string)($package['name'] ?? 'Pack'), (string)($package['name_color_hex'] ?? ''))) ?>"><?= h((string)($package['name'] ?? 'Pack')) ?></span></td>
                      <?php if ($hasProfileColumn): ?><td><?= h((string)($package['profile'] ?? '-')) ?></td><?php endif; ?>
                      <td><?= (int)($package['pass_threshold_percent'] ?? 0) ?></td>
                      <td><?= (int)($package['duration_limit_minutes'] ?? 0) ?></td>
                      <td><?= (int)($package['selection_count'] ?? 0) ?></td>
                      <td>
                        <span class="pill <?= $isProgramActive ? 'success' : 'warning' ?>">
                          <?= $isProgramActive ? 'Actif' : 'Inactif' ?>
                        </span>
                      </td>
                      <td>
                        <span class="pill <?= $isReady ? 'success' : 'warning' ?>" title="<?= (int)$available ?> / <?= (int)$required ?>">
                          <?= $isReady ? 'OK' : '&Agrave; completer' ?>
                        </span>
                      </td>
                      <td class="actions-cell">
                        <a class="btn ghost icon-btn" href="<?= h($packageUrl) ?>" aria-label="Modifier ce pack" title="Modifier ce pack">
                          <svg class="icon-edit" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm2.92 2.33H5v-.92l8.06-8.06.92.92L5.92 19.58zM20.71 7.04a1.003 1.003 0 0 0 0-1.42l-2.34-2.34a1.003 1.003 0 0 0-1.42 0l-1.13 1.13 3.75 3.75 1.14-1.12z"/>
                          </svg>
                        </a>
                        <form method="post" class="inline-action-form">
                          <input type="hidden" name="action" value="toggle_package_active">
                          <input type="hidden" name="id" value="<?= (int)$programId ?>">
                          <input type="hidden" name="package_id" value="<?= (int)($package['id'] ?? 0) ?>">
                          <button class="btn ghost icon-btn <?= $isProgramActive ? 'warning-soft' : 'success-soft' ?>" type="submit" aria-label="<?= $isProgramActive ? 'Rendre le pack inactif dans ce programme' : 'Rendre le pack actif dans ce programme' ?>" title="<?= $isProgramActive ? 'Rendre le pack inactif dans ce programme' : 'Rendre le pack actif dans ce programme' ?>">
                            <svg class="icon-power" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                              <path d="M11 3h2v9h-2zM7.05 5.64 8.46 7.05A7 7 0 1 0 15.54 7.05l1.41-1.41A9 9 0 1 1 7.05 5.64z"/>
                            </svg>
                          </button>
                        </form>
                        <form method="post" class="inline-action-form" onsubmit="return confirm('Retirer ce pack de ce programme ?');">
                          <input type="hidden" name="action" value="detach_package">
                          <input type="hidden" name="id" value="<?= (int)$programId ?>">
                          <input type="hidden" name="package_id" value="<?= (int)($package['id'] ?? 0) ?>">
                          <button class="btn ghost icon-btn danger" type="submit" aria-label="Retirer ce pack du programme" title="Retirer du programme">
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
            </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </div>
</body>
</html>
