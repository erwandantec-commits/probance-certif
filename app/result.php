<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

$pdo = db();
$lang = get_lang();

function result_package_column_exists(PDO $pdo, string $column): bool {
  static $cache = [];
  if (isset($cache[$column])) {
    return $cache[$column];
  }
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'packages'
      AND COLUMN_NAME = ?
  ");
  $st->execute([$column]);
  $cache[$column] = ((int)$st->fetchColumn() > 0);
  return $cache[$column];
}

$resultBadgeImageSelect = result_package_column_exists($pdo, 'badge_image_filename')
  ? ", pk.badge_image_filename AS package_badge_image"
  : ", NULL AS package_badge_image";
$resultProfileSelect = result_package_column_exists($pdo, 'profile')
  ? ", pk.profile AS package_profile"
  : ", NULL AS package_profile";
$resultCertValidityDaysSelect = result_package_column_exists($pdo, 'cert_validity_days')
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
  $threshold = (int)$s['pass_threshold_percent'];
  $passed = ($score >= $threshold) ? 1 : 0;
  mark_session_terminated($pdo, $sid, round($score, 2), $passed, 'TIMEOUT');

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

function result_display_status(array $s): string {
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
    default => $status,
  };
}

$isTrainingSession = (($s['session_type'] ?? 'EXAM') === 'TRAINING');
$displayStatus = result_display_status($s);
$canShowReview = $isTrainingSession && in_array($displayStatus, ['TERMINATED', 'EXPIRED'], true);
$reviewItems = [];
$isTerminatedExam = (
  (string)($s['session_type'] ?? '') === 'EXAM' &&
  in_array($displayStatus, ['TERMINATED', 'EXPIRED'], true)
);
$isPassedTerminatedExam = $isTerminatedExam && (int)($s['passed'] ?? 0) === 1;
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
$heroImagePath = ((int)($s['passed'] ?? 0) === 1)
  ? '/assets/badges/' . $passedBadge . '?v=' . urlencode($badgeVersion)
  : '/assets/badges/failed.png?v=' . urlencode($badgeVersion);
$heroScoreColor = ((int)($s['passed'] ?? 0) === 1)
  ? package_color_hex((string)($s['package_name'] ?? ''), (string)($s['package_color_hex'] ?? ''))
  : '#C7C5B1';
$heroProfile = trim((string)($s['package_profile'] ?? ''));
if ($heroProfile === '') {
  $heroProfile = localize_text((string)($s['package_name'] ?? ''), $lang);
}

if ($canShowReview) {
  $reviewStmt = $pdo->prepare("
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
      ) AS picked_labels,
      (
        SELECT GROUP_CONCAT(qo.id ORDER BY qo.id SEPARATOR ',')
        FROM question_options qo
        WHERE qo.question_id = q.id AND qo.is_correct = 1
      ) AS correct_ids,
      (
        SELECT GROUP_CONCAT(ao.option_id ORDER BY ao.option_id SEPARATOR ',')
        FROM answer_options ao
        WHERE ao.session_id = sq.session_id AND ao.question_id = q.id
      ) AS picked_ids
    FROM session_questions sq
    JOIN questions q ON q.id = sq.question_id
    WHERE sq.session_id=?
      AND EXISTS (
        SELECT 1
        FROM answer_options ao
        WHERE ao.session_id = sq.session_id
          AND ao.question_id = q.id
      )
    ORDER BY sq.position ASC
  ");
  $reviewStmt->execute([$sid]);
  $reviewItems = $reviewStmt->fetchAll() ?: [];
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <meta charset="utf-8">
  <title><?= h(t('result.title', [], $lang)) ?></title>
  <link rel="stylesheet" href="/assets/style.css?v=9">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>

<body>
  <div class="container">
    <div class="card">
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:8px;">
        <select id="result-lang" class="input lang-select"
                onchange="window.location.href='/result.php?sid=<?= h(urlencode($sid)) ?>&lang=' + encodeURIComponent(this.value);">
          <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>><?= h(t('lang.fr', [], $lang)) ?></option>
          <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>><?= h(t('lang.en', [], $lang)) ?></option>
          <option value="es" <?= $lang === 'es' ? 'selected' : '' ?>><?= h(t('lang.es', [], $lang)) ?></option>
          <option value="jp" <?= $lang === 'jp' ? 'selected' : '' ?>><?= h(t('lang.jp', [], $lang)) ?></option>
        </select>
      </div>

      <div class="header">
        <div>
          <h2 class="h1"><?= h(t('result.title', [], $lang)) ?></h2>
          <p class="sub"><span style="<?= h(package_label_style((string)$s['package_name'], (string)($s['package_color_hex'] ?? ''))) ?>"><?= h(localize_text((string)$s['package_name'], $lang)) ?></span> - <?= h($s['email']) ?></p>
        </div>

        <?php if ($displayStatus === 'TERMINATED'): ?>
          <?php if ((int)$s['passed'] === 1): ?>
            <span class="badge ok"><?= h(t('result.badge.passed', [], $lang)) ?></span>
          <?php else: ?>
            <span class="badge bad"><?= h(t('result.badge.failed', [], $lang)) ?></span>
          <?php endif; ?>
        <?php elseif ($displayStatus === 'EXPIRED'): ?>
          <span class="badge bad"><?= h(t('result.badge.expired', [], $lang)) ?></span>
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

	      <?php if (in_array($displayStatus, ['TERMINATED', 'EXPIRED'], true)): ?>
          <?php if ($isTerminatedExam): ?>
            <div class="result-blue-hero">
              <img class="result-blue-hero-badge" src="<?= h($heroImagePath) ?>" alt="Badge Resultat">
              <?php if ((int)$s['passed'] === 1): ?>
                <p class="result-blue-hero-title"><?= h(t('result.hero_passed_title', ['cert' => localize_text((string)$s['package_name'], $lang)], $lang)) ?></p>
                <p class="result-blue-hero-valid"><?= h(t('result.hero_profile_mention', ['profile' => $heroProfile], $lang)) ?></p>
              <?php else: ?>
                <p class="result-blue-hero-title"><?= h(t('result.hero_failed_title', [], $lang)) ?></p>
              <?php endif; ?>
              <p class="result-blue-hero-score" style="color:<?= h($heroScoreColor) ?>">
                <span class="result-blue-hero-score-label"><?= h(t('result.hero_score_prefix', [], $lang)) ?></span>
                <b><?= h(number_format((float)$s['score_percent'], 0, '.', '')) ?>%</b>
              </p>
              <?php if ((int)$s['passed'] === 1 && $validUntil !== ''): ?>
                <p class="result-blue-hero-valid">
                  <?= h(t('result.hero_valid_until', ['date' => $validUntil], $lang)) ?>
                  <?php if ($validUntilDays !== null): ?>
                    <?= ' (' . h(t('dash.certifications.days_left', ['days' => (string)$validUntilDays], $lang)) . ')' ?>
                  <?php endif; ?>
                </p>
              <?php endif; ?>
              <?php if ((int)$s['passed'] !== 1): ?>
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
          <a class="btn" href="/exam.php?sid=<?= h($sid) ?>&p=1&lang=<?= h($lang) ?>"><?= h(t('result.resume', [], $lang)) ?></a>
          <a class="btn ghost" href="/dashboard.php?lang=<?= h($lang) ?>"><?= h(t('result.back', [], $lang)) ?></a>
        </div>
      <?php endif; ?>
	    </div>

      <?php if ($canShowReview): ?>
        <div class="card" style="margin-top:14px;">
          <h3 style="margin-top:0;"><?= h(t('result.review_title', [], $lang)) ?></h3>
          <div class="table-wrap">
            <?php if (!$reviewItems): ?>
              <p class="empty-state"><?= h(t('dash.none', [], $lang)) ?></p>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th><?= h(t('result.review_question', [], $lang)) ?></th>
                    <th><?= h(t('result.review_your_answer', [], $lang)) ?></th>
                    <th><?= h(t('result.review_expected', [], $lang)) ?></th>
                    <th><?= h(t('result.review_status', [], $lang)) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($reviewItems as $it): ?>
                    <?php
                      $pickedIds = (string)($it['picked_ids'] ?? '');
                      $correctIds = (string)($it['correct_ids'] ?? '');
                      if ($pickedIds === '') {
                        $reviewKey = 'result.review_unanswered';
                        $reviewClass = 'pill warning';
                      } elseif ($pickedIds === $correctIds) {
                        $reviewKey = 'result.review_correct';
                        $reviewClass = 'pill success';
                      } else {
                        $reviewKey = 'result.review_incorrect';
                        $reviewClass = 'pill danger';
                      }
                    ?>
                    <tr>
                      <td><?= (int)$it['position'] ?></td>
                      <td><?= h(localize_text((string)$it['text'], $lang)) ?></td>
                      <td><?= h((string)($it['picked_labels'] ?: '-')) ?></td>
                      <td><?= h((string)($it['correct_labels'] ?: '-')) ?></td>
                      <td><span class="<?= h($reviewClass) ?>"><?= h(t($reviewKey, [], $lang)) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
	      <p class="small" style="margin-top:14px;"><?= h(t('result.answers_admin_only', [], $lang)) ?></p>
      <?php endif; ?>
	  </div>
</body>
</html>

