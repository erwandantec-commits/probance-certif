<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin_area();
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
$activeProgramId = auth_admin_program_context($pdo, $adminUser, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
$hasPackageProgramColumn = table_column_exists($pdo, 'packages', 'program_id');
$hasProgramQuestionLinksTable = auth_table_exists($pdo, 'program_question_links');
$idFilterRaw = trim((string)($_GET['id_question'] ?? ''));
$idFilter = ($idFilterRaw !== '' && preg_match('/^\d+$/', $idFilterRaw)) ? (int)$idFilterRaw : null;

$rawNeeds = $_GET['needs'] ?? [];
if (!is_array($rawNeeds)) {
  $rawNeeds = [$rawNeeds];
}
$activeNeeds = [];
foreach ($rawNeeds as $n) {
  $n = normalize_question_need((string)$n);
  if ($n !== '') {
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
  $pair = trim((string)$pairRaw);
  if (preg_match('/^(.+):([1-3])$/', $pair, $matches)) {
    $need = normalize_question_need($matches[1]);
    if ($need !== '') {
      $activeNeedLevels[] = $need . ':' . (int)$matches[2];
    }
  }
}
$activeNeedLevels = array_values(array_unique($activeNeedLevels));

// Backward compatibility with old single-value filters.
$legacyNeed = normalize_question_need((string)($_GET['need'] ?? ''));
if ($legacyNeed !== '' && empty($activeNeeds)) {
  $activeNeeds[] = $legacyNeed;
}
$legacyLevel = (int)($_GET['level'] ?? 0);
if ($legacyLevel >= 1 && $legacyLevel <= 3 && empty($activeNeedLevels)) {
  if ($legacyNeed !== '') {
    $activeNeedLevels[] = $legacyNeed . ':' . $legacyLevel;
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
  $conds[] = 'q.external_id = ?';
  $params[] = $idFilter;
}
if ($activeProgramId > 0 && $hasProgramQuestionLinksTable) {
  $conds[] = 'EXISTS (
    SELECT 1
    FROM program_question_links pql
    WHERE pql.question_id = q.id
      AND pql.program_id = ?
  )';
  $params[] = $activeProgramId;
}

$where = $conds ? ("WHERE " . implode(" AND ", $conds)) : "";

$limit = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$packageWhereSql = $activeProgramId > 0
  ? ('WHERE ' . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false))
  : '';
$packages = $pdo->query("
  SELECT pk.id, pk.name, pk.name_color_hex, pk.selection_rules_json
  FROM packages pk
  $packageWhereSql
  ORDER BY pk.name ASC
")->fetchAll() ?: [];

function questions_package_usage_definitions(array $packages): array {
  $definitions = [];
  foreach ($packages as $pkg) {
    $packageId = (int)($pkg['id'] ?? 0);
    if ($packageId <= 0) {
      continue;
    }
    $definition = [
      'id' => $packageId,
      'name' => (string)($pkg['name'] ?? ''),
      'color' => (string)($pkg['name_color_hex'] ?? ''),
      'mode' => 'legacy',
      'rules' => [],
    ];
    $rawRules = trim((string)($pkg['selection_rules_json'] ?? ''));
    if ($rawRules !== '') {
      $decoded = json_decode($rawRules, true);
      $buckets = is_array($decoded) ? ($decoded['buckets'] ?? null) : null;
      if (is_array($buckets) && !empty($buckets)) {
        $compiledRules = [];
        foreach ($buckets as $bucket) {
          $need = normalize_question_need((string)($bucket['need'] ?? ''));
          $levels = $bucket['levels'] ?? [];
          if ($need === '' || !is_array($levels)) {
            continue;
          }
          $levels = array_values(array_unique(array_filter(array_map('intval', $levels), static fn(int $level): bool => $level >= 1 && $level <= 9)));
          if (!$levels) {
            continue;
          }
          $compiledRules[] = [
            'need' => $need,
            'levels' => $levels,
          ];
        }
        if ($compiledRules) {
          $definition['mode'] = 'rules';
          $definition['rules'] = $compiledRules;
        }
      }
    }
    $definitions[] = $definition;
  }
  return $definitions;
}

function questions_packages_for_question(array $question, array $packageDefinitions): array {
  $matches = [];
  $questionNeed = normalize_question_need((string)($question['need'] ?? ''));
  $questionLevel = (int)($question['level'] ?? 0);
  $questionPackageId = (int)($question['package_id'] ?? 0);
  foreach ($packageDefinitions as $definition) {
    if (($definition['mode'] ?? 'legacy') === 'rules') {
      foreach (($definition['rules'] ?? []) as $rule) {
        if ($questionNeed === (string)($rule['need'] ?? '') && in_array($questionLevel, $rule['levels'] ?? [], true)) {
          $matches[] = $definition;
          break;
        }
      }
      continue;
    }
    if ($questionPackageId > 0 && $questionPackageId === (int)$definition['id']) {
      $matches[] = $definition;
    }
  }
  return $matches;
}

$packageDefinitions = questions_package_usage_definitions($packages);

$stmt = $pdo->prepare("
  SELECT
    q.id, q.external_id, q.text, q.need, q.level, q.question_type, q.allow_skip, q.package_id,
    (SELECT COUNT(*) FROM question_options qo WHERE qo.question_id=q.id) AS opt_count
  FROM questions q
  $where
  ORDER BY q.id DESC
");
$i = 1;
foreach ($params as $v) {
  $stmt->bindValue($i++, $v);
}
$stmt->execute();
$questionRows = $stmt->fetchAll() ?: [];

$allFilteredQuestions = [];
foreach ($questionRows as $row) {
  $usedPackages = questions_packages_for_question($row, $packageDefinitions);
  $row['_used_packages'] = $usedPackages;
  $allFilteredQuestions[] = $row;
}

$totalQuestions = count($allFilteredQuestions);
$totalPages = max(1, (int)ceil($totalQuestions / $limit));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $limit;
$questions = array_slice($allFilteredQuestions, $offset, $limit);

$distribution = [];
foreach ($allFilteredQuestions as $row) {
  $need = normalize_question_need((string)($row['need'] ?? ''));
  if ($need === '') {
    continue;
  }
  $level = (int)($row['level'] ?? 0);
  $distribution[$need][$level] = (int)($distribution[$need][$level] ?? 0) + 1;
}
$allNeeds = question_known_needs($pdo, array_keys($distribution));

function package_label_style_local(string $packageName): string {
  $name = strtoupper(trim($packageName));
  if ($name === 'BLACK') {
    return 'color:var(--pack-black-text,#111827);font-weight:700;';
  }
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
  $programId = (int)($_GET['program_id'] ?? 0);
  if ($programId > 0) {
    $params['program_id'] = $programId;
  }
  $cleanNeeds = [];
  foreach ($needs as $need) {
    $need = normalize_question_need((string)$need);
    if ($need !== '') {
      $cleanNeeds[] = $need;
    }
  }
  $cleanNeeds = array_values(array_unique($cleanNeeds));
  if (!empty($cleanNeeds)) {
    $params['needs'] = $cleanNeeds;
  }

  $cleanNeedLevels = [];
  foreach ($needLevels as $pair) {
    $pair = trim((string)$pair);
    if (preg_match('/^(.+):([1-3])$/', $pair, $matches)) {
      $need = normalize_question_need($matches[1]);
      if ($need !== '') {
        $cleanNeedLevels[] = $need . ':' . (int)$matches[2];
      }
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

function questions_export_url(array $query): string {
  unset($query['page']);
  $query['export'] = '1';
  return '/admin/questions.php?' . http_build_query($query);
}

if (isset($_GET['export']) && $_GET['export'] === '1') {
  $exportStmt = $pdo->prepare("
    SELECT
      q.external_id AS export_id,
      q.text AS question_text,
      MAX(CASE WHEN qo.label = 'A' THEN qo.option_text END) AS response_1,
      MAX(CASE WHEN qo.label = 'B' THEN qo.option_text END) AS response_2,
      MAX(CASE WHEN qo.label = 'C' THEN qo.option_text END) AS response_3,
      MAX(CASE WHEN qo.label = 'D' THEN qo.option_text END) AS response_4,
      MAX(CASE WHEN qo.label = 'E' THEN qo.option_text END) AS response_5,
      MAX(CASE WHEN qo.label = 'F' THEN qo.option_text END) AS response_6,
      q.explanation,
      COALESCE(NULLIF(q.knowledge_required_csv, ''), q.need, '') AS category_export,
      q.theme,
      q.level AS question_level,
      q.need AS question_need,
      q.package_id AS question_package_id,
      (
        SELECT qt.question_text
        FROM question_translations qt
        WHERE qt.question_id = q.id AND qt.lang = 'en'
        LIMIT 1
      ) AS question_text_en,
      (
        SELECT qt.explanation
        FROM question_translations qt
        WHERE qt.question_id = q.id AND qt.lang = 'en'
        LIMIT 1
      ) AS explanation_en,
      (
        SELECT qt.question_text
        FROM question_translations qt
        WHERE qt.question_id = q.id AND qt.lang = 'es'
        LIMIT 1
      ) AS question_text_es,
      (
        SELECT qt.explanation
        FROM question_translations qt
        WHERE qt.question_id = q.id AND qt.lang = 'es'
        LIMIT 1
      ) AS explanation_es,
      (
        SELECT qt.question_text
        FROM question_translations qt
        WHERE qt.question_id = q.id AND qt.lang = 'jp'
        LIMIT 1
      ) AS question_text_jp,
      (
        SELECT qt.explanation
        FROM question_translations qt
        WHERE qt.question_id = q.id AND qt.lang = 'jp'
        LIMIT 1
      ) AS explanation_jp,
      MAX(CASE WHEN qo.label = 'A' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'en'
        LIMIT 1
      ) END) AS response_1_en,
      MAX(CASE WHEN qo.label = 'B' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'en'
        LIMIT 1
      ) END) AS response_2_en,
      MAX(CASE WHEN qo.label = 'C' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'en'
        LIMIT 1
      ) END) AS response_3_en,
      MAX(CASE WHEN qo.label = 'D' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'en'
        LIMIT 1
      ) END) AS response_4_en,
      MAX(CASE WHEN qo.label = 'E' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'en'
        LIMIT 1
      ) END) AS response_5_en,
      MAX(CASE WHEN qo.label = 'F' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'en'
        LIMIT 1
      ) END) AS response_6_en,
      MAX(CASE WHEN qo.label = 'A' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'es'
        LIMIT 1
      ) END) AS response_1_es,
      MAX(CASE WHEN qo.label = 'B' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'es'
        LIMIT 1
      ) END) AS response_2_es,
      MAX(CASE WHEN qo.label = 'C' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'es'
        LIMIT 1
      ) END) AS response_3_es,
      MAX(CASE WHEN qo.label = 'D' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'es'
        LIMIT 1
      ) END) AS response_4_es,
      MAX(CASE WHEN qo.label = 'E' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'es'
        LIMIT 1
      ) END) AS response_5_es,
      MAX(CASE WHEN qo.label = 'F' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'es'
        LIMIT 1
      ) END) AS response_6_es,
      MAX(CASE WHEN qo.label = 'A' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'jp'
        LIMIT 1
      ) END) AS response_1_jp,
      MAX(CASE WHEN qo.label = 'B' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'jp'
        LIMIT 1
      ) END) AS response_2_jp,
      MAX(CASE WHEN qo.label = 'C' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'jp'
        LIMIT 1
      ) END) AS response_3_jp,
      MAX(CASE WHEN qo.label = 'D' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'jp'
        LIMIT 1
      ) END) AS response_4_jp,
      MAX(CASE WHEN qo.label = 'E' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'jp'
        LIMIT 1
      ) END) AS response_5_jp,
      MAX(CASE WHEN qo.label = 'F' THEN (
        SELECT qot.option_text
        FROM question_option_translations qot
        WHERE qot.option_id = qo.id AND qot.lang = 'jp'
        LIMIT 1
      ) END) AS response_6_jp,
      GROUP_CONCAT(
        CASE
          WHEN qo.is_correct = 1 THEN
            CASE qo.label
              WHEN 'A' THEN '1'
              WHEN 'B' THEN '2'
              WHEN 'C' THEN '3'
              WHEN 'D' THEN '4'
              WHEN 'E' THEN '5'
              WHEN 'F' THEN '6'
              ELSE qo.label
            END
          ELSE NULL
        END
        ORDER BY qo.label ASC
        SEPARATOR ';'
      ) AS correct_answers,
      q.meta_json,
      q.open_to_client
    FROM questions q
    LEFT JOIN question_options qo ON qo.question_id = q.id
    $where
    GROUP BY
      q.id,
      q.external_id,
      q.text,
      q.explanation,
      q.knowledge_required_csv,
      q.need,
      q.theme,
      q.level,
      q.meta_json,
      q.open_to_client
    ORDER BY q.id DESC
  ");

  $i = 1;
  foreach ($params as $v) {
    $exportStmt->bindValue($i++, $v);
  }
  $exportStmt->execute();

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="questions_export.csv"');

  $columns = [
    'ID',
    'Questions',
    'Reponse 1',
    'Reponse 2',
    'Reponse 3',
    'Reponse 4',
    'Reponse 5',
    'Reponse 6',
    'Explication',
    'Categorie',
    'Theme',
    'Niveau question',
    'Bonnes reponses',
    'Utilisateur Probance',
    'Utilisateur Brainpad',
    'Ouvert au client',
    'Questions EN',
    'Reponse 1 EN',
    'Reponse 2 EN',
    'Reponse 3 EN',
    'Reponse 4 EN',
    'Reponse 5 EN',
    'Reponse 6 EN',
    'Explication EN',
    'Questions ES',
    'Reponse 1 ES',
    'Reponse 2 ES',
    'Reponse 3 ES',
    'Reponse 4 ES',
    'Reponse 5 ES',
    'Reponse 6 ES',
    'Explication ES',
    'Questions JP',
    'Reponse 1 JP',
    'Reponse 2 JP',
    'Reponse 3 JP',
    'Reponse 4 JP',
    'Reponse 5 JP',
    'Reponse 6 JP',
    'Explication JP',
  ];

  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, $columns);

  while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
    $meta = json_decode((string)($row['meta_json'] ?? ''), true);
    if (!is_array($meta)) {
      $meta = [];
    }

    $line = [
      $row['export_id'] ?? '',
      $row['question_text'] ?? '',
      $row['response_1'] ?? '',
      $row['response_2'] ?? '',
      $row['response_3'] ?? '',
      $row['response_4'] ?? '',
      $row['response_5'] ?? '',
      $row['response_6'] ?? '',
      $row['explanation'] ?? '',
      $row['category_export'] ?? '',
      $row['theme'] ?? '',
      $row['question_level'] ?? '',
      $row['correct_answers'] ?? '',
      (string)($meta['User Probance'] ?? ''),
      (string)($meta['User Brainpad'] ?? ''),
      ((int)($row['open_to_client'] ?? 0) === 1) ? 'TRUE' : 'FALSE',
      $row['question_text_en'] ?? '',
      $row['response_1_en'] ?? '',
      $row['response_2_en'] ?? '',
      $row['response_3_en'] ?? '',
      $row['response_4_en'] ?? '',
      $row['response_5_en'] ?? '',
      $row['response_6_en'] ?? '',
      $row['explanation_en'] ?? '',
      $row['question_text_es'] ?? '',
      $row['response_1_es'] ?? '',
      $row['response_2_es'] ?? '',
      $row['response_3_es'] ?? '',
      $row['response_4_es'] ?? '',
      $row['response_5_es'] ?? '',
      $row['response_6_es'] ?? '',
      $row['explanation_es'] ?? '',
      $row['question_text_jp'] ?? '',
      $row['response_1_jp'] ?? '',
      $row['response_2_jp'] ?? '',
      $row['response_3_jp'] ?? '',
      $row['response_4_jp'] ?? '',
      $row['response_5_jp'] ?? '',
      $row['response_6_jp'] ?? '',
      $row['explanation_jp'] ?? '',
    ];
    fputcsv($out, $line);
  }

  fclose($out);
  exit;
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('admin.questions.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card admin-page-shell">
    <div class="admin-head admin-page-hero">
      <div class="admin-head-copy">
        <p class="admin-page-eyebrow">Administration</p>
        <h2 class="h1"><?= h(t('admin.questions.title', [], $lang)) ?></h2>
        <p class="sub">Modifier / supprimer (creation via import uniquement)</p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('questions'); ?>
      </div>
    </div>

    <div class="admin-page-layout">
    <section class="admin-section-panel admin-section-panel-accent">
    <div class="admin-panel-toolbar">
      <div>
        <h3 class="h1"><?= h(t('admin.questions.catalog_title', [], $lang)) ?></h3>
        <p class="sub" style="margin:6px 0 0;">Recherche, navigation et analyse de la banque de questions.</p>
      </div>
      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <a class="btn ghost" href="<?= h(questions_export_url($_GET)) ?>"><?= h(t('admin.common.export_csv', [], $lang)) ?></a>
        <a class="btn admin-primary-action-btn" href="/admin/import_questions.php<?= $activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId : '' ?>">+ Importer</a>
      </div>
    </div>
    <form method="get" class="filters-grid users-filters admin-panel-surface" style="margin-bottom:8px;">
      <?php foreach ($activeNeeds as $n): ?>
        <input type="hidden" name="needs[]" value="<?= h($n) ?>">
      <?php endforeach; ?>
      <?php foreach ($activeNeedLevels as $pair): ?>
        <input type="hidden" name="need_levels[]" value="<?= h($pair) ?>">
      <?php endforeach; ?>
      <div>
        <label class="label" for="id_question">Rechercher ID question</label>
        <input class="input" id="id_question" name="id_question" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="ID question" value="<?= h($idFilterRaw) ?>">
      </div>
      <div class="filters-actions">
        <button class="btn" type="submit">Rechercher</button>
        <a class="btn ghost" href="/admin/questions.php<?= $activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId : '' ?>"><?= h(t('admin.common.reset', [], $lang)) ?></a>
      </div>
    </form>

    <?php if ($totalQuestions > 0): ?>
      <div class="distribution-wrap">
        <p class="distribution-title">R&eacute;partition actuelle</p>
        <div class="distribution-grid">
          <?php foreach ($allNeeds as $n): ?>
            <?php
              $visibleCount = 0;
              for ($levelIndex = 1; $levelIndex <= 3; $levelIndex++) {
                $visibleCount += (int)($distribution[$n][$levelIndex] ?? 0);
              }
              if ($visibleCount <= 0) {
                continue;
              }
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
    <?php endif; ?>
    </section>

    <section class="admin-section-panel">
    <div class="section-head admin-section-head">
      <div>
        <h3 class="h1"><?= h(t('admin.questions.list_title', [], $lang)) ?></h3>
        <p class="sub sessions-meta">Page <?= (int)$page ?> / <?= (int)$totalPages ?> (<?= (int)$totalQuestions ?> question(s))</p>
      </div>
    </div>

    <div class="table-wrap admin-table-panel">
      <?php if (!$questions): ?>
        <p class="empty-state"><?= h(t('admin.questions.none', [], $lang)) ?></p>
      <?php else: ?>
        <table class="table questions-table questions-admin-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>ID question</th>
              <th>Categorie</th>
              <th>Niveau</th>
              <th>Type</th>
              <th>Options</th>
              <?php if ($activeProgramId > 0): ?><th class="question-pack-count-col">Pack</th><?php endif; ?>
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
                <?php if ($activeProgramId > 0): ?>
                  <td class="question-pack-count-col">
                    <?php $usedPackages = is_array($q['_used_packages'] ?? null) ? $q['_used_packages'] : []; ?>
                    <?php $usedPackageCount = count($usedPackages); ?>
                    <span class="pill <?= $usedPackageCount > 0 ? 'info' : 'warning' ?>">
                      <?= (int)$usedPackageCount ?> pack<?= $usedPackageCount > 1 ? 's' : '' ?>
                    </span>
                  </td>
                <?php endif; ?>
                <td><?= h(mb_strimwidth((string)$q['text'], 0, 90, '...', 'UTF-8')) ?></td>
	                <td class="actions-cell">
	                  <a class="btn ghost icon-btn" href="/admin/question_edit.php?id=<?= (int)$q['id'] ?><?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>&return=<?= h(urlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/questions.php'))) ?>" aria-label="Modifier la question" title="Modifier la question">
	                    <svg class="icon-edit" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
	                      <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm2.92 2.33H5v-.92l8.06-8.06.92.92L5.92 19.58zM20.71 7.04a1.003 1.003 0 0 0 0-1.42l-2.34-2.34a1.003 1.003 0 0 0-1.42 0l-1.13 1.13 3.75 3.75 1.14-1.12z"/>
	                    </svg>
	                  </a>
	                  <a class="btn ghost icon-btn" href="/admin/question_performance_failures.php?qid=<?= (int)$q['id'] ?><?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>&return=<?= h(urlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/questions.php'))) ?>" aria-label="Voir la performance de la question" title="Voir la performance de la question">
	                    <svg class="icon-performance" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
	                      <path d="M5 19h14v2H5zM6 10h3v7H6zM11 6h3v11h-3zM16 12h3v5h-3z"/>
	                    </svg>
	                  </a>
	                  <a class="btn ghost icon-btn danger" href="/admin/question_delete.php?id=<?= (int)$q['id'] ?><?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>"
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
    </section>
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

