<?php
require_once __DIR__ . '/_auth.php';
require_admin();

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

    -- bonnes réponses (ex: 'A' ou 'A,C')
    (
      SELECT GROUP_CONCAT(qo.label ORDER BY qo.label SEPARATOR ',')
      FROM question_options qo
      WHERE qo.question_id = q.id AND qo.is_correct = 1
    ) AS correct_labels,

    -- réponses candidat (ex: 'B' ou 'A,D')
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
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Détail session</title><link rel="stylesheet" href="/assets/style.css">
</head>
<body style="font-family: Arial; max-width: 980px; margin: 40px auto;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
  <div>
      <h2 class="h1" style="margin:0;">Détail session</h2>
    </div>
    <div style="display:flex; gap:10px;">
      <a class="btn ghost" href="/admin/index.php">← Retour</a>
      <a class="btn ghost" href="/logout.php">Déconnexion</a>
    </div>
  </div>

  <p><b>Email :</b> <?= h($s['email']) ?></p>
  <p><b>Package :</b> <?= h($s['package_name']) ?></p>
  <p><b>Statut :</b> <?= h($s['status']) ?></p>
  <p><b>Score :</b> <?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?></p>

  <hr>
  <?php foreach ($items as $it): ?>
    <div style="padding: 12px; border:1px solid #ddd; border-radius:8px; margin-bottom:10px;">
      <p><b>#<?= (int)$it['position'] ?>.</b> <?= h($it['text']) ?></p>
      <p><b>Réponse candidat :</b> <?= h($it['picked_labels'] ?: '-') ?> | <b>Correct :</b> <?= h($it['correct_labels'] ?: '-') ?></p>
    </div>
  <?php endforeach; ?>
</body>
</html>
