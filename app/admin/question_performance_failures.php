<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

function admin_perf_safe_return(?string $candidate): string {
  $fallback = '/admin/question_performance.php';
  $candidate = trim((string)$candidate);
  if ($candidate === '' || preg_match('/[\r\n]/', $candidate) || strpos($candidate, '/admin/') !== 0) {
    return $fallback;
  }
  return $candidate;
}

$qid = (int)($_GET['qid'] ?? 0);
$returnTo = admin_perf_safe_return((string)($_GET['return'] ?? ''));
$sessionType = strtoupper(trim((string)($_GET['session_type'] ?? 'ALL')));
$answerStatus = strtoupper(trim((string)($_GET['answer_status'] ?? 'ALL')));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;

if (!in_array($sessionType, ['ALL', 'EXAM', 'TRAINING'], true)) {
  $sessionType = 'ALL';
}
if (!in_array($answerStatus, ['ALL', 'OK', 'KO'], true)) {
  $answerStatus = 'ALL';
}

if ($qid <= 0) {
  http_response_code(400);
  echo 'Question manquante.';
  exit;
}

$questionStmt = $pdo->prepare("
  SELECT id, external_id, text
  FROM questions
  WHERE id = ?
  LIMIT 1
");
$questionStmt->execute([$qid]);
$question = $questionStmt->fetch();
if (!$question) {
  http_response_code(404);
  echo 'Question introuvable.';
  exit;
}

$whereParts = [
  "sq.question_id = ?",
  "s0.status IN ('TERMINATED', 'EXPIRED')",
];
$params = [$qid];
if ($sessionType !== 'ALL') {
  $whereParts[] = "s0.session_type = ?";
  $params[] = $sessionType;
}
$whereSql = implode("\n      AND ", $whereParts);
$resultFilterSql = $answerStatus === 'ALL' ? "perf.answer_status IN ('OK', 'KO')" : "perf.answer_status = ?";
$resultLabel = match ($answerStatus) {
  'OK' => 'reussites',
  'KO' => 'echecs',
  default => 'resultats',
};

$perfSql = "
  FROM (
    SELECT
      sq.session_id,
      sq.question_id,
      CASE
        WHEN COALESCE(ans.selected_correct_count, 0) = qstats.correct_count
         AND COALESCE(ans.selected_total_count, 0) = qstats.correct_count
        THEN 'OK'
        WHEN COALESCE(ans.selected_total_count, 0) = 0 THEN 'UNANSWERED'
        ELSE 'KO'
      END AS answer_status
    FROM session_questions sq
    JOIN sessions s0 ON s0.id = sq.session_id
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
    WHERE $whereSql
  ) perf
  JOIN sessions s ON s.id = perf.session_id
  JOIN contacts c ON c.id = s.contact_id
  JOIN packages pk ON pk.id = s.package_id
  JOIN questions q ON q.id = perf.question_id
  WHERE $resultFilterSql
";

$resultParams = $params;
if ($answerStatus !== 'ALL') {
  $resultParams[] = $answerStatus;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) $perfSql");
$countStmt->execute($resultParams);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $limit;

$uniqueUserStmt = $pdo->prepare("SELECT COUNT(DISTINCT c.email) $perfSql");
$uniqueUserStmt->execute($resultParams);
$distinctUsers = (int)$uniqueUserStmt->fetchColumn();

$summarySql = "
  SELECT
    SUM(CASE WHEN perf.answer_status = 'OK' THEN 1 ELSE 0 END) AS ok_count,
    SUM(CASE WHEN perf.answer_status = 'KO' THEN 1 ELSE 0 END) AS ko_count,
    COUNT(DISTINCT c.email) AS user_count
  FROM (
    SELECT
      sq.session_id,
      sq.question_id,
      CASE
        WHEN COALESCE(ans.selected_correct_count, 0) = qstats.correct_count
         AND COALESCE(ans.selected_total_count, 0) = qstats.correct_count
        THEN 'OK'
        WHEN COALESCE(ans.selected_total_count, 0) = 0 THEN 'UNANSWERED'
        ELSE 'KO'
      END AS answer_status
    FROM session_questions sq
    JOIN sessions s0 ON s0.id = sq.session_id
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
    WHERE $whereSql
  ) perf
  JOIN sessions s ON s.id = perf.session_id
  JOIN contacts c ON c.id = s.contact_id
  WHERE perf.answer_status IN ('OK', 'KO')
";
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: ['ok_count' => 0, 'ko_count' => 0, 'user_count' => 0];
$okCount = (int)($summary['ok_count'] ?? 0);
$koCount = (int)($summary['ko_count'] ?? 0);
$answeredCount = $okCount + $koCount;
$okRate = $answeredCount > 0 ? round(($okCount * 100) / $answeredCount, 1) : 0.0;
$koRate = $answeredCount > 0 ? round(($koCount * 100) / $answeredCount, 1) : 0.0;

$stmt = $pdo->prepare("
  SELECT
    s.id AS session_id,
    s.started_at,
    s.session_type,
    s.score_percent,
    perf.answer_status,
    c.email,
    pk.name AS package_name,
    pk.name_color_hex AS package_color_hex,
    (
      SELECT GROUP_CONCAT(qo2.label ORDER BY qo2.label SEPARATOR ',')
      FROM answer_options ao
      JOIN question_options qo2 ON qo2.id = ao.option_id
      WHERE ao.session_id = s.id AND ao.question_id = q.id
    ) AS picked_labels,
    (
      SELECT GROUP_CONCAT(qo3.label ORDER BY qo3.label SEPARATOR ',')
      FROM question_options qo3
      WHERE qo3.question_id = q.id AND qo3.is_correct = 1
    ) AS correct_labels
  $perfSql
  ORDER BY s.started_at DESC, s.id DESC
  LIMIT ? OFFSET ?
");
$bindIndex = 1;
foreach ($resultParams as $param) {
  $stmt->bindValue($bindIndex++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue($bindIndex++, $limit, PDO::PARAM_INT);
$stmt->bindValue($bindIndex++, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll() ?: [];
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Zoom performance question</title>
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
        <h2 class="h1">Admin &middot; Zoom performance question</h2>
        <p class="sub"><?= h(mb_strimwidth((string)$question['text'], 0, 140, '...', 'UTF-8')) ?></p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('performance'); ?>
        <a class="btn ghost back-nav-btn icon-btn zoom-edit-btn" href="/admin/question_edit.php?id=<?= (int)$qid ?>&return=<?= h(urlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/question_performance_failures.php?qid=' . $qid))) ?>" aria-label="Modifier la question" title="Modifier la question">
          <svg class="icon-edit" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm2.92 2.33H5v-.92l8.06-8.06.92.92L5.92 19.58zM20.71 7.04a1.003 1.003 0 0 0 0-1.42l-2.34-2.34a1.003 1.003 0 0 0-1.42 0l-1.13 1.13 3.75 3.75 1.14-1.12z"/>
          </svg>
        </a>
        <a class="btn ghost back-nav-btn" href="<?= h($returnTo) ?>">Retour</a>
      </div>
    </div>

    <div class="admin-stats-grid">
      <article class="admin-stat-card">
        <span class="admin-stat-label">ID question</span>
        <strong class="admin-stat-value"><?= ($question['external_id'] === null || $question['external_id'] === '') ? '-' : (int)$question['external_id'] ?></strong>
      </article>
      <article class="admin-stat-card">
        <span class="admin-stat-label">Taux reussite</span>
        <strong class="admin-stat-value"><?= h(number_format($okRate, 1, '.', '')) ?>%</strong>
      </article>
      <article class="admin-stat-card">
        <span class="admin-stat-label">Taux echec</span>
        <strong class="admin-stat-value"><?= h(number_format($koRate, 1, '.', '')) ?>%</strong>
      </article>
      <article class="admin-stat-card">
        <span class="admin-stat-label">Users distincts</span>
        <strong class="admin-stat-value"><?= $distinctUsers ?></strong>
      </article>
    </div>

    <div class="admin-page-layout">
    <section class="admin-section-panel">
    <div class="section-head admin-section-head">
      <div>
        <h3 class="h1">Historique des <?= h($resultLabel) ?></h3>
        <p class="sub sessions-meta">Page <?= (int)$page ?> / <?= (int)$totalPages ?> (<?= (int)$totalRows ?> resultat(s))</p>
      </div>
    </div>

    <form method="get" class="admin-panel-surface" style="margin-bottom:12px;">
      <input type="hidden" name="qid" value="<?= (int)$qid ?>">
      <input type="hidden" name="return" value="<?= h($returnTo) ?>">
      <div class="filters-grid" style="grid-template-columns: repeat(2, minmax(0, 220px)); align-items:end;">
        <div>
          <label class="label" for="session_type">Type</label>
          <select class="input" id="session_type" name="session_type">
            <option value="ALL" <?= $sessionType === 'ALL' ? 'selected' : '' ?>>Tous</option>
            <option value="EXAM" <?= $sessionType === 'EXAM' ? 'selected' : '' ?>>Certification</option>
            <option value="TRAINING" <?= $sessionType === 'TRAINING' ? 'selected' : '' ?>>Test</option>
          </select>
        </div>
        <div>
          <label class="label" for="answer_status">Resultat</label>
          <select class="input" id="answer_status" name="answer_status">
            <option value="ALL" <?= $answerStatus === 'ALL' ? 'selected' : '' ?>>Tous</option>
            <option value="OK" <?= $answerStatus === 'OK' ? 'selected' : '' ?>>Reussites</option>
            <option value="KO" <?= $answerStatus === 'KO' ? 'selected' : '' ?>>Echecs</option>
          </select>
        </div>
      </div>
      <div class="filters-actions" style="margin-top:12px;">
        <button class="btn" type="submit">Filtrer</button>
        <a class="btn ghost" href="/admin/question_performance_failures.php?qid=<?= (int)$qid ?>&return=<?= h(urlencode($returnTo)) ?>">Reset</a>
      </div>
    </form>

    <div class="table-wrap admin-table-panel">
      <?php if (!$rows): ?>
        <p class="empty-state">Aucun resultat enregistre sur cette question pour ces filtres.</p>
      <?php else: ?>
        <table class="table questions-table performance-history-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>User</th>
              <th>Type</th>
              <th>Resultat</th>
              <th>Pack</th>
              <th>Reponse candidat</th>
              <th>Reponse correcte</th>
              <th>Score session</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= h((string)$row['started_at']) ?></td>
                <td><?= h((string)$row['email']) ?></td>
                <td><?= h((string)$row['session_type'] === 'EXAM' ? 'Certification' : 'Test') ?></td>
                <td>
                  <span class="<?= h((string)$row['answer_status'] === 'OK' ? 'badge ok' : 'badge bad') ?>">
                    <?= h((string)$row['answer_status'] === 'OK' ? 'Reussite' : 'Echec') ?>
                  </span>
                </td>
                <td><span style="<?= h(package_label_style((string)$row['package_name'], (string)($row['package_color_hex'] ?? ''))) ?>"><?= h((string)$row['package_name']) ?></span></td>
                <td><?= h((string)($row['picked_labels'] ?: '-')) ?></td>
                <td><?= h((string)($row['correct_labels'] ?: '-')) ?></td>
                <td><?= $row['score_percent'] !== null ? h((string)$row['score_percent']) . '%' : '-' ?></td>
                <td class="actions-cell">
                  <a class="btn ghost icon-btn" href="/admin/session.php?sid=<?= h((string)$row['session_id']) ?>&return=<?= h(urlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/question_performance_failures.php?qid=' . $qid))) ?>" aria-label="Voir la session" title="Voir la session">
                    <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                      <path d="M12 5c5.5 0 9.5 4.6 10.8 6.3a1.2 1.2 0 0 1 0 1.4C21.5 14.4 17.5 19 12 19S2.5 14.4 1.2 12.7a1.2 1.2 0 0 1 0-1.4C2.5 9.6 6.5 5 12 5zm0 2C8 7 4.9 10.3 3.3 12 4.9 13.7 8 17 12 17s7.1-3.3 8.7-5C19.1 10.3 16 7 12 7zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5z"/>
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
    <div class="sessions-pagination">
      <?php if ($page > 1): ?>
        <a class="btn ghost" href="<?= h('/admin/question_performance_failures.php' . $common . $sep . 'page=' . ($page - 1)) ?>">&larr;</a>
      <?php else: ?>
        <button class="btn ghost" disabled>&larr;</button>
      <?php endif; ?>
      <?php if ($totalPages <= 7): ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance_failures.php' . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
        <?php endfor; ?>
      <?php else: ?>
        <a class="btn <?= $page === 1 ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance_failures.php' . $common . $sep . 'page=1') ?>">1</a>
        <?php if ($page <= 4): ?>
          <?php for ($p = 2; $p <= 5; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance_failures.php' . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
          <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
        <?php elseif ($page >= ($totalPages - 3)): ?>
          <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php for ($p = $totalPages - 4; $p <= $totalPages - 1; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance_failures.php' . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
        <?php else: ?>
          <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
          <?php for ($p = $page - 1; $p <= $page + 1; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance_failures.php' . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
          <span class="pagination-ellipsis" aria-hidden="true" style="position:relative; top:10px;">...</span>
        <?php endif; ?>
        <a class="btn <?= $totalPages === $page ? '' : 'ghost' ?>" href="<?= h('/admin/question_performance_failures.php' . $common . $sep . 'page=' . $totalPages) ?>"><?= (int)$totalPages ?></a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
        <a class="btn ghost" href="<?= h('/admin/question_performance_failures.php' . $common . $sep . 'page=' . ($page + 1)) ?>">&rarr;</a>
      <?php else: ?>
        <button class="btn ghost" disabled>&rarr;</button>
      <?php endif; ?>
    </div>
    </section>
    </div>
  </div>
</div>
</body>
</html>
