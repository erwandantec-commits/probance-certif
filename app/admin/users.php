<?php
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin_area();
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
$currentAdminId = (int)$adminUser['id'];
$hasOrganizationColumn = auth_column_exists($pdo, 'users', 'organization_id');
$hasOrganizationsTable = auth_table_exists($pdo, 'organizations');
$hasProgramsTable = auth_table_exists($pdo, 'programs');
$hasUserProgramAccessTable = auth_table_exists($pdo, 'user_program_access');
$hasEmailControlBypassColumn = auth_column_exists($pdo, 'users', 'email_control_bypass');

if (empty($_SESSION['admin_users_csrf']) || !is_string($_SESSION['admin_users_csrf'])) {
  $_SESSION['admin_users_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['admin_users_csrf'];

function admin_users_safe_return_url(?string $url, string $fallback = '/admin/users.php'): string {
  $url = trim((string)$url);
  if ($url === '' || preg_match('/[\r\n]/', $url)) {
    return $fallback;
  }
  if (!str_starts_with($url, '/admin/')) {
    return $fallback;
  }
  return $url;
}

function admin_users_redirect(array $params = [], ?string $target = null): void {
  $base = admin_users_safe_return_url($target, '/admin/users.php');
  if ($params) {
    $base .= (str_contains($base, '?') ? '&' : '?') . http_build_query($params);
  }
  header('Location: ' . $base);
  exit;
}

function admin_users_replace_query_param(string $url, string $key, string $value): string {
  $parts = parse_url($url);
  if ($parts === false) {
    return $url;
  }
  $query = [];
  if (isset($parts['query'])) {
    parse_str($parts['query'], $query);
  }
  $query[$key] = $value;
  $path = $parts['path'] ?? '/admin/users.php';
  $fragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';
  return $path . ($query ? ('?' . http_build_query($query)) : '') . $fragment;
}

function admin_users_set_notice(string $type, string $text): void {
  $_SESSION['admin_users_notice'] = [
    'type' => $type,
    'text' => $text,
  ];
}

function admin_users_set_create_form(array $data): void {
  $_SESSION['admin_users_create_form'] = $data;
}

function admin_users_clear_create_form(): void {
  unset($_SESSION['admin_users_create_form']);
}

function admin_users_set_edit_form(int $userId, array $data): void {
  $_SESSION['admin_users_edit_form'] = [
    'user_id' => $userId,
    'data' => $data,
  ];
}

function admin_users_clear_edit_form(): void {
  unset($_SESSION['admin_users_edit_form']);
}

function admin_users_guess_first_last(?string $fullName): array {
  $fullName = trim((string)$fullName);
  if ($fullName === '') {
    return ['', ''];
  }
  $parts = preg_split('/\s+/', $fullName) ?: [];
  if (count($parts) <= 1) {
    return [$fullName, ''];
  }
  $first = (string)array_shift($parts);
  $last = trim(implode(' ', $parts));
  return [$first, $last];
}

function admin_users_format_short_date(?string $raw): string {
  $raw = trim((string)$raw);
  if ($raw === '') {
    return '-';
  }
  try {
    return (new DateTimeImmutable($raw))->format('Y-m-d');
  } catch (Throwable $e) {
    return $raw;
  }
}

function admin_users_scope_programs(PDO $pdo, array $actor): array {
  return user_has_role($actor, 'ADMIN') ? auth_accessible_programs($pdo, $actor) : auth_manageable_programs($pdo, $actor);
}

function admin_users_normalize_ids(mixed $raw): array {
  if (!is_array($raw)) {
    return [];
  }
  $ids = [];
  foreach ($raw as $value) {
    $id = (int)$value;
    if ($id > 0) {
      $ids[$id] = $id;
    }
  }
  return array_values($ids);
}

function admin_users_normalize_program_roles(mixed $raw, array $allowedProgramIds, array $actor): array {
  if (!is_array($raw)) {
    return [];
  }
  $allowed = array_fill_keys($allowedProgramIds, true);
  $roles = [];
  foreach ($raw as $programIdRaw => $roleRaw) {
    $programId = (int)$programIdRaw;
    if ($programId <= 0 || !isset($allowed[$programId])) {
      continue;
    }
    $role = strtoupper(trim((string)$roleRaw));
    if ($role === 'OWNER' && user_has_role($actor, ['ADMIN', 'OWNER'])) {
      $roles[$programId] = 'OWNER';
    } elseif ($role === 'USER') {
      $roles[$programId] = 'USER';
    }
  }
  return $roles;
}

function admin_users_program_role_ids(array $programRoles): array {
  return array_values(array_map('intval', array_keys($programRoles)));
}

function admin_users_user_program_roles(PDO $pdo, int $userId): array {
  if ($userId <= 0 || !auth_table_exists($pdo, 'user_program_access')) {
    return [];
  }
  $st = $pdo->prepare("
    SELECT program_id, " . auth_program_access_role_expr($pdo, 'user_program_access') . " AS access_role
    FROM user_program_access
    WHERE user_id = ?
    ORDER BY program_id ASC
  ");
  $st->execute([$userId]);
  $roles = [];
  foreach ($st->fetchAll() ?: [] as $row) {
    $programId = (int)($row['program_id'] ?? 0);
    if ($programId > 0) {
      $roles[$programId] = auth_normalize_program_access_role((string)($row['access_role'] ?? 'USER'));
    }
  }
  return $roles;
}

function admin_users_sync_role_from_program_roles(string $requestedRole, array $programRoles): string {
  $requestedRole = normalize_user_role($requestedRole);
  if ($requestedRole === 'ADMIN') {
    return 'ADMIN';
  }
  return in_array('OWNER', $programRoles, true) ? 'OWNER' : 'USER';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedToken = (string)($_POST['csrf_token'] ?? '');
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    admin_users_set_notice('bad', 'Action refusée: token de sécurité invalide.');
    admin_users_redirect();
  }

  $action = (string)($_POST['action'] ?? '');
  if ($action === 'create_user') {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    $newRole = normalize_user_role((string)($_POST['role'] ?? 'USER'));
    $programRows = admin_users_scope_programs($pdo, $adminUser);
    $allowedProgramIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $programRows);
    $programRoles = admin_users_normalize_program_roles($_POST['program_roles'] ?? [], $allowedProgramIds, $adminUser);
    $programIds = admin_users_program_role_ids($programRoles);
    $newRole = admin_users_sync_role_from_program_roles($newRole, $programRoles);
    $emailControlBypass = user_has_role($adminUser, 'ADMIN') && $hasEmailControlBypassColumn
      ? (((string)($_POST['email_control_bypass'] ?? '0') === '1') ? 1 : 0)
      : 0;

    admin_users_set_create_form([
      'first_name' => $firstName,
      'last_name' => $lastName,
      'email' => $email,
      'role' => $newRole,
      'program_roles' => $programRoles,
      'email_control_bypass' => $emailControlBypass,
    ]);

    if ($firstName === '' || $lastName === '' || $email === '' || $password === '' || $password2 === '') {
      admin_users_set_notice('bad', 'Tous les champs de création sont obligatoires.');
      admin_users_redirect(['open_create' => '1']);
    }
    if (strlen($firstName) > 100 || strlen($lastName) > 100) {
      admin_users_set_notice('bad', 'Prénom/nom trop longs (max 100 caractères).');
      admin_users_redirect(['open_create' => '1']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      admin_users_set_notice('bad', 'Email invalide.');
      admin_users_redirect(['open_create' => '1']);
    }
    if (strlen($password) < 8) {
      admin_users_set_notice('bad', 'Mot de passe trop court (min 8 caractères).');
      admin_users_redirect(['open_create' => '1']);
    }
    if ($password !== $password2) {
      admin_users_set_notice('bad', 'Les mots de passe ne correspondent pas.');
      admin_users_redirect(['open_create' => '1']);
    }
    if ($newRole === 'ADMIN' && !user_has_role($adminUser, 'ADMIN')) {
      admin_users_set_notice('bad', 'Seul un administrateur peut creer un autre administrateur.');
      admin_users_redirect(['open_create' => '1']);
    }
    if (!user_can_assign_role($adminUser, $newRole)) {
      admin_users_set_notice('bad', 'Role refuse pour votre niveau de permission.');
      admin_users_redirect(['open_create' => '1']);
    }

    try {
      $pdo->beginTransaction();

      $existsStmt = $pdo->prepare("SELECT id FROM users WHERE email=? FOR UPDATE");
      $existsStmt->execute([$email]);
      if ($existsStmt->fetch()) {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Un utilisateur existe déjà avec cet email.');
        admin_users_redirect(['open_create' => '1']);
      }

      $fullName = trim($firstName . ' ' . $lastName);
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $organizationId = user_organization_id($adminUser);
      if ($organizationId <= 0 && auth_column_exists($pdo, 'users', 'organization_id')) {
        $organizationId = auth_find_or_create_organization_for_email($pdo, $email);
      }

      $emailControlError = auth_validate_user_email($pdo, $email, $emailControlBypass === 1, $programIds);
      if ($emailControlError !== null) {
        $pdo->rollBack();
        admin_users_set_notice('bad', $emailControlError);
        admin_users_redirect(['open_create' => '1']);
      }

      $hasOrganizationColumn = auth_column_exists($pdo, 'users', 'organization_id');
      if ($hasOrganizationColumn && $hasEmailControlBypassColumn) {
        $ins = $pdo->prepare("INSERT INTO users(email, password_hash, name, role, organization_id, email_control_bypass) VALUES(?, ?, ?, ?, ?, ?)");
        $ins->execute([$email, $hash, $fullName, $newRole, $organizationId, $emailControlBypass]);
      } elseif ($hasOrganizationColumn) {
        $ins = $pdo->prepare("INSERT INTO users(email, password_hash, name, role, organization_id) VALUES(?, ?, ?, ?, ?)");
        $ins->execute([$email, $hash, $fullName, $newRole, $organizationId]);
      } else {
        $ins = $pdo->prepare("INSERT INTO users(email, password_hash, name, role) VALUES(?, ?, ?, ?)");
        $ins->execute([$email, $hash, $fullName, $newRole]);
      }
      $newUserId = (int)$pdo->lastInsertId();

      $contactUpsert = $pdo->prepare("
        INSERT INTO contacts(email, first_name, last_name)
        VALUES(?, ?, ?)
        ON DUPLICATE KEY UPDATE
          first_name = VALUES(first_name),
          last_name = VALUES(last_name)
      ");
      $contactUpsert->execute([$email, $firstName, $lastName]);
      if (auth_table_exists($pdo, 'user_program_access')) {
        $hasAccessRoleColumn = auth_program_access_role_column_exists($pdo);
        $grantProgram = $pdo->prepare($hasAccessRoleColumn
          ? "INSERT IGNORE INTO user_program_access(user_id, program_id, access_role, granted_by_user_id) VALUES(?, ?, ?, ?)"
          : "INSERT IGNORE INTO user_program_access(user_id, program_id, granted_by_user_id) VALUES(?, ?, ?)"
        );
        foreach ($programRoles as $programId => $programRole) {
          $hasAccessRoleColumn
            ? $grantProgram->execute([$newUserId, $programId, $programRole, $currentAdminId])
            : $grantProgram->execute([$newUserId, $programId, $currentAdminId]);
        }
      }
      $pdo->commit();
      admin_users_clear_create_form();
      admin_users_set_notice('ok', 'Utilisateur créé: ' . $email . ' (' . $newRole . ').');
      admin_users_redirect(['search' => $email]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      admin_users_set_notice('bad', "Erreur serveur pendant la création de l'utilisateur.");
      admin_users_redirect(['open_create' => '1']);
    }
  }

  if ($action === 'update_user') {
    $returnTo = admin_users_safe_return_url((string)($_POST['return_to'] ?? ''), '/admin/users.php');
    $targetId = (int)($_POST['user_id'] ?? 0);
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $newRole = normalize_user_role((string)($_POST['role'] ?? 'USER'));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $newPassword2 = (string)($_POST['new_password2'] ?? '');
    $programRows = admin_users_scope_programs($pdo, $adminUser);
    $allowedProgramIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $programRows);
    $programRoles = admin_users_normalize_program_roles($_POST['program_roles'] ?? [], $allowedProgramIds, $adminUser);
    $programIds = admin_users_program_role_ids($programRoles);
    $emailControlBypass = user_has_role($adminUser, 'ADMIN') && $hasEmailControlBypassColumn
      ? (((string)($_POST['email_control_bypass'] ?? '0') === '1') ? 1 : 0)
      : 0;

    admin_users_set_edit_form($targetId, [
      'first_name' => $firstName,
      'last_name' => $lastName,
      'email' => $email,
      'role' => $newRole,
      'program_roles' => $programRoles,
      'email_control_bypass' => $emailControlBypass,
    ]);

    if ($targetId <= 0) {
      admin_users_set_notice('bad', 'Utilisateur invalide.');
      admin_users_redirect([], $returnTo);
    }
    if ($firstName === '' || $lastName === '' || $email === '') {
      admin_users_set_notice('bad', 'Prénom, nom et email sont obligatoires.');
      admin_users_redirect([], $returnTo);
    }
    if (strlen($firstName) > 100 || strlen($lastName) > 100) {
      admin_users_set_notice('bad', 'Prénom/nom trop longs (max 100 caractères).');
      admin_users_redirect([], $returnTo);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      admin_users_set_notice('bad', 'Email invalide.');
      admin_users_redirect([], $returnTo);
    }
    if ($newPassword !== '' || $newPassword2 !== '') {
      if (strlen($newPassword) < 8) {
        admin_users_set_notice('bad', 'Mot de passe trop court (min 8 caractères).');
        admin_users_redirect([], $returnTo);
      }
      if ($newPassword !== $newPassword2) {
        admin_users_set_notice('bad', 'Les mots de passe ne correspondent pas.');
        admin_users_redirect([], $returnTo);
      }
    }

    try {
      $pdo->beginTransaction();
      $emailControlBypassSelect = $hasEmailControlBypassColumn ? ", email_control_bypass" : ", 0 AS email_control_bypass";
      $targetStmt = $pdo->prepare("SELECT id, email, role, organization_id $emailControlBypassSelect FROM users WHERE id=? FOR UPDATE");
      $targetStmt->execute([$targetId]);
      $target = $targetStmt->fetch();
      if (!$target) {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Utilisateur introuvable.');
        admin_users_redirect([], $returnTo);
      }

      $oldRole = (string)$target['role'];
      if ($targetId !== $currentAdminId && !user_can_manage_target_role($adminUser, $oldRole)) {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Action refusee sur ce role utilisateur.');
        admin_users_redirect([], $returnTo);
      }
      if ($targetId !== $currentAdminId && !user_has_role($adminUser, 'ADMIN') && !auth_users_share_program_scope($pdo, $currentAdminId, $targetId)) {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Action refusee hors de vos programmes.');
        admin_users_redirect([], $returnTo);
      }
      $existsStmt = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
      $existsStmt->execute([$email, $targetId]);
      if ($existsStmt->fetch()) {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Un autre utilisateur existe déjà avec cet email.');
        admin_users_redirect([], $returnTo);
      }

      if ($targetId === $currentAdminId && user_has_role($adminUser, 'ADMIN') && $newRole !== 'ADMIN') {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Action refusee: vous ne pouvez pas retirer vos propres droits admin.');
        admin_users_redirect([], $returnTo);
      }
      if ($targetId === $currentAdminId && !user_has_role($adminUser, 'ADMIN') && $newRole !== normalize_user_role($oldRole)) {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Action refusee: vous ne pouvez pas modifier votre propre role.');
        admin_users_redirect([], $returnTo);
      }

      if ($oldRole === 'ADMIN' && $newRole !== 'ADMIN') {
        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='ADMIN'")->fetchColumn();
        if ($adminCount <= 1) {
          $pdo->rollBack();
          admin_users_set_notice('bad', 'Impossible: au moins un administrateur doit rester actif.');
          admin_users_redirect([], $returnTo);
        }
      }

      $existingProgramRoles = admin_users_user_program_roles($pdo, $targetId);
      if (user_has_role($adminUser, 'ADMIN')) {
        $finalProgramRoles = $programRoles;
      } else {
        $allowedProgramMap = array_fill_keys($allowedProgramIds, true);
        $finalProgramRoles = array_filter(
          $existingProgramRoles,
          static fn(int $programId): bool => !isset($allowedProgramMap[$programId]),
          ARRAY_FILTER_USE_KEY
        );
        foreach ($programRoles as $programId => $programRole) {
          $finalProgramRoles[(int)$programId] = $programRole;
        }
      }
      $newRole = admin_users_sync_role_from_program_roles($newRole, $finalProgramRoles);
      $programIds = admin_users_program_role_ids($finalProgramRoles);

      if ($newRole !== normalize_user_role($oldRole) && !user_can_assign_role($adminUser, $newRole)) {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Changement de role refuse pour votre niveau de permission.');
        admin_users_redirect([], $returnTo);
      }

      $fullName = trim($firstName . ' ' . $lastName);
      $targetOrganizationId = (int)($target['organization_id'] ?? 0);
      if (!user_has_role($adminUser, 'ADMIN') || !$hasEmailControlBypassColumn) {
        $emailControlBypass = (int)($target['email_control_bypass'] ?? 0);
      }
      $emailControlError = auth_validate_user_email($pdo, $email, $emailControlBypass === 1, $programIds);
      if ($emailControlError !== null) {
        $pdo->rollBack();
        admin_users_set_notice('bad', $emailControlError);
        admin_users_redirect([], $returnTo);
      }
      if ($newPassword !== '') {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($hasEmailControlBypassColumn && auth_column_exists($pdo, 'users', 'organization_id')) {
          $upd = $pdo->prepare("UPDATE users SET email=?, name=?, role=?, organization_id=?, email_control_bypass=?, password_hash=? WHERE id=?");
          $upd->execute([$email, $fullName, $newRole, $targetOrganizationId, $emailControlBypass, $hash, $targetId]);
        } else {
          $upd = $pdo->prepare("UPDATE users SET email=?, name=?, role=?, password_hash=? WHERE id=?");
          $upd->execute([$email, $fullName, $newRole, $hash, $targetId]);
        }
      } else {
        if ($hasEmailControlBypassColumn && auth_column_exists($pdo, 'users', 'organization_id')) {
          $upd = $pdo->prepare("UPDATE users SET email=?, name=?, role=?, organization_id=?, email_control_bypass=? WHERE id=?");
          $upd->execute([$email, $fullName, $newRole, $targetOrganizationId, $emailControlBypass, $targetId]);
        } else {
          $upd = $pdo->prepare("UPDATE users SET email=?, name=?, role=? WHERE id=?");
          $upd->execute([$email, $fullName, $newRole, $targetId]);
        }
      }

      $contactUpsert = $pdo->prepare("
        INSERT INTO contacts(email, first_name, last_name)
        VALUES(?, ?, ?)
        ON DUPLICATE KEY UPDATE
          first_name = VALUES(first_name),
          last_name = VALUES(last_name)
      ");
      $contactUpsert->execute([$email, $firstName, $lastName]);

      if (auth_table_exists($pdo, 'user_program_access')) {
        if (user_has_role($adminUser, 'ADMIN')) {
          $pdo->prepare("DELETE FROM user_program_access WHERE user_id = ?")->execute([$targetId]);
        } elseif ($allowedProgramIds) {
          $deleteScoped = $pdo->prepare("
            DELETE FROM user_program_access
            WHERE user_id = ?
              AND program_id IN (" . implode(',', array_fill(0, count($allowedProgramIds), '?')) . ")
          ");
          $deleteScoped->execute(array_merge([$targetId], $allowedProgramIds));
        }
        $hasAccessRoleColumn = auth_program_access_role_column_exists($pdo);
        $grantProgram = $pdo->prepare($hasAccessRoleColumn
          ? "INSERT INTO user_program_access(user_id, program_id, access_role, granted_by_user_id) VALUES(?, ?, ?, ?)"
          : "INSERT INTO user_program_access(user_id, program_id, granted_by_user_id) VALUES(?, ?, ?)"
        );
        foreach ($programRoles as $programId => $programRole) {
          $hasAccessRoleColumn
            ? $grantProgram->execute([$targetId, $programId, $programRole, $currentAdminId])
            : $grantProgram->execute([$targetId, $programId, $currentAdminId]);
        }
      }

      $pdo->commit();
      admin_users_clear_edit_form();
      admin_users_set_notice('ok', 'Utilisateur mis a jour: ' . $email . '.');
      $successReturnTo = $returnTo;
      if (str_starts_with($successReturnTo, '/admin/contact.php')) {
        $successReturnTo = admin_users_replace_query_param($successReturnTo, 'email', $email);
      }
      admin_users_redirect([], $successReturnTo);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      admin_users_set_notice('bad', "Erreur serveur pendant la modification de l'utilisateur.");
      admin_users_redirect([], $returnTo);
    }
  }

  $targetId = (int)($_POST['user_id'] ?? 0);
  if ($action !== 'delete_user' || $targetId <= 0) {
    admin_users_set_notice('bad', 'Action invalide.');
    admin_users_redirect();
  }

  try {
    $pdo->beginTransaction();

    $targetStmt = $pdo->prepare("SELECT id, email, role FROM users WHERE id=? FOR UPDATE");
    $targetStmt->execute([$targetId]);
    $target = $targetStmt->fetch();

    if (!$target) {
      $pdo->rollBack();
      admin_users_set_notice('bad', 'Utilisateur introuvable.');
      admin_users_redirect();
    }

    $targetEmail = (string)$target['email'];
    $targetRole = (string)$target['role'];

    if (!user_can_manage_target_role($adminUser, $targetRole)) {
      $pdo->rollBack();
      admin_users_set_notice('bad', 'Action refusee sur ce role utilisateur.');
      admin_users_redirect();
    }
    if (!user_has_role($adminUser, 'ADMIN') && !auth_users_share_program_scope($pdo, $currentAdminId, $targetId)) {
      $pdo->rollBack();
      admin_users_set_notice('bad', 'Action refusee hors de vos programmes.');
      admin_users_redirect();
    }
    if ($targetId === $currentAdminId) {
      $pdo->rollBack();
      admin_users_set_notice('bad', 'Action refusée: vous ne pouvez pas supprimer votre propre compte.');
      admin_users_redirect();
    }

    if ($targetRole === 'ADMIN') {
      $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='ADMIN'")->fetchColumn();
      if ($adminCount <= 1) {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Impossible: au moins un administrateur doit rester actif.');
        admin_users_redirect();
      }
    }

    $del = $pdo->prepare("DELETE FROM users WHERE id=?");
    $del->execute([$targetId]);
    $pdo->commit();
    admin_users_set_notice('ok', 'Utilisateur supprimé: ' . $targetEmail . '.');
    admin_users_redirect();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    admin_users_set_notice('bad', "Erreur serveur pendant l'operation.");
    admin_users_redirect();
  }
}

$role = strtoupper(trim((string)($_GET['role'] ?? 'ALL')));
$allowedRoles = ['ALL', 'ADMIN', 'OWNER', 'USER'];
if (!in_array($role, $allowedRoles, true)) {
  $role = 'ALL';
}

$emailFilter = trim((string)($_GET['email'] ?? ''));
$nameFilter = trim((string)($_GET['name'] ?? ''));
$lastNameFilter = trim((string)($_GET['last_name'] ?? ''));
$firstNameFilter = trim((string)($_GET['first_name'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
if ($search !== '') {
  if ($emailFilter === '') {
    $emailFilter = $search;
  }
  if ($nameFilter === '' && $lastNameFilter === '' && $firstNameFilter === '') {
    $nameFilter = $search;
  }
}
if ($nameFilter === '') {
  $nameFilter = trim($lastNameFilter . ' ' . $firstNameFilter);
}
$sort = trim((string)($_GET['sort'] ?? 'created_at'));
$dir = strtoupper(trim((string)($_GET['dir'] ?? 'DESC')));

$allowedSort = ['created_at', 'email', 'role', 'session_count', 'passed_exam_count', 'last_session_at'];
$allowedDir = ['ASC', 'DESC'];
if (!in_array($sort, $allowedSort, true)) {
  $sort = 'created_at';
}
if (!in_array($dir, $allowedDir, true)) {
  $dir = 'DESC';
}

$where = [];
$params = [];

if ($role !== 'ALL') {
  $where[] = 'u.role = ?';
  $params[] = $role;
}
if (!user_has_role($adminUser, 'ADMIN') && auth_table_exists($pdo, 'user_program_access')) {
  $where[] = "EXISTS (
    SELECT 1
    FROM user_program_access target_access
    JOIN user_program_access actor_access
      ON actor_access.program_id = target_access.program_id
    WHERE target_access.user_id = u.id
      AND actor_access.user_id = ?
      " . (auth_program_access_role_column_exists($pdo) ? "AND actor_access.access_role = 'OWNER'" : "") . "
  )";
  $params[] = $currentAdminId;
}
if ($emailFilter !== '') {
  $where[] = 'u.email LIKE ?';
  $params[] = '%' . $emailFilter . '%';
}
if ($nameFilter !== '') {
  $where[] = '(c.last_name LIKE ? OR c.first_name LIKE ? OR u.name LIKE ?)';
  $params[] = '%' . $nameFilter . '%';
  $params[] = '%' . $nameFilter . '%';
  $params[] = '%' . $nameFilter . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$statsWhere = [];
$statsParams = [];
$statsJoin = '';
if (!user_has_role($adminUser, 'ADMIN') && auth_table_exists($pdo, 'user_program_access')) {
  $statsJoin = "
    JOIN user_program_access stats_target_access ON stats_target_access.user_id = users.id
    JOIN user_program_access stats_actor_access
      ON stats_actor_access.program_id = stats_target_access.program_id
     AND stats_actor_access.user_id = ?
     " . (auth_program_access_role_column_exists($pdo) ? "AND stats_actor_access.access_role = 'OWNER'" : "") . "
  ";
  $statsParams[] = $currentAdminId;
}
$statsSql = "
  SELECT
    COUNT(DISTINCT users.id) AS total_users,
    COUNT(DISTINCT CASE WHEN users.role='ADMIN' THEN users.id END) AS total_admins,
    COUNT(DISTINCT CASE WHEN users.role='OWNER' THEN users.id END) AS total_owners,
    COUNT(DISTINCT CASE WHEN users.role='USER' THEN users.id END) AS total_standard
  FROM users
  $statsJoin
" . ($statsWhere ? (' WHERE ' . implode(' AND ', $statsWhere)) : '');
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch() ?: ['total_users' => 0, 'total_admins' => 0, 'total_owners' => 0, 'total_standard' => 0];

$baseFrom = "
  FROM users u
  LEFT JOIN contacts c ON c.email = u.email
  LEFT JOIN (
    SELECT
      user_id,
      COUNT(*) AS session_count,
      SUM(session_type='EXAM' AND status='TERMINATED' AND passed=1) AS passed_exam_count,
      MAX(started_at) AS last_session_at
    FROM sessions
    WHERE user_id IS NOT NULL
    GROUP BY user_id
  ) su ON su.user_id = u.id
";

$countStmt = $pdo->prepare("SELECT COUNT(*) " . $baseFrom . " " . $whereSql);
$i = 1;
foreach ($params as $v) {
  $countStmt->bindValue($i++, $v);
}
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();

$limit = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $limit;

$orderSql = match ($sort . ':' . $dir) {
  'email:ASC' => 'u.email ASC',
  'email:DESC' => 'u.email DESC',
  'role:ASC' => 'u.role ASC',
  'role:DESC' => 'u.role DESC',
  'session_count:ASC' => 'COALESCE(su.session_count, 0) ASC',
  'session_count:DESC' => 'COALESCE(su.session_count, 0) DESC',
  'passed_exam_count:ASC' => 'COALESCE(su.passed_exam_count, 0) ASC',
  'passed_exam_count:DESC' => 'COALESCE(su.passed_exam_count, 0) DESC',
  'last_session_at:ASC' => 'su.last_session_at ASC',
  'last_session_at:DESC' => 'su.last_session_at DESC',
  'created_at:ASC' => 'u.created_at ASC',
  default => 'u.created_at DESC',
};

$listSql = "
  SELECT
    u.id,
    u.email,
    u.name,
    " . ($hasEmailControlBypassColumn ? "u.email_control_bypass" : "0 AS email_control_bypass") . ",
    c.first_name,
    c.last_name,
    u.role,
    u.created_at,
    COALESCE(su.session_count, 0) AS session_count,
    COALESCE(su.passed_exam_count, 0) AS passed_exam_count,
    su.last_session_at
  " . $baseFrom . "
  " . $whereSql . "
  ORDER BY " . $orderSql . ", u.id DESC
  LIMIT ? OFFSET ?
";

$listStmt = $pdo->prepare($listSql);
$i = 1;
foreach ($params as $v) {
  $listStmt->bindValue($i++, $v);
}
$listStmt->bindValue($i++, $limit, PDO::PARAM_INT);
$listStmt->bindValue($i++, $offset, PDO::PARAM_INT);
$listStmt->execute();
$users = $listStmt->fetchAll() ?: [];

$programRows = $hasProgramsTable ? admin_users_scope_programs($pdo, $adminUser) : [];
$programLabelsById = [];
foreach ($programRows as $programRow) {
  $programId = (int)($programRow['id'] ?? 0);
  if ($programId > 0) {
    $programLabelsById[$programId] = trim((string)($programRow['name'] ?? 'Programme'));
  }
}
$userProgramMap = [];
if ($users && $hasProgramsTable && $hasUserProgramAccessTable) {
  $userIds = array_values(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $users));
  if ($userIds) {
    $stPrograms = $pdo->query("
      SELECT upa.user_id, upa.program_id, p.name, " . auth_program_access_role_expr($pdo, 'upa') . " AS access_role
      FROM user_program_access upa
      JOIN programs p ON p.id = upa.program_id
      WHERE upa.user_id IN (" . implode(',', array_map('intval', $userIds)) . ")
      ORDER BY p.display_order ASC, p.id ASC
    ");
    foreach ($stPrograms ? ($stPrograms->fetchAll() ?: []) : [] as $row) {
      $uid = (int)($row['user_id'] ?? 0);
      if ($uid <= 0) {
        continue;
      }
      $userProgramMap[$uid][] = [
        'id' => (int)($row['program_id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'access_role' => auth_normalize_program_access_role((string)($row['access_role'] ?? 'USER')),
      ];
    }
  }
}
$notice = $_SESSION['admin_users_notice'] ?? null;
unset($_SESSION['admin_users_notice']);
$createForm = $_SESSION['admin_users_create_form'] ?? null;
unset($_SESSION['admin_users_create_form']);
if (!is_array($createForm)) {
  $createForm = [];
}
$createFirstName = trim((string)($createForm['first_name'] ?? ''));
$createLastName = trim((string)($createForm['last_name'] ?? ''));
$createEmail = trim((string)($createForm['email'] ?? ''));
$createRole = normalize_user_role((string)($createForm['role'] ?? 'USER'));
$createProgramRoles = is_array($createForm['program_roles'] ?? null) ? $createForm['program_roles'] : [];
$createEmailControlBypass = (int)($createForm['email_control_bypass'] ?? 0);
$canAssignAdmin = user_can_assign_role($adminUser, 'ADMIN');
$openCreate = ((string)($_GET['open_create'] ?? '') === '1');
$openEdit = max(0, (int)($_GET['open_edit'] ?? 0));
$editFormSession = $_SESSION['admin_users_edit_form'] ?? null;
unset($_SESSION['admin_users_edit_form']);
$editFormUserId = 0;
$editForm = [];
if (is_array($editFormSession)) {
  $editFormUserId = (int)($editFormSession['user_id'] ?? 0);
  if (is_array($editFormSession['data'] ?? null)) {
    $editForm = $editFormSession['data'];
  }
}
function admin_users_sort_link(array $qs, string $key): string {
  $currentSort = (string)($qs['sort'] ?? 'created_at');
  $currentDir = strtoupper((string)($qs['dir'] ?? 'DESC'));
  $next = $qs;
  $next['sort'] = $key;
  if ($currentSort !== $key) {
    $next['dir'] = 'DESC';
  } else {
    $next['dir'] = ($currentDir === 'DESC') ? 'ASC' : 'DESC';
  }
  unset($next['page']);
  return '/admin/users.php?' . http_build_query($next);
}
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('admin.users.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card admin-page-shell">
      <div class="admin-head admin-page-hero">
        <div class="admin-head-copy">
          <p class="admin-page-eyebrow"><?= h(t('admin.nav.group_admin', [], $lang)) ?></p>
          <h2 class="h1"><?= h(t('admin.users.title', [], $lang)) ?></h2>
          <p class="sub"><?= h(t('admin.users.subtitle', [], $lang)) ?></p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('users'); ?>
        </div>
      </div>

      <?php if (is_array($notice) && isset($notice['type'], $notice['text'])): ?>
        <div class="admin-notice <?= ((string)$notice['type'] === 'ok') ? 'is-ok' : 'is-bad' ?>">
          <?= h((string)$notice['text']) ?>
        </div>
      <?php endif; ?>

      <div class="admin-stats-grid">
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h(t('admin.users.stat_total', [], $lang)) ?></span>
          <strong class="admin-stat-value"><?= (int)$stats['total_users'] ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h(t('admin.users.stat_admins', [], $lang)) ?></span>
          <strong class="admin-stat-value"><?= (int)$stats['total_admins'] ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h(t('admin.users.stat_owners', [], $lang)) ?></span>
          <strong class="admin-stat-value"><?= (int)$stats['total_owners'] ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label"><?= h(t('admin.users.stat_users', [], $lang)) ?></span>
          <strong class="admin-stat-value"><?= (int)$stats['total_standard'] ?></strong>
        </article>
      </div>

      <div class="admin-page-layout">
      <section class="admin-section-panel admin-section-panel-accent">

      <div class="users-create-toggle">
        <button
          type="button"
          class="btn users-create-toggle-btn admin-primary-action-btn"
          id="users-create-toggle-btn"
          aria-expanded="<?= $openCreate ? 'true' : 'false' ?>"
          aria-controls="users-create-panel"
        >
          <?= h(t('admin.users.create_btn', [], $lang)) ?>
        </button>
      </div>

      <div class="users-create<?= $openCreate ? ' is-open' : '' ?>" id="users-create-panel" <?= $openCreate ? '' : 'hidden' ?>>
        <div class="section-head admin-section-head">
          <div>
            <h3 class="h1"><?= h(t('admin.users.create_title', [], $lang)) ?></h3>
            <p class="sub"><?= h(t('admin.users.create_subtitle', [], $lang)) ?></p>
          </div>
        </div>
        <form method="post" class="users-create-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <input type="hidden" name="action" value="create_user">
          <div class="users-create-grid">
            <div>
              <label class="label" for="create-first-name"><?= h(t('admin.users.firstname', [], $lang)) ?></label>
              <input class="input" id="create-first-name" name="first_name" type="text" maxlength="100" required value="<?= h($createFirstName) ?>" autocomplete="given-name">
            </div>
            <div>
              <label class="label" for="create-last-name"><?= h(t('admin.users.lastname', [], $lang)) ?></label>
              <input class="input" id="create-last-name" name="last_name" type="text" maxlength="100" required value="<?= h($createLastName) ?>" autocomplete="family-name">
            </div>
            <div>
              <label class="label" for="create-email"><?= h(t('admin.common.email', [], $lang)) ?></label>
              <input class="input" id="create-email" name="email" type="email" required value="<?= h($createEmail) ?>" autocomplete="email">
            </div>
            <div>
              <label class="label" for="create-password"><?= h(t('admin.users.password', [], $lang)) ?></label>
              <input class="input" id="create-password" name="password" type="password" minlength="8" required autocomplete="new-password">
            </div>
            <div>
              <label class="label" for="create-password2"><?= h(t('admin.users.password_confirm', [], $lang)) ?></label>
              <input class="input" id="create-password2" name="password2" type="password" minlength="8" required autocomplete="new-password">
            </div>
            <input type="hidden" name="role" value="USER">
            <?php if ($canAssignAdmin): ?>
              <div class="users-multiselect">
                <label class="admin-inline-checkbox">
                  <input type="checkbox" name="role" value="ADMIN" data-admin-role-toggle <?= $createRole === 'ADMIN' ? 'checked' : '' ?>>
                  <span><?= h(t('admin.users.role_checkbox_admin', [], $lang)) ?></span>
                </label>
              </div>
            <?php endif; ?>
            <div class="users-multiselect" data-program-role-block>
              <span class="label"><?= h(t('admin.users.role_program', [], $lang)) ?></span>
              <div class="users-checkbox-list">
                <?php foreach ($programRows as $programRow): ?>
                  <?php $programIdOption = (int)($programRow['id'] ?? 0); ?>
                  <?php $selectedProgramRole = auth_normalize_program_access_role((string)($createProgramRoles[$programIdOption] ?? '')); ?>
                  <?php $selectedProgramRole = isset($createProgramRoles[$programIdOption]) ? $selectedProgramRole : 'NONE'; ?>
                  <label class="users-checkbox-item users-program-role-item">
                    <span><?= h((string)($programLabelsById[$programIdOption] ?? ($programRow['name'] ?? 'Programme'))) ?></span>
                    <select class="input users-program-role-select" name="program_roles[<?= $programIdOption ?>]">
                      <option value="NONE" <?= $selectedProgramRole === 'NONE' ? 'selected' : '' ?>><?= h(t('admin.users.role_none', [], $lang)) ?></option>
                      <option value="USER" <?= $selectedProgramRole === 'USER' ? 'selected' : '' ?>><?= h(t('admin.users.role_user', [], $lang)) ?></option>
                      <?php if (user_has_role($adminUser, ['ADMIN', 'OWNER'])): ?>
                        <option value="OWNER" <?= $selectedProgramRole === 'OWNER' ? 'selected' : '' ?>><?= h(t('admin.users.role_owner', [], $lang)) ?></option>
                      <?php endif; ?>
                    </select>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php if (user_has_role($adminUser, 'ADMIN') && $hasEmailControlBypassColumn): ?>
              <div class="users-multiselect">
                <span class="label"><?= h(t('admin.users.email_bypass', [], $lang)) ?></span>
                <label class="admin-inline-checkbox">
                  <input type="checkbox" name="email_control_bypass" value="1" <?= $createEmailControlBypass === 1 ? 'checked' : '' ?>>
                  <span><?= h(t('admin.users.email_bypass_hint', [], $lang)) ?></span>
                </label>
              </div>
            <?php endif; ?>
          </div>
          <div class="users-create-actions">
            <button class="btn" type="submit"><?= h(t('admin.users.create_title', [], $lang)) ?></button>
            <button class="btn ghost" type="button" id="users-create-cancel-btn"><?= h(t('admin.common.cancel', [], $lang)) ?></button>
          </div>
        </form>
      </div>

      </section>

      <section class="admin-section-panel">
      <div class="section-head admin-section-head">
        <div>
          <h3 class="h1"><?= h(t('admin.users.directory_title', [], $lang)) ?></h3>
          <p class="sub sessions-meta"><?= h(t('admin.common.page_of', ['page' => $page, 'total' => $totalPages, 'count' => $totalRows], $lang)) ?></p>
        </div>
      </div>

      <form method="get" class="filters-grid users-filters admin-panel-surface">
        <div>
          <label class="label" for="email">Email</label>
          <input class="input" id="email" type="text" name="email" value="<?= h($emailFilter) ?>" placeholder="Email">
        </div>
        <div>
          <label class="label" for="name"><?= h(t('admin.users.col_name', [], $lang)) ?></label>
          <input class="input" id="name" type="text" name="name" value="<?= h($nameFilter) ?>" placeholder="<?= h(t('admin.users.filter_name_ph', [], $lang)) ?>">
        </div>
        <div>
          <label class="label" for="role"><?= h(t('admin.common.role', [], $lang)) ?></label>
          <select class="input" id="role" name="role">
            <option value="ALL" <?= $role === 'ALL' ? 'selected' : '' ?>><?= h(t('admin.common.all', [], $lang)) ?></option>
            <option value="ADMIN" <?= $role === 'ADMIN' ? 'selected' : '' ?>><?= h(t('admin.users.stat_admins', [], $lang)) ?></option>
            <option value="OWNER" <?= $role === 'OWNER' ? 'selected' : '' ?>><?= h(t('admin.users.stat_owners', [], $lang)) ?></option>
            <option value="USER" <?= $role === 'USER' ? 'selected' : '' ?>><?= h(t('admin.users.stat_users', [], $lang)) ?></option>
          </select>
        </div>
        <div class="filters-actions">
          <button class="btn" type="submit"><?= h(t('admin.common.filter', [], $lang)) ?></button>
          <a class="btn ghost" href="/admin/users.php"><?= h(t('admin.common.reset', [], $lang)) ?></a>
        </div>
      </form>

      <div class="table-wrap admin-table-panel">
        <?php if (!$users): ?>
          <p class="empty-state"><?= h(t('admin.users.none', [], $lang)) ?></p>
        <?php else: ?>
          <table class="table questions-table users-directory-table">
            <colgroup>
              <col class="users-directory-col-email">
              <col class="users-directory-col-name">
              <col class="users-directory-col-firstname">
              <col class="users-directory-col-role">
              <col class="users-directory-col-created">
              <col class="users-directory-col-sessions">
              <col class="users-directory-col-actions">
            </colgroup>
            <thead>
              <tr>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'email')) ?>">
                    Email<?php if ($sort === 'email'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th><?= h(t('admin.users.lastname', [], $lang)) ?></th>
                <th><?= h(t('admin.users.firstname', [], $lang)) ?></th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'role')) ?>">
                    <?= h(t('admin.common.role', [], $lang)) ?><?php if ($sort === 'role'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'created_at')) ?>">
                    <?= h(t('admin.users.col_created', [], $lang)) ?><?php if ($sort === 'created_at'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'session_count')) ?>">
                    <?= h(t('admin.users.col_sessions', [], $lang)) ?><?php if ($sort === 'session_count'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th><?= h(t('admin.common.actions', [], $lang)) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                  $uid = (int)$u['id'];
                  $isSelf = ($uid === $currentAdminId);
                  $targetRole = normalize_user_role((string)$u['role']);
                  $isAdmin = ($targetRole === 'ADMIN');
                  $isOwner = ($targetRole === 'OWNER');
                  $canManageTarget = $isSelf || user_can_manage_target_role($adminUser, $targetRole);
                  $programBadges = $userProgramMap[$uid] ?? [];
                  $programRolesById = [];
                  foreach ($programBadges as $programBadge) {
                    $programRolesById[(int)($programBadge['id'] ?? 0)] = auth_normalize_program_access_role((string)($programBadge['access_role'] ?? 'USER'));
                  }
                  $profileLink = '/admin/contact.php?email=' . urlencode((string)$u['email']);
                ?>
                <tr>
                  <td class="users-directory-email">
                    <a href="<?= h($profileLink) ?>" title="<?= h((string)$u['email']) ?>">
                      <?= h((string)$u['email']) ?>
                    </a>
                  </td>
                  <?php
                    $firstName = trim((string)($u['first_name'] ?? ''));
                    $lastName = trim((string)($u['last_name'] ?? ''));
                    if ($firstName === '' && $lastName === '') {
                      [$firstGuess, $lastGuess] = admin_users_guess_first_last((string)($u['name'] ?? ''));
                      $firstName = $firstGuess;
                      $lastName = $lastGuess;
                    }
                    $isOpenEdit = ($openEdit === $uid);
                    if ($isOpenEdit && $editFormUserId === $uid) {
                      $firstName = trim((string)($editForm['first_name'] ?? $firstName));
                      $lastName = trim((string)($editForm['last_name'] ?? $lastName));
                    }
                    $editEmail = $isOpenEdit && $editFormUserId === $uid
                      ? trim((string)($editForm['email'] ?? (string)$u['email']))
                      : (string)$u['email'];
                    $editRole = $isOpenEdit && $editFormUserId === $uid
                      ? normalize_user_role((string)($editForm['role'] ?? $targetRole))
                      : $targetRole;
                    $canToggleAdmin = $canAssignAdmin || $editRole === 'ADMIN';
                    $editQs = $_GET;
                    $editQs['open_edit'] = $uid;
                    $editLink = '/admin/users.php?' . http_build_query($editQs);
                    $closeQs = $_GET;
                    unset($closeQs['open_edit']);
                    $closeLink = '/admin/users.php' . ($closeQs ? ('?' . http_build_query($closeQs)) : '');
                  ?>
                  <td class="users-directory-name"><?= h($lastName) !== '' ? h($lastName) : '-' ?></td>
                  <td class="users-directory-firstname"><?= h($firstName) !== '' ? h($firstName) : '-' ?></td>
                  <td class="users-directory-role">
                  <?php if ($isAdmin): ?>
                      <span class="badge ok">ADMIN</span>
                    <?php elseif ($isOwner): ?>
                      <span class="badge ok">OWNER</span>
                    <?php else: ?>
                      <span class="badge">USER</span>
                    <?php endif; ?>
                  </td>
                  <td class="users-directory-created" title="<?= h((string)$u['created_at']) ?>"><?= h(admin_users_format_short_date((string)$u['created_at'])) ?></td>
                  <td class="users-directory-sessions"><?= (int)$u['session_count'] ?></td>
                  <td class="actions-cell users-directory-actions">
                    <div class="users-directory-actions-wrap">
                      <a class="btn ghost icon-btn" href="<?= h($profileLink) ?>" aria-label="<?= h(t('admin.users.open_profile', [], $lang)) ?>" title="<?= h(t('admin.users.open_profile', [], $lang)) ?>">
                        <svg class="icon-edit" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                          <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm2.92 2.33H5v-.92l8.06-8.06.92.92L5.92 19.58zM20.71 7.04a1.003 1.003 0 0 0 0-1.42l-2.34-2.34a1.003 1.003 0 0 0-1.42 0l-1.13 1.13 3.75 3.75 1.14-1.12z"/>
                        </svg>
                      </a>
                      <?php if (user_has_role($adminUser, 'ADMIN')): ?>
                      <form method="post" class="inline-action-form js-delete-user-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <button
                          class="btn ghost icon-btn danger js-delete-user-btn"
                          type="button"
                          <?= (!$canManageTarget || $isSelf) ? 'disabled' : '' ?>
                          <?= $isSelf ? ('title="' . h(t('admin.users.delete_self_title', [], $lang)) . '"') : (!$canManageTarget ? ('title="' . h(t('admin.users.delete_forbidden_title', [], $lang)) . '"') : '') ?>
                          data-user-email="<?= h((string)$u['email']) ?>"
                          data-user-name="<?= h(trim((string)$u['first_name'] . ' ' . (string)$u['last_name'])) ?>"
                          aria-label="<?= h(t('admin.users.delete_user', [], $lang)) ?>"
                          title="<?= h(t('admin.common.delete', [], $lang)) ?>"
                        >
                          <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                          </svg>
                        </button>
                      </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if ($isOpenEdit && $canManageTarget): ?>
                  <?php
                    $editProgramRoles = $isOpenEdit && $editFormUserId === $uid && is_array($editForm['program_roles'] ?? null)
                      ? $editForm['program_roles']
                      : $programRolesById;
                    $editEmailControlBypass = $isOpenEdit && $editFormUserId === $uid
                      ? (int)($editForm['email_control_bypass'] ?? 0)
                      : (int)($u['email_control_bypass'] ?? 0);
                  ?>
                  <tr class="users-edit-row">
                    <td colspan="<?= $showOrganizationColumn ? '8' : '7' ?>">
                      <form method="post" class="users-edit-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="user_id" value="<?= (int)$uid ?>">
                        <div class="users-edit-grid">
                          <div>
                            <label class="label" for="edit-first-<?= (int)$uid ?>"><?= h(t('admin.users.firstname', [], $lang)) ?></label>
                            <input class="input" id="edit-first-<?= (int)$uid ?>" name="first_name" type="text" maxlength="100" required value="<?= h($firstName) ?>">
                          </div>
                          <div>
                            <label class="label" for="edit-last-<?= (int)$uid ?>"><?= h(t('admin.users.lastname', [], $lang)) ?></label>
                            <input class="input" id="edit-last-<?= (int)$uid ?>" name="last_name" type="text" maxlength="100" required value="<?= h($lastName) ?>">
                          </div>
                          <div>
                            <label class="label" for="edit-email-<?= (int)$uid ?>"><?= h(t('admin.common.email', [], $lang)) ?></label>
                            <input class="input" id="edit-email-<?= (int)$uid ?>" name="email" type="email" required value="<?= h($editEmail) ?>">
                          </div>
                          <div>
                            <label class="label" for="edit-pass-<?= (int)$uid ?>"><?= h(t('admin.users.password_new', [], $lang)) ?></label>
                            <input class="input" id="edit-pass-<?= (int)$uid ?>" name="new_password" type="password" minlength="8" autocomplete="new-password">
                          </div>
                          <div>
                            <label class="label" for="edit-pass2-<?= (int)$uid ?>"><?= h(t('admin.users.password_new_confirm', [], $lang)) ?></label>
                            <input class="input" id="edit-pass2-<?= (int)$uid ?>" name="new_password2" type="password" minlength="8" autocomplete="new-password">
                          </div>
                          <input type="hidden" name="role" value="USER">
                          <?php if ($canToggleAdmin): ?>
                            <div class="users-multiselect">
                              <label class="admin-inline-checkbox">
                                <input type="checkbox" name="role" value="ADMIN" data-admin-role-toggle <?= $editRole === 'ADMIN' ? 'checked' : '' ?> <?= $canAssignAdmin ? '' : 'disabled' ?>>
                                <span><?= h(t('admin.users.role_checkbox_admin', [], $lang)) ?></span>
                              </label>
                            </div>
                          <?php endif; ?>
                          <div class="users-multiselect" data-program-role-block>
                            <span class="label"><?= h(t('admin.users.role_program', [], $lang)) ?></span>
                            <div class="users-checkbox-list">
                              <?php foreach ($programRows as $programRow): ?>
                                <?php $programIdOption = (int)($programRow['id'] ?? 0); ?>
                                <?php $selectedProgramRole = auth_normalize_program_access_role((string)($editProgramRoles[$programIdOption] ?? '')); ?>
                                <?php $selectedProgramRole = isset($editProgramRoles[$programIdOption]) ? $selectedProgramRole : 'NONE'; ?>
                                <label class="users-checkbox-item users-program-role-item">
                                  <span><?= h((string)($programLabelsById[$programIdOption] ?? ($programRow['name'] ?? 'Programme'))) ?></span>
                                  <select class="input users-program-role-select" name="program_roles[<?= $programIdOption ?>]">
                                    <option value="NONE" <?= $selectedProgramRole === 'NONE' ? 'selected' : '' ?>><?= h(t('admin.users.role_none', [], $lang)) ?></option>
                                    <option value="USER" <?= $selectedProgramRole === 'USER' ? 'selected' : '' ?>><?= h(t('admin.users.role_user', [], $lang)) ?></option>
                                    <?php if (user_has_role($adminUser, ['ADMIN', 'OWNER'])): ?>
                                      <option value="OWNER" <?= $selectedProgramRole === 'OWNER' ? 'selected' : '' ?>><?= h(t('admin.users.role_owner', [], $lang)) ?></option>
                                    <?php endif; ?>
                                  </select>
                                </label>
                              <?php endforeach; ?>
                            </div>
                          </div>
                          <?php if (user_has_role($adminUser, 'ADMIN') && $hasEmailControlBypassColumn): ?>
                            <div class="users-multiselect">
                              <span class="label"><?= h(t('admin.users.email_bypass', [], $lang)) ?></span>
                              <label class="admin-inline-checkbox">
                                <input type="checkbox" name="email_control_bypass" value="1" <?= $editEmailControlBypass === 1 ? 'checked' : '' ?>>
                                <span><?= h(t('admin.users.email_bypass_hint', [], $lang)) ?></span>
                              </label>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="users-edit-actions">
                          <button class="btn" type="submit"><?= h(t('admin.common.save', [], $lang)) ?></button>
                          <a class="btn ghost" href="<?= h($closeLink) ?>"><?= h(t('admin.common.close', [], $lang)) ?></a>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <?php
        $qs = $_GET;
        unset($qs['page']);
        $base = '/admin/users.php';
        $common = $qs ? ('?' . http_build_query($qs)) : '';
        $sep = $common ? '&' : '?';
      ?>
      <div class="sessions-pagination">
        <?php if ($page > 1): ?>
          <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page - 1)) ?>">&larr;</a>
        <?php else: ?>
          <button class="btn ghost" disabled>&larr;</button>
        <?php endif; ?>

        <?php if ($totalPages <= 7): ?>
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
        <?php else: ?>
          <a class="btn <?= $page === 1 ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=1') ?>">1</a>
          <?php if ($page > 4): ?>
            <span class="pagination-ellipsis" aria-hidden="true">...</span>
          <?php endif; ?>
          <?php
            $startPage = max(2, $page - 1);
            $endPage = min($totalPages - 1, $page + 1);
            for ($p = $startPage; $p <= $endPage; $p++):
          ?>
            <a class="btn <?= $p === $page ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $p) ?>"><?= (int)$p ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages - 3): ?>
            <span class="pagination-ellipsis" aria-hidden="true">...</span>
          <?php endif; ?>
          <a class="btn <?= $page === $totalPages ? '' : 'ghost' ?>" href="<?= h($base . $common . $sep . 'page=' . $totalPages) ?>"><?= (int)$totalPages ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a class="btn ghost" href="<?= h($base . $common . $sep . 'page=' . ($page + 1)) ?>">&rarr;</a>
        <?php else: ?>
          <button class="btn ghost" disabled>&rarr;</button>
        <?php endif; ?>
      </div>
      </section>
      </div>
    </div>
  </div>
  <div id="delete-user-modal" class="exam-abandon-overlay" style="display:none;">
    <div class="exam-abandon-dialog">
      <div class="exam-abandon-icon">⚠️</div>
      <h3 class="exam-abandon-title"><?= h(match($lang) { 'en' => 'Delete this user?', 'es' => '¿Eliminar este usuario?', 'jp' => 'このユーザーを削除しますか？', default => 'Supprimer cet utilisateur ?' }) ?></h3>
      <p class="exam-abandon-warning" id="delete-user-modal-email" style="color:var(--text);font-weight:600;font-size:14px;"></p>
      <p class="exam-abandon-body"><?= h(match($lang) {
        'en' => 'The account is permanently deleted. Session history is kept but anonymised. Certifications remain visible via the contact\'s email.',
        'es' => 'La cuenta se elimina definitivamente. El historial de sesiones se conserva pero anonimizado. Las certificaciones siguen visibles mediante el email del contacto.',
        'jp' => 'アカウントは完全に削除されます。セッション履歴は保持されますが匿名化されます。認定はコンタクトのメールから確認できます。',
        default => 'Le compte est supprimé définitivement. L\'historique des sessions est conservé mais anonymisé. Les certifications restent visibles via l\'email du contact.',
      }) ?></p>
      <div class="exam-abandon-actions">
        <button type="button" id="delete-user-cancel" class="btn ghost"><?= h(match($lang) { 'en' => 'Cancel', 'es' => 'Cancelar', 'jp' => 'キャンセル', default => 'Annuler' }) ?></button>
        <button type="button" id="delete-user-confirm" class="btn danger"><?= h(match($lang) { 'en' => 'Delete', 'es' => 'Eliminar', 'jp' => '削除', default => 'Supprimer' }) ?></button>
      </div>
    </div>
  </div>

  <script>
    (function () {
      var toggleBtn = document.getElementById('users-create-toggle-btn');
      var cancelBtn = document.getElementById('users-create-cancel-btn');
      var panel = document.getElementById('users-create-panel');
      if (!toggleBtn || !panel) return;

      function setOpen(open) {
        toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.hidden = !open;
        panel.classList.toggle('is-open', open);
        if (open) {
          var firstInput = panel.querySelector('#create-first-name');
          if (firstInput) firstInput.focus();
        }
      }

      toggleBtn.addEventListener('click', function () {
        var isOpen = toggleBtn.getAttribute('aria-expanded') === 'true';
        setOpen(!isOpen);
      });

      if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
          setOpen(false);
        });
      }

      document.querySelectorAll('form.users-create-form, form.users-edit-form').forEach(function (form) {
        var adminToggle = form.querySelector('[data-admin-role-toggle]');
        var programRoleBlock = form.querySelector('[data-program-role-block]');
        if (!adminToggle || !programRoleBlock) return;

        function syncProgramRoleVisibility() {
          programRoleBlock.hidden = adminToggle.checked;
        }

        syncProgramRoleVisibility();
        adminToggle.addEventListener('change', syncProgramRoleVisibility);
      });
    })();

    (function () {
      var modal      = document.getElementById('delete-user-modal');
      var modalEmail = document.getElementById('delete-user-modal-email');
      var confirmBtn = document.getElementById('delete-user-confirm');
      var cancelBtn  = document.getElementById('delete-user-cancel');
      var pendingForm = null;

      document.querySelectorAll('.js-delete-user-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (btn.disabled) return;
          pendingForm = btn.closest('.js-delete-user-form');
          var name  = btn.getAttribute('data-user-name') || '';
          var email = btn.getAttribute('data-user-email') || '';
          if (modalEmail) modalEmail.textContent = name ? name + ' — ' + email : email;
          if (modal) modal.style.display = 'flex';
        });
      });

      if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
          if (modal) modal.style.display = 'none';
          if (pendingForm) pendingForm.submit();
        });
      }

      if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
          if (modal) modal.style.display = 'none';
          pendingForm = null;
        });
      }

      if (modal) {
        modal.addEventListener('click', function (e) {
          if (e.target === modal) { modal.style.display = 'none'; pendingForm = null; }
        });
      }
    })();
  </script>
</body>
</html>

