<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();
$created = ((string)($_GET['created'] ?? '') === '1');
$deleted = ((string)($_GET['deleted'] ?? '') === '1');
$deleteError = trim((string)($_GET['delete_error'] ?? ''));
$reordered = ((string)($_GET['reordered'] ?? '') === '1');
$reorderError = trim((string)($_GET['reorder_error'] ?? ''));
$toggled = ((string)($_GET['toggled'] ?? '') === '1');
$toggleError = trim((string)($_GET['toggle_error'] ?? ''));

$hasProfileColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'profile'
")->fetchColumn();
$hasDisplayOrderColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'display_order'
")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ((string)($_POST['action'] ?? '') === 'move_pack')) {
  if (!$hasDisplayOrderColumn) {
    header('Location: /admin/packages.php?reorder_error=' . urlencode("Colonne display_order indisponible."));
    exit;
  }

  $moveId = (int)($_POST['id'] ?? 0);
  $direction = strtolower(trim((string)($_POST['direction'] ?? '')));
  if ($moveId <= 0 || !in_array($direction, ['up', 'down'], true)) {
    header('Location: /admin/packages.php?reorder_error=' . urlencode("Demande de tri invalide."));
    exit;
  }

  $ids = array_map(
    static fn(array $row): int => (int)$row['id'],
    $pdo->query("SELECT id FROM packages ORDER BY display_order ASC, id ASC")->fetchAll()
  );
  $index = array_search($moveId, $ids, true);
  if ($index === false) {
    header('Location: /admin/packages.php?reorder_error=' . urlencode("Pack introuvable."));
    exit;
  }

  $target = ($direction === 'up') ? ($index - 1) : ($index + 1);
  if ($target < 0 || $target >= count($ids)) {
    header('Location: /admin/packages.php');
    exit;
  }

  [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
  $up = $pdo->prepare("UPDATE packages SET display_order=? WHERE id=?");
  try {
    $pdo->beginTransaction();
    foreach ($ids as $pos => $id) {
      $up->execute([($pos + 1) * 10, (int)$id]);
    }
    $pdo->commit();
    header('Location: /admin/packages.php?reordered=1');
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    header('Location: /admin/packages.php?reorder_error=' . urlencode("Impossible de changer l'ordre des packs."));
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ((string)($_POST['action'] ?? '') === 'toggle_active')) {
  $toggleId = (int)($_POST['id'] ?? 0);
  if ($toggleId <= 0) {
    header('Location: /admin/packages.php?toggle_error=' . urlencode("Pack invalide."));
    exit;
  }

  $toggleStmt = $pdo->prepare("
    UPDATE packages
    SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
    WHERE id = ?
  ");

  try {
    $toggleStmt->execute([$toggleId]);
    header('Location: /admin/packages.php?toggled=1');
    exit;
  } catch (Throwable $e) {
    header('Location: /admin/packages.php?toggle_error=' . urlencode("Impossible de changer le statut du pack."));
    exit;
  }
}

$packagesOrderSql = $hasDisplayOrderColumn ? "ORDER BY display_order ASC, id ASC" : "ORDER BY id ASC";
$packages = $pdo->query("SELECT * FROM packages $packagesOrderSql")->fetchAll();

$counts = [];
$cntStmt = $pdo->query("
  SELECT q.need, q.level, COUNT(*) c
  FROM questions q
  WHERE EXISTS (
    SELECT 1
    FROM question_options qo
    WHERE qo.question_id = q.id
    GROUP BY qo.question_id
    HAVING COUNT(*) >= 2
  )
  GROUP BY q.need, q.level
");
foreach ($cntStmt->fetchAll() as $r) {
  $need = normalize_question_need((string)($r['need'] ?? ''));
  if ($need === '') {
    continue;
  }
  $counts[$need][(int)$r['level']] = (int)$r['c'];
}

$legacyStmt = $pdo->query("
  SELECT package_id, COUNT(*) c
  FROM questions
  WHERE package_id IS NOT NULL
  GROUP BY package_id
");
$legacyCounts = [];
foreach ($legacyStmt->fetchAll() as $r) {
  $legacyCounts[(int)$r['package_id']] = (int)$r['c'];
}

function compute_availability(array $pk, array $counts, array $legacyCounts): array {
  $required = 0;
  $available = 0;

  $raw = $pk['selection_rules_json'] ?? '';
  $raw = is_string($raw) ? trim($raw) : '';
  if ($raw !== '') {
    $rules = json_decode($raw, true);
    if (is_array($rules) && !empty($rules['buckets']) && is_array($rules['buckets'])) {
      $required = isset($rules['max']) ? (int)$rules['max'] : (int)($pk['selection_count'] ?? 0);
      if ($required < 1) {
        $required = (int)($pk['selection_count'] ?? 0);
      }

      $sumAvail = 0;
      foreach ($rules['buckets'] as $b) {
        $need = normalize_question_need((string)($b['need'] ?? ''));
        $take = (int)($b['take'] ?? 0);
        $targetTotal = (int)($b['target_total'] ?? 0);
        $levels = $b['levels'] ?? [];
        if ($need === '' || !is_array($levels)) {
          continue;
        }

        $bucketAvail = 0;
        foreach ($levels as $lv) {
          $lv = (int)$lv;
          $bucketAvail += (int)($counts[$need][$lv] ?? 0);
        }

        $remainingToRequired = $required - $sumAvail;
        if ($remainingToRequired <= 0) {
          break;
        }

        if ($take <= 0) {
          $take = $remainingToRequired;
        }

        $canTake = min($take, $bucketAvail);
        if ($targetTotal > 0) {
          $remainingToTarget = $targetTotal - $sumAvail;
          if ($remainingToTarget <= 0) {
            continue;
          }
          $canTake = min($canTake, $remainingToTarget);
        }
        $canTake = min($canTake, $remainingToRequired);
        if ($canTake <= 0) {
          continue;
        }
        $sumAvail += $canTake;
      }

      $available = $sumAvail;
      $ok = ($available >= $required);
      return [$available, $required, $ok];
    }
  }

  $required = (int)($pk['selection_count'] ?? 0);
  $available = (int)($legacyCounts[(int)$pk['id']] ?? 0);
  $ok = ($available >= $required);
  return [$available, $required, $ok];
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Packs</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card admin-page-shell">
      <div class="admin-head admin-page-hero">
        <div class="admin-head-copy">
          <p class="admin-page-eyebrow">Administration</p>
          <h2 class="h1">Admin &middot; Packs</h2>
          <p class="sub">Param&egrave;tres des packs</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('packages'); ?>
        </div>
      </div>

      <?php if ($created): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Pack cree avec succes.</p>
      <?php endif; ?>
      <?php if ($deleted): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Pack supprime avec succes.</p>
      <?php endif; ?>
      <?php if ($deleteError !== ''): ?>
        <p class="error" style="margin:0 0 12px;"><?= h($deleteError) ?></p>
      <?php endif; ?>
      <?php if ($reordered): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Ordre des packs mis a jour.</p>
      <?php endif; ?>
      <?php if ($reorderError !== ''): ?>
        <p class="error" style="margin:0 0 12px;"><?= h($reorderError) ?></p>
      <?php endif; ?>
      <?php if ($toggled): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Statut du pack mis a jour.</p>
      <?php endif; ?>
      <?php if ($toggleError !== ''): ?>
        <p class="error" style="margin:0 0 12px;"><?= h($toggleError) ?></p>
      <?php endif; ?>

      <div class="admin-page-layout">
      <section class="admin-section-panel admin-section-panel-accent">
      <div class="admin-panel-toolbar">
        <div>
          <h3 class="h1" style="margin:0;">Gestion des packs</h3>
          <p class="sub" style="margin:6px 0 0;">Cr&eacute;ation, ordre d'affichage, activation et disponibilit&eacute; des packs.</p>
        </div>
        <a class="btn admin-primary-action-btn" href="/admin/pack_create.php">+ Cr&eacute;er un pack</a>
      </div>
      </section>

      <section class="admin-section-panel">
      <div class="section-head admin-section-head">
        <div>
          <h3 class="h1">Catalogue des packs</h3>
        </div>
      </div>
      <div class="table-wrap admin-table-panel">
        <table class="table questions-table packages-table">
          <thead>
            <tr>
              <th>Nom</th>
              <?php if ($hasProfileColumn): ?><th>Profil</th><?php endif; ?>
              <?php if ($hasDisplayOrderColumn): ?>
                <th>
                  <span class="order-help-wrap">
                    <span>Ordre</span>
                    <span class="order-help-tip" tabindex="0" aria-label="Aide sur l'ordre des packs">
                      i
                      <span class="order-help-bubble">Definit l'ordre d'affichage des packs: le premier apparait en haut a gauche dans l'espace candidat.</span>
                    </span>
                  </span>
                </th>
              <?php endif; ?>
              <th>Seuil (%)</th>
              <th>Dur&eacute;e (min)</th>
              <th>Questions</th>
              <th>Statut</th>
              <th>
                <span class="order-help-wrap">
                  <span>Disponibilit&eacute;</span>
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
            <?php foreach ($packages as $idx => $pk): ?>
              <?php [$avail, $req, $ok] = compute_availability($pk, $counts, $legacyCounts); ?>
              <tr>
                <td><span style="<?= h(package_label_style((string)$pk['name'], (string)($pk['name_color_hex'] ?? ''))) ?>"><?= h($pk['name']) ?></span></td>
                <?php if ($hasProfileColumn): ?><td><?= h((string)($pk['profile'] ?? '-')) ?></td><?php endif; ?>
                <?php if ($hasDisplayOrderColumn): ?>
                  <td>
                    <div style="display:flex; align-items:center; justify-content:center; gap:6px; white-space:nowrap;">
                      <form method="post" style="display:flex; gap:6px; margin:0;">
                        <input type="hidden" name="action" value="move_pack">
                        <input type="hidden" name="id" value="<?= (int)$pk['id'] ?>">
                        <button class="btn ghost" type="submit" name="direction" value="up" <?= $idx === 0 ? 'disabled' : '' ?> title="Monter">&uarr;</button>
                        <button class="btn ghost" type="submit" name="direction" value="down" <?= $idx === (count($packages) - 1) ? 'disabled' : '' ?> title="Descendre">&darr;</button>
                      </form>
                    </div>
                  </td>
                <?php endif; ?>
                <td><?= (int)$pk['pass_threshold_percent'] ?></td>
                <td><?= (int)$pk['duration_limit_minutes'] ?></td>
                <td><?= (int)$pk['selection_count'] ?></td>
                <td>
                  <?php $isActive = ((int)($pk['is_active'] ?? 1) === 1); ?>
                  <span class="pill <?= $isActive ? 'success' : 'warning' ?>">
                    <?= $isActive ? 'Actif' : 'Inactif' ?>
                  </span>
                </td>
                <td>
                  <span class="pill <?= $ok ? 'success' : 'warning' ?>" title="<?= (int)$avail ?> / <?= (int)$req ?>">
                    <?= $ok ? 'OK' : '&Agrave; compl&eacute;ter' ?>
                  </span>
                </td>
                <td class="actions-cell">
                  <a class="btn ghost icon-btn" href="/admin/package_edit.php?id=<?= (int)$pk['id'] ?>" aria-label="Modifier ce pack" title="Modifier ce pack">
                    <svg class="icon-edit" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                      <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm2.92 2.33H5v-.92l8.06-8.06.92.92L5.92 19.58zM20.71 7.04a1.003 1.003 0 0 0 0-1.42l-2.34-2.34a1.003 1.003 0 0 0-1.42 0l-1.13 1.13 3.75 3.75 1.14-1.12z"/>
                    </svg>
                  </a>
                  <form method="post" class="inline-action-form">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= (int)$pk['id'] ?>">
                    <button class="btn ghost icon-btn <?= $isActive ? 'warning-soft' : 'success-soft' ?>" type="submit" aria-label="<?= $isActive ? 'Rendre le pack inactif' : 'Rendre le pack actif' ?>" title="<?= $isActive ? 'Rendre le pack inactif' : 'Rendre le pack actif' ?>">
                      <svg class="icon-power" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M11 3h2v9h-2zM7.05 5.64 8.46 7.05A7 7 0 1 0 15.54 7.05l1.41-1.41A9 9 0 1 1 7.05 5.64z"/>
                      </svg>
                    </button>
                  </form>
                  <a class="btn ghost icon-btn danger"
                     href="/admin/package_delete.php?id=<?= (int)$pk['id'] ?>"
                     aria-label="Supprimer ce pack"
                     title="Supprimer"
                     onclick="return confirm('Supprimer ce pack ? Cette action est irreversible et supprimera les donnees liees.');">
                    <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                      <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                    </svg>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </section>
      </div>
    </div>
  </div>
</body>
</html>
