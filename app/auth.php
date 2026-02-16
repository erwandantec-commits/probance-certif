<?php
// app/auth.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_auth(): array {
  $u = current_user();
  if (!$u) {
    header("Location: /login.php");
    exit;
  }
  return $u;
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
