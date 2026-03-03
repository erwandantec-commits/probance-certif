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

function package_edit_filter_url(int $id, array $needs, array $needLevels): string {
  $needs = array_values(array_unique(array_map(
    static fn($v) => strtoupper(trim((string)$v)),
    $needs
  )));
  $needs = array_values(array_filter($needs, static fn($v) => in_array($v, ['PONE', 'PHM', 'PPM'], true)));

  $cleanNeedLevels = [];
  foreach ($needLevels as $pair) {
    $pair = strtoupper(trim((string)$pair));
    if (!preg_match('/^(PONE|PHM|PPM):([1-3])$/', $pair)) {
      continue;
    }
    $cleanNeedLevels[] = $pair;
  }
  $cleanNeedLevels = array_values(array_unique($cleanNeedLevels));

  $qs = ['id' => $id];
  if (!empty($needs)) {
    $qs['needs'] = $needs;
  }
  if (!empty($cleanNeedLevels)) {
    $qs['need_levels'] = $cleanNeedLevels;
  }
  return '/admin/package_edit.php?' . http_build_query($qs);
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

function package_edit_badge_file_options(): array {
  $baseDir = realpath(__DIR__ . '/../assets/badges');
  if ($baseDir === false) {
    return [];
  }
  $files = glob($baseDir . DIRECTORY_SEPARATOR . '*.{png,jpg,jpeg,webp,gif}', GLOB_BRACE) ?: [];
  $out = [];
  foreach ($files as $path) {
    $name = basename((string)$path);
    if ($name === '') {
      continue;
    }
    $out[] = $name;
  }
  natcasesort($out);
  return array_values(array_unique($out));
}


$id = (int)($_GET['id'] ?? 0);
$rawNeeds = $_GET['needs'] ?? [];
if (!is_array($rawNeeds)) {
  $rawNeeds = [$rawNeeds];
}
$filterNeeds = [];
foreach ($rawNeeds as $needRaw) {
  $need = strtoupper(trim((string)$needRaw));
  if (in_array($need, ['PONE', 'PHM', 'PPM'], true)) {
    $filterNeeds[] = $need;
  }
}
$filterNeeds = array_values(array_unique($filterNeeds));

$rawLevels = $_GET['levels'] ?? [];
if (!is_array($rawLevels)) {
  $rawLevels = [$rawLevels];
}
$legacyFilterLevels = [];
foreach ($rawLevels as $lvRaw) {
  $lv = (int)$lvRaw;
  if ($lv >= 1 && $lv <= 3) {
    $legacyFilterLevels[] = $lv;
  }
}
$legacyFilterLevels = array_values(array_unique($legacyFilterLevels));
sort($legacyFilterLevels);

$rawNeedLevels = $_GET['need_levels'] ?? [];
if (!is_array($rawNeedLevels)) {
  $rawNeedLevels = [$rawNeedLevels];
}
$filterNeedLevels = [];
foreach ($rawNeedLevels as $pairRaw) {
  $pair = strtoupper(trim((string)$pairRaw));
  if (preg_match('/^(PONE|PHM|PPM):([1-3])$/', $pair)) {
    $filterNeedLevels[] = $pair;
  }
}
$filterNeedLevels = array_values(array_unique($filterNeedLevels));

// backward compatibility with old single filter params
$legacyNeed = strtoupper(trim((string)($_GET['need'] ?? '')));
if ($legacyNeed !== '' && in_array($legacyNeed, ['PONE', 'PHM', 'PPM'], true) && empty($filterNeeds)) {
  $filterNeeds[] = $legacyNeed;
}
$legacyLevel = (int)($_GET['level'] ?? 0);
if ($legacyLevel >= 1 && $legacyLevel <= 3 && empty($filterNeedLevels)) {
  if ($legacyNeed !== '' && in_array($legacyNeed, ['PONE', 'PHM', 'PPM'], true)) {
    $filterNeedLevels[] = $legacyNeed . ':' . $legacyLevel;
  } else {
    foreach (['PONE', 'PHM', 'PPM'] as $legacyAnyNeed) {
      $filterNeedLevels[] = $legacyAnyNeed . ':' . $legacyLevel;
    }
  }
}
if (empty($filterNeedLevels) && !empty($filterNeeds) && !empty($legacyFilterLevels)) {
  foreach ($filterNeeds as $n) {
    foreach ($legacyFilterLevels as $lv) {
      $filterNeedLevels[] = $n . ':' . $lv;
    }
  }
}
$filterNeedLevels = array_values(array_unique($filterNeedLevels));
$filterNeedLevelMap = [];
foreach ($filterNeedLevels as $pair) {
  [$n, $lv] = explode(':', $pair, 2);
  $filterNeedLevelMap[$n][(int)$lv] = true;
}
$page = max(1, (int)($_GET['page'] ?? 1));

$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=?");
$stmt->execute([$id]);
$pk = $stmt->fetch();
$hasNameColorColumn = package_edit_package_column_exists($pdo, 'name_color_hex');
$hasRulesColumn = package_edit_package_column_exists($pdo, 'selection_rules_json');
$hasProfileColumn = package_edit_package_column_exists($pdo, 'profile');
$hasDisplayOrderColumn = package_edit_package_column_exists($pdo, 'display_order');
$hasBadgeImageColumn = package_edit_package_column_exists($pdo, 'badge_image_filename');
$hasAntiRepeatSessionsColumn = package_edit_package_column_exists($pdo, 'anti_repeat_sessions');
$hasCertValidityDaysColumn = package_edit_package_column_exists($pdo, 'cert_validity_days');
$badgeImageOptions = package_edit_badge_file_options();
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
$badgeImageFilename = trim((string)($pk['badge_image_filename'] ?? ''));
if ($badgeImageFilename === '') {
  $badgeImageFilename = 'user-badge-blue.png';
}
if ($hasBadgeImageColumn) {
  $pickedBadge = trim((string)($_GET['badge_selected'] ?? ''));
  if ($pickedBadge !== '' && in_array($pickedBadge, $badgeImageOptions, true)) {
    $badgeImageFilename = $pickedBadge;
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  if (isset($_GET['draft_name'])) {
    $name = trim((string)$_GET['draft_name']);
  }
  if (isset($_GET['draft_profile'])) {
    $profile = trim((string)$_GET['draft_profile']);
  }
  $isActive = ((int)($_GET['draft_active'] ?? (int)($pk['is_active'] ?? 1)) === 1) ? 1 : 0;

  if (isset($_GET['draft_threshold']) && $_GET['draft_threshold'] !== '') {
    $threshold = (int)$_GET['draft_threshold'];
  }
  if (isset($_GET['draft_duration']) && $_GET['draft_duration'] !== '') {
    $duration = (int)$_GET['draft_duration'];
  }
  if (isset($_GET['draft_cert_validity_days']) && $_GET['draft_cert_validity_days'] !== '') {
    $certValidityDays = (int)$_GET['draft_cert_validity_days'];
  }
  if (isset($_GET['draft_count']) && $_GET['draft_count'] !== '') {
    $count = (int)$_GET['draft_count'];
  }
  if (isset($_GET['draft_anti_repeat']) && $_GET['draft_anti_repeat'] !== '') {
    $antiRepeatSessions = (int)$_GET['draft_anti_repeat'];
  }
  if (isset($threshold)) $threshold = max(0, min(100, (int)$threshold));
  if (isset($certValidityDays)) $certValidityDays = max(1, min(3650, (int)$certValidityDays));
  if (isset($duration)) $duration = max(1, min(600, (int)$duration));
  if (isset($count)) $count = max(1, min(200, (int)$count));
  if (isset($antiRepeatSessions)) $antiRepeatSessions = max(0, min(20, (int)$antiRepeatSessions));
  if (isset($displayOrder)) $displayOrder = max(0, min(9999, (int)$displayOrder));

  if ($hasNameColorColumn) {
    $draftColor = normalize_hex_color(trim((string)($_GET['draft_color'] ?? '')));
    if ($draftColor !== null) {
      $packNameColor = $draftColor;
    }
  }

  if ($hasRulesColumn) {
    $draftTemplate = strtoupper(trim((string)($_GET['draft_template'] ?? '')));
    if ($draftTemplate !== '' && isset($ruleTemplates[$draftTemplate])) {
      $selectedTemplate = $draftTemplate;
    }

    $draftRulesRaw = trim((string)($_GET['draft_rules'] ?? ''));
    if ($draftRulesRaw !== '') {
      $decodedRules = json_decode($draftRulesRaw, true);
      if (is_array($decodedRules)) {
        $parsedRows = [];
        foreach ($decodedRules as $row) {
          if (!is_array($row)) {
            continue;
          }
          $need = strtoupper(trim((string)($row['need'] ?? '')));
          $take = (int)($row['take'] ?? 0);
          $targetTotal = (int)($row['target_total'] ?? 0);
          $levelsRaw = $row['levels'] ?? [];
          if (!is_array($levelsRaw)) {
            $levelsRaw = [];
          }
          $levels = [];
          foreach ($levelsRaw as $lv) {
            $lv = (int)$lv;
            if ($lv >= 1 && $lv <= 3) {
              $levels[] = $lv;
            }
          }
          $levels = array_values(array_unique($levels));
          sort($levels);

          if (!in_array($need, ['PONE', 'PHM', 'PPM'], true) || $take <= 0 || !$levels) {
            continue;
          }
          $parsedRows[] = [
            'need' => $need,
            'levels' => $levels,
            'take' => $take,
            'target_total' => max(0, $targetTotal),
          ];
        }
        $ruleRows = $parsedRows;
      }
    }
  }
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

$filterNeeds = array_values(array_filter(
  $filterNeeds,
  static fn(string $need): bool => isset($allowedByNeed[$need])
));

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
if (!empty($filterNeeds) || !empty($filterNeedLevels)) {
  $orParts = [];
  foreach ($filterNeeds as $need) {
    $orParts[] = "(q.need = ?)";
    $qCountParams[] = $need;
  }
  foreach ($filterNeedLevels as $pair) {
    [$pairNeed, $pairLevel] = explode(':', $pair, 2);
    $orParts[] = "(q.need = ? AND q.level = ?)";
    $qCountParams[] = $pairNeed;
    $qCountParams[] = (int)$pairLevel;
  }
  if (!empty($orParts)) {
    $qCountSql .= " AND (" . implode(' OR ', $orParts) . ") ";
  }
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
if (!empty($filterNeeds) || !empty($filterNeedLevels)) {
  $orParts = [];
  foreach ($filterNeeds as $need) {
    $orParts[] = "(q.need = ?)";
    $qParams[] = $need;
  }
  foreach ($filterNeedLevels as $pair) {
    [$pairNeed, $pairLevel] = explode(':', $pair, 2);
    $orParts[] = "(q.need = ? AND q.level = ?)";
    $qParams[] = $pairNeed;
    $qParams[] = (int)$pairLevel;
  }
  if (!empty($orParts)) {
    $qSql .= " AND (" . implode(' OR ', $orParts) . ") ";
  }
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
  $name = trim((string)($_POST['name'] ?? (string)($pk['name'] ?? '')));
  $threshold = (int)($_POST['pass_threshold_percent'] ?? 80);
  $certValidityDays = (int)($_POST['cert_validity_days'] ?? (int)($pk['cert_validity_days'] ?? 365));
  $duration = (int)($_POST['duration_limit_minutes'] ?? 120);
  $count = (int)($_POST['selection_count'] ?? 5);
  $antiRepeatSessions = (int)($_POST['anti_repeat_sessions'] ?? (int)($pk['anti_repeat_sessions'] ?? 4));
  $isActive = ((int)($_POST['is_active'] ?? (int)($pk['is_active'] ?? 1)) === 1) ? 1 : 0;
  $profile = trim((string)($_POST['profile'] ?? ''));
  $displayOrder = (int)($_POST['display_order'] ?? (int)($pk['display_order'] ?? 100));
  $badgeImageFilename = trim((string)($_POST['badge_image_filename'] ?? $badgeImageFilename));
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

  if ($name === '') {
    $error = "Nom du pack obligatoire";
  } elseif ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 255) {
    $error = "Nom du pack trop long";
  } elseif ($threshold < 0 || $threshold > 100) {
    $error = "Seuil invalide";
  } elseif ($hasCertValidityDaysColumn && ($certValidityDays < 1 || $certValidityDays > 3650)) {
    $error = "Validite invalide (1 a 3650 jours)";
  } elseif ($duration < 1 || $duration > 600) {
    $error = "Duree invalide";
  } elseif ($count < 1 || $count > 200) {
    $error = "Nombre de questions invalide";
  } elseif ($hasAntiRepeatSessionsColumn && ($antiRepeatSessions < 0 || $antiRepeatSessions > 20)) {
    $error = "Anti-repetition invalide (0 a 20 sessions)";
  } elseif ($hasDisplayOrderColumn && ($displayOrder < 0 || $displayOrder > 9999)) {
    $error = "Ordre d'affichage invalide";
  } elseif ($hasProfileColumn && (function_exists('mb_strlen') ? mb_strlen($profile) : strlen($profile)) > 255) {
    $error = "Profil trop long";
  } elseif ($hasBadgeImageColumn && $badgeImageFilename !== '' && !empty($badgeImageOptions) && !in_array($badgeImageFilename, $badgeImageOptions, true)) {
    $error = "Image de badge invalide";
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
    } else {
      $sets = [
        'name=?',
        'pass_threshold_percent=?',
        'duration_limit_minutes=?',
        'selection_count=?',
        'is_active=?',
      ];
      $values = [$name, $threshold, $duration, $count, $isActive];
      if ($hasCertValidityDaysColumn) {
        $sets[] = 'cert_validity_days=?';
        $values[] = $certValidityDays;
      }
      if ($hasAntiRepeatSessionsColumn) {
        $sets[] = 'anti_repeat_sessions=?';
        $values[] = $antiRepeatSessions;
      }
      if ($hasNameColorColumn) {
        $sets[] = 'name_color_hex=?';
        $values[] = $packNameColor;
      }
      if ($hasProfileColumn) {
        $sets[] = 'profile=?';
        $values[] = ($profile !== '') ? $profile : null;
      }
      if ($hasDisplayOrderColumn) {
        $sets[] = 'display_order=?';
        $values[] = $displayOrder;
      }
      if ($hasBadgeImageColumn) {
        $sets[] = 'badge_image_filename=?';
        $values[] = ($badgeImageFilename !== '') ? $badgeImageFilename : null;
      }
      if ($hasRulesColumn) {
        $sets[] = 'selection_rules_json=?';
        $values[] = $selectionRulesJson;
      }
      $values[] = $id;
      $update = $pdo->prepare("
        UPDATE packages
        SET " . implode(', ', $sets) . "
        WHERE id=?
      ");
      $update->execute($values);
    }

    if ($error === '') {
      header("Location: /admin/packages.php");
      exit;
    }
  }
}

$formThreshold = isset($threshold) ? $threshold : (int)$pk['pass_threshold_percent'];
$formCertValidityDays = isset($certValidityDays) ? $certValidityDays : (int)($pk['cert_validity_days'] ?? 365);
$formDuration = isset($duration) ? $duration : (int)$pk['duration_limit_minutes'];
$formCount = isset($count) ? $count : (int)$pk['selection_count'];
$formAntiRepeatSessions = isset($antiRepeatSessions) ? $antiRepeatSessions : (int)($pk['anti_repeat_sessions'] ?? 4);
$formIsActive = isset($isActive) ? $isActive : (((int)($pk['is_active'] ?? 1) === 1) ? 1 : 0);
$formName = isset($name) && $name !== '' ? $name : (string)($pk['name'] ?? '');
$formProfile = isset($profile) ? $profile : (string)($pk['profile'] ?? '');
$formDisplayOrder = isset($displayOrder) ? $displayOrder : (int)($pk['display_order'] ?? 100);
$formBadgeImageFilename = isset($badgeImageFilename) ? $badgeImageFilename : ((string)($pk['badge_image_filename'] ?? 'user-badge-blue.png'));
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Modifier pack</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Modifier pack</h2>
          <p class="sub"><span style="<?= h(package_label_style((string)$formName, $packNameColor)) ?>"><?= h($formName) ?></span></p>
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
        <section class="pack-config-section">
          <h3 class="pack-config-title">Configuration du pack</h3>
          <div class="pack-config-grid">
            <article class="pack-config-card">
              <h4 class="pack-config-card-title">Informations</h4>
              <div class="pack-config-fields">
                <div>
                  <label class="label">Nom du pack</label>
                  <input
                    class="input"
                    type="text"
                    name="name"
                    maxlength="255"
                    value="<?= h($formName) ?>"
                    required
                  >
                </div>
                <?php if ($hasProfileColumn): ?>
                  <div>
                    <label class="label">Profil</label>
                    <input
                      class="input"
                      type="text"
                      name="profile"
                      maxlength="255"
                      value="<?= h($formProfile) ?>"
                    >
                  </div>
                <?php endif; ?>
                <div>
                  <label class="label">Statut</label>
                  <select class="input" name="is_active">
                    <option value="1" <?= $formIsActive === 1 ? 'selected' : '' ?>>Actif</option>
                    <option value="0" <?= $formIsActive === 0 ? 'selected' : '' ?>>Inactif</option>
                  </select>
                </div>
                <?php if ($hasCertValidityDaysColumn): ?>
                  <div>
                    <label class="label">P&eacute;riode de validit&eacute; (jours)</label>
                    <input
                      class="input"
                      type="number"
                      name="cert_validity_days"
                      min="1"
                      max="3650"
                      value="<?= (int)$formCertValidityDays ?>"
                      required
                    >
                  </div>
                <?php endif; ?>
              </div>
            </article>

            <article class="pack-config-card">
              <h4 class="pack-config-card-title">Evaluation</h4>
              <div class="pack-config-fields">
                <div>
                  <label class="label">Seuil de r&eacute;ussite (%)</label>
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
                  <label class="label">Dur&eacute;e max (minutes)</label>
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
                  <label class="label">Nombre de questions tir&eacute;es</label>
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
                <?php if ($hasAntiRepeatSessionsColumn): ?>
                  <div>
                    <label class="label">
                      <span class="order-help-wrap">
                        <span>Nombre de sessions</span>
                        <span class="order-help-tip" tabindex="0" aria-label="Aide sur les sessions anti-repetition">
                          i
                          <span class="order-help-bubble">Evite de reposer des questions vues dans les N dernieres sessions de ce pack pour cet utilisateur (toutes sessions confondues).</span>
                        </span>
                      </span>
                    </label>
                    <input
                      class="input"
                      type="number"
                      name="anti_repeat_sessions"
                      min="0"
                      max="20"
                      value="<?= (int)$formAntiRepeatSessions ?>"
                      required
                    >
                  </div>
                <?php endif; ?>
              </div>
            </article>

            <article class="pack-config-card pack-config-card-wide">
              <h4 class="pack-config-card-title">Apparence</h4>
              <div class="pack-config-fields pack-appearance-fields">
                <?php if ($hasNameColorColumn): ?>
                  <div class="pack-color-field">
                    <label class="label">Couleur du nom</label>
                    <div class="pack-color-row">
                      <input
                        class="pack-color-input"
                        id="edit-pack-color"
                        type="color"
                        name="name_color_hex"
                        value="<?= h($packNameColor) ?>"
                      >
                      <span class="pack-color-swatch" data-pack-color-preview style="background:<?= h($packNameColor) ?>;"></span>
                      <span class="pack-color-code" data-pack-color-code><?= h(strtoupper($packNameColor)) ?></span>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($hasBadgeImageColumn): ?>
                  <div class="badge-picker-field">
                    <label class="label">Image du badge</label>
                    <input type="hidden" name="badge_image_filename" value="<?= h($formBadgeImageFilename) ?>">
                    <?php $libraryReturn = '/admin/package_edit.php?id=' . (int)$id; ?>
                    <?php if ($formBadgeImageFilename !== ''): ?>
                      <a
                        id="pack-edit-badge-picker"
                        class="badge-current-link"
                        data-base-return="<?= h($libraryReturn) ?>"
                        href="/admin/badge_library.php?return=<?= h(urlencode($libraryReturn)) ?>"
                      >Cliquez pour changer l'image.</a>
                    </div>
                    <?php if (!$badgeImageOptions): ?>
                      <p class="small" style="margin-top:8px;">Aucune image de badge disponible.</p>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </article>
          </div>
        </section>

        <?php if ($hasRulesColumn): ?>
          <section class="rule-builder">
            <h3 class="distribution-title rule-builder-title">
              <span class="order-help-wrap">
                <span>R&egrave;gles de tirage des questions</span>
                <span class="order-help-tip" tabindex="0" aria-label="Aide sur les regles de tirage">
                  i
                  <span class="order-help-bubble">D&eacute;finis l'ordre des paliers. Chaque ligne prend "jusqu'&agrave; X" questions.</span>
                </span>
              </span>
            </h3>

            <div class="rule-toolbar">
              <div class="rule-template-group">
                <label class="label" for="rule-template">Mod&egrave;le</label>
                <select class="input" id="rule-template" name="rule_template">
                  <option value="">Aucun</option>
                  <?php foreach (array_keys($ruleTemplates) as $tplName): ?>
                    <option value="<?= h($tplName) ?>" <?= $selectedTemplate === $tplName ? 'selected' : '' ?>><?= h($tplName) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="rule-toolbar-actions">
                <button class="btn ghost rule-action-btn rule-action-apply" type="button" id="apply-rule-template">Appliquer le mod&egrave;le</button>
                <button class="btn ghost rule-action-btn rule-action-add" type="button" id="add-rule-row">Ajouter un palier</button>
              </div>
            </div>

            <div class="table-wrap rule-table-wrap">
              <table class="table questions-table rules-table" id="rule-rows-table">
                <thead>
                  <tr>
                    <th>Connaissances requises</th>
                    <th>Niveaux</th>
                    <th>Nb de questions (max)</th>
                    <th>
                      <span class="order-help-wrap">
                        <span>Cible cumul&eacute;e</span>
                        <span class="order-help-tip" tabindex="0" aria-label="Aide sur la cible cumulee">
                          i
                          <span class="order-help-bubble">Stoppe le palier quand le total cumul&eacute; de questions atteint cette cible.</span>
                        </span>
                      </span>
                    </th>
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
                      <td class="rule-levels-cell">
                        <input type="hidden" class="rule-level-1-input" value="<?= !empty($levelsMap[1]) ? '1' : '0' ?>">
                        <label class="rule-level-check"><input type="checkbox" class="rule-level-1-check" <?= !empty($levelsMap[1]) ? 'checked' : '' ?>> L1</label>
                        <input type="hidden" class="rule-level-2-input" value="<?= !empty($levelsMap[2]) ? '1' : '0' ?>">
                        <label class="rule-level-check"><input type="checkbox" class="rule-level-2-check" <?= !empty($levelsMap[2]) ? 'checked' : '' ?>> L2</label>
                        <input type="hidden" class="rule-level-3-input" value="<?= !empty($levelsMap[3]) ? '1' : '0' ?>">
                        <label class="rule-level-check"><input type="checkbox" class="rule-level-3-check" <?= !empty($levelsMap[3]) ? 'checked' : '' ?>> L3</label>
                      </td>
                      <td><input class="input rule-take" type="number" name="rule_take[]" min="1" max="200" value="<?= (int)($row['take'] ?? 0) ?>"></td>
                      <td><input class="input rule-target-total" type="number" name="rule_target_total[]" min="0" max="200" value="<?= (int)($row['target_total'] ?? 0) ?>"></td>
                      <td><button class="btn ghost rule-remove rule-remove-btn" type="button">Supprimer</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
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
        R&eacute;partition par connaissances requises et niveau (banque globale, utilis&eacute;e pour le tirage de ce pack).
        <?php if (!empty($filterNeeds) || !empty($filterNeedLevels)): ?>
          <span class="small" style="margin-left:8px;">
            Filtre:
            <b><?= h(!empty($filterNeeds) ? implode(', ', $filterNeeds) : 'Tous besoins') ?></b>
            <?php if (!empty($filterNeedLevels)): ?>
              <?php
                $pairLabels = [];
                foreach ($filterNeedLevels as $pair) {
                  [$pn, $pl] = explode(':', $pair, 2);
                  $pairLabels[] = $pn . ' L' . (int)$pl;
                }
              ?>
              <?= ' - ' . h(implode(', ', $pairLabels)) ?>
            <?php endif; ?>
            <a href="/admin/package_edit.php?id=<?= (int)$id ?>" style="margin-left:8px;">Reset</a>
          </span>
        <?php endif; ?>
      </p>

	      <div class="distribution-grid" style="margin-top:10px;">
	        <?php foreach (['PONE', 'PHM', 'PPM'] as $need): ?>
          <?php if (!isset($allowedByNeed[$need])) continue; ?>
          <?php
            $needIsActive = in_array($need, $filterNeeds, true);
            $nextNeeds = $filterNeeds;
            if ($needIsActive) {
              $nextNeeds = array_values(array_filter($nextNeeds, static fn($v) => $v !== $need));
            } else {
              $nextNeeds[] = $need;
            }
            $nextNeedLevels = $filterNeedLevels;
            if ($needIsActive) {
              $nextNeedLevels = array_values(array_filter($nextNeedLevels, static fn($pair) => strpos($pair, $need . ':') !== 0));
            }
            $needToggleUrl = package_edit_filter_url($id, $nextNeeds, $nextNeedLevels);
          ?>
	          <div class="distribution-card distribution-card-clickable"
	               data-filter-need-url="<?= h($needToggleUrl) ?>"
               role="link"
               tabindex="0"
               aria-label="<?= h(($needIsActive ? 'Retirer' : 'Ajouter') . ' le filtre ' . $need) ?>">
            <p class="distribution-need">
              <a class="distribution-need-link <?= $needIsActive ? 'is-active' : '' ?>"
                 href="<?= h($needToggleUrl) ?>">
                <?= h($need) ?>
              </a>
            </p>
            <div class="distribution-levels">
              <?php for ($lv = 1; $lv <= 3; $lv++): ?>
                <?php
                  $pairKey = $need . ':' . $lv;
                  $levelIsActive = !empty($filterNeedLevelMap[$need][$lv]);
                  $nextNeedLevels = $filterNeedLevels;
                  if ($levelIsActive) {
                    $nextNeedLevels = array_values(array_filter($nextNeedLevels, static fn($pair) => $pair !== $pairKey));
                  } else {
                    $nextNeedLevels[] = $pairKey;
                  }
                  $nextNeedsForLevel = array_values(array_filter($filterNeeds, static fn($n) => $n !== $need));
                  $levelToggleUrl = package_edit_filter_url($id, $nextNeedsForLevel, $nextNeedLevels);
                ?>
                <a class="distribution-chip distribution-chip-link <?= $levelIsActive ? 'is-active' : '' ?>"
                   href="<?= h($levelToggleUrl) ?>">
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
                <th>&Eacute;nonc&eacute;</th>
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
    var packEditForm = document.querySelector('form[method="post"]');
    var editBadgePickerLink = document.getElementById('pack-edit-badge-picker');

    function editFieldValue(name) {
      if (!packEditForm) return '';
      var el = packEditForm.querySelector('[name="' + name + '"]');
      if (!el) return '';
      return String(el.value || '').trim();
    }

    function buildEditDraftRules() {
      var body = document.getElementById('rule-rows-body');
      if (!body) return [];
      var rows = [];
      body.querySelectorAll('.rule-row').forEach(function (row) {
        var need = row.querySelector('.rule-need');
        var take = row.querySelector('.rule-take');
        var target = row.querySelector('.rule-target-total');
        var levels = [];
        if (row.querySelector('.rule-level-1-check:checked')) levels.push(1);
        if (row.querySelector('.rule-level-2-check:checked')) levels.push(2);
        if (row.querySelector('.rule-level-3-check:checked')) levels.push(3);
        var needVal = need ? String(need.value || '').trim().toUpperCase() : '';
        var takeVal = take ? parseInt(String(take.value || '0'), 10) : 0;
        var targetVal = target ? parseInt(String(target.value || '0'), 10) : 0;
        if (!needVal || !levels.length || !Number.isFinite(takeVal) || takeVal <= 0) return;
        rows.push({
          need: needVal,
          levels: levels,
          take: takeVal,
          target_total: Number.isFinite(targetVal) && targetVal > 0 ? targetVal : 0
        });
      });
      return rows;
    }

    if (editBadgePickerLink) {
      editBadgePickerLink.addEventListener('click', function () {
        var baseReturn = editBadgePickerLink.getAttribute('data-base-return') || '/admin/package_edit.php?id=<?= (int)$id ?>';
        try {
          var returnUrl = new URL(baseReturn, window.location.origin);
          returnUrl.searchParams.set('draft_name', editFieldValue('name'));
          returnUrl.searchParams.set('draft_profile', editFieldValue('profile'));
          returnUrl.searchParams.set('draft_active', editFieldValue('is_active') || '1');
          returnUrl.searchParams.set('draft_threshold', editFieldValue('pass_threshold_percent') || String(<?= (int)$formThreshold ?>));
          returnUrl.searchParams.set('draft_cert_validity_days', editFieldValue('cert_validity_days') || String(<?= (int)$formCertValidityDays ?>));
          returnUrl.searchParams.set('draft_duration', editFieldValue('duration_limit_minutes') || String(<?= (int)$formDuration ?>));
          returnUrl.searchParams.set('draft_count', editFieldValue('selection_count') || String(<?= (int)$formCount ?>));
          returnUrl.searchParams.set('draft_anti_repeat', editFieldValue('anti_repeat_sessions') || String(<?= (int)$formAntiRepeatSessions ?>));
          returnUrl.searchParams.set('draft_color', editFieldValue('name_color_hex') || '<?= h($packNameColor) ?>');
          returnUrl.searchParams.set('draft_template', editFieldValue('rule_template'));
          var draftRules = buildEditDraftRules();
          if (draftRules.length > 0) {
            returnUrl.searchParams.set('draft_rules', JSON.stringify(draftRules));
          } else {
            returnUrl.searchParams.delete('draft_rules');
          }
          editBadgePickerLink.href = '/admin/badge_library.php?return=' + encodeURIComponent(returnUrl.pathname + returnUrl.search);
        } catch (e) {
          // keep fallback href
        }
      });
    }

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
          '<td class="rule-levels-cell">' +
            '<input type="hidden" class="rule-level-1-input" value="' + (hasL1 ? '1' : '0') + '">' +
            '<label class="rule-level-check"><input type="checkbox" class="rule-level-1-check"' + (hasL1 ? ' checked' : '') + '> L1</label>' +
            '<input type="hidden" class="rule-level-2-input" value="' + (hasL2 ? '1' : '0') + '">' +
            '<label class="rule-level-check"><input type="checkbox" class="rule-level-2-check"' + (hasL2 ? ' checked' : '') + '> L2</label>' +
            '<input type="hidden" class="rule-level-3-input" value="' + (hasL3 ? '1' : '0') + '">' +
            '<label class="rule-level-check"><input type="checkbox" class="rule-level-3-check"' + (hasL3 ? ' checked' : '') + '> L3</label>' +
          '</td>' +
          '<td><input class="input rule-take" type="number" min="1" max="200" value="' + take + '"></td>' +
          '<td><input class="input rule-target-total" type="number" min="0" max="200" value="' + targetTotal + '"></td>' +
          '<td><button class="btn ghost rule-remove rule-remove-btn" type="button">Supprimer</button></td>' +
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

    var colorInput = document.getElementById('edit-pack-color');
    var colorPreview = document.querySelector('[data-pack-color-preview]');
    var colorCode = document.querySelector('[data-pack-color-code]');
    function syncColorPreview() {
      if (!colorInput) return;
      var value = String(colorInput.value || '').trim();
      if (!value) return;
      if (colorPreview) colorPreview.style.background = value;
      if (colorCode) colorCode.textContent = value.toUpperCase();
    }
    if (colorInput) {
      colorInput.addEventListener('input', syncColorPreview);
      syncColorPreview();
    }
    <?php endif; ?>
  })();
  </script>
</body>
</html>

