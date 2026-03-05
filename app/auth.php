<?php
// app/auth.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/i18n.php';

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_auth(): array {
  $u = current_user();
  if (!$u) {
    $lang = get_lang();
    header("Location: /login.php?lang=" . urlencode($lang));
    exit;
  }

  $uid = (int)($u['id'] ?? 0);
  $email = trim((string)($u['email'] ?? ''));
  if ($uid <= 0 || $email === '') {
    logout();
    $lang = get_lang();
    header("Location: /login.php?lang=" . urlencode($lang));
    exit;
  }

  $pdo = db();
  $st = $pdo->prepare("SELECT id, email, name, role FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $dbUser = $st->fetch();
  if (!$dbUser) {
    logout();
    $lang = get_lang();
    header("Location: /login.php?lang=" . urlencode($lang));
    exit;
  }

  // Refresh session from DB to avoid stale IDs/roles after admin edits.
  $_SESSION['user'] = [
    'id' => (int)$dbUser['id'],
    'email' => (string)$dbUser['email'],
    'name' => (string)($dbUser['name'] ?? ''),
    'role' => (string)($dbUser['role'] ?? 'USER'),
  ];
  return $_SESSION['user'];
}

function require_admin(): array {
  $u = require_auth();
  if (($u['role'] ?? 'USER') !== 'ADMIN') {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
  return $u;
}

function logout(): void {
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
  }
  session_destroy();
}
