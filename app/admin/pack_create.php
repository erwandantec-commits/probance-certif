<?php
require_once __DIR__ . '/_auth.php';
require_admin_area();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../auth.php';
$pdo = db();
$adminUser = current_user() ?? ['role' => 'USER'];
$activeProgramId = auth_admin_program_context($pdo, $adminUser, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);

function pack_create_rule_templates(): array {
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
        ['need' => 'PONE', 'levels' => [2], 'take' => 3],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10],
      ],
    ],
    'BLACK' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PHM', 'levels' => [2, 3], 'take' => 30],
        ['need' => 'PHM', 'levels' => [1], 'take' => 40, 'target_total' => 40],
        ['need' => 'PONE', 'levels' => [3], 'take' => 3, 'target_total' => 10],
        ['need' => 'PONE', 'levels' => [2], 'take' => 3],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10],
      ],
    ],
    'SILVER' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PPM', 'levels' => [1], 'take' => 40],
        ['need' => 'PHM', 'levels' => [3], 'take' => 2, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [3], 'take' => 2],
        ['need' => 'PHM', 'levels' => [2], 'take' => 2],
        ['need' => 'PONE', 'levels' => [2], 'take' => 2],
        ['need' => 'PHM', 'levels' => [1], 'take' => 5],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10],
      ],
    ],
    'GOLD' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PPM', 'levels' => [2, 3], 'take' => 30],
        ['need' => 'PPM', 'levels' => [1], 'take' => 40, 'target_total' => 40],
        ['need' => 'PHM', 'levels' => [3], 'take' => 2, 'target_total' => 10],
        ['need' => 'PONE', 'levels' => [3], 'take' => 2],
        ['need' => 'PHM', 'levels' => [2], 'take' => 2],
        ['need' => 'PONE', 'levels' => [2], 'take' => 2],
        ['need' => 'PHM', 'levels' => [1], 'take' => 5],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10],
      ],
    ],
  ];
}

function pack_create_rule_rows_with_cumulative_targets(array $rows): array {
  $out = [];
  $runningTarget = 0;
  foreach ($rows as $row) {
    $targetStep = max(0, (int)($row['target_total'] ?? 0));
    if ($targetStep > 0) {
      $runningTarget += $targetStep;
    }
    $row['target_total'] = $runningTarget;
    $out[] = $row;
  }
  return $out;
}

function pack_create_rule_rows_from_post(): array {
  $needs = $_POST['rule_need'] ?? [];
  $targets = $_POST['rule_target_total'] ?? [];
  $level1 = $_POST['rule_level_1'] ?? [];
  $level2 = $_POST['rule_level_2'] ?? [];
  $level3 = $_POST['rule_level_3'] ?? [];

  if (!is_array($needs) || !is_array($targets)) {
    return [];
  }

  $rows = [];
  $total = count($needs);
  for ($i = 0; $i < $total; $i++) {
    $need = normalize_question_need((string)($needs[$i] ?? ''));
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

    if ($need === '' || !$levels) {
      continue;
    }

    $rows[] = [
      'need' => $need,
      'levels' => $levels,
      'target_total' => $targetTotal > 0 ? $targetTotal : 0,
    ];
  }

  return $rows;
}

function pack_create_rule_rows_to_json(array $rows, int $selectionCount): string {
  $rows = pack_create_rule_rows_with_cumulative_targets($rows);
  $buckets = [];
  foreach ($rows as $row) {
    $bucket = [
      'need' => (string)$row['need'],
      'levels' => array_values(array_map('intval', $row['levels'] ?? [])),
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

function pack_create_validate_rule_rows(array $rows, int $selectionCount): string {
  $targetSum = 0;
  foreach ($rows as $idx => $row) {
    $line = $idx + 1;
    $targetTotal = (int)($row['target_total'] ?? 0);

    if ($targetTotal < 0 || $targetTotal > $selectionCount) {
      return 'Cible cumulee invalide ligne ' . $line . ' (0 a ' . $selectionCount . ').';
    }

    if ($targetTotal > 0) {
      $targetSum += $targetTotal;
      if ($targetSum > $selectionCount) {
        return 'Cible cumulee totale invalide (0 a ' . $selectionCount . ').';
      }
    }
  }

  return '';
}

function pack_create_badge_file_options(): array {
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

function pack_create_program_question_scope_sql(PDO $pdo, int $activeProgramId): string {
  if ($activeProgramId <= 0 || !auth_table_exists($pdo, 'program_question_links')) {
    return '';
  }

  return "EXISTS (
    SELECT 1
    FROM program_question_links pql
    WHERE pql.question_id = q.id
      AND pql.program_id = " . (int)$activeProgramId . "
  )";
}

function pack_create_rule_templates_for_program(PDO $pdo, int $activeProgramId, array $ruleTemplates): array {
  if ($activeProgramId <= 0) {
    return $ruleTemplates;
  }

  if (auth_program_package_links_enabled($pdo)) {
    $stmt = $pdo->prepare("
      SELECT UPPER(TRIM(pk.name)) AS package_name
      FROM packages pk
      JOIN program_package_links ppl ON ppl.package_id = pk.id
      WHERE ppl.program_id = ?
    ");
    $stmt->execute([$activeProgramId]);
  } elseif (auth_column_exists($pdo, 'packages', 'program_id')) {
    $stmt = $pdo->prepare("
      SELECT UPPER(TRIM(name)) AS package_name
      FROM packages
      WHERE program_id = ?
    ");
    $stmt->execute([$activeProgramId]);
  } else {
    return $ruleTemplates;
  }

  $allowedNames = [];
  foreach (($stmt ? $stmt->fetchAll() : []) as $row) {
    $name = (string)($row['package_name'] ?? '');
    if ($name !== '') {
      $allowedNames[$name] = true;
    }
  }

  return array_intersect_key($ruleTemplates, $allowedNames);
}

function pack_create_known_needs(PDO $pdo, array $ruleTemplates, int $activeProgramId = 0): array {
  $seedNeeds = [];
  foreach ($ruleTemplates as $template) {
    foreach (($template['buckets'] ?? []) as $bucket) {
      $seedNeeds[] = (string)($bucket['need'] ?? '');
    }
  }

  $programQuestionScopeSql = pack_create_program_question_scope_sql($pdo, $activeProgramId);
  if ($programQuestionScopeSql === '') {
    return question_known_needs($pdo, $seedNeeds);
  }

  $needs = [];
  foreach ($seedNeeds as $need) {
    $need = normalize_question_need((string)$need);
    if ($need !== '') {
      $needs[$need] = true;
    }
  }
  $stmt = $pdo->query("
    SELECT DISTINCT TRIM(q.need) AS need_name
    FROM questions q
    WHERE q.need IS NOT NULL
      AND TRIM(q.need) <> ''
      AND $programQuestionScopeSql
    ORDER BY need_name ASC
  ");
  foreach (($stmt ? $stmt->fetchAll() : []) as $row) {
    $need = normalize_question_need((string)($row['need_name'] ?? ''));
    if ($need !== '') {
      $needs[$need] = true;
    }
  }

  return array_keys($needs);
}

function pack_create_available_question_counts(PDO $pdo, int $activeProgramId = 0): array {
  $counts = [];
  $programQuestionScopeSql = pack_create_program_question_scope_sql($pdo, $activeProgramId);
  $programWhereSql = $programQuestionScopeSql !== '' ? "AND $programQuestionScopeSql" : "";
  $st = $pdo->query("
    SELECT q.need, q.level, COUNT(*) c
    FROM questions q
    WHERE EXISTS (
      SELECT 1
      FROM question_options qo
      WHERE qo.question_id = q.id
      GROUP BY qo.question_id
      HAVING COUNT(*) >= 2
    )
    $programWhereSql
    GROUP BY q.need, q.level
  ");
  foreach (($st ? $st->fetchAll() : []) as $row) {
    $need = normalize_question_need((string)($row['need'] ?? ''));
    $level = (int)($row['level'] ?? 0);
    if ($need === '' || $level < 1 || $level > 3) {
      continue;
    }
    if (!isset($counts[$need])) {
      $counts[$need] = [1 => 0, 2 => 0, 3 => 0];
    }
    $counts[$need][$level] = (int)($row['c'] ?? 0);
  }
  return $counts;
}

$error = '';
$name = '';
$threshold = 80;
$certValidityDays = 365;
$failedCooldownDays = 365;
$duration = 120;
$count = 10;
$antiRepeatSessions = 1;
$profile = '';
$displayOrder = 100;
$badgeImageFilename = 'user-badge-blue.png';
$isActive = 1;
$nameColorHex = '#334155';
$ruleTemplates = pack_create_rule_templates();
$ruleTemplates = pack_create_rule_templates_for_program($pdo, $activeProgramId, $ruleTemplates);
$knownNeeds = pack_create_known_needs($pdo, $ruleTemplates, $activeProgramId);
$defaultNeed = question_default_need($knownNeeds);
$availableQuestionCounts = pack_create_available_question_counts($pdo, $activeProgramId);
$selectedTemplate = '';
$ruleRows = [];

$hasNameColorColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'name_color_hex'
")->fetchColumn();
$hasRulesColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'selection_rules_json'
")->fetchColumn();
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
$hasBadgeImageColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'badge_image_filename'
")->fetchColumn();
$hasAntiRepeatSessionsColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'anti_repeat_sessions'
")->fetchColumn();
$hasCertValidityDaysColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'cert_validity_days'
")->fetchColumn();
$hasFailedCooldownDaysColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'failed_cooldown_days'
")->fetchColumn();
$hasProgramIdColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'program_id'
")->fetchColumn();
$hasProgramPackageLinksTable = auth_table_exists($pdo, 'program_package_links');
$badgeImageOptions = pack_create_badge_file_options();
if ($hasDisplayOrderColumn) {
  $nextDisplayOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order), 0) + 10 FROM packages")->fetchColumn();
  $displayOrder = max(0, min(9999, $nextDisplayOrder));
}
if ($hasBadgeImageColumn) {
  $pickedBadge = trim((string)($_GET['badge_selected'] ?? ''));
  if ($pickedBadge !== '' && in_array($pickedBadge, $badgeImageOptions, true)) {
    $badgeImageFilename = $pickedBadge;
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $name = trim((string)($_GET['draft_name'] ?? $name));
  $profile = trim((string)($_GET['draft_profile'] ?? $profile));
  $isActive = ((int)($_GET['draft_active'] ?? $isActive) === 1) ? 1 : 0;

  if (isset($_GET['draft_threshold']) && $_GET['draft_threshold'] !== '') {
    $threshold = (int)$_GET['draft_threshold'];
  }
  if (isset($_GET['draft_duration']) && $_GET['draft_duration'] !== '') {
    $duration = (int)$_GET['draft_duration'];
  }
  if (isset($_GET['draft_cert_validity_days']) && $_GET['draft_cert_validity_days'] !== '') {
    $certValidityDays = (int)$_GET['draft_cert_validity_days'];
  }
  if (isset($_GET['draft_failed_cooldown_days']) && $_GET['draft_failed_cooldown_days'] !== '') {
    $failedCooldownDays = (int)$_GET['draft_failed_cooldown_days'];
  }
  if (isset($_GET['draft_count']) && $_GET['draft_count'] !== '') {
    $count = (int)$_GET['draft_count'];
  }
  $threshold = max(0, min(100, $threshold));
  $certValidityDays = max(1, min(3650, $certValidityDays));
  $failedCooldownDays = max(0, min(3650, $failedCooldownDays));
  $duration = max(1, min(600, $duration));
  $count = max(1, min(200, $count));
  $antiRepeatSessions = max(0, min(20, $antiRepeatSessions));
  $displayOrder = max(0, min(9999, $displayOrder));

  if ($hasNameColorColumn) {
    $draftColor = normalize_hex_color(trim((string)($_GET['draft_color'] ?? '')));
    if ($draftColor !== null) {
      $nameColorHex = $draftColor;
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
          $need = normalize_question_need((string)($row['need'] ?? ''));
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

          if ($need === '' || !$levels) {
            continue;
          }
          $parsedRows[] = [
            'need' => $need,
            'levels' => $levels,
            'target_total' => max(0, $targetTotal),
          ];
        }
        $ruleRows = $parsedRows;
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim((string)($_POST['name'] ?? ''));
  $threshold = (int)($_POST['pass_threshold_percent'] ?? 80);
  $certValidityDays = (int)($_POST['cert_validity_days'] ?? 365);
  $failedCooldownDays = (int)($_POST['failed_cooldown_days'] ?? 365);
  $duration = (int)($_POST['duration_limit_minutes'] ?? 120);
  $count = (int)($_POST['selection_count'] ?? 10);
  $antiRepeatSessions = 1;
  $profile = trim((string)($_POST['profile'] ?? ''));
  $displayOrder = (int)($_POST['display_order'] ?? $displayOrder);
  $badgeImageFilename = trim((string)($_POST['badge_image_filename'] ?? $badgeImageFilename));
  $isActive = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;
  $selectedTemplate = strtoupper(trim((string)($_POST['rule_template'] ?? '')));
  if (!isset($ruleTemplates[$selectedTemplate])) {
    $selectedTemplate = '';
  }
  $ruleRows = pack_create_rule_rows_from_post();
  $postedColor = trim((string)($_POST['name_color_hex'] ?? ''));
  $normalizedColor = normalize_hex_color($postedColor);
  if ($normalizedColor !== null) {
    $nameColorHex = $normalizedColor;
  }

  $nameLen = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
  if ($name === '') {
    $error = 'Le nom du pack est obligatoire.';
  } elseif ($nameLen > 255) {
    $error = 'Nom de pack trop long (max 255 caracteres).';
  } elseif ($threshold < 0 || $threshold > 100) {
    $error = 'Seuil invalide (0 a 100).';
  } elseif ($hasCertValidityDaysColumn && ($certValidityDays < 1 || $certValidityDays > 3650)) {
    $error = 'Validite invalide (1 a 3650 jours).';
  } elseif ($hasFailedCooldownDaysColumn && ($failedCooldownDays < 0 || $failedCooldownDays > 3650)) {
    $error = 'Delai apres echec invalide (0 a 3650 jours).';
  } elseif ($duration < 1 || $duration > 600) {
    $error = 'Duree invalide (1 a 600 minutes).';
  } elseif ($count < 1 || $count > 200) {
    $error = 'Nombre de questions invalide (1 a 200).';
  } elseif ($hasDisplayOrderColumn && ($displayOrder < 0 || $displayOrder > 9999)) {
    $error = "Ordre d'affichage invalide (0 a 9999).";
  } elseif ($hasProfileColumn && (function_exists('mb_strlen') ? mb_strlen($profile) : strlen($profile)) > 255) {
    $error = 'Profil trop long (max 255 caracteres).';
  } elseif ($hasBadgeImageColumn && $badgeImageFilename !== '' && !empty($badgeImageOptions) && !in_array($badgeImageFilename, $badgeImageOptions, true)) {
    $error = 'Image de badge invalide.';
  } elseif ($hasRulesColumn && !empty($ruleRows) && count($ruleRows) > 20) {
    $error = 'Maximum 20 paliers de regles.';
  } elseif (
    $hasRulesColumn
    && !empty($ruleRows)
    && array_diff(
      array_map(static fn(array $row): string => normalize_question_need((string)($row['need'] ?? '')), $ruleRows),
      $knownNeeds
    )
  ) {
    $error = 'Categorie indisponible dans ce programme.';
  } elseif (
    $hasRulesColumn
    && !empty($ruleRows)
    && ($ruleError = pack_create_validate_rule_rows($ruleRows, $count)) !== ''
  ) {
    $error = $ruleError;
  } elseif ($hasNameColorColumn && $normalizedColor === null) {
    $error = 'Couleur invalide.';
  } else {
    $existsStmt = $pdo->prepare("SELECT id FROM packages WHERE UPPER(name)=UPPER(?) LIMIT 1");
    $existsStmt->execute([$name]);
    if ($existsStmt->fetch()) {
      $error = 'Un pack avec ce nom existe deja.';
    } else {
      $selectionRulesJson = null;
      if ($hasRulesColumn && !empty($ruleRows)) {
        $selectionRulesJson = pack_create_rule_rows_to_json($ruleRows, $count);
        if ($selectionRulesJson === '') {
          $error = 'Regles de tirage invalides.';
        }
      }

      if ($error !== '') {
        // keep form state
      } else {
        $columns = ['name', 'pass_threshold_percent', 'duration_limit_minutes', 'selection_count', 'is_active'];
        $values = [$name, $threshold, $duration, $count, $isActive];
        if ($hasCertValidityDaysColumn) {
          $columns[] = 'cert_validity_days';
          $values[] = $certValidityDays;
        }
        if ($hasFailedCooldownDaysColumn) {
          $columns[] = 'failed_cooldown_days';
          $values[] = $failedCooldownDays;
        }
        if ($hasProgramIdColumn) {
          $columns[] = 'program_id';
          $values[] = $activeProgramId;
        }
        if ($hasAntiRepeatSessionsColumn) {
          $columns[] = 'anti_repeat_sessions';
          $values[] = 1;
        }
        if ($hasNameColorColumn) {
          $columns[] = 'name_color_hex';
          $values[] = $nameColorHex;
        }
        if ($hasProfileColumn) {
          $columns[] = 'profile';
          $values[] = ($profile !== '') ? $profile : null;
        }
        if ($hasDisplayOrderColumn) {
          $columns[] = 'display_order';
          $values[] = $displayOrder;
        }
        if ($hasBadgeImageColumn) {
          $columns[] = 'badge_image_filename';
          $values[] = ($badgeImageFilename !== '') ? $badgeImageFilename : null;
        }
        if ($hasRulesColumn) {
          $columns[] = 'selection_rules_json';
          $values[] = $selectionRulesJson;
        }
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $ins = $pdo->prepare("
          INSERT INTO packages(" . implode(',', $columns) . ")
          VALUES($placeholders)
        ");
        $ins->execute($values);
        $newPackageId = (int)$pdo->lastInsertId();
        if ($hasProgramPackageLinksTable && $activeProgramId > 0 && $newPackageId > 0) {
          $linkStmt = $pdo->prepare("
            INSERT IGNORE INTO program_package_links(program_id, package_id, is_active)
            VALUES(?, ?, 1)
          ");
          $linkStmt->execute([$activeProgramId, $newPackageId]);
        }
      }
      if ($error === '') {
        $redirect = '/admin/packages.php?created=1';
        if ($activeProgramId > 0) {
          $redirect .= '&program_id=' . (int)$activeProgramId;
        }
        header('Location: ' . $redirect);
        exit;
      }
    }
  }
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('admin.pack.create_title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1"><?= h(t('admin.pack.create_title', [], $lang)) ?></h2>
          <p class="sub"><?= h(t('admin.pack.create_subtitle', [], $lang)) ?></p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('packages'); ?>
        </div>
      </div>

      <hr class="separator">

      <?php if ($error !== ''): ?>
        <p class="error" style="margin:0 0 10px;"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post" class="users-create-form">
        <section class="pack-config-section">
          <h3 class="pack-config-title"><?= h(t('admin.pack.section_config', [], $lang)) ?></h3>
          <div class="pack-config-grid">
            <article class="pack-config-card">
              <h4 class="pack-config-card-title"><?= h(t('admin.pack.card_info', [], $lang)) ?></h4>
              <div class="pack-config-fields">
                <div>
                  <label class="label" for="create-pack-name"><?= h(t('admin.pack.field_name', [], $lang)) ?></label>
                  <input class="input" id="create-pack-name" name="name" type="text" maxlength="255" required value="<?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <?php if ($hasProfileColumn): ?>
                  <div>
                    <label class="label" for="create-pack-profile"><?= h(t('admin.pack.field_profile', [], $lang)) ?></label>
                    <input class="input" id="create-pack-profile" name="profile" type="text" maxlength="255" value="<?= htmlspecialchars((string)$profile, ENT_QUOTES, 'UTF-8') ?>">
                  </div>
                <?php endif; ?>
                <div>
                  <label class="label" for="create-pack-active"><?= h(t('admin.common.status', [], $lang)) ?></label>
                  <select class="input" id="create-pack-active" name="is_active">
                    <option value="1" <?= $isActive === 1 ? 'selected' : '' ?>><?= h(t('admin.common.active', [], $lang)) ?></option>
                    <option value="0" <?= $isActive === 0 ? 'selected' : '' ?>><?= h(t('admin.common.inactive', [], $lang)) ?></option>
                  </select>
                </div>
                <?php if ($hasCertValidityDaysColumn): ?>
                  <div>
                    <label class="label" for="create-pack-cert-validity-days"><?= h(t('admin.pack.field_validity', [], $lang)) ?></label>
                    <input class="input" id="create-pack-cert-validity-days" name="cert_validity_days" type="number" min="1" max="3650" required value="<?= (int)$certValidityDays ?>">
                  </div>
                <?php endif; ?>
                <?php if ($hasFailedCooldownDaysColumn): ?>
                  <div>
                    <label class="label" for="create-pack-failed-cooldown-days"><?= h(t('admin.pack.field_cooldown', [], $lang)) ?></label>
                    <input class="input" id="create-pack-failed-cooldown-days" name="failed_cooldown_days" type="number" min="0" max="3650" required value="<?= (int)$failedCooldownDays ?>">
                  </div>
                <?php endif; ?>
              </div>
            </article>

            <article class="pack-config-card">
              <h4 class="pack-config-card-title"><?= h(t('admin.pack.card_eval', [], $lang)) ?></h4>
              <div class="pack-config-fields">
                <div>
                  <label class="label" for="create-pack-threshold"><?= h(t('admin.pack.field_threshold_short', [], $lang)) ?></label>
                  <input class="input" id="create-pack-threshold" name="pass_threshold_percent" type="number" min="0" max="100" required value="<?= (int)$threshold ?>">
                </div>
                <div>
                  <label class="label" for="create-pack-duration"><?= h(t('admin.pack.field_duration_short', [], $lang)) ?></label>
                  <input class="input" id="create-pack-duration" name="duration_limit_minutes" type="number" min="1" max="600" required value="<?= (int)$duration ?>">
                </div>
                <div>
                  <label class="label" for="create-pack-count"><?= h(t('admin.pack.field_count', [], $lang)) ?></label>
                  <input class="input" id="create-pack-count" name="selection_count" type="number" min="1" max="200" required value="<?= (int)$count ?>">
                </div>
              </div>
            </article>

            <article class="pack-config-card pack-config-card-wide">
              <h4 class="pack-config-card-title"><?= h(t('admin.pack.card_appearance', [], $lang)) ?></h4>
              <div class="pack-config-fields pack-appearance-fields">
                <?php if ($hasNameColorColumn): ?>
                  <div class="pack-color-field">
                    <label class="label" for="create-pack-color"><?= h(t('admin.pack.field_color', [], $lang)) ?></label>
                    <div class="pack-color-row">
                      <input class="pack-color-input" id="create-pack-color" name="name_color_hex" type="color" value="<?= htmlspecialchars((string)$nameColorHex, ENT_QUOTES, 'UTF-8') ?>">
                      <span class="pack-color-swatch" data-pack-color-preview style="background:<?= h($nameColorHex) ?>;"></span>
                      <span class="pack-color-code" data-pack-color-code><?= h(strtoupper($nameColorHex)) ?></span>
                    </div>
                  </div>
                <?php endif; ?>
                <?php if ($hasBadgeImageColumn): ?>
                  <div class="badge-picker-field">
                    <label class="label"><?= h(t('admin.pack.field_badge', [], $lang)) ?></label>
                    <input type="hidden" name="badge_image_filename" value="<?= h($badgeImageFilename) ?>">
                    <?php $libraryReturn = '/admin/pack_create.php' . ($activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId : ''); ?>
                    <a
                      id="pack-create-badge-picker"
                      class="badge-current-link"
                      data-base-return="<?= h($libraryReturn) ?>"
                      href="/admin/badge_library.php?return=<?= h(urlencode($libraryReturn)) ?>"
                    >
                      <?php if ($badgeImageFilename !== ''): ?>
                        <span class="badge-current">
                          <img src="/assets/badges/<?= h(rawurlencode($badgeImageFilename)) ?>" alt="<?= h($badgeImageFilename) ?>">
                          <span class="badge-current-meta"><?= h($badgeImageFilename) ?></span>
                        </span>
                      <?php else: ?>
                        <span class="badge-current">
                          <span class="badge-current-meta"><?= h(t('admin.pack.no_badge_selected', [], $lang)) ?></span>
                        </span>
                      <?php endif; ?>
                    </a>
                    <?php if (!$badgeImageOptions): ?>
                      <p class="small" style="margin-top:8px;"><?= h(t('admin.pack.no_badge_available', [], $lang)) ?></p>
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
                <span><?= h(t('admin.pack.rules_title', [], $lang)) ?></span>
                <span class="order-help-tip" tabindex="0" aria-label="<?= h(t('admin.pack.rules_title', [], $lang)) ?>">
                  i
                  <span class="order-help-bubble"><?= h(t('admin.pack.rules_help', [], $lang)) ?></span>
                </span>
              </span>
            </h3>
            <div class="rule-toolbar">
              <div class="rule-template-group">
                <label class="label" for="rule-template"><?= h(t('admin.pack.rules_model', [], $lang)) ?></label>
                <select class="input" id="rule-template" name="rule_template">
                  <option value=""><?= h(t('admin.pack.rules_none', [], $lang)) ?></option>
                  <?php foreach (array_keys($ruleTemplates) as $tplName): ?>
                    <option value="<?= h($tplName) ?>" <?= $selectedTemplate === $tplName ? 'selected' : '' ?>><?= h($tplName) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="rule-toolbar-actions">
                <button class="btn ghost rule-action-btn rule-action-apply" type="button" id="apply-rule-template"><?= h(t('admin.pack.rules_apply', [], $lang)) ?></button>
                <button class="btn ghost rule-action-btn rule-action-add" type="button" id="add-rule-row"><?= h(t('admin.pack.rules_add', [], $lang)) ?></button>
              </div>
            </div>

            <div class="table-wrap rule-table-wrap">
              <table class="table questions-table rules-table" id="rule-rows-table">
                <thead>
                  <tr>
                    <th><?= h(t('admin.pack.rules_col_category', [], $lang)) ?></th>
                    <th><?= h(t('admin.pack.rules_col_levels', [], $lang)) ?></th>
                    <th>
                      <span class="order-help-wrap">
                        <span><?= h(t('admin.pack.rules_col_cumul', [], $lang)) ?></span>
                        <span class="order-help-tip" tabindex="0" aria-label="<?= h(t('admin.pack.rules_col_cumul', [], $lang)) ?>">
                          i
                          <span class="order-help-bubble"><?= h(t('admin.pack.rules_cumul_help', [], $lang)) ?></span>
                        </span>
                      </span>
                    </th>
                    <th><?= h(t('admin.common.action', [], $lang)) ?></th>
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
                          <?php foreach ($knownNeeds as $needOpt): ?>
                            <option value="<?= h($needOpt) ?>" <?= ((string)($row['need'] ?? '') === $needOpt) ? 'selected' : '' ?>><?= h($needOpt) ?></option>
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
                      <td>
                        <div class="rule-target-cell">
                          <input class="input rule-target-total" type="number" name="rule_target_total[]" min="0" max="200" value="<?= (int)($row['target_total'] ?? 0) ?>">
                          <span class="rule-target-meta">max. 0</span>
                        </div>
                        <p class="rule-target-warning" hidden></p>
                      </td>
                      <td>
                        <button class="btn ghost icon-btn danger rule-remove rule-remove-btn" type="button" aria-label="<?= h(t('admin.pack.rules_remove', [], $lang)) ?>" title="<?= h(t('admin.pack.rules_remove', [], $lang)) ?>">
                          <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                          </svg>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr class="rule-summary-row">
                    <td colspan="2" class="rule-summary-label-cell"><span class="rule-summary-label"><?= h(t('admin.pack.rules_summary', [], $lang)) ?></span></td>
                    <td class="rule-summary-value"><span id="rule-total-target" class="rule-summary-number">0</span></td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <p class="rule-builder-warning" id="rule-builder-warning" hidden></p>
          </section>
        <?php endif; ?>

        <div class="users-create-actions">
          <button class="btn" type="submit"><?= h(t('admin.pack.create_btn', [], $lang)) ?></button>
          <a class="btn ghost" href="/admin/packages.php<?= $activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId : '' ?>"><?= h(t('admin.common.cancel', [], $lang)) ?></a>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function () {
    var packCreateForm = document.querySelector('form.users-create-form');
    var badgePickerLink = document.getElementById('pack-create-badge-picker');

    function fieldValue(name) {
      if (!packCreateForm) return '';
      var el = packCreateForm.querySelector('[name="' + name + '"]');
      if (!el) return '';
      return String(el.value || '').trim();
    }

    function buildDraftRules() {
      var body = document.getElementById('rule-rows-body');
      if (!body) return [];
      var rows = [];
      body.querySelectorAll('.rule-row').forEach(function (row) {
        var need = row.querySelector('.rule-need');
        var target = row.querySelector('.rule-target-total');
        var levels = [];
        if (row.querySelector('.rule-level-1-check:checked')) levels.push(1);
        if (row.querySelector('.rule-level-2-check:checked')) levels.push(2);
        if (row.querySelector('.rule-level-3-check:checked')) levels.push(3);
        var needVal = need ? String(need.value || '').trim().toUpperCase() : '';
        var targetVal = target ? parseInt(String(target.value || '0'), 10) : 0;
        if (!needVal || !levels.length) return;
        rows.push({
          need: needVal,
          levels: levels,
          target_total: Number.isFinite(targetVal) && targetVal > 0 ? targetVal : 0
        });
      });
      return rows;
    }

    if (badgePickerLink) {
      badgePickerLink.addEventListener('click', function () {
        var baseReturn = badgePickerLink.getAttribute('data-base-return') || '/admin/pack_create.php';
        try {
          var returnUrl = new URL(baseReturn, window.location.origin);
          returnUrl.searchParams.set('draft_name', fieldValue('name'));
          returnUrl.searchParams.set('draft_profile', fieldValue('profile'));
          returnUrl.searchParams.set('draft_active', fieldValue('is_active') || '1');
          returnUrl.searchParams.set('draft_threshold', fieldValue('pass_threshold_percent') || '80');
          returnUrl.searchParams.set('draft_cert_validity_days', fieldValue('cert_validity_days') || '365');
          returnUrl.searchParams.set('draft_failed_cooldown_days', fieldValue('failed_cooldown_days') || '365');
          returnUrl.searchParams.set('draft_duration', fieldValue('duration_limit_minutes') || '120');
          returnUrl.searchParams.set('draft_count', fieldValue('selection_count') || '10');
          returnUrl.searchParams.set('draft_color', fieldValue('name_color_hex') || '#334155');
          returnUrl.searchParams.set('draft_template', fieldValue('rule_template'));
          var draftRules = buildDraftRules();
          if (draftRules.length > 0) {
            returnUrl.searchParams.set('draft_rules', JSON.stringify(draftRules));
          } else {
            returnUrl.searchParams.delete('draft_rules');
          }
          badgePickerLink.href = '/admin/badge_library.php?return=' + encodeURIComponent(returnUrl.pathname + returnUrl.search);
        } catch (e) {
          // keep fallback href
        }
      });
    }

    <?php if ($hasRulesColumn): ?>
    var ruleTemplates = <?= json_encode($ruleTemplates, JSON_UNESCAPED_UNICODE) ?>;
    var knownNeeds = <?= json_encode(array_values($knownNeeds), JSON_UNESCAPED_UNICODE) ?>;
    var availableQuestionCounts = <?= json_encode($availableQuestionCounts, JSON_UNESCAPED_UNICODE) ?>;
    var tbody = document.getElementById('rule-rows-body');
    var addRowBtn = document.getElementById('add-rule-row');
    var applyTemplateBtn = document.getElementById('apply-rule-template');
    var templateSelect = document.getElementById('rule-template');
    var countInput = document.querySelector('input[name="selection_count"]');
    var form = document.querySelector('form[method="post"]');
    var totalTargetEl = document.getElementById('rule-total-target');
    var ruleBuilderWarningEl = document.getElementById('rule-builder-warning');

    function bindRowActions(row) {
      var removeBtn = row.querySelector('.rule-remove');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          row.remove();
          syncRuleInputNames();
          updateRuleTotals();
          validateRuleTargets(false);
        });
      }
    }

    function updateRuleTotals() {
      if (!tbody) return;
      var totalTarget = 0;
      tbody.querySelectorAll('.rule-row').forEach(function (row) {
        var targetInput = row.querySelector('.rule-target-total');
        var targetVal = targetInput ? parseInt(String(targetInput.value || '0'), 10) : 0;
        if (Number.isFinite(targetVal) && targetVal > 0) {
          totalTarget += targetVal;
        }
      });
      if (totalTargetEl) totalTargetEl.textContent = String(totalTarget);
    }

    function cloneAvailableCounts() {
      var copy = {};
      Object.keys(availableQuestionCounts || {}).forEach(function (need) {
        var levels = availableQuestionCounts[need] || {};
        copy[need] = {
          1: parseInt(String(levels[1] || 0), 10) || 0,
          2: parseInt(String(levels[2] || 0), 10) || 0,
          3: parseInt(String(levels[3] || 0), 10) || 0
        };
      });
      return copy;
    }

    function getRuleRowNeed(row) {
      var needInput = row.querySelector('.rule-need');
      return needInput ? String(needInput.value || '').trim().toUpperCase() : '';
    }

    function getRuleRowLevels(row) {
      var levels = [];
      if (row.querySelector('.rule-level-1-check:checked')) levels.push(1);
      if (row.querySelector('.rule-level-2-check:checked')) levels.push(2);
      if (row.querySelector('.rule-level-3-check:checked')) levels.push(3);
      return levels;
    }

    function getRuleRowTargetValue(row) {
      var targetInput = row.querySelector('.rule-target-total');
      var raw = targetInput ? String(targetInput.value || '').trim() : '';
      var targetVal = raw === '' ? 0 : parseInt(raw, 10);
      if (!Number.isFinite(targetVal) || targetVal < 0) {
        return 0;
      }
      return targetVal;
    }

    function setRuleRowAvailability(row, stepMax, requestedStep) {
      var meta = row.querySelector('.rule-target-meta');
      var warning = row.querySelector('.rule-target-warning');
      var hasWarning = requestedStep > stepMax;
      if (meta) {
        meta.textContent = 'max. ' + stepMax;
      }
      row.classList.toggle('rule-row-warning', hasWarning);
      if (warning) {
        if (hasWarning) {
          warning.textContent = 'Risque de questions insuffisantes : ' + requestedStep + ' demandée(s), ' + stepMax + ' disponible(s) pour ce palier.';
          warning.hidden = false;
        } else {
          warning.textContent = '';
          warning.hidden = true;
        }
      }
      return hasWarning;
    }

    function updateRuleAvailabilityWarnings() {
      if (!tbody) return;
      var remainingByNeed = cloneAvailableCounts();
      var hasAnyWarning = false;
      tbody.querySelectorAll('.rule-row').forEach(function (row) {
        var need = getRuleRowNeed(row);
        var levels = getRuleRowLevels(row);
        var requestedStep = getRuleRowTargetValue(row);
        var stepMax = 0;

        levels.forEach(function (level) {
          if (!remainingByNeed[need]) {
            return;
          }
          stepMax += parseInt(String(remainingByNeed[need][level] || 0), 10) || 0;
        });

        if (setRuleRowAvailability(row, stepMax, requestedStep)) {
          hasAnyWarning = true;
        }

        var toConsume = Math.min(requestedStep, stepMax);
        levels.forEach(function (level) {
          if (!remainingByNeed[need] || toConsume <= 0) {
            return;
          }
          var available = parseInt(String(remainingByNeed[need][level] || 0), 10) || 0;
          var consumed = Math.min(available, toConsume);
          remainingByNeed[need][level] = available - consumed;
          toConsume -= consumed;
        });
      });

      if (ruleBuilderWarningEl) {
        if (hasAnyWarning) {
          ruleBuilderWarningEl.textContent = 'Certaines lignes demandent plus de questions que le stock actuellement disponible en base.';
          ruleBuilderWarningEl.hidden = false;
        } else {
          ruleBuilderWarningEl.textContent = '';
          ruleBuilderWarningEl.hidden = true;
        }
      }
    }

    function buildRowHtml(data) {
      var need = String(data.need || <?= json_encode($defaultNeed, JSON_UNESCAPED_UNICODE) ?>);
      var targetTotal = data.target_total || 0;
      var levels = Array.isArray(data.levels) ? data.levels : [1];
      var hasL1 = levels.indexOf(1) !== -1;
      var hasL2 = levels.indexOf(2) !== -1;
      var hasL3 = levels.indexOf(3) !== -1;
      var needOptions = knownNeeds.map(function (needOpt) {
        var escapedValue = String(needOpt).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        var selected = needOpt === need ? ' selected' : '';
        return '<option value="' + escapedValue + '"' + selected + '>' + escapedValue + '</option>';
      }).join('');

      return '' +
        '<tr class="rule-row">' +
          '<td>' +
            '<select class="input rule-need">' + needOptions + '</select>' +
          '</td>' +
          '<td class="rule-levels-cell">' +
            '<input type="hidden" class="rule-level-1-input" value="' + (hasL1 ? '1' : '0') + '">' +
            '<label class="rule-level-check"><input type="checkbox" class="rule-level-1-check"' + (hasL1 ? ' checked' : '') + '> L1</label>' +
            '<input type="hidden" class="rule-level-2-input" value="' + (hasL2 ? '1' : '0') + '">' +
            '<label class="rule-level-check"><input type="checkbox" class="rule-level-2-check"' + (hasL2 ? ' checked' : '') + '> L2</label>' +
            '<input type="hidden" class="rule-level-3-input" value="' + (hasL3 ? '1' : '0') + '">' +
            '<label class="rule-level-check"><input type="checkbox" class="rule-level-3-check"' + (hasL3 ? ' checked' : '') + '> L3</label>' +
          '</td>' +
          '<td>' +
            '<div class="rule-target-cell">' +
              '<input class="input rule-target-total" type="number" min="0" max="200" value="' + targetTotal + '">' +
              '<span class="rule-target-meta">max. 0</span>' +
            '</div>' +
            '<p class="rule-target-warning" hidden></p>' +
          '</td>' +
          '<td><button class="btn ghost icon-btn danger rule-remove rule-remove-btn" type="button" aria-label="Supprimer ce palier" title="Supprimer ce palier"><svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/></svg></button></td>' +
        '</tr>';
    }

    function addRuleRow(data) {
      if (!tbody) return;
      var wrap = document.createElement('tbody');
      wrap.innerHTML = buildRowHtml(data || {});
      var row = wrap.firstElementChild;
      tbody.appendChild(row);
      bindRowActions(row);
      syncRuleInputNames();
      updateRuleTotals();
      validateRuleTargets(false);
      updateRuleAvailabilityWarnings();
    }

    function syncRuleInputNames() {
      if (!tbody) return;
      var rows = tbody.querySelectorAll('.rule-row');
      rows.forEach(function (row, idx) {
        var need = row.querySelector('.rule-need');
        var target = row.querySelector('.rule-target-total');
        var l1Input = row.querySelector('.rule-level-1-input');
        var l2Input = row.querySelector('.rule-level-2-input');
        var l3Input = row.querySelector('.rule-level-3-input');
        var l1Check = row.querySelector('.rule-level-1-check');
        var l2Check = row.querySelector('.rule-level-2-check');
        var l3Check = row.querySelector('.rule-level-3-check');

        if (need) need.name = 'rule_need[' + idx + ']';
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

    function clearRuleTargetErrors() {
      if (!tbody) return;
      tbody.querySelectorAll('.rule-target-total').forEach(function (input) {
        input.classList.remove('rule-target-invalid');
      });
    }

    function validateRuleTargets(showPopup) {
      if (!tbody) return true;
      clearRuleTargetErrors();
      updateRuleTotals();

      var maxTotal = countInput ? parseInt(String(countInput.value || '0'), 10) : 0;
      if (!Number.isFinite(maxTotal) || maxTotal < 1) {
        maxTotal = 200;
      }

      var targetSum = 0;
      var invalidInput = null;
      var message = '';
      var rows = tbody.querySelectorAll('.rule-row');
      rows.forEach(function (row, idx) {
        if (invalidInput) return;
        var targetInput = row.querySelector('.rule-target-total');
        if (!targetInput) return;

        var raw = String(targetInput.value || '').trim();
        var targetVal = raw === '' ? 0 : parseInt(raw, 10);
        if (!Number.isFinite(targetVal)) {
          targetVal = 0;
        }

        var line = idx + 1;
        if (targetVal < 0 || targetVal > maxTotal) {
          invalidInput = targetInput;
          message = 'Cible cumulée invalide ligne ' + line + ' (0 à ' + maxTotal + ').';
          return;
        }

        if (targetVal > 0) {
          targetSum += targetVal;
          if (targetSum > maxTotal) {
            invalidInput = targetInput;
            message = 'Cible cumulée totale invalide (0 à ' + maxTotal + ').';
          }
        }
      });

      if (invalidInput) {
        invalidInput.classList.add('rule-target-invalid');
        if (showPopup) {
          window.alert(message);
        }
        return false;
      }

      return true;
    }

    if (tbody) {
      tbody.querySelectorAll('.rule-row').forEach(bindRowActions);
      syncRuleInputNames();
      updateRuleTotals();
      validateRuleTargets(false);
      updateRuleAvailabilityWarnings();
      tbody.addEventListener('input', function (e) {
        if (e.target && e.target.classList && (
          e.target.classList.contains('rule-target-total') ||
          e.target.classList.contains('rule-level-1-check') ||
          e.target.classList.contains('rule-level-2-check') ||
          e.target.classList.contains('rule-level-3-check') ||
          e.target.classList.contains('rule-need')
        )) {
          validateRuleTargets(false);
          updateRuleAvailabilityWarnings();
        }
      });
      tbody.addEventListener('change', function (e) {
        if (e.target && e.target.classList && (
          e.target.classList.contains('rule-target-total') ||
          e.target.classList.contains('rule-level-1-check') ||
          e.target.classList.contains('rule-level-2-check') ||
          e.target.classList.contains('rule-level-3-check') ||
          e.target.classList.contains('rule-need')
        )) {
          updateRuleAvailabilityWarnings();
        }
        if (e.target && e.target.classList && e.target.classList.contains('rule-target-total')) {
          if (!validateRuleTargets(true)) {
            e.target.focus();
          }
        }
      });
    }

    if (addRowBtn) {
      addRowBtn.addEventListener('click', function () {
        addRuleRow({ need: <?= json_encode($defaultNeed, JSON_UNESCAPED_UNICODE) ?>, levels: [1], target_total: 0 });
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
        updateRuleTotals();
        validateRuleTargets(false);
        updateRuleAvailabilityWarnings();
      });
    }

    if (countInput) {
      countInput.addEventListener('input', function () {
        validateRuleTargets(false);
        updateRuleAvailabilityWarnings();
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        syncRuleInputNames();
        if (!validateRuleTargets(true)) {
          e.preventDefault();
        }
      });
    }

    var colorInput = document.getElementById('create-pack-color');
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
