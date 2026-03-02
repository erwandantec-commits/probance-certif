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
  $counts[strtoupper((string)$r['need'])][(int)$r['level']] = (int)$r['c'];
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
        $need = strtoupper((string)($b['need'] ?? ''));
        $take = (int)($b['take'] ?? 0);
        $targetTotal = (int)($b['target_total'] ?? 0);
        $levels = $b['levels'] ?? [];
        if ($take <= 0 || !in_array($need, ['PONE', 'PHM', 'PPM'], true) || !is_array($levels)) {
          continue;
        }

        $bucketAvail = 0;
        foreach ($levels as $lv) {
          $lv = (int)$lv;
          $bucketAvail += (int)($counts[$need][$lv] ?? 0);
        }

        $canTake = min($take, $bucketAvail);
        if ($targetTotal > 0) {
          $remainingToTarget = $targetTotal - $sumAvail;
          if ($remainingToTarget <= 0) {
            continue;
          }
          $canTake = min($canTake, $remainingToTarget);
        }
        $remainingToRequired = $required - $sumAvail;
        if ($remainingToRequired <= 0) {
          break;
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
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Packs</h2>
          <p class="sub">Param&egrave;tres des packs</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('packages'); ?>
        </div>
      </div>

      <hr class="separator">

      <?php if ($created): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Pack cree avec succes.</p>
      <?php endif; ?>
      <?php if ($deleted): ?>
        <p class="small" style="margin:0 0 12px; color: var(--ok); font-weight:700;">Pack supprime avec succes.</p>
      <?php endif; ?>
      <?php if ($deleteError !== ''): ?>
        <p class="error" style="margin:0 0 12px;"><?= h($deleteError) ?></p>
      <?php endif; ?>

      <div style="margin: 0 0 12px; display:flex; gap:10px; flex-wrap:wrap;">
        <a class="btn admin-primary-action-btn" href="/admin/pack_create.php">+ Cr&eacute;er un pack</a>
      </div>

      <div class="table-wrap">
        <table class="table questions-table packages-table">
          <thead>
            <tr>
              <th>Nom</th>
              <?php if ($hasProfileColumn): ?><th>Profil</th><?php endif; ?>
              <?php if ($hasDisplayOrderColumn): ?><th>Ordre</th><?php endif; ?>
              <th>Seuil (%)</th>
              <th>Dur&eacute;e (min)</th>
              <th>Questions</th>
              <th>Statut</th>
              <th>Disponibilit&eacute;</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($packages as $pk): ?>
              <?php [$avail, $req, $ok] = compute_availability($pk, $counts, $legacyCounts); ?>
              <tr>
                <td><span style="<?= h(package_label_style((string)$pk['name'], (string)($pk['name_color_hex'] ?? ''))) ?>"><?= h($pk['name']) ?></span></td>
                <?php if ($hasProfileColumn): ?><td><?= h((string)($pk['profile'] ?? '-')) ?></td><?php endif; ?>
                <?php if ($hasDisplayOrderColumn): ?><td><?= (int)($pk['display_order'] ?? 100) ?></td><?php endif; ?>
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
                  <a class="btn ghost" href="/admin/package_edit.php?id=<?= (int)$pk['id'] ?>">Modifier</a>
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
    </div>
  </div>
</body>
</html>
