<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin_area();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$activeProgramId = auth_admin_program_context($pdo, $adminUser, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
if ($id <= 0) { http_response_code(400); echo "Missing id"; exit; }

if ($activeProgramId > 0) {
  $scopeSql = auth_program_question_links_enabled($pdo)
    ? auth_program_question_scope_sql($pdo, $activeProgramId, 'q')
    : "(q.package_id IS NULL OR " . auth_program_package_scope_sql($pdo, $activeProgramId, 'p', false) . ")";
  $scopeStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM questions q
    LEFT JOIN packages p ON p.id = q.package_id
    WHERE q.id = ?
      AND $scopeSql
  ");
  $scopeStmt->execute([$id]);
  if ((int)$scopeStmt->fetchColumn() <= 0) {
    http_response_code(404);
    echo "Question not found";
    exit;
  }
}

$pdo->beginTransaction();
try {
  if (auth_table_exists($pdo, 'question_option_translations')) {
    $pdo->prepare("
      DELETE qot
      FROM question_option_translations qot
      JOIN question_options qo ON qo.id = qot.option_id
      WHERE qo.question_id = ?
    ")->execute([$id]);
  }
  if (auth_table_exists($pdo, 'question_translations')) {
    $pdo->prepare("DELETE FROM question_translations WHERE question_id=?")->execute([$id]);
  }
  if (auth_program_question_links_enabled($pdo)) {
    $pdo->prepare("DELETE FROM program_question_links WHERE question_id=?")->execute([$id]);
  }
  $pdo->prepare("DELETE FROM question_options WHERE question_id=?")->execute([$id]);
  $pdo->prepare("DELETE FROM questions WHERE id=?")->execute([$id]);
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

header("Location: /admin/questions.php" . ($activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId : ''));
exit;
