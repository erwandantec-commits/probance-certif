<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_nav.php';

$pdo = db();

$packages = $pdo->query("SELECT id, name FROM packages ORDER BY id DESC")->fetchAll();
$pkg = (int)($_GET['package_id'] ?? 0);

$need = strtoupper(trim((string)($_GET['need'] ?? '')));
$level = (int)($_GET['level'] ?? 0);
$activeNeed = in_array($need, ['PONE', 'PHM', 'PPM'], true) ? $need : '';
$activeLevel = ($level >= 1 && $level <= 3) ? $level : 0;

$params = [];
$conds = [];

if ($pkg > 0) { $conds[] = "q.package_id=?"; $params[] = $pkg; }
if (in_array($need, ['PONE', 'PHM', 'PPM'], true)) { $conds[] = "q.need=?"; $params[] = $need; }
if ($level >= 1 && $level <= 3) { $conds[] = "q.level=?"; $params[] = $level; }

$where = $conds ? ("WHERE " . implode(" AND ", $conds)) : "";

$limit = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM questions q $where");
$countStmt->execute($params);
$totalQuestions = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalQuestions / $limit));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
  SELECT
    q.id, q.text, q.need, q.level, q.question_type, q.allow_skip,
    COALESCE(p.name, '(Banque globale)') AS package_name,
    (SELECT COUNT(*) FROM question_options qo WHERE qo.question_id=q.id) AS opt_count
  FROM questions q
  LEFT JOIN packages p ON p.id=q.package_id
  $where
  ORDER BY q.id DESC
  LIMIT ? OFFSET ?
");

$i = 1;
foreach ($params as $v) {
  $stmt->bindValue($i++, $v);
}
$stmt->bindValue($i++, (int)$limit, PDO::PARAM_INT);
$stmt->bindValue($i++, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$questions = $stmt->fetchAll();

$distStmt = $pdo->query("
  SELECT need, level, COUNT(*) c
  FROM questions
  GROUP BY need, level
");

$distribution = [];
foreach ($distStmt->fetchAll() as $row) {
  $distribution[$row['need']][(int)$row['level']] = (int)$row['c'];
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function package_label_style_local(string $packageName): string {
  $name = strtoupper(trim($packageName));
  $color = match ($name) {
    'GREEN' => '#16a34a',
    'BLUE' => '#2563eb',
    'RED' => '#dc2626',
    'BLACK' => '#111827',
    'SILVER' => '#64748b',
    'VERMEIL' => '#b45309',
    default => '#334155',
  };
  return 'color:' . $color . ';font-weight:700;';
}

function questions_filter_url(int $pkg, string $need = '', int $level = 0): string {
  $params = [];
  if ($pkg > 0) {
    $params['package_id'] = $pkg;
  }
  if (in_array($need, ['PONE', 'PHM', 'PPM'], true)) {
    $params['need'] = $need;
  }
  if ($level >= 1 && $level <= 3) {
    $params['level'] = $level;
  }
  return '/admin/questions.php' . ($params ? ('?' . http_build_query($params)) : '');
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Questions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1">Admin &middot; Questions</h2>
        <p class="sub">Ajouter / modifier / supprimer</p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('questions'); ?>
      </div>
    </div>

    <hr class="separator">

    <form method="get" class="filters-grid">
      <div>
        <label class="label" for="package_id">Package</label>
        <select id="package_id" name="package_id">
          <option value="0">Tous</option>
	          <?php foreach ($packages as $p): ?>
	            <option value="<?= (int)$p['id'] ?>" <?= $pkg === (int)$p['id'] ? 'selected' : '' ?> style="<?= h(package_label_style_local((string)$p['name'])) ?>">
	              <?= h($p['name']) ?>
	            </option>
	          <?php endforeach; ?>
        </select>
      </div>

      <div class="filters-actions">
        <button class="btn" type="submit">Filtrer</button>
        <a class="btn ghost" href="/admin/questions.php">Reset</a>
        <a class="btn ghost" href="/admin/question_edit.php?new=1<?= $pkg ? '&package_id=' . $pkg : '' ?>">+ Ajouter</a>
        <a class="btn ghost" href="/admin/import_questions.php<?= $pkg ? '?package_id=' . $pkg : '' ?>">&uarr; Importer</a>
      </div>
    </form>

    <div class="distribution-wrap">
      <p class="distribution-title">R&eacute;partition actuelle</p>
      <div class="distribution-grid">
        <?php foreach (['PONE', 'PHM', 'PPM'] as $n): ?>
          <div class="distribution-card distribution-card-clickable"
               data-filter-need-url="<?= h(questions_filter_url($pkg, $n, 0)) ?>"
               role="link"
               tabindex="0"
               aria-label="Filtrer sur <?= h($n) ?>">
            <p class="distribution-need">
              <a class="distribution-need-link <?= $activeNeed === $n && $activeLevel === 0 ? 'is-active' : '' ?>"
                 href="<?= h(questions_filter_url($pkg, $n, 0)) ?>">
                <?= h($n) ?>
              </a>
            </p>
            <div class="distribution-levels">
              <?php for ($i = 1; $i <= 3; $i++):
                $c = $distribution[$n][$i] ?? 0;
              ?>
                <a class="distribution-chip distribution-chip-link <?= $activeNeed === $n && $activeLevel === $i ? 'is-active' : '' ?>"
                   href="<?= h(questions_filter_url($pkg, $n, $i)) ?>">
                  L<?= $i ?> <b><?= $c ?></b>
                </a>
              <?php endfor; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <p class="sub sessions-meta">Page <?= (int)$page ?> / <?= (int)$totalPages ?> (<?= (int)$totalQuestions ?> question(s))</p>

    <div class="table-wrap">
      <?php if (!$questions): ?>
        <p class="empty-state">Aucune question.</p>
      <?php else: ?>
        <table class="table questions-table questions-admin-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Package</th>
              <th>Need</th>
              <th>Level</th>
              <th>Type</th>
              <th>Options</th>
              <th>&Eacute;nonc&eacute;</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($questions as $q): ?>
              <tr>
                <td><?= (int)$q['id'] ?></td>
	                <td><span style="<?= h(package_label_style_local((string)$q['package_name'])) ?>"><?= h($q['package_name']) ?></span></td>
                <td><?= h($q['need']) ?></td>
                <td><?= (int)$q['level'] ?></td>
                <td>
                  <?= ($q['question_type'] === 'TRUE_FALSE') ? 'Vrai/Faux' : 'Choix multiple' ?>
                </td>
	                <td><?= (int)$q['opt_count'] ?></td>
                <td><?= h(mb_strimwidth((string)$q['text'], 0, 90, '...', 'UTF-8')) ?></td>
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
      $base = '/admin/questions.php';
      $common = $qs ? ('?' . http_build_query($qs)) : '';
      $sep = $common ? '&' : '?';
    ?>
    <div class="sessions-pagination">
      <?php if ($page > 1): ?>
        <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page - 1)) ?>">&larr; Précédent</a>
      <?php else: ?>
        <button class="btn ghost" disabled>&larr; Précédent</button>
      <?php endif; ?>

      <?php if ($page < $totalPages): ?>
        <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page + 1)) ?>">Suivant &rarr;</a>
      <?php else: ?>
        <button class="btn ghost" disabled>Suivant &rarr;</button>
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
<script src="/assets/package-colors.js"></script>
</body>
</html>
