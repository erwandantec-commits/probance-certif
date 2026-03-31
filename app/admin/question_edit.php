<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
ensure_question_translation_schema($pdo);

function admin_question_edit_safe_return(?string $candidate): string {
  $fallback = '/admin/questions.php';
  $candidate = trim((string)$candidate);
  if ($candidate === '') {
    return $fallback;
  }
  if (preg_match('/[\r\n]/', $candidate)) {
    return $fallback;
  }
  if (strpos($candidate, '/admin/') !== 0) {
    return $fallback;
  }
  return $candidate;
}

function admin_question_edit_known_needs(PDO $pdo): array {
  $needs = [];
  $st = $pdo->query("
    SELECT DISTINCT TRIM(need) AS need_name
    FROM questions
    WHERE need IS NOT NULL AND TRIM(need) <> ''
    ORDER BY need_name ASC
  ");
  foreach (($st ? $st->fetchAll() : []) as $row) {
    $need = normalize_question_need((string)($row['need_name'] ?? ''));
    if ($need !== '') {
      $needs[$need] = true;
    }
  }
  return array_keys($needs);
}

$id = (int)($_GET['id'] ?? 0);
$returnTo = admin_question_edit_safe_return((string)($_GET['return'] ?? ''));

if ($id <= 0) {
  http_response_code(403);
  echo "Creation manuelle des questions desactivee. Utilisez l'import.";
  exit;
}

$question = [
  'id' => 0,
  'text' => '',
  'need' => 'PONE',
  'level' => 1,
  'question_type' => 'MULTI',
  'allow_skip' => 0,
  'explanation' => '',
];

$optionsByLabel = [];

$st = $pdo->prepare("SELECT id, text, need, level, question_type, allow_skip, explanation FROM questions WHERE id=?");
$st->execute([$id]);
$q = $st->fetch();
if (!$q) {
  http_response_code(404);
  echo "Question not found";
  exit;
}

$question = [
  'id' => (int)$q['id'],
  'text' => (string)$q['text'],
  'need' => (string)($q['need'] ?? 'PONE'),
  'level' => (int)($q['level'] ?? 1),
  'question_type' => (string)($q['question_type'] ?? 'MULTI'),
  'allow_skip' => 0,
  'explanation' => (string)($q['explanation'] ?? ''),
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

$knownNeeds = admin_question_edit_known_needs($pdo);
if (!in_array($question['need'], $knownNeeds, true) && $question['need'] !== '') {
  $knownNeeds[] = $question['need'];
  natcasesort($knownNeeds);
  $knownNeeds = array_values($knownNeeds);
}

$labels = ['A', 'B', 'C', 'D', 'E', 'F'];
$errors = [];
$translationLangs = ['en' => 'EN', 'es' => 'ES', 'jp' => 'JA'];
$translationsByLang = [];
foreach ($translationLangs as $translationLang => $translationLabel) {
  $translationMetaStmt = $pdo->prepare("
    SELECT question_text, explanation, source_updated_at
    FROM question_translations
    WHERE question_id = ? AND lang = ?
    LIMIT 1
  ");
  $translationMetaStmt->execute([$id, $translationLang]);
  $translationMeta = $translationMetaStmt->fetch() ?: [];
  $translationsByLang[$translationLang] = [
    'status' => question_translation_status($pdo, $id, $translationLang),
    'text' => trim((string)($translationMeta['question_text'] ?? '')),
    'explanation' => trim((string)($translationMeta['explanation'] ?? '')),
    'source_updated_at' => trim((string)($translationMeta['source_updated_at'] ?? '')),
    'options' => [],
  ];
}
foreach ($labels as $label) {
  $option = $optionsByLabel[$label] ?? null;
  $optionId = (int)($option['id'] ?? 0);
  foreach (array_keys($translationLangs) as $translationLang) {
    $translationsByLang[$translationLang]['options'][$label] = translated_option_text($pdo, $optionId, $translationLang, '');
  }
}
$existingTranslationsByLang = $translationsByLang;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $returnTo = admin_question_edit_safe_return((string)($_POST['return'] ?? $returnTo));
  $question['text'] = trim((string)($_POST['text'] ?? ''));
  $question['need'] = normalize_question_need((string)($_POST['need'] ?? ($question['need'] ?? 'PONE')));
  $question['level'] = (int)($_POST['level'] ?? ($question['level'] ?? 1));
  $question['question_type'] = (string)($_POST['question_type'] ?? 'MULTI');
  $question['allow_skip'] = 0;
  $question['explanation'] = trim((string)($_POST['explanation'] ?? ''));
  foreach (array_keys($translationLangs) as $translationLang) {
    $translationsByLang[$translationLang]['text'] = trim((string)($_POST['translations'][$translationLang]['text'] ?? ''));
    $translationsByLang[$translationLang]['explanation'] = trim((string)($_POST['translations'][$translationLang]['explanation'] ?? ''));
    foreach ($labels as $label) {
      $translationsByLang[$translationLang]['options'][$label] = trim((string)($_POST['translations'][$translationLang]['options'][$label] ?? ''));
    }
  }

  if ($question['text'] === '') {
    $errors[] = "Enonce obligatoire.";
  }
  if (!in_array($question['question_type'], ['MULTI', 'SINGLE', 'TRUE_FALSE'], true)) {
    $errors[] = "Type invalide.";
  }
  if ($question['need'] === '') {
    $errors[] = "Categorie obligatoire.";
  } elseif (!in_array($question['need'], $knownNeeds, true)) {
    $errors[] = "Categorie invalide.";
  }
  if ($question['level'] < 1 || $question['level'] > 3) {
    $errors[] = "Niveau question invalide (1..3).";
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
    $scoreValue = $isCorrect ? 1 : 0;

    if (mb_strtoupper($text, 'UTF-8') === 'NSP') {
      $scoreValue = 0;
      $isCorrect = 0;
    }

    if (isset($score[$label]) && $score[$label] !== '') {
      $manual = filter_var($score[$label], FILTER_VALIDATE_INT);
      if ($manual !== false) {
        $scoreValue = max(0, (int)$manual);
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
	  } elseif ($question['question_type'] === 'SINGLE') {
	    if ($nbCorrect !== 1) {
	      $errors[] = "SINGLE : exactement 1 bonne reponse.";
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
        $up = $pdo->prepare("UPDATE questions SET text=?, need=?, level=?, question_type=?, allow_skip=?, explanation=?, updated_at=NOW() WHERE id=?");
        $up->execute([
          $question['text'],
          $question['need'],
          $question['level'],
          $question['question_type'],
          $question['allow_skip'],
          $question['explanation'] !== '' ? $question['explanation'] : null,
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

      $questionUpdatedAtStmt = $pdo->prepare("SELECT updated_at FROM questions WHERE id = ? LIMIT 1");
      $questionUpdatedAtStmt->execute([$qid]);
      $currentQuestionUpdatedAt = (string)($questionUpdatedAtStmt->fetchColumn() ?: '');

      $optionsReloadStmt = $pdo->prepare("
        SELECT id, label
        FROM question_options
        WHERE question_id = ?
        ORDER BY label ASC
      ");
      $optionsReloadStmt->execute([$qid]);
      $reloadedOptions = $optionsReloadStmt->fetchAll() ?: [];
      $optionIdByLabel = [];
      foreach ($reloadedOptions as $reloadedOption) {
        $optionIdByLabel[(string)$reloadedOption['label']] = (int)$reloadedOption['id'];
      }

      $saveQuestionTranslation = $pdo->prepare("
        INSERT INTO question_translations(question_id, lang, question_text, explanation, source_updated_at, created_at, updated_at)
        VALUES(?,?,?,?,?,NOW(),NOW())
        ON DUPLICATE KEY UPDATE
          question_text = VALUES(question_text),
          explanation = VALUES(explanation),
          source_updated_at = VALUES(source_updated_at),
          updated_at = NOW()
      ");
      $deleteQuestionTranslation = $pdo->prepare("
        DELETE FROM question_translations
        WHERE question_id = ? AND lang = ?
      ");
      $saveOptionTranslation = $pdo->prepare("
        INSERT INTO question_option_translations(option_id, lang, option_text, created_at, updated_at)
        VALUES(?,?,?,NOW(),NOW())
        ON DUPLICATE KEY UPDATE
          option_text = VALUES(option_text),
          updated_at = NOW()
      ");
      $deleteOptionTranslationsForLang = $pdo->prepare("
        DELETE qot
        FROM question_option_translations qot
        JOIN question_options qo ON qo.id = qot.option_id
        WHERE qo.question_id = ? AND qot.lang = ?
      ");

      foreach (array_keys($translationLangs) as $translationLang) {
        $translatedText = trim((string)($translationsByLang[$translationLang]['text'] ?? ''));
        $translatedExplanation = trim((string)($translationsByLang[$translationLang]['explanation'] ?? ''));
        $translatedOptions = $translationsByLang[$translationLang]['options'] ?? [];
        $existingTranslation = $existingTranslationsByLang[$translationLang] ?? ['text' => '', 'explanation' => '', 'options' => [], 'source_updated_at' => ''];
        $hasAnyOptionTranslation = false;
        foreach ($translatedOptions as $translatedOptionText) {
          if (trim((string)$translatedOptionText) !== '') {
            $hasAnyOptionTranslation = true;
            break;
          }
        }
        $hasTranslationPayload = ($translatedText !== '' || $translatedExplanation !== '' || $hasAnyOptionTranslation);

        if (!$hasTranslationPayload) {
          $deleteQuestionTranslation->execute([$qid, $translationLang]);
          $deleteOptionTranslationsForLang->execute([$qid, $translationLang]);
          continue;
        }

        $translationChanged = (
          $translatedText !== trim((string)($existingTranslation['text'] ?? ''))
          || $translatedExplanation !== trim((string)($existingTranslation['explanation'] ?? ''))
        );
        foreach ($labels as $label) {
          if (trim((string)($translatedOptions[$label] ?? '')) !== trim((string)($existingTranslation['options'][$label] ?? ''))) {
            $translationChanged = true;
            break;
          }
        }
        $sourceUpdatedAtForSave = $translationChanged
          ? ($currentQuestionUpdatedAt !== '' ? $currentQuestionUpdatedAt : null)
          : (trim((string)($existingTranslation['source_updated_at'] ?? '')) !== '' ? trim((string)($existingTranslation['source_updated_at'] ?? '')) : null);

        $saveQuestionTranslation->execute([
          $qid,
          $translationLang,
          $translatedText !== '' ? $translatedText : $question['text'],
          $translatedExplanation !== '' ? $translatedExplanation : null,
          $sourceUpdatedAtForSave,
        ]);

        $deleteOptionTranslationsForLang->execute([$qid, $translationLang]);
        foreach ($labels as $label) {
          $optionId = (int)($optionIdByLabel[$label] ?? 0);
          $translatedOptionText = trim((string)($translatedOptions[$label] ?? ''));
          if ($optionId <= 0 || $translatedOptionText === '') {
            continue;
          }
          $saveOptionTranslation->execute([$optionId, $translationLang, $translatedOptionText]);
        }
      }

      $pdo->commit();
      header("Location: " . $returnTo);
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = "Erreur DB: " . $e->getMessage();
    }
  }
}

?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title><?= "Modifier question #".(int)$question['id'] ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
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
      <input type="hidden" name="return" value="<?= h($returnTo) ?>">
      <section class="pack-config-section">
        <h3 class="pack-config-title">Configuration de la question</h3>
        <div class="pack-config-grid">
          <article class="pack-config-card">
            <h4 class="pack-config-card-title">Param&egrave;tres</h4>
            <div class="pack-config-fields">
              <div class="question-field">
                <label class="label">Categorie</label>
                <select class="input" name="need" required>
                  <?php foreach ($knownNeeds as $needOpt): ?>
                    <option value="<?= h($needOpt) ?>" <?= ((string)($question['need'] ?? '') === $needOpt) ? 'selected' : '' ?>>
                      <?= h($needOpt) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="question-field">
                <label class="label">Niveau question</label>
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
                    'SINGLE' => 'Choix unique',
                    'TRUE_FALSE' => 'Vrai / Faux',
                  ];
                  foreach ($typeLabels as $typeValue => $typeLabel):
                  ?>
                    <option value="<?= h($typeValue) ?>" <?= $question['question_type'] === $typeValue ? 'selected' : '' ?>><?= h($typeLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

            </div>
          </article>

          <article class="pack-config-card pack-config-card-wide">
            <h4 class="pack-config-card-title">Enonc&eacute;</h4>
            <div class="pack-config-fields">
              <div class="question-field-full">
                <label class="label">Texte de la question</label>
                <textarea name="text" rows="4" class="question-textarea" required><?= h($question['text']) ?></textarea>
              </div>
              <div class="question-field-full">
                <label class="label">Explication</label>
                <textarea name="explanation" rows="5" class="question-textarea" placeholder="Explication affichee apres la question, par exemple le raisonnement ou le rappel de la bonne reponse."><?= h((string)($question['explanation'] ?? '')) ?></textarea>
              </div>
            </div>
          </article>
        </div>
      </section>

      <section class="pack-config-section">
        <h3 class="pack-config-title">Options de r&eacute;ponse</h3>
        <div class="question-options-head">
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
      </section>

      <section class="pack-config-section">
        <h3 class="pack-config-title">Traductions</h3>
        <div class="translation-edit-grid">
          <?php foreach ($translationLangs as $translationLang => $translationLabel): ?>
            <?php
              $translationStatus = (string)($translationsByLang[$translationLang]['status'] ?? 'missing');
              $statusLabel = match ($translationStatus) {
                'complete' => 'A jour',
                'stale' => 'A revoir',
                'partial' => 'Partielle',
                default => 'Manquante',
              };
              $statusClass = match ($translationStatus) {
                'complete' => 'pill success',
                'stale' => 'pill warning',
                'partial' => 'pill info',
                default => 'pill danger',
              };
            ?>
            <article class="pack-config-card translation-edit-card">
              <div class="translation-edit-head">
                <h4 class="pack-config-card-title"><?= h($translationLabel) ?></h4>
                <span class="<?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
              </div>
              <div class="pack-config-fields">
                <div class="question-field-full">
                  <label class="label">Texte de la question</label>
                  <textarea name="translations[<?= h($translationLang) ?>][text]" rows="3" class="question-textarea"><?= h((string)($translationsByLang[$translationLang]['text'] ?? '')) ?></textarea>
                </div>
                <div class="question-field-full">
                  <label class="label">Explication</label>
                  <textarea name="translations[<?= h($translationLang) ?>][explanation]" rows="4" class="question-textarea"><?= h((string)($translationsByLang[$translationLang]['explanation'] ?? '')) ?></textarea>
                </div>
                <?php foreach ($labels as $label): ?>
                  <div class="question-field-full">
                    <label class="label">Option <?= h($label) ?></label>
                    <input class="input" type="text" name="translations[<?= h($translationLang) ?>][options][<?= h($label) ?>]" value="<?= h((string)($translationsByLang[$translationLang]['options'][$label] ?? '')) ?>" placeholder="Traduction de l'option <?= h($label) ?>">
                  </div>
                <?php endforeach; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="question-actions">
        <button class="btn" type="submit">Enregistrer</button>
        <a class="btn ghost" href="<?= h($returnTo) ?>">Annuler</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
