<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_nav.php';

$pdo = db();

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

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

function db_column_exists(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $column]);
  return ((int)$st->fetchColumn() > 0);
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

function mapping_fields(): array {
  return [
    'id' => ['label' => 'ID', 'required' => true, 'aliases' => ['id']],
    'question' => ['label' => 'Questions', 'required' => true, 'aliases' => ['questions', 'question']],
    'theme' => ['label' => 'Theme question', 'required' => true, 'aliases' => ['themequestion', 'theme']],
    'category' => ['label' => 'Categorie question', 'required' => true, 'aliases' => ['categoriequestion', 'categoryquestion', 'category', 'categorie']],
    'profile' => ['label' => 'Profil', 'required' => true, 'aliases' => ['profil', 'profile']],
    'knowledge_required' => ['label' => 'Knowledge required', 'required' => true, 'aliases' => ['knowledgerequired', 'knowledge', 'need', 'needs']],
    'level' => ['label' => 'Niveau question', 'required' => true, 'aliases' => ['niveauquestion', 'level', 'niveau']],
    'answer1' => ['label' => 'Reponse 1', 'required' => true, 'aliases' => ['reponse1', 'response1', 'answer1']],
    'answer2' => ['label' => 'Reponse 2', 'required' => true, 'aliases' => ['reponse2', 'response2', 'answer2']],
    'answer3' => ['label' => 'Reponse 3', 'required' => false, 'aliases' => ['reponse3', 'response3', 'answer3']],
    'answer4' => ['label' => 'Reponse 4', 'required' => false, 'aliases' => ['reponse4', 'response4', 'answer4']],
    'answer5' => ['label' => 'Reponse 5', 'required' => false, 'aliases' => ['reponse5', 'response5', 'answer5']],
    'answer6' => ['label' => 'Reponse 6', 'required' => false, 'aliases' => ['reponse6', 'response6', 'answer6']],
    'correct' => ['label' => 'Bonnes reponses', 'required' => true, 'aliases' => ['bonnesreponses', 'bonnereponse', 'correctanswers', 'goodanswers', 'correct']],
    'open_to_client' => ['label' => 'Ouvert au client', 'required' => false, 'aliases' => ['ouvertauclient', 'open_to_client', 'opentoclient', 'clientopen', 'openedtoclient']],
    'explanation' => ['label' => 'Explication', 'required' => false, 'aliases' => ['explicationdetailleedelabonneresponse', 'explicationdetaillee', 'explanation']],
  ];
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
  $parts = preg_split('/[;,\|\/]+|\s+/', strtoupper(trim($raw)));
  $valid = ['PONE' => true, 'PHM' => true, 'PPM' => true];
  $tokens = [];
  foreach ($parts as $part) {
    $part = trim((string)$part);
    if ($part === '') {
      continue;
    }
    if (!isset($valid[$part])) {
      continue;
    }
    $tokens[$part] = true;
  }
  return array_keys($tokens);
}

function primary_need_from_tokens(array $tokens): string {
  if (in_array('PPM', $tokens, true)) {
    return 'PPM';
  }
  if (in_array('PHM', $tokens, true)) {
    return 'PHM';
  }
  return 'PONE';
}

function auto_map_headers(array $headers): array {
  $map = [];
  $normToIdx = [];
  foreach ($headers as $idx => $header) {
    $normToIdx[normalize_header((string)$header)] = (int)$idx;
  }
  foreach (mapping_fields() as $key => $def) {
    $map[$key] = null;
    foreach ($def['aliases'] as $alias) {
      if (array_key_exists($alias, $normToIdx)) {
        $map[$key] = (int)$normToIdx[$alias];
        break;
      }
    }
  }
  return $map;
}

function validate_mapping(array $map): array {
  $errors = [];
  foreach (mapping_fields() as $key => $def) {
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

function validate_and_prepare_rows(array $rows, array $map): array {
  $report = [
    'read_lines' => 0,
    'created' => 0,
    'updated' => 0,
    'rejected' => 0,
    'errors' => [],
  ];
  $prepared = [];
  $headerCells = $rows[0]['__cells'] ?? [];

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
    $theme = cell_value($cells, $map['theme'] ?? null);
    $category = cell_value($cells, $map['category'] ?? null);
    $profile = cell_value($cells, $map['profile'] ?? null);
    $knowledgeRequiredRaw = cell_value($cells, $map['knowledge_required'] ?? null);
    $levelRaw = cell_value($cells, $map['level'] ?? null);
    $correctRaw = cell_value($cells, $map['correct'] ?? null);
    $openToClientRaw = cell_value($cells, $map['open_to_client'] ?? null);
    $explanation = cell_value($cells, $map['explanation'] ?? null);
    $openToClient = parse_open_to_client_value($openToClientRaw);

    $rowErrors = [];
    if ($externalIdRaw === '' || !preg_match('/^\d+$/', $externalIdRaw)) {
      $rowErrors[] = "ID absent ou non numerique.";
    }
    if ($questionText === '') {
      $rowErrors[] = "Questions vide.";
    }
    if ($theme === '') {
      $rowErrors[] = "Theme question vide.";
    }
    if ($category === '') {
      $rowErrors[] = "Categorie question vide.";
    }
    if ($profile === '') {
      $rowErrors[] = "Profil vide.";
    }
    $knowledgeTokens = parse_knowledge_required($knowledgeRequiredRaw);
    if (!$knowledgeTokens) {
      $rowErrors[] = "Knowledge required invalide (attendu: PONE/PHM/PPM, multi possible).";
    }
    if ($levelRaw === '' || !preg_match('/^-?\d+$/', $levelRaw)) {
      $rowErrors[] = "Niveau question non numerique.";
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

    $allowMulti = count($correctIndexes) > 1 ? 1 : 0;
    if ($isBoolean) {
      if (count($correctIndexes) !== 1) {
        $rowErrors[] = "VRAI/FAUX: une seule bonne reponse autorisee.";
      }
      $questionType = 'TRUE_FALSE';
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
      'theme' => $theme,
      'category' => $category,
      'profile' => $profile,
      'need' => primary_need_from_tokens($knowledgeTokens),
      'knowledge_required_csv' => implode(',', $knowledgeTokens),
      'level' => (int)$levelRaw,
      'question_type' => $questionType,
      'allow_skip' => 1,
      'open_to_client' => $openToClient,
      'explanation' => $explanation !== '' ? $explanation : null,
      'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
      'responses' => $responses,
      'correct_indexes' => array_keys($correctIndexes),
    ];
  }

  return ['prepared' => $prepared, 'report' => $report];
}

function run_import(PDO $pdo, array $prepared, array $report): array {
  $hasOpenToClientColumn = db_column_exists($pdo, 'questions', 'open_to_client');
  $selectQ = $pdo->prepare("SELECT id FROM questions WHERE external_id=? LIMIT 1");
  if ($hasOpenToClientColumn) {
    $insertQ = $pdo->prepare("
      INSERT INTO questions(
        external_id, package_id, text, need, level, question_type, allow_skip,
        knowledge_required_csv, theme, category, profile, open_to_client, explanation, meta_json, created_at, updated_at
      ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
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
        category=?,
        profile=?,
        explanation=?,
        meta_json=?,
        updated_at=NOW()
      WHERE external_id=?
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
        category=?,
        profile=?,
        open_to_client=?,
        explanation=?,
        meta_json=?,
        updated_at=NOW()
      WHERE external_id=?
    ");
  } else {
    $insertQ = $pdo->prepare("
      INSERT INTO questions(
        external_id, package_id, text, need, level, question_type, allow_skip,
        knowledge_required_csv, theme, category, profile, explanation, meta_json, created_at, updated_at
      ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
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
        category=?,
        profile=?,
        explanation=?,
        meta_json=?,
        updated_at=NOW()
      WHERE external_id=?
    ");
    $updateQWithOpen = null;
  }
  $deleteOpts = $pdo->prepare("DELETE FROM question_options WHERE question_id=?");
  $insertOpt = $pdo->prepare("
    INSERT INTO question_options(question_id, label, option_text, is_correct, score_value)
    VALUES(?,?,?,?,?)
  ");
  $labels = ['A', 'B', 'C', 'D', 'E', 'F'];

  foreach ($prepared as $row) {
    $lineNo = (int)$row['line_no'];
    $externalId = (int)$row['external_id'];
    $correctMap = array_fill_keys(array_map('intval', $row['correct_indexes']), true);

    $pdo->beginTransaction();
    try {
      $selectQ->execute([$externalId]);
      $existingQid = $selectQ->fetchColumn();

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
            $row['category'],
            $row['profile'],
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
            $row['category'],
            $row['profile'],
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
            $row['category'],
            $row['profile'],
            $row['open_to_client'],
            $row['explanation'],
            $row['meta_json'],
            $externalId,
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
            $row['category'],
            $row['profile'],
            $row['explanation'],
            $row['meta_json'],
            $externalId,
          ]);
        }
        $report['updated']++;
      }

      $deleteOpts->execute([$qid]);
      for ($i = 1; $i <= 6; $i++) {
        $txt = trim((string)$row['responses'][$i]);
        if ($txt === '') {
          continue;
        }
        $isCorrect = isset($correctMap[$i]) ? 1 : 0;
        $scoreValue = $isCorrect ? 1 : -1;
        $insertOpt->execute([$qid, $labels[$i - 1], $txt, $isCorrect, $scoreValue]);
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

$stateKey = 'question_import_v11';
if (!isset($_SESSION[$stateKey]) || !is_array($_SESSION[$stateKey])) {
  $_SESSION[$stateKey] = [];
}
$state = $_SESSION[$stateKey];

$schemaErrors = [];
foreach (['external_id', 'package_id', 'text', 'need', 'knowledge_required_csv', 'level', 'question_type', 'allow_skip', 'theme', 'category', 'profile', 'explanation', 'meta_json', 'updated_at'] as $col) {
  if (!db_column_exists($pdo, 'questions', $col)) {
    $schemaErrors[] = "Colonne manquante dans questions: $col";
  }
}
if (db_column_exists($pdo, 'questions', 'package_id') && !db_column_nullable($pdo, 'questions', 'package_id')) {
  $schemaErrors[] = "questions.package_id est NOT NULL. Lance la migration import v1.1.";
}

$mappingDefs = mapping_fields();
$mapping = $state['mapping'] ?? [];
$report = null;
$verifyDone = false;
$action = trim((string)($_POST['action'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
      $mapping = auto_map_headers($headers);
      $state = [
        'file_name' => (string)($_FILES['import_file']['name'] ?? ''),
        'rows' => $rows,
        'headers' => $headers,
        'mapping' => $mapping,
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
      $mapping = [];
      foreach ($mappingDefs as $key => $_def) {
        $raw = $_POST['mapping'][$key] ?? '';
        $mapping[$key] = ($raw === '' ? null : (int)$raw);
      }
      $errors = validate_mapping($mapping);
      if ($errors) {
        $report = [
          'read_lines' => 0,
          'created' => 0,
          'updated' => 0,
          'rejected' => 0,
          'errors' => $errors,
        ];
      } else {
        $validation = validate_and_prepare_rows($state['rows'], $mapping);
        $report = $validation['report'];
        $state['mapping'] = $mapping;
        $state['can_import'] = true;
        $_SESSION[$stateKey] = $state;
        $verifyDone = true;
      }
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
      $validationErrors = validate_mapping($mapping);
      if ($validationErrors) {
        $report = [
          'read_lines' => 0,
          'created' => 0,
          'updated' => 0,
          'rejected' => 0,
          'errors' => $validationErrors,
        ];
      } else {
        $validation = validate_and_prepare_rows($state['rows'], $mapping);
        $report = run_import($pdo, $validation['prepared'], $validation['report']);
        $state['can_import'] = false;
        $_SESSION[$stateKey] = $state;
        $verifyDone = true;
      }
    }
  }
}

$state = $_SESSION[$stateKey] ?? [];
$headers = $state['headers'] ?? [];
$mapping = $state['mapping'] ?? $mapping;

?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Import questions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <script src="/assets/theme-toggle.js?v=1" defer></script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1">Admin &middot; Importer des questions</h2>
        <p class="sub">Workflow: fichier -> mapping -> verification -> import</p>
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
          <p class="small">Applique la migration SQL import v1.1 avant de continuer.</p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($report !== null): ?>
      <div class="import-report">
        <div class="import-report-title">Rapport</div>
        <div class="import-report-stats">
          <span class="pill">Lignes lues: <?= (int)$report['read_lines'] ?></span>
          <span class="pill success">Creees: <?= (int)$report['created'] ?></span>
          <span class="pill info">Mises a jour: <?= (int)$report['updated'] ?></span>
          <span class="pill danger">Rejetees: <?= (int)$report['rejected'] ?></span>
        </div>
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
      </div>
    <?php endif; ?>

    <form method="post" class="import-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="load_file">
      <div class="import-fields">
        <div class="import-field import-field-full">
          <label class="label">Fichier (.csv ou .xlsx)</label>
          <input class="input" type="file" name="import_file" accept=".csv,.xlsx" required>
        </div>
      </div>
      <div class="import-actions">
        <button class="btn" type="submit" <?= $schemaErrors ? 'disabled' : '' ?>>Charger le fichier</button>
      </div>
    </form>

    <?php if ($headers): ?>
      <hr class="separator">
      <div class="import-help">
        <div class="import-help-head">
          <span class="import-help-tag">Mapping</span>
          <strong>Associe chaque champ a une colonne du fichier</strong>
        </div>
        <p class="small">Fichier charge: <b><?= h((string)($state['file_name'] ?? '')) ?></b></p>
      </div>

      <form method="post" class="import-form">
        <input type="hidden" name="action" value="verify">
        <div class="import-fields">
          <?php foreach ($mappingDefs as $key => $def): ?>
            <?php $selected = $mapping[$key] ?? null; ?>
            <div class="import-field">
              <label class="label">
                <?= h($def['label']) ?><?= $def['required'] ? ' *' : '' ?>
              </label>
              <select name="mapping[<?= h($key) ?>]">
                <option value="">-- non mappe --</option>
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
          <button class="btn" type="submit" <?= $schemaErrors ? 'disabled' : '' ?>>Verifier les donnees</button>
          <a class="btn ghost" href="/admin/questions.php">Annuler</a>
        </div>
      </form>
    <?php endif; ?>

    <?php if (($verifyDone || ($report !== null && empty($report['errors']))) && !empty($state['can_import'])): ?>
      <form method="post" class="import-form" style="margin-top:14px;">
        <input type="hidden" name="action" value="import">
        <div class="import-actions">
          <button class="btn" type="submit">Importer les lignes valides</button>
        </div>
      </form>
    <?php endif; ?>

    <p class="small import-note">
      *Colonnes obligatoires.<br>
      La verification ne modifie pas la base. A chaque re-import d'un meme <code>ID question</code>, les infos de la question sont remplacées par les nouvelles.
    </p>
  </div>
</div>
<script src="/assets/package-colors.js"></script>
</body>
</html>
