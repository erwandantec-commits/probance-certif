<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

function package_edit_questions_column_exists(PDO $pdo, string $column): bool {
  static $cache = [];
  if (isset($cache[$column])) {
    return $cache[$column];
  }
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'questions'
      AND COLUMN_NAME = ?
  ");
  $st->execute([$column]);
  $cache[$column] = ((int)$st->fetchColumn() > 0);
  return $cache[$column];
}


$id = (int)($_GET['id'] ?? 0);
$filterNeed = strtoupper(trim((string)($_GET['need'] ?? '')));
$filterLevel = (int)($_GET['level'] ?? 0);
if (!in_array($filterNeed, ['PONE', 'PHM', 'PPM'], true)) {
  $filterNeed = '';
}
if ($filterLevel < 1 || $filterLevel > 3) {
  $filterLevel = 0;
}
$page = max(1, (int)($_GET['page'] ?? 1));

$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=?");
$stmt->execute([$id]);
$pk = $stmt->fetch();

if (!$pk) {
  http_response_code(404);
  echo "Not found";
  exit;
}

$allowedByNeed = [];
$rulesRaw = trim((string)($pk['selection_rules_json'] ?? ''));
if ($rulesRaw !== '') {
  $rules = json_decode($rulesRaw, true);
  if (is_array($rules) && !empty($rules['buckets']) && is_array($rules['buckets'])) {
    foreach ($rules['buckets'] as $bucket) {
      $need = strtoupper(trim((string)($bucket['need'] ?? '')));
      $levels = $bucket['levels'] ?? [];
      if (!in_array($need, ['PONE', 'PHM', 'PPM'], true) || !is_array($levels)) {
        continue;
      }
      if (!isset($allowedByNeed[$need])) {
        $allowedByNeed[$need] = [];
      }
      foreach ($levels as $lv) {
        $lv = (int)$lv;
        if ($lv >= 1 && $lv <= 9) {
          $allowedByNeed[$need][$lv] = true;
        }
      }
    }
  }
}
if (empty($allowedByNeed)) {
  $allowedByNeed = [
    'PONE' => [1 => true, 2 => true, 3 => true],
    'PHM' => [1 => true, 2 => true, 3 => true],
    'PPM' => [1 => true, 2 => true, 3 => true],
  ];
}

if ($filterNeed !== '' && !isset($allowedByNeed[$filterNeed])) {
  $filterNeed = '';
}

$dist = [
  'PONE' => [1 => 0, 2 => 0, 3 => 0],
  'PHM' => [1 => 0, 2 => 0, 3 => 0],
  'PPM' => [1 => 0, 2 => 0, 3 => 0],
];

$distStmt = $pdo->prepare("
  SELECT need, knowledge_required_csv, level
  FROM questions
");
$distStmt->execute();
foreach ($distStmt->fetchAll() as $r) {
  $level = (int)($r['level'] ?? 1);
  if ($level < 1 || $level > 3) {
    continue;
  }
  $tokens = [];
  $fallbackNeed = strtoupper((string)($r['need'] ?? 'PONE'));
  if (isset($dist[$fallbackNeed])) {
    $tokens[$fallbackNeed] = true;
  }
  foreach (array_keys($tokens) as $tk) {
    if (!isset($allowedByNeed[$tk]) || !isset($allowedByNeed[$tk][$level])) {
      continue;
    }
    $dist[$tk][$level] = (int)($dist[$tk][$level] ?? 0) + 1;
  }
}

$eligibleWhereParts = [];
$eligibleParams = [];
foreach ($allowedByNeed as $needKey => $levelsMap) {
  $levels = array_keys($levelsMap);
  $levels = array_values(array_filter(array_map('intval', $levels), fn($x) => $x >= 1 && $x <= 9));
  if (!$levels) {
    continue;
  }
  $inLevels = implode(',', array_fill(0, count($levels), '?'));
  $eligibleWhereParts[] = "(q.need = ? AND q.level IN ($inLevels))";
  $eligibleParams[] = $needKey;
  foreach ($levels as $lv) {
    $eligibleParams[] = $lv;
  }
}
$eligibleSql = $eligibleWhereParts ? ('(' . implode(' OR ', $eligibleWhereParts) . ')') : '1=1';

$qCountSql = "
  SELECT COUNT(*)
  FROM questions q
  WHERE $eligibleSql
";
$qCountParams = $eligibleParams;
if ($filterNeed !== '') {
  $qCountSql .= " AND q.need = ? ";
  $qCountParams[] = $filterNeed;
}
if ($filterLevel > 0) {
  $qCountSql .= " AND q.level = ? ";
  $qCountParams[] = $filterLevel;
}
$qCountStmt = $pdo->prepare($qCountSql);
$qCountStmt->execute($qCountParams);
$totalQuestions = (int)$qCountStmt->fetchColumn();
$limit = 20;
$totalPages = max(1, (int)ceil($totalQuestions / $limit));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $limit;

$qSql = "
  SELECT
    q.id,
    q.text,
    q.need,
    q.knowledge_required_csv,
    q.level,
    q.question_type,
    q.allow_skip,
    COUNT(qo.id) AS option_count
  FROM questions q
  LEFT JOIN question_options qo ON qo.question_id = q.id
  WHERE $eligibleSql
";
$qParams = $eligibleParams;
if ($filterNeed !== '') {
  $qSql .= " AND q.need = ? ";
  $qParams[] = $filterNeed;
}
if ($filterLevel > 0) {
  $qSql .= " AND q.level = ? ";
  $qParams[] = $filterLevel;
}
$qSql .= "
	  GROUP BY q.id, q.text, q.need, q.knowledge_required_csv, q.level, q.question_type, q.allow_skip
  ORDER BY q.id DESC
  LIMIT ? OFFSET ?
";
$qStmt = $pdo->prepare($qSql);
$i = 1;
foreach ($qParams as $v) {
  $qStmt->bindValue($i++, $v);
}
$qStmt->bindValue($i++, (int)$limit, PDO::PARAM_INT);
$qStmt->bindValue($i++, (int)$offset, PDO::PARAM_INT);
$qStmt->execute();
$questions = $qStmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $threshold = (int)($_POST['pass_threshold_percent'] ?? 80);
  $duration = (int)($_POST['duration_limit_minutes'] ?? 120);
  $count = (int)($_POST['selection_count'] ?? 5);

  if ($threshold < 0 || $threshold > 100) {
    $error = "Seuil invalide";
  } elseif ($duration < 1 || $duration > 600) {
    $error = "Duree invalide";
  } elseif ($count < 1 || $count > 200) {
    $error = "Nombre de questions invalide";
  } else {
    $update = $pdo->prepare("
      UPDATE packages
      SET pass_threshold_percent=?,
          duration_limit_minutes=?,
          selection_count=?
      WHERE id=?
    ");

    $update->execute([$threshold, $duration, $count, $id]);
    header("Location: /admin/packages.php");
    exit;
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Modifier package</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1" defer></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Modifier package</h2>
          <p class="sub"><span style="<?= h(package_label_style((string)$pk['name'])) ?>"><?= h($pk['name']) ?></span></p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('packages'); ?>
        </div>
      </div>

      <hr class="separator">

      <?php if ($error): ?>
        <p class="error"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post">
        <div class="package-edit-grid">
          <div>
            <label class="label">Seuil de réussite (%)</label>
            <input
              class="input"
              type="number"
              name="pass_threshold_percent"
              min="0"
              max="100"
              value="<?= (int)$pk['pass_threshold_percent'] ?>"
              required
            >
          </div>

          <div>
            <label class="label">Durée max (minutes)</label>
            <input
              class="input"
              type="number"
              name="duration_limit_minutes"
              min="1"
              max="600"
              value="<?= (int)$pk['duration_limit_minutes'] ?>"
              required
            >
          </div>

          <div>
            <label class="label">Nombre de questions tirées</label>
            <input
              class="input"
              type="number"
              name="selection_count"
              min="1"
              max="200"
              value="<?= (int)$pk['selection_count'] ?>"
              required
            >
          </div>
        </div>

        <div style="margin-top:14px; display:flex; gap:10px;">
          <button class="btn" type="submit">Enregistrer</button>
          <a class="btn ghost" href="/admin/packages.php">Annuler</a>
        </div>
      </form>

      <hr class="separator">

      <h3 class="distribution-title">Questions du package</h3>
      <div style="margin: 0 0 10px; display:flex; gap:10px; flex-wrap:wrap;">
        <a class="btn ghost" href="/admin/import_questions.php">Importer questions</a>
      </div>
      <p class="small">
        Répartition par connaissances requises et niveau (banque globale, utilisée pour le tirage de ce package).
        <?php if ($filterNeed !== '' || $filterLevel > 0): ?>
          <span class="small" style="margin-left:8px;">
            Filtre: <b><?= h($filterNeed ?: 'Tous') ?></b><?= $filterLevel > 0 ? ' - L' . (int)$filterLevel : '' ?>
            <a href="/admin/package_edit.php?id=<?= (int)$id ?>" style="margin-left:8px;">Reset</a>
          </span>
        <?php endif; ?>
      </p>

	      <div class="distribution-grid" style="margin-top:10px;">
	        <?php foreach (['PONE', 'PHM', 'PPM'] as $need): ?>
          <?php if (!isset($allowedByNeed[$need])) continue; ?>
	          <div class="distribution-card distribution-card-clickable"
	               data-filter-need-url="/admin/package_edit.php?id=<?= (int)$id ?>&need=<?= urlencode($need) ?>"
               role="link"
               tabindex="0"
               aria-label="Filtrer sur <?= h($need) ?>">
            <p class="distribution-need">
              <a class="distribution-need-link <?= $filterNeed === $need && $filterLevel === 0 ? 'is-active' : '' ?>"
                 href="/admin/package_edit.php?id=<?= (int)$id ?>&need=<?= urlencode($need) ?>">
                <?= h($need) ?>
              </a>
            </p>
            <div class="distribution-levels">
              <?php for ($lv = 1; $lv <= 3; $lv++): ?>
                <a class="distribution-chip distribution-chip-link <?= $filterNeed === $need && $filterLevel === $lv ? 'is-active' : '' ?>"
                   href="/admin/package_edit.php?id=<?= (int)$id ?>&need=<?= urlencode($need) ?>&level=<?= (int)$lv ?>">
                  L<?= $lv ?> <b><?= (int)$dist[$need][$lv] ?></b>
                </a>
              <?php endfor; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <p class="sub sessions-meta">Page <?= (int)$page ?> / <?= (int)$totalPages ?> (<?= (int)$totalQuestions ?> question(s))</p>

      <div class="table-wrap" style="margin-top:14px;">
        <?php if (!$questions): ?>
          <p class="empty-state">Aucune question dans la banque pour ce filtre.</p>
        <?php else: ?>
          <table class="table questions-table package-questions-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Énoncé</th>
	                <th>Connaissances requises</th>
	                <th>Niveau</th>
                <th>Type</th>
                <th>Options</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($questions as $q): ?>
                <?php
                  $text = trim((string)$q['text']);
                  if (mb_strlen($text, 'UTF-8') > 110) {
                    $text = mb_substr($text, 0, 110, 'UTF-8') . '...';
                  }
                ?>
                <tr>
                  <td><?= (int)$q['id'] ?></td>
                  <td><?= h($text) ?></td>
	                  <td><?= h((string)($q['need'] ?? 'PONE')) ?></td>
                  <td><?= (int)($q['level'] ?? 1) ?></td>
                  <td>
                    <?php
                      $qt = (string)($q['question_type'] ?? 'MULTI');
                      echo h($qt === 'TRUE_FALSE' ? 'Vrai / Faux' : 'Choix multiple');
                    ?>
                  </td>
                  <td><?= (int)($q['option_count'] ?? 0) ?></td>
                  <td class="actions-cell">
                    <a class="btn ghost" href="/admin/question_edit.php?id=<?= (int)$q['id'] ?>">Modifier</a>
                    <a class="btn ghost icon-btn danger" href="/admin/question_delete.php?id=<?= (int)$q['id'] ?>"
                       aria-label="Supprimer cette question"
                       title="Supprimer"
                       onclick="return confirm('Supprimer cette question ?');">
                      <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                      </svg>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <?php
        $qs = $_GET;
        unset($qs['page']);
        $qs['id'] = (int)$id;
        $base = '/admin/package_edit.php';
        $common = '?' . http_build_query($qs);
      ?>
      <div class="sessions-pagination">
        <?php if ($page > 1): ?>
          <a class="btn ghost" href="<?= h($base . $common . '&page=' . ($page - 1)) ?>">&larr;</a>
        <?php else: ?>
          <button class="btn ghost" disabled>&larr;</button>
        <?php endif; ?>

        <?php if ($totalPages <= 7): ?>
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . '&page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
        <?php else: ?>
          <a class="btn <?= $page === 1 ? '' : 'ghost' ?>" href="<?= h($base . $common . '&page=1') ?>">1</a>

          <?php if ($page <= 4): ?>
            <?php for ($p = 2; $p <= 5; $p++): ?>
              <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . '&page=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php elseif ($page >= ($totalPages - 3)): ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php for ($p = $totalPages - 4; $p <= $totalPages - 1; $p++): ?>
              <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . '&page=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
          <?php else: ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php for ($p = $page - 1; $p <= $page + 1; $p++): ?>
              <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . '&page=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php endif; ?>

          <a class="btn <?= $totalPages === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . '&page=' . $totalPages) ?>"><?= (int)$totalPages ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a class="btn ghost" href="<?= h($base . $common . '&page=' . ($page + 1)) ?>">&rarr;</a>
        <?php else: ?>
          <button class="btn ghost" disabled>&rarr;</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script>
    document.querySelectorAll('.distribution-card-clickable').forEach(function (card) {
      var href = card.getAttribute('data-filter-need-url');
      if (!href) return;

      card.addEventListener('click', function (e) {
        if (e.target.closest('a')) return;
        window.location.href = href;
      });

      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          if (e.target.closest('a')) return;
          e.preventDefault();
          window.location.href = href;
        }
      });
    });
  </script>
</body>
</html>

