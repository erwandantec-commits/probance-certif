<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
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

$rows = $pdo->prepare("
  SELECT
    sq.position,
    q.id AS question_id,
    q.external_id AS question_external_id,
    q.text,
    (
      SELECT GROUP_CONCAT(qo.label ORDER BY qo.label SEPARATOR ',')
      FROM question_options qo
      WHERE qo.question_id = q.id AND qo.is_correct = 1
    ) AS correct_labels,
    (
      SELECT GROUP_CONCAT(qo2.label ORDER BY qo2.label SEPARATOR ',')
      FROM answer_options ao
      JOIN question_options qo2 ON qo2.id = ao.option_id
      WHERE ao.session_id = sq.session_id AND ao.question_id = q.id
    ) AS picked_labels
  FROM session_questions sq
  JOIN questions q ON q.id = sq.question_id
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
  if ($picked === '') {
    $it['answer_status'] = '—';
    $it['answer_status_label'] = 'Non repondue';
    $it['answer_status_class'] = 'badge';
    $unansweredCount++;
  } elseif ($picked === $correct) {
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
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Detail session</h2>
          <p class="sub">Analyse complete de la session</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('sessions'); ?>
          <a class="btn ghost" href="<?= h($returnTo) ?>">Retour</a>
        </div>
      </div>

      <hr class="separator">

      <div class="row sessions-stats">
        <span class="badge">Email: <?= h($s['email']) ?></span>
        <span class="badge">Pack: <span style="<?= h(package_label_style((string)$s['package_name'], (string)($s['package_color_hex'] ?? ''))) ?>"><?= h($s['package_name']) ?></span></span>
        <span class="<?= h($statusClass) ?>">Statut: <?= h($statusLabel) ?></span>
        <span class="badge">Score: <?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?></span>
        <span class="badge ok">Bonnes: <?= (int)$goodCount ?></span>
        <span class="badge bad">Mauvaises: <?= (int)$badCount ?></span>
        <span class="badge">Non repondues: <?= (int)$unansweredCount ?></span>
      </div>

      <div class="table-wrap">
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
                  <td><?= h($it['text']) ?></td>
                  <td><?= h($it['picked_labels'] ?: '-') ?></td>
                  <td><?= h($it['correct_labels'] ?: '-') ?></td>
                  <td><span class="<?= h($it['answer_status_class']) ?>" title="<?= h((string)($it['answer_status_label'] ?? '')) ?>" aria-label="<?= h((string)($it['answer_status_label'] ?? '')) ?>"><?= h($it['answer_status']) ?></span></td>
                  <td class="actions-cell">
                    <a class="btn ghost icon-btn" href="/admin/question_edit.php?id=<?= (int)$it['question_id'] ?>&return=<?= h(urlencode($sessionSelfUrl)) ?>" aria-label="Voir la question" title="Voir la question">
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
    </div>
  </div>
</body>
</html>
