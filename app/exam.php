<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

$pdo = db();
$lang = get_lang();

$sid = $_GET['sid'] ?? '';
$p = (int)($_GET['p'] ?? 1);
if (!$sid) {
  http_response_code(400);
  echo h(t('exam.missing_sid', [], $lang));
  exit;
}

$stmt = $pdo->prepare("
  SELECT s.*, pk.duration_limit_minutes, pk.name AS package_name
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.id=?
");
$stmt->execute([$sid]);
$sess = $stmt->fetch();
if (!$sess) {
  http_response_code(404);
  echo h(t('exam.session_not_found', [], $lang));
  exit;
}

if ($sess['status'] !== 'ACTIVE') {
  header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
  exit;
}

if (session_is_expired($sess)) {
  mark_session_expired($pdo, $sid);
  header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
  exit;
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) c FROM session_questions WHERE session_id=?");
$totalStmt->execute([$sid]);
$total = (int)$totalStmt->fetch()['c'];
if ($total <= 0) {
  header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
  exit;
}

if ($p < 1) {
  $p = 1;
}
if ($p > $total) {
  $p = $total;
}

$qstmt = $pdo->prepare("
  SELECT q.id, q.text, q.question_type, q.allow_skip
  FROM session_questions sq
  JOIN questions q ON q.id = sq.question_id
  WHERE sq.session_id=? AND sq.position=?
");
$qstmt->execute([$sid, $p]);
$q = $qstmt->fetch();
if (!$q) {
  http_response_code(404);
  echo h(t('exam.question_not_found', [], $lang));
  exit;
}

$qid = (int)$q['id'];
$qType = $q['question_type'] ?? 'MULTI';
$allowSkip = (int)($q['allow_skip'] ?? 1) === 1;

$optStmt = $pdo->prepare("
  SELECT id, label, option_text
  FROM question_options
  WHERE question_id=?
  ORDER BY label ASC
");
$optStmt->execute([$qid]);
$options = $optStmt->fetchAll();
if (count($options) < 2) {
  header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
  exit;
}

$selStmt = $pdo->prepare("SELECT option_id FROM answer_options WHERE session_id=? AND question_id=?");
$selStmt->execute([$sid, $qid]);
$selectedIds = array_map(fn($r) => (int)$r['option_id'], $selStmt->fetchAll());
$selectedMap = array_fill_keys($selectedIds, true);

$expiresTs = strtotime((string)$sess['started_at']) + (max(1, (int)$sess['duration_limit_minutes']) * 60);
$remainingSeconds = max(0, $expiresTs - time());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $lang = get_lang();

  $pdo->beginTransaction();
  try {
    $pdo->prepare("DELETE FROM answer_options WHERE session_id=? AND question_id=?")->execute([$sid, $qid]);

    $picked = [];
    if (isset($_POST['skip']) && $allowSkip) {
      $picked = [];
    } else {
      if ($qType === 'MULTI') {
        $picked = $_POST['answer'] ?? [];
        if (!is_array($picked)) {
          $picked = [];
        }
      } else {
        $one = $_POST['answer'] ?? '';
        $picked = ($one !== '') ? [$one] : [];
      }

      $valid = [];
      foreach ($options as $o) {
        $valid[(int)$o['id']] = true;
      }

      $insert = $pdo->prepare("INSERT INTO answer_options(session_id, question_id, option_id) VALUES(?,?,?)");
      foreach ($picked as $oid) {
        $oid = (int)$oid;
        if (!isset($valid[$oid])) {
          continue;
        }
        $insert->execute([$sid, $qid, $oid]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  if (isset($_POST['prev']) && $p > 1) {
    $p--;
  }
  if (isset($_POST['next']) && $p < $total) {
    $p++;
  }
  if (isset($_POST['finish'])) {
    header("Location: /submit.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
    exit;
  }
  header("Location: /exam.php?sid=" . urlencode($sid) . "&p=" . (int)$p . "&lang=" . urlencode($lang));
  exit;
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <meta charset="utf-8">
  <title>Exam</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
</head>
<body>
<div class="container">
  <div class="card">
    <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:8px;">
      <select id="exam-lang" class="input lang-select"
              onchange="window.location.href='/exam.php?sid=<?= h(urlencode($sid)) ?>&p=<?= (int)$p ?>&lang=' + encodeURIComponent(this.value);">
        <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>><?= h(t('lang.fr', [], $lang)) ?></option>
        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>><?= h(t('lang.en', [], $lang)) ?></option>
        <option value="jp" <?= $lang === 'jp' ? 'selected' : '' ?>><?= h(t('lang.jp', [], $lang)) ?></option>
      </select>
    </div>

    <div class="header">
      <div>
        <h2 class="h1"><span style="<?= h(package_label_style((string)$sess['package_name'])) ?>"><?= h(localize_text((string)$sess['package_name'], $lang)) ?></span></h2>
        <p class="sub"><?= h(t('exam.title', ['p' => (int)$p, 'total' => (int)$total], $lang)) ?></p>
      </div>
      <span class="badge" id="t"></span>
    </div>

    <form method="post">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">

      <div class="card" style="box-shadow:none; border-radius:12px; border:1px solid var(--border);">
        <p style="font-size:18px; margin-top:0;"><b><?= h(localize_text((string)$q['text'], $lang)) ?></b></p>

        <?php foreach ($options as $o):
          $oid = (int)$o['id'];
          $checked = isset($selectedMap[$oid]);
          $isMulti = ($qType !== 'TRUE_FALSE');
        ?>
          <label style="display:block; padding:10px 10px; border:1px solid var(--border); border-radius:10px; margin:10px 0; cursor:pointer;">
            <input
              type="<?= $isMulti ? 'checkbox' : 'radio' ?>"
              name="<?= $isMulti ? 'answer[]' : 'answer' ?>"
              value="<?= $oid ?>"
              <?= $checked ? 'checked' : '' ?>
              <?= (!$allowSkip && !$isMulti) ? 'required' : '' ?>
            >
            <b style="margin-left:8px;"><?= h($o['label']) ?>.</b>
            <span style="margin-left:6px;"><?= h(localize_text((string)$o['option_text'], $lang)) ?></span>
          </label>
        <?php endforeach; ?>

        <?php if ($allowSkip): ?>
          <button class="btn ghost" name="skip" value="1" style="margin-top:6px;"><?= h(t('exam.skip', [], $lang)) ?></button>
        <?php endif; ?>
      </div>

      <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn ghost" name="prev" <?= $p <= 1 ? 'disabled' : '' ?>>&larr; <?= h(t('exam.prev', [], $lang)) ?></button>
        <button type="submit" class="btn ghost" name="next" value="1" <?= ((int)$p === (int)$total) ? 'disabled="disabled"' : '' ?>>
          <?= h(t('exam.next', [], $lang)) ?> &rarr;
        </button>
        <button class="btn" name="finish" style="margin-left:auto;"><?= h(t('exam.finish', [], $lang)) ?></button>
      </div>

      <p class="small" style="margin-top:12px;"><?= h(t('exam.score_hint', [], $lang)) ?></p>
    </form>
  </div>
</div>

<script>
  let remaining = <?= (int)$remainingSeconds ?>;
  function tick() {
    const m = Math.floor(remaining / 60);
    const s = remaining % 60;
    document.getElementById('t').textContent =
      "<?= h(t('exam.timer_prefix', [], $lang)) ?>: " + m + "<?= h(t('exam.min', [], $lang)) ?> " + (s < 10 ? "0" : "") + s + "<?= h(t('exam.sec', [], $lang)) ?>";
    remaining--;
    if (remaining < 0) location.href = "/submit.php?sid=<?= h(urlencode($sid)) ?>&lang=<?= h($lang) ?>";
  }
  tick();
  setInterval(tick, 1000);
</script>

</body>
</html>
