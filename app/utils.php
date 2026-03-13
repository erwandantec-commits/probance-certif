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

function app_build_url(string $path): string {
  return APP_BASE_URL . '/' . ltrim($path, '/');
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
