<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = db();
$lang = get_lang();


$resultBadgeImageSelect = table_column_exists($pdo, 'packages', 'badge_image_filename')
  ? ", pk.badge_image_filename AS package_badge_image"
  : ", NULL AS package_badge_image";
$resultProfileSelect = table_column_exists($pdo, 'packages', 'profile')
  ? ", pk.profile AS package_profile"
  : ", NULL AS package_profile";
$resultCertValidityDaysSelect = table_column_exists($pdo, 'packages', 'cert_validity_days')
  ? ", pk.cert_validity_days AS package_cert_validity_days"
  : ", 365 AS package_cert_validity_days";

$sid = $_GET['sid'] ?? '';
if (!$sid) {
  http_response_code(400);
  echo h(t('exam.missing_sid', [], $lang));
  exit;
}

$stmt = $pdo->prepare("
  SELECT s.*, c.email, pk.name AS package_name, pk.name_color_hex AS package_color_hex, pk.pass_threshold_percent, pk.duration_limit_minutes
    $resultBadgeImageSelect
    $resultProfileSelect
    $resultCertValidityDaysSelect
  FROM sessions s
  JOIN contacts c ON c.id = s.contact_id
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.id=?
");
$stmt->execute([$sid]);
$s = $stmt->fetch();
if (!$s) {
  http_response_code(404);
  echo h(t('exam.session_not_found', [], $lang));
  exit;
}

if (session_is_expired($s)) {
  $scoreSnapshot = compute_session_score_snapshot($pdo, $sid);
  $score = (float)($scoreSnapshot['score_percent'] ?? 0.0);
  $roundedScore = round($score, 2);
  $threshold = (int)$s['pass_threshold_percent'];
  $passed = ($roundedScore >= $threshold) ? 1 : 0;
  mark_session_terminated($pdo, $sid, $roundedScore, $passed, 'TIMEOUT');

  $stmt = $pdo->prepare("
    SELECT s.*, c.email, pk.name AS package_name, pk.name_color_hex AS package_color_hex, pk.pass_threshold_percent, pk.duration_limit_minutes
      $resultBadgeImageSelect
      $resultProfileSelect
      $resultCertValidityDaysSelect
    FROM sessions s
    JOIN contacts c ON c.id = s.contact_id
    JOIN packages pk ON pk.id = s.package_id
    WHERE s.id=?
  ");
$stmt->execute([$sid]);
$s = $stmt->fetch();
}

function result_is_timeout_session(array $s): bool {
  return (string)($s['status'] ?? '') === 'EXPIRED'
    || (
      (string)($s['status'] ?? '') === 'TERMINATED'
      && strtoupper(trim((string)($s['termination_type'] ?? 'MANUAL'))) === 'TIMEOUT'
    );
}

function result_is_abandoned_session(array $s): bool {
  return (string)($s['status'] ?? '') === 'TERMINATED'
    && strtoupper(trim((string)($s['termination_type'] ?? ''))) === 'ABANDONED';
}

function result_display_status(array $s): string {
  if (result_is_abandoned_session($s)) {
    return 'ABANDONED';
  }
  if (result_is_timeout_session($s)) {
    return 'EXPIRED';
  }
  return (string)($s['status'] ?? '');
}

function result_status_label(string $status, string $lang): string {
  return match ($status) {
    'TERMINATED' => t('dash.status.terminated', [], $lang),
    'ACTIVE' => t('dash.status.active', [], $lang),
    'EXPIRED' => t('dash.status.expired', [], $lang),
    'ABANDONED' => t('dash.status.abandoned', [], $lang),
    default => $status,
  };
}

function result_session_passed(array $s): ?bool {
  $displayStatus = result_display_status($s);
  if ($displayStatus === 'ABANDONED') {
    return false;
  }
  if (!in_array($displayStatus, ['TERMINATED', 'EXPIRED'], true)) {
    return null;
  }

  if ($s['score_percent'] !== null && $s['score_percent'] !== '' && isset($s['pass_threshold_percent'])) {
    return round((float)$s['score_percent'], 2) >= (float)$s['pass_threshold_percent'];
  }

  if ($s['passed'] === null || $s['passed'] === '') {
    return null;
  }

  return (int)$s['passed'] === 1;
}

$isTrainingSession = (($s['session_type'] ?? 'EXAM') === 'TRAINING');
$displayStatus = result_display_status($s);
$resultPassed = result_session_passed($s);
$canShowReview = $isTrainingSession && in_array($displayStatus, ['TERMINATED', 'EXPIRED'], true);
$reviewPosition = max(0, (int)($_GET['review_p'] ?? 0));
$reviewItems = [];
$selectedReviewItem = null;
$selectedReviewOptions = [];
$isAbandonedSession = ($displayStatus === 'ABANDONED');
$isTerminatedExam = (
  (string)($s['session_type'] ?? '') === 'EXAM' &&
  in_array($displayStatus, ['TERMINATED', 'EXPIRED'], true) &&
  !$isAbandonedSession
);
$isPassedTerminatedExam = $isTerminatedExam && ($resultPassed === true);
$validUntil = '';
$validUntilDays = null;
if ($isPassedTerminatedExam) {
  $baseDateRaw = (string)($s['submitted_at'] ?: $s['started_at']);
  if ($baseDateRaw !== '') {
    try {
      $dt = new DateTimeImmutable($baseDateRaw);
      $validityDays = (int)($s['package_cert_validity_days'] ?? 365);
      if ($validityDays < 1) {
        $validityDays = 1;
      } elseif ($validityDays > 3650) {
        $validityDays = 3650;
      }
      $expiresAt = $dt->modify('+' . $validityDays . ' days');
      $validUntil = $expiresAt->format('d/m/Y');
      $daysRemaining = (int)(new DateTimeImmutable('today'))->diff($expiresAt)->format('%r%a');
      if ($daysRemaining >= 0) {
        $validUntilDays = $daysRemaining;
      }
    } catch (Throwable $e) {
      $validUntil = '';
      $validUntilDays = null;
    }
  }
}
$packageCode = strtolower(trim((string)($s['package_name'] ?? '')));
$badgeByPackage = [
  'green' => 'user-badge-green.png',
  'blue' => 'user-badge-blue.png',
  'red' => 'user-badge-red.png',
  'black' => 'user-badge-black.png',
  'silver' => 'user-badge-silver.png',
  'gold' => 'user-badge-gold.png',
  'vermeil' => 'user-badge-gold.png',
];
$passedBadge = trim((string)($s['package_badge_image'] ?? ''));
if ($passedBadge === '') {
  $passedBadge = $badgeByPackage[$packageCode] ?? 'user-badge-blue.png';
}
$passedBadge = basename($passedBadge);
$badgeVersion = (string)time();
$heroImagePath = ($resultPassed === true)
  ? '/assets/badges/' . $passedBadge . '?v=' . urlencode($badgeVersion)
  : '/assets/badges/failed.png?v=' . urlencode($badgeVersion);
$heroScoreColor = ($resultPassed === true)
  ? package_color_hex((string)($s['package_name'] ?? ''), (string)($s['package_color_hex'] ?? ''))
  : '#C7C5B1';
$heroProfile = trim((string)($s['package_profile'] ?? ''));
if ($heroProfile === '') {
  $heroProfile = localize_text((string)($s['package_name'] ?? ''), $lang);
}

$hasQuestionTextSnapshot = table_column_exists($pdo, 'session_questions', 'question_text_snapshot');
$hasCorrectLabelsSnapshot = table_column_exists($pdo, 'session_questions', 'correct_option_labels_snapshot');
$hasAnswerStatusSnapshot = table_column_exists($pdo, 'session_questions', 'answer_status_snapshot');
$hasQuestionUpdatedSnapshot = table_column_exists($pdo, 'session_questions', 'question_updated_at_snapshot');
$reviewTextExpr = $hasQuestionTextSnapshot
  ? "COALESCE(sq.question_text_snapshot, q.text) AS text"
  : "q.text AS text";
$reviewCorrectLabelsExpr = $hasCorrectLabelsSnapshot
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
$reviewAnswerStatusExpr = $hasAnswerStatusSnapshot
  ? "sq.answer_status_snapshot"
  : "NULL AS answer_status_snapshot";
$reviewQuestionUpdatedExpr = $hasQuestionUpdatedSnapshot
  ? "sq.question_updated_at_snapshot"
  : "NULL AS question_updated_at_snapshot";

if ($canShowReview) {
  $reviewStmt = $pdo->prepare("
    SELECT
      sq.id AS session_question_id,
      sq.position,
      q.id AS question_id,
      $reviewTextExpr,
      $reviewCorrectLabelsExpr,
      " . session_question_picked_labels_expr($pdo, 'sq') . " AS picked_labels,
      $reviewAnswerStatusExpr,
      $reviewQuestionUpdatedExpr,
      q.updated_at AS current_question_updated_at,
      CASE WHEN q.id IS NULL THEN 1 ELSE 0 END AS is_question_deleted
    FROM session_questions sq
    LEFT JOIN questions q ON q.id = sq.question_id
    WHERE sq.session_id=?
      AND " . session_question_picked_labels_expr($pdo, 'sq') . " IS NOT NULL
    ORDER BY sq.position ASC
  ");
  $reviewStmt->execute([$sid]);
  $reviewItems = $reviewStmt->fetchAll() ?: [];
  foreach ($reviewItems as &$reviewItem) {
    $reviewQuestionId = (int)($reviewItem['question_id'] ?? 0);
    if ($reviewQuestionId > 0 && (int)($reviewItem['is_question_deleted'] ?? 0) !== 1) {
      $reviewItem['text'] = translated_question_field($pdo, $reviewQuestionId, $lang, 'question_text', (string)($reviewItem['text'] ?? ''));
    }
  }
  unset($reviewItem);

  if ($reviewPosition > 0) {
    foreach ($reviewItems as $reviewItem) {
      if ((int)($reviewItem['position'] ?? 0) === $reviewPosition) {
        $selectedReviewItem = $reviewItem;
        break;
      }
    }
  }

  if ($selectedReviewItem) {
    $selectedQuestionId = (int)($selectedReviewItem['question_id'] ?? 0);
    $snapshotUpdatedAt = trim((string)($selectedReviewItem['question_updated_at_snapshot'] ?? ''));
    $currentUpdatedAt = trim((string)($selectedReviewItem['current_question_updated_at'] ?? ''));
    $selectedQuestionModified = (
      (int)($selectedReviewItem['is_question_deleted'] ?? 0) !== 1
      && $snapshotUpdatedAt !== ''
      && $currentUpdatedAt !== ''
      && strtotime($currentUpdatedAt) > strtotime($snapshotUpdatedAt)
    );
    if ($selectedQuestionId > 0 && !$selectedQuestionModified && (int)($selectedReviewItem['is_question_deleted'] ?? 0) !== 1) {
      $selectedOptionsStmt = $pdo->prepare("
        SELECT
          qo.id,
          qo.label,
          qo.option_text,
          qo.is_correct,
          EXISTS(
            SELECT 1
            FROM answer_options ao
            WHERE ao.session_id = ?
              AND ao.question_id = ?
              AND ao.option_id = qo.id
          ) AS is_picked
        FROM question_options qo
        WHERE qo.question_id = ?
        ORDER BY qo.label ASC
      ");
      $selectedOptionsStmt->execute([$sid, $selectedQuestionId, $selectedQuestionId]);
      $selectedReviewOptions = $selectedOptionsStmt->fetchAll() ?: [];
      foreach ($selectedReviewOptions as &$selectedOption) {
        $selectedOption['option_text'] = translated_option_text($pdo, (int)($selectedOption['id'] ?? 0), $lang, (string)($selectedOption['option_text'] ?? ''));
      }
      unset($selectedOption);
    }
  }
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('result.title', [], $lang)) ?></title>
  <link rel="stylesheet" href="/assets/style.css?v=9">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>

<body>
  <div class="container">
    <div class="card">
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:8px;">
        <?php render_flag_lang_picker($lang, "'/result.php?sid=" . urlencode($sid) . "&lang={lang}'"); ?>
      </div>

      <div class="header">
        <div>
          <h2 class="h1"><?= h(t('result.title', [], $lang)) ?></h2>
          <p class="sub"><span style="<?= h(package_label_style((string)$s['package_name'], (string)($s['package_color_hex'] ?? ''))) ?>"><?= h(localize_text((string)$s['package_name'], $lang)) ?></span> - <?= h($s['email']) ?></p>
        </div>

        <?php if ($displayStatus === 'TERMINATED'): ?>
          <?php if ($resultPassed === true): ?>
            <span class="badge ok"><?= h(t('result.badge.passed', [], $lang)) ?></span>
          <?php else: ?>
            <span class="badge bad"><?= h(t('result.badge.failed', [], $lang)) ?></span>
          <?php endif; ?>
        <?php elseif ($displayStatus === 'EXPIRED'): ?>
          <span class="badge bad"><?= h(t('result.badge.expired', [], $lang)) ?></span>
        <?php elseif ($displayStatus === 'ABANDONED'): ?>
          <span class="badge bad"><?= h(t('result.badge.failed', [], $lang)) ?></span>
        <?php else: ?>
          <span class="badge"><?= h(t('result.badge.active', [], $lang)) ?></span>
        <?php endif; ?>
      </div>

      <div class="row" style="margin-bottom:12px;">
        <span class="badge"><?= h(t('result.status_label', [], $lang)) ?>: <?= h(result_status_label($displayStatus, $lang)) ?></span>
        <?php if (!empty($s['started_at'])): ?>
          <span class="badge"><?= h(t('result.started', [], $lang)) ?>: <?= h($s['started_at']) ?></span>
        <?php endif; ?>
        <?php if (!empty($s['submitted_at'])): ?>
          <span class="badge"><?= h(t('result.ended', [], $lang)) ?>: <?= h($s['submitted_at']) ?></span>
        <?php endif; ?>
      </div>

	      <?php if ($isAbandonedSession): ?>
          <div class="card" style="box-shadow:none; border-radius:12px; border:1px solid var(--border); margin-bottom:14px;">
            <p style="margin:0; font-size:16px;"><?= h(t('result.abandoned_message', [], $lang)) ?></p>
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a class="btn" href="/dashboard.php?lang=<?= h($lang) ?>"><?= h(t('result.candidate_space', [], $lang)) ?></a>
          </div>
        <?php elseif (in_array($displayStatus, ['TERMINATED', 'EXPIRED'], true)): ?>
          <?php if ($isTerminatedExam): ?>
            <div class="result-blue-hero">
              <img class="result-blue-hero-badge" src="<?= h($heroImagePath) ?>" alt="Badge Resultat">
              <?php if ($resultPassed === true): ?>
                <p class="result-blue-hero-title"><?= h(t('result.hero_passed_title', ['cert' => localize_text((string)$s['package_name'], $lang)], $lang)) ?></p>
                <p class="result-blue-hero-valid"><?= h(t('result.hero_profile_mention', ['profile' => $heroProfile], $lang)) ?></p>
              <?php else: ?>
                <p class="result-blue-hero-title"><?= h(t('result.hero_failed_title', [], $lang)) ?></p>
              <?php endif; ?>
              <p class="result-blue-hero-score" style="color:<?= h($heroScoreColor) ?>">
                <span class="result-blue-hero-score-label"><?= h(t('result.hero_score_prefix', [], $lang)) ?></span>
                <b><?= h(number_format((float)$s['score_percent'], 0, '.', '')) ?>%</b>
              </p>
              <?php if ($resultPassed === true && $validUntil !== ''): ?>
                <p class="result-blue-hero-valid">
                  <?= h(t('result.hero_valid_until', ['date' => $validUntil], $lang)) ?>
                  <?php if ($validUntilDays !== null): ?>
                    <?= ' (' . h(t('dash.certifications.days_left', ['days' => (string)$validUntilDays], $lang)) . ')' ?>
                  <?php endif; ?>
                </p>
              <?php endif; ?>
              <?php if ($resultPassed !== true): ?>
                <p class="result-blue-hero-valid"><?= h(t('result.hero_failed_message', ['score' => number_format((float)$s['score_percent'], 0, '.', '')], $lang)) ?></p>
              <?php endif; ?>
            </div>
          <?php else: ?>
	          <div class="card" style="box-shadow:none; border-radius:12px; border:1px solid var(--border);">
	            <p style="margin:0; font-size:16px;">
	              <b><?= h(t('result.score', [], $lang)) ?>:</b> <?= h($s['score_percent']) ?>%
	              <span class="small">(<?= h(t('result.threshold', ['value' => (int)$s['pass_threshold_percent']], $lang)) ?>)</span>
	            </p>
	          </div>
          <?php endif; ?>
          <?php if ($displayStatus === 'EXPIRED'): ?>
            <p class="error" style="margin-top:12px;"><?= h(t('result.expired_message', [], $lang)) ?></p>
          <?php endif; ?>

	        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
	          <a class="btn" href="/dashboard.php?lang=<?= h($lang) ?>"><?= h(t('result.candidate_space', [], $lang)) ?></a>
        </div>

      <?php else: ?>
        <p><?= h(t('result.in_progress', [], $lang)) ?></p>
        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <?php if ($isTrainingSession): ?>
            <a class="btn" href="/exam.php?sid=<?= h($sid) ?>&p=1&lang=<?= h($lang) ?>"><?= h(t('result.resume', [], $lang)) ?></a>
          <?php endif; ?>
          <a class="btn ghost" href="/dashboard.php?lang=<?= h($lang) ?>"><?= h(t('result.back', [], $lang)) ?></a>
        </div>
      <?php endif; ?>
	    </div>

      <?php if ($canShowReview): ?>
        <div class="card" style="margin-top:14px;">
          <h3 style="margin-top:0;"><?= h(t('result.review_title', [], $lang)) ?></h3>
          <?php if ($selectedReviewItem): ?>
            <?php
              $selectedCorrectCount = 0;
              foreach ($selectedReviewOptions as $selectedOption) {
                if ((int)($selectedOption['is_correct'] ?? 0) === 1) {
                  $selectedCorrectCount++;
                }
              }
              $selectedInputType = $selectedCorrectCount > 1 ? 'checkbox' : 'radio';
            ?>
            <div id="review-detail" class="card" style="box-shadow:none; border-radius:12px; border:1px solid var(--border); margin-bottom:14px; position:relative; padding-right:48px;">
              <a class="btn ghost icon-btn review-detail-close" href="/result.php?sid=<?= h(urlencode($sid)) ?>&lang=<?= h(urlencode($lang)) ?>" aria-label="<?= h(t('result.back', [], $lang)) ?>" title="<?= h(t('result.back', [], $lang)) ?>" style="position:absolute; top:12px; right:12px; background:rgba(251,146,60,0.12); border-color:rgba(251,146,60,0.35); color:#c2540a; flex-shrink:0;">
                <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                  <path d="M6.7 5.3a1 1 0 0 1 1.4 0L12 9.17l3.9-3.88a1 1 0 1 1 1.4 1.42L13.42 10.6l3.88 3.9a1 1 0 0 1-1.42 1.4L12 12.01l-3.9 3.88a1 1 0 0 1-1.4-1.42l3.87-3.88-3.88-3.9a1 1 0 0 1 0-1.4Z" fill="currentColor"/>
                </svg>
              </a>
              <div>
                <p style="margin:0; font-size:16px;"><b>#<?= (int)$selectedReviewItem['position'] ?></b> <?= h(localize_text((string)$selectedReviewItem['text'], $lang)) ?></p>
              </div>
              <div style="margin-top:12px;">
                <?php if (!$selectedReviewOptions): ?>
                  <p class="small" style="margin:0;">Le détail des options n'est plus disponible car la question a été modifiée ou supprimée depuis la session.</p>
                <?php else: ?>
                  <?php foreach ($selectedReviewOptions as $selectedOption): ?>
                    <?php
                      $selectedOptionClass = 'exam-option';
                      $selectedOptionIsCorrect = (int)($selectedOption['is_correct'] ?? 0) === 1;
                      $selectedOptionIsPicked = (int)($selectedOption['is_picked'] ?? 0) === 1;
                      if ($selectedOptionIsCorrect) {
                        $selectedOptionClass .= ' is-correct';
                      } elseif ($selectedOptionIsPicked) {
                        $selectedOptionClass .= ' is-wrong';
                      }
                    ?>
                    <label class="<?= h($selectedOptionClass) ?>" style="cursor:default;">
                      <input type="<?= h($selectedInputType) ?>" <?= $selectedOptionIsPicked ? 'checked' : '' ?> disabled>
                      <b style="margin-left:8px;"><?= h((string)$selectedOption['label']) ?>.</b>
                      <span style="margin-left:6px;"><?= h(localize_text((string)$selectedOption['option_text'], $lang)) ?></span>
                    </label>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <div class="table-wrap">
            <?php if (!$reviewItems): ?>
              <p class="empty-state"><?= h(t('dash.none', [], $lang)) ?></p>
            <?php else: ?>
              <table class="table result-review-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th><?= h(t('result.review_question', [], $lang)) ?></th>
                    <th><?= h(t('result.review_your_answer', [], $lang)) ?></th>
                    <th><?= h(t('result.review_expected', [], $lang)) ?></th>
                    <th><?= h(t('result.review_status', [], $lang)) ?></th>
                    <th><?= h(t('dash.col.action', [], $lang)) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($reviewItems as $it): ?>
                    <?php
                      $pickedLabels = trim((string)($it['picked_labels'] ?? ''));
                      $correctLabels = trim((string)($it['correct_labels'] ?? ''));
                      $status = strtoupper(trim((string)($it['answer_status_snapshot'] ?? '')));
                      if (!in_array($status, ['OK', 'KO', 'UNANSWERED'], true)) {
                        $status = build_session_question_answer_status($pickedLabels, $correctLabels);
                      }
                      $snapshotUpdatedAt = trim((string)($it['question_updated_at_snapshot'] ?? ''));
                      $currentUpdatedAt = trim((string)($it['current_question_updated_at'] ?? ''));
                      $isQuestionModified = (
                        (int)($it['is_question_deleted'] ?? 0) !== 1
                        && $snapshotUpdatedAt !== ''
                        && $currentUpdatedAt !== ''
                        && strtotime($currentUpdatedAt) > strtotime($snapshotUpdatedAt)
                      );
                      if ($status === 'UNANSWERED') {
                        $reviewKey = 'result.review_unanswered';
                        $reviewClass = 'pill warning';
                      } elseif ($status === 'OK') {
                        $reviewKey = 'result.review_correct';
                        $reviewClass = 'pill success';
                      } else {
                        $reviewKey = 'result.review_incorrect';
                        $reviewClass = 'pill danger';
                      }
                    ?>
                    <tr>
                      <td><?= (int)$it['position'] ?></td>
                      <td>
                        <?= h(localize_text((string)$it['text'], $lang)) ?>
                      </td>
                      <td><?= h((string)($it['picked_labels'] ?: '-')) ?></td>
                      <td><?= h((string)($it['correct_labels'] ?: '-')) ?></td>
                      <td>
                        <div class="result-review-status">
                          <span class="<?= h($reviewClass) ?>"><?= h(t($reviewKey, [], $lang)) ?></span>
                          <?php if ((int)($it['is_question_deleted'] ?? 0) === 1): ?>
                            <span class="result-review-flag" title="Question supprimée depuis la session" aria-label="Question supprimée depuis la session">!</span>
                          <?php elseif ($isQuestionModified): ?>
                            <span class="result-review-flag" title="Question modifiée depuis la session" aria-label="Question modifiée depuis la session">!</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td>
                        <div class="result-review-actions">
                          <a
                            class="btn ghost icon-btn"
                            href="/result.php?sid=<?= h(urlencode($sid)) ?>&lang=<?= h(urlencode($lang)) ?>&review_p=<?= (int)$it['position'] ?>#review-detail"
                            aria-label="<?= h(t('dash.view', [], $lang)) ?>"
                            title="<?= h(t('dash.view', [], $lang)) ?>"
                          >
                            <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                              <path d="M1.5 12s3.8-6.5 10.5-6.5S22.5 12 22.5 12s-3.8 6.5-10.5 6.5S1.5 12 1.5 12Zm10.5 4a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0-2.2a1.8 1.8 0 1 1 0-3.6 1.8 1.8 0 0 1 0 3.6Z" fill="currentColor"/>
                            </svg>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
	  </div>
</body>
</html>

