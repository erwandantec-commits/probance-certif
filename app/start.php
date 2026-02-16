<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';

$user = require_auth();
$uid = (int)$user['id'];
$email = $user['email'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: /dashboard.php");
  exit;
}

$pdo = db();
$package_id = (int)($_POST['package_id'] ?? 0);

if ($package_id <= 0) {
  header("Location: /dashboard.php?err=" . urlencode("Package invalide"));
  exit;
}

// Load package
$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1");
$stmt->execute([$package_id]);
$pkg = $stmt->fetch();

if (!$pkg) {
  header("Location: /dashboard.php?err=" . urlencode("Package introuvable"));
  exit;
}

$limit = (int)$pkg['selection_count'];
if ($limit < 1) $limit = 1;

$rules = null;
$rulesRaw = $pkg['selection_rules_json'] ?? null;
if (is_string($rulesRaw) && trim($rulesRaw) !== '') {
  $tmp = json_decode($rulesRaw, true);
  if (is_array($tmp)) $rules = $tmp;
}

// --------------------------
// Select questions (2 modes)
// --------------------------

$qids = []; // array of question ids (ints)

// Helper: fetch random question ids by filters, excluding already selected
$pickByNeedLevel = function(string $need, array $levels, int $take, array $excludeIds) use ($pdo) : array {
  if ($take <= 0) return [];

  // sanitize levels -> ints
  $levels = array_values(array_unique(array_map('intval', $levels)));
  $levels = array_filter($levels, fn($x) => $x >= 1 && $x <= 9);
  if (!$levels) return [];

  $placeLevels = implode(',', array_fill(0, count($levels), '?'));

  $sql = "
    SELECT q.id
    FROM questions q
    JOIN question_options qo ON qo.question_id = q.id
    WHERE q.need = ?
      AND q.level IN ($placeLevels)
    GROUP BY q.id
    HAVING COUNT(qo.id) >= 2
  ";

  $params = array_merge([$need], $levels);

  if (!empty($excludeIds)) {
    $placeEx = implode(',', array_fill(0, count($excludeIds), '?'));
    $sql .= " AND q.id NOT IN ($placeEx) ";
    $params = array_merge($params, array_map('intval', $excludeIds));
  }

  $sql .= " ORDER BY RAND() LIMIT " . (int)$take;

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
  return array_map(fn($r) => (int)$r['id'], $rows ?: []);
};

if ($rules && !empty($rules['buckets']) && is_array($rules['buckets'])) {
  // New mode: use recipe (need/level buckets)
  $max = isset($rules['max']) ? (int)$rules['max'] : $limit;
  if ($max < 1) $max = $limit;

  foreach ($rules['buckets'] as $b) {
    if (count($qids) >= $max) break;

    $need = strtoupper(trim((string)($b['need'] ?? '')));
    $levels = $b['levels'] ?? [];
    $take = (int)($b['take'] ?? 0);

    if (!in_array($need, ['PONE','PHM','PPM'], true)) continue;
    if (!is_array($levels)) $levels = [];
    if ($take <= 0) continue;

    $remaining = $max - count($qids);
    if ($take > $remaining) $take = $remaining;

    $picked = $pickByNeedLevel($need, $levels, $take, $qids);
    foreach ($picked as $id) {
      $qids[] = $id;
    }
  }

  // If recipe didn't reach max, fail with clear error
  if (count($qids) < $max) {
    header("Location: /dashboard.php?err=" . urlencode("Pas assez de questions taguées (need/level) pour générer cette certification"));
    exit;
  }

  // Ensure we use $max count
  if (count($qids) > $max) {
    $qids = array_slice($qids, 0, $max);
  }

  $limit = $max; // for downstream checks
} else {
  // Legacy mode: questions attached to package_id (your current behavior)
  $q = $pdo->prepare("
    SELECT q.id
    FROM questions q
    JOIN question_options qo ON qo.question_id = q.id
    WHERE q.package_id=?
    GROUP BY q.id
    HAVING COUNT(qo.id) >= 2
    ORDER BY RAND()
    LIMIT $limit
  ");
  $q->execute([$package_id]);
  $rows = $q->fetchAll();
  $qids = array_map(fn($r) => (int)$r['id'], $rows ?: []);

  if (count($qids) < $limit) {
    header("Location: /dashboard.php?err=" . urlencode("Pas assez de questions pour ce package"));
    exit;
  }
}

// --------------------------
// Create session
// --------------------------
$pdo->beginTransaction();
try {
  // upsert contact by user email
  $stmt = $pdo->prepare("SELECT id FROM contacts WHERE email=?");
  $stmt->execute([$email]);
  $contact = $stmt->fetch();

  if (!$contact) {
    $ins = $pdo->prepare("INSERT INTO contacts(email) VALUES(?)");
    $ins->execute([$email]);
    $contact_id = (int)$pdo->lastInsertId();
  } else {
    $contact_id = (int)$contact['id'];
  }

  $session_id = uuidv4();

  $ins = $pdo->prepare("
    INSERT INTO sessions(id, contact_id, user_id, package_id, session_type)
    VALUES(?,?,?,?,'EXAM')
  ");
  $ins->execute([$session_id, $contact_id, $uid, $package_id]);

  $pos = 1;
  $insq = $pdo->prepare("INSERT INTO session_questions(session_id, question_id, position) VALUES(?,?,?)");
  foreach ($qids as $qid) {
    $insq->execute([$session_id, (int)$qid, $pos++]);
  }

  $pdo->commit();

  header("Location: /exam.php?sid=" . urlencode($session_id) . "&p=1");
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  header("Location: /dashboard.php?err=" . urlencode("Erreur création session"));
  exit;
}
