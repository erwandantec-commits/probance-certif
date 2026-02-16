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

// Pick eligible questions (>=2 options)
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
$qids = $q->fetchAll();

if (count($qids) < $limit) {
  header("Location: /dashboard.php?err=" . urlencode("Pas assez de questions pour ce package"));
  exit;
}

// Create session
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
  foreach ($qids as $row) {
    $insq->execute([$session_id, (int)$row['id'], $pos++]);
  }

  $pdo->commit();

  header("Location: /exam.php?sid=" . urlencode($session_id) . "&p=1");
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  header("Location: /dashboard.php?err=" . urlencode("Erreur création session"));
  exit;
}
