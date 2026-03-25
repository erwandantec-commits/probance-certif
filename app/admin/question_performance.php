<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../services/session_service.php';
$pdo = db();

$knowledgeRequired = trim((string)($_GET['knowledge_required'] ?? ''));
$questionSearch = trim((string)($_GET['q'] ?? ''));
$questionIdRaw = trim((string)($_GET['question_id'] ?? ''));
$sessionType = strtoupper(trim((string)($_GET['session_type'] ?? 'ALL')));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$packageId = (int)($_GET['package_id'] ?? 0);
$sort = trim((string)($_GET['sort'] ?? 'fail_rate'));
$dir = strtoupper(trim((string)($_GET['dir'] ?? 'DESC')));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$okRateOp = trim((string)($_GET['ok_rate_op'] ?? ''));
$okRateValueRaw = trim((string)($_GET['ok_rate_value'] ?? ''));
$okRateValue2Raw = trim((string)($_GET['ok_rate_value2'] ?? ''));
$failRateOp = trim((string)($_GET['fail_rate_op'] ?? ''));
$failRateValueRaw = trim((string)($_GET['fail_rate_value'] ?? ''));
$failRateValue2Raw = trim((string)($_GET['fail_rate_value2'] ?? ''));
$responseOp = trim((string)($_GET['response_op'] ?? ''));
$responseValueRaw = trim((string)($_GET['response_value'] ?? ''));
$responseValue2Raw = trim((string)($_GET['response_value2'] ?? ''));
$chartResponseCountRaw = trim((string)($_GET['chart_response_count'] ?? ''));
$chartFailRateRaw = trim((string)($_GET['chart_fail_rate'] ?? ''));

if (!in_array($sessionType, ['ALL', 'EXAM', 'TRAINING'], true)) {
  $sessionType = 'ALL';
}
if (!in_array($sort, ['question_text', 'knowledge_required', 'response_count', 'ok_rate', 'fail_rate'], true)) {
  $sort = 'fail_rate';
}
if (!in_array($dir, ['ASC', 'DESC'], true)) {
  $dir = 'DESC';
}
if (!in_array($okRateOp, ['', 'eq', 'gte', 'lte', 'between'], true)) {
  $okRateOp = '';
}
if (!in_array($failRateOp, ['', 'eq', 'gte', 'lte', 'between'], true)) {
  $failRateOp = '';
}
if (!in_array($responseOp, ['', 'eq', 'gte', 'lte', 'between'], true)) {
  $responseOp = '';
}
function performance_parse_percent(?string $raw): ?float {
  $raw = trim((string)$raw);
  if ($raw === '' || !is_numeric($raw)) {
    return null;
  }
  $value = (float)$raw;
  if ($value < 0 || $value > 100) {
    return null;
  }
  return round($value, 1);
}

function performance_having_clause(string $field, string $operator, ?float $value1, ?float $value2, array &$params): ?string {
  if ($operator === '' || $value1 === null) {
    return null;
  }
  if ($operator === 'between') {
    if ($value2 === null) {
      return null;
    }
    $params[] = min($value1, $value2);
    $params[] = max($value1, $value2);
    return "$field BETWEEN ? AND ?";
  }
  $params[] = $value1;
  return match ($operator) {
    'eq' => "$field = ?",
    'gte' => "$field >= ?",
    'lte' => "$field <= ?",
    default => null,
  };
}

function performance_parse_int(?string $raw): ?int {
  $raw = trim((string)$raw);
  if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
    return null;
  }
  return (int)$raw;
}

function performance_parse_date(?string $raw): string {
  $raw = trim((string)$raw);
  if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
    return '';
  }
  $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
  return ($dt && $dt->format('Y-m-d') === $raw) ? $raw : '';
}

function performance_having_int_clause(string $field, string $operator, ?int $value1, ?int $value2, array &$params): ?string {
  if ($operator === '' || $value1 === null) {
    return null;
  }
  if ($operator === 'between') {
    if ($value2 === null) {
      return null;
    }
    $params[] = min($value1, $value2);
    $params[] = max($value1, $value2);
    return "$field BETWEEN ? AND ?";
  }
  $params[] = $value1;
  return match ($operator) {
    'eq' => "$field = ?",
    'gte' => "$field >= ?",
    'lte' => "$field <= ?",
    default => null,
  };
}

$okRateValue = performance_parse_percent($okRateValueRaw);
$okRateValue2 = performance_parse_percent($okRateValue2Raw);
$failRateValue = performance_parse_percent($failRateValueRaw);
$failRateValue2 = performance_parse_percent($failRateValue2Raw);
$responseValue = performance_parse_int($responseValueRaw);
$responseValue2 = performance_parse_int($responseValue2Raw);
$questionId = ($questionIdRaw !== '' && preg_match('/^\d+$/', $questionIdRaw)) ? (int)$questionIdRaw : null;
$dateFrom = performance_parse_date($dateFrom);
$dateTo = performance_parse_date($dateTo);
$chartSelectedResponseCount = performance_parse_int($chartResponseCountRaw);
$chartSelectedFailRate = performance_parse_percent($chartFailRateRaw);

$packages = $pdo->query("SELECT id, name, name_color_hex FROM packages ORDER BY name ASC")->fetchAll() ?: [];
$packageIds = array_map(fn($pkg) => (int)$pkg['id'], $packages);
if ($packageId > 0 && !in_array($packageId, $packageIds, true)) {
  $packageId = 0;
}

$knowledgeRequiredRows = $pdo->query("
  SELECT DISTINCT TRIM(knowledge_required_csv) AS knowledge_required_name
  FROM questions
  WHERE knowledge_required_csv IS NOT NULL AND TRIM(knowledge_required_csv) <> ''
  ORDER BY knowledge_required_name ASC
")->fetchAll() ?: [];

$where = ["s.status IN ('TERMINATED', 'EXPIRED')"];
$params = [];
if ($sessionType !== 'ALL') {
  $where[] = "s.session_type = ?";
  $params[] = $sessionType;
}
if ($packageId > 0) {
  $where[] = "s.package_id = ?";
  $params[] = $packageId;
}
if ($dateFrom !== '') {
  $where[] = "s.started_at >= ?";
  $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
  $where[] = "s.started_at < DATE_ADD(?, INTERVAL 1 DAY)";
  $params[] = $dateTo;
}
if ($knowledgeRequired !== '') {
  $where[] = "q0.knowledge_required_csv = ?";
  $params[] = $knowledgeRequired;
}
if ($questionSearch !== '') {
  $where[] = "q0.text LIKE ?";
  $params[] = '%' . $questionSearch . '%';
}
if ($questionId !== null) {
  $where[] = "q0.external_id = ?";
  $params[] = $questionId;
}
$whereSql = implode("\n      AND ", $where);
$hasAnswerStatusSnapshot = table_column_exists($pdo, 'session_questions', 'answer_status_snapshot');
$answerStatusExpr = $hasAnswerStatusSnapshot
  ? "COALESCE(sq.answer_status_snapshot, 'UNANSWERED')"
  : "CASE
        WHEN COALESCE(ans.selected_correct_count, 0) = qstats.correct_count
         AND COALESCE(ans.selected_total_count, 0) = qstats.correct_count
        THEN 'OK'
        WHEN COALESCE(ans.selected_total_count, 0) = 0 THEN 'UNANSWERED'
        ELSE 'KO'
      END";
$havingParams = [];
$havingParts = [];
$okHaving = performance_having_clause('ROUND((100.0 * SUM(CASE WHEN perf.answer_status = \'OK\' THEN 1 ELSE 0 END)) / COUNT(*), 1)', $okRateOp, $okRateValue, $okRateValue2, $havingParams);
if ($okHaving !== null) {
  $havingParts[] = $okHaving;
}
$failHaving = performance_having_clause('ROUND((100.0 * SUM(CASE WHEN perf.answer_status = \'KO\' THEN 1 ELSE 0 END)) / COUNT(*), 1)', $failRateOp, $failRateValue, $failRateValue2, $havingParams);
if ($failHaving !== null) {
  $havingParts[] = $failHaving;
}
$responseHaving = performance_having_int_clause('COUNT(*)', $responseOp, $responseValue, $responseValue2, $havingParams);
if ($responseHaving !== null) {
  $havingParts[] = $responseHaving;
}
$havingSql = $havingParts ? ('HAVING ' . implode(' AND ', $havingParts)) : '';

$perfFromSql = "
  FROM (
    SELECT
      sq.session_id,
      sq.question_id,
      $answerStatusExpr AS answer_status
    FROM session_questions sq
    JOIN sessions s ON s.id = sq.session_id
    JOIN questions q0 ON q0.id = sq.question_id
    " . ($hasAnswerStatusSnapshot ? "" : "
    JOIN (
      SELECT
        qo.question_id,
        COUNT(CASE WHEN qo.is_correct = 1 THEN 1 END) AS correct_count
      FROM question_options qo
      GROUP BY qo.question_id
    ) qstats ON qstats.question_id = sq.question_id
    LEFT JOIN (
      SELECT
        ao.session_id,
        ao.question_id,
        COUNT(*) AS selected_total_count,
        COUNT(CASE WHEN qo.is_correct = 1 THEN 1 END) AS selected_correct_count
      FROM answer_options ao
      JOIN question_options qo ON qo.id = ao.option_id
      GROUP BY ao.session_id, ao.question_id
    ) ans ON ans.session_id = sq.session_id AND ans.question_id = sq.question_id
    ") . "
    WHERE $whereSql
  ) perf
  JOIN questions q ON q.id = perf.question_id
";
$answeredPerfFromSql = $perfFromSql . "
  WHERE perf.answer_status <> 'UNANSWERED'
";

$countStmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM (
    SELECT q.id
    $answeredPerfFromSql
    GROUP BY q.id
    $havingSql
  ) question_perf
");
$countStmt->execute(array_merge($params, $havingParams));
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $limit;

$sql = "
  SELECT
    q.id,
    q.external_id,
    q.text AS question_text,
    COALESCE(NULLIF(TRIM(q.knowledge_required_csv), ''), '-') AS knowledge_required,
    COUNT(*) AS response_count,
    SUM(CASE WHEN perf.answer_status = 'OK' THEN 1 ELSE 0 END) AS ok_count,
    SUM(CASE WHEN perf.answer_status = 'KO' THEN 1 ELSE 0 END) AS fail_count,
    ROUND((100.0 * SUM(CASE WHEN perf.answer_status = 'OK' THEN 1 ELSE 0 END)) / COUNT(*), 1) AS ok_rate,
    ROUND((100.0 * SUM(CASE WHEN perf.answer_status = 'KO' THEN 1 ELSE 0 END)) / COUNT(*), 1) AS fail_rate
  $answeredPerfFromSql
  GROUP BY q.id, q.external_id, q.text, q.knowledge_required_csv
  $havingSql
  ORDER BY $sort $dir, response_count DESC, q.id DESC
  LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);
$bindIndex = 1;
foreach (array_merge($params, $havingParams) as $param) {
  $stmt->bindValue($bindIndex++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue($bindIndex++, $limit, PDO::PARAM_INT);
$stmt->bindValue($bindIndex++, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll() ?: [];

$rankingSql = "
  SELECT
    q.id,
    q.external_id,
    q.text AS question_text,
    COALESCE(NULLIF(TRIM(q.knowledge_required_csv), ''), '-') AS knowledge_required,
    COUNT(*) AS response_count,
    ROUND((100.0 * SUM(CASE WHEN perf.answer_status = 'OK' THEN 1 ELSE 0 END)) / COUNT(*), 1) AS ok_rate,
    ROUND((100.0 * SUM(CASE WHEN perf.answer_status = 'KO' THEN 1 ELSE 0 END)) / COUNT(*), 1) AS fail_rate
  $answeredPerfFromSql
  GROUP BY q.id, q.external_id, q.text, q.knowledge_required_csv
  $havingSql
  ORDER BY ok_rate DESC, response_count DESC, q.id ASC
";
$rankingStmt = $pdo->prepare($rankingSql);
$rankingStmt->execute(array_merge($params, $havingParams));
$rankingRows = $rankingStmt->fetchAll() ?: [];
$rankByQuestionId = [];
foreach ($rankingRows as $index => $rankingRow) {
  $rankByQuestionId[(int)$rankingRow['id']] = $index + 1;
}

$summaryStmt = $pdo->prepare("
  SELECT
    COUNT(*) AS response_count,
    SUM(CASE WHEN perf.answer_status = 'OK' THEN 1 ELSE 0 END) AS ok_count,
    SUM(CASE WHEN perf.answer_status = 'KO' THEN 1 ELSE 0 END) AS fail_count
  $answeredPerfFromSql
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: ['response_count' => 0, 'ok_count' => 0, 'fail_count' => 0];
$totalResponses = (int)($summary['response_count'] ?? 0);
$globalOkRate = $totalResponses > 0 ? round(((int)$summary['ok_count'] * 100) / $totalResponses, 1) : 0.0;
$globalFailRate = $totalResponses > 0 ? round(((int)$summary['fail_count'] * 100) / $totalResponses, 1) : 0.0;

$tableTitle = 'Tableau de performance';
$tableRows = $rows;
$tableCount = $totalRows;
$showPagination = true;

$chartRows = $rankingRows;
usort($chartRows, static function (array $left, array $right): int {
  $leftCount = (int)($left['response_count'] ?? 0);
  $rightCount = (int)($right['response_count'] ?? 0);
  if ($leftCount !== $rightCount) {
    return $rightCount <=> $leftCount;
  }
  return ((float)($right['fail_rate'] ?? 0.0)) <=> ((float)($left['fail_rate'] ?? 0.0));
});
$chartMaxResponses = 1;
foreach ($chartRows as $chartRow) {
  $chartMaxResponses = max($chartMaxResponses, (int)($chartRow['response_count'] ?? 0));
}
$chartWidth = 900;
$chartHeight = 320;
$chartPaddingLeft = 56;
$chartPaddingRight = 18;
$chartPaddingTop = 20;
$chartPaddingBottom = 36;
$chartPlotWidth = $chartWidth - $chartPaddingLeft - $chartPaddingRight;
$chartPlotHeight = $chartHeight - $chartPaddingTop - $chartPaddingBottom;
$chartXTicks = [];
if ($chartMaxResponses <= 10) {
  for ($tickValue = 0; $tickValue <= $chartMaxResponses; $tickValue++) {
    $chartXTicks[] = [
      'value' => $tickValue,
      'x' => $chartPaddingLeft + (($tickValue / max(1, $chartMaxResponses)) * $chartPlotWidth),
    ];
  }
} else {
  foreach ([0, 25, 50, 75, 100] as $tickPercent) {
    $chartXTicks[] = [
      'value' => (int)round(($chartMaxResponses * $tickPercent) / 100),
      'x' => $chartPaddingLeft + (($tickPercent / 100) * $chartPlotWidth),
    ];
  }
}
$chartBubbleGroups = [];
foreach ($chartRows as $chartRow) {
  $responseCount = (int)($chartRow['response_count'] ?? 0);
  $failRate = max(0.0, min(100.0, (float)($chartRow['fail_rate'] ?? 0.0)));
  $groupKey = $responseCount . '|' . number_format($failRate, 1, '.', '');
  if (!isset($chartBubbleGroups[$groupKey])) {
    $chartBubbleGroups[$groupKey] = [
      'response_count' => $responseCount,
      'fail_rate' => $failRate,
      'questions' => [],
    ];
  }
  $chartBubbleGroups[$groupKey]['questions'][] = [
    'id' => (int)($chartRow['id'] ?? 0),
    'external_id' => $chartRow['external_id'],
    'question_text' => (string)($chartRow['question_text'] ?? ''),
    'knowledge_required' => (string)($chartRow['knowledge_required'] ?? '-'),
    'response_count' => $responseCount,
    'ok_rate' => (float)($chartRow['ok_rate'] ?? 0.0),
    'fail_rate' => $failRate,
  ];
}

$chartPoints = [];
$selectedChartBubble = null;
foreach ($chartBubbleGroups as $groupKey => $chartGroup) {
  $responseCount = (int)$chartGroup['response_count'];
  $failRate = (float)$chartGroup['fail_rate'];
  $cx = $chartPaddingLeft + (($responseCount / $chartMaxResponses) * $chartPlotWidth);
  $cy = $chartPaddingTop + ((100 - $failRate) / 100) * $chartPlotHeight;
  $questionCount = count($chartGroup['questions']);
  $radius = 7 + min(14, ($responseCount / $chartMaxResponses) * 8) + min(10, ($questionCount - 1) * 1.6);
  $chartPoints[] = [
    'group_key' => $groupKey,
    'response_count' => $responseCount,
    'fail_rate' => $failRate,
    'question_count' => $questionCount,
    'questions' => $chartGroup['questions'],
    'cx' => round($cx, 1),
    'cy' => round($cy, 1),
    'radius' => round($radius, 1),
  ];
  if ($chartSelectedResponseCount !== null && $chartSelectedFailRate !== null
    && $responseCount === $chartSelectedResponseCount
    && abs($failRate - $chartSelectedFailRate) < 0.05) {
    $selectedChartBubble = $chartGroup;
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Analyse questions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card admin-page-shell">
    <div class="admin-head admin-page-hero">
      <div class="admin-head-copy">
        <p class="admin-page-eyebrow">Administration</p>
        <h2 class="h1">Admin &middot; Analyse questions</h2>
        <p class="sub">Vue agr&eacute;g&eacute;e par question sur les r&eacute;ponses des sessions termin&eacute;es et expir&eacute;es.</p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('performance'); ?>
      </div>
    </div>

    <div class="admin-stats-grid">
      <article class="admin-stat-card">
        <span class="admin-stat-label">Questions</span>
        <strong class="admin-stat-value"><?= (int)$totalRows ?></strong>
      </article>
      <article class="admin-stat-card">
        <span class="admin-stat-label">Reponses</span>
        <strong class="admin-stat-value"><?= (int)$totalResponses ?></strong>
      </article>
      <article class="admin-stat-card">
        <span class="admin-stat-label">Taux reussite global</span>
        <strong class="admin-stat-value"><?= h(number_format($globalOkRate, 1, '.', '')) ?>%</strong>
      </article>
      <article class="admin-stat-card">
        <span class="admin-stat-label">Taux echec global</span>
        <strong class="admin-stat-value"><?= h(number_format($globalFailRate, 1, '.', '')) ?>%</strong>
      </article>
    </div>

    <div class="admin-page-layout">
    <section class="admin-section-panel">
    <div class="section-head admin-section-head">
      <div>
        <h3 class="h1">Filtres d'analyse</h3>
      </div>
    </div>
    <form method="get" class="admin-panel-surface audit-config-panel">
      <input type="hidden" name="sort" value="<?= h($sort) ?>">
      <input type="hidden" name="dir" value="<?= h($dir) ?>">
      <div class="audit-panel-block">
        <div class="audit-panel-head">
          <span class="audit-config-eyebrow">Perimetre d'analyse</span>
        </div>
        <div class="audit-filter-grid audit-filter-grid-main">
          <div>
            <label class="label" for="audit_question_id">Question ID</label>
            <input class="input" id="audit_question_id" name="question_id" type="text" inputmode="numeric" pattern="[0-9]*" value="<?= h($questionIdRaw) ?>" placeholder="ID">
          </div>
          <div>
            <label class="label" for="audit_q">Question</label>
            <input class="input" id="audit_q" name="q" type="text" value="<?= h($questionSearch) ?>" placeholder="Contient...">
          </div>
          <div>
            <label class="label" for="audit_session_type">Type</label>
            <select class="input" id="audit_session_type" name="session_type">
              <option value="ALL" <?= $sessionType === 'ALL' ? 'selected' : '' ?>>Tous</option>
              <option value="EXAM" <?= $sessionType === 'EXAM' ? 'selected' : '' ?>>Certification</option>
              <option value="TRAINING" <?= $sessionType === 'TRAINING' ? 'selected' : '' ?>>Test</option>
            </select>
          </div>
          <div>
            <label class="label" for="audit_package_id">Package</label>
            <select class="input" id="audit_package_id" name="package_id">
              <option value="0" <?= $packageId === 0 ? 'selected' : '' ?>>Tous</option>
              <?php foreach ($packages as $pkg): ?>
                <option value="<?= (int)$pkg['id'] ?>" <?= $packageId === (int)$pkg['id'] ? 'selected' : '' ?>><?= h((string)$pkg['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="audit_knowledge_required">Categorie</label>
            <select class="input" id="audit_knowledge_required" name="knowledge_required">
              <option value="" <?= $knowledgeRequired === '' ? 'selected' : '' ?>>Toutes</option>
              <?php foreach ($knowledgeRequiredRows as $knowledgeRow): ?>
                <?php $knowledgeName = (string)($knowledgeRow['knowledge_required_name'] ?? ''); ?>
                <option value="<?= h($knowledgeName) ?>" <?= $knowledgeRequired === $knowledgeName ? 'selected' : '' ?>><?= h($knowledgeName) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="audit-filter-grid audit-filter-grid-dates">
          <div>
            <label class="label" for="date_from">Date debut analyse</label>
            <input class="input" id="date_from" name="date_from" type="date" value="<?= h($dateFrom) ?>">
          </div>
          <div>
            <label class="label" for="date_to">Date fin analyse</label>
            <input class="input" id="date_to" name="date_to" type="date" value="<?= h($dateTo) ?>">
          </div>
        </div>
      </div>
      <div class="audit-panel-block">
        <div class="audit-panel-head">
          <span class="audit-config-eyebrow">Filtres avances</span>
        </div>
        <div class="audit-advanced-grid">
          <div class="audit-advanced-card">
            <span class="audit-setting-title">Taux reussite</span>
            <div class="audit-filter-grid audit-filter-grid-metrics">
              <div>
                <label class="label" for="audit_ok_rate_op">Comparateur</label>
                <select class="input" id="audit_ok_rate_op" name="ok_rate_op">
                  <option value="" <?= $okRateOp === '' ? 'selected' : '' ?>>Tous</option>
                  <option value="eq" <?= $okRateOp === 'eq' ? 'selected' : '' ?>>Egal a</option>
                  <option value="gte" <?= $okRateOp === 'gte' ? 'selected' : '' ?>>Superieur a (&gt;=)</option>
                  <option value="lte" <?= $okRateOp === 'lte' ? 'selected' : '' ?>>Inferieur a (&lt;=)</option>
                  <option value="between" <?= $okRateOp === 'between' ? 'selected' : '' ?>>Entre (&gt;=, &lt;=)</option>
                </select>
              </div>
              <div>
                <label class="label" for="audit_ok_rate_value">Valeur 1</label>
                <input class="input" id="audit_ok_rate_value" name="ok_rate_value" type="number" min="0" max="100" step="0.1" value="<?= h($okRateValueRaw) ?>" placeholder="%">
              </div>
              <div id="audit-ok-rate-value2-wrap" class="audit-filter-optional<?= $okRateOp === 'between' ? '' : ' is-hidden' ?>">
                <label class="label" for="audit_ok_rate_value2">Valeur 2</label>
                <input class="input" id="audit_ok_rate_value2" name="ok_rate_value2" type="number" min="0" max="100" step="0.1" value="<?= h($okRateValue2Raw) ?>" placeholder="%">
              </div>
            </div>
          </div>
          <div class="audit-advanced-card">
            <span class="audit-setting-title">Taux echec</span>
            <div class="audit-filter-grid audit-filter-grid-metrics">
              <div>
                <label class="label" for="audit_fail_rate_op">Comparateur</label>
                <select class="input" id="audit_fail_rate_op" name="fail_rate_op">
                  <option value="" <?= $failRateOp === '' ? 'selected' : '' ?>>Tous</option>
                  <option value="eq" <?= $failRateOp === 'eq' ? 'selected' : '' ?>>Egal a</option>
                  <option value="gte" <?= $failRateOp === 'gte' ? 'selected' : '' ?>>Superieur a (&gt;=)</option>
                  <option value="lte" <?= $failRateOp === 'lte' ? 'selected' : '' ?>>Inferieur a (&lt;=)</option>
                  <option value="between" <?= $failRateOp === 'between' ? 'selected' : '' ?>>Entre (&gt;=, &lt;=)</option>
                </select>
              </div>
              <div>
                <label class="label" for="audit_fail_rate_value">Valeur 1</label>
                <input class="input" id="audit_fail_rate_value" name="fail_rate_value" type="number" min="0" max="100" step="0.1" value="<?= h($failRateValueRaw) ?>" placeholder="%">
              </div>
              <div id="audit-fail-rate-value2-wrap" class="audit-filter-optional<?= $failRateOp === 'between' ? '' : ' is-hidden' ?>">
                <label class="label" for="audit_fail_rate_value2">Valeur 2</label>
                <input class="input" id="audit_fail_rate_value2" name="fail_rate_value2" type="number" min="0" max="100" step="0.1" value="<?= h($failRateValue2Raw) ?>" placeholder="%">
              </div>
            </div>
          </div>
          <div class="audit-advanced-card">
            <span class="audit-setting-title">Nb reponses</span>
            <div class="audit-filter-grid audit-filter-grid-metrics">
              <div>
                <label class="label" for="audit_response_op">Comparateur</label>
                <select class="input" id="audit_response_op" name="response_op">
                  <option value="" <?= $responseOp === '' ? 'selected' : '' ?>>Tous</option>
                  <option value="eq" <?= $responseOp === 'eq' ? 'selected' : '' ?>>Egal a</option>
                  <option value="gte" <?= $responseOp === 'gte' ? 'selected' : '' ?>>Superieur a (&gt;=)</option>
                  <option value="lte" <?= $responseOp === 'lte' ? 'selected' : '' ?>>Inferieur a (&lt;=)</option>
                  <option value="between" <?= $responseOp === 'between' ? 'selected' : '' ?>>Entre (&gt;=, &lt;=)</option>
                </select>
              </div>
              <div>
                <label class="label" for="audit_response_value">Valeur 1</label>
                <input class="input" id="audit_response_value" name="response_value" type="number" min="0" step="1" value="<?= h($responseValueRaw) ?>" placeholder="nb">
              </div>
              <div id="audit-response-value2-wrap" class="audit-filter-optional<?= $responseOp === 'between' ? '' : ' is-hidden' ?>">
                <label class="label" for="audit_response_value2">Valeur 2</label>
                <input class="input" id="audit_response_value2" name="response_value2" type="number" min="0" step="1" value="<?= h($responseValue2Raw) ?>" placeholder="nb">
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="filters-actions audit-config-actions">
        <button class="btn" type="submit">Appliquer</button>
        <a class="btn ghost" href="/admin/question_performance.php">Reset</a>
      </div>
    </form>

    </section>

    <section id="performance-results" class="admin-section-panel">
    <div class="section-head admin-section-head">
      <div>
        <h3 class="h1"><?= h($tableTitle) ?></h3>
        <p class="sub sessions-meta">Page <?= (int)$page ?> / <?= (int)$totalPages ?> (<?= (int)$tableCount ?> question(s))</p>
      </div>
    </div>

    <div class="table-wrap admin-table-panel">
      <?php if (!$tableRows): ?>
        <p class="empty-state">Aucune donn&eacute;e pour ces filtres.</p>
      <?php else: ?>
        <table class="table questions-table performance-table">
          <thead>
            <tr>
              <?php
                $qs = $_GET;
                unset($qs['page']);
                $base = '/admin/question_performance.php?';
              ?>
              <th>Rang</th>
              <th>ID</th>
              <th>
                <?php $urlQs = $qs; $urlQs['sort'] = 'question_text'; $urlQs['dir'] = ($sort === 'question_text' && $dir === 'DESC') ? 'ASC' : 'DESC'; ?>
                <a class="sort-link" href="<?= h($base . http_build_query($urlQs)) ?>">Question</a>
              </th>
              <th>
                <?php $urlQs = $qs; $urlQs['sort'] = 'knowledge_required'; $urlQs['dir'] = ($sort === 'knowledge_required' && $dir === 'DESC') ? 'ASC' : 'DESC'; ?>
                <a class="sort-link" href="<?= h($base . http_build_query($urlQs)) ?>">Categorie</a>
              </th>
              <th>
                <?php $urlQs = $qs; $urlQs['sort'] = 'response_count'; $urlQs['dir'] = ($sort === 'response_count' && $dir === 'DESC') ? 'ASC' : 'DESC'; ?>
                <a class="sort-link" href="<?= h($base . http_build_query($urlQs)) ?>">Nb reponses</a>
              </th>
              <th>
                <?php $urlQs = $qs; $urlQs['sort'] = 'ok_rate'; $urlQs['dir'] = ($sort === 'ok_rate' && $dir === 'DESC') ? 'ASC' : 'DESC'; ?>
                <a class="sort-link" href="<?= h($base . http_build_query($urlQs)) ?>">Taux reussite</a>
              </th>
              <th>
                <?php $urlQs = $qs; $urlQs['sort'] = 'fail_rate'; $urlQs['dir'] = ($sort === 'fail_rate' && $dir === 'DESC') ? 'ASC' : 'DESC'; ?>
                <a class="sort-link" href="<?= h($base . http_build_query($urlQs)) ?>">Taux echec</a>
              </th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
              <?php foreach ($tableRows as $row): ?>
              <?php $returnTo = (string)($_SERVER['REQUEST_URI'] ?? '/admin/question_performance.php'); ?>
              <tr>
                <td><?= (int)($rankByQuestionId[(int)$row['id']] ?? 0) ?></td>
                <td><?= ($row['external_id'] === null || $row['external_id'] === '') ? '-' : (int)$row['external_id'] ?></td>
                <td><?= h(mb_strimwidth((string)$row['question_text'], 0, 110, '...', 'UTF-8')) ?></td>
                <td><?= h((string)$row['knowledge_required']) ?></td>
                <td><?= (int)$row['response_count'] ?></td>
                <td><span class="badge ok"><?= h(number_format((float)$row['ok_rate'], 1, '.', '')) ?>%</span></td>
                <td><span class="badge bad"><?= h(number_format((float)$row['fail_rate'], 1, '.', '')) ?>%</span></td>
                <td class="actions-cell">
                  <a class="btn ghost icon-btn" href="/admin/question_edit.php?id=<?= (int)$row['id'] ?>&return=<?= h(urlencode($returnTo)) ?>" aria-label="Modifier la question" title="Modifier la question">
                    <svg class="icon-edit" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                      <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm2.92 2.33H5v-.92l8.06-8.06.92.92L5.92 19.58zM20.71 7.04a1.003 1.003 0 0 0 0-1.42l-2.34-2.34a1.003 1.003 0 0 0-1.42 0l-1.13 1.13 3.75 3.75 1.14-1.12z"/>
                    </svg>
                  </a>
                  <a class="btn ghost icon-btn" href="/admin/question_performance_failures.php?qid=<?= (int)$row['id'] ?>&session_type=<?= h(urlencode($sessionType)) ?>&date_from=<?= h(urlencode($dateFrom)) ?>&date_to=<?= h(urlencode($dateTo)) ?>&return=<?= h(urlencode($returnTo)) ?>" aria-label="Zoom performance" title="Zoom performance">
                    <svg class="icon-performance" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                      <path d="M5 19h14v2H5zM6 10h3v7H6zM11 6h3v11h-3zM16 12h3v5h-3z"/>
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
      $common = $qs ? ('?' . http_build_query($qs)) : '';
      $sep = $common ? '&' : '?';
    ?>
    <?php if ($showPagination): ?>
      <div class="sessions-pagination">
        <?php if ($page > 1): ?>
          <a class="btn ghost" href="<?= h('/admin/question_performance.php' . $common . $sep . 'page=' . ($page - 1)) ?>">&larr;</a>
        <?php else: ?>
          <button class="btn ghost" disabled>&larr;</button>
        <?php endif; ?>
        <?php if ($totalPages <= 7): ?>
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance.php' . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
        <?php else: ?>
          <a class="btn <?= $page === 1 ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance.php' . $common . $sep . 'page=1') ?>">1</a>
          <?php if ($page <= 4): ?>
            <?php for ($p = 2; $p <= 5; $p++): ?>
              <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance.php' . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php elseif ($page >= ($totalPages - 3)): ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php for ($p = $totalPages - 4; $p <= $totalPages - 1; $p++): ?>
              <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance.php' . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
          <?php else: ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
            <?php for ($p = $page - 1; $p <= $page + 1; $p++): ?>
              <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance.php' . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
            <?php endfor; ?>
            <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php endif; ?>
          <a class="btn <?= $totalPages === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance.php' . $common . $sep . 'page=' . $totalPages) ?>"><?= (int)$totalPages ?></a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="btn ghost" href="<?= h('/admin/question_performance.php' . $common . $sep . 'page=' . ($page + 1)) ?>">&rarr;</a>
        <?php else: ?>
          <button class="btn ghost" disabled>&rarr;</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    </section>
    </div>
  </div>
</div>
<script>
  (function () {
    function bindConditionalSecondValue(selectId, wrapId) {
      var operatorSelect = document.getElementById(selectId);
      var secondValueWrap = document.getElementById(wrapId);
      if (!operatorSelect || !secondValueWrap) return;

      function syncVisibility() {
        secondValueWrap.classList.toggle('is-hidden', operatorSelect.value !== 'between');
      }

      operatorSelect.addEventListener('change', syncVisibility);
      syncVisibility();
    }

    bindConditionalSecondValue('audit_ok_rate_op', 'audit-ok-rate-value2-wrap');
    bindConditionalSecondValue('audit_fail_rate_op', 'audit-fail-rate-value2-wrap');
    bindConditionalSecondValue('audit_response_op', 'audit-response-value2-wrap');
  })();
</script>
</body>
</html>
