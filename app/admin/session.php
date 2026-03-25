<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../services/session_service.php';
$pdo = db();

function admin_session_safe_return(?string $candidate): string {
  $fallback = '/admin/index.php';
  $candidate = trim((string)$candidate);
  if ($candidate === '') {
    return $fallback;
  }
  if (preg_match('/[\r\n]/', $candidate)) {
    return $fallback;
  }
  if (strpos($candidate, '/admin/') !== 0) {
    return $fallback;
  }
  return $candidate;
}

$sid = $_GET['sid'] ?? '';
if (!$sid) { http_response_code(400); echo "Missing sid"; exit; }
$returnTo = admin_session_safe_return((string)($_GET['return'] ?? ''));
$sessionSelfUrl = '/admin/session.php?sid=' . urlencode((string)$sid);
if ($returnTo !== '/admin/index.php') {
  $sessionSelfUrl .= '&return=' . urlencode($returnTo);
}

$stmt = $pdo->prepare("
  SELECT s.*, c.email, pk.name AS package_name, pk.name_color_hex AS package_color_hex, pk.pass_threshold_percent
  FROM sessions s
  JOIN contacts c ON c.id = s.contact_id
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.id=?
");
$stmt->execute([$sid]);
$s = $stmt->fetch();
if (!$s) { http_response_code(404); echo "Not found"; exit; }

$hasQuestionExternalSnapshot = table_column_exists($pdo, 'session_questions', 'question_external_id_snapshot');
$hasQuestionTextSnapshot = table_column_exists($pdo, 'session_questions', 'question_text_snapshot');
$hasCorrectLabelsSnapshot = table_column_exists($pdo, 'session_questions', 'correct_option_labels_snapshot');
$hasAnswerStatusSnapshot = table_column_exists($pdo, 'session_questions', 'answer_status_snapshot');
$hasQuestionUpdatedSnapshot = table_column_exists($pdo, 'session_questions', 'question_updated_at_snapshot');
$questionExternalExpr = $hasQuestionExternalSnapshot
  ? "COALESCE(sq.question_external_id_snapshot, q.external_id) AS question_external_id"
  : "q.external_id AS question_external_id";
$questionTextExpr = $hasQuestionTextSnapshot
  ? "COALESCE(sq.question_text_snapshot, q.text) AS text"
  : "q.text AS text";
$correctLabelsExpr = $hasCorrectLabelsSnapshot
  ? "COALESCE(sq.correct_option_labels_snapshot, (
      SELECT GROUP_CONCAT(qo.label ORDER BY qo.label SEPARATOR ',')
      FROM question_options qo
      WHERE qo.question_id = q.id AND qo.is_correct = 1
    )) AS correct_labels"
  : "(
      SELECT GROUP_CONCAT(qo.label ORDER BY qo.label SEPARATOR ',')
      FROM question_options qo
      WHERE qo.question_id = q.id AND qo.is_correct = 1
    ) AS correct_labels";
$answerStatusExpr = $hasAnswerStatusSnapshot
  ? "sq.answer_status_snapshot"
  : "NULL AS answer_status_snapshot";
$questionUpdatedSnapshotExpr = $hasQuestionUpdatedSnapshot
  ? "sq.question_updated_at_snapshot"
  : "NULL AS question_updated_at_snapshot";

$rows = $pdo->prepare("
  SELECT
    sq.id AS session_question_id,
    sq.position,
    q.id AS question_id,
    $questionExternalExpr,
    $questionTextExpr,
    $correctLabelsExpr,
    " . session_question_picked_labels_expr($pdo, 'sq') . " AS picked_labels,
    $answerStatusExpr,
    $questionUpdatedSnapshotExpr,
    q.updated_at AS current_question_updated_at,
    CASE WHEN q.id IS NULL THEN 1 ELSE 0 END AS is_question_deleted
  FROM session_questions sq
  LEFT JOIN questions q ON q.id = sq.question_id
  WHERE sq.session_id=?
  ORDER BY sq.position ASC
");
$rows->execute([$sid]);
$items = $rows->fetchAll();

$goodCount = 0;
$badCount = 0;
$unansweredCount = 0;
foreach ($items as &$it) {
  $picked = trim((string)($it['picked_labels'] ?? ''));
  $correct = trim((string)($it['correct_labels'] ?? ''));
  $status = strtoupper(trim((string)($it['answer_status_snapshot'] ?? '')));
  if (!in_array($status, ['OK', 'KO', 'UNANSWERED'], true)) {
    $status = build_session_question_answer_status($picked, $correct);
  }
  $snapshotUpdatedAt = trim((string)($it['question_updated_at_snapshot'] ?? ''));
  $currentUpdatedAt = trim((string)($it['current_question_updated_at'] ?? ''));
  $it['is_question_modified'] = (
    (int)($it['is_question_deleted'] ?? 0) !== 1
    && $snapshotUpdatedAt !== ''
    && $currentUpdatedAt !== ''
    && strtotime($currentUpdatedAt) > strtotime($snapshotUpdatedAt)
  );
  if ($status === 'UNANSWERED') {
    $it['answer_status'] = '—';
    $it['answer_status_label'] = 'Non repondue';
    $it['answer_status_class'] = 'badge';
    $unansweredCount++;
  } elseif ($status === 'OK') {
    $it['answer_status'] = '✓';
    $it['answer_status_label'] = 'Bonne reponse';
    $it['answer_status_class'] = 'badge ok';
    $goodCount++;
  } else {
    $it['answer_status'] = '✕';
    $it['answer_status_label'] = 'Mauvaise reponse';
    $it['answer_status_class'] = 'badge bad';
    $badCount++;
  }
}
unset($it);

$terminationType = strtoupper(trim((string)($s['termination_type'] ?? '')));
$statusLabel = match ((string)$s['status']) {
  'TERMINATED' => ($terminationType === 'TIMEOUT' ? 'Temps écoulé' : 'Terminé'),
  'ACTIVE' => 'En cours',
  'EXPIRED' => 'Temps écoulé',
  default => (string)$s['status'],
};

$statusClass = match ((string)$s['status']) {
  'TERMINATED' => 'badge ok',
  'EXPIRED' => 'badge bad',
  default => 'badge',
};

?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Detail session</title>
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
          <h2 class="h1">Admin &middot; Detail session</h2>
          <p class="sub">Analyse complete de la session</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('sessions'); ?>
          <a class="btn ghost back-nav-btn" href="<?= h($returnTo) ?>">Retour</a>
        </div>
      </div>

      <div class="admin-stats-grid">
        <article class="admin-stat-card">
          <span class="admin-stat-label">Email</span>
          <strong class="admin-stat-value admin-stat-value-sm"><?= h($s['email']) ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label">Score</span>
          <strong class="admin-stat-value"><?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label">Bonnes r&eacute;ponses</span>
          <strong class="admin-stat-value"><?= (int)$goodCount ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label">Mauvaises r&eacute;ponses</span>
          <strong class="admin-stat-value"><?= (int)$badCount ?></strong>
        </article>
      </div>

      <div class="admin-page-layout">
      <section class="admin-section-panel admin-section-panel-accent">
        <div class="section-head admin-section-head">
          <div>
            <h3 class="h1">Contexte de session</h3>
            <p class="sub">Pack : <span style="<?= h(package_label_style((string)$s['package_name'], (string)($s['package_color_hex'] ?? ''))) ?>"><?= h($s['package_name']) ?></span> · Statut : <span class="<?= h($statusClass) ?>"><?= h($statusLabel) ?></span> · Non repondues : <?= (int)$unansweredCount ?></p>
          </div>
        </div>
      </section>

      <section class="admin-section-panel">
      <div class="section-head admin-section-head">
        <div>
          <h3 class="h1">Questions de la session</h3>
        </div>
      </div>
      <div class="table-wrap admin-table-panel">
        <?php if (!$items): ?>
          <p class="empty-state">
            Aucune question detaillee disponible pour cette session.
            Les questions liees ont probablement ete supprimees apres import/reset.
          </p>
        <?php else: ?>
          <table class="table questions-table">
            <thead>
              <tr>
                <th>#</th>
                <th>ID question</th>
                <th>Question</th>
                <th>Reponse candidat</th>
                <th>Reponse correcte</th>
                <th>Statut</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><?= (int)$it['position'] ?></td>
                  <td><?= ($it['question_external_id'] === null || $it['question_external_id'] === '') ? '-' : (int)$it['question_external_id'] ?></td>
                  <td>
                    <?= h((string)$it['text']) ?>
                    <?php if ((int)($it['is_question_deleted'] ?? 0) === 1): ?>
                      <span class="badge" title="Question supprimée depuis la session" aria-label="Question supprimée depuis la session">!</span>
                    <?php elseif (!empty($it['is_question_modified'])): ?>
                      <span class="badge" title="Question modifiée depuis la session" aria-label="Question modifiée depuis la session">!</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h($it['picked_labels'] ?: '-') ?></td>
                  <td><?= h($it['correct_labels'] ?: '-') ?></td>
                  <td>
                    <span class="<?= h($it['answer_status_class']) ?>" title="<?= h((string)($it['answer_status_label'] ?? '')) ?>" aria-label="<?= h((string)($it['answer_status_label'] ?? '')) ?>"><?= h($it['answer_status']) ?></span>
                    <?php if ((int)($it['is_question_deleted'] ?? 0) === 1): ?>
                      <span class="badge" title="Question supprimée depuis la session" aria-label="Question supprimée depuis la session">!</span>
                    <?php elseif (!empty($it['is_question_modified'])): ?>
                      <span class="badge" title="Question modifiée depuis la session" aria-label="Question modifiée depuis la session">!</span>
                    <?php endif; ?>
                  </td>
                  <td class="actions-cell">
                    <?php if (!empty($it['question_id'])): ?>
                      <a class="btn ghost icon-btn" href="/admin/question_edit.php?id=<?= (int)$it['question_id'] ?>&return=<?= h(urlencode($sessionSelfUrl)) ?>" aria-label="Modifier la question" title="Modifier la question">
                        <svg class="icon-edit" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                          <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm2.92 2.33H5v-.92l8.06-8.06.92.92L5.92 19.58zM20.71 7.04a1.003 1.003 0 0 0 0-1.42l-2.34-2.34a1.003 1.003 0 0 0-1.42 0l-1.13 1.13 3.75 3.75 1.14-1.12z"/>
                        </svg>
                      </a>
                      <a class="btn ghost icon-btn" href="/admin/question_performance_failures.php?qid=<?= (int)$it['question_id'] ?>&return=<?= h(urlencode($sessionSelfUrl)) ?>" aria-label="Voir la performance de la question" title="Voir la performance de la question">
                        <svg class="icon-performance" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                          <path d="M5 19h14v2H5zM6 10h3v7H6zM11 6h3v11h-3zM16 12h3v5h-3z"/>
                        </svg>
                      </a>
                    <?php else: ?>
                      <span class="small">Historique conserve</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      </section>
      </div>
    </div>
  </div>
</body>
</html>
