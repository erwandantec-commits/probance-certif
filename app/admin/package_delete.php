<?php
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin_area();
require_once __DIR__ . '/../db.php';

$pdo = db();
$activeProgramId = auth_admin_program_context($pdo, $adminUser, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: /admin/packages.php' . ($activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId . '&' : '?') . 'delete_error=' . urlencode('Identifiant de pack invalide.'));
  exit;
}

try {
  $stmt = $pdo->prepare("SELECT id, program_id FROM packages WHERE id=?");
  $stmt->execute([$id]);
  $pack = $stmt->fetch();
  if (!$pack) {
    header('Location: /admin/packages.php' . ($activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId . '&' : '?') . 'delete_error=' . urlencode('Pack introuvable.'));
    exit;
  }
  if ($activeProgramId > 0) {
    $scopeStmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM packages pk
      WHERE pk.id = ?
        AND " . auth_program_package_scope_sql($pdo, $activeProgramId, 'pk', false) . "
    ");
    $scopeStmt->execute([$id]);
    if ((int)$scopeStmt->fetchColumn() <= 0) {
      header('Location: /admin/packages.php' . ($activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId . '&' : '?') . 'delete_error=' . urlencode('Pack introuvable.'));
      exit;
    }
  }

  if ($activeProgramId > 0 && auth_program_package_links_enabled($pdo)) {
    $del = $pdo->prepare("DELETE FROM program_package_links WHERE program_id=? AND package_id=?");
    $del->execute([$activeProgramId, $id]);
  } else {
    $del = $pdo->prepare("DELETE FROM packages WHERE id=?");
    $del->execute([$id]);
  }

  header('Location: /admin/packages.php' . ($activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId . '&' : '?') . 'deleted=1');
  exit;
} catch (Throwable $e) {
  header('Location: /admin/packages.php' . ($activeProgramId > 0 ? '?program_id=' . (int)$activeProgramId . '&' : '?') . 'delete_error=' . urlencode("Impossible de supprimer ce pack."));
  exit;
}
