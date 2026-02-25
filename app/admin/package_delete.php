<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/../db.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: /admin/packages.php?delete_error=' . urlencode('Identifiant de pack invalide.'));
  exit;
}

try {
  $stmt = $pdo->prepare("SELECT id FROM packages WHERE id=?");
  $stmt->execute([$id]);
  if (!$stmt->fetch()) {
    header('Location: /admin/packages.php?delete_error=' . urlencode('Pack introuvable.'));
    exit;
  }

  $del = $pdo->prepare("DELETE FROM packages WHERE id=?");
  $del->execute([$id]);

  header('Location: /admin/packages.php?deleted=1');
  exit;
} catch (Throwable $e) {
  header('Location: /admin/packages.php?delete_error=' . urlencode("Impossible de supprimer ce pack."));
  exit;
}
