<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin_area();
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
ensure_question_translation_schema($pdo);
ensure_program_source_language_schema($pdo);
$activeProgramId = auth_admin_program_context($pdo, $adminUser, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
$programSourceLang = program_source_lang($pdo, $activeProgramId);
$translationLangs = question_translation_target_langs($programSourceLang);
$packagesWhereSql = $activeProgramId > 0
  ? ('WHERE ' . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false))
  : '';

$packageId = (int)($_GET['package_id'] ?? 0);
$needFilter = normalize_question_need((string)($_GET['need'] ?? ''));
$langFilter = question_translation_normalize_lang((string)($_GET['lang_filter'] ?? ''));
$stateFilter = trim((string)($_GET['state_filter'] ?? 'ALL'));
$idFilter = (int)($_GET['id_filter'] ?? 0);
$searchQuery = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;

if ($langFilter !== '' && !array_key_exists($langFilter, $translationLangs)) {
  $langFilter = '';
}
if (!in_array($stateFilter, ['ALL', 'complete', 'stale', 'partial', 'missing'], true)) {
  $stateFilter = 'ALL';
}

$packages = $pdo->query("
  SELECT pk.id, pk.name, pk.name_color_hex, pk.selection_rules_json
  FROM packages pk
  $packagesWhereSql
  ORDER BY pk.name ASC
")->fetchAll() ?: [];
$validPackageIds = array_map(static fn(array $pkg): int => (int)$pkg['id'], $packages);
if ($packageId > 0 && !in_array($packageId, $validPackageIds, true)) {
  $packageId = 0;
}

$questionProgramScopeSql = ($activeProgramId > 0 && auth_program_question_links_enabled($pdo))
  ? auth_program_question_scope_sql($pdo, $activeProgramId, 'q')
  : '1 = 1';

$needsRows = $pdo->query("
  SELECT DISTINCT TRIM(q.need) AS need_name
  FROM questions q
  WHERE q.need IS NOT NULL
    AND TRIM(q.need) <> ''
    AND $questionProgramScopeSql
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
$where[] = $questionProgramScopeSql;
if ($needFilter !== '') {
  $where[] = "q.need = ?";
  $params[] = $needFilter;
}
if ($idFilter > 0) {
  $where[] = "(q.external_id = ? OR q.id = ?)";
  $params[] = $idFilter;
  $params[] = $idFilter;
}
if ($searchQuery !== '') {
  $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $searchQuery) . '%';
  $where[] = "(q.text LIKE ?
    OR q.explanation LIKE ?
    OR EXISTS (SELECT 1 FROM question_options qo WHERE qo.question_id = q.id AND qo.option_text LIKE ?)
    OR EXISTS (SELECT 1 FROM question_translations qt WHERE qt.question_id = q.id AND (qt.question_text LIKE ? OR qt.explanation LIKE ?))
    OR EXISTS (SELECT 1 FROM question_option_translations qot JOIN question_options qo2 ON qo2.id = qot.option_id WHERE qo2.question_id = q.id AND qot.option_text LIKE ?))";
  array_push($params, $like, $like, $like, $like, $like, $like);
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

function translation_package_usage_meta(array $usedPackages, string $lang = 'fr'): array {
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
    'label' => t('admin.translations.n_packs', ['n' => $count], $lang),
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
  if ($activeProgramId > 0 && !$usedPackages) {
    continue;
  }

  if ($packageId > 0) {
    $usedPackageIds = array_map(static fn(array $pkg): int => (int)($pkg['id'] ?? 0), $usedPackages);
    if (!in_array($packageId, $usedPackageIds, true)) {
      continue;
    }
  }

  $statuses = [];
  foreach ($translationLangs as $langCode => $_langLabel) {
    $statuses[$langCode] = question_translation_status($pdo, $questionId, $langCode, $programSourceLang);
  }
  if ($stateFilter !== 'ALL' && $langFilter !== '' && ($statuses[$langFilter] ?? 'missing') !== $stateFilter) {
    continue;
  }

  $filteredRows[] = [
    'id' => $questionId,
    'external_id' => $row['external_id'],
    'text' => (string)($row['text'] ?? ''),
    'need' => (string)($row['need'] ?? ''),
    'level' => (int)($row['level'] ?? 0),
    'used_packages' => $usedPackages,
    'usage_meta' => translation_package_usage_meta($usedPackages, $lang),
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
$summary = [];
foreach ($translationLangs as $langCode => $_langLabel) {
  $summary[$langCode] = ['complete' => 0, 'stale' => 0, 'partial' => 0, 'missing' => 0];
}
foreach ($filteredRows as $row) {
  foreach ($translationLangs as $langCode => $_langLabel) {
    $status = (string)($row['statuses'][$langCode] ?? 'missing');
    if (!isset($summary[$langCode][$status])) {
      $summary[$langCode][$status] = 0;
    }
    $summary[$langCode][$status]++;
  }
}

function translation_status_meta(string $status, string $lang = 'fr'): array {
  return match ($status) {
    'complete' => ['label' => t('admin.translations.stat_ok', [], $lang), 'class' => 'pill success'],
    'stale' => ['label' => t('admin.translations.stat_stale', [], $lang), 'class' => 'pill warning'],
    'partial' => ['label' => t('admin.translations.stat_partial', [], $lang), 'class' => 'pill info'],
    default => ['label' => t('admin.translations.stat_missing', [], $lang), 'class' => 'pill danger'],
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

function translation_import_url(int $activeProgramId): string {
  $params = ['mode' => 'translation'];
  if ($activeProgramId > 0) {
    $params['program_id'] = (int)$activeProgramId;
  }
  return '/admin/import_questions.php?' . http_build_query($params);
}

function translation_export_url(int $activeProgramId): string {
  $params = ['export' => '1'];
  if ($activeProgramId > 0) {
    $params['program_id'] = $activeProgramId;
  }
  return '/admin/questions.php?' . http_build_query($params);
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('admin.translations.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var langSel = document.getElementById('translation_lang_filter');
    var stateSel = document.getElementById('translation_state_filter');
    if (!langSel || !stateSel) return;
    function syncState() {
      stateSel.disabled = langSel.value === '';
      if (stateSel.disabled) stateSel.value = 'ALL';
    }
    langSel.addEventListener('change', syncState);
    syncState();
  });
  </script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card admin-page-shell">
    <div class="admin-head admin-page-hero">
      <div class="admin-head-copy">
        <p class="admin-page-eyebrow"><?= h(t('admin.common.program', [], $lang)) ?></p>
        <h2 class="h1"><?= h(t('admin.translations.title', [], $lang)) ?></h2>
        <p class="sub"><?= h(t('admin.translations.subtitle', ['source' => question_translation_lang_label($programSourceLang)], $lang)) ?></p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('translations'); ?>
      </div>
    </div>

    <hr class="separator">

    <div class="admin-stats-grid">
      <?php foreach ($translationLangs as $langCode => $langLabel): ?>
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h($langLabel) ?></span>
          <strong class="admin-stat-value"><?= (int)($summary[$langCode]['complete'] ?? 0) ?> / <?= (int)count($allQuestionIds) ?></strong>
          <div class="translation-summary-meta">
            <span class="pill success"><?= h(t('admin.translations.stat_ok', [], $lang)) ?> <?= (int)($summary[$langCode]['complete'] ?? 0) ?></span>
            <span class="pill warning"><?= h(t('admin.translations.stat_stale', [], $lang)) ?> <?= (int)($summary[$langCode]['stale'] ?? 0) ?></span>
            <span class="pill info"><?= h(t('admin.translations.stat_partial', [], $lang)) ?> <?= (int)($summary[$langCode]['partial'] ?? 0) ?></span>
            <span class="pill danger"><?= h(t('admin.translations.stat_missing', [], $lang)) ?> <?= (int)($summary[$langCode]['missing'] ?? 0) ?></span>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="admin-page-layout">
      <section class="admin-section-panel">
        <div class="admin-panel-toolbar">
          <div>
            <h3 class="h1" style="margin:0;"><?= h(t('admin.translations.catalog_title', [], $lang)) ?></h3>
            <p class="sub" style="margin:6px 0 0;"><?= h(t('admin.translations.catalog_subtitle', [], $lang)) ?></p>
          </div>
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <a class="btn ghost" href="<?= h(translation_export_url($activeProgramId)) ?>"><?= h(t('admin.common.export_csv', [], $lang)) ?></a>
            <a class="btn admin-primary-action-btn" href="<?= h(translation_import_url($activeProgramId)) ?>">+ <?= h(t('admin.common.import', [], $lang)) ?></a>
          </div>
        </div>

        <form method="get" class="admin-panel-surface audit-config-panel">
          <?php if ($activeProgramId > 0): ?><input type="hidden" name="program_id" value="<?= (int)$activeProgramId ?>"><?php endif; ?>
          <div class="audit-filter-grid audit-filter-grid-main">
            <div>
              <label class="label" for="translation_package_id"><?= h(t('admin.translations.filter_packs', [], $lang)) ?></label>
              <select class="input" id="translation_package_id" name="package_id">
                <option value="0" <?= $packageId === 0 ? 'selected' : '' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
                <?php foreach ($packages as $pkg): ?>
                  <option value="<?= (int)$pkg['id'] ?>" <?= $packageId === (int)$pkg['id'] ? 'selected' : '' ?>><?= h((string)$pkg['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label" for="translation_need"><?= h(t('admin.questions.col_category', [], $lang)) ?></label>
              <select class="input" id="translation_need" name="need">
                <option value="" <?= $needFilter === '' ? 'selected' : '' ?>><?= h(t('admin.translations.filter_all_cats', [], $lang)) ?></option>
                <?php foreach ($allNeeds as $need): ?>
                  <option value="<?= h($need) ?>" <?= $needFilter === $need ? 'selected' : '' ?>><?= h($need) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label" for="translation_lang_filter"><?= h(t('admin.translations.filter_lang', [], $lang)) ?></label>
              <select class="input" id="translation_lang_filter" name="lang_filter">
                <option value="" <?= $langFilter === '' ? 'selected' : '' ?>><?= h(t('admin.translations.filter_all_langs', [], $lang)) ?></option>
                <?php foreach ($translationLangs as $langCode => $langLabel): ?>
                  <option value="<?= h($langCode) ?>" <?= $langFilter === $langCode ? 'selected' : '' ?>><?= h($langLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label" for="translation_state_filter"><?= h(t('admin.common.status', [], $lang)) ?></label>
              <select class="input" id="translation_state_filter" name="state_filter" <?= $langFilter === '' ? 'disabled' : '' ?>>
                <option value="ALL" <?= $stateFilter === 'ALL' ? 'selected' : '' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
                <option value="complete" <?= $stateFilter === 'complete' ? 'selected' : '' ?>><?= h(t('admin.translations.stat_ok', [], $lang)) ?></option>
                <option value="stale" <?= $stateFilter === 'stale' ? 'selected' : '' ?>><?= h(t('admin.translations.stat_stale', [], $lang)) ?></option>
                <option value="partial" <?= $stateFilter === 'partial' ? 'selected' : '' ?>><?= h(t('admin.translations.stat_partial', [], $lang)) ?></option>
                <option value="missing" <?= $stateFilter === 'missing' ? 'selected' : '' ?>><?= h(t('admin.translations.stat_missing', [], $lang)) ?></option>
              </select>
            </div>
            <div>
              <label class="label" for="translation_id_filter"><?= h(t('admin.translations.filter_id', [], $lang)) ?></label>
              <input class="input" type="number" id="translation_id_filter" name="id_filter" value="<?= $idFilter > 0 ? $idFilter : '' ?>" placeholder="ex: 42" min="1">
            </div>
            <div>
              <label class="label" for="translation_search"><?= h(t('admin.translations.filter_search', [], $lang)) ?></label>
              <input class="input" type="text" id="translation_search" name="q" value="<?= h($searchQuery) ?>" placeholder="<?= h(t('admin.translations.filter_search_ph', [], $lang)) ?>">
            </div>
          </div>
          <div class="filters-actions audit-config-actions">
            <button class="btn" type="submit"><?= h(t('admin.translations.apply', [], $lang)) ?></button>
            <a class="btn ghost" href="/admin/question_translations.php<?= $activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId : '' ?>"><?= h(t('admin.common.reset', [], $lang)) ?></a>
          </div>
        </form>
      </section>

      <section class="admin-section-panel">
        <div class="section-head admin-section-head">
          <div>
            <h3 class="h1"><?= h(t('admin.translations.questions_title', [], $lang)) ?></h3>
            <p class="sub sessions-meta"><?= h(t('admin.common.page_of', ['page' => $page, 'total' => $totalPages, 'count' => $totalQuestions], $lang)) ?></p>
          </div>
        </div>

        <div class="table-wrap admin-table-panel">
          <?php if (!$coverageRows): ?>
            <p class="empty-state"><?= h(t('admin.translations.none', [], $lang)) ?></p>
          <?php else: ?>
            <table class="table questions-table translation-coverage-table">
              <thead>
                <tr>
                  <th><?= h(t('admin.questions.col_id', [], $lang)) ?></th>
                  <th><?= h(t('admin.translations.col_question', [], $lang)) ?></th>
                  <th><?= h(t('admin.questions.col_category', [], $lang)) ?></th>
                  <th><?= h(t('admin.translations.filter_packs', [], $lang)) ?></th>
                  <?php foreach ($translationLangs as $langLabel): ?>
                    <th><?= h($langLabel) ?></th>
                  <?php endforeach; ?>
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
                    <?php foreach ($translationLangs as $langCode => $_langLabel): ?>
                      <?php $meta = translation_status_meta((string)($row['statuses'][$langCode] ?? 'missing'), $lang); ?>
                      <td><span class="<?= h($meta['class']) ?>"><?= h($meta['label']) ?></span></td>
                    <?php endforeach; ?>
                    <td class="actions-cell">
                      <a class="btn ghost icon-btn" href="/admin/question_edit.php?id=<?= (int)$row['id'] ?><?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>&return=<?= h(urlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/question_translations.php'))) ?>" aria-label="Modifier la question" title="Modifier la question">
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
