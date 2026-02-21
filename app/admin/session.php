<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

$sid = $_GET['sid'] ?? '';
if (!$sid) { http_response_code(400); echo "Missing sid"; exit; }

$stmt = $pdo->prepare("
  SELECT s.*, c.email, pk.name AS package_name, pk.pass_threshold_percent
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
  <link rel="stylesheet" href="/assets/style.css">
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
        </div>
      </div>

      <hr class="separator">

      <div class="row sessions-stats">
        <span class="badge">Email: <?= h($s['email']) ?></span>
        <span class="badge">Package: <span style="<?= h(package_label_style((string)$s['package_name'])) ?>"><?= h($s['package_name']) ?></span></span>
        <span class="<?= h($statusClass) ?>">Statut: <?= h($statusLabel) ?></span>
        <span class="badge">Score: <?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?></span>
      </div>

      <div class="table-wrap">
        <?php if (!$items): ?>
          <p class="empty-state">Aucune question dans cette session.</p>
        <?php else: ?>
          <table class="table questions-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Question</th>
                <th>Reponse candidat</th>
                <th>Reponse correcte</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><?= (int)$it['position'] ?></td>
                  <td><?= h($it['text']) ?></td>
                  <td><?= h($it['picked_labels'] ?: '-') ?></td>
                  <td><?= h($it['correct_labels'] ?: '-') ?></td>
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
