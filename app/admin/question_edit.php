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
  $translationStatusOverrides = [];
  foreach ((array)($_POST['translation_status_overrides'] ?? []) as $overrideLang => $overrideValue) {
    $normalizedLang = question_translation_normalize_lang((string)$overrideLang);
    if (($overrideValue === 'complete' || $overrideValue === 'stale') && isset($translationLangs[$normalizedLang])) {
      $translationStatusOverrides[$normalizedLang] = $overrideValue;
    }
  }
  $stayOnPageAfterSave = !empty($translationStatusOverrides) || !empty($_POST['translations']);

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

    $pdo->beginTransaction();
    try {
      if ($question['id'] > 0) {
        $qid = $question['id'];
      } else {
        throw new RuntimeException("Creation manuelle des questions desactivee.");
      }

      if ($sourceChanged) {
        $up = $pdo->prepare("UPDATE questions SET text=?, need=?, theme=?, level=?, question_type=?, allow_skip=?, explanation=?, updated_at=NOW() WHERE id=?");
        $up->execute([
          $question['text'],
          $question['need'],
          $question['theme'] !== '' ? $question['theme'] : null,
          $question['level'],
          $question['question_type'],
          $question['allow_skip'],
          $question['explanation'] !== '' ? $question['explanation'] : null,
          $qid,
        ]);

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

        $pdo->prepare("
          UPDATE question_translations
          SET source_updated_at = NULL, status_override = 'stale', updated_at = NOW()
          WHERE question_id = ?
        ")->execute([$qid]);
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

        if ($translationChanged) {
          $saveQuestionTranslation->execute([
            $qid,
            $translationLang,
            $translatedText !== '' ? $translatedText : $question['text'],
            $translatedExplanation !== '' ? $translatedExplanation : null,
            $currentQuestionUpdatedAt !== '' ? $currentQuestionUpdatedAt : null,
          ]);
        }

        if ($translationChanged || $sourceChanged) {
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
      }

      foreach ($translationStatusOverrides as $overrideLang => $overrideValue) {
        $pdo->prepare("
          UPDATE question_translations
          SET source_updated_at = ?, status_override = ?, updated_at = NOW()
          WHERE question_id = ? AND lang = ?
        ")->execute([
          $currentQuestionUpdatedAt !== '' ? $currentQuestionUpdatedAt : null,
          $overrideValue,
          $qid,
          $overrideLang,
        ]);
      }

      $pdo->commit();
      $staleCheckStmt = $pdo->prepare("
        SELECT qt.lang FROM question_translations qt
        JOIN questions q ON q.id = qt.question_id
        WHERE qt.question_id = ?
        AND (
          qt.status_override = 'stale'
          OR (qt.status_override IS NULL AND qt.source_updated_at < q.updated_at)
        )
        ORDER BY qt.lang ASC
      ");
      $staleCheckStmt->execute([$qid]);
      $staleLangs = $staleCheckStmt->fetchAll(\PDO::FETCH_COLUMN);
      if ($stayOnPageAfterSave) {
        header("Location: /admin/question_edit.php?id=" . (int)$qid . ($activeProgramId > 0 ? "&program_id=" . (int)$activeProgramId : '') . "&return=" . urlencode($returnTo) . "&saved=1" . (!empty($staleLangs) ? "&trad_stale=" . urlencode(implode(',', array_map('question_translation_lang_label', $staleLangs))) : ""));
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
  <title><?= h(t('admin.questions.edit_title', ['id' => $displayQuestionId], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1"><?= h(t('admin.questions.edit_title', ['id' => $displayQuestionId], $lang)) ?></h2>
        <?php if ($question['external_id'] !== null): ?>
          <p class="sub"><?= h(t('admin.questions.internal_id', ['id' => (int)$question['id']], $lang)) ?></p>
        <?php endif; ?>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('questions'); ?>
      </div>
    </div>

    <hr class="separator">

    <?php if ($errors): ?>
      <div class="import-report question-errors">
        <div class="import-report-title"><?= h(t('admin.questions.errors', [], $lang)) ?></div>
        <div class="import-report-errors">
          <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['saved'])): ?>
      <div class="flash-success" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <span><?= h(t('admin.questions.saved', [], $lang)) ?></span>
        <a class="btn ghost" style="white-space:nowrap;" href="<?= h($returnTo) ?>"><?= h(t('admin.questions.back_to_list', [], $lang)) ?></a>
      </div>
      <?php if (isset($_GET['trad_stale']) && $_GET['trad_stale'] !== ''): ?>
        <div class="flash-warn"><?= h(t('admin.questions.saved_trad_stale', [], $lang)) ?> : <?= h($_GET['trad_stale']) ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" class="question-form">
      <input type="hidden" name="return" value="<?= h($returnTo) ?>">
      <section class="pack-config-section">
        <h3 class="pack-config-title"><?= h(t('admin.questions.section_config', [], $lang)) ?></h3>
        <div class="pack-config-grid">
          <article class="pack-config-card">
            <h4 class="pack-config-card-title"><?= h(t('admin.questions.card_params', [], $lang)) ?></h4>
            <div class="pack-config-fields">
              <div class="question-field">
                <label class="label"><?= h(t('admin.questions.field_category', [], $lang)) ?></label>
                <select class="input" name="need" required>
                  <?php foreach ($knownNeeds as $needOpt): ?>
                    <option value="<?= h($needOpt) ?>" <?= ((string)($question['need'] ?? '') === $needOpt) ? 'selected' : '' ?>>
                      <?= h($needOpt) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="question-field">
                <label class="label"><?= h(t('admin.questions.field_level', [], $lang)) ?></label>
                <select name="level" required>
                  <?php for ($i = 1; $i <= 3; $i++): ?>
                    <option value="<?= $i ?>" <?= ((int)($question['level'] ?? 1) === $i) ? 'selected' : '' ?>>
                      <?= $i ?>
                    </option>
                  <?php endfor; ?>
                </select>
              </div>

              <div class="question-field">
                <label class="label"><?= h(t('admin.questions.field_theme', [], $lang)) ?></label>
                <input class="input" type="text" name="theme" value="<?= h((string)($question['theme'] ?? '')) ?>" placeholder="<?= h(t('admin.questions.field_theme_ph', [], $lang)) ?>">
              </div>

              <div class="question-field">
                <label class="label"><?= h(t('admin.common.type', [], $lang)) ?></label>
                <select name="question_type">
                  <?php
                  $typeLabels = [
                    'MULTI' => t('admin.questions.type_multi', [], $lang),
                    'SINGLE' => t('admin.questions.type_single', [], $lang),
                    'TRUE_FALSE' => t('admin.questions.type_tf', [], $lang),
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
            <h4 class="pack-config-card-title"><?= h(t('admin.questions.card_statement', [], $lang)) ?></h4>
            <div class="pack-config-fields">
              <div class="question-field-full">
                <label class="label"><?= h(t('admin.questions.field_text', [], $lang)) ?></label>
                <textarea name="text" rows="4" class="question-textarea" required><?= h($question['text']) ?></textarea>
              </div>
              <div class="question-field-full">
                <label class="label"><?= h(t('admin.questions.field_explanation', [], $lang)) ?></label>
                <textarea name="explanation" rows="5" class="question-textarea" placeholder="<?= h(t('admin.questions.field_explanation_ph', [], $lang)) ?>"><?= h((string)($question['explanation'] ?? '')) ?></textarea>
              </div>
            </div>
          </article>
        </div>
      </section>

      <section class="pack-config-section">
        <h3 class="pack-config-title"><?= h(t('admin.questions.options_title', [], $lang)) ?></h3>
        <div class="question-options-head">
          <p class="small"><?= h(t('admin.questions.options_hint', [], $lang)) ?></p>
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
              <input class="input question-option-input" type="text" name="opt[<?= h($label) ?>]" value="<?= h($text) ?>" placeholder="<?= h($label) ?>">
              <label class="question-option-check">
                <input type="checkbox" name="correct[<?= h($label) ?>]" <?= $isCorrect ? 'checked' : '' ?>> <?= h(t('admin.questions.option_correct', [], $lang)) ?>
              </label>
              <input class="input question-option-score" type="number" name="score[<?= h($label) ?>]" value="<?= h($scoreValue) ?>" placeholder="score">
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="pack-config-section">
        <h3 class="pack-config-title"><?= h(t('admin.questions.translations_title', [], $lang)) ?></h3>
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
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;" data-status-container="<?= h($translationLang) ?>">
                  <input type="hidden" name="translation_status_overrides[<?= h($translationLang) ?>]" value="">
                  <button class="<?= h($reviewBtnClass) ?>" type="button" data-status-lang="<?= h($translationLang) ?>" data-status-action="stale"><?= h(t('admin.questions.translation_stale', [], $lang)) ?></button>
                  <button class="<?= h($upToDateBtnClass) ?>" type="button" data-status-lang="<?= h($translationLang) ?>" data-status-action="complete"><?= h(t('admin.questions.translation_ok', [], $lang)) ?></button>
                </div>
              </div>
              <div class="pack-config-fields">
                <div class="question-field-full">
                  <label class="label"><?= h(t('admin.questions.translation_text', [], $lang)) ?></label>
                  <textarea name="translations[<?= h($translationLang) ?>][text]" rows="3" class="question-textarea"><?= h((string)($translationsByLang[$translationLang]['text'] ?? '')) ?></textarea>
                </div>
                <div class="question-field-full">
                  <label class="label"><?= h(t('admin.questions.translation_explanation', [], $lang)) ?></label>
                  <textarea name="translations[<?= h($translationLang) ?>][explanation]" rows="4" class="question-textarea"><?= h((string)($translationsByLang[$translationLang]['explanation'] ?? '')) ?></textarea>
                </div>
                <?php foreach ($labels as $label): ?>
                  <div class="question-field-full">
                    <label class="label"><?= h(t('admin.questions.translation_option', ['label' => $label], $lang)) ?></label>
                    <input class="input" type="text" name="translations[<?= h($translationLang) ?>][options][<?= h($label) ?>]" value="<?= h((string)($translationsByLang[$translationLang]['options'][$label] ?? '')) ?>" placeholder="<?= h($label) ?>">
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
<script>
(function () {
  document.querySelectorAll('[data-status-lang]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var action = btn.getAttribute('data-status-action');
      var container = btn.closest('[data-status-container]');
      if (container) {
        container.querySelectorAll('[data-status-lang]').forEach(function (b) {
          b.classList.remove('is-active');
          b.classList.add('ghost');
        });
        var input = container.querySelector('input[type="hidden"]');
        if (input) { input.value = action; }
      }
      btn.classList.remove('ghost');
      btn.classList.add('is-active');
    });
  });
})();
</script>
</body>
</html>
