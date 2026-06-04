<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin_area();
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
ensure_question_translation_schema($pdo);
ensure_program_source_language_schema($pdo);
$activeProgramId = auth_admin_program_context($pdo, $adminUser, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
$activeProgramSourceLang = program_source_lang($pdo, $activeProgramId);
$translationLangs = question_translation_target_langs($activeProgramSourceLang);
$activeProgramPackageCount = 0;
if ($activeProgramId > 0) {
  if (auth_program_package_links_enabled($pdo)) {
    $programPackageCountStmt = $pdo->prepare("SELECT COUNT(*) FROM program_package_links WHERE program_id = ?");
    $programPackageCountStmt->execute([$activeProgramId]);
    $activeProgramPackageCount = (int)$programPackageCountStmt->fetchColumn();
  } elseif (auth_column_exists($pdo, 'packages', 'program_id')) {
    $programPackageCountStmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE program_id = ?");
    $programPackageCountStmt->execute([$activeProgramId]);
    $activeProgramPackageCount = (int)$programPackageCountStmt->fetchColumn();
  }
}

function normalize_header(string $value): string {
  $value = trim(mb_strtolower($value, 'UTF-8'));
  $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
  if (is_string($ascii) && $ascii !== '') {
    $value = $ascii;
  }
  $value = preg_replace('/[^a-z0-9]+/', '', $value);
  return $value ?? '';
}

function normalize_boolean_token(string $value): string {
  $value = trim(mb_strtolower($value, 'UTF-8'));
  $value = preg_replace('/\s+/u', ' ', $value);
  return $value ?? '';
}

function detect_delimiter(string $headerLine): string {
  $candidates = [",", ";", "\t"];
  $best = ",";
  $bestCount = -1;
  foreach ($candidates as $candidate) {
    $count = substr_count($headerLine, $candidate);
    if ($count > $bestCount) {
      $best = $candidate;
      $bestCount = $count;
    }
  }
  return $best;
}

function parse_csv_rows(string $tmpPath): array {
  $rows = [];
  $handle = fopen($tmpPath, 'rb');
  if ($handle === false) {
    throw new RuntimeException("Impossible d'ouvrir le fichier CSV.");
  }
  $first = fgets($handle);
  if ($first === false) {
    fclose($handle);
    return [];
  }
  $delimiter = detect_delimiter($first);
  rewind($handle);

  $line = 0;
  while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
    $line++;
    if ($cells === null) {
      continue;
    }
    $trimmed = [];
    $isEmpty = true;
    foreach ($cells as $cell) {
      $v = trim((string)$cell);
      if ($v !== '') {
        $isEmpty = false;
      }
      $trimmed[] = $v;
    }
    if ($isEmpty) {
      continue;
    }
    $rows[] = [
      '__line' => $line,
      '__cells' => $trimmed,
    ];
  }
  fclose($handle);
  return $rows;
}

function xlsx_col_to_index(string $letters): int {
  $letters = strtoupper($letters);
  $index = 0;
  $len = strlen($letters);
  for ($i = 0; $i < $len; $i++) {
    $index = ($index * 26) + (ord($letters[$i]) - ord('A') + 1);
  }
  return max(1, $index) - 1;
}

function parse_xlsx_rows(string $tmpPath): array {
  if (!class_exists('ZipArchive')) {
    throw new RuntimeException("Le support XLSX requiert l'extension ZipArchive.");
  }
  $zip = new ZipArchive();
  if ($zip->open($tmpPath) !== true) {
    throw new RuntimeException("Impossible d'ouvrir le fichier XLSX.");
  }

  $sharedStrings = [];
  $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
  if ($sharedXml !== false) {
    $sx = simplexml_load_string($sharedXml);
    if ($sx !== false && isset($sx->si)) {
      foreach ($sx->si as $si) {
        $text = '';
        if (isset($si->t)) {
          $text = (string)$si->t;
        } elseif (isset($si->r)) {
          foreach ($si->r as $run) {
            $text .= (string)$run->t;
          }
        }
        $sharedStrings[] = trim($text);
      }
    }
  }

  $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
  if ($sheetXml === false) {
    $zip->close();
    throw new RuntimeException("Feuille sheet1 introuvable dans le XLSX.");
  }

  $sheet = simplexml_load_string($sheetXml);
  if ($sheet === false || !isset($sheet->sheetData->row)) {
    $zip->close();
    return [];
  }

  $rows = [];
  foreach ($sheet->sheetData->row as $row) {
    $lineNo = (int)($row['r'] ?? 0);
    $cells = [];
    foreach ($row->c as $c) {
      $ref = (string)($c['r'] ?? '');
      $letters = preg_replace('/\d+/', '', $ref);
      $colIdx = xlsx_col_to_index((string)$letters);
      $type = (string)($c['t'] ?? '');
      $value = '';

      if ($type === 'inlineStr' && isset($c->is->t)) {
        $value = (string)$c->is->t;
      } elseif (isset($c->v)) {
        $raw = (string)$c->v;
        if ($type === 's') {
          $strIdx = (int)$raw;
          $value = (string)($sharedStrings[$strIdx] ?? '');
        } elseif ($type === 'b') {
          $value = ($raw === '1') ? 'TRUE' : 'FALSE';
        } else {
          $value = $raw;
        }
      }
      $cells[$colIdx] = trim($value);
    }
    if (count($cells) === 0) {
      continue;
    }
    ksort($cells);
    $maxIndex = max(array_keys($cells));
    $dense = [];
    for ($i = 0; $i <= $maxIndex; $i++) {
      $dense[] = (string)($cells[$i] ?? '');
    }
    $rows[] = [
      '__line' => $lineNo > 0 ? $lineNo : (count($rows) + 1),
      '__cells' => $dense,
    ];
  }
  $zip->close();
  return $rows;
}

function load_input_rows(array $file): array {
  if (!isset($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
    throw new RuntimeException("Aucun fichier charge.");
  }
  $name = (string)($file['name'] ?? '');
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext === 'csv') {
    return parse_csv_rows((string)$file['tmp_name']);
  }
  if ($ext === 'xlsx') {
    return parse_xlsx_rows((string)$file['tmp_name']);
  }
  throw new RuntimeException("Format non supporte: .$ext (attendu: .csv ou .xlsx).");
}

function db_column_nullable(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("
    SELECT IS_NULLABLE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table, $column]);
  $v = strtoupper((string)$st->fetchColumn());
  return $v === 'YES';
}

function import_question_by_external_id(PDO $pdo, int $externalId, int $activeProgramId = 0): ?array {
  if ($externalId <= 0) {
    return null;
  }
  if ($activeProgramId > 0 && auth_program_question_links_enabled($pdo)) {
    $st = $pdo->prepare("
      SELECT q.id, q.updated_at
      FROM questions q
      JOIN program_question_links pql
        ON pql.question_id = q.id
       AND pql.program_id = ?
      WHERE q.external_id = ?
      LIMIT 1
    ");
    $st->execute([$activeProgramId, $externalId]);
    $row = $st->fetch();
    return $row ?: null;
  }

  $st = $pdo->prepare("
    SELECT id, updated_at
    FROM questions
    WHERE external_id = ?
    ORDER BY id ASC
    LIMIT 1
  ");
  $st->execute([$externalId]);
  $row = $st->fetch();
  return $row ?: null;
}

function import_mode_normalize(string $mode): string {
  $mode = strtolower(trim($mode));
  return in_array($mode, ['source', 'update', 'translation'], true) ? $mode : 'source';
}

function mapping_fields(string $importMode = 'source'): array {
  $common = [
    'id' => ['label' => 'ID', 'required' => true, 'aliases' => ['id', 'questionid', 'questionexternalid']],
    'question' => ['label' => 'Questions', 'required' => true, 'aliases' => ['questions', 'question']],
    'answer1' => ['label' => 'Reponse 1', 'required' => true, 'aliases' => ['reponse1', 'response1', 'answer1', 'answera']],
    'answer2' => ['label' => 'Reponse 2', 'required' => true, 'aliases' => ['reponse2', 'response2', 'answer2', 'answerb']],
    'answer3' => ['label' => 'Reponse 3', 'required' => false, 'aliases' => ['reponse3', 'response3', 'answer3', 'answerc']],
    'answer4' => ['label' => 'Reponse 4', 'required' => false, 'aliases' => ['reponse4', 'response4', 'answer4', 'answerd']],
    'answer5' => ['label' => 'Reponse 5', 'required' => false, 'aliases' => ['reponse5', 'response5', 'answer5', 'answere']],
    'answer6' => ['label' => 'Reponse 6', 'required' => false, 'aliases' => ['reponse6', 'response6', 'answer6', 'answerf']],
    'explanation' => ['label' => 'Explication', 'required' => false, 'aliases' => ['explicationdetailleedelabonneresponse', 'explicationdetaillee', 'explanation']],
  ];

  if ($importMode === 'translation') {
    return $common;
  }

  $sourceFields = $common + [
    'knowledge_required' => ['label' => 'Categorie', 'required' => true, 'aliases' => ['categorie', 'toolconcerned', 'connaissancesrequises', 'knowledgerequired', 'knowledge', 'need', 'needs']],
    'theme' => ['label' => 'Theme', 'required' => false, 'aliases' => ['themequestion', 'theme']],
    'level' => ['label' => 'Niveau question', 'required' => true, 'aliases' => ['niveauquestion', 'level', 'niveau']],
    'correct' => ['label' => 'Bonnes reponses', 'required' => true, 'aliases' => ['bonnesreponses', 'bonnereponse', 'correctanswers', 'goodanswers', 'correct']],
    'user_probance' => ['label' => 'Utilisateur Probance', 'required' => false, 'aliases' => ['utilisateurprobance', 'userprobance', 'probanceuser']],
    'user_brainpad' => ['label' => 'Utilisateur Brainpad', 'required' => false, 'aliases' => ['utilisateurbrainpad', 'userbrainpad', 'brainpaduser']],
    'open_to_client' => ['label' => 'Ouvert au client', 'required' => false, 'aliases' => ['ouvertauclient', 'open_to_client', 'opentoclient', 'clientopen', 'openedtoclient']],
  ];

  return $sourceFields;
}

function parse_open_to_client_value(string $raw): ?int {
  $v = normalize_boolean_token($raw);
  $vNorm = normalize_header($raw);
  if ($v === '') {
    return null;
  }

  $trueValues = ['1', 'true', 'vrai', 'oui', 'o', 'yes', 'y', 'open', 'ouvert'];
  $falseValues = ['0', 'false', 'faux', 'non', 'n', 'no', 'closed', 'ferme', 'fermee'];

  if (in_array($v, $trueValues, true) || in_array($vNorm, $trueValues, true)) {
    return 1;
  }
  if (in_array($v, $falseValues, true) || in_array($vNorm, $falseValues, true)) {
    return 0;
  }
  return null;
}

function parse_knowledge_required(string $raw): array {
  return parse_question_need_tokens($raw);
}

function primary_need_from_tokens(array $tokens): string {
  return (string)($tokens[0] ?? '');
}

function auto_map_headers(array $headers, string $importMode): array {
  $map = [];
  $normToIdx = [];
  foreach ($headers as $idx => $header) {
    $normToIdx[normalize_header((string)$header)] = (int)$idx;
  }
  foreach (mapping_fields($importMode) as $key => $def) {
    $map[$key] = null;
    foreach ($def['aliases'] as $alias) {
      if (array_key_exists($alias, $normToIdx)) {
        $map[$key] = (int)$normToIdx[$alias];
        break;
      }
    }
    if ($map[$key] !== null) {
      continue;
    }
    foreach ($normToIdx as $normalizedHeader => $idx) {
      foreach ($def['aliases'] as $alias) {
        if ($alias !== '' && strpos($normalizedHeader, $alias) !== false) {
          $map[$key] = (int)$idx;
          break 2;
        }
      }
    }
  }
  return $map;
}

function validate_mapping(array $map, string $importMode): array {
  $errors = [];
  foreach (mapping_fields($importMode) as $key => $def) {
    if (!$def['required']) {
      continue;
    }
    if (!isset($map[$key]) || $map[$key] === null || $map[$key] === '') {
      $errors[] = "Mapping manquant: " . $def['label'];
    }
  }
  return $errors;
}

function cell_value(array $cells, ?int $idx): string {
  if ($idx === null || $idx < 0) {
    return '';
  }
  return trim((string)($cells[$idx] ?? ''));
}

function validate_and_prepare_rows(PDO $pdo, array $rows, array $map, string $importMode, string $importLang, int $activeProgramId = 0): array {
  $report = [
    'read_lines' => 0,
    'created' => 0,
    'updated' => 0,
    'rejected' => 0,
    'errors' => [],
  ];
  $prepared = [];
  $headerCells = $rows[0]['__cells'] ?? [];
  $importMode = import_mode_normalize($importMode);
  $importLang = question_translation_normalize_lang($importLang);

  for ($r = 1; $r < count($rows); $r++) {
    $lineNo = (int)($rows[$r]['__line'] ?? ($r + 1));
    $cells = $rows[$r]['__cells'] ?? [];

    $isEmpty = true;
    foreach ($cells as $c) {
      if (trim((string)$c) !== '') {
        $isEmpty = false;
        break;
      }
    }
    if ($isEmpty) {
      continue;
    }
    $report['read_lines']++;

    $externalIdRaw = cell_value($cells, $map['id'] ?? null);
    $questionText = cell_value($cells, $map['question'] ?? null);
    $explanation = cell_value($cells, $map['explanation'] ?? null);
    $theme = cell_value($cells, $map['theme'] ?? null);
    $knowledgeRequiredRaw = cell_value($cells, $map['knowledge_required'] ?? null);
    $levelRaw = cell_value($cells, $map['level'] ?? null);
    $correctRaw = cell_value($cells, $map['correct'] ?? null);
    $userProbanceRaw = cell_value($cells, $map['user_probance'] ?? null);
    $userBrainpadRaw = cell_value($cells, $map['user_brainpad'] ?? null);
    $openToClientRaw = cell_value($cells, $map['open_to_client'] ?? null);
    $openToClient = parse_open_to_client_value($openToClientRaw);
    $rowErrors = [];
    if ($externalIdRaw === '' || !preg_match('/^\d+$/', $externalIdRaw)) {
      $rowErrors[] = "ID absent ou non numerique.";
    }
    if ($questionText === '') {
      $rowErrors[] = "Questions vide.";
    }

    $responses = [];
    $nonEmptyResponseIndexes = [];
    for ($i = 1; $i <= 6; $i++) {
      $key = 'answer' . $i;
      $responses[$i] = cell_value($cells, $map[$key] ?? null);
      if ($responses[$i] !== '') {
        $nonEmptyResponseIndexes[] = $i;
      }
    }

    if ($responses[1] === '' || $responses[2] === '') {
      $rowErrors[] = "Reponse 1 et Reponse 2 sont obligatoires.";
    }
    if (count($nonEmptyResponseIndexes) < 2) {
      $rowErrors[] = "Moins de 2 reponses non vides.";
    }
    if ($importMode === 'translation') {
      $optionLookup = $pdo->prepare("
        SELECT id, label
        FROM question_options
        WHERE question_id = ?
        ORDER BY label ASC
      ");

      $questionSource = import_question_by_external_id($pdo, (int)$externalIdRaw, $activeProgramId);
      $questionId = (int)($questionSource['id'] ?? 0);
      if ($questionId <= 0) {
        $rowErrors[] = "Question source introuvable pour cet ID dans ce programme.";
      }

      $existingOptions = [];
      if ($questionId > 0) {
        $optionLookup->execute([$questionId]);
        $existingOptions = $optionLookup->fetchAll() ?: [];
      }
      if (count($existingOptions) < 2) {
        $rowErrors[] = "Question source sans assez de reponses.";
      }

      foreach ($existingOptions as $optionIndex => $existingOption) {
        $responseIndex = $optionIndex + 1;
        if (trim((string)($responses[$responseIndex] ?? '')) === '') {
          $rowErrors[] = "Traduction manquante pour la reponse " . $existingOption['label'] . ".";
        }
      }
      for ($i = count($existingOptions) + 1; $i <= 6; $i++) {
        if (trim((string)($responses[$i] ?? '')) !== '') {
          $rowErrors[] = "Reponse traduite en trop (colonne $i) par rapport a la question source.";
        }
      }

      if ($rowErrors) {
        $report['rejected']++;
        $rowId = $externalIdRaw !== '' ? $externalIdRaw : '?';
        foreach ($rowErrors as $rowError) {
          $report['errors'][] = "Ligne $lineNo (ID $rowId): $rowError";
        }
        continue;
      }

      $translations = [];
      foreach ($existingOptions as $optionIndex => $existingOption) {
        $responseIndex = $optionIndex + 1;
        $translations[] = [
          'option_id' => (int)$existingOption['id'],
          'label' => (string)$existingOption['label'],
          'text' => trim((string)($responses[$responseIndex] ?? '')),
        ];
      }

      $prepared[] = [
        'line_no' => $lineNo,
        'external_id' => (int)$externalIdRaw,
        'question_id' => $questionId,
        'lang' => $importLang,
        'question_text' => $questionText,
        'explanation' => $explanation !== '' ? $explanation : null,
        'source_updated_at' => (string)($questionSource['updated_at'] ?? ''),
        'option_translations' => $translations,
      ];
      continue;
    }

    $knowledgeTokens = parse_knowledge_required($knowledgeRequiredRaw);
    if (!$knowledgeTokens) {
      $rowErrors[] = "Categorie vide ou invalide.";
    }
    if ($levelRaw === '' || !preg_match('/^-?\d+$/', $levelRaw)) {
      $rowErrors[] = "Niveau question non numerique.";
    }
    if ($correctRaw === '') {
      $rowErrors[] = "Bonnes reponses vide.";
    }
    if ($openToClientRaw !== '' && $openToClient === null) {
      $rowErrors[] = "Ouvert au client invalide (attendu: oui/non, true/false, 1/0).";
    }

    $correctIndexes = [];
    if ($correctRaw !== '') {
      $parts = explode(';', $correctRaw);
      foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') {
          continue;
        }
        if (!preg_match('/^\d+$/', $part)) {
          $rowErrors[] = "Bonnes reponses invalide: $correctRaw";
          $correctIndexes = [];
          break;
        }
        $n = (int)$part;
        if ($n < 1 || $n > 6) {
          $rowErrors[] = "Index bonne reponse hors [1..6]: $n";
          $correctIndexes = [];
          break;
        }
        $correctIndexes[$n] = true;
      }
      if (!$correctIndexes) {
        $rowErrors[] = "Aucune bonne reponse valide.";
      }
    }

    foreach (array_keys($correctIndexes) as $idx) {
      if (($responses[$idx] ?? '') === '') {
        $rowErrors[] = "Bonne reponse $idx pointe une reponse vide.";
      }
    }

    $isBoolean = false;
    if (count($nonEmptyResponseIndexes) === 2) {
      $v1 = normalize_boolean_token($responses[$nonEmptyResponseIndexes[0]]);
      $v2 = normalize_boolean_token($responses[$nonEmptyResponseIndexes[1]]);
      $pair = [$v1, $v2];
      sort($pair);
      if ($pair === ['faux', 'vrai'] || $pair === ['false', 'true']) {
        $isBoolean = true;
      }
    }

    if ($isBoolean) {
      if (count($correctIndexes) !== 1) {
        $rowErrors[] = "VRAI/FAUX: une seule bonne reponse autorisee.";
      }
      $questionType = 'TRUE_FALSE';
      $allowMulti = 0;
    } elseif (count($correctIndexes) === 1) {
      $questionType = 'SINGLE';
      $allowMulti = 0;
    } else {
      $questionType = 'MULTI';
      $allowMulti = 1;
    }

    if ($rowErrors) {
      $report['rejected']++;
      $rowId = $externalIdRaw !== '' ? $externalIdRaw : '?';
      foreach ($rowErrors as $rowError) {
        $report['errors'][] = "Ligne $lineNo (ID $rowId): $rowError";
      }
      continue;
    }

    $knownIdx = [];
    foreach ($map as $idx) {
      if ($idx !== null && $idx !== '') {
        $knownIdx[] = (int)$idx;
      }
    }
    $meta = ['allow_multi' => $allowMulti];
    if ($userProbanceRaw !== '') {
      $meta['User Probance'] = $userProbanceRaw;
    }
    if ($userBrainpadRaw !== '') {
      $meta['User Brainpad'] = $userBrainpadRaw;
    }
    foreach ($headerCells as $idx => $headerName) {
      if (in_array($idx, $knownIdx, true)) {
        continue;
      }
      $extra = trim((string)($cells[$idx] ?? ''));
      if ($extra !== '') {
        $meta[(string)$headerName] = $extra;
      }
    }

    $prepared[] = [
      'line_no' => $lineNo,
      'external_id' => (int)$externalIdRaw,
      'text' => $questionText,
      'theme' => $theme !== '' ? $theme : null,
      'need' => primary_need_from_tokens($knowledgeTokens),
      'knowledge_required_csv' => implode(',', $knowledgeTokens),
      'level' => (int)$levelRaw,
      'question_type' => $questionType,
      'allow_skip' => 0,
      'open_to_client' => $openToClient,
      'explanation' => $explanation !== '' ? $explanation : null,
      'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
      'responses' => $responses,
      'correct_indexes' => array_keys($correctIndexes),
    ];
  }

  return ['prepared' => $prepared, 'report' => $report];
}

function run_import(PDO $pdo, array $prepared, array $report, string $importMode, string $importLang, int $activeProgramId = 0): array {
  $importMode = import_mode_normalize($importMode);
  $importLang = question_translation_normalize_lang($importLang);

  if ($importMode === 'translation') {
    ensure_question_translation_schema($pdo);
    $upsertQuestionTranslation = $pdo->prepare("
      INSERT INTO question_translations(question_id, lang, question_text, explanation, source_updated_at, created_at, updated_at)
      VALUES(?,?,?,?,?,NOW(),NOW())
      ON DUPLICATE KEY UPDATE
        question_text = VALUES(question_text),
        explanation = VALUES(explanation),
        source_updated_at = VALUES(source_updated_at),
        status_override = NULL,
        updated_at = NOW()
    ");
    $upsertOptionTranslation = $pdo->prepare("
      INSERT INTO question_option_translations(option_id, lang, option_text, created_at, updated_at)
      VALUES(?,?,?,NOW(),NOW())
      ON DUPLICATE KEY UPDATE
        option_text = VALUES(option_text),
        updated_at = NOW()
    ");

    foreach ($prepared as $row) {
      $lineNo = (int)$row['line_no'];
      $externalId = (int)$row['external_id'];
      $pdo->beginTransaction();
      try {
        $upsertQuestionTranslation->execute([
          (int)$row['question_id'],
          $importLang,
          (string)$row['question_text'],
          $row['explanation'],
          ($row['source_updated_at'] !== '' ? $row['source_updated_at'] : null),
        ]);
        foreach (($row['option_translations'] ?? []) as $optionTranslation) {
          $upsertOptionTranslation->execute([
            (int)$optionTranslation['option_id'],
            $importLang,
            (string)$optionTranslation['text'],
          ]);
        }
        $pdo->commit();
        $report['updated']++;
      } catch (Throwable $e) {
        $pdo->rollBack();
        $report['rejected']++;
        $report['errors'][] = "Ligne $lineNo (ID $externalId): Erreur DB traduction: " . $e->getMessage();
      }
    }

    return $report;
  }

  $hasOpenToClientColumn = table_column_exists($pdo, 'questions', 'open_to_client');
  if ($hasOpenToClientColumn) {
    $insertQ = $pdo->prepare("
      INSERT INTO questions(
        external_id, package_id, text, need, level, question_type, allow_skip,
        knowledge_required_csv, theme, open_to_client, explanation, meta_json, created_at, updated_at
      ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
    ");
    $updateQ = $pdo->prepare("
      UPDATE questions SET
        text=?,
        need=?,
        level=?,
        question_type=?,
        allow_skip=?,
        knowledge_required_csv=?,
        theme=?,
        explanation=?,
        meta_json=?,
        updated_at=NOW()
      WHERE id=?
    ");
    $updateQWithOpen = $pdo->prepare("
      UPDATE questions SET
        text=?,
        need=?,
        level=?,
        question_type=?,
        allow_skip=?,
        knowledge_required_csv=?,
        theme=?,
        open_to_client=?,
        explanation=?,
        meta_json=?,
        updated_at=NOW()
      WHERE id=?
    ");
  } else {
    $insertQ = $pdo->prepare("
      INSERT INTO questions(
        external_id, package_id, text, need, level, question_type, allow_skip,
        knowledge_required_csv, theme, explanation, meta_json, created_at, updated_at
      ) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
    ");
    $updateQ = $pdo->prepare("
      UPDATE questions SET
        text=?,
        need=?,
        level=?,
        question_type=?,
        allow_skip=?,
        knowledge_required_csv=?,
        theme=?,
        explanation=?,
        meta_json=?,
        updated_at=NOW()
      WHERE id=?
    ");
    $updateQWithOpen = null;
  }
  $deleteOpts = $pdo->prepare("DELETE FROM question_options WHERE question_id=?");
  $deleteOptionTranslations = auth_table_exists($pdo, 'question_option_translations')
    ? $pdo->prepare("
      DELETE qot
      FROM question_option_translations qot
      JOIN question_options qo ON qo.id = qot.option_id
      WHERE qo.question_id = ?
    ")
    : null;
  $insertOpt = $pdo->prepare("
    INSERT INTO question_options(question_id, label, option_text, is_correct, score_value)
    VALUES(?,?,?,?,?)
  ");
  $markTranslationsStale = auth_table_exists($pdo, 'question_translations')
    ? $pdo->prepare("
      UPDATE question_translations
      SET source_updated_at = NULL,
          status_override = 'stale',
          updated_at = NOW()
      WHERE question_id = ?
    ")
    : null;
  $linkQuestionToProgram = null;
  if ($activeProgramId > 0 && auth_table_exists($pdo, 'program_question_links')) {
    $linkQuestionToProgram = $pdo->prepare("
      INSERT INTO program_question_links(program_id, question_id)
      VALUES(?, ?)
      ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");
  }
  $labels = ['A', 'B', 'C', 'D', 'E', 'F'];

  foreach ($prepared as $row) {
    $lineNo = (int)$row['line_no'];
    $externalId = (int)$row['external_id'];
    $correctMap = array_fill_keys(array_map('intval', $row['correct_indexes']), true);

    $pdo->beginTransaction();
    try {
      $existingQuestion = import_question_by_external_id($pdo, $externalId, $activeProgramId);
      $existingQid = $existingQuestion['id'] ?? false;

      if ($existingQid === false) {
        if ($hasOpenToClientColumn) {
          $insertQ->execute([
            $externalId,
            null,
            $row['text'],
            $row['need'],
            $row['level'],
            $row['question_type'],
            $row['allow_skip'],
            $row['knowledge_required_csv'],
            $row['theme'],
            $row['open_to_client'] ?? 0,
            $row['explanation'],
            $row['meta_json'],
          ]);
        } else {
          $insertQ->execute([
            $externalId,
            null,
            $row['text'],
            $row['need'],
            $row['level'],
            $row['question_type'],
            $row['allow_skip'],
            $row['knowledge_required_csv'],
            $row['theme'],
            $row['explanation'],
            $row['meta_json'],
          ]);
        }
        $qid = (int)$pdo->lastInsertId();
        $report['created']++;
      } else {
        $qid = (int)$existingQid;
        if ($hasOpenToClientColumn && $row['open_to_client'] !== null) {
          $updateQWithOpen->execute([
            $row['text'],
            $row['need'],
            $row['level'],
            $row['question_type'],
            $row['allow_skip'],
            $row['knowledge_required_csv'],
            $row['theme'],
            $row['open_to_client'],
            $row['explanation'],
            $row['meta_json'],
            $qid,
          ]);
        } else {
          $updateQ->execute([
            $row['text'],
            $row['need'],
            $row['level'],
            $row['question_type'],
            $row['allow_skip'],
            $row['knowledge_required_csv'],
            $row['theme'],
            $row['explanation'],
            $row['meta_json'],
            $qid,
          ]);
        }
        $report['updated']++;
      }

      if ($deleteOptionTranslations !== null) {
        $deleteOptionTranslations->execute([$qid]);
      }
      $deleteOpts->execute([$qid]);
      $insertedOptionIds = [];
      for ($i = 1; $i <= 6; $i++) {
        $txt = trim((string)$row['responses'][$i]);
        if ($txt === '') {
          continue;
        }
        $isCorrect = isset($correctMap[$i]) ? 1 : 0;
        $scoreValue = $isCorrect ? 1 : 0;
        $insertOpt->execute([$qid, $labels[$i - 1], $txt, $isCorrect, $scoreValue]);
        $insertedOptionIds[$i] = (int)$pdo->lastInsertId();
      }
      if ($linkQuestionToProgram !== null) {
        $linkQuestionToProgram->execute([$activeProgramId, $qid]);
      }
      if ($markTranslationsStale !== null) {
        $markTranslationsStale->execute([$qid]);
      }
      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      $report['rejected']++;
      $report['errors'][] = "Ligne $lineNo (ID $externalId): Erreur DB: " . $e->getMessage();
    }
  }

  return $report;
}

$stateKey = 'question_import_v12';
if (!isset($_SESSION[$stateKey]) || !is_array($_SESSION[$stateKey])) {
  $_SESSION[$stateKey] = [];
}
$state = $_SESSION[$stateKey];

$schemaErrors = [];
foreach (['external_id', 'package_id', 'text', 'need', 'knowledge_required_csv', 'level', 'question_type', 'allow_skip', 'theme', 'category', 'profile', 'explanation', 'meta_json', 'updated_at'] as $col) {
  if (!table_column_exists($pdo, 'questions', $col)) {
    $schemaErrors[] = "Colonne manquante dans questions: $col";
  }
}
if (table_column_exists($pdo, 'questions', 'package_id') && !db_column_nullable($pdo, 'questions', 'package_id')) {
  $schemaErrors[] = "questions.package_id est NOT NULL. Lance les migrations SQL du projet.";
}

$importMode = import_mode_normalize((string)($_POST['import_mode'] ?? ($state['import_mode'] ?? 'source')));
$importLang = import_effective_lang($importMode, (string)($_POST['import_lang'] ?? ($state['import_lang'] ?? $activeProgramSourceLang)), $activeProgramSourceLang);
$mappingDefs = mapping_fields($importMode);
$mapping = $state['mapping'] ?? [];
$report = null;
$verifyDone = false;
$action = trim((string)($_POST['action'] ?? ''));
$lastAction = '';

if (isset($_GET['cancel_import']) && (string)$_GET['cancel_import'] === '1') {
  $_SESSION[$stateKey] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $lastAction = $action;
  if ($schemaErrors) {
    $report = [
      'read_lines' => 0,
      'created' => 0,
      'updated' => 0,
      'rejected' => 0,
      'errors' => array_map(static fn($e) => "Schema: $e", $schemaErrors),
    ];
  } elseif ($action === 'load_file') {
    try {
      $rows = load_input_rows($_FILES['import_file'] ?? []);
      if (!$rows || count($rows) < 2) {
        throw new RuntimeException("Le fichier est vide ou ne contient pas de donnees.");
      }
      $headers = $rows[0]['__cells'] ?? [];
      $importMode = import_mode_normalize((string)($_POST['import_mode'] ?? 'source'));
      $importLang = import_effective_lang($importMode, (string)($_POST['import_lang'] ?? $activeProgramSourceLang), $activeProgramSourceLang);
      $mapping = auto_map_headers($headers, $importMode);
      $state = [
        'file_name' => (string)($_FILES['import_file']['name'] ?? ''),
        'rows' => $rows,
        'headers' => $headers,
        'mapping' => $mapping,
        'import_mode' => $importMode,
        'import_lang' => $importLang,
        'can_import' => false,
      ];
      $_SESSION[$stateKey] = $state;
    } catch (Throwable $e) {
      $report = [
        'read_lines' => 0,
        'created' => 0,
        'updated' => 0,
        'rejected' => 0,
        'errors' => [$e->getMessage()],
      ];
    }
  } elseif ($action === 'verify') {
    $state = $_SESSION[$stateKey] ?? [];
    if (!isset($state['rows']) || !is_array($state['rows'])) {
      $report = [
        'read_lines' => 0,
        'created' => 0,
        'updated' => 0,
        'rejected' => 0,
        'errors' => ["Aucun fichier charge. Charge d'abord un fichier."],
      ];
    } else {
      $importMode = import_mode_normalize((string)($_POST['import_mode'] ?? ($state['import_mode'] ?? 'source')));
      $importLang = import_effective_lang($importMode, (string)($_POST['import_lang'] ?? ($state['import_lang'] ?? $activeProgramSourceLang)), $activeProgramSourceLang);
      $mapping = [];
      foreach (mapping_fields($importMode) as $key => $_def) {
        $raw = $_POST['mapping'][$key] ?? '';
        $mapping[$key] = ($raw === '' ? null : (int)$raw);
      }
      $errors = validate_mapping($mapping, $importMode);
      if ($errors) {
        $report = [
          'read_lines' => 0,
          'created' => 0,
          'updated' => 0,
          'rejected' => 0,
          'errors' => $errors,
        ];
      } else {
        $validation = validate_and_prepare_rows($pdo, $state['rows'], $mapping, $importMode, $importLang, $activeProgramId);
        $report = $validation['report'];
        $state['mapping'] = $mapping;
        $state['import_mode'] = $importMode;
        $state['import_lang'] = $importLang;
        $state['can_import'] = true;
        $_SESSION[$stateKey] = $state;
        $verifyDone = true;
      }
    }
  } elseif ($action === 'update_context') {
    $state = $_SESSION[$stateKey] ?? [];
    if (isset($state['rows']) && is_array($state['rows']) && !empty($state['rows'])) {
      $headers = $state['headers'] ?? ($state['rows'][0]['__cells'] ?? []);
      if (!is_array($headers)) {
        $headers = [];
      }
      $importMode = import_mode_normalize((string)($_POST['import_mode'] ?? ($state['import_mode'] ?? 'source')));
      $importLang = import_effective_lang($importMode, (string)($_POST['import_lang'] ?? ($state['import_lang'] ?? $activeProgramSourceLang)), $activeProgramSourceLang);
      $state['import_mode'] = $importMode;
      $state['import_lang'] = $importLang;
      $state['mapping'] = auto_map_headers($headers, $importMode);
      $state['can_import'] = false;
      $_SESSION[$stateKey] = $state;
    }
  } elseif ($action === 'import') {
    $state = $_SESSION[$stateKey] ?? [];
    if (empty($state['can_import'])) {
      $report = [
        'read_lines' => 0,
        'created' => 0,
        'updated' => 0,
        'rejected' => 0,
        'errors' => ["Verification requise avant import. Clique sur 'Verifier les donnees'."],
      ];
    } else {
      $mapping = $state['mapping'] ?? [];
      $importMode = import_mode_normalize((string)($_POST['import_mode'] ?? ($state['import_mode'] ?? 'source')));
      $importLang = import_effective_lang($importMode, (string)($_POST['import_lang'] ?? ($state['import_lang'] ?? $activeProgramSourceLang)), $activeProgramSourceLang);
      $state['import_mode'] = $importMode;
      $state['import_lang'] = $importLang;
      $_SESSION[$stateKey] = $state;
      $validationErrors = validate_mapping($mapping, $importMode);
      if ($validationErrors) {
        $report = [
          'read_lines' => 0,
          'created' => 0,
          'updated' => 0,
          'rejected' => 0,
          'errors' => $validationErrors,
        ];
      } else {
        $validation = validate_and_prepare_rows($pdo, $state['rows'], $mapping, $importMode, $importLang, $activeProgramId);
        $report = run_import($pdo, $validation['prepared'], $validation['report'], $importMode, $importLang, $activeProgramId);
        // Import termine: on purge l'etat pour masquer le mapping.
        $_SESSION[$stateKey] = [];
        $state = [];
        $verifyDone = true;
      }
    }
  }
}

$state = $_SESSION[$stateKey] ?? [];
$importMode = import_mode_normalize((string)($state['import_mode'] ?? $importMode ?? 'source'));
$importLang = import_effective_lang($importMode, (string)($state['import_lang'] ?? $importLang ?? $activeProgramSourceLang), $activeProgramSourceLang);
$mappingDefs = mapping_fields($importMode);
$hasLoadedRows = isset($state['rows']) && is_array($state['rows']) && count($state['rows']) > 0;
$headers = $state['headers'] ?? [];
if ((empty($headers) || !is_array($headers)) && $hasLoadedRows) {
  $headers = $state['rows'][0]['__cells'] ?? [];
  if (is_array($headers) && !empty($headers)) {
    $state['headers'] = $headers;
    $_SESSION[$stateKey] = $state;
  }
}
$mapping = $state['mapping'] ?? $mapping;

function import_mode_label(string $mode): string {
  return match (import_mode_normalize($mode)) {
    'translation' => 'Traductions',
    'update' => 'Mise a jour',
    default => 'Reinitialisation',
  };
}

function import_effective_lang(string $mode, string $lang, string $sourceLang): string {
  $mode = import_mode_normalize($mode);
  $lang = question_translation_normalize_lang($lang);
  $sourceLang = question_translation_normalize_lang($sourceLang);
  if ($mode === 'source' || $mode === 'update') {
    return $sourceLang;
  }

  if ($lang !== $sourceLang) {
    return $lang;
  }

  $targets = question_translation_target_langs($sourceLang);
  return (string)array_key_first($targets);
}

function import_lang_label(string $lang): string {
  return question_translation_lang_label($lang);
}

?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title>Admin &middot; Import questions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1">Admin &middot; Importer des questions</h2>
        <p class="sub">Workflow: fichier -> mapping -> v&eacute;rification -> import</p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('questions'); ?>
      </div>
    </div>

    <hr class="separator">

    <?php if ($schemaErrors): ?>
      <div class="import-report question-errors">
        <div class="import-report-title">Migration requise</div>
        <div class="import-report-errors">
          <ul>
            <?php foreach ($schemaErrors as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
          <p class="small">Applique les migrations SQL du projet avant de continuer.</p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($activeProgramId > 0 && $activeProgramPackageCount === 0): ?>
      <div class="admin-notice is-ok" style="margin-bottom:12px;">
        Aucun pack associ&eacute;: l'import reste possible. Les questions seront visibles dans l'administration, mais elles ne seront pas utilisables en certification tant qu'un pack ne les cible pas.
      </div>
    <?php endif; ?>

    <?php if ($report !== null): ?>
      <div class="import-report">
        <div class="import-report-title">Rapport</div>
        <div class="import-report-stats">
          <span class="pill">Lignes lues: <?= (int)$report['read_lines'] ?></span>
          <span class="pill success">Cr&eacute;&eacute;es: <?= (int)$report['created'] ?></span>
          <span class="pill info">Mises &agrave; jour: <?= (int)$report['updated'] ?></span>
          <span class="pill danger">Rejet&eacute;es: <?= (int)$report['rejected'] ?></span>
        </div>
        <?php
          $readLines = (int)($report['read_lines'] ?? 0);
          $rejectedLines = (int)($report['rejected'] ?? 0);
          $validLines = max(0, $readLines - $rejectedLines);
          $validPercent = $readLines > 0 ? (int)round(($validLines * 100) / $readLines) : 0;
        ?>
        <?php if ($lastAction === 'verify' && empty($report['errors'])): ?>
          <div class="admin-notice is-ok" style="margin-top:10px;">
            V&eacute;rification OK: <?= (int)$validPercent ?>% lignes valides (<?= (int)$validLines ?>/<?= (int)$readLines ?>).
            Vous pouvez cliquer sur <b><?= h(t('admin.import.do_import', [], $lang)) ?></b>.
          </div>
        <?php endif; ?>
        <?php if (!empty($report['errors'])): ?>
          <div class="import-report-errors">
            <b>Erreurs</b>
            <ul>
              <?php foreach ($report['errors'] as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <?php if (($verifyDone || ($report !== null && empty($report['errors']))) && !empty($state['can_import'])): ?>
          <form method="post" class="import-form" style="margin-top:12px;">
            <input type="hidden" name="action" value="import">
            <input type="hidden" name="import_mode" value="<?= h($importMode) ?>">
            <input type="hidden" name="import_lang" value="<?= h($importLang) ?>">
            <div class="import-help" style="margin-top:0;">
              <p class="small" style="margin:0;">
                Pret pour import:
                <b data-import-summary-mode><?= h(import_mode_label($importMode)) ?></b>
                &middot; langue <b data-import-summary-lang><?= h(import_lang_label($importLang)) ?></b>
              </p>
            </div>
            <div class="import-actions">
              <button class="btn" type="submit"><?= h(t('admin.import.do_import', [], $lang)) ?></button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="import-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="<?= $hasLoadedRows ? 'update_context' : 'load_file' ?>">
      <div class="import-fields">
        <div class="import-field">
          <label class="label">
            <span class="order-help-wrap">
              <span>Mode d'import</span>
              <span class="order-help-tip" tabindex="0" aria-label="Aide sur le mode d'import">
                i
                <span class="order-help-bubble">Reinitialisation: remplace les questions/reponses de reference et marque les traductions a revoir.<br>Mise a jour: insere/met a jour uniquement les questions/reponses de reference.<br>Traductions: met a jour uniquement la langue choisie pour des questions deja existantes.</span>
              </span>
            </span>
          </label>
          <select class="input" name="import_mode">
            <option value="source" <?= $importMode === 'source' ? 'selected' : '' ?>>Reinitialisation</option>
            <option value="update" <?= $importMode === 'update' ? 'selected' : '' ?>>Mise a jour</option>
            <option value="translation" <?= $importMode === 'translation' ? 'selected' : '' ?>>Traductions</option>
          </select>
        </div>
        <div class="import-field import-field-full">
          <label class="label">
            <span class="order-help-wrap">
              <span>Langue importee</span>
              <span class="order-help-tip" tabindex="0" aria-label="Aide sur la langue importee">
                i
                <span class="order-help-bubble">La reference est toujours importee dans la langue source du programme. En traductions, cette liste sert aux libelles traduits.</span>
              </span>
            </span>
          </label>
          <div class="import-source-lang-note" data-source-lang-note style="<?= $importMode === 'source' ? '' : 'display:none;' ?>">
            Importer les questions sources en <b><?= h(import_lang_label($activeProgramSourceLang)) ?></b>.
          </div>
          <div class="import-source-lang-note" data-update-lang-note style="<?= $importMode === 'update' ? '' : 'display:none;' ?>">
            Mise a jour des questions/reponses sources en <b><?= h(import_lang_label($activeProgramSourceLang)) ?></b>.
          </div>
          <select class="input" name="import_lang" data-import-lang-select style="<?= $importMode === 'translation' ? '' : 'display:none;' ?>">
            <?php foreach ($translationLangs as $translationLang => $translationLabel): ?>
              <option value="<?= h($translationLang) ?>" <?= $importLang === $translationLang ? 'selected' : '' ?>><?= h($translationLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="import-field import-field-full">
          <label class="label">Fichier (.csv ou .xlsx)</label>
          <input class="input" type="file" name="import_file" accept=".csv,.xlsx" <?= $hasLoadedRows ? '' : 'required' ?>>
        </div>
      </div>
      <div class="import-actions">
        <button class="btn" type="submit" <?= $schemaErrors ? 'disabled' : '' ?>><?= $hasLoadedRows ? 'Mettre a jour le mapping' : 'Charger le fichier' ?></button>
      </div>
      <?php if ($hasLoadedRows): ?>
        <p class="small" style="margin:10px 0 0;">
          Le fichier courant est charge. Si tu changes le mode ou la langue, le mapping sera recalcul&eacute; pour ce fichier.
          Tu peux aussi <a href="/admin/import_questions.php?cancel_import=1<?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>">annuler l'import</a> pour repartir de zero.
        </p>
      <?php endif; ?>
    </form>

    <?php if ($hasLoadedRows && $headers): ?>
      <hr class="separator">
      <div class="import-help">
        <div class="import-help-head">
          <span class="import-help-tag">Mapping</span>
          <strong>Associe chaque champ &agrave; une colonne du fichier</strong>
        </div>
        <p class="small">Fichier charg&eacute;: <b><?= h((string)($state['file_name'] ?? '')) ?></b></p>
      </div>

      <form method="post" class="import-form">
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="import_mode" value="<?= h($importMode) ?>">
        <input type="hidden" name="import_lang" value="<?= h($importLang) ?>">
        <p class="small" style="margin:0 0 14px;">
          Mode: <b data-mapping-summary-mode><?= h(import_mode_label($importMode)) ?></b>
          &middot; Reference: <b><?= h(import_lang_label($activeProgramSourceLang)) ?></b>
          <span data-mapping-translation-summary style="<?= $importMode === 'translation' ? '' : 'display:none;' ?>">
            &middot; Traduction: <b data-mapping-summary-lang><?= h(import_lang_label($importLang)) ?></b>
          </span>
        </p>
        <div class="import-fields">
          <?php foreach ($mappingDefs as $key => $def): ?>
            <?php $selected = $mapping[$key] ?? null; ?>
            <div class="import-field">
              <label class="label">
                <?= h($def['label']) ?><?= $def['required'] ? ' *' : '' ?>
              </label>
              <select name="mapping[<?= h($key) ?>]">
                <option value="">-- non mapp&eacute; --</option>
                <?php foreach ($headers as $idx => $header): ?>
                  <option value="<?= (int)$idx ?>" <?= ((string)$selected === (string)$idx) ? 'selected' : '' ?>>
                    <?= h('#' . ((int)$idx + 1) . ' - ' . (string)$header) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="import-actions">
          <button class="btn" type="submit" <?= $schemaErrors ? 'disabled' : '' ?>>V&eacute;rifier les donn&eacute;es</button>
          <a class="btn ghost" href="/admin/import_questions.php?cancel_import=1<?= $activeProgramId > 0 ? '&program_id=' . (int)$activeProgramId : '' ?>"><?= h(t('admin.import.cancel', [], $lang)) ?></a>
        </div>
      </form>
    <?php endif; ?>

    <p class="small import-note">
      *Colonnes obligatoires.<br>
      La verification ne modifie pas la base. En mode <code>reinitialisation</code>, l'import cree les nouveaux ID et remplace les questions/reponses des ID deja existants dans la langue source du programme (<?= h(import_lang_label($activeProgramSourceLang)) ?>), sans supprimer les questions absentes du fichier; les traductions existantes sont marquees a revoir et les traductions de reponses sont supprimees. En mode <code>mise a jour</code>, l'import insere/met a jour uniquement les questions/reponses dans la langue source du programme. En mode <code>traduction</code>, l'import met a jour uniquement les libelles traduits de la langue choisie.
    </p>
  </div>
</div>
<script src="/assets/package-colors.js"></script>
<script>
  (function () {
    var importForm = document.querySelector('form.import-form[enctype="multipart/form-data"]');
    if (!importForm) return;
    var modeSelect = importForm.querySelector('select[name="import_mode"]');
    var langSelect = importForm.querySelector('select[name="import_lang"]');
    var sourceLangNote = importForm.querySelector('[data-source-lang-note]');
    var updateLangNote = importForm.querySelector('[data-update-lang-note]');
    var fileInput = importForm.querySelector('input[type="file"][name="import_file"]');
    var actionInput = importForm.querySelector('input[name="action"]');
    var hasLoadedRows = <?= $hasLoadedRows ? 'true' : 'false' ?>;
    var mappingModeSummaryEls = Array.prototype.slice.call(document.querySelectorAll('[data-mapping-summary-mode]'));
    var mappingLangSummaryEls = Array.prototype.slice.call(document.querySelectorAll('[data-mapping-summary-lang]'));
    var mappingTranslationSummaryEls = Array.prototype.slice.call(document.querySelectorAll('[data-mapping-translation-summary]'));
    var importModeSummaryEls = Array.prototype.slice.call(document.querySelectorAll('[data-import-summary-mode]'));
    var importLangSummaryEls = Array.prototype.slice.call(document.querySelectorAll('[data-import-summary-lang]'));
    var sourceLang = <?= json_encode($activeProgramSourceLang) ?>;

    function modeLabel(value) {
      value = String(value || '');
      if (value === 'translation') return 'Traductions';
      if (value === 'update') return 'Mise a jour';
      return 'Reinitialisation';
    }

    function langLabel(value) {
      if (value === 'en') return 'EN';
      if (value === 'es') return 'ES';
      if (value === 'jp') return 'JA';
      return 'FR';
    }

    function syncVisibleSummaries() {
      var currentMode = String((modeSelect && modeSelect.value) || 'source');
      var currentLang = currentMode === 'translation' ? String((langSelect && langSelect.value) || 'fr') : sourceLang;
      if (sourceLangNote) sourceLangNote.style.display = currentMode === 'source' ? '' : 'none';
      if (updateLangNote) updateLangNote.style.display = currentMode === 'update' ? '' : 'none';
      if (langSelect) langSelect.style.display = currentMode === 'translation' ? '' : 'none';

      mappingModeSummaryEls.forEach(function (el) { el.textContent = modeLabel(currentMode); });
      mappingLangSummaryEls.forEach(function (el) { el.textContent = langLabel(currentLang); });
      mappingTranslationSummaryEls.forEach(function (el) { el.style.display = currentMode === 'translation' ? '' : 'none'; });
      importModeSummaryEls.forEach(function (el) { el.textContent = modeLabel(currentMode); });
      importLangSummaryEls.forEach(function (el) { el.textContent = langLabel(currentLang); });

      document.querySelectorAll('form.import-form input[name="import_mode"][type="hidden"]').forEach(function (input) {
        input.value = currentMode;
      });
      document.querySelectorAll('form.import-form input[name="import_lang"][type="hidden"]').forEach(function (input) {
        input.value = currentLang;
      });
    }


    syncVisibleSummaries();
    if (modeSelect) modeSelect.addEventListener('change', syncVisibleSummaries);
    if (langSelect) langSelect.addEventListener('change', syncVisibleSummaries);

    if (!hasLoadedRows || !modeSelect || !actionInput) {
      return;
    }

    function submitContextUpdate() {
      if (fileInput && fileInput.value) {
        return;
      }
      actionInput.value = 'update_context';
      importForm.submit();
    }
    modeSelect.addEventListener('change', submitContextUpdate);
    if (langSelect) langSelect.addEventListener('change', submitContextUpdate);
  })();
</script>
</body>
</html>
