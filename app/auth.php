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
require_once __DIR__ . '/services/session_service.php';

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function normalize_user_role(?string $role): string {
  $role = strtoupper(trim((string)$role));
  return match ($role) {
    'ADMIN', 'OWNER', 'USER' => $role,
    default => 'USER',
  };
}

function user_has_role(array $user, string|array $roles): bool {
  $userRole = normalize_user_role((string)($user['role'] ?? 'USER'));
  $roles = is_array($roles) ? $roles : [$roles];
  foreach ($roles as $role) {
    if ($userRole === normalize_user_role((string)$role)) {
      return true;
    }
  }
  return false;
}

function user_can_access_admin_area(array $user): bool {
  return user_has_role($user, ['ADMIN', 'OWNER']);
}

function user_can_access_reporting_area(array $user): bool {
  return user_has_role($user, ['ADMIN', 'OWNER']);
}

function user_can_manage_program_catalog(array $user): bool {
  return user_has_role($user, 'ADMIN');
}

function user_can_assign_role(array $actor, string $role): bool {
  $role = normalize_user_role($role);
  if (user_has_role($actor, 'ADMIN')) {
    return in_array($role, ['USER', 'OWNER', 'ADMIN'], true);
  }
  if (user_has_role($actor, 'OWNER')) {
    return in_array($role, ['USER', 'OWNER'], true);
  }
  return false;
}

function user_can_manage_target_role(array $actor, string $targetRole): bool {
  $targetRole = normalize_user_role($targetRole);
  if (user_has_role($actor, 'ADMIN')) {
    return true;
  }
  if (user_has_role($actor, 'OWNER')) {
    return $targetRole !== 'ADMIN';
  }
  return false;
}

function auth_program_access_role_column_exists(PDO $pdo): bool {
  return auth_column_exists($pdo, 'user_program_access', 'access_role');
}

function auth_program_access_role_expr(PDO $pdo, string $alias = 'upa'): string {
  $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'upa';
  return auth_program_access_role_column_exists($pdo) ? "{$alias}.access_role" : "'USER'";
}

function auth_normalize_program_access_role(?string $role): string {
  $role = strtoupper(trim((string)$role));
  return $role === 'OWNER' ? 'OWNER' : 'USER';
}

function auth_table_exists(PDO $pdo, string $table): bool {
  return table_exists($pdo, $table);
}

function auth_column_exists(PDO $pdo, string $table, string $column): bool {
  return table_column_exists($pdo, $table, $column);
}

function auth_global_setting(PDO $pdo, string $key, ?string $default = null): ?string {
  if (!auth_table_exists($pdo, 'global_settings')) {
    return $default;
  }
  $st = $pdo->prepare("SELECT setting_value FROM global_settings WHERE setting_key = ? LIMIT 1");
  $st->execute([$key]);
  $value = $st->fetchColumn();
  return $value === false ? $default : (string)$value;
}

function auth_global_setting_bool(PDO $pdo, string $key, bool $default = false): bool {
  $value = auth_global_setting($pdo, $key, $default ? '1' : '0');
  return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function auth_set_global_setting(PDO $pdo, string $key, ?string $value, ?int $updatedByUserId = null): void {
  if (!auth_table_exists($pdo, 'global_settings')) {
    return;
  }
  $st = $pdo->prepare("
    INSERT INTO global_settings(setting_key, setting_value, updated_by_user_id)
    VALUES(?, ?, ?)
    ON DUPLICATE KEY UPDATE
      setting_value = VALUES(setting_value),
      updated_by_user_id = VALUES(updated_by_user_id)
  ");
  $st->execute([$key, $value, $updatedByUserId]);
}

function auth_user_email_control_enabled(PDO $pdo): bool {
  return auth_global_setting_bool($pdo, 'user_email_control_enabled', true);
}

function auth_parse_domain_list(?string $raw): array {
  $raw = strtolower((string)$raw);
  if ($raw === '') {
    return [];
  }
  $parts = preg_split('/[\s,;]+/', $raw) ?: [];
  $domains = [];
  foreach ($parts as $part) {
    $domain = trim((string)$part);
    if ($domain === '') {
      continue;
    }
    $domain = ltrim($domain, '@');
    $domain = preg_replace('/[^a-z0-9.-]+/', '', $domain);
    $domain = trim((string)$domain, '.');
    if ($domain === '' || !str_contains($domain, '.')) {
      continue;
    }
    $domains[$domain] = $domain;
  }
  return array_values($domains);
}

function auth_parse_id_list(?string $raw): array {
  $parts = preg_split('/[^0-9]+/', (string)$raw) ?: [];
  $ids = [];
  foreach ($parts as $part) {
    $id = (int)$part;
    if ($id > 0) {
      $ids[$id] = $id;
    }
  }
  return array_values($ids);
}

function auth_user_email_control_program_ids(PDO $pdo): array {
  return auth_parse_id_list(auth_global_setting($pdo, 'user_email_control_program_ids', ''));
}

function auth_user_email_control_allowed_domains(PDO $pdo): array {
  return auth_parse_domain_list(auth_global_setting($pdo, 'user_email_control_allowed_domains', ''));
}

function auth_user_email_control_applies_to_programs(PDO $pdo, array $programIds): bool {
  $configuredProgramIds = auth_user_email_control_program_ids($pdo);
  if (!$configuredProgramIds) {
    return true;
  }
  foreach ($programIds as $programId) {
    if (in_array((int)$programId, $configuredProgramIds, true)) {
      return true;
    }
  }
  return false;
}

function auth_user_email_control_bypass(array $user): bool {
  return (int)($user['email_control_bypass'] ?? 0) === 1;
}

function auth_validate_user_email(PDO $pdo, string $email, bool $bypass = false, array $programIds = []): ?string {
  if (!auth_user_email_control_enabled($pdo) || $bypass) {
    return null;
  }
  if (!auth_user_email_control_applies_to_programs($pdo, $programIds)) {
    return null;
  }

  $emailDomain = auth_email_domain($email);
  $allowedDomains = auth_user_email_control_allowed_domains($pdo);
  if (!$allowedDomains) {
    return null;
  }

  $acceptedDomains = [];
  foreach ($allowedDomains as $allowedDomain) {
    $acceptedDomains[$allowedDomain] = $allowedDomain;
  }

  if ($emailDomain === '' || !isset($acceptedDomains[$emailDomain])) {
    $domainsLabel = implode(', ', array_values($acceptedDomains));
    return "L'email doit utiliser un domaine autorise ({$domainsLabel}).";
  }

  return null;
}

function user_organization_id(array $user): int {
  return (int)($user['organization_id'] ?? 0);
}

function user_team_id(array $user): int {
  return (int)($user['team_id'] ?? 0);
}

function user_role_label(string $role): string {
  return match (normalize_user_role($role)) {
    'ADMIN' => 'ADMIN',
    'OWNER' => 'OWNER',
    default => 'USER',
  };
}

function auth_slugify_label(string $value): string {
  $value = strtolower(trim($value));
  $value = preg_replace('/[^a-z0-9]+/', '-', $value);
  return trim((string)$value, '-');
}

function auth_email_domain(string $email): string {
  $parts = explode('@', strtolower(trim($email)));
  return trim((string)($parts[1] ?? ''));
}

function auth_organization_name_from_email(string $email): string {
  $domain = auth_email_domain($email);
  if ($domain === '') {
    return 'Organisation';
  }
  $base = preg_replace('/\.[a-z0-9-]+$/', '', $domain);
  $base = str_replace(['.', '-', '_'], ' ', (string)$base);
  $base = trim((string)$base);
  if ($base === '') {
    return ucfirst($domain);
  }
  return ucwords($base);
}

function auth_find_or_create_organization_for_email(PDO $pdo, string $email): int {
  if (!auth_table_exists($pdo, 'organizations')) {
    return 0;
  }

  $domain = auth_email_domain($email);
  if ($domain !== '') {
    $findByDomain = $pdo->prepare("SELECT id FROM organizations WHERE primary_domain = ? LIMIT 1");
    $findByDomain->execute([$domain]);
    $organizationId = (int)($findByDomain->fetchColumn() ?: 0);
    if ($organizationId > 0) {
      return $organizationId;
    }
  }

  $baseName = auth_organization_name_from_email($email);
  $baseSlug = auth_slugify_label($baseName);
  if ($baseSlug === '') {
    $baseSlug = 'organisation';
  }

  $slug = $baseSlug;
  $suffix = 2;
  while (true) {
    $findBySlug = $pdo->prepare("SELECT id FROM organizations WHERE slug = ? LIMIT 1");
    $findBySlug->execute([$slug]);
    $existingId = (int)($findBySlug->fetchColumn() ?: 0);
    if ($existingId <= 0) {
      break;
    }
    $slug = $baseSlug . '-' . $suffix++;
  }

  $insert = $pdo->prepare("
    INSERT INTO organizations(name, slug, primary_domain, is_internal)
    VALUES(?, ?, ?, 0)
  ");
  $insert->execute([$baseName, $slug, $domain !== '' ? $domain : null]);
  return (int)$pdo->lastInsertId();
}

function auth_accessible_programs(PDO $pdo, array $user): array {
  if (!auth_table_exists($pdo, 'programs')) {
    return [];
  }

  if (user_has_role($user, 'ADMIN')) {
    $st = $pdo->query("
      SELECT id, name, slug, code, 'OWNER' AS access_role
      FROM programs
      WHERE is_active = 1
      ORDER BY display_order ASC, id ASC
    ");
    return $st ? ($st->fetchAll() ?: []) : [];
  }

  if (!auth_table_exists($pdo, 'user_program_access')) {
    $st = $pdo->query("
      SELECT id, name, slug, code, 'USER' AS access_role
      FROM programs
      WHERE is_active = 1
      ORDER BY display_order ASC, id ASC
    ");
    return $st ? ($st->fetchAll() ?: []) : [];
  }

  $roleExpr = auth_program_access_role_expr($pdo, 'upa');
  $st = $pdo->prepare("
    SELECT p.id, p.name, p.slug, p.code, $roleExpr AS access_role
    FROM programs p
    JOIN user_program_access upa ON upa.program_id = p.id
    WHERE upa.user_id = ?
      AND p.is_active = 1
    ORDER BY p.display_order ASC, p.id ASC
  ");
  $st->execute([(int)($user['id'] ?? 0)]);
  return $st->fetchAll() ?: [];
}

function auth_manageable_programs(PDO $pdo, array $user): array {
  if (!auth_table_exists($pdo, 'programs')) {
    return [];
  }
  if (user_has_role($user, 'ADMIN')) {
    $st = $pdo->query("
      SELECT id, name, slug, code, 'OWNER' AS access_role
      FROM programs
      WHERE is_active = 1
      ORDER BY display_order ASC, id ASC
    ");
    return $st ? ($st->fetchAll() ?: []) : [];
  }
  if (!auth_table_exists($pdo, 'user_program_access')) {
    return [];
  }
  $roleExpr = auth_program_access_role_expr($pdo, 'upa');
  $ownerWhere = auth_program_access_role_column_exists($pdo)
    ? "AND upa.access_role = 'OWNER'"
    : "";
  $st = $pdo->prepare("
    SELECT p.id, p.name, p.slug, p.code, $roleExpr AS access_role
    FROM programs p
    JOIN user_program_access upa ON upa.program_id = p.id
    WHERE upa.user_id = ?
      AND p.is_active = 1
      $ownerWhere
    ORDER BY p.display_order ASC, p.id ASC
  ");
  $st->execute([(int)($user['id'] ?? 0)]);
  return $st->fetchAll() ?: [];
}

function auth_accessible_program_ids(PDO $pdo, array $user): array {
  return array_values(array_map(static fn(array $program): int => (int)($program['id'] ?? 0), auth_accessible_programs($pdo, $user)));
}

function auth_user_can_access_program(PDO $pdo, array $user, int $programId): bool {
  if ($programId <= 0) {
    return false;
  }
  return in_array($programId, auth_accessible_program_ids($pdo, $user), true);
}

function auth_program_package_links_enabled(PDO $pdo): bool {
  return auth_table_exists($pdo, 'program_package_links');
}

function auth_package_program_ids(PDO $pdo, int $packageId, bool $activeOnly = false): array {
  if ($packageId <= 0) {
    return [];
  }

  if (auth_program_package_links_enabled($pdo)) {
    $sql = "
      SELECT program_id
      FROM program_package_links
      WHERE package_id = ?
    ";
    if ($activeOnly) {
      $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY program_id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$packageId]);
    return array_values(array_filter(array_map(static fn($value): int => (int)$value, $st->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn(int $value): bool => $value > 0));
  }

  if (!auth_column_exists($pdo, 'packages', 'program_id')) {
    return [];
  }
  $st = $pdo->prepare("SELECT program_id FROM packages WHERE id = ? LIMIT 1");
  $st->execute([$packageId]);
  $programId = (int)($st->fetchColumn() ?: 0);
  return $programId > 0 ? [$programId] : [];
}

function auth_package_program_id(PDO $pdo, int $packageId): int {
  return (int)(auth_package_program_ids($pdo, $packageId)[0] ?? 0);
}

function auth_user_can_access_package(PDO $pdo, array $user, int $packageId): bool {
  $programIds = auth_package_program_ids($pdo, $packageId, true);
  if (!$programIds) {
    return true;
  }
  foreach ($programIds as $programId) {
    if (auth_user_can_access_program($pdo, $user, $programId)) {
      return true;
    }
  }
  return false;
}

function auth_program_package_scope_sql(PDO $pdo, int $programId, string $packageAlias = 'pk', bool $activeOnly = true): string {
  if ($programId <= 0) {
    return '1 = 1';
  }
  $packageAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $packageAlias) ?: 'pk';

  if (auth_program_package_links_enabled($pdo)) {
    return "EXISTS (
      SELECT 1
      FROM program_package_links ppl
      WHERE ppl.package_id = {$packageAlias}.id
        AND ppl.program_id = " . (int)$programId . ($activeOnly ? "
        AND ppl.is_active = 1" : '') . "
    )";
  }

  if (auth_column_exists($pdo, 'packages', 'program_id')) {
    return "{$packageAlias}.program_id = " . (int)$programId;
  }

  return '1 = 1';
}

function auth_program_question_links_enabled(PDO $pdo): bool {
  return auth_table_exists($pdo, 'program_question_links');
}

function auth_program_question_scope_sql(PDO $pdo, int $programId, string $questionAlias = 'q'): string {
  if ($programId <= 0 || !auth_program_question_links_enabled($pdo)) {
    return '1 = 1';
  }
  $questionAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $questionAlias) ?: 'q';
  return "EXISTS (
    SELECT 1
    FROM program_question_links pql_scope
    WHERE pql_scope.question_id = {$questionAlias}.id
      AND pql_scope.program_id = " . (int)$programId . "
  )";
}

function auth_question_program_ids(PDO $pdo, int $questionId): array {
  if ($questionId <= 0 || !auth_program_question_links_enabled($pdo)) {
    return [];
  }
  $st = $pdo->prepare("
    SELECT program_id
    FROM program_question_links
    WHERE question_id = ?
    ORDER BY program_id ASC
  ");
  $st->execute([$questionId]);
  return array_values(array_filter(array_map(static fn($value): int => (int)$value, $st->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn(int $value): bool => $value > 0));
}

function auth_resolve_program_context(PDO $pdo, array $user, ?int $requestedProgramId, string $sessionKey): int {
  $programs = auth_accessible_programs($pdo, $user);
  if (!$programs) {
    unset($_SESSION[$sessionKey]);
    return 0;
  }

  $programIds = array_values(array_map(static fn(array $program): int => (int)($program['id'] ?? 0), $programs));
  if ($requestedProgramId !== null && in_array($requestedProgramId, $programIds, true)) {
    $_SESSION[$sessionKey] = $requestedProgramId;
    return $requestedProgramId;
  }

  $storedProgramId = (int)($_SESSION[$sessionKey] ?? 0);
  if (in_array($storedProgramId, $programIds, true)) {
    return $storedProgramId;
  }

  $fallback = (int)($programIds[0] ?? 0);
  if ($fallback > 0) {
    $_SESSION[$sessionKey] = $fallback;
  } else {
    unset($_SESSION[$sessionKey]);
  }
  return $fallback;
}

function auth_candidate_program_context(PDO $pdo, array $user, ?int $requestedProgramId = null): int {
  return auth_resolve_program_context($pdo, $user, $requestedProgramId, 'candidate_program_id');
}

function auth_admin_program_context(PDO $pdo, array $user, ?int $requestedProgramId = null): int {
  $programs = auth_manageable_programs($pdo, $user);
  if (!$programs) {
    unset($_SESSION['admin_program_id']);
    return 0;
  }

  $programIds = array_values(array_map(static fn(array $program): int => (int)($program['id'] ?? 0), $programs));
  if ($requestedProgramId !== null && in_array($requestedProgramId, $programIds, true)) {
    $_SESSION['admin_program_id'] = $requestedProgramId;
    return $requestedProgramId;
  }

  $storedProgramId = (int)($_SESSION['admin_program_id'] ?? 0);
  if (in_array($storedProgramId, $programIds, true)) {
    return $storedProgramId;
  }

  $fallback = (int)($programIds[0] ?? 0);
  if ($fallback > 0) {
    $_SESSION['admin_program_id'] = $fallback;
  } else {
    unset($_SESSION['admin_program_id']);
  }
  return $fallback;
}

function auth_user_program_ids(PDO $pdo, int $userId): array {
  if ($userId <= 0 || !auth_table_exists($pdo, 'user_program_access')) {
    return [];
  }
  $st = $pdo->prepare("
    SELECT program_id
    FROM user_program_access
    WHERE user_id = ?
    ORDER BY program_id ASC
  ");
  $st->execute([$userId]);
  return array_values(array_filter(array_map(static fn($value): int => (int)$value, $st->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn(int $value): bool => $value > 0));
}

function auth_users_share_program_scope(PDO $pdo, int $actorUserId, int $targetUserId): bool {
  if ($actorUserId <= 0 || $targetUserId <= 0 || !auth_table_exists($pdo, 'user_program_access')) {
    return false;
  }
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM user_program_access actor_access
    JOIN user_program_access target_access
      ON target_access.program_id = actor_access.program_id
    WHERE actor_access.user_id = ?
      AND target_access.user_id = ?
      " . (auth_program_access_role_column_exists($pdo) ? "AND actor_access.access_role = 'OWNER'" : "") . "
  ");
  $st->execute([$actorUserId, $targetUserId]);
  return ((int)$st->fetchColumn() > 0);
}

function user_can_view_team_reporting(array $user): bool {
  return user_has_role($user, ['OWNER', 'ADMIN']);
}

function user_can_manage_same_organization(array $actor, array $target): bool {
  return user_has_role($actor, ['OWNER', 'ADMIN']);
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
    'role' => normalize_user_role((string)($dbUser['role'] ?? 'USER')),
  ];
  return $_SESSION['user'];
}

function require_admin(): array {
  $u = require_auth();
  if (!user_has_role($u, 'ADMIN')) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
  return $u;
}

function require_admin_area(): array {
  $u = require_auth();
  if (
    !user_can_access_admin_area($u)
    || (!user_has_role($u, 'ADMIN') && !auth_manageable_programs(db(), $u))
  ) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
  return $u;
}

function require_team_reporting(): array {
  $u = require_auth();
  if (
    !user_can_access_reporting_area($u)
    || (!user_has_role($u, 'ADMIN') && !auth_manageable_programs(db(), $u))
  ) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
  return $u;
}

function auth_managed_team_ids(PDO $pdo, array $user): array {
  if (!auth_table_exists($pdo, 'teams')) {
    return [];
  }

  if (user_has_role($user, 'ADMIN') || user_has_role($user, 'OWNER')) {
    $organizationId = user_organization_id($user);
    if ($organizationId <= 0) {
      return [];
    }
    $st = $pdo->prepare("SELECT id FROM teams WHERE organization_id = ? ORDER BY name ASC, id ASC");
    $st->execute([$organizationId]);
    return array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $st->fetchAll() ?: []);
  }

  return [];
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
