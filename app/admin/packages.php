<?php
require_once __DIR__ . '/_auth.php';
require_admin();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

$packages = $pdo->query("SELECT * FROM packages ORDER BY id")->fetchAll();

$cntStmt = $pdo->query("
  SELECT need, level, COUNT(*) c
  FROM questions
  GROUP BY need, level
");
$counts = [];
foreach ($cntStmt->fetchAll() as $r) {
  $counts[strtoupper($r['need'])][(int)$r['level']] = (int)$r['c'];
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
  $detail = '';

  $raw = $pk['selection_rules_json'] ?? '';
  $raw = is_string($raw) ? trim($raw) : '';
  if ($raw !== '') {
    $rules = json_decode($raw, true);
    if (is_array($rules) && !empty($rules['buckets']) && is_array($rules['buckets'])) {
      $required = isset($rules['max']) ? (int)$rules['max'] : (int)($pk['selection_count'] ?? 0);
      if ($required < 1) {
        $required = (int)($pk['selection_count'] ?? 0);
      }

      $missingParts = [];
      $sumAvail = 0;

      foreach ($rules['buckets'] as $b) {
        $need = strtoupper((string)($b['need'] ?? ''));
        $take = (int)($b['take'] ?? 0);
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
        $sumAvail += $canTake;

        if ($bucketAvail < $take) {
          $missingParts[] = "$need L" . implode(',', array_map('intval', $levels)) . " (-" . ($take - $bucketAvail) . ")";
        }
      }

      $available = $sumAvail;
      $detail = $missingParts ? ('Manque: ' . implode(' - ', $missingParts)) : 'OK';
      $ok = ($available >= $required);
      return [$available, $required, $ok, $detail];
    }
  }

  $required = (int)($pk['selection_count'] ?? 0);
  $available = (int)($legacyCounts[(int)$pk['id']] ?? 0);
  $ok = ($available >= $required);
  $detail = $ok ? 'OK' : ('Manque ' . ($required - $available));
  return [$available, $required, $ok, $detail];
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Packages</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Packages</h2>
          <p class="sub">Param&egrave;tres de certification</p>
        </div>
        <div class="admin-head-actions">
          <a class="btn ghost" href="/admin/index.php">&larr; Retour</a>
          <a class="btn ghost admin-logout-btn" href="/logout.php">D&eacute;connexion</a>
        </div>
      </div>

      <hr class="separator">

      <div class="table-wrap">
        <table class="table questions-table">
          <thead>
            <tr>
              <th>Nom</th>
              <th>Seuil (%)</th>
              <th>Dur&eacute;e (min)</th>
              <th>Questions</th>
              <th>Disponibilit&eacute;</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($packages as $pk): ?>
              <?php [$avail, $req, $ok, $detail] = compute_availability($pk, $counts, $legacyCounts); ?>
              <tr>
                <td><?= h($pk['name']) ?></td>
                <td><?= (int)$pk['pass_threshold_percent'] ?></td>
                <td><?= (int)$pk['duration_limit_minutes'] ?></td>
                <td><?= (int)$pk['selection_count'] ?></td>
                <td>
                  <span class="pill <?= $ok ? 'success' : 'warning' ?>">
                    <?= $ok ? 'OK' : 'Incomplet' ?>
                  </span>
                  <span class="small" style="margin-left:8px;">
                    <?= (int)$avail ?> / <?= (int)$req ?>
                    <?php if (!$ok): ?> - <?= h($detail) ?><?php endif; ?>
                  </span>
                </td>
                <td class="actions-cell">
                  <a class="btn ghost" href="/admin/package_edit.php?id=<?= (int)$pk['id'] ?>">Modifier</a>
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
