<?php
require_once __DIR__ . '/auth.php';
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
  SELECT sq.position, q.text, q.correct_option, a.answer
  FROM session_questions sq
  JOIN questions q ON q.id = sq.question_id
  LEFT JOIN answers a ON a.session_id = sq.session_id AND a.question_id = q.id
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
  <h2>Détail session</h2>
  <p><a href="/admin/index.php">← Liste</a></p>

  <p><b>Email :</b> <?= h($s['email']) ?></p>
  <p><b>Package :</b> <?= h($s['package_name']) ?></p>
  <p><b>Statut :</b> <?= h($s['status']) ?></p>
  <p><b>Score :</b> <?= $s['score_percent'] !== null ? h($s['score_percent']).'%' : '-' ?></p>

  <hr>
  <?php foreach ($items as $it): ?>
    <div style="padding: 12px; border:1px solid #ddd; border-radius:8px; margin-bottom:10px;">
      <p><b>#<?= (int)$it['position'] ?>.</b> <?= h($it['text']) ?></p>
      <p><b>Réponse candidat :</b> <?= h($it['answer'] ?? '-') ?> | <b>Correct :</b> <?= h($it['correct_option']) ?></p>
    </div>
  <?php endforeach; ?>
</body>
</html>
