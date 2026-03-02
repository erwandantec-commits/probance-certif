<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_nav.php';

$pdo = db();
$idFilterRaw = trim((string)($_GET['id_question'] ?? ''));
$idFilter = ($idFilterRaw !== '' && preg_match('/^\d+$/', $idFilterRaw)) ? (int)$idFilterRaw : null;

$rawNeeds = $_GET['needs'] ?? [];
if (!is_array($rawNeeds)) {
  $rawNeeds = [$rawNeeds];
}
$activeNeeds = [];
foreach ($rawNeeds as $n) {
  $n = strtoupper(trim((string)$n));
  if (in_array($n, ['PONE', 'PHM', 'PPM'], true)) {
    $activeNeeds[] = $n;
  }
}
$activeNeeds = array_values(array_unique($activeNeeds));

$rawLevels = $_GET['levels'] ?? [];
if (!is_array($rawLevels)) {
  $rawLevels = [$rawLevels];
}
$legacyActiveLevels = [];
foreach ($rawLevels as $lv) {
  $lv = (int)$lv;
  if ($lv >= 1 && $lv <= 3) {
    $legacyActiveLevels[] = $lv;
  }
}
$legacyActiveLevels = array_values(array_unique($legacyActiveLevels));
sort($legacyActiveLevels);

$rawNeedLevels = $_GET['need_levels'] ?? [];
if (!is_array($rawNeedLevels)) {
  $rawNeedLevels = [$rawNeedLevels];
}
$activeNeedLevels = [];
foreach ($rawNeedLevels as $pairRaw) {
  $pair = strtoupper(trim((string)$pairRaw));
  if (preg_match('/^(PONE|PHM|PPM):([1-3])$/', $pair)) {
    $activeNeedLevels[] = $pair;
  }
}
$activeNeedLevels = array_values(array_unique($activeNeedLevels));

// Backward compatibility with old single-value filters.
$legacyNeed = strtoupper(trim((string)($_GET['need'] ?? '')));
if ($legacyNeed !== '' && in_array($legacyNeed, ['PONE', 'PHM', 'PPM'], true) && empty($activeNeeds)) {
  $activeNeeds[] = $legacyNeed;
}
$legacyLevel = (int)($_GET['level'] ?? 0);
if ($legacyLevel >= 1 && $legacyLevel <= 3 && empty($activeNeedLevels)) {
  if ($legacyNeed !== '' && in_array($legacyNeed, ['PONE', 'PHM', 'PPM'], true)) {
    $activeNeedLevels[] = $legacyNeed . ':' . $legacyLevel;
  } else {
    foreach (['PONE', 'PHM', 'PPM'] as $legacyAnyNeed) {
      $activeNeedLevels[] = $legacyAnyNeed . ':' . $legacyLevel;
    }
  }
}
if (empty($activeNeedLevels) && !empty($activeNeeds) && !empty($legacyActiveLevels)) {
  foreach ($activeNeeds as $n) {
    foreach ($legacyActiveLevels as $lv) {
      $activeNeedLevels[] = $n . ':' . $lv;
    }
  }
}
$activeNeedLevels = array_values(array_unique($activeNeedLevels));
$activeNeedLevelMap = [];
foreach ($activeNeedLevels as $pair) {
  [$n, $lv] = explode(':', $pair, 2);
  $activeNeedLevelMap[$n][(int)$lv] = true;
}

$params = [];
$conds = [];

if (!empty($activeNeeds) || !empty($activeNeedLevels)) {
  $orParts = [];
  foreach ($activeNeeds as $need) {
    $orParts[] = "(q.need = ?)";
    $params[] = $need;
  }
  foreach ($activeNeedLevels as $pair) {
    [$pairNeed, $pairLevel] = explode(':', $pair, 2);
    $orParts[] = "(q.need = ? AND q.level = ?)";
    $params[] = $pairNeed;
    $params[] = (int)$pairLevel;
  }
  if (!empty($orParts)) {
    $conds[] = '(' . implode(' OR ', $orParts) . ')';
  }
}
if ($idFilter !== null) {
  $conds[] = '(q.id = ? OR q.external_id = ?)';
  $params[] = $idFilter;
  $params[] = $idFilter;
}

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
    q.id, q.external_id, q.text, q.need, q.level, q.question_type, q.allow_skip,
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

$distribution = [];
$distStmt = $pdo->query("
  SELECT need, level, COUNT(*) c
  FROM questions
  GROUP BY need, level
");
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
    'GOLD' => '#d4af37',
    default => '#334155',
  };
  return 'color:' . $color . ';font-weight:700;';
}

function questions_filter_url(array $needs = [], array $needLevels = [], ?int $idFilter = null): string {
  $params = [];
  $cleanNeeds = [];
  foreach ($needs as $need) {
    $need = strtoupper(trim((string)$need));
    if (in_array($need, ['PONE', 'PHM', 'PPM'], true)) {
      $cleanNeeds[] = $need;
    }
  }
  $cleanNeeds = array_values(array_unique($cleanNeeds));
  if (!empty($cleanNeeds)) {
    $params['needs'] = $cleanNeeds;
  }

  $cleanNeedLevels = [];
  foreach ($needLevels as $pair) {
    $pair = strtoupper(trim((string)$pair));
    if (preg_match('/^(PONE|PHM|PPM):([1-3])$/', $pair)) {
      $cleanNeedLevels[] = $pair;
    }
  }
  $cleanNeedLevels = array_values(array_unique($cleanNeedLevels));
  if (!empty($cleanNeedLevels)) {
    $params['need_levels'] = $cleanNeedLevels;
  }
  if ($idFilter !== null && $idFilter > 0) {
    $params['id_question'] = $idFilter;
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
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1">Admin &middot; Questions</h2>
        <p class="sub">Modifier / supprimer (creation via import uniquement)</p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('questions'); ?>
      </div>
    </div>

    <hr class="separator">

    <div style="display:flex; gap:10px; flex-wrap:wrap; margin: 0 0 8px;">
      <a class="btn admin-primary-action-btn" href="/admin/import_questions.php">+ Importer</a>
    </div>
    <hr class="separator">
    <form method="get" class="filters-grid users-filters" style="margin-bottom:8px;">
      <?php foreach ($activeNeeds as $n): ?>
        <input type="hidden" name="needs[]" value="<?= h($n) ?>">
      <?php endforeach; ?>
      <?php foreach ($activeNeedLevels as $pair): ?>
        <input type="hidden" name="need_levels[]" value="<?= h($pair) ?>">
      <?php endforeach; ?>
      <div>
        <label class="label" for="id_question">ID question</label>
        <input class="input" id="id_question" name="id_question" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="ID interne ou ID question" value="<?= h($idFilterRaw) ?>">
      </div>
      <div class="filters-actions">
        <button class="btn" type="submit">Rechercher</button>
        <a class="btn ghost" href="/admin/questions.php">Reset</a>
      </div>
    </form>

    <div class="distribution-wrap">
      <p class="distribution-title">R&eacute;partition actuelle</p>
      <div class="distribution-grid">
        <?php foreach (['PONE', 'PHM', 'PPM'] as $n): ?>
          <?php
            $needActive = in_array($n, $activeNeeds, true);
            $nextNeeds = $activeNeeds;
            if ($needActive) {
              $nextNeeds = array_values(array_filter($nextNeeds, static fn($v) => $v !== $n));
            } else {
              $nextNeeds[] = $n;
            }
            $nextNeedLevels = $activeNeedLevels;
            if ($needActive) {
              $nextNeedLevels = array_values(array_filter($nextNeedLevels, static fn($pair) => strpos($pair, $n . ':') !== 0));
            }
            $needUrl = questions_filter_url($nextNeeds, $nextNeedLevels, $idFilter);
          ?>
          <div class="distribution-card distribution-card-clickable"
               data-filter-need-url="<?= h($needUrl) ?>"
               role="link"
               tabindex="0"
               aria-label="<?= h(($needActive ? 'Retirer' : 'Ajouter') . ' le filtre ' . $n) ?>">
            <p class="distribution-need">
              <a class="distribution-need-link <?= $needActive ? 'is-active' : '' ?>"
                 href="<?= h($needUrl) ?>">
                <?= h($n) ?>
              </a>
            </p>
            <div class="distribution-levels">
              <?php for ($i = 1; $i <= 3; $i++):
                $c = $distribution[$n][$i] ?? 0;
                $pairKey = $n . ':' . $i;
                $levelActive = !empty($activeNeedLevelMap[$n][$i]);
                $nextNeedLevels = $activeNeedLevels;
                if ($levelActive) {
                  $nextNeedLevels = array_values(array_filter($nextNeedLevels, static fn($pair) => $pair !== $pairKey));
                } else {
                  $nextNeedLevels[] = $pairKey;
                }
                $nextNeedsForLevel = array_values(array_filter($activeNeeds, static fn($needName) => $needName !== $n));
                $levelUrl = questions_filter_url($nextNeedsForLevel, $nextNeedLevels, $idFilter);
              ?>
                <a class="distribution-chip distribution-chip-link <?= $levelActive ? 'is-active' : '' ?>"
                   href="<?= h($levelUrl) ?>">
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
              <th>ID question</th>
              <th>Connaissances requises</th>
              <th>Niveau</th>
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
                <td><?= ($q['external_id'] === null || $q['external_id'] === '') ? '-' : (int)$q['external_id'] ?></td>
                <td><?= h((string)$q['need']) ?></td>
                <td><?= (int)$q['level'] ?></td>
                <td>
                  <?= match ((string)$q['question_type']) {
                    'TRUE_FALSE' => 'Vrai/Faux',
                    'SINGLE' => 'Choix unique',
                    default => 'Choix multiple',
                  } ?>
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
        <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page - 1)) ?>">&larr;</a>
      <?php else: ?>
        <button class="btn ghost" disabled>&larr;</button>
      <?php endif; ?>

      <?php if ($totalPages <= 7): ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
        <?php endfor; ?>
      <?php else: ?>
        <a class="btn <?= $page === 1 ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=1') ?>">1</a>

        <?php if ($page <= 4): ?>
          <?php for ($p = 2; $p <= 5; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
          <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
        <?php elseif ($page >= ($totalPages - 3)): ?>
          <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php for ($p = $totalPages - 4; $p <= $totalPages - 1; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
        <?php else: ?>
          <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php for ($p = $page - 1; $p <= $page + 1; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
          <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
        <?php endif; ?>

        <a class="btn <?= $totalPages === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $totalPages) ?>"><?= (int)$totalPages ?></a>
      <?php endif; ?>

      <?php if ($page < $totalPages): ?>
        <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page + 1)) ?>">&rarr;</a>
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
<script src="/assets/package-colors.js"></script>
</body>
</html>

