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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedToken = (string)($_POST['csrf_token'] ?? '');
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    admin_users_set_notice('bad', 'Action refusee: token de securite invalide.');
    admin_users_redirect();
  }

  $action = (string)($_POST['action'] ?? '');
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
        admin_users_set_notice('ok', 'Aucune modification: ' . $targetEmail . ' est deja admin.');
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
      admin_users_set_notice('bad', 'Action refusee: vous ne pouvez pas retirer vos propres droits admin.');
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
    admin_users_set_notice('ok', 'Droits admin retires pour ' . $targetEmail . '.');
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
  $where[] = '(u.email LIKE ? OR u.name LIKE ?)';
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
  <script src="/assets/theme-toggle.js?v=1" defer></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Utilisateurs</h2>
          <p class="sub">Gestion des comptes, roles et habilitations administration</p>
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

      <p class="sub sessions-meta">Page <?= (int)$page ?> / <?= (int)$totalPages ?> (<?= (int)$totalRows ?> resultats)</p>

      <div class="table-wrap">
        <?php if (!$users): ?>
          <p class="empty-state">Aucun utilisateur trouve.</p>
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
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'role')) ?>">
                    Role<?php if ($sort === 'role'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'created_at')) ?>">
                    Date creation<?php if ($sort === 'created_at'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'session_count')) ?>">
                    Sessions<?php if ($sort === 'session_count'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'passed_exam_count')) ?>">
                    Exams reussis<?php if ($sort === 'passed_exam_count'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
                  </a>
                </th>
                <th>
                  <a class="sort-link" href="<?= h(admin_users_sort_link($_GET, 'last_session_at')) ?>">
                    Derniere session<?php if ($sort === 'last_session_at'): ?> <span><?= $dir === 'DESC' ? '&darr;' : '&uarr;' ?></span><?php endif; ?>
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
                  <td><?= h((string)($u['name'] ?? '')) ?: '-' ?></td>
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
</body>
</html>
