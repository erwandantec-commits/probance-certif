<?php
require_once __DIR__ . '/_auth.php';
$reportUser = require_team_reporting();
header('Location: /admin/index.php');
exit;
if (!in_array($roleFilter, $allowedRoleFilters, true)) {
  $roleFilter = 'ALL';
}

$teamIdFilter = (int)($_GET['team_id'] ?? 0);
$organizationId = user_organization_id($reportUser);
$managedTeamIds = auth_managed_team_ids($pdo, $reportUser);

$teamRows = [];
if (auth_table_exists($pdo, 'teams')) {
  if (user_has_role($reportUser, 'ADMIN')) {
    $teamRows = $pdo->query("
      SELECT t.id, t.name, o.name AS organization_name
      FROM teams t
      JOIN organizations o ON o.id = t.organization_id
      ORDER BY o.name ASC, t.name ASC, t.id ASC
    ")->fetchAll() ?: [];
  } elseif ($organizationId > 0) {
    $stTeams = $pdo->prepare("
      SELECT t.id, t.name, o.name AS organization_name
      FROM teams t
      JOIN organizations o ON o.id = t.organization_id
      WHERE t.organization_id = ?
      ORDER BY t.name ASC, t.id ASC
    ");
    $stTeams->execute([$organizationId]);
    $teamRows = $stTeams->fetchAll() ?: [];
  }
}

$validTeamIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $teamRows);
if ($teamIdFilter > 0 && !in_array($teamIdFilter, $validTeamIds, true)) {
  $teamIdFilter = 0;
}

$where = [];
$params = [];

if (!user_has_role($reportUser, 'ADMIN') && auth_column_exists($pdo, 'users', 'organization_id')) {
  $where[] = 'u.organization_id = ?';
  $params[] = $organizationId;
}

if ($teamIdFilter > 0 && auth_column_exists($pdo, 'users', 'team_id')) {
  $where[] = 'u.team_id = ?';
  $params[] = $teamIdFilter;
}

if ($roleFilter !== 'ALL') {
  $where[] = 'u.role = ?';
  $params[] = $roleFilter;
}

if ($email !== '') {
  $where[] = 'u.email LIKE ?';
  $params[] = '%' . $email . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$statsSql = "
  SELECT
    COUNT(*) AS total_users,
    SUM(u.role = 'OWNER') AS total_owners,
    SUM(u.role = 'USER') AS total_standard,
    SUM(COALESCE(su.passed_exam_count, 0)) AS passed_exam_total
  FROM users u
  LEFT JOIN (
    SELECT
      user_id,
      SUM(session_type = 'EXAM' AND status = 'TERMINATED' AND passed = 1) AS passed_exam_count
    FROM sessions
    WHERE user_id IS NOT NULL
    GROUP BY user_id
  ) su ON su.user_id = u.id
  $whereSql
";
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($params);
$stats = $statsStmt->fetch() ?: [
  'total_users' => 0,
  'total_owners' => 0,
  'total_standard' => 0,
  'passed_exam_total' => 0,
];

$listSql = "
  SELECT
    u.id,
    u.email,
    u.name,
    u.role,
    u.created_at,
    c.first_name,
    c.last_name,
    t.name AS team_name,
    o.name AS organization_name,
    COALESCE(su.session_count, 0) AS session_count,
    COALESCE(su.passed_exam_count, 0) AS passed_exam_count,
    su.last_session_at
  FROM users u
  LEFT JOIN contacts c ON c.email = u.email
  LEFT JOIN teams t ON t.id = u.team_id
  LEFT JOIN organizations o ON o.id = u.organization_id
  LEFT JOIN (
    SELECT
      user_id,
      COUNT(*) AS session_count,
      SUM(session_type = 'EXAM' AND status = 'TERMINATED' AND passed = 1) AS passed_exam_count,
      MAX(started_at) AS last_session_at
    FROM sessions
    WHERE user_id IS NOT NULL
    GROUP BY user_id
  ) su ON su.user_id = u.id
  $whereSql
  ORDER BY COALESCE(t.name, ''), u.email ASC
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$rows = $listStmt->fetchAll() ?: [];
?>
<!doctype html>
<html lang="fr">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title>Admin &middot; Suivi equipe</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card admin-page-shell">
      <div class="admin-head admin-page-hero">
        <div class="admin-head-copy">
          <p class="admin-page-eyebrow">Suivi</p>
          <h2 class="h1">Equipe &middot; Resultats</h2>
          <p class="sub">Vue des utilisateurs, sessions et certifications de votre perimetre.</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('team_overview'); ?>
        </div>
      </div>

      <div class="admin-stats-grid">
        <article class="admin-stat-card">
          <span class="admin-stat-label">Utilisateurs</span>
          <strong class="admin-stat-value"><?= (int)$stats['total_users'] ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label">Owners</span>
          <strong class="admin-stat-value"><?= (int)$stats['total_owners'] ?></strong>
        </article>
        <article class="admin-stat-card">
          <span class="admin-stat-label">Certifs reussies</span>
          <strong class="admin-stat-value"><?= (int)$stats['passed_exam_total'] ?></strong>
        </article>
      </div>

      <div class="admin-page-layout">
        <section class="admin-section-panel">
          <div class="section-head admin-section-head">
            <div>
              <h3 class="h1">Filtres</h3>
            </div>
          </div>

          <form method="get" class="filters-grid admin-panel-surface">
            <div>
              <label class="label" for="team-overview-email">Email</label>
              <input class="input" id="team-overview-email" type="text" name="email" value="<?= h($email) ?>">
            </div>
            <div>
              <label class="label" for="team-overview-role">Role</label>
              <select class="input" id="team-overview-role" name="role">
                <option value="ALL" <?= $roleFilter === 'ALL' ? 'selected' : '' ?>>Tous</option>
                <option value="OWNER" <?= $roleFilter === 'OWNER' ? 'selected' : '' ?>>OWNER</option>
                <option value="USER" <?= $roleFilter === 'USER' ? 'selected' : '' ?>>USER</option>
              </select>
            </div>
            <div>
              <label class="label" for="team-overview-team">Equipe</label>
              <select class="input" id="team-overview-team" name="team_id">
                <option value="0" <?= $teamIdFilter === 0 ? 'selected' : '' ?>>Toutes</option>
                <?php foreach ($teamRows as $team): ?>
                  <?php $teamId = (int)($team['id'] ?? 0); ?>
                  <option value="<?= $teamId ?>" <?= $teamIdFilter === $teamId ? 'selected' : '' ?>>
                    <?= h((string)($team['name'] ?? '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filters-actions">
              <button class="btn" type="submit">Filtrer</button>
              <a class="btn ghost" href="/admin/team_overview.php">Reset</a>
            </div>
          </form>
        </section>

        <section class="admin-section-panel">
          <div class="section-head admin-section-head">
            <div>
              <h3 class="h1">Utilisateurs suivis</h3>
              <p class="sub sessions-meta"><?= (int)count($rows) ?> resultat(s)</p>
            </div>
          </div>

          <div class="table-wrap admin-table-panel">
            <?php if (!$rows): ?>
              <p class="empty-state">Aucun utilisateur dans votre perimetre.</p>
            <?php else: ?>
              <table class="table questions-table users-table">
                <thead>
                  <tr>
                    <th>Email</th>
                    <th>Nom</th>
                    <th>Role</th>
                    <th>Equipe</th>
                    <th>Sessions</th>
                    <th>Certifs reussies</th>
                    <th>Derniere session</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $row): ?>
                    <?php
                      $firstName = trim((string)($row['first_name'] ?? ''));
                      $lastName = trim((string)($row['last_name'] ?? ''));
                      $fullName = trim($firstName . ' ' . $lastName);
                      if ($fullName === '') {
                        $fullName = trim((string)($row['name'] ?? ''));
                      }
                    ?>
                    <tr>
                      <td><?= h((string)($row['email'] ?? '')) ?></td>
                      <td><?= h($fullName !== '' ? $fullName : '-') ?></td>
                      <td><span class="pill info"><?= h(user_role_label((string)($row['role'] ?? 'USER'))) ?></span></td>
                      <td><?= h((string)($row['team_name'] ?? '-') !== '' ? (string)($row['team_name'] ?? '-') : '-') ?></td>
                      <td><?= (int)($row['session_count'] ?? 0) ?></td>
                      <td><?= (int)($row['passed_exam_count'] ?? 0) ?></td>
                      <td><?= h((string)($row['last_session_at'] ?? '-') !== '' ? (string)($row['last_session_at'] ?? '-') : '-') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
  </div>
</body>
</html>
