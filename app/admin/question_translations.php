<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
ensure_question_translation_schema($pdo);

$packageId = (int)($_GET['package_id'] ?? 0);
$needFilter = normalize_question_need((string)($_GET['need'] ?? ''));
$langFilter = question_translation_normalize_lang((string)($_GET['lang_filter'] ?? 'en'));
$stateFilter = trim((string)($_GET['state_filter'] ?? 'ALL'));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;

if (!in_array($langFilter, ['en', 'es', 'jp'], true)) {
  $langFilter = 'en';
}
if (!in_array($stateFilter, ['ALL', 'complete', 'stale', 'partial', 'missing'], true)) {
  $stateFilter = 'ALL';
}

$packages = $pdo->query("
  SELECT id, name, name_color_hex, selection_rules_json
  FROM packages
  ORDER BY name ASC
")->fetchAll() ?: [];
$validPackageIds = array_map(static fn(array $pkg): int => (int)$pkg['id'], $packages);
if ($packageId > 0 && !in_array($packageId, $validPackageIds, true)) {
  $packageId = 0;
}

$needsRows = $pdo->query("
  SELECT DISTINCT TRIM(need) AS need_name
  FROM questions
  WHERE need IS NOT NULL AND TRIM(need) <> ''
  ORDER BY need_name ASC
")->fetchAll() ?: [];
$allNeeds = [];
foreach ($needsRows as $needRow) {
  $normalized = normalize_question_need((string)($needRow['need_name'] ?? ''));
  if ($normalized !== '') {
    $allNeeds[] = $normalized;
  }
}
$allNeeds = array_values(array_unique($allNeeds));
if ($needFilter !== '' && !in_array($needFilter, $allNeeds, true)) {
  $needFilter = '';
}

$where = [];
$params = [];
if ($needFilter !== '') {
  $where[] = "q.need = ?";
  $params[] = $needFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$questionQuery = "
  SELECT
    q.id,
    q.external_id,
    q.text,
    q.need,
    q.level,
    q.package_id
  FROM questions q
  $whereSql
  ORDER BY q.id DESC
";
$questionStmt = $pdo->prepare($questionQuery);
$questionStmt->execute($params);
$questionRows = $questionStmt->fetchAll() ?: [];

function translation_package_usage_definitions(array $packages): array {
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

function translation_packages_for_question(array $question, array $packageDefinitions): array {
  $matches = [];
  $questionNeed = normalize_question_need((string)($question['need'] ?? ''));
  $questionLevel = (int)($question['level'] ?? 0);
  $questionPackageId = (int)($question['package_id'] ?? 0);

  foreach ($packageDefinitions as $definition) {
    if (($definition['mode'] ?? 'legacy') === 'rules') {
      foreach (($definition['rules'] ?? []) as $rule) {
        if ($questionNeed === (string)($rule['need'] ?? '') && in_array($questionLevel, $rule['levels'] ?? [], true)) {
          $matches[] = [
            'id' => (int)$definition['id'],
            'name' => (string)$definition['name'],
            'color' => (string)$definition['color'],
          ];
          break;
        }
      }
      continue;
    }

    if ($questionPackageId > 0 && $questionPackageId === (int)$definition['id']) {
      $matches[] = [
        'id' => (int)$definition['id'],
        'name' => (string)$definition['name'],
        'color' => (string)$definition['color'],
      ];
    }
  }

  return $matches;
}

function translation_package_usage_meta(array $usedPackages): array {
  $count = count($usedPackages);
  $names = array_values(array_filter(array_map(static fn(array $pkg): string => trim((string)($pkg['name'] ?? '')), $usedPackages), static fn(string $name): bool => $name !== ''));
  $tooltip = implode(', ', $names);

  if ($count === 0) {
    return [
      'label' => '',
      'title' => '',
      'class' => 'translation-pack-usage-empty',
      'color' => '',
      'is_package' => false,
    ];
  }

  if ($count === 1) {
    return [
      'label' => (string)($names[0] ?? ''),
      'title' => (string)($names[0] ?? ''),
      'class' => 'translation-pack-usage-single',
      'color' => (string)($usedPackages[0]['color'] ?? ''),
      'is_package' => true,
    ];
  }

  return [
    'label' => $count . ' packs',
    'title' => $tooltip,
    'class' => 'translation-pack-usage-multi pill info',
    'color' => '',
    'is_package' => false,
  ];
}

$packageDefinitions = translation_package_usage_definitions($packages);
$filteredRows = [];
foreach ($questionRows as $row) {
  $questionId = (int)($row['id'] ?? 0);
  $usedPackages = translation_packages_for_question($row, $packageDefinitions);

  if ($packageId > 0) {
    $usedPackageIds = array_map(static fn(array $pkg): int => (int)($pkg['id'] ?? 0), $usedPackages);
    if (!in_array($packageId, $usedPackageIds, true)) {
      continue;
    }
  }

  $statuses = [];
  foreach (['en', 'es', 'jp'] as $langCode) {
    $statuses[$langCode] = question_translation_status($pdo, $questionId, $langCode);
  }
  if ($stateFilter !== 'ALL' && ($statuses[$langFilter] ?? 'missing') !== $stateFilter) {
    continue;
  }

  $filteredRows[] = [
    'id' => $questionId,
    'external_id' => $row['external_id'],
    'text' => (string)($row['text'] ?? ''),
    'need' => (string)($row['need'] ?? ''),
    'level' => (int)($row['level'] ?? 0),
    'used_packages' => $usedPackages,
    'usage_meta' => translation_package_usage_meta($usedPackages),
    'statuses' => $statuses,
  ];
}

$totalQuestions = count($filteredRows);
$totalPages = max(1, (int)ceil($totalQuestions / $limit));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $limit;
$coverageRows = array_slice($filteredRows, $offset, $limit);

$allQuestionIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $filteredRows);
$summary = [
  'en' => ['complete' => 0, 'stale' => 0, 'partial' => 0, 'missing' => 0],
  'es' => ['complete' => 0, 'stale' => 0, 'partial' => 0, 'missing' => 0],
  'jp' => ['complete' => 0, 'stale' => 0, 'partial' => 0, 'missing' => 0],
];
foreach ($filteredRows as $row) {
  foreach (['en', 'es', 'jp'] as $langCode) {
    $status = (string)($row['statuses'][$langCode] ?? 'missing');
    if (!isset($summary[$langCode][$status])) {
      $summary[$langCode][$status] = 0;
    }
    $summary[$langCode][$status]++;
  }
}

function translation_status_meta(string $status): array {
  return match ($status) {
    'complete' => ['label' => 'A jour', 'class' => 'pill success'],
    'stale' => ['label' => 'A revoir', 'class' => 'pill warning'],
    'partial' => ['label' => 'Partielle', 'class' => 'pill info'],
    default => ['label' => 'Manquante', 'class' => 'pill danger'],
  };
}

function translation_cover_query(array $overrides = []): string {
  $params = $_GET;
  unset($params['page']);
  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '' || $value === 0) {
      unset($params[$key]);
      continue;
    }
    $params[$key] = $value;
  }
  return '/admin/question_translations.php' . ($params ? ('?' . http_build_query($params)) : '');
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Couverture traductions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1">Admin &middot; Couverture traductions</h2>
        <p class="sub">Suivi global des statuts EN / ES / JA sur les questions.</p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('translations'); ?>
      </div>
    </div>

    <hr class="separator">

    <div class="admin-stats-grid">
      <?php foreach (['en' => 'EN', 'es' => 'ES', 'jp' => 'JA'] as $langCode => $langLabel): ?>
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h($langLabel) ?></span>
          <strong class="admin-stat-value"><?= (int)($summary[$langCode]['complete'] ?? 0) ?> / <?= (int)count($allQuestionIds) ?></strong>
          <div class="translation-summary-meta">
            <span class="pill success">A jour <?= (int)($summary[$langCode]['complete'] ?? 0) ?></span>
            <span class="pill warning">A revoir <?= (int)($summary[$langCode]['stale'] ?? 0) ?></span>
            <span class="pill info">Partielle <?= (int)($summary[$langCode]['partial'] ?? 0) ?></span>
            <span class="pill danger">Manquante <?= (int)($summary[$langCode]['missing'] ?? 0) ?></span>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="admin-page-layout">
      <section class="admin-section-panel">
        <div class="section-head admin-section-head">
          <div>
            <h3 class="h1">Filtres</h3>
          </div>
        </div>

        <form method="get" class="admin-panel-surface audit-config-panel">
          <div class="audit-filter-grid audit-filter-grid-main">
            <div>
              <label class="label" for="translation_package_id">Packs</label>
              <select class="input" id="translation_package_id" name="package_id">
                <option value="0" <?= $packageId === 0 ? 'selected' : '' ?>>Tous</option>
                <?php foreach ($packages as $pkg): ?>
                  <option value="<?= (int)$pkg['id'] ?>" <?= $packageId === (int)$pkg['id'] ? 'selected' : '' ?>><?= h((string)$pkg['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label" for="translation_need">Catégorie</label>
              <select class="input" id="translation_need" name="need">
                <option value="" <?= $needFilter === '' ? 'selected' : '' ?>>Toutes</option>
                <?php foreach ($allNeeds as $need): ?>
                  <option value="<?= h($need) ?>" <?= $needFilter === $need ? 'selected' : '' ?>><?= h($need) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label" for="translation_lang_filter">Langue pilote</label>
              <select class="input" id="translation_lang_filter" name="lang_filter">
                <option value="en" <?= $langFilter === 'en' ? 'selected' : '' ?>>EN</option>
                <option value="es" <?= $langFilter === 'es' ? 'selected' : '' ?>>ES</option>
                <option value="jp" <?= $langFilter === 'jp' ? 'selected' : '' ?>>JA</option>
              </select>
            </div>
            <div>
              <label class="label" for="translation_state_filter">Statut</label>
              <select class="input" id="translation_state_filter" name="state_filter">
                <option value="ALL" <?= $stateFilter === 'ALL' ? 'selected' : '' ?>>Tous</option>
                <option value="complete" <?= $stateFilter === 'complete' ? 'selected' : '' ?>>A jour</option>
                <option value="stale" <?= $stateFilter === 'stale' ? 'selected' : '' ?>>A revoir</option>
                <option value="partial" <?= $stateFilter === 'partial' ? 'selected' : '' ?>>Partielle</option>
                <option value="missing" <?= $stateFilter === 'missing' ? 'selected' : '' ?>>Manquante</option>
              </select>
            </div>
          </div>
          <div class="filters-actions audit-config-actions">
            <button class="btn" type="submit">Appliquer</button>
            <a class="btn ghost" href="/admin/question_translations.php">Reset</a>
          </div>
        </form>
      </section>

      <section class="admin-section-panel">
        <div class="section-head admin-section-head">
          <div>
            <h3 class="h1">Questions suivies</h3>
            <p class="sub sessions-meta">Page <?= (int)$page ?> / <?= (int)$totalPages ?> (<?= (int)$totalQuestions ?> question(s))</p>
          </div>
        </div>

        <div class="table-wrap admin-table-panel">
          <?php if (!$coverageRows): ?>
            <p class="empty-state">Aucune question pour ces filtres.</p>
          <?php else: ?>
            <table class="table questions-table questions-admin-table translation-coverage-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Question</th>
                  <th>Catégorie</th>
                  <th>Packs</th>
                  <th>EN</th>
                  <th>ES</th>
                  <th>JA</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($coverageRows as $row): ?>
                  <tr>
                    <td><?= $row['external_id'] === null || $row['external_id'] === '' ? '-' : (int)$row['external_id'] ?></td>
                    <td><?= h(mb_strimwidth((string)$row['text'], 0, 110, '...', 'UTF-8')) ?></td>
                    <td><?= h((string)$row['need']) ?></td>
                    <td>
                      <?php $usageMeta = $row['usage_meta'] ?? []; ?>
                      <?php if (!empty($usageMeta['is_package'])): ?>
                        <span title="<?= h((string)($usageMeta['title'] ?? '')) ?>" style="<?= h(package_label_style((string)($usageMeta['label'] ?? ''), (string)($usageMeta['color'] ?? ''))) ?>"><?= h((string)($usageMeta['label'] ?? '')) ?></span>
                      <?php elseif ((string)($usageMeta['label'] ?? '') !== ''): ?>
                        <span class="<?= h((string)($usageMeta['class'] ?? 'pill info')) ?>" title="<?= h((string)($usageMeta['title'] ?? '')) ?>"><?= h((string)($usageMeta['label'] ?? '')) ?></span>
                      <?php else: ?>
                        <span class="translation-pack-usage-empty"></span>
                      <?php endif; ?>
                    </td>
                    <?php foreach (['en', 'es', 'jp'] as $langCode): ?>
                      <?php $meta = translation_status_meta((string)($row['statuses'][$langCode] ?? 'missing')); ?>
                      <td><span class="<?= h($meta['class']) ?>"><?= h($meta['label']) ?></span></td>
                    <?php endforeach; ?>
                    <td class="actions-cell">
                      <a class="btn ghost icon-btn" href="/admin/question_edit.php?id=<?= (int)$row['id'] ?>&return=<?= h(urlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/question_translations.php'))) ?>" aria-label="Modifier la question" title="Modifier la question">
                        <svg class="icon-edit" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                          <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm2.92 2.33H5v-.92l8.06-8.06.92.92L5.92 19.58zM20.71 7.04a1.003 1.003 0 0 0 0-1.42l-2.34-2.34a1.003 1.003 0 0 0-1.42 0l-1.13 1.13 3.75 3.75 1.14-1.12z"/>
                        </svg>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
          <div class="sessions-pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
              <a class="btn ghost<?= $i === $page ? ' is-active' : '' ?>" href="<?= h(translation_cover_query(['page' => $i])) ?>"><?= (int)$i ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>
</body>
</html>
