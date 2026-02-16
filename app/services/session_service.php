<?php

function sessions_column_exists(PDO $pdo, string $column): bool {
  static $cache = [];
  if (isset($cache[$column])) {
    return $cache[$column];
  }

  $sql = "
    SELECT COUNT(*) AS c
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sessions'
      AND COLUMN_NAME = ?
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$column]);
  $cache[$column] = ((int)$st->fetchColumn() > 0);
  return $cache[$column];
}

function create_session_record(
  PDO $pdo,
  string $sessionId,
  int $contactId,
  int $userId,
  int $packageId,
  string $sessionType,
  string $language
): void {
  $hasLanguage = sessions_column_exists($pdo, 'language');

  if ($hasLanguage) {
    $sql = "
      INSERT INTO sessions(id, contact_id, user_id, package_id, session_type, language)
      VALUES(?,?,?,?,?,?)
    ";
    $pdo->prepare($sql)->execute([$sessionId, $contactId, $userId, $packageId, $sessionType, $language]);
    return;
  }

  $sql = "
    INSERT INTO sessions(id, contact_id, user_id, package_id, session_type)
    VALUES(?,?,?,?,?)
  ";
  $pdo->prepare($sql)->execute([$sessionId, $contactId, $userId, $packageId, $sessionType]);
}

function mark_session_terminated(PDO $pdo, string $sessionId, float $scorePercent, int $passed): void {
  $hasEndedAt = sessions_column_exists($pdo, 'ended_at');
  $hasTerminationType = sessions_column_exists($pdo, 'termination_type');

  $sets = ["status='TERMINATED'", "submitted_at=NOW()", "score_percent=?", "passed=?"];
  if ($hasEndedAt) {
    $sets[] = "ended_at=NOW()";
  }
  if ($hasTerminationType) {
    $sets[] = "termination_type='MANUAL'";
  }

  $sql = "UPDATE sessions SET " . implode(', ', $sets) . " WHERE id=?";
  $pdo->prepare($sql)->execute([$scorePercent, $passed, $sessionId]);
}

function mark_session_expired(PDO $pdo, string $sessionId): void {
  $hasEndedAt = sessions_column_exists($pdo, 'ended_at');
  $hasTerminationType = sessions_column_exists($pdo, 'termination_type');

  $sets = ["status='EXPIRED'"];
  if ($hasEndedAt) {
    $sets[] = "ended_at=NOW()";
  }
  if ($hasTerminationType) {
    $sets[] = "termination_type='TIMEOUT'";
  }

  $sql = "UPDATE sessions SET " . implode(', ', $sets) . " WHERE id=? AND status='ACTIVE'";
  $pdo->prepare($sql)->execute([$sessionId]);
}

function session_is_expired(array $session, ?DateTimeImmutable $now = null): bool {
  if (($session['status'] ?? '') !== 'ACTIVE') {
    return false;
  }

  $startedAt = (string)($session['started_at'] ?? '');
  if ($startedAt === '') {
    return false;
  }

  $limitMinutes = (int)($session['duration_limit_minutes'] ?? 120);
  if ($limitMinutes < 1) {
    $limitMinutes = 1;
  }

  $started = new DateTimeImmutable($startedAt);
  $expires = $started->modify("+{$limitMinutes} minutes");
  $now = $now ?: new DateTimeImmutable('now');

  return $now > $expires;
}

function certification_status_from_last_success(?string $lastSuccessAt, ?DateTimeImmutable $now = null): array {
  if ($lastSuccessAt === null || trim($lastSuccessAt) === '') {
    return [
      'status_key' => 'NONE',
      'status_label' => 'Aucune',
      'status_class' => 'pill',
      'expires_at' => null,
    ];
  }

  $now = $now ?: new DateTimeImmutable('today');
  $last = new DateTimeImmutable($lastSuccessAt);
  $expires = $last->modify('+1 year');
  $soonLimit = $now->modify('+30 days');

  if ($expires < $now) {
    return [
      'status_key' => 'EXPIRED',
      'status_label' => 'Expire',
      'status_class' => 'pill danger',
      'expires_at' => $expires,
    ];
  }

  if ($expires <= $soonLimit) {
    return [
      'status_key' => 'SOON',
      'status_label' => 'Expire bientot',
      'status_class' => 'pill warning',
      'expires_at' => $expires,
    ];
  }

  return [
    'status_key' => 'CERTIFIED',
    'status_label' => 'Certifie',
    'status_class' => 'pill success',
    'expires_at' => $expires,
  ];
}
