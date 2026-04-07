<?php
// app/auth.php
if (!isset($_SESSION) || !is_array($_SESSION)) {
  $_SESSION = [];
}

function auth_parse_ini_size(string $value): int {
  $value = trim($value);
  if ($value === '') {
    return 0;
  }
  $unit = strtolower(substr($value, -1));
  $number = (float)$value;
  return match ($unit) {
    'g' => (int)($number * 1024 * 1024 * 1024),
    'm' => (int)($number * 1024 * 1024),
    'k' => (int)($number * 1024),
    default => (int)$number,
  };
}

function auth_request_too_large(): bool {
  if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    return false;
  }
  $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  if ($contentLength <= 0) {
    return false;
  }
  $postMaxSize = auth_parse_ini_size((string)ini_get('post_max_size'));
  return $postMaxSize > 0 && $contentLength > $postMaxSize;
}

if (auth_request_too_large()) {
  if (!headers_sent()) {
    http_response_code(413);
  }
  echo "Request too large. Reduce the file size and try again.";
  exit;
}

if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) session_start();
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
  if (session_status() === PHP_SESSION_ACTIVE && ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
  }
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
  }
}
