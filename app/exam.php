<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = db();
$lang = get_lang();

$sid = $_GET['sid'] ?? '';
$p = (int)($_GET['p'] ?? 1);
$checked = ($_GET['checked'] ?? '') === '1';
if (!$sid) {
  render_error_page(400, 'Paramètre manquant', 'Identifiant de session manquant.', '/dashboard.php');
}

$stmt = $pdo->prepare("
  SELECT s.*, pk.duration_limit_minutes, pk.pass_threshold_percent, pk.name AS package_name, pk.name_color_hex AS package_color_hex
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.id=?
");
$stmt->execute([$sid]);
$sess = $stmt->fetch();
if (!$sess) {
  render_error_page(404, 'Session introuvable', 'Cette session n\'existe pas.', '/dashboard.php');
}

function exam_redirect_to_submit(string $sid, string $lang): void {
  header("Location: /submit.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
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
  SELECT sq.id AS session_question_id, q.id, q.text, q.explanation, q.question_type, q.allow_skip
  FROM session_questions sq
  JOIN questions q ON q.id = sq.question_id
  WHERE sq.session_id=? AND sq.position=?
");
$qstmt->execute([$sid, $p]);
$q = $qstmt->fetch();
if (!$q) {
  render_error_page(404, 'Question introuvable', 'Cette question n\'existe plus dans la session.', '/dashboard.php');
}

$qid = (int)$q['id'];
$sessionQuestionId = (int)($q['session_question_id'] ?? 0);
$q['text'] = translated_question_field($pdo, $qid, $lang, 'question_text', (string)($q['text'] ?? ''));
$q['explanation'] = translated_question_field($pdo, $qid, $lang, 'explanation', (string)($q['explanation'] ?? ''));
$questionExplanation = trim(localize_text((string)($q['explanation'] ?? ''), $lang));
$qType = (string)($q['question_type'] ?? 'MULTI');
if (!in_array($qType, ['MULTI', 'SINGLE', 'TRUE_FALSE'], true)) {
  $qType = 'MULTI';
}
$allowSkip = (int)($q['allow_skip'] ?? 0) === 1;
$isTraining = (($sess['session_type'] ?? 'EXAM') === 'TRAINING');
$showFeedback = $isTraining && $checked;

$pkgCooldownDays = 0;
if (!$isTraining && table_column_exists($pdo, 'packages', 'failed_cooldown_days')) {
  $cdStmt = $pdo->prepare("SELECT failed_cooldown_days FROM packages WHERE id=?");
  $cdStmt->execute([(int)$sess['package_id']]);
  $pkgCooldownDays = (int)(($cdStmt->fetchColumn()) ?: 0);
}

$optStmt = $pdo->prepare("
  SELECT id, label, option_text, is_correct
  FROM question_options
  WHERE question_id=?
  ORDER BY label ASC
");
$optStmt->execute([$qid]);
$options = $optStmt->fetchAll();
foreach ($options as &$optionRow) {
  $optionRow['option_text'] = translated_option_text($pdo, (int)($optionRow['id'] ?? 0), $lang, (string)($optionRow['option_text'] ?? ''));
}
unset($optionRow);
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
$effectiveQType = $qType;
if ($qType !== 'TRUE_FALSE') {
  $effectiveQType = count($correctIds) === 1 ? 'SINGLE' : 'MULTI';
}
$questionTypeHintKey = match ($effectiveQType) {
  'MULTI' => 'exam.answer_mode_multi',
  'SINGLE' => 'exam.answer_mode_single',
  default => '',
};
sort($selectedIds);
sort($correctIds);
$isQuestionCorrect = ($selectedIds === $correctIds);
$hasAnyCorrectSelection = false;
$hasAnyWrongSelection = false;
if ($effectiveQType === 'MULTI' && $selectedIds !== []) {
  foreach ($selectedIds as $selectedId) {
    if (in_array($selectedId, $correctIds, true)) {
      $hasAnyCorrectSelection = true;
    } else {
      $hasAnyWrongSelection = true;
    }
  }
}
$isIncompleteTrainingAnswer = $showFeedback && $isTraining && $effectiveQType === 'MULTI' && !$isQuestionCorrect && $hasAnyCorrectSelection && !$hasAnyWrongSelection;
$feedbackClass = $isQuestionCorrect ? 'exam-feedback exam-feedback-ok' : ($isIncompleteTrainingAnswer ? 'exam-feedback exam-feedback-partial' : 'exam-feedback exam-feedback-bad');
$feedbackKey = $isQuestionCorrect ? 'exam.feedback.correct' : ($isIncompleteTrainingAnswer ? 'exam.feedback.partial' : 'exam.feedback.incorrect');

$expiresTs = strtotime((string)$sess['started_at']) + (max(1, (int)$sess['duration_limit_minutes']) * 60);
$remainingSeconds = max(0, $expiresTs - time());
$formError = '';

if ($remainingSeconds <= 0 || session_is_expired($sess)) {
  exam_redirect_to_submit($sid, $lang);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $lang = get_lang();
  if ($remainingSeconds <= 0 || session_is_expired($sess)) {
    exam_redirect_to_submit($sid, $lang);
  }
  $navigationOnlyFromFeedback =
    $showFeedback &&
    (isset($_POST['next']) || ($isTraining && isset($_POST['pause'])) || isset($_POST['finish']) || isset($_POST['abandon']));
  $mustAnswerValidationError = false;

  if ($isTraining && isset($_POST['check'])) {
    $hasAnswer = false;
    if ($effectiveQType === 'MULTI') {
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
        if ($effectiveQType === 'MULTI') {
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

        $insert = answer_option_snapshots_enabled($pdo)
          ? $pdo->prepare("
              INSERT INTO answer_options(
                session_id,
                session_question_id,
                question_id,
                option_id,
                option_label_snapshot,
                option_text_snapshot
              )
              VALUES(?,?,?,?,?,?)
            ")
          : $pdo->prepare("INSERT INTO answer_options(session_id, question_id, option_id) VALUES(?,?,?)");
        foreach ($picked as $oid) {
          $oid = (int)$oid;
          if (!isset($valid[$oid])) {
            continue;
          }
          if (answer_option_snapshots_enabled($pdo)) {
            $matchedOption = null;
            foreach ($options as $optionRow) {
              if ((int)($optionRow['id'] ?? 0) === $oid) {
                $matchedOption = $optionRow;
                break;
              }
            }
            if ($matchedOption === null) {
              continue;
            }
            $insert->execute([
              $sid,
              $sessionQuestionId > 0 ? $sessionQuestionId : null,
              $qid,
              $oid,
              (string)($matchedOption['label'] ?? ''),
              (string)($matchedOption['option_text'] ?? ''),
            ]);
          } else {
            $insert->execute([$sid, $qid, $oid]);
          }
        }
      }

      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }

    // Keep a live score snapshot during the session.
    if ($sessionQuestionId > 0) {
      refresh_session_question_answer_status($pdo, $sessionQuestionId);
    }
    refresh_active_session_score($pdo, $sid);
  }

  if ($isTraining && isset($_POST['pause'])) {
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
  if (isset($_POST['abandon'])) {
    if ($isTraining) {
      $scoreSnapshot = compute_session_score_snapshot($pdo, $sid);
      $score = round((float)($scoreSnapshot['score_percent'] ?? 0.0), 2);
      $threshold = (int)$sess['pass_threshold_percent'];
      $passed = ($score >= $threshold) ? 1 : 0;
      mark_session_terminated($pdo, $sid, $score, $passed, 'MANUAL');
    } else {
      mark_session_terminated($pdo, $sid, null, 0, 'ABANDONED');
    }
    header("Location: /result.php?sid=" . urlencode($sid) . "&lang=" . urlencode($lang));
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
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title>Exam</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container">
  <div class="card">
    <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:8px;">
      <?php render_flag_lang_picker($lang, "'/exam.php?sid=" . urlencode($sid) . "&p=" . (int)$p . "&lang={lang}" . ($showFeedback ? '&checked=1' : '') . "'"); ?>
    </div>

    <div class="header">
      <div>
        <h2 class="h1"><span style="<?= h(package_label_style((string)$sess['package_name'], (string)($sess['package_color_hex'] ?? ''))) ?>"><?= h(localize_text((string)$sess['package_name'], $lang)) ?></span></h2>
        <p class="sub"><?= h(t('exam.title', ['p' => (int)$p, 'total' => (int)$total], $lang)) ?></p>
      </div>
      <span class="badge" id="t"></span>
    </div>

    <form method="post" id="exam-form">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">

	      <div class="card" style="box-shadow:none; border-radius:12px; border:1px solid var(--border);">
	        <p style="font-size:18px; margin-top:0;"><b><?= h(localize_text((string)$q['text'], $lang)) ?></b></p>
          <?php if ($questionTypeHintKey !== ''): ?>
            <p class="small" style="margin-top:8px;"><?= h(t($questionTypeHintKey, [], $lang)) ?></p>
          <?php endif; ?>

          <?php if ($formError !== ''): ?>
            <p class="error"><?= h($formError) ?></p>
          <?php endif; ?>

	        <?php foreach ($options as $o):
	          $oid = (int)$o['id'];
	          $isChecked = isset($selectedMap[$oid]);
		          $isMulti = ($effectiveQType === 'MULTI');
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
            <p class="<?= h($feedbackClass) ?>">
              <?= h(t($feedbackKey, [], $lang)) ?>
            </p>
            <?php if ($questionExplanation !== ''): ?>
              <div class="exam-explanation">
                <p class="exam-explanation-title"><?= h(t('exam.explanation', [], $lang)) ?></p>
                <p class="exam-explanation-text"><?= h($questionExplanation) ?></p>
              </div>
            <?php endif; ?>
          <?php endif; ?>

	      </div>

	      <div class="exam-actions" style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <?php if ($isTraining): ?>
            <?php if (!$showFeedback): ?>
              <button type="submit" class="btn" id="exam-primary-submit" name="check" value="1">
                <?= h(t('exam.validate', [], $lang)) ?>
              </button>
            <?php elseif ((int)$p < (int)$total): ?>
              <button type="submit" class="btn" name="next" value="1">
                <?= h(t('exam.next', [], $lang)) ?> &rarr;
              </button>
            <?php else: ?>
              <button type="submit" class="btn" id="exam-primary-submit" name="finish" value="1">
                <?= h(t('exam.validate', [], $lang)) ?>
              </button>
            <?php endif; ?>
          <?php else: ?>
            <?php if ((int)$p < (int)$total): ?>
              <button type="submit" class="btn" id="exam-primary-submit" name="next" value="1">
                <?= h(t('exam.validate', [], $lang)) ?>
              </button>
            <?php else: ?>
              <button type="submit" class="btn" id="exam-primary-submit" name="finish" value="1">
                <?= h(t('exam.validate', [], $lang)) ?>
              </button>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($isTraining): ?>
	        <button class="btn ghost" type="submit" name="pause" value="1" formnovalidate><?= h(t('exam.pause', [], $lang)) ?></button>
          <?php endif; ?>

          <button
            class="btn danger"
            type="submit"
            name="abandon"
            value="1"
            formnovalidate
            data-confirm-message="<?= h(t($isTraining ? 'exam.finish_confirm_training' : 'exam.finish_confirm_exam', [], $lang)) ?>"
            style="margin-left:auto;"
          >
            <?= h(t('exam.finish_qcm', [], $lang)) ?>
          </button>
	      </div>

    </form>
  </div>
</div>

<?php
$_abandonTitle = match($lang) {
  'en' => $isTraining ? 'End training session?' : 'Abandon this exam?',
  'es' => $isTraining ? '¿Terminar el entrenamiento?' : '¿Abandonar el examen?',
  'jp' => $isTraining ? 'トレーニングを終了しますか？' : '試験を放棄しますか？',
  default => $isTraining ? "Terminer l'entraînement ?" : "Abandonner l'examen ?",
};
$_abandonWarning = $isTraining ? '' : match($lang) {
  'en' => 'This action is irreversible.',
  'es' => 'Esta acción es irreversible.',
  'jp' => 'この操作は取り消せません。',
  default => 'Cette action est irréversible.',
};
$_abandonBody = match($lang) {
  'en' => $isTraining ? 'Your score will be calculated on questions answered so far.' : 'Your session will be cancelled and no score will be calculated.',
  'es' => $isTraining ? 'Tu puntuación se calculará con las preguntas respondidas hasta ahora.' : 'Tu sesión será cancelada y no se calculará ninguna puntuación.',
  'jp' => $isTraining ? 'ここまで回答した問題でスコアが計算されます。' : 'セッションがキャンセルされ、スコアは計算されません。',
  default => $isTraining ? 'Votre score sera calculé sur les questions répondues jusqu\'ici.' : 'Votre session sera annulée et aucun score ne sera calculé.',
};
$_abandonCooldown = '';
if (!$isTraining && $pkgCooldownDays > 0) {
  $_abandonCooldown = match($lang) {
    'en' => "You will not be able to retake this exam for {$pkgCooldownDays} days.",
    'es' => "No podrás volver a presentarte a este examen durante {$pkgCooldownDays} días.",
    'jp' => "{$pkgCooldownDays}日間、この試験を再受験できません。",
    default => "Vous ne pourrez pas repasser cet examen avant {$pkgCooldownDays} jours.",
  };
}
$_abandonConfirmLabel = match($lang) {
  'en' => $isTraining ? 'End session' : 'Confirm abandon',
  'es' => $isTraining ? 'Terminar sesión' : 'Confirmar abandono',
  'jp' => $isTraining ? 'セッション終了' : '放棄を確認',
  default => $isTraining ? 'Terminer la session' : "Confirmer l'abandon",
};
$_abandonCancelLabel = match($lang) { 'en' => 'Cancel', 'es' => 'Cancelar', 'jp' => 'キャンセル', default => 'Annuler' };
?>
<div id="exam-abandon-modal" class="exam-abandon-overlay" style="display:none;">
  <div class="exam-abandon-dialog">
    <div class="exam-abandon-icon"><?= $isTraining ? '⏹' : '⚠️' ?></div>
    <h3 class="exam-abandon-title"><?= h($_abandonTitle) ?></h3>
    <?php if ($_abandonWarning): ?>
      <p class="exam-abandon-warning"><?= h($_abandonWarning) ?></p>
    <?php endif; ?>
    <p class="exam-abandon-body"><?= h($_abandonBody) ?></p>
    <?php if ($_abandonCooldown): ?>
      <p class="exam-abandon-cooldown"><?= h($_abandonCooldown) ?></p>
    <?php endif; ?>
    <div class="exam-abandon-actions">
      <button type="button" id="exam-abandon-cancel" class="btn ghost"><?= h($_abandonCancelLabel) ?></button>
      <button type="button" id="exam-abandon-confirm" class="btn danger"><?= h($_abandonConfirmLabel) ?></button>
    </div>
  </div>
</div>

<script>
  window.__examIntentionalNavigation = false;

  (function () {
    var isExamSession = <?= json_encode(!$isTraining) ?>;
    var leaveEndpoint = '/session_leave.php';
    var leaveConfirmMessage = <?= json_encode(t('exam.leave_confirm_exam', [], $lang)) ?>;
    var leaveNoticeMessage = <?= json_encode(t('exam.leave_notice_exam', [], $lang)) ?>;
    var dashboardUrl = '/dashboard.php?lang=' + encodeURIComponent(<?= json_encode($lang) ?>) + '&err=' + encodeURIComponent(leaveNoticeMessage);
    var leaveHandled = false;
    var form = document.getElementById('exam-form');
    if (!form) return;
    var primarySubmit = document.getElementById('exam-primary-submit');
    var langSelect = document.getElementById('exam-lang');
    var answerInputs = Array.prototype.slice.call(form.querySelectorAll('input[name="answer"], input[name="answer[]"]'));

    function markIntentionalNavigation() {
      window.__examIntentionalNavigation = true;
    }

    function sendLeaveSignal(force) {
      if (!isExamSession || leaveHandled) return Promise.resolve();
      if (!force && window.__examIntentionalNavigation) return Promise.resolve();
      leaveHandled = true;

      try {
        var payload = new FormData();
        payload.append('sid', <?= json_encode($sid) ?>);

        return fetch(leaveEndpoint, {
          method: 'POST',
          body: payload,
          credentials: 'same-origin',
          keepalive: true
        }).catch(function () {});
      } catch (e) {
        return Promise.resolve();
      }

      return Promise.resolve();
    }

    function notifyExamLeave() {
      if (!isExamSession || window.__examIntentionalNavigation || leaveHandled) return;

      if (navigator.sendBeacon) {
        try {
          var payload = new FormData();
          payload.append('sid', <?= json_encode($sid) ?>);
          leaveHandled = true;
          navigator.sendBeacon(leaveEndpoint, payload);
          return;
        } catch (e) {
          leaveHandled = false;
        }
      }

      sendLeaveSignal(false);
    }

    function leaveExamAndGo(targetUrl) {
      if (!isExamSession) {
        window.location.replace(targetUrl);
        return;
      }

      markIntentionalNavigation();
      Promise.resolve(sendLeaveSignal(true)).finally(function () {
        window.location.replace(targetUrl);
      });
    }

    function syncPrimarySubmitState() {
      if (!primarySubmit) return;
      var hasCheckedAnswer = answerInputs.some(function (input) {
        return input.checked;
      });
      primarySubmit.disabled = !hasCheckedAnswer;
    }

    if (primarySubmit && answerInputs.length > 0 && !primarySubmit.disabled) {
      syncPrimarySubmitState();
      answerInputs.forEach(function (input) {
        input.addEventListener('change', syncPrimarySubmitState);
      });
    }

    var abandonModal   = document.getElementById('exam-abandon-modal');
    var abandonConfirm = document.getElementById('exam-abandon-confirm');
    var abandonCancel  = document.getElementById('exam-abandon-cancel');

    form.addEventListener('submit', function (e) {
      var submitter = e.submitter;
      if (!submitter) return;
      if (submitter.name !== 'abandon') { markIntentionalNavigation(); return; }
      e.preventDefault();
      if (abandonModal) abandonModal.style.display = 'flex';
    });

    if (abandonConfirm) {
      abandonConfirm.addEventListener('click', function () {
        if (abandonModal) abandonModal.style.display = 'none';
        markIntentionalNavigation();
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'abandon'; inp.value = '1';
        form.appendChild(inp);
        form.submit();
      });
    }

    if (abandonCancel) {
      abandonCancel.addEventListener('click', function () {
        if (abandonModal) abandonModal.style.display = 'none';
      });
    }

    if (abandonModal) {
      abandonModal.addEventListener('click', function (e) {
        if (e.target === abandonModal) abandonModal.style.display = 'none';
      });
    }

    if (langSelect) {
      langSelect.addEventListener('change', markIntentionalNavigation);
    }

    if (isExamSession) {
      try {
        window.history.pushState({ examGuard: true }, '', window.location.href);
      } catch (e) {
        // Ignore history guard failures.
      }
    }

    window.addEventListener('beforeunload', function (e) {
      if (!isExamSession || window.__examIntentionalNavigation) return;
      e.preventDefault();
      e.returnValue = leaveConfirmMessage;
      return leaveConfirmMessage;
    });

    window.addEventListener('popstate', function () {
      if (!isExamSession || window.__examIntentionalNavigation) return;

      var confirmed = window.confirm(leaveConfirmMessage);
      if (!confirmed) {
        try {
          window.history.pushState({ examGuard: true }, '', window.location.href);
        } catch (e) {
          // Ignore history guard failures.
        }
        return;
      }

      leaveExamAndGo(dashboardUrl);
    });

    window.addEventListener('pagehide', notifyExamLeave);
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) {
        window.location.reload();
      }
    });
  })();

  let remaining = <?= (int)$remainingSeconds ?>;
  function tick() {
    const m = Math.floor(remaining / 60);
    const s = remaining % 60;
    document.getElementById('t').textContent =
      "<?= h(t('exam.timer_prefix', [], $lang)) ?>: " + m + "<?= h(t('exam.min', [], $lang)) ?> " + (s < 10 ? "0" : "") + s + "<?= h(t('exam.sec', [], $lang)) ?>";
    remaining--;
    if (remaining < 0) {
      window.__examIntentionalNavigation = true;
      location.href = "/submit.php?sid=<?= h(urlencode($sid)) ?>&lang=<?= h($lang) ?>";
    }
  }
  tick();
  setInterval(tick, 1000);
</script>

</body>
</html>

