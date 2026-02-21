<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

$pdo = db();
$lang = get_lang();

$sid = $_GET['sid'] ?? '';
if (!$sid) {
  http_response_code(400);
  echo h(t('exam.missing_sid', [], $lang));
  exit;
}

$stmt = $pdo->prepare("
  SELECT s.*, c.email, pk.name AS package_name, pk.pass_threshold_percent, pk.duration_limit_minutes
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
  mark_session_expired($pdo, $sid);

  $stmt = $pdo->prepare("
    SELECT s.*, c.email, pk.name AS package_name, pk.pass_threshold_percent, pk.duration_limit_minutes
    FROM sessions s
    JOIN contacts c ON c.id = s.contact_id
    JOIN packages pk ON pk.id = s.package_id
    WHERE s.id=?
  ");
$stmt->execute([$sid]);
$s = $stmt->fetch();
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
$canShowReview = $isTrainingSession && in_array((string)$s['status'], ['TERMINATED', 'EXPIRED'], true);
$reviewItems = [];

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
  <link rel="stylesheet" href="/assets/style.css">
</head>

<body>
  <div class="container">
    <div class="card">
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:8px;">
        <select id="result-lang" class="input lang-select"
                onchange="window.location.href='/result.php?sid=<?= h(urlencode($sid)) ?>&lang=' + encodeURIComponent(this.value);">
          <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>><?= h(t('lang.fr', [], $lang)) ?></option>
          <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>><?= h(t('lang.en', [], $lang)) ?></option>
          <option value="jp" <?= $lang === 'jp' ? 'selected' : '' ?>><?= h(t('lang.jp', [], $lang)) ?></option>
        </select>
      </div>

      <div class="header">
        <div>
          <h2 class="h1"><?= h(t('result.title', [], $lang)) ?></h2>
          <p class="sub"><span style="<?= h(package_label_style((string)$s['package_name'])) ?>"><?= h(localize_text((string)$s['package_name'], $lang)) ?></span> - <?= h($s['email']) ?></p>
        </div>

        <?php if ($s['status'] === 'TERMINATED'): ?>
          <?php if ((int)$s['passed'] === 1): ?>
            <span class="badge ok"><?= h(t('result.badge.passed', [], $lang)) ?></span>
          <?php else: ?>
            <span class="badge bad"><?= h(t('result.badge.failed', [], $lang)) ?></span>
          <?php endif; ?>
        <?php elseif ($s['status'] === 'EXPIRED'): ?>
          <span class="badge bad"><?= h(t('result.badge.expired', [], $lang)) ?></span>
        <?php else: ?>
          <span class="badge"><?= h(t('result.badge.active', [], $lang)) ?></span>
        <?php endif; ?>
      </div>

      <div class="row" style="margin-bottom:12px;">
        <span class="badge"><?= h(t('result.status_label', [], $lang)) ?>: <?= h(result_status_label((string)$s['status'], $lang)) ?></span>
        <?php if (!empty($s['started_at'])): ?>
          <span class="badge"><?= h(t('result.started', [], $lang)) ?>: <?= h($s['started_at']) ?></span>
        <?php endif; ?>
        <?php if (!empty($s['submitted_at'])): ?>
          <span class="badge"><?= h(t('result.ended', [], $lang)) ?>: <?= h($s['submitted_at']) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($s['status'] === 'TERMINATED'): ?>
        <div class="card" style="box-shadow:none; border-radius:12px; border:1px solid var(--border);">
          <p style="margin:0; font-size:16px;">
            <b><?= h(t('result.score', [], $lang)) ?>:</b> <?= h($s['score_percent']) ?>%
            <span class="small">(<?= h(t('result.threshold', ['value' => (int)$s['pass_threshold_percent']], $lang)) ?>)</span>
          </p>
        </div>

        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <a class="btn" href="/dashboard.php?lang=<?= h($lang) ?>"><?= h(t('result.candidate_space', [], $lang)) ?></a>
        </div>

      <?php elseif ($s['status'] === 'EXPIRED'): ?>
        <p class="error"><?= h(t('result.expired_message', [], $lang)) ?></p>

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
