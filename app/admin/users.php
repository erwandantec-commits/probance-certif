<?php
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin();
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';

$pdo = db();
$currentAdminId = (int)$adminUser['id'];

if (empty($_SESSION['admin_users_csrf']) || !is_string($_SESSION['admin_users_csrf'])) {
  $_SESSION['admin_users_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['admin_users_csrf'];

function admin_users_redirect(array $params = []): void {
  $base = '/admin/users.php';
  if ($params) {
    $base .= '?' . http_build_query($params);
  }
  header('Location: ' . $base);
  exit;
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
    $newRole = strtoupper(trim((string)($_POST['new_role'] ?? 'USER')));
    if (!in_array($newRole, ['USER', 'ADMIN'], true)) {
      $newRole = 'USER';
    }

    admin_users_set_create_form([
      'first_name' => $firstName,
      'last_name' => $lastName,
      'email' => $email,
      'new_role' => $newRole,
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

      $ins = $pdo->prepare("INSERT INTO users(email, password_hash, name, role) VALUES(?, ?, ?, ?)");
      $ins->execute([$email, $hash, $fullName, $newRole]);

      $contactUpsert = $pdo->prepare("
        INSERT INTO contacts(email, first_name, last_name)
        VALUES(?, ?, ?)
        ON DUPLICATE KEY UPDATE
          first_name = VALUES(first_name),
          last_name = VALUES(last_name)
      ");
      $contactUpsert->execute([$email, $firstName, $lastName]);

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
    $targetId = (int)($_POST['user_id'] ?? 0);
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $newRole = strtoupper(trim((string)($_POST['new_role'] ?? 'USER')));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $newPassword2 = (string)($_POST['new_password2'] ?? '');
    if (!in_array($newRole, ['USER', 'ADMIN'], true)) {
      $newRole = 'USER';
    }

    admin_users_set_edit_form($targetId, [
      'first_name' => $firstName,
      'last_name' => $lastName,
      'email' => $email,
      'new_role' => $newRole,
    ]);

    if ($targetId <= 0) {
      admin_users_set_notice('bad', 'Utilisateur invalide.');
      admin_users_redirect();
    }
    if ($firstName === '' || $lastName === '' || $email === '') {
      admin_users_set_notice('bad', 'Prénom, nom et email sont obligatoires.');
      admin_users_redirect(['open_edit' => $targetId]);
    }
    if (strlen($firstName) > 100 || strlen($lastName) > 100) {
      admin_users_set_notice('bad', 'Prénom/nom trop longs (max 100 caractères).');
      admin_users_redirect(['open_edit' => $targetId]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      admin_users_set_notice('bad', 'Email invalide.');
      admin_users_redirect(['open_edit' => $targetId]);
    }
    if ($newPassword !== '' || $newPassword2 !== '') {
      if (strlen($newPassword) < 8) {
        admin_users_set_notice('bad', 'Mot de passe trop court (min 8 caractères).');
        admin_users_redirect(['open_edit' => $targetId]);
      }
      if ($newPassword !== $newPassword2) {
        admin_users_set_notice('bad', 'Les mots de passe ne correspondent pas.');
        admin_users_redirect(['open_edit' => $targetId]);
      }
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

      $oldRole = (string)$target['role'];
      $existsStmt = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
      $existsStmt->execute([$email, $targetId]);
      if ($existsStmt->fetch()) {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Un autre utilisateur existe déjà avec cet email.');
        admin_users_redirect(['open_edit' => $targetId]);
      }

      if ($targetId === $currentAdminId && $newRole !== 'ADMIN') {
        $pdo->rollBack();
        admin_users_set_notice('bad', 'Action refusée: vous ne pouvez pas retirer vos propres droits admin.');
        admin_users_redirect(['open_edit' => $targetId]);
      }

      if ($oldRole === 'ADMIN' && $newRole !== 'ADMIN') {
        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='ADMIN'")->fetchColumn();
        if ($adminCount <= 1) {
          $pdo->rollBack();
          admin_users_set_notice('bad', 'Impossible: au moins un administrateur doit rester actif.');
          admin_users_redirect(['open_edit' => $targetId]);
        }
      }

      $fullName = trim($firstName . ' ' . $lastName);
      if ($newPassword !== '') {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET email=?, name=?, role=?, password_hash=? WHERE id=?");
        $upd->execute([$email, $fullName, $newRole, $hash, $targetId]);
      } else {
        $upd = $pdo->prepare("UPDATE users SET email=?, name=?, role=? WHERE id=?");
        $upd->execute([$email, $fullName, $newRole, $targetId]);
      }

      $contactUpsert = $pdo->prepare("
        INSERT INTO contacts(email, first_name, last_name)
        VALUES(?, ?, ?)
        ON DUPLICATE KEY UPDATE
          first_name = VALUES(first_name),
          last_name = VALUES(last_name)
      ");
      $contactUpsert->execute([$email, $firstName, $lastName]);

      $pdo->commit();
      admin_users_clear_edit_form();
      admin_users_set_notice('ok', 'Utilisateur mis a jour: ' . $email . '.');
      admin_users_redirect(['search' => $email]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      admin_users_set_notice('bad', "Erreur serveur pendant la modification de l'utilisateur.");
      admin_users_redirect(['open_edit' => $targetId]);
    }
  }

  $targetId = (int)($_POST['user_id'] ?? 0);
  if (!in_array($action, ['grant_admin', 'revoke_admin'], true) || $targetId <= 0) {
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

    if ($action === 'grant_admin') {
      if ($targetRole === 'ADMIN') {
        $pdo->commit();
        admin_users_set_notice('ok', 'Aucune modification: ' . $targetEmail . ' est déjà admin.');
        admin_users_redirect();
      }

      $upd = $pdo->prepare("UPDATE users SET role='ADMIN' WHERE id=?");
      $upd->execute([$targetId]);
      $pdo->commit();
      admin_users_set_notice('ok', 'Droits admin accordes a ' . $targetEmail . '.');
      admin_users_redirect();
    }

    if ($targetRole !== 'ADMIN') {
      $pdo->commit();
      admin_users_set_notice('ok', 'Aucune modification: ' . $targetEmail . " n'est pas admin.");
      admin_users_redirect();
    }

    if ($targetId === $currentAdminId) {
      $pdo->rollBack();
      admin_users_set_notice('bad', 'Action refusée: vous ne pouvez pas retirer vos propres droits admin.');
      admin_users_redirect();
    }

    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='ADMIN'")->fetchColumn();
    if ($adminCount <= 1) {
      $pdo->rollBack();
      admin_users_set_notice('bad', 'Impossible: au moins un administrateur doit rester actif.');
      admin_users_redirect();
    }

    $upd = $pdo->prepare("UPDATE users SET role='USER' WHERE id=?");
    $upd->execute([$targetId]);
    $pdo->commit();
    admin_users_set_notice('ok', 'Droits admin retirés pour ' . $targetEmail . '.');
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
$allowedRoles = ['ALL', 'ADMIN', 'USER'];
if (!in_array($role, $allowedRoles, true)) {
  $role = 'ALL';
}

$search = trim((string)($_GET['search'] ?? ''));
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
if ($search !== '') {
  $where[] = '(u.email LIKE ? OR u.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)';
  $params[] = '%' . $search . '%';
  $params[] = '%' . $search . '%';
  $params[] = '%' . $search . '%';
  $params[] = '%' . $search . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$stats = $pdo->query("
  SELECT
    COUNT(*) AS total_users,
    SUM(role='ADMIN') AS total_admins,
    SUM(role='USER') AS total_standard
  FROM users
")->fetch() ?: ['total_users' => 0, 'total_admins' => 0, 'total_standard' => 0];

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

$orderSql = match ($sort) {
  'email' => 'u.email',
  'role' => 'u.role',
  'session_count' => 'COALESCE(su.session_count, 0)',
  'passed_exam_count' => 'COALESCE(su.passed_exam_count, 0)',
  'last_session_at' => 'su.last_session_at',
  default => 'u.created_at',
};

$listSql = "
  SELECT
    u.id,
    u.email,
    u.name,
    c.first_name,
    c.last_name,
    u.role,
    u.created_at,
    COALESCE(su.session_count, 0) AS session_count,
    COALESCE(su.passed_exam_count, 0) AS passed_exam_count,
    su.last_session_at
  " . $baseFrom . "
  " . $whereSql . "
  ORDER BY " . $orderSql . " " . $dir . ", u.id DESC
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
$createRole = strtoupper(trim((string)($createForm['new_role'] ?? 'USER')));
if (!in_array($createRole, ['USER', 'ADMIN'], true)) {
  $createRole = 'USER';
}
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
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Utilisateurs</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Utilisateurs</h2>
          <p class="sub">Gestion des comptes, rôles et habilitations administration</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('users'); ?>
        </div>
      </div>

      <hr class="separator">

      <?php if (is_array($notice) && isset($notice['type'], $notice['text'])): ?>
        <div class="admin-notice <?= ((string)$notice['type'] === 'ok') ? 'is-ok' : 'is-bad' ?>">
          <?= h((string)$notice['text']) ?>
        </div>
      <?php endif; ?>

      <div class="users-create-toggle">
        <button
          type="button"
          class="btn users-create-toggle-btn admin-primary-action-btn"
          id="users-create-toggle-btn"
          aria-expanded="<?= $openCreate ? 'true' : 'false' ?>"
          aria-controls="users-create-panel"
        >
          + Créer un utilisateur
        </button>
      </div>

      <div class="users-create<?= $openCreate ? ' is-open' : '' ?>" id="users-create-panel" <?= $openCreate ? '' : 'hidden' ?>>
        <div class="section-head">
          <div>
            <h3 class="h1 users-create-title">Créer un utilisateur</h3>
            <p class="sub">Création immédiate d'un compte avec rôle USER ou ADMIN.</p>
          </div>
        </div>
        <form method="post" class="users-create-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <input type="hidden" name="action" value="create_user">
          <div class="users-create-grid">
            <div>
              <label class="label" for="create-first-name">Prénom</label>
              <input class="input" id="create-first-name" name="first_name" type="text" maxlength="100" required value="<?= h($createFirstName) ?>" autocomplete="given-name">
            </div>
            <div>
              <label class="label" for="create-last-name">Nom</label>
              <input class="input" id="create-last-name" name="last_name" type="text" maxlength="100" required value="<?= h($createLastName) ?>" autocomplete="family-name">
            </div>
            <div>
              <label class="label" for="create-email">Email</label>
              <input class="input" id="create-email" name="email" type="email" required value="<?= h($createEmail) ?>" autocomplete="email">
            </div>
            <div>
              <label class="label" for="create-role">Rôle initial</label>
              <select class="input" id="create-role" name="new_role">
                <option value="USER" <?= $createRole === 'USER' ? 'selected' : '' ?>>USER</option>
                <option value="ADMIN" <?= $createRole === 'ADMIN' ? 'selected' : '' ?>>ADMIN</option>
              </select>
            </div>
            <div>
              <label class="label" for="create-password">Mot de passe</label>
              <input class="input" id="create-password" name="password" type="password" minlength="8" required autocomplete="new-password">
            </div>
            <div>
              <label class="label" for="create-password2">Confirmer le mot de passe</label>
              <input class="input" id="create-password2" name="password2" type="password" minlength="8" required autocomplete="new-password">
            </div>
          </div>
          <div class="users-create-actions">
            <button class="btn" type="submit">Créer utilisateur</button>
            <button class="btn ghost" type="button" id="users-create-cancel-btn">Annuler</button>
          </div>
        </form>
      </div>

      <hr class="separator">

      <form method="get" class="filters-grid users-filters">
        <div>
          <label class="label" for="role">Role</label>
          <select class="input" id="role" name="role">
            <option value="ALL" <?= $role === 'ALL' ? 'selected' : '' ?>>Tous</option>
            <option value="ADMIN" <?= $role === 'ADMIN' ? 'selected' : '' ?>>Administrateurs</option>
            <option value="USER" <?= $role === 'USER' ? 'selected' : '' ?>>Utilisateurs</option>
          </select>
        </div>
        <div>
          <label class="label" for="search">Recherche</label>
          <input class="input" id="search" type="text" name="search" value="<?= h($search) ?>" placeholder="Email ou nom">
        </div>
        <div class="filters-actions">
          <button class="btn" type="submit">Filtrer</button>
          <a class="btn ghost" href="/admin/users.php">Reset</a>
        </div>
      </form>

      <div class="row sessions-stats">
        <span class="badge">Total: <?= (int)$stats['total_users'] ?></span>
        <span class="badge ok">Admins: <?= (int)$stats['total_admins'] ?></span>
        <span class="badge">Utilisateurs: <?= (int)$stats['total_standard'] ?></span>
      </div>

      <p class="sub sessions-meta">Page <?= (int)$page ?> / <?= (int)$totalPages ?> (<?= (int)$totalRows ?> résultats)</p>

      <div class="table-wrap">
        <?php if (!$users): ?>
          <p class="empty-state">Aucun utilisateur trouvé.</p>
        <?php else: ?>
          <table class="table questions-table users-table">
            <thead>
              <tr>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'email')) ?>">
                    Email<?php if ($sort === 'email'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>Nom</th>
                <th>Prenom</th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'role')) ?>">
                    Role<?php if ($sort === 'role'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'created_at')) ?>">
                    Date création<?php if ($sort === 'created_at'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'session_count')) ?>">
                    Sessions<?php if ($sort === 'session_count'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'passed_exam_count')) ?>">
                    Certifications réussis<?php if ($sort === 'passed_exam_count'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'last_session_at')) ?>">
                    Dernière session<?php if ($sort === 'last_session_at'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                  $uid = (int)$u['id'];
                  $isSelf = ($uid === $currentAdminId);
                  $isAdmin = ((string)$u['role'] === 'ADMIN');
                  $lastSessionAt = $u['last_session_at'] ? (string)$u['last_session_at'] : '-';
                ?>
                <tr>
                  <td><?= h((string)$u['email']) ?></td>
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
                      ? strtoupper(trim((string)($editForm['new_role'] ?? (string)$u['role'])))
                      : (string)$u['role'];
                    if (!in_array($editRole, ['USER', 'ADMIN'], true)) {
                      $editRole = 'USER';
                    }
                    $editQs = $_GET;
                    $editQs['open_edit'] = $uid;
                    $editLink = '/admin/users.php?' . http_build_query($editQs);
                    $closeQs = $_GET;
                    unset($closeQs['open_edit']);
                    $closeLink = '/admin/users.php' . ($closeQs ? ('?' . http_build_query($closeQs)) : '');
                  ?>
                  <td><?= h($lastName) !== '' ? h($lastName) : '-' ?></td>
                  <td><?= h($firstName) !== '' ? h($firstName) : '-' ?></td>
                  <td>
                    <?php if ($isAdmin): ?>
                      <span class="badge ok">ADMIN</span>
                    <?php else: ?>
                      <span class="badge">USER</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h((string)$u['created_at']) ?></td>
                  <td><?= (int)$u['session_count'] ?></td>
                  <td><?= (int)$u['passed_exam_count'] ?></td>
                  <td><?= h($lastSessionAt) ?></td>
                  <td class="actions-cell">
                    <a class="btn ghost" href="<?= h($editLink) ?>"><?= $isOpenEdit ? 'Edition...' : 'Modifier' ?></a>
                    <?php if (!$isAdmin): ?>
                      <form method="post" class="inline-action-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="grant_admin">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <button class="btn ghost" type="submit">Promouvoir admin</button>
                      </form>
                    <?php else: ?>
                      <form method="post" class="inline-action-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="revoke_admin">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <button
                          class="btn ghost danger-soft"
                          type="submit"
                          <?= $isSelf ? 'disabled' : '' ?>
                          <?= $isSelf ? 'title="Vous ne pouvez pas retirer vos propres droits."' : '' ?>
                          onclick="return confirm('Confirmer le retrait des droits admin pour cet utilisateur ?');"
                        >
                          Retirer admin
                        </button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php if ($isOpenEdit): ?>
                  <tr class="users-edit-row">
                    <td colspan="9">
                      <form method="post" class="users-edit-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="user_id" value="<?= (int)$uid ?>">
                        <div class="users-edit-grid">
                          <div>
                            <label class="label" for="edit-first-<?= (int)$uid ?>">Prenom</label>
                            <input class="input" id="edit-first-<?= (int)$uid ?>" name="first_name" type="text" maxlength="100" required value="<?= h($firstName) ?>">
                          </div>
                          <div>
                            <label class="label" for="edit-last-<?= (int)$uid ?>">Nom</label>
                            <input class="input" id="edit-last-<?= (int)$uid ?>" name="last_name" type="text" maxlength="100" required value="<?= h($lastName) ?>">
                          </div>
                          <div>
                            <label class="label" for="edit-email-<?= (int)$uid ?>">Email</label>
                            <input class="input" id="edit-email-<?= (int)$uid ?>" name="email" type="email" required value="<?= h($editEmail) ?>">
                          </div>
                          <div>
                            <label class="label" for="edit-role-<?= (int)$uid ?>">Role</label>
                            <select class="input" id="edit-role-<?= (int)$uid ?>" name="new_role">
                              <option value="USER" <?= $editRole === 'USER' ? 'selected' : '' ?>>USER</option>
                              <option value="ADMIN" <?= $editRole === 'ADMIN' ? 'selected' : '' ?>>ADMIN</option>
                            </select>
                          </div>
                          <div>
                            <label class="label" for="edit-pass-<?= (int)$uid ?>">Nouveau mot de passe (optionnel)</label>
                            <input class="input" id="edit-pass-<?= (int)$uid ?>" name="new_password" type="password" minlength="8" autocomplete="new-password">
                          </div>
                          <div>
                            <label class="label" for="edit-pass2-<?= (int)$uid ?>">Confirmer le nouveau mot de passe</label>
                            <input class="input" id="edit-pass2-<?= (int)$uid ?>" name="new_password2" type="password" minlength="8" autocomplete="new-password">
                          </div>
                        </div>
                        <div class="users-edit-actions">
                          <button class="btn" type="submit">Enregistrer</button>
                          <a class="btn ghost" href="<?= h($closeLink) ?>">Fermer</a>
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
    })();
  </script>
</body>
</html>
