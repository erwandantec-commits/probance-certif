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

function table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (isset($cache[$table])) {
    return $cache[$table];
  }

  $sql = "
    SELECT COUNT(*) AS c
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$table]);
  $cache[$table] = ((int)$st->fetchColumn() > 0);
  return $cache[$table];
}

function table_column_exists(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $cacheKey = $table . ':' . $column;
  if (isset($cache[$cacheKey])) {
    return $cache[$cacheKey];
  }

  $sql = "
    SELECT COUNT(*) AS c
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $column]);
  $cache[$cacheKey] = ((int)$st->fetchColumn() > 0);
  return $cache[$cacheKey];
}

function compute_package_selection_target(array $pkg, int $eligibleCount): int {
  $mode = strtoupper(trim((string)($pkg['selection_mode'] ?? 'COUNT')));
  if ($mode === 'PERCENT') {
    $percent = (int)($pkg['selection_percent'] ?? 0);
    if ($percent < 1) {
      $percent = 1;
    }
    if ($percent > 100) {
      $percent = 100;
    }
    $target = (int)ceil(($eligibleCount * $percent) / 100);
    return max(1, $target);
  }

  $count = (int)($pkg['selection_count'] ?? 0);
  if ($count < 1) {
    $count = 1;
  }
  return $count;
}

function select_questions_for_package(PDO $pdo, array $pkg): array {
  $packageId = (int)($pkg['id'] ?? 0);
  if ($packageId <= 0) {
    return ['ids' => [], 'error_key' => 'start.err.invalid_package'];
  }

  $limit = (int)($pkg['selection_count'] ?? 0);
  if ($limit < 1) {
    $limit = 1;
  }

  $hasNeed = table_column_exists($pdo, 'questions', 'need');
  $hasLevel = table_column_exists($pdo, 'questions', 'level');

  // Legacy bucket rules can drive distribution, but package count stays authoritative.
  $raw = $pkg['selection_rules_json'] ?? null;
  if ($hasNeed && $hasLevel && is_string($raw) && trim($raw) !== '') {
    $rules = json_decode($raw, true);
    if (is_array($rules) && !empty($rules['buckets']) && is_array($rules['buckets'])) {
      // Package settings are authoritative for the total number of questions.
      // Legacy JSON `max` is ignored to keep admin package config effective.
      $max = $limit;

      $qids = [];
      foreach ($rules['buckets'] as $bucket) {
        if (count($qids) >= $max) {
          break;
        }

        $need = strtoupper(trim((string)($bucket['need'] ?? '')));
        $levels = $bucket['levels'] ?? [];
        $take = (int)($bucket['take'] ?? 0);
        $targetTotal = (int)($bucket['target_total'] ?? 0);

        if (!in_array($need, ['PONE', 'PHM', 'PPM'], true) || !is_array($levels) || $take <= 0) {
          continue;
        }

        $levels = array_values(array_unique(array_map('intval', $levels)));
        $levels = array_values(array_filter($levels, fn($x) => $x >= 1 && $x <= 9));
        if (!$levels) {
          continue;
        }

        $remaining = $max - count($qids);
        if ($take > $remaining) {
          $take = $remaining;
        }
        if ($targetTotal > 0) {
          $remainingToTarget = $targetTotal - count($qids);
          if ($remainingToTarget <= 0) {
            continue;
          }
          if ($take > $remainingToTarget) {
            $take = $remainingToTarget;
          }
        }
        if ($take <= 0) {
          continue;
        }

        $inLevels = implode(',', array_fill(0, count($levels), '?'));
        $sql = "
          SELECT q.id
          FROM questions q
          JOIN question_options qo ON qo.question_id = q.id
          WHERE q.need = ?
            AND q.level IN ($inLevels)
        ";
        $params = array_merge([$need], $levels);

        if (!empty($qids)) {
          $inExclude = implode(',', array_fill(0, count($qids), '?'));
          $sql .= " AND q.id NOT IN ($inExclude)";
          $params = array_merge($params, array_map('intval', $qids));
        }

        $sql .= "
          GROUP BY q.id
          HAVING COUNT(qo.id) >= 2
          ORDER BY RAND()
          LIMIT " . (int)$take;

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];
        foreach ($rows as $row) {
          $qids[] = (int)$row['id'];
        }
      }

      if (count($qids) < $max) {
        return ['ids' => [], 'error_key' => 'start.err.not_enough_tagged'];
      }

      if (count($qids) > $max) {
        $qids = array_slice($qids, 0, $max);
      }

      return ['ids' => $qids, 'error_key' => null];
    }
  }

  // Legacy fallback: package-local random selection by selection_count.
  $sql = "
    SELECT q.id
    FROM questions q
    JOIN question_options qo ON qo.question_id = q.id
    WHERE q.package_id = ?
    GROUP BY q.id
    HAVING COUNT(qo.id) >= 2
    ORDER BY RAND()
    LIMIT " . (int)$limit;
  $st = $pdo->prepare($sql);
  $st->execute([$packageId]);
  $rows = $st->fetchAll() ?: [];
  $qids = array_map(fn($r) => (int)$r['id'], $rows);

  if (count($qids) < $limit) {
    return ['ids' => [], 'error_key' => 'start.err.not_enough_package'];
  }

  return ['ids' => $qids, 'error_key' => null];
}

function compute_score_percent_from_raw(int $rawScore, int $maxPoints): float {
  if ($maxPoints <= 0) {
    return 0.0;
  }

  $ratio = $rawScore / $maxPoints;
  if ($ratio < 0) {
    $ratio = 0;
  }
  if ($ratio > 1) {
    $ratio = 1;
  }

  return $ratio * 100.0;
}

function compute_session_score_snapshot(PDO $pdo, string $sessionId): array {
  // Max is the sum of all correct options (+1 each).
  $maxStmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN qo.is_correct=1 THEN 1 ELSE 0 END), 0) AS max_points
    FROM session_questions sq
    JOIN question_options qo ON qo.question_id = sq.question_id
    WHERE sq.session_id=?
  ");
  $maxStmt->execute([$sessionId]);
  $maxPoints = (int)($maxStmt->fetch()['max_points'] ?? 0);

  // Raw score follows business rules aligned with correction feedback:
  // correct = +1, wrong = 0, unanswered = 0.
  $rawStmt = $pdo->prepare("
    SELECT COALESCE(SUM(
      CASE
        WHEN qo.is_correct = 1 THEN 1
        ELSE 0
      END
    ), 0) AS raw_score
    FROM answer_options ao
    JOIN question_options qo ON qo.id = ao.option_id
    WHERE ao.session_id=?
  ");
  $rawStmt->execute([$sessionId]);
  $rawScore = (int)($rawStmt->fetch()['raw_score'] ?? 0);

  $scorePercent = compute_score_percent_from_raw($rawScore, $maxPoints);

  return [
    'raw_score' => $rawScore,
    'max_points' => $maxPoints,
    'score_percent' => $scorePercent,
  ];
}

function refresh_active_session_score(PDO $pdo, string $sessionId): float {
  $snapshot = compute_session_score_snapshot($pdo, $sessionId);
  $scorePercent = round((float)($snapshot['score_percent'] ?? 0.0), 2);
  $pdo->prepare("UPDATE sessions SET score_percent=? WHERE id=? AND status='ACTIVE'")
      ->execute([$scorePercent, $sessionId]);
  return $scorePercent;
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

function mark_session_terminated(
  PDO $pdo,
  string $sessionId,
  float $scorePercent,
  int $passed,
  string $terminationType = 'MANUAL'
): void {
  $hasEndedAt = sessions_column_exists($pdo, 'ended_at');
  $hasTerminationType = sessions_column_exists($pdo, 'termination_type');
  $terminationType = strtoupper(trim($terminationType));
  if (!in_array($terminationType, ['MANUAL', 'TIMEOUT'], true)) {
    $terminationType = 'MANUAL';
  }

  $sets = ["status='TERMINATED'", "submitted_at=NOW()", "score_percent=?", "passed=?"];
  if ($hasEndedAt) {
    $sets[] = "ended_at=NOW()";
  }
  if ($hasTerminationType) {
    $sets[] = "termination_type='" . $terminationType . "'";
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
    'status_label' => 'Certifié',
    'status_class' => 'pill success',
    'expires_at' => $expires,
  ];
}
