<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin_area();
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

$id = (int)($_GET['id'] ?? 0);
$activeProgramId = auth_admin_program_context($pdo, $adminUser, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
$returnTo = admin_question_edit_safe_return((string)($_GET['return'] ?? ''));
if ($activeProgramId > 0 && strpos($returnTo, 'program_id=') === false) {
  $returnTo .= (str_contains($returnTo, '?') ? '&' : '?') . 'program_id=' . $activeProgramId;
}

if ($id <= 0) {
  http_response_code(403);
  echo "Creation manuelle des questions desactivee. Utilisez l'import.";
  exit;
}

$question = [
  'id' => 0,
  'external_id' => null,
  'text' => '',
  'need' => '',
  'theme' => '',
  'level' => 1,
  'question_type' => 'MULTI',
  'allow_skip' => 0,
  'explanation' => '',
];

$optionsByLabel = [];

$st = $pdo->prepare("SELECT id, external_id, text, need, theme, level, question_type, allow_skip, explanation FROM questions WHERE id=?");
$st->execute([$id]);
$q = $st->fetch();
if (!$q) {
  http_response_code(404);
  echo "Question not found";
  exit;
}

$question = [
  'id' => (int)$q['id'],
  'external_id' => ($q['external_id'] === null || $q['external_id'] === '') ? null : (int)$q['external_id'],
  'text' => (string)$q['text'],
  'need' => normalize_question_need((string)($q['need'] ?? '')),
  'theme' => (string)($q['theme'] ?? ''),
  'level' => (int)($q['level'] ?? 1),
  'question_type' => (string)($q['question_type'] ?? 'MULTI'),
  'allow_skip' => 0,
  'explanation' => (string)($q['explanation'] ?? ''),
];

if ($activeProgramId > 0) {
  $scopeSql = auth_program_question_links_enabled($pdo)
    ? auth_program_question_scope_sql($pdo, $activeProgramId, 'q')
    : "(q.package_id IS NULL OR " . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false) . ")";
  $programScopeStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM questions q
    LEFT JOIN packages pk ON pk.id = q.package_id
    WHERE q.id = ?
      AND $scopeSql
  ");
  $programScopeStmt->execute([$id]);
  $programScoped = ((int)$programScopeStmt->fetchColumn() > 0);
  if (!$programScoped) {
    http_response_code(404);
    echo "Question not found";
    exit;
  }
}

$os = $pdo->prepare("
  SELECT id, label, option_text, is_correct, score_value
  FROM question_options
  WHERE question_id=?
  ORDER BY label ASC
");
$os->execute([$id]);
foreach ($os->fetchAll() as $o) {
  $optionsByLabel[(string)$o['label']] = $o;
}

if ($activeProgramId > 0 && auth_program_question_links_enabled($pdo)) {
  $knownNeedsStmt = $pdo->prepare("
    SELECT DISTINCT UPPER(TRIM(q.need)) AS need_key
    FROM questions q
    WHERE " . auth_program_question_scope_sql($pdo, $activeProgramId, 'q') . "
      AND q.need IS NOT NULL
      AND TRIM(q.need) <> ''
    ORDER BY need_key ASC
  ");
  $knownNeedsStmt->execute();
  $knownNeeds = [];
  foreach ($knownNeedsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $needValue) {
    $needValue = normalize_question_need((string)$needValue);
    if ($needValue !== '') {
      $knownNeeds[] = $needValue;
    }
  }
} else {
  $knownNeeds = question_known_needs($pdo);
}
if (!in_array($question['need'], $knownNeeds, true) && $question['need'] !== '') {
  $knownNeeds[] = $question['need'];
  natcasesort($knownNeeds);
  $knownNeeds = array_values($knownNeeds);
}
if ($question['need'] === '') {
  $question['need'] = question_default_need($knownNeeds);
}

$labels = ['A', 'B', 'C', 'D', 'E', 'F'];
$errors = [];
$programSourceLang = $activeProgramId > 0 ? program_source_lang($pdo, $activeProgramId) : question_program_source_lang($pdo, $id);
$translationLangs = question_translation_target_langs($programSourceLang);
$translationsByLang = [];
foreach ($translationLangs as $translationLang => $translationLabel) {
  $translationMetaStmt = $pdo->prepare("
    SELECT question_text, explanation, source_updated_at, status_override
    FROM question_translations
    WHERE question_id = ? AND lang = ?
    LIMIT 1
  ");
  $translationMetaStmt->execute([$id, $translationLang]);
  $translationMeta = $translationMetaStmt->fetch() ?: [];
  $translationsByLang[$translationLang] = [
    'status' => question_translation_status($pdo, $id, $translationLang, $programSourceLang),
    'text' => trim((string)($translationMeta['question_text'] ?? '')),
    'explanation' => trim((string)($translationMeta['explanation'] ?? '')),
    'source_updated_at' => trim((string)($translationMeta['source_updated_at'] ?? '')),
    'status_override' => trim((string)($translationMeta['status_override'] ?? '')),
    'options' => [],
  ];
}
foreach ($labels as $label) {
  $option = $optionsByLabel[$label] ?? null;
  $optionId = (int)($option['id'] ?? 0);
  foreach (array_keys($translationLangs) as $translationLang) {
    $translationsByLang[$translationLang]['options'][$label] = translated_option_text($pdo, $optionId, $translationLang, '', $programSourceLang);
  }
}
$existingTranslationsByLang = $translationsByLang;
$originalQuestion = $question;
$originalRowsByLabel = [];
foreach ($labels as $label) {
  $currentOption = $optionsByLabel[$label] ?? null;
  if (!$currentOption) {
    continue;
  }
  $originalRowsByLabel[$label] = [
    'text' => trim((string)($currentOption['option_text'] ?? '')),
    'is_correct' => (int)($currentOption['is_correct'] ?? 0),
    'score_value' => (int)($currentOption['score_value'] ?? 0),
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $returnTo = admin_question_edit_safe_return((string)($_POST['return'] ?? $returnTo));
  $translationStatusActionRaw = trim((string)($_POST['set_translation_status'] ?? ''));
  $translationStatusLang = '';
  $translationStatusValue = '';
  if (preg_match('/^([a-z]{2,5}):(complete|stale)$/', $translationStatusActionRaw, $matches)) {
    $translationStatusLang = question_translation_normalize_lang($matches[1]);
    $translationStatusValue = $matches[2];
  }
  $stayOnPageAfterSave = ($translationStatusLang !== '');

  if ($translationStatusLang !== '' && isset($translationLangs[$translationStatusLang])) {
    try {
      $currentQuestionUpdatedAtStmt = $pdo->prepare("SELECT updated_at FROM questions WHERE id = ? LIMIT 1");
      $currentQuestionUpdatedAtStmt->execute([$id]);
      $currentQuestionUpdatedAt = trim((string)($currentQuestionUpdatedAtStmt->fetchColumn() ?: ''));

      $updateTranslationStatusStmt = $pdo->prepare("
        UPDATE question_translations
        SET source_updated_at = ?, status_override = ?, updated_at = NOW()
        WHERE question_id = ? AND lang = ?
      ");
      $updateTranslationStatusStmt->execute([
        $currentQuestionUpdatedAt !== '' ? $currentQuestionUpdatedAt : null,
        $translationStatusValue,
        $id,
        $translationStatusLang,
      ]);

      header("Location: /admin/question_edit.php?id=" . (int)$id . ($activeProgramId > 0 ? "&program_id=" . (int)$activeProgramId : '') . "&return=" . urlencode($returnTo));
      exit;
    } catch (Throwable $e) {
      $errors[] = "Erreur mise a jour du statut de traduction: " . $e->getMessage();
    }
  }

  $question['text'] = trim((string)($_POST['text'] ?? ''));
  $question['need'] = normalize_question_need((string)($_POST['need'] ?? ($question['need'] ?? '')));
  $question['theme'] = trim((string)($_POST['theme'] ?? ''));
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
        $up = $pdo->prepare("UPDATE questions SET text=?, need=?, theme=?, level=?, question_type=?, allow_skip=?, explanation=?, updated_at=NOW() WHERE id=?");
        $up->execute([
          $question['text'],
          $question['need'],
          $question['theme'] !== '' ? $question['theme'] : null,
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

      if (auth_table_exists($pdo, 'question_option_translations')) {
        $pdo->prepare("
          DELETE qot
          FROM question_option_translations qot
          JOIN question_options qo ON qo.id = qot.option_id
          WHERE qo.question_id = ?
        ")->execute([$qid]);
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

      $sourceChanged =
        $question['text'] !== (string)($originalQuestion['text'] ?? '')
        || $question['need'] !== (string)($originalQuestion['need'] ?? '')
        || $question['theme'] !== trim((string)($originalQuestion['theme'] ?? ''))
        || (int)$question['level'] !== (int)($originalQuestion['level'] ?? 1)
        || $question['question_type'] !== (string)($originalQuestion['question_type'] ?? 'MULTI')
        || $question['explanation'] !== trim((string)($originalQuestion['explanation'] ?? ''));

      if (!$sourceChanged) {
        $submittedRowsByLabel = [];
        foreach ($rows as $submittedRow) {
          $submittedRowsByLabel[(string)$submittedRow['label']] = [
            'text' => trim((string)($submittedRow['text'] ?? '')),
            'is_correct' => (int)($submittedRow['is_correct'] ?? 0),
            'score_value' => (int)($submittedRow['score_value'] ?? 0),
          ];
        }
        $allLabels = array_values(array_unique(array_merge(array_keys($originalRowsByLabel), array_keys($submittedRowsByLabel))));
        foreach ($allLabels as $label) {
          $before = $originalRowsByLabel[$label] ?? ['text' => '', 'is_correct' => 0, 'score_value' => 0];
          $after = $submittedRowsByLabel[$label] ?? ['text' => '', 'is_correct' => 0, 'score_value' => 0];
          if (
            $before['text'] !== $after['text']
            || (int)$before['is_correct'] !== (int)$after['is_correct']
            || (int)$before['score_value'] !== (int)$after['score_value']
          ) {
            $sourceChanged = true;
            break;
          }
        }
      }

      if ($sourceChanged) {
        $clearTranslationOverridesStmt = $pdo->prepare("
          UPDATE question_translations
          SET status_override = NULL
          WHERE question_id = ?
        ");
        $clearTranslationOverridesStmt->execute([$qid]);
      }

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
        $sourceUpdatedAtForSave = null;
        if ($translationChanged) {
          $sourceUpdatedAtForSave = ($currentQuestionUpdatedAt !== '' ? $currentQuestionUpdatedAt : null);
        } else {
          $sourceUpdatedAtForSave = (trim((string)($existingTranslation['source_updated_at'] ?? '')) !== '' ? trim((string)($existingTranslation['source_updated_at'] ?? '')) : null);
        }

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
      if ($stayOnPageAfterSave) {
        header("Location: /admin/question_edit.php?id=" . (int)$qid . ($activeProgramId > 0 ? "&program_id=" . (int)$activeProgramId : '') . "&return=" . urlencode($returnTo));
      } else {
        header("Location: " . $returnTo);
      }
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = "Erreur DB: " . $e->getMessage();
    }
  }
}

?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <?php $displayQuestionId = $question['external_id'] !== null ? (int)$question['external_id'] : (int)$question['id']; ?>
  <title><?= "Modifier question #".(int)$displayQuestionId ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1"><?= "Admin &middot; Modifier question #".(int)$displayQuestionId ?></h2>
        <?php if ($question['external_id'] !== null): ?>
          <p class="sub">ID interne: #<?= (int)$question['id'] ?></p>
        <?php endif; ?>
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
                <label class="label">Th&eacute;matique</label>
                <input class="input" type="text" name="theme" value="<?= h((string)($question['theme'] ?? '')) ?>" placeholder="Theme de la question">
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
                <label class="label">Explication d&eacute;taill&eacute;e en cas de mauvaise r&eacute;ponse</label>
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
              $reviewBtnClass = ($translationStatus === 'stale') ? 'btn translation-status-btn translation-status-review is-active' : 'btn ghost translation-status-btn translation-status-review';
              $upToDateBtnClass = ($translationStatus === 'complete') ? 'btn translation-status-btn translation-status-complete is-active' : 'btn ghost translation-status-btn translation-status-complete';
            ?>
            <article class="pack-config-card translation-edit-card">
              <div class="translation-edit-head">
                <h4 class="pack-config-card-title"><?= h($translationLabel) ?></h4>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <button class="<?= h($reviewBtnClass) ?>" type="submit" name="set_translation_status" value="<?= h($translationLang) ?>:stale">A revoir</button>
                  <button class="<?= h($upToDateBtnClass) ?>" type="submit" name="set_translation_status" value="<?= h($translationLang) ?>:complete">A jour</button>
                </div>
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
        <button class="btn" type="submit"><?= h(t('admin.common.save', [], $lang)) ?></button>
        <a class="btn ghost" href="<?= h($returnTo) ?>"><?= h(t('admin.common.cancel', [], $lang)) ?></a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
