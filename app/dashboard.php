<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

$pdo = db();
$user = require_auth();
$lang = get_lang();
$uid = (int)$user['id'];

$errKey = trim((string)($_GET['err_key'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));

$statsStmt = $pdo->prepare("
  SELECT
    COUNT(*) as total_attempts,
    SUM(CASE WHEN status='TERMINATED' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status='TERMINATED' AND passed=1 THEN 1 ELSE 0 END) as passed_count
  FROM sessions
  WHERE user_id=?
");
$statsStmt->execute([$uid]);
$stats = $statsStmt->fetch() ?: [
  'total_attempts' => 0,
  'completed' => 0,
  'passed_count' => 0,
];

$pkgStmt = $pdo->query("SELECT id,name,duration_limit_minutes FROM packages ORDER BY id DESC");
$packages = $pkgStmt->fetchAll();

$packageOrder = [
  'GREEN' => 1,
  'BLUE' => 2,
  'RED' => 3,
  'BLACK' => 4,
  'SILVER' => 5,
  'VERMEIL' => 6,
];
usort($packages, static function (array $a, array $b) use ($packageOrder): int {
  $an = strtoupper(trim((string)($a['name'] ?? '')));
  $bn = strtoupper(trim((string)($b['name'] ?? '')));
  $ai = $packageOrder[$an] ?? 999;
  $bi = $packageOrder[$bn] ?? 999;
  if ($ai === $bi) {
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  }
  return $ai <=> $bi;
});

$lastStmt = $pdo->prepare("
  SELECT s.id, s.status, s.session_type, s.started_at, s.submitted_at, s.score_percent, s.passed, pk.name as package_name
  FROM sessions s
  JOIN packages pk ON pk.id = s.package_id
  WHERE s.user_id=?
  ORDER BY s.started_at DESC
  LIMIT 10
");
$lastStmt->execute([$uid]);
$last = $lastStmt->fetchAll();

$activeStmt = $pdo->prepare("
  SELECT id, package_id, session_type, started_at
  FROM sessions
  WHERE user_id=? AND status='ACTIVE'
  ORDER BY started_at DESC
");
$activeStmt->execute([$uid]);
$activeByPackage = [];
$resumePosBySession = [];

$resumePosStmt = $pdo->prepare("
  SELECT MIN(sq.position) AS resume_pos
  FROM session_questions sq
  WHERE sq.session_id=?
    AND NOT EXISTS (
      SELECT 1
      FROM answer_options ao
      WHERE ao.session_id = sq.session_id
        AND ao.question_id = sq.question_id
    )
");
$totalPosStmt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM session_questions
  WHERE session_id=?
");

foreach ($activeStmt->fetchAll() as $row) {
  $sid = (string)$row['id'];
  $pkgId = (int)$row['package_id'];
  $stype = strtoupper(trim((string)($row['session_type'] ?? 'EXAM')));
  if (!in_array($stype, ['EXAM', 'TRAINING'], true)) {
    $stype = 'EXAM';
  }

  $resumePosStmt->execute([$sid]);
  $resumePos = (int)($resumePosStmt->fetch()['resume_pos'] ?? 0);
  if ($resumePos < 1) {
    $totalPosStmt->execute([$sid]);
    $resumePos = max(1, (int)($totalPosStmt->fetch()['c'] ?? 1));
  }
  $resumePosBySession[$sid] = $resumePos;

  if (!isset($activeByPackage[$pkgId])) {
    $activeByPackage[$pkgId] = [];
  }
  if (!isset($activeByPackage[$pkgId][$stype])) {
    $activeByPackage[$pkgId][$stype] = [
      'id' => $sid,
      'resume_p' => $resumePos,
      'started_at' => (string)$row['started_at'],
    ];
  }
}

function dash_status_label(string $status, string $lang): string {
  return match ($status) {
    'TERMINATED' => t('dash.status.terminated', [], $lang),
    'ACTIVE' => t('dash.status.active', [], $lang),
    'EXPIRED' => t('dash.status.expired', [], $lang),
    default => $status,
  };
}

function dash_status_badge_class(string $status): string {
  return match ($status) {
    'TERMINATED' => 'pill success',
    'ACTIVE' => 'pill info',
    'EXPIRED' => 'pill warning',
    default => 'pill',
  };
}

function dash_score_fmt($v, string $lang): string {
  if ($v === null || $v === '') {
    return t('dash.na', [], $lang);
  }
  return number_format((float)$v, 2, '.', '') . '%';
}

function dash_result_label(array $s, string $lang): string {
  if ($s['status'] !== 'TERMINATED' || $s['passed'] === null) {
    return t('dash.na', [], $lang);
  }
  return ((int)$s['passed'] === 1) ? t('dash.result.passed', [], $lang) : t('dash.result.failed', [], $lang);
}

function dash_result_badge_class(array $s): string {
  if ($s['status'] !== 'TERMINATED' || $s['passed'] === null) {
    return 'pill';
  }
  return ((int)$s['passed'] === 1) ? 'pill success' : 'pill danger';
}

function dash_session_type_label(string $type, string $lang): string {
  return match ($type) {
    'EXAM' => t('dash.session_type.exam', [], $lang),
    'TRAINING' => t('dash.session_type.training', [], $lang),
    default => $type,
  };
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <meta charset="utf-8">
  <title><?= h(t('dash.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
</head>
<body>
<div class="container dashboard-container">
  <div class="dashboard-head">
    <div class="dashboard-head-copy">
      <h2 class="h1"><?= h(t('dash.hello', ['name' => ($user['name'] ?: $user['email'])], $lang)) ?></h2>
      <p class="sub"><?= h(t('dash.subtitle', [], $lang)) ?></p>
    </div>

      <div class="dashboard-head-actions">
      <div class="lang-switch">
        <select id="dash-lang" class="input lang-select"
                onchange="window.location.href='/dashboard.php?lang=' + encodeURIComponent(this.value);">
          <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>><?= h(t('lang.fr', [], $lang)) ?></option>
          <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>><?= h(t('lang.en', [], $lang)) ?></option>
          <option value="jp" <?= $lang === 'jp' ? 'selected' : '' ?>><?= h(t('lang.jp', [], $lang)) ?></option>
        </select>
      </div>
      <div class="dashboard-main-actions">
        <?php if (($user['role'] ?? 'USER') === 'ADMIN'): ?>
          <a class="btn ghost" href="/admin/"><?= h(t('dash.admin', [], $lang)) ?></a>
        <?php endif; ?>
        <a class="btn ghost dashboard-logout-btn" href="/logout.php"><?= h(t('dash.logout', [], $lang)) ?></a>
      </div>
    </div>
  </div>

  <?php if ($errKey || $err): ?>
    <div class="card dashboard-alert">
      <p class="error dashboard-alert-text">
        <?= h($errKey !== '' ? t($errKey, [], $lang) : $err) ?>
      </p>
    </div>
  <?php endif; ?>

  <div class="dashboard-stats">
    <div class="card stat-card">
      <div class="stat-title"><?= h(t('dash.attempts', [], $lang)) ?></div>
      <div class="stat-value"><?= (int)$stats['total_attempts'] ?></div>
    </div>

    <div class="card stat-card">
      <div class="stat-title"><?= h(t('dash.completed', [], $lang)) ?></div>
      <div class="stat-value"><?= (int)$stats['completed'] ?></div>
    </div>

    <div class="card stat-card">
      <div class="stat-title"><?= h(t('dash.passed', [], $lang)) ?></div>
      <div class="stat-value"><?= (int)$stats['passed_count'] ?></div>
    </div>
  </div>

  <div class="card dashboard-card">
    <h3 class="dashboard-section-title"><?= h(t('dash.start_cert', [], $lang)) ?></h3>

	    <form method="post" action="/start.php" class="dashboard-start-form">
	      <input type="hidden" name="lang" value="<?= h($lang) ?>">
	      <div class="dashboard-start-grid">
		        <div class="dashboard-field dashboard-cert-field">
		          <label class="label"><?= h(t('dash.cert', [], $lang)) ?></label>
		          <?php if ($packages): ?>
		            <?php $selectedPkg = $packages[0]; ?>
		            <input id="dash-package-input" type="hidden" name="package_id" value="<?= (int)$selectedPkg['id'] ?>">
		          <?php endif; ?>
		          <?php if ($packages): ?>
		            <?php
		              $firstPkg = $packages[0];
		            ?>
		            <div class="dash-cert-grid" id="dash-cert-grid">
		              <?php foreach ($packages as $pk): ?>
		                <?php
		                  $pkName = localize_text((string)$pk['name'], $lang);
		                  $pkTone = package_color_hex((string)$pk['name']);
	                  $pkDuration = (int)$pk['duration_limit_minutes'];
	                  $isFirst = ((int)$pk['id'] === (int)$firstPkg['id']);
	                ?>
		                <button
		                  type="button"
		                  class="dash-cert-tile<?= $isFirst ? ' is-active' : '' ?>"
		                  data-package-value="<?= (int)$pk['id'] ?>"
		                  data-package-name="<?= h($pkName) ?>"
		                  data-active-sid-exam="<?= h((string)($activeByPackage[(int)$pk['id']]['EXAM']['id'] ?? '')) ?>"
		                  data-active-p-exam="<?= (int)($activeByPackage[(int)$pk['id']]['EXAM']['resume_p'] ?? 1) ?>"
		                  data-active-sid-training="<?= h((string)($activeByPackage[(int)$pk['id']]['TRAINING']['id'] ?? '')) ?>"
		                  data-active-p-training="<?= (int)($activeByPackage[(int)$pk['id']]['TRAINING']['resume_p'] ?? 1) ?>"
		                  data-package-duration="<?= (int)$pkDuration ?>"
		                  data-package-tone="<?= h($pkTone) ?>"
		                  style="--cert-tone: <?= h($pkTone) ?>;"
		                >
	                  <span class="dash-cert-tile-name"><?= h($pkName) ?></span>
	                  <span class="dash-cert-tile-time"><?= (int)$pkDuration ?> min</span>
	                </button>
		              <?php endforeach; ?>
		            </div>
		          <?php else: ?>
		            <div class="dash-cert-empty"><?= h(t('dash.no_active_packages', [], $lang)) ?></div>
		          <?php endif; ?>
		        </div>

        <div class="dashboard-field dashboard-session-mode">
          <label class="label"><?= h(t('dash.session_type', [], $lang)) ?></label>
          <div class="dashboard-mode-group" role="radiogroup" aria-label="<?= h(t('dash.session_type', [], $lang)) ?>">
            <label class="dashboard-mode-option">
              <input type="radio" name="session_type" value="EXAM" checked>
              <span class="dashboard-mode-content">
                <span class="dashboard-mode-title"><?= h(t('dash.session_type.exam', [], $lang)) ?></span>
                <span class="dashboard-mode-hint"><?= h(t('dash.session_type.exam_hint', [], $lang)) ?></span>
              </span>
            </label>
            <label class="dashboard-mode-option">
              <input type="radio" name="session_type" value="TRAINING">
              <span class="dashboard-mode-content">
                <span class="dashboard-mode-title"><?= h(t('dash.session_type.training', [], $lang)) ?></span>
                <span class="dashboard-mode-hint"><?= h(t('dash.session_type.training_hint', [], $lang)) ?></span>
              </span>
            </label>
          </div>
        </div>
      </div>

		      <div class="dashboard-start-foot">
		        <div class="dashboard-start-action">
		          <button id="dash-start-btn" class="btn" type="submit" <?= $packages ? '' : 'disabled' ?>><?= h(t('dash.start', [], $lang)) ?></button>
              <a id="dash-continue-btn"
                 class="btn ghost"
                 href="#"
                 style="display:none;"
              ><?= h(t('dash.continue_current', [], $lang)) ?></a>
		        </div>
		      </div>
		    </form>
  </div>

  <div class="card dashboard-card">
    <h3 class="dashboard-section-title"><?= h(t('dash.last_sessions', [], $lang)) ?></h3>

    <?php if (!$last): ?>
      <p class="small"><?= h(t('dash.none', [], $lang)) ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table dashboard-table">
        <thead>
	          <tr>
	            <th><?= h(t('dash.col.cert', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.type', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.started', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.status', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.score', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.result', [], $lang)) ?></th>
	            <th><?= h(t('dash.col.action', [], $lang)) ?></th>
	          </tr>
	        </thead>
        <tbody>
        <?php foreach ($last as $s): ?>
          <tr>
	            <td><span style="<?= h(package_label_style((string)$s['package_name'])) ?>"><?= h(localize_text((string)$s['package_name'], $lang)) ?></span></td>
            <td><?= h(dash_session_type_label((string)$s['session_type'], $lang)) ?></td>
            <td><?= date('d/m/Y H:i', strtotime($s['started_at'])) ?></td>
            <td>
              <span class="<?= h(dash_status_badge_class($s['status'])) ?>">
                <?= h(dash_status_label($s['status'], $lang)) ?>
              </span>
            </td>
            <td><?= h(dash_score_fmt($s['score_percent'], $lang)) ?></td>
            <td>
              <span class="<?= h(dash_result_badge_class($s)) ?>">
                <?= h(dash_result_label($s, $lang)) ?>
              </span>
            </td>
	            <td>
	              <?php if ($s['status'] === 'ACTIVE'): ?>
	                <a class="btn ghost" href="/exam.php?sid=<?= h($s['id']) ?>&p=<?= (int)($resumePosBySession[(string)$s['id']] ?? 1) ?>&lang=<?= h($lang) ?>"><?= h(t('dash.resume', [], $lang)) ?></a>
                  <a class="btn ghost icon-btn danger"
                     href="/session_delete.php?sid=<?= h($s['id']) ?>&lang=<?= h($lang) ?>"
                     aria-label="<?= h(t('dash.delete_active', [], $lang)) ?>"
                     title="<?= h(t('dash.delete_active', [], $lang)) ?>"
                     onclick="return confirm('<?= h(t('dash.delete_confirm', [], $lang)) ?>');">
                    <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                      <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                    </svg>
                  </a>
	              <?php else: ?>
	                <a class="btn ghost" href="/result.php?sid=<?= h($s['id']) ?>&lang=<?= h($lang) ?>"><?= h(t('dash.view', [], $lang)) ?></a>
	              <?php endif; ?>
	            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<script src="/assets/package-colors.js"></script>
<script>
  (function () {
    var form = document.querySelector('.dashboard-start-form');
    var input = document.getElementById('dash-package-input');
    var tiles = document.querySelectorAll('.dash-cert-tile');
    var startBtn = document.getElementById('dash-start-btn');
    var continueBtn = document.getElementById('dash-continue-btn');
    var startDefaultLabel = <?= json_encode(t('dash.start', [], $lang)) ?>;
    var startNewExamLabel = <?= json_encode(t('dash.start_new_exam', [], $lang)) ?>;
    var startNewTrainingLabel = <?= json_encode(t('dash.start_new_training', [], $lang)) ?>;
    var continueExamLabel = <?= json_encode(t('dash.continue_current_exam', [], $lang)) ?>;
    var continueTrainingLabel = <?= json_encode(t('dash.continue_current_training', [], $lang)) ?>;
    var overwriteConfirmExamText = <?= json_encode(t('dash.confirm_overwrite_session_exam', [], $lang)) ?>;
    var overwriteConfirmTrainingText = <?= json_encode(t('dash.confirm_overwrite_session_training', [], $lang)) ?>;
    var selectedActiveSid = '';
    var selectedMode = 'EXAM';
    if (!input || !tiles.length) return;

    function getMode() {
      var checked = document.querySelector('input[name="session_type"]:checked');
      var mode = checked ? checked.value : 'EXAM';
      return mode === 'TRAINING' ? 'TRAINING' : 'EXAM';
    }

    function setActive(value) {
      var activeSid = '';
      var activeP = '1';
      selectedMode = getMode();
      tiles.forEach(function (tile) {
        var active = (tile.getAttribute('data-package-value') === value);
        tile.classList.toggle('is-active', active);
        if (active) {
          if (selectedMode === 'TRAINING') {
            activeSid = tile.getAttribute('data-active-sid-training') || '';
            activeP = tile.getAttribute('data-active-p-training') || '1';
          } else {
            activeSid = tile.getAttribute('data-active-sid-exam') || '';
            activeP = tile.getAttribute('data-active-p-exam') || '1';
          }
        }
      });

      if (startBtn) {
        if (!activeSid) {
          startBtn.textContent = startDefaultLabel;
        } else {
          startBtn.textContent = (selectedMode === 'TRAINING') ? startNewTrainingLabel : startNewExamLabel;
        }
      }
      if (continueBtn) {
        if (activeSid) {
          continueBtn.style.display = '';
          continueBtn.textContent = (selectedMode === 'TRAINING') ? continueTrainingLabel : continueExamLabel;
          continueBtn.setAttribute('href', '/exam.php?sid=' + encodeURIComponent(activeSid) + '&p=' + encodeURIComponent(activeP) + '&lang=' + encodeURIComponent(<?= json_encode($lang) ?>));
        } else {
          continueBtn.style.display = 'none';
          continueBtn.setAttribute('href', '#');
        }
      }
      selectedActiveSid = activeSid;
    }

    tiles.forEach(function (tile) {
      tile.addEventListener('click', function () {
        var value = tile.getAttribute('data-package-value');
        if (!value) return;
        input.value = value;
        setActive(value);
      });
    });

    document.querySelectorAll('input[name="session_type"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        setActive(input.value);
      });
    });

    setActive(input.value);

    if (form) {
      form.addEventListener('submit', function (e) {
        if (!selectedActiveSid) return;
        var confirmText = (selectedMode === 'TRAINING') ? overwriteConfirmTrainingText : overwriteConfirmExamText;
        if (!window.confirm(confirmText)) {
          e.preventDefault();
        }
      });
    }
  })();
</script>
</body>
</html>
