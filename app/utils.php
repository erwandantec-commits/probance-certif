<?php

function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

function normalize_hex_color(?string $color): ?string {
  if (!is_string($color)) return null;
  $value = strtoupper(trim($color));
  if (!preg_match('/^#[0-9A-F]{6}$/', $value)) {
    return null;
  }
  return $value;
}

function package_color_hex(string $packageName, ?string $customColor = null): string {
  $custom = normalize_hex_color($customColor);
  if ($custom !== null) {
    return $custom;
  }
  $name = strtoupper(trim($packageName));
  return match ($name) {
    'GREEN' => '#16a34a',
    'BLUE' => '#2563eb',
    'RED' => '#dc2626',
    'BLACK' => '#111827',
    'SILVER' => '#64748b',
    'GOLD' => '#d4af37',
    default => '#334155',
  };
}

function package_label_style(string $packageName, ?string $customColor = null): string {
  $name = strtoupper(trim($packageName));
  if ($name === 'BLACK' && normalize_hex_color($customColor) === null) {
    return 'color:var(--pack-black-text,#111827);font-weight:700;';
  }
  return 'color:' . package_color_hex($packageName, $customColor) . ';font-weight:700;';
}

function normalize_question_need(?string $value): string {
  if (!is_string($value)) {
    return '';
  }
  $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  if ($value === '') {
    return '';
  }
  return function_exists('mb_strtoupper')
    ? mb_strtoupper($value, 'UTF-8')
    : strtoupper($value);
}

function parse_question_need_tokens(?string $raw): array {
  if (!is_string($raw)) {
    return [];
  }
  $parts = preg_split('/[;,\|\/]+/', $raw);
  if (!is_array($parts)) {
    return [];
  }

  $tokens = [];
  foreach ($parts as $part) {
    $need = normalize_question_need((string)$part);
    if ($need === '') {
      continue;
    }
    $tokens[$need] = true;
  }

  return array_keys($tokens);
}

function question_known_needs(PDO $pdo, array $seedNeeds = []): array {
  $needs = [];

  foreach ($seedNeeds as $seedNeed) {
    $need = normalize_question_need((string)$seedNeed);
    if ($need !== '') {
      $needs[$need] = true;
    }
  }

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

  $knownNeeds = array_keys($needs);
  natcasesort($knownNeeds);
  return array_values($knownNeeds);
}

function question_default_need(array $knownNeeds, string $preferred = 'PONE'): string {
  $preferred = normalize_question_need($preferred);
  $knownNeeds = array_values(array_filter(array_map(
    static fn($need) => normalize_question_need((string)$need),
    $knownNeeds
  ), static fn($need) => $need !== ''));

  if ($preferred !== '' && in_array($preferred, $knownNeeds, true)) {
    return $preferred;
  }

  return $knownNeeds[0] ?? $preferred;
}

function app_build_url(string $path): string {
  return APP_BASE_URL . '/' . ltrim($path, '/');
}

function question_translation_normalize_lang(?string $lang): string {
  $lang = strtolower(trim((string)$lang));
  if ($lang === 'ja') {
    $lang = 'jp';
  }
  if (!in_array($lang, ['fr', 'en', 'es', 'jp'], true)) {
    $lang = 'fr';
  }
  return $lang;
}

function question_translation_lang_labels(): array {
  return [
    'fr' => 'FR',
    'en' => 'EN',
    'es' => 'ES',
    'jp' => 'JA',
  ];
}

function question_translation_lang_label(string $lang): string {
  $labels = question_translation_lang_labels();
  $lang = question_translation_normalize_lang($lang);
  return $labels[$lang] ?? strtoupper($lang);
}

function question_translation_target_langs(string $sourceLang): array {
  $sourceLang = question_translation_normalize_lang($sourceLang);
  return array_diff_key(question_translation_lang_labels(), [$sourceLang => true]);
}

function question_translation_table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (isset($cache[$table])) {
    return $cache[$table];
  }
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
  $st->execute([$table]);
  $cache[$table] = ((int)$st->fetchColumn() > 0);
  return $cache[$table];
}

function question_translation_column_exists(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table . ':' . $column;
  if (isset($cache[$key])) {
    return $cache[$key];
  }
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $column]);
  $cache[$key] = ((int)$st->fetchColumn() > 0);
  return $cache[$key];
}

function ensure_program_source_language_schema(PDO $pdo): void {
  static $done = false;
  if ($done || !question_translation_table_exists($pdo, 'programs')) {
    return;
  }
  if (!question_translation_column_exists($pdo, 'programs', 'source_lang')) {
    $pdo->exec("ALTER TABLE programs ADD COLUMN source_lang VARCHAR(5) NOT NULL DEFAULT 'fr' AFTER description");
  }
  $done = true;
}

function program_source_lang(PDO $pdo, int $programId): string {
  $programId = max(0, $programId);
  ensure_program_source_language_schema($pdo);
  if ($programId <= 0 || !question_translation_table_exists($pdo, 'programs')) {
    return 'fr';
  }

  $st = $pdo->prepare("SELECT source_lang FROM programs WHERE id = ? LIMIT 1");
  $st->execute([$programId]);
  return question_translation_normalize_lang((string)($st->fetchColumn() ?: 'fr'));
}

function question_program_source_lang(PDO $pdo, int $questionId): string {
  $questionId = max(0, $questionId);
  if ($questionId <= 0 || !question_translation_table_exists($pdo, 'program_question_links')) {
    return 'fr';
  }

  $st = $pdo->prepare("
    SELECT program_id
    FROM program_question_links
    WHERE question_id = ?
    ORDER BY program_id ASC
    LIMIT 1
  ");
  $st->execute([$questionId]);
  return program_source_lang($pdo, (int)($st->fetchColumn() ?: 0));
}

function package_program_source_lang(PDO $pdo, int $packageId, int $preferredProgramId = 0): string {
  $packageId = max(0, $packageId);
  $preferredProgramId = max(0, $preferredProgramId);
  if ($preferredProgramId > 0) {
    return program_source_lang($pdo, $preferredProgramId);
  }
  if ($packageId <= 0) {
    return 'fr';
  }

  if (function_exists('auth_package_program_ids')) {
    $programIds = auth_package_program_ids($pdo, $packageId, true);
    return program_source_lang($pdo, (int)($programIds[0] ?? 0));
  }
  if (question_translation_table_exists($pdo, 'program_package_links')) {
    $st = $pdo->prepare("
      SELECT program_id
      FROM program_package_links
      WHERE package_id = ?
        AND is_active = 1
      ORDER BY program_id ASC
      LIMIT 1
    ");
    $st->execute([$packageId]);
    return program_source_lang($pdo, (int)($st->fetchColumn() ?: 0));
  }
  if (question_translation_column_exists($pdo, 'packages', 'program_id')) {
    $st = $pdo->prepare("SELECT program_id FROM packages WHERE id = ? LIMIT 1");
    $st->execute([$packageId]);
    return program_source_lang($pdo, (int)($st->fetchColumn() ?: 0));
  }

  return 'fr';
}

function ensure_question_translation_schema(PDO $pdo): void {
  static $done = false;
  if ($done) {
    return;
  }

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS question_translations (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      question_id INT NOT NULL,
      lang VARCHAR(5) NOT NULL,
      question_text TEXT NOT NULL,
      explanation TEXT NULL,
      source_updated_at DATETIME NULL,
      status_override VARCHAR(16) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_question_lang (question_id, lang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS question_option_translations (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      option_id INT NOT NULL,
      lang VARCHAR(5) NOT NULL,
      option_text TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_option_lang (option_id, lang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  if (!question_translation_column_exists($pdo, 'question_translations', 'source_updated_at')) {
    $pdo->exec("ALTER TABLE question_translations ADD COLUMN source_updated_at DATETIME NULL AFTER explanation");
  }
  if (!question_translation_column_exists($pdo, 'question_translations', 'status_override')) {
    $pdo->exec("ALTER TABLE question_translations ADD COLUMN status_override VARCHAR(16) NULL AFTER source_updated_at");
  }

  $done = true;
}

function question_translations_available(PDO $pdo): bool {
  ensure_question_translation_schema($pdo);
  return question_translation_table_exists($pdo, 'question_translations')
    && question_translation_table_exists($pdo, 'question_option_translations');
}

function translated_question_field(PDO $pdo, int $questionId, string $lang, string $field, ?string $fallback = null, ?string $sourceLang = null): string {
  $fallbackValue = trim((string)$fallback);
  if ($questionId <= 0) {
    return $fallbackValue;
  }

  $lang = question_translation_normalize_lang($lang);
  $sourceLang = question_translation_normalize_lang($sourceLang ?? question_program_source_lang($pdo, $questionId));
  if ($lang === $sourceLang || !question_translations_available($pdo)) {
    return $fallbackValue;
  }

  if (!in_array($field, ['question_text', 'explanation'], true)) {
    return $fallbackValue;
  }

  static $cache = [];
  $cacheKey = $questionId . ':' . $lang;
  if (!array_key_exists($cacheKey, $cache)) {
    $st = $pdo->prepare("
      SELECT question_text, explanation
      FROM question_translations
      WHERE question_id = ?
        AND lang = ?
      LIMIT 1
    ");
    $st->execute([$questionId, $lang]);
    $cache[$cacheKey] = $st->fetch() ?: null;
  }

  $row = $cache[$cacheKey];
  if (!is_array($row)) {
    return $fallbackValue;
  }

  $value = trim((string)($row[$field] ?? ''));
  return $value !== '' ? $value : $fallbackValue;
}

function translated_option_text(PDO $pdo, int $optionId, string $lang, ?string $fallback = null, ?string $sourceLang = null): string {
  $fallbackValue = trim((string)$fallback);
  if ($optionId <= 0) {
    return $fallbackValue;
  }

  $lang = question_translation_normalize_lang($lang);
  if ($sourceLang === null) {
    $questionStmt = $pdo->prepare("SELECT question_id FROM question_options WHERE id = ? LIMIT 1");
    $questionStmt->execute([$optionId]);
    $sourceLang = question_program_source_lang($pdo, (int)($questionStmt->fetchColumn() ?: 0));
  }
  $sourceLang = question_translation_normalize_lang($sourceLang);
  if ($lang === $sourceLang || !question_translations_available($pdo)) {
    return $fallbackValue;
  }

  static $cache = [];
  $cacheKey = $optionId . ':' . $lang;
  if (!array_key_exists($cacheKey, $cache)) {
    $st = $pdo->prepare("
      SELECT option_text
      FROM question_option_translations
      WHERE option_id = ?
        AND lang = ?
      LIMIT 1
    ");
    $st->execute([$optionId, $lang]);
    $cache[$cacheKey] = $st->fetchColumn();
  }

  $value = trim((string)($cache[$cacheKey] ?? ''));
  return $value !== '' ? $value : $fallbackValue;
}

function question_translation_missing_details(PDO $pdo, array $questionIds, string $lang, ?string $sourceLang = null): array {
  $lang = question_translation_normalize_lang($lang);
  $sourceLang = question_translation_normalize_lang($sourceLang ?? 'fr');
  $questionIds = array_values(array_unique(array_map('intval', $questionIds)));
  $questionIds = array_values(array_filter($questionIds, static fn($id) => $id > 0));

  if ($lang === $sourceLang || $questionIds === []) {
    return [];
  }

  if (!question_translations_available($pdo)) {
    $details = [];
    foreach ($questionIds as $questionId) {
      $details[$questionId] = ['question_text', 'explanation', 'options'];
    }
    return $details;
  }

  $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

  $questionStmt = $pdo->prepare("
    SELECT q.id, q.explanation AS source_explanation, qt.question_text, qt.explanation
    FROM questions q
    LEFT JOIN question_translations qt
      ON qt.question_id = q.id
     AND qt.lang = ?
    WHERE q.id IN ($placeholders)
  ");
  $questionStmt->execute(array_merge([$lang], $questionIds));
  $questionRows = $questionStmt->fetchAll() ?: [];
  $questionMap = [];
  foreach ($questionRows as $row) {
    $questionMap[(int)$row['id']] = $row;
  }

  $optionStmt = $pdo->prepare("
    SELECT
      qo.question_id,
      COUNT(*) AS option_count,
      SUM(CASE WHEN qot.option_id IS NOT NULL AND TRIM(qot.option_text) <> '' THEN 1 ELSE 0 END) AS translated_count
    FROM question_options qo
    LEFT JOIN question_option_translations qot
      ON qot.option_id = qo.id
     AND qot.lang = ?
    WHERE qo.question_id IN ($placeholders)
    GROUP BY qo.question_id
  ");
  $optionStmt->execute(array_merge([$lang], $questionIds));
  $optionRows = $optionStmt->fetchAll() ?: [];
  $optionMap = [];
  foreach ($optionRows as $row) {
    $optionMap[(int)$row['question_id']] = $row;
  }

  $missing = [];
  foreach ($questionIds as $questionId) {
    $row = $questionMap[$questionId] ?? null;
    $itemMissing = [];
    if (!$row || trim((string)($row['question_text'] ?? '')) === '') {
      $itemMissing[] = 'question_text';
    }
    $sourceExplanation = trim((string)($row['source_explanation'] ?? ''));
    if ($sourceExplanation !== '' && (!$row || trim((string)($row['explanation'] ?? '')) === '')) {
      $itemMissing[] = 'explanation';
    }
    $optionInfo = $optionMap[$questionId] ?? null;
    $optionCount = (int)($optionInfo['option_count'] ?? 0);
    $translatedCount = (int)($optionInfo['translated_count'] ?? 0);
    if ($optionCount > 0 && $translatedCount < $optionCount) {
      $itemMissing[] = 'options';
    }
    if ($itemMissing !== []) {
      $missing[$questionId] = $itemMissing;
    }
  }

  return $missing;
}

function questions_have_complete_translation(PDO $pdo, array $questionIds, string $lang, ?string $sourceLang = null): bool {
  return question_translation_missing_details($pdo, $questionIds, $lang, $sourceLang) === [];
}

function question_translation_status(PDO $pdo, int $questionId, string $lang, ?string $sourceLang = null): string {
  $lang = question_translation_normalize_lang($lang);
  if ($questionId <= 0) {
    return 'missing';
  }
  $sourceLang = question_translation_normalize_lang($sourceLang ?? question_program_source_lang($pdo, $questionId));
  if ($lang === $sourceLang) {
    return 'complete';
  }
  if (!question_translations_available($pdo)) {
    return 'missing';
  }

  $st = $pdo->prepare("
    SELECT q.updated_at, q.explanation AS source_explanation, qt.question_text, qt.explanation, qt.source_updated_at, qt.status_override
    FROM questions q
    LEFT JOIN question_translations qt
      ON qt.question_id = q.id
     AND qt.lang = ?
    WHERE q.id = ?
    LIMIT 1
  ");
  $st->execute([$lang, $questionId]);
  $row = $st->fetch();
  if (!$row) {
    return 'missing';
  }

  $statusOverride = trim((string)($row['status_override'] ?? ''));
  if (in_array($statusOverride, ['complete', 'stale'], true)) {
    return $statusOverride;
  }

  $questionText = trim((string)($row['question_text'] ?? ''));
  if ($questionText === '') {
    return 'missing';
  }

  $optionSt = $pdo->prepare("
    SELECT
      COUNT(*) AS option_count,
      SUM(CASE WHEN qot.option_id IS NOT NULL AND TRIM(qot.option_text) <> '' THEN 1 ELSE 0 END) AS translated_count
    FROM question_options qo
    LEFT JOIN question_option_translations qot
      ON qot.option_id = qo.id
     AND qot.lang = ?
    WHERE qo.question_id = ?
  ");
  $optionSt->execute([$lang, $questionId]);
  $optionInfo = $optionSt->fetch() ?: ['option_count' => 0, 'translated_count' => 0];
  $optionCount = (int)($optionInfo['option_count'] ?? 0);
  $translatedCount = (int)($optionInfo['translated_count'] ?? 0);
  $hasAllOptions = ($optionCount > 0 && $translatedCount === $optionCount);
  $sourceExplanation = trim((string)($row['source_explanation'] ?? ''));
  $hasExplanation = ($sourceExplanation === '') || trim((string)($row['explanation'] ?? '')) !== '';
  $hasSourceSnapshot = trim((string)($row['source_updated_at'] ?? '')) !== '';

  if (!$hasAllOptions || !$hasExplanation) {
    return 'partial';
  }

  $sourceUpdatedAt = trim((string)($row['source_updated_at'] ?? ''));
  $questionUpdatedAt = trim((string)($row['updated_at'] ?? ''));
  if (!$hasSourceSnapshot || ($questionUpdatedAt !== '' && $sourceUpdatedAt !== '' && strtotime($sourceUpdatedAt) < strtotime($questionUpdatedAt))) {
    return 'stale';
  }

  return 'complete';
}

function mail_header_encode(string $value): string {
  if ($value === '' || preg_match('/^[\x20-\x7E]+$/', $value)) {
    return $value;
  }
  return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_normalize_email(string $email): string {
  $email = trim($email);
  if ($email === '' || preg_match('/[\r\n]/', $email)) {
    return '';
  }
  return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function smtp_read_response($socket): array {
  $message = '';
  $code = 0;

  while (($line = fgets($socket, 515)) !== false) {
    $message .= $line;
    if (preg_match('/^(\d{3})([ -])/', $line, $matches)) {
      $code = (int)$matches[1];
      if ($matches[2] === ' ') {
        break;
      }
    } else {
      break;
    }
  }

  return [$code, trim($message)];
}

function smtp_expect($socket, array $expectedCodes, string $context): void {
  [$code, $message] = smtp_read_response($socket);
  if (!in_array($code, $expectedCodes, true)) {
    throw new RuntimeException($context . ' failed: ' . ($message !== '' ? $message : 'no SMTP response'));
  }
}

function smtp_write_line($socket, string $line): void {
  fwrite($socket, $line . "\r\n");
}

function smtp_send_mail(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null): bool {
  $fromEmail = smtp_normalize_email(SMTP_FROM_EMAIL);
  $toEmail = smtp_normalize_email($toEmail);

  if ($fromEmail === '' || $toEmail === '' || SMTP_HOST === '' || SMTP_PORT <= 0) {
    return false;
  }

  if (SMTP_USE_TLS) {
    error_log('[smtp] TLS is requested but not supported by this mailer.');
    return false;
  }

  if (SMTP_USERNAME !== '' || SMTP_PASSWORD !== '') {
    error_log('[smtp] SMTP authentication is configured but not supported by this mailer.');
    return false;
  }

  $socket = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 10.0);
  if (!is_resource($socket)) {
    error_log(sprintf('[smtp] Connection to %s:%d failed: %s (%d)', SMTP_HOST, SMTP_PORT, $errstr, $errno));
    return false;
  }

  stream_set_timeout($socket, 10);

  try {
    smtp_expect($socket, [220], 'SMTP greeting');
    smtp_write_line($socket, 'HELO certif.local');
    smtp_expect($socket, [250], 'HELO');
    smtp_write_line($socket, 'MAIL FROM:<' . $fromEmail . '>');
    smtp_expect($socket, [250], 'MAIL FROM');
    smtp_write_line($socket, 'RCPT TO:<' . $toEmail . '>');
    smtp_expect($socket, [250, 251], 'RCPT TO');
    smtp_write_line($socket, 'DATA');
    smtp_expect($socket, [354], 'DATA');

    $fromHeader = SMTP_FROM_NAME !== ''
      ? mail_header_encode(SMTP_FROM_NAME) . ' <' . $fromEmail . '>'
      : $fromEmail;
    $headers = [
      'Date: ' . gmdate('D, d M Y H:i:s O'),
      'From: ' . $fromHeader,
      'To: <' . $toEmail . '>',
      'Subject: ' . mail_header_encode($subject),
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'Content-Transfer-Encoding: 8bit',
    ];

    $body = str_replace(["\r\n", "\r"], "\n", $htmlBody);
    $bodyLines = explode("\n", $body);
    foreach ($bodyLines as &$line) {
      if (str_starts_with($line, '.')) {
        $line = '.' . $line;
      }
    }
    unset($line);

    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $bodyLines) . "\r\n.\r\n");
    smtp_expect($socket, [250], 'Message body');
    smtp_write_line($socket, 'QUIT');
    smtp_expect($socket, [221], 'QUIT');
    fclose($socket);
    return true;
  } catch (Throwable $e) {
    error_log('[smtp] ' . $e->getMessage());
    fclose($socket);
    return false;
  }
}


// ==========================
// QCM ENGINE
// ==========================

/**
 * Validate user selection for a question.
 * $question must contain:
 * - question_type: SINGLE|MULTI|TRUE_FALSE
 * - allow_skip: bool (optional, default false)
 * - options: array of ['id'=>int, ...]
 */
function validateAnswerSelection(array $question, array $selectedOptionIds): array {

  $allowSkip = $question['allow_skip'] ?? false;

  // sanitize ids (array may contain strings)
  $selectedOptionIds = array_values(array_unique(array_map('intval', $selectedOptionIds)));

  // skip
  if (count($selectedOptionIds) === 0) {
    return $allowSkip
      ? ['ok' => true, 'error' => null]
      : ['ok' => false, 'error' => 'Réponse obligatoire.'];
  }

  // ensure selected options belong to this question
  $optionIds = array_map(fn($o) => (int)$o['id'], $question['options'] ?? []);
  $optionIdSet = array_flip($optionIds);

  foreach ($selectedOptionIds as $oid) {
    if (!isset($optionIdSet[$oid])) {
      return ['ok' => false, 'error' => 'Option invalide.'];
    }
  }

  $type = $question['question_type'] ?? 'SINGLE';

  if (($type === 'SINGLE' || $type === 'TRUE_FALSE') && count($selectedOptionIds) > 1) {
    return ['ok' => false, 'error' => 'Une seule réponse possible.'];
  }

  if (count($selectedOptionIds) > 6) {
    return ['ok' => false, 'error' => 'Trop de réponses sélectionnées.'];
  }

  return ['ok' => true, 'error' => null];
}


/**
 * Compute score:
 * - exact match only:
 *   all correct options selected and no wrong option => +1
 *   otherwise => 0
 */
function computeScore(array $question, array $selectedOptionIds): int {

  return isPerfectAnswer($question, $selectedOptionIds) ? 1 : 0;
}


/**
 * Optional helper: exact match (all and only correct options selected)
 */
function isPerfectAnswer(array $question, array $selectedOptionIds): bool {
  $selectedOptionIds = array_values(array_unique(array_map('intval', $selectedOptionIds)));

  $correctIds = [];
  foreach (($question['options'] ?? []) as $opt) {
    if (!empty($opt['is_correct'])) $correctIds[] = (int)$opt['id'];
  }

  sort($correctIds);
  sort($selectedOptionIds);

  return $selectedOptionIds === $correctIds;
}

function app_markdown_inline(string $text): string {
  $escaped = h($text);
  $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped);
  $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);
  return (string)$escaped;
}

function app_markdown_slugify(string $title): string {
  $slug = strtolower(trim($title));
  $slug = preg_replace('/[^[:alnum:]]+/u', '-', $slug);
  return trim((string)$slug, '-');
}

function app_markdown_to_html(string $markdown): string {
  $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
  $lines = explode("\n", $markdown);
  $html = [];
  $paragraph = [];
  $listItems = [];
  $listType = '';

  $flushParagraph = static function () use (&$paragraph, &$html): void {
    if ($paragraph === []) {
      return;
    }
    $text = trim(implode(' ', $paragraph));
    if ($text !== '') {
      $html[] = '<p>' . app_markdown_inline($text) . '</p>';
    }
    $paragraph = [];
  };

  $flushList = static function () use (&$listItems, &$listType, &$html): void {
    if ($listItems === [] || $listType === '') {
      $listItems = [];
      $listType = '';
      return;
    }
    $html[] = '<' . $listType . '>';
    foreach ($listItems as $item) {
      $html[] = '<li>' . app_markdown_inline($item) . '</li>';
    }
    $html[] = '</' . $listType . '>';
    $listItems = [];
    $listType = '';
  };

  foreach ($lines as $line) {
    $trimmed = trim($line);

    if ($trimmed === '') {
      $flushParagraph();
      $flushList();
      continue;
    }

    if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $matches)) {
      $flushParagraph();
      $flushList();
      $level = strlen($matches[1]) + 1;
      $level = min(4, max(2, $level));
      $title = trim($matches[2]);
      $slug = app_markdown_slugify($title);
      $idAttr = $slug !== '' ? ' id="' . h($slug) . '"' : '';
      $html[] = '<h' . $level . $idAttr . '>' . app_markdown_inline($title) . '</h' . $level . '>';
      continue;
    }

    if (preg_match('/^-\s+(.*)$/', $trimmed, $matches)) {
      $flushParagraph();
      if ($listType !== '' && $listType !== 'ul') {
        $flushList();
      }
      $listType = 'ul';
      $listItems[] = trim($matches[1]);
      continue;
    }

    if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $matches)) {
      $flushParagraph();
      if ($listType !== '' && $listType !== 'ol') {
        $flushList();
      }
      $listType = 'ol';
      $listItems[] = trim($matches[1]);
      continue;
    }

    $flushList();
    $paragraph[] = $trimmed;
  }

  $flushParagraph();
  $flushList();

  return implode("\n", $html);
}
