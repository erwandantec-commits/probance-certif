<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

$pdo = db();

$sid = $_GET['sid'] ?? '';
$p = (int)($_GET['p'] ?? 1);
if (!$sid) { http_response_code(400); echo "Missing sid"; exit; }

// Load session + package
$stmt = $pdo->prepare("
  SELECT s.*, pk.duration_limit_minutes, pk.name AS package_name
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.id=?
");
$stmt->execute([$sid]);
$sess = $stmt->fetch();
if (!$sess) { http_response_code(404); echo "Session not found"; exit; }

if ($sess['status'] !== 'ACTIVE') {
  header("Location: /result.php?sid=" . urlencode($sid));
  exit;
}

// Expiration
$started = new DateTime($sess['started_at']);
$limitMin = (int)$sess['duration_limit_minutes'];
if ($limitMin < 1) $limitMin = 1;

$expires = (clone $started)->modify("+{$limitMin} minutes");
$now = new DateTime("now");

if ($now > $expires) {
  $upd = $pdo->prepare("UPDATE sessions SET status='EXPIRED' WHERE id=?");
  $upd->execute([$sid]);
  header("Location: /result.php?sid=" . urlencode($sid));
  exit;
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) c FROM session_questions WHERE session_id=?");
$totalStmt->execute([$sid]);
$total = (int)$totalStmt->fetch()['c'];
if ($total <= 0) {
  // nothing to display -> go result
  header("Location: /result.php?sid=" . urlencode($sid));
  exit;
}

if ($p < 1) $p = 1;
if ($p > $total) $p = $total;

// Load question at position
$qstmt = $pdo->prepare("
  SELECT q.id, q.text, q.question_type, q.allow_skip
  FROM session_questions sq
  JOIN questions q ON q.id = sq.question_id
  WHERE sq.session_id=? AND sq.position=?
");
$qstmt->execute([$sid, $p]);
$q = $qstmt->fetch();
if (!$q) { http_response_code(404); echo "Question not found"; exit; }

$qid = (int)$q['id'];
$qType = $q['question_type'] ?? 'SINGLE';
$allowSkip = (int)($q['allow_skip'] ?? 1) === 1;

// Load options A..F
$optStmt = $pdo->prepare("
  SELECT id, label, option_text
  FROM question_options
  WHERE question_id=?
  ORDER BY label ASC
");
$optStmt->execute([$qid]);
$options = $optStmt->fetchAll();
if (count($options) < 2) {
  header("Location: /result.php?sid=" . urlencode($sid));
  exit;
}

// Existing selections
$selStmt = $pdo->prepare("SELECT option_id FROM answer_options WHERE session_id=? AND question_id=?");
$selStmt->execute([$sid, $qid]);
$selectedIds = array_map(fn($r)=>(int)$r['option_id'], $selStmt->fetchAll());
$selectedMap = array_fill_keys($selectedIds, true);

$remainingSeconds = max(0, $expires->getTimestamp() - $now->getTimestamp());

// Save answer if posted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Always replace selections for this question
  $pdo->beginTransaction();
  try {
    $pdo->prepare("DELETE FROM answer_options WHERE session_id=? AND question_id=?")->execute([$sid, $qid]);

    $picked = [];

    if (isset($_POST['skip']) && $allowSkip) {
      $picked = []; // no answer
    } else {
      if ($qType === 'MULTI') {
        $picked = $_POST['answer'] ?? [];
        if (!is_array($picked)) $picked = [];
      } else {
        $one = $_POST['answer'] ?? '';
        $picked = ($one !== '') ? [$one] : [];
      }

      // Keep only valid option ids
      $valid = [];
      foreach ($options as $o) $valid[(int)$o['id']] = true;

      $insert = $pdo->prepare("
        INSERT INTO answer_options(session_id, question_id, option_id)
        VALUES(?,?,?)
      ");

      foreach ($picked as $oid) {
        $oid = (int)$oid;
        if (!isset($valid[$oid])) continue;
        $insert->execute([$sid, $qid, $oid]);
      }
    }

    $pdo->commit();

  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  if (isset($_POST['prev'])) $p--;
  if (isset($_POST['next'])) $p++;
  if (isset($_POST['finish'])) {
    header("Location: /submit.php?sid=" . urlencode($sid));
    exit;
  }
  header("Location: /exam.php?sid=" . urlencode($sid) . "&p=" . (int)$p);
  exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Exam</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="container">
  <div class="card">
    <div class="header">
      <div>
        <h2 class="h1"><?= h($sess['package_name']) ?></h2>
        <p class="sub">Question <?= (int)$p ?> / <?= (int)$total ?> · <?= h($qType) ?></p>
      </div>
      <span class="badge" id="t">⏱</span>
    </div>

    <form method="post">
      <div class="card" style="box-shadow:none; border-radius:12px; border:1px solid var(--border);">
        <p style="font-size:18px; margin-top:0;"><b><?= h($q['text']) ?></b></p>

        <?php foreach ($options as $o):
          $oid = (int)$o['id'];
          $checked = isset($selectedMap[$oid]);
          $label = $o['label'];
          $text = $o['option_text'];
          $isMulti = ($qType === 'MULTI');
        ?>
          <label style="display:block; padding:10px 10px; border:1px solid var(--border); border-radius:10px; margin:10px 0; cursor:pointer;">
            <input
              type="<?= $isMulti ? 'checkbox' : 'radio' ?>"
              name="<?= $isMulti ? 'answer[]' : 'answer' ?>"
              value="<?= $oid ?>"
              <?= $checked ? 'checked' : '' ?>
              <?= (!$allowSkip && !$isMulti) ? 'required' : '' ?>
            >
            <b style="margin-left:8px;"><?= h($label) ?>.</b>
            <span style="margin-left:6px;"><?= h($text) ?></span>
          </label>
        <?php endforeach; ?>

        <?php if ($allowSkip): ?>
          <button class="btn ghost" name="skip" value="1" style="margin-top:6px;">Ne pas répondre</button>
        <?php endif; ?>
      </div>

      <div style="margin-top: 14px; display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn ghost" name="prev" <?= $p<=1?'disabled':'' ?>>← Précédent</button>
        <button class="btn ghost" name="next" <?= $p>=$total?'disabled':'' ?>>Suivant →</button>
        <button class="btn" name="finish" style="margin-left:auto;">Terminer</button>
      </div>

      <p class="small" style="margin-top:12px;">
        Score calculé à la fin. Mauvaise réponse = -1 (selon score_value).
      </p>
    </form>
  </div>
</div>

<script>
  let remaining = <?= (int)$remainingSeconds ?>;
  function tick(){
    const m = Math.floor(remaining/60);
    const s = remaining % 60;
    document.getElementById('t').textContent = "⏱ " + m + "m " + (s<10?"0":"") + s + "s";
    remaining--;
    if (remaining < 0) location.href = "/submit.php?sid=<?= h($sid) ?>";
  }
  tick(); setInterval(tick, 1000);
</script>

</body>
</html>
