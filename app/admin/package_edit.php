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

function package_edit_package_column_exists(PDO $pdo, string $column): bool {
  static $cache = [];
  $key = 'pkg:' . $column;
  if (isset($cache[$key])) {
    return $cache[$key];
  }
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'packages'
      AND COLUMN_NAME = ?
  ");
  $st->execute([$column]);
  $cache[$key] = ((int)$st->fetchColumn() > 0);
  return $cache[$key];
}

function package_rule_templates(): array {
  return [
    'GREEN' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PONE', 'levels' => [2, 3], 'take' => 10],
        ['need' => 'PONE', 'levels' => [1], 'take' => 50, 'target_total' => 50],
      ],
    ],
    'BLUE' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PONE', 'levels' => [2, 3], 'take' => 30],
        ['need' => 'PONE', 'levels' => [1], 'take' => 50, 'target_total' => 50],
      ],
    ],
    'RED' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PHM', 'levels' => [1], 'take' => 40],
        ['need' => 'PONE', 'levels' => [3], 'take' => 3, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [2], 'take' => 3, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10, 'target_total' => 50],
      ],
    ],
    'BLACK' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PHM', 'levels' => [2, 3], 'take' => 30],
        ['need' => 'PHM', 'levels' => [1], 'take' => 40, 'target_total' => 40],
        ['need' => 'PONE', 'levels' => [3], 'take' => 3, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [2], 'take' => 3, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10, 'target_total' => 50],
      ],
    ],
    'SILVER' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PPM', 'levels' => [1], 'take' => 40],
        ['need' => 'PHM', 'levels' => [3], 'take' => 2, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [3], 'take' => 2, 'target_total' => 50],
        ['need' => 'PHM', 'levels' => [2], 'take' => 2, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [2], 'take' => 2, 'target_total' => 50],
        ['need' => 'PHM', 'levels' => [1], 'take' => 5, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10, 'target_total' => 50],
      ],
    ],
    'GOLD' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PPM', 'levels' => [2, 3], 'take' => 30],
        ['need' => 'PPM', 'levels' => [1], 'take' => 40, 'target_total' => 40],
        ['need' => 'PHM', 'levels' => [3], 'take' => 2, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [3], 'take' => 2, 'target_total' => 50],
        ['need' => 'PHM', 'levels' => [2], 'take' => 2, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [2], 'take' => 2, 'target_total' => 50],
        ['need' => 'PHM', 'levels' => [1], 'take' => 5, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10, 'target_total' => 50],
      ],
    ],
  ];
}

function package_rules_to_rows(array $rules): array {
  $rows = [];
  $buckets = $rules['buckets'] ?? [];
  if (!is_array($buckets)) {
    return $rows;
  }
  foreach ($buckets as $bucket) {
    $need = strtoupper(trim((string)($bucket['need'] ?? '')));
    $levels = $bucket['levels'] ?? [];
    $take = (int)($bucket['take'] ?? 0);
    $targetTotal = (int)($bucket['target_total'] ?? 0);
    if (!in_array($need, ['PONE', 'PHM', 'PPM'], true) || !is_array($levels) || $take <= 0) {
      continue;
    }
    $selectedLevels = [];
    foreach ($levels as $lv) {
      $lv = (int)$lv;
      if ($lv >= 1 && $lv <= 3) {
        $selectedLevels[] = $lv;
      }
    }
    $selectedLevels = array_values(array_unique($selectedLevels));
    if (!$selectedLevels) {
      continue;
    }
    sort($selectedLevels);
    $rows[] = [
      'need' => $need,
      'levels' => $selectedLevels,
      'take' => $take,
      'target_total' => $targetTotal > 0 ? $targetTotal : 0,
    ];
  }
  return $rows;
}

function package_rule_rows_from_post(): array {
  $needs = $_POST['rule_need'] ?? [];
  $takes = $_POST['rule_take'] ?? [];
  $targets = $_POST['rule_target_total'] ?? [];
  $level1 = $_POST['rule_level_1'] ?? [];
  $level2 = $_POST['rule_level_2'] ?? [];
  $level3 = $_POST['rule_level_3'] ?? [];

  if (!is_array($needs) || !is_array($takes) || !is_array($targets)) {
    return [];
  }

  $rows = [];
  $total = count($needs);
  for ($i = 0; $i < $total; $i++) {
    $need = strtoupper(trim((string)($needs[$i] ?? '')));
    $take = (int)($takes[$i] ?? 0);
    $targetTotal = (int)($targets[$i] ?? 0);
    $levels = [];

    if ((string)($level1[$i] ?? '') === '1') {
      $levels[] = 1;
    }
    if ((string)($level2[$i] ?? '') === '1') {
      $levels[] = 2;
    }
    if ((string)($level3[$i] ?? '') === '1') {
      $levels[] = 3;
    }

    if (!in_array($need, ['PONE', 'PHM', 'PPM'], true) || $take <= 0 || !$levels) {
      continue;
    }

    $rows[] = [
      'need' => $need,
      'levels' => $levels,
      'take' => $take,
      'target_total' => $targetTotal > 0 ? $targetTotal : 0,
    ];
  }

  return $rows;
}

function package_rule_rows_to_json(array $rows, int $selectionCount): string {
  $buckets = [];
  foreach ($rows as $row) {
    $bucket = [
      'need' => (string)$row['need'],
      'levels' => array_values(array_map('intval', $row['levels'] ?? [])),
      'take' => (int)$row['take'],
    ];
    $targetTotal = (int)($row['target_total'] ?? 0);
    if ($targetTotal > 0) {
      $bucket['target_total'] = $targetTotal;
    }
    $buckets[] = $bucket;
  }

  return (string)json_encode([
    'max' => $selectionCount,
    'buckets' => $buckets,
  ], JSON_UNESCAPED_UNICODE);
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
$hasNameColorColumn = package_edit_package_column_exists($pdo, 'name_color_hex');
$hasRulesColumn = package_edit_package_column_exists($pdo, 'selection_rules_json');
$ruleTemplates = package_rule_templates();
$packNameColor = normalize_hex_color((string)($pk['name_color_hex'] ?? '')) ?? package_color_hex((string)($pk['name'] ?? ''));

if (!$pk) {
  http_response_code(404);
  echo "Not found";
  exit;
}

$selectedTemplate = '';
$packNameUpper = strtoupper(trim((string)($pk['name'] ?? '')));
if (isset($ruleTemplates[$packNameUpper])) {
  $selectedTemplate = $packNameUpper;
}

$allowedByNeed = [];
$rulesRaw = trim((string)($pk['selection_rules_json'] ?? ''));
$ruleRows = [];
if ($rulesRaw !== '') {
  $rules = json_decode($rulesRaw, true);
  if (is_array($rules)) {
    $ruleRows = package_rules_to_rows($rules);
    foreach ($ruleRows as $row) {
      $need = (string)$row['need'];
      if (!isset($allowedByNeed[$need])) {
        $allowedByNeed[$need] = [];
      }
      foreach (($row['levels'] ?? []) as $lv) {
        $allowedByNeed[$need][(int)$lv] = true;
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
  $selectedTemplate = strtoupper(trim((string)($_POST['rule_template'] ?? '')));
  if (!isset($ruleTemplates[$selectedTemplate])) {
    $selectedTemplate = '';
  }
  $ruleRows = package_rule_rows_from_post();
  $postedColor = trim((string)($_POST['name_color_hex'] ?? ''));
  $normalizedColor = normalize_hex_color($postedColor);
  if ($normalizedColor !== null) {
    $packNameColor = $normalizedColor;
  }

  if ($threshold < 0 || $threshold > 100) {
    $error = "Seuil invalide";
  } elseif ($duration < 1 || $duration > 600) {
    $error = "Duree invalide";
  } elseif ($count < 1 || $count > 200) {
    $error = "Nombre de questions invalide";
  } elseif ($hasRulesColumn && !empty($ruleRows) && count($ruleRows) > 20) {
    $error = "Maximum 20 paliers de regles.";
  } elseif ($hasNameColorColumn && $normalizedColor === null) {
    $error = "Couleur invalide";
  } else {
    $selectionRulesJson = null;
    if ($hasRulesColumn && !empty($ruleRows)) {
      $selectionRulesJson = package_rule_rows_to_json($ruleRows, $count);
      if ($selectionRulesJson === '') {
        $error = "Regles de tirage invalides.";
      }
    }

    if ($error !== '') {
      // Keep form state and display error.
    } elseif ($hasNameColorColumn && $hasRulesColumn) {
      $update = $pdo->prepare("
        UPDATE packages
        SET pass_threshold_percent=?,
            duration_limit_minutes=?,
            selection_count=?,
            name_color_hex=?,
            selection_rules_json=?
        WHERE id=?
      ");
      $update->execute([$threshold, $duration, $count, $packNameColor, $selectionRulesJson, $id]);
    } elseif ($hasNameColorColumn) {
      $update = $pdo->prepare("
        UPDATE packages
        SET pass_threshold_percent=?,
            duration_limit_minutes=?,
            selection_count=?,
            name_color_hex=?
        WHERE id=?
      ");
      $update->execute([$threshold, $duration, $count, $packNameColor, $id]);
    } elseif ($hasRulesColumn) {
      $update = $pdo->prepare("
        UPDATE packages
        SET pass_threshold_percent=?,
            duration_limit_minutes=?,
            selection_count=?,
            selection_rules_json=?
        WHERE id=?
      ");
      $update->execute([$threshold, $duration, $count, $selectionRulesJson, $id]);
    } else {
      $update = $pdo->prepare("
        UPDATE packages
        SET pass_threshold_percent=?,
            duration_limit_minutes=?,
            selection_count=?
        WHERE id=?
      ");
      $update->execute([$threshold, $duration, $count, $id]);
    }

    if ($error === '') {
      header("Location: /admin/packages.php");
      exit;
    }
  }
}

$formThreshold = isset($threshold) ? $threshold : (int)$pk['pass_threshold_percent'];
$formDuration = isset($duration) ? $duration : (int)$pk['duration_limit_minutes'];
$formCount = isset($count) ? $count : (int)$pk['selection_count'];
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Modifier pack</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1" defer></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Modifier pack</h2>
          <p class="sub"><span style="<?= h(package_label_style((string)$pk['name'], $packNameColor)) ?>"><?= h($pk['name']) ?></span></p>
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
              value="<?= (int)$formThreshold ?>"
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
              value="<?= (int)$formDuration ?>"
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
              value="<?= (int)$formCount ?>"
              required
            >
          </div>
          <?php if ($hasNameColorColumn): ?>
            <div>
              <label class="label">Couleur du nom</label>
              <input
                class="input"
                type="color"
                name="name_color_hex"
                value="<?= h($packNameColor) ?>"
              >
            </div>
          <?php endif; ?>
        </div>

        <?php if ($hasRulesColumn): ?>
          <div style="margin-top:16px; border:1px solid var(--line); border-radius:14px; padding:12px;">
            <h3 class="distribution-title" style="margin:0 0 6px;">Regles de tirage des questions</h3>
            <p class="small" style="margin:0 0 10px;">
              Definis l'ordre des paliers. Chaque ligne prend "jusqu'a X" questions. La colonne "Cible cumulee" permet de stopper un palier quand le total atteint la cible.
            </p>

            <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-bottom:10px;">
              <div>
                <label class="label" for="rule-template">Modele</label>
                <select class="input" id="rule-template" name="rule_template">
                  <option value="">Aucun</option>
                  <?php foreach (array_keys($ruleTemplates) as $tplName): ?>
                    <option value="<?= h($tplName) ?>" <?= $selectedTemplate === $tplName ? 'selected' : '' ?>><?= h($tplName) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn ghost" type="button" id="apply-rule-template">Appliquer le modele</button>
              <button class="btn ghost" type="button" id="add-rule-row">+ Ajouter un palier</button>
            </div>

            <div style="overflow:auto;">
              <table class="table questions-table" id="rule-rows-table">
                <thead>
                  <tr>
                    <th>Need</th>
                    <th>Niveaux</th>
                    <th>Prendre (max)</th>
                    <th>Cible cumulee</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="rule-rows-body">
                  <?php foreach ($ruleRows as $row): ?>
                    <?php
                      $levelsMap = [];
                      foreach (($row['levels'] ?? []) as $lv) {
                        $levelsMap[(int)$lv] = true;
                      }
                    ?>
                    <tr class="rule-row">
                      <td>
                        <select class="input rule-need" name="rule_need[]">
                          <?php foreach (['PONE', 'PHM', 'PPM'] as $needOpt): ?>
                            <option value="<?= h($needOpt) ?>" <?= ($row['need'] ?? '') === $needOpt ? 'selected' : '' ?>><?= h($needOpt) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td style="white-space:nowrap;">
                        <input type="hidden" class="rule-level-1-input" value="<?= !empty($levelsMap[1]) ? '1' : '0' ?>">
                        <label style="margin-right:8px;"><input type="checkbox" class="rule-level-1-check" <?= !empty($levelsMap[1]) ? 'checked' : '' ?>> L1</label>
                        <input type="hidden" class="rule-level-2-input" value="<?= !empty($levelsMap[2]) ? '1' : '0' ?>">
                        <label style="margin-right:8px;"><input type="checkbox" class="rule-level-2-check" <?= !empty($levelsMap[2]) ? 'checked' : '' ?>> L2</label>
                        <input type="hidden" class="rule-level-3-input" value="<?= !empty($levelsMap[3]) ? '1' : '0' ?>">
                        <label><input type="checkbox" class="rule-level-3-check" <?= !empty($levelsMap[3]) ? 'checked' : '' ?>> L3</label>
                      </td>
                      <td><input class="input rule-take" type="number" name="rule_take[]" min="1" max="200" value="<?= (int)($row['take'] ?? 0) ?>"></td>
                      <td><input class="input rule-target-total" type="number" name="rule_target_total[]" min="0" max="200" value="<?= (int)($row['target_total'] ?? 0) ?>"></td>
                      <td><button class="btn ghost rule-remove" type="button">Supprimer</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <div style="margin-top:14px; display:flex; gap:10px;">
          <button class="btn" type="submit">Enregistrer</button>
          <a class="btn ghost" href="/admin/packages.php">Annuler</a>
        </div>
      </form>

      <hr class="separator">

      <h3 class="distribution-title">Questions du pack</h3>
      <div style="margin: 0 0 10px; display:flex; gap:10px; flex-wrap:wrap;">
        <a class="btn ghost" href="/admin/import_questions.php">Importer questions</a>
      </div>
      <p class="small">
        Répartition par connaissances requises et niveau (banque globale, utilisée pour le tirage de ce pack).
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
  (function () {
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

    <?php if ($hasRulesColumn): ?>
    var ruleTemplates = <?= json_encode($ruleTemplates, JSON_UNESCAPED_UNICODE) ?>;
    var tbody = document.getElementById('rule-rows-body');
    var addRowBtn = document.getElementById('add-rule-row');
    var applyTemplateBtn = document.getElementById('apply-rule-template');
    var templateSelect = document.getElementById('rule-template');
    var countInput = document.querySelector('input[name="selection_count"]');
    var form = document.querySelector('form[method="post"]');

    function bindRowActions(row) {
      var removeBtn = row.querySelector('.rule-remove');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          row.remove();
        });
      }
    }

    function buildRowHtml(data) {
      var need = data.need || 'PONE';
      var take = data.take || 1;
      var targetTotal = data.target_total || 0;
      var levels = Array.isArray(data.levels) ? data.levels : [1];
      var hasL1 = levels.indexOf(1) !== -1;
      var hasL2 = levels.indexOf(2) !== -1;
      var hasL3 = levels.indexOf(3) !== -1;

      return '' +
        '<tr class="rule-row">' +
          '<td>' +
            '<select class="input rule-need">' +
              '<option value="PONE"' + (need === 'PONE' ? ' selected' : '') + '>PONE</option>' +
              '<option value="PHM"' + (need === 'PHM' ? ' selected' : '') + '>PHM</option>' +
              '<option value="PPM"' + (need === 'PPM' ? ' selected' : '') + '>PPM</option>' +
            '</select>' +
          '</td>' +
          '<td style="white-space:nowrap;">' +
            '<input type="hidden" class="rule-level-1-input" value="' + (hasL1 ? '1' : '0') + '">' +
            '<label style="margin-right:8px;"><input type="checkbox" class="rule-level-1-check"' + (hasL1 ? ' checked' : '') + '> L1</label>' +
            '<input type="hidden" class="rule-level-2-input" value="' + (hasL2 ? '1' : '0') + '">' +
            '<label style="margin-right:8px;"><input type="checkbox" class="rule-level-2-check"' + (hasL2 ? ' checked' : '') + '> L2</label>' +
            '<input type="hidden" class="rule-level-3-input" value="' + (hasL3 ? '1' : '0') + '">' +
            '<label><input type="checkbox" class="rule-level-3-check"' + (hasL3 ? ' checked' : '') + '> L3</label>' +
          '</td>' +
          '<td><input class="input rule-take" type="number" min="1" max="200" value="' + take + '"></td>' +
          '<td><input class="input rule-target-total" type="number" min="0" max="200" value="' + targetTotal + '"></td>' +
          '<td><button class="btn ghost rule-remove" type="button">Supprimer</button></td>' +
        '</tr>';
    }

    function addRuleRow(data) {
      if (!tbody) return;
      var wrap = document.createElement('tbody');
      wrap.innerHTML = buildRowHtml(data || {});
      var row = wrap.firstElementChild;
      tbody.appendChild(row);
      bindRowActions(row);
    }

    function syncRuleInputNames() {
      if (!tbody) return;
      var rows = tbody.querySelectorAll('.rule-row');
      rows.forEach(function (row, idx) {
        var need = row.querySelector('.rule-need');
        var take = row.querySelector('.rule-take');
        var target = row.querySelector('.rule-target-total');
        var l1Input = row.querySelector('.rule-level-1-input');
        var l2Input = row.querySelector('.rule-level-2-input');
        var l3Input = row.querySelector('.rule-level-3-input');
        var l1Check = row.querySelector('.rule-level-1-check');
        var l2Check = row.querySelector('.rule-level-2-check');
        var l3Check = row.querySelector('.rule-level-3-check');

        if (need) need.name = 'rule_need[' + idx + ']';
        if (take) take.name = 'rule_take[' + idx + ']';
        if (target) target.name = 'rule_target_total[' + idx + ']';
        if (l1Input) {
          l1Input.name = 'rule_level_1[' + idx + ']';
          l1Input.value = (l1Check && l1Check.checked) ? '1' : '0';
        }
        if (l2Input) {
          l2Input.name = 'rule_level_2[' + idx + ']';
          l2Input.value = (l2Check && l2Check.checked) ? '1' : '0';
        }
        if (l3Input) {
          l3Input.name = 'rule_level_3[' + idx + ']';
          l3Input.value = (l3Check && l3Check.checked) ? '1' : '0';
        }
      });
    }

    if (tbody) {
      tbody.querySelectorAll('.rule-row').forEach(bindRowActions);
      syncRuleInputNames();
    }

    if (addRowBtn) {
      addRowBtn.addEventListener('click', function () {
        addRuleRow({ need: 'PONE', levels: [1], take: 1, target_total: 0 });
      });
    }

    if (applyTemplateBtn) {
      applyTemplateBtn.addEventListener('click', function () {
        var key = templateSelect ? templateSelect.value : '';
        if (!key || !ruleTemplates[key] || !Array.isArray(ruleTemplates[key].buckets) || !tbody) return;

        tbody.innerHTML = '';
        ruleTemplates[key].buckets.forEach(function (bucket) {
          addRuleRow(bucket);
        });
        if (countInput && ruleTemplates[key].max) {
          countInput.value = String(ruleTemplates[key].max);
        }
        syncRuleInputNames();
      });
    }

    if (form) {
      form.addEventListener('submit', function () {
        syncRuleInputNames();
      });
    }
    <?php endif; ?>
  })();
  </script>
</body>
</html>

