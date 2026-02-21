<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = db();
$lang = get_lang();

$sid = $_GET['sid'] ?? '';
$p = (int)($_GET['p'] ?? 1);
$checked = ($_GET['checked'] ?? '') === '1';
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

$hasPausedRemaining = sessions_column_exists($pdo, 'paused_remaining_seconds');
if ($hasPausedRemaining && isset($sess['paused_remaining_seconds']) && $sess['paused_remaining_seconds'] !== null) {
  $remaining = max(0, (int)$sess['paused_remaining_seconds']);
  $durationSeconds = max(1, (int)$sess['duration_limit_minutes']) * 60;
  $elapsed = max(0, $durationSeconds - $remaining);
  $resume = $pdo->prepare("
    UPDATE sessions
    SET started_at=FROM_UNIXTIME(UNIX_TIMESTAMP(NOW()) - ?),
        paused_remaining_seconds=NULL
    WHERE id=? AND status='ACTIVE'
  ");
  $resume->execute([$elapsed, $sid]);

  $stmt->execute([$sid]);
  $sess = $stmt->fetch();
}

if (!$hasPausedRemaining && isset($_SESSION['paused_remaining'][$sid])) {
  $remaining = max(0, (int)$_SESSION['paused_remaining'][$sid]);
  $durationSeconds = max(1, (int)$sess['duration_limit_minutes']) * 60;
  $elapsed = max(0, $durationSeconds - $remaining);
  $resume = $pdo->prepare("
    UPDATE sessions
    SET started_at=FROM_UNIXTIME(UNIX_TIMESTAMP(NOW()) - ?)
    WHERE id=? AND status='ACTIVE'
  ");
  $resume->execute([$elapsed, $sid]);
  unset($_SESSION['paused_remaining'][$sid]);

  $stmt->execute([$sid]);
  $sess = $stmt->fetch();
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
$isTraining = (($sess['session_type'] ?? 'EXAM') === 'TRAINING');
$showFeedback = $isTraining && $checked;

$optStmt = $pdo->prepare("
  SELECT id, label, option_text, is_correct
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

$correctIds = [];
foreach ($options as $o) {
  if ((int)($o['is_correct'] ?? 0) === 1) {
    $correctIds[] = (int)$o['id'];
  }
}
sort($selectedIds);
sort($correctIds);
$isQuestionCorrect = ($selectedIds === $correctIds);

$expiresTs = strtotime((string)$sess['started_at']) + (max(1, (int)$sess['duration_limit_minutes']) * 60);
$remainingSeconds = max(0, $expiresTs - time());
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $lang = get_lang();
  $navigationOnlyFromFeedback =
    $showFeedback &&
    (isset($_POST['next']) || isset($_POST['pause']) || isset($_POST['finish']));
  $mustAnswerValidationError = false;

  if ($isTraining && isset($_POST['check'])) {
    $hasAnswer = false;
    if ($qType === 'MULTI') {
      $posted = $_POST['answer'] ?? [];
      $hasAnswer = is_array($posted) && count($posted) > 0;
    } else {
      $one = trim((string)($_POST['answer'] ?? ''));
      $hasAnswer = ($one !== '');
    }
    if (!$hasAnswer) {
      $mustAnswerValidationError = true;
      $formError = t('exam.must_answer', [], $lang);
    }
  }

  if (!$navigationOnlyFromFeedback && !$mustAnswerValidationError) {
    $pdo->beginTransaction();
    try {
      $pdo->prepare("DELETE FROM answer_options WHERE session_id=? AND question_id=?")->execute([$sid, $qid]);

      $picked = [];
      if (isset($_POST['skip']) && $allowSkip && !$isTraining) {
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
  }

  if (isset($_POST['pause'])) {
    if ($hasPausedRemaining) {
      $savePause = $pdo->prepare("
        UPDATE sessions
        SET paused_remaining_seconds=?
        WHERE id=? AND status='ACTIVE'
      ");
      $savePause->execute([max(0, (int)$remainingSeconds), $sid]);
    } else {
      if (!isset($_SESSION['paused_remaining']) || !is_array($_SESSION['paused_remaining'])) {
        $_SESSION['paused_remaining'] = [];
      }
      $_SESSION['paused_remaining'][$sid] = max(0, (int)$remainingSeconds);
    }
    header("Location: /dashboard.php?lang=" . urlencode($lang));
    exit;
  }
  if ($isTraining && isset($_POST['check'])) {
    if ($mustAnswerValidationError) {
      // Stay on the same question and display the validation message.
      $checked = false;
      $showFeedback = false;
    } else {
    header("Location: /exam.php?sid=" . urlencode($sid) . "&p=" . (int)$p . "&lang=" . urlencode($lang) . "&checked=1");
    exit;
    }
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
	              onchange="window.location.href='/exam.php?sid=<?= h(urlencode($sid)) ?>&p=<?= (int)$p ?>&lang=' + encodeURIComponent(this.value) + '<?= $showFeedback ? '&checked=1' : '' ?>';">
	        <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>><?= h(t('lang.fr', [], $lang)) ?></option>
	        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>><?= h(t('lang.en', [], $lang)) ?></option>
	        <option value="es" <?= $lang === 'es' ? 'selected' : '' ?>><?= h(t('lang.es', [], $lang)) ?></option>
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

          <?php if ($formError !== ''): ?>
            <p class="error"><?= h($formError) ?></p>
          <?php endif; ?>

	        <?php foreach ($options as $o):
	          $oid = (int)$o['id'];
	          $isChecked = isset($selectedMap[$oid]);
	          $isMulti = ($qType !== 'TRUE_FALSE');
            $isCorrectOption = (int)($o['is_correct'] ?? 0) === 1;
            $optionClass = 'exam-option';
            if ($showFeedback) {
              if ($isCorrectOption) {
                $optionClass .= ' is-correct';
              } elseif ($isChecked) {
                $optionClass .= ' is-wrong';
              }
            }
	        ?>
	          <label class="<?= h($optionClass) ?>">
	            <input
	              type="<?= $isMulti ? 'checkbox' : 'radio' ?>"
	              name="<?= $isMulti ? 'answer[]' : 'answer' ?>"
	              value="<?= $oid ?>"
	              <?= $isChecked ? 'checked' : '' ?>
	              <?= (($isTraining || !$allowSkip) && !$isMulti) ? 'required' : '' ?>
                <?= $showFeedback ? 'disabled' : '' ?>
	            >
	            <b style="margin-left:8px;"><?= h($o['label']) ?>.</b>
	            <span style="margin-left:6px;"><?= h(localize_text((string)$o['option_text'], $lang)) ?></span>
	          </label>
	        <?php endforeach; ?>

          <?php if ($showFeedback): ?>
            <p class="<?= $isQuestionCorrect ? 'exam-feedback exam-feedback-ok' : 'exam-feedback exam-feedback-bad' ?>">
              <?= h($isQuestionCorrect ? t('exam.feedback.correct', [], $lang) : t('exam.feedback.incorrect', [], $lang)) ?>
            </p>
          <?php endif; ?>

	        <?php if ($allowSkip && !$showFeedback && !$isTraining): ?>
	          <button class="btn ghost" name="skip" value="1" style="margin-top:6px;"><?= h(t('exam.skip', [], $lang)) ?></button>
	        <?php endif; ?>
	      </div>

	      <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <?php if ($isTraining): ?>
            <?php if (!$showFeedback): ?>
              <button type="submit" class="btn" name="check" value="1">
                <?= h(t('exam.validate', [], $lang)) ?>
              </button>
            <?php elseif ((int)$p < (int)$total): ?>
              <button type="submit" class="btn" name="next" value="1">
                <?= h(t('exam.next', [], $lang)) ?> &rarr;
              </button>
            <?php endif; ?>
          <?php else: ?>
            <?php if ((int)$p < (int)$total): ?>
              <button type="submit" class="btn" name="next" value="1">
                <?= h(t('exam.validate', [], $lang)) ?>
              </button>
            <?php endif; ?>
          <?php endif; ?>
	        <button class="btn ghost" type="submit" name="pause" value="1"><?= h(t('exam.pause', [], $lang)) ?></button>
	        <?php if ((int)$p === (int)$total && (!$isTraining || $showFeedback)): ?>
	          <button class="btn" name="finish" style="margin-left:auto;"><?= h(t('exam.finish', [], $lang)) ?></button>
	        <?php endif; ?>
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

