<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/session_service.php';

$user = require_auth();
$lang = get_lang();
$uid = (int)$user['id'];
$email = $user['email'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: /dashboard.php?lang=" . urlencode($lang));
  exit;
}

$pdo = db();
$package_id = (int)($_POST['package_id'] ?? 0);

if ($package_id <= 0) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.invalid_package'));
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1");
$stmt->execute([$package_id]);
$pkg = $stmt->fetch();

if (!$pkg) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.package_not_found'));
  exit;
}

$selection = select_questions_for_package($pdo, $pkg);
$qids = $selection['ids'] ?? [];
if (($selection['error_key'] ?? null) !== null) {
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode((string)$selection['error_key']));
  exit;
}

$pdo->beginTransaction();
try {
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

  create_session_record($pdo, $session_id, $contact_id, $uid, $package_id, 'EXAM', $lang);

  $pos = 1;
  $insq = $pdo->prepare("INSERT INTO session_questions(session_id, question_id, position) VALUES(?,?,?)");
  foreach ($qids as $qid) {
    $insq->execute([$session_id, (int)$qid, $pos++]);
  }

  $pdo->commit();

  header("Location: /exam.php?sid=" . urlencode($session_id) . "&p=1&lang=" . urlencode($lang));
  exit;
} catch (Throwable $e) {
  $pdo->rollBack();
  header("Location: /dashboard.php?lang=" . urlencode($lang) . "&err_key=" . urlencode('start.err.create_failed'));
  exit;
}
