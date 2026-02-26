<?php

function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

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
  return 'color:' . package_color_hex($packageName, $customColor) . ';font-weight:700;';
}


// ==========================
// QCM ENGINE
// ==========================

/**
 * Validate user selection for a question.
 * $question must contain:
 * - question_type: SINGLE|MULTI|TRUE_FALSE
 * - allow_skip: bool (optional, default true)
 * - options: array of ['id'=>int, ...]
 */
function validateAnswerSelection(array $question, array $selectedOptionIds): array {

  $allowSkip = $question['allow_skip'] ?? true;

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
 * - sum by selected options with fixed policy:
 *   correct => +1, incorrect => 0
 * - skip => 0
 */
function computeScore(array $question, array $selectedOptionIds): int {

  if (count($selectedOptionIds) === 0) return 0;

  $selectedOptionIds = array_values(array_unique(array_map('intval', $selectedOptionIds)));

  // Build score map option_id => points
  $scoreMap = [];
  foreach (($question['options'] ?? []) as $opt) {
    $id = (int)($opt['id'] ?? 0);
    if ($id <= 0) continue;

    $scoreMap[$id] = !empty($opt['is_correct']) ? 1 : 0;
  }

  $score = 0;
  foreach ($selectedOptionIds as $oid) {
    $score += $scoreMap[$oid] ?? 0;
  }

  return $score;
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
