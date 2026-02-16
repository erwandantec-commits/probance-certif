<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo "Missing id"; exit; }

$pdo->beginTransaction();
try {
  $pdo->prepare("DELETE FROM question_options WHERE question_id=?")->execute([$id]);
  $pdo->prepare("DELETE FROM questions WHERE id=?")->execute([$id]);
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

header("Location: /admin/questions.php");
exit;
