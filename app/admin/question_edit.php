<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_nav.php';

$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$prefPkg = (int)($_GET['package_id'] ?? 0);

if ($id <= 0) {
  http_response_code(403);
  echo "Creation manuelle des questions desactivee. Utilisez l'import.";
  exit;
}

$packages = $pdo->query("SELECT id, name FROM packages ORDER BY id DESC")->fetchAll();

$question = [
  'id' => 0,
  'package_id' => $prefPkg ?: (int)($packages[0]['id'] ?? 1),
  'text' => '',
  'need' => 'PONE',
  'level' => 1,
  'question_type' => 'MULTI',
  'allow_skip' => 1,
];

$optionsByLabel = [];

$st = $pdo->prepare("SELECT id, package_id, text, need, level, question_type, allow_skip FROM questions WHERE id=?");
$st->execute([$id]);
$q = $st->fetch();
if (!$q) {
  http_response_code(404);
  echo "Question not found";
  exit;
}

$question = [
  'id' => (int)$q['id'],
  'package_id' => (int)$q['package_id'],
  'text' => (string)$q['text'],
  'need' => (string)($q['need'] ?? 'PONE'),
  'level' => (int)($q['level'] ?? 1),
  'question_type' => (string)(($q['question_type'] ?? 'MULTI') === 'SINGLE' ? 'MULTI' : ($q['question_type'] ?? 'MULTI')),
  'allow_skip' => (int)($q['allow_skip'] ?? 1),
];

$os = $pdo->prepare("
  SELECT label, option_text, is_correct, score_value
  FROM question_options
  WHERE question_id=?
  ORDER BY label ASC
");
$os->execute([$id]);
foreach ($os->fetchAll() as $o) {
  $optionsByLabel[(string)$o['label']] = $o;
}

$labels = ['A', 'B', 'C', 'D', 'E', 'F'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $question['package_id'] = (int)($_POST['package_id'] ?? $question['package_id']);
  $question['text'] = trim((string)($_POST['text'] ?? ''));
  $question['need'] = strtoupper(trim((string)($_POST['need'] ?? ($question['need'] ?? 'PONE'))));
  $question['level'] = (int)($_POST['level'] ?? ($question['level'] ?? 1));
  $question['question_type'] = (string)($_POST['question_type'] ?? 'MULTI');
  $question['allow_skip'] = isset($_POST['allow_skip']) ? 1 : 0;

  if ($question['text'] === '') {
    $errors[] = "Enonce obligatoire.";
  }
  if (!in_array($question['question_type'], ['MULTI', 'TRUE_FALSE'], true)) {
    $errors[] = "Type invalide.";
  }
  if (!in_array($question['need'], ['PONE', 'PHM', 'PPM'], true)) {
    $errors[] = "Need invalide.";
  }
  if ($question['level'] < 1 || $question['level'] > 3) {
    $errors[] = "Level invalide (1..3).";
  }

  $optText = $_POST['opt'] ?? [];
  $correct = $_POST['correct'] ?? [];
  $score = $_POST['score'] ?? [];

  $rows = [];
  foreach ($labels as $label) {
    $text = trim((string)($optText[$label] ?? ''));
    if ($text === '') {
      continue;
    }

    $isCorrect = isset($correct[$label]) ? 1 : 0;
    $scoreValue = $isCorrect ? 1 : -1;

    if (mb_strtoupper($text, 'UTF-8') === 'NSP') {
      $scoreValue = 0;
      $isCorrect = 0;
    }

    if (isset($score[$label]) && $score[$label] !== '') {
      $manual = filter_var($score[$label], FILTER_VALIDATE_INT);
      if ($manual !== false) {
        $scoreValue = (int)$manual;
      }
    }

    $rows[] = [
      'label' => $label,
      'text' => $text,
      'is_correct' => $isCorrect,
      'score_value' => $scoreValue,
    ];
  }

  if (count($rows) < 2) {
    $errors[] = "Il faut au moins 2 options.";
  }
  if (count($rows) > 6) {
    $errors[] = "Max 6 options.";
  }

  $nbCorrect = array_sum(array_map(fn($r) => $r['is_correct'], $rows));

  if ($question['question_type'] === 'TRUE_FALSE') {
    if ($nbCorrect !== 1) {
      $errors[] = "TRUE_FALSE : exactement 1 bonne reponse.";
    }
  } else {
    if ($nbCorrect < 1) {
      $errors[] = "MULTI : au moins 1 bonne reponse.";
    }
  }

  if (!$errors && $question['question_type'] === 'TRUE_FALSE') {
    $allowed = ['VRAI', 'FAUX', 'NSP'];
    foreach ($rows as $r) {
      $option = mb_strtoupper(trim($r['text']), 'UTF-8');
      if (!in_array($option, $allowed, true)) {
        $errors[] = "TRUE_FALSE : options attendues = Vrai / Faux / NSP (trouve: {$r['text']}).";
        break;
      }
    }
  }

  if (!$errors) {
    $pdo->beginTransaction();
    try {
      if ($question['id'] > 0) {
        $up = $pdo->prepare("UPDATE questions SET package_id=?, text=?, need=?, level=?, question_type=?, allow_skip=? WHERE id=?");
        $up->execute([
          $question['package_id'],
          $question['text'],
          $question['need'],
          $question['level'],
          $question['question_type'],
          $question['allow_skip'],
          $question['id'],
        ]);
        $qid = $question['id'];
      } else {
        throw new RuntimeException("Creation manuelle des questions desactivee.");
      }

      $pdo->prepare("DELETE FROM question_options WHERE question_id=?")->execute([$qid]);

      $io = $pdo->prepare("
        INSERT INTO question_options(question_id,label,option_text,is_correct,score_value)
        VALUES(?,?,?,?,?)
      ");
      foreach ($rows as $r) {
        $io->execute([$qid, $r['label'], $r['text'], $r['is_correct'], $r['score_value']]);
      }

      $pdo->commit();
      header("Location: /admin/questions.php");
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = "Erreur DB: " . $e->getMessage();
    }
  }
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function package_label_style_local(string $packageName): string {
  $name = strtoupper(trim($packageName));
  $color = match ($name) {
    'GREEN' => '#16a34a',
    'BLUE' => '#2563eb',
    'RED' => '#dc2626',
    'BLACK' => '#111827',
    'SILVER' => '#64748b',
    'GOLD' => '#d4af37',
    default => '#334155',
  };
  return 'color:' . $color . ';font-weight:700;';
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title><?= "Modifier question #".(int)$question['id'] ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1"><?= "Admin &middot; Modifier question #".(int)$question['id'] ?></h2>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('questions'); ?>
      </div>
    </div>

    <hr class="separator">

    <?php if ($errors): ?>
      <div class="import-report question-errors">
        <div class="import-report-title">Erreurs</div>
        <div class="import-report-errors">
          <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" class="question-form">
      <div class="import-help">
        <div class="import-help-head">
          <span class="import-help-tag">Guide</span>
          <strong>Configuration de la question</strong>
        </div>
        <div class="import-help-grid">
          <div class="import-help-block">
            <div class="import-help-block-title">Utilise pour le tirage</div>
            <p class="import-help-text"><code>Need</code> + <code>Level</code></p>
          </div>
          <div class="import-help-block">
            <div class="import-help-block-title">Utilise pour organiser</div>
            <p class="import-help-text"><code>Package</code> (vue admin)</p>
          </div>
        </div>
      </div>

      <div class="question-fields">
        <div class="question-field question-field-full">
          <label class="label">Package</label>
	          <select name="package_id" required>
	            <?php foreach ($packages as $p): ?>
	              <option value="<?= (int)$p['id'] ?>" <?= $question['package_id'] === (int)$p['id'] ? 'selected' : '' ?> style="<?= h(package_label_style_local((string)$p['name'])) ?>">
	                <?= h($p['name']) ?>
	              </option>
	            <?php endforeach; ?>
	          </select>
        </div>

        <div class="question-field">
          <label class="label">Need</label>
          <select name="need" required>
            <?php foreach (['PONE', 'PHM', 'PPM'] as $n): ?>
              <option value="<?= h($n) ?>" <?= (($question['need'] ?? 'PONE') === $n) ? 'selected' : '' ?>>
                <?= h($n) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="question-field">
          <label class="label">Level</label>
          <select name="level" required>
            <?php for ($i = 1; $i <= 3; $i++): ?>
              <option value="<?= $i ?>" <?= ((int)($question['level'] ?? 1) === $i) ? 'selected' : '' ?>>
                <?= $i ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="question-field">
          <label class="label">Type</label>
          <select name="question_type">
            <?php
            $typeLabels = [
              'MULTI' => 'Choix multiple',
              'TRUE_FALSE' => 'Vrai / Faux',
            ];
            foreach ($typeLabels as $typeValue => $typeLabel):
            ?>
              <option value="<?= h($typeValue) ?>" <?= $question['question_type'] === $typeValue ? 'selected' : '' ?>><?= h($typeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="question-field question-toggle-wrap">
          <label class="question-toggle">
            <input type="checkbox" name="allow_skip" <?= ((int)$question['allow_skip'] === 1) ? 'checked' : '' ?>>
            Autoriser "ne pas repondre"
          </label>
        </div>
      </div>

      <div class="question-field">
        <label class="label">Enonce</label>
        <textarea name="text" rows="4" class="question-textarea" required><?= h($question['text']) ?></textarea>
      </div>

      <div class="question-options-head">
        <b>Options</b>
        <p class="small">Coche la/les bonnes. Laisse vide une option si tu n'en as pas besoin.</p>
      </div>

      <div class="question-options">
        <?php foreach ($labels as $label):
          $cur = $optionsByLabel[$label] ?? null;
          $text = $cur['option_text'] ?? '';
          $isCorrect = (int)($cur['is_correct'] ?? 0) === 1;
          $scoreValue = $cur['score_value'] ?? '';
        ?>
          <div class="question-option-row">
            <b class="question-option-label"><?= h($label) ?>.</b>
            <input class="input question-option-input" type="text" name="opt[<?= h($label) ?>]" value="<?= h($text) ?>" placeholder="Texte option <?= h($label) ?>">
            <label class="question-option-check">
              <input type="checkbox" name="correct[<?= h($label) ?>]" <?= $isCorrect ? 'checked' : '' ?>> Correct
            </label>
            <input class="input question-option-score" type="number" name="score[<?= h($label) ?>]" value="<?= h($scoreValue) ?>" placeholder="score">
          </div>
        <?php endforeach; ?>
      </div>

      <div class="question-actions">
        <button class="btn" type="submit">Enregistrer</button>
        <a class="btn ghost" href="/admin/questions.php">Annuler</a>
      </div>
    </form>
  </div>
</div>
<script src="/assets/package-colors.js"></script>
</body>
</html>
