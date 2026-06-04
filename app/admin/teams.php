<?php
require_once __DIR__ . '/_auth.php';
$adminUser = require_admin_area();
header('Location: /admin/users.php');
exit;

if (empty($_SESSION['admin_teams_csrf']) || !is_string($_SESSION['admin_teams_csrf'])) {
  $_SESSION['admin_teams_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['admin_teams_csrf'];

function admin_teams_redirect(array $params = []): void {
  $url = '/admin/teams.php';
  if ($params) {
    $url .= '?' . http_build_query($params);
  }
  header('Location: ' . $url);
  exit;
}

function admin_teams_set_notice(string $type, string $text): void {
  $_SESSION['admin_teams_notice'] = ['type' => $type, 'text' => $text];
}

function admin_teams_team_detail_url(int $teamId): string {
  return '/admin/team_profile.php?team_id=' . $teamId;
}

function admin_teams_scope_organizations(PDO $pdo, array $actor): array {
  if (!auth_table_exists($pdo, 'organizations')) {
    return [];
  }
  if (user_has_role($actor, 'ADMIN')) {
    $st = $pdo->query("SELECT id, name FROM organizations ORDER BY name ASC, id ASC");
    return $st ? ($st->fetchAll() ?: []) : [];
  }
  $organizationId = user_organization_id($actor);
  if ($organizationId <= 0) {
    return [];
  }
  $st = $pdo->prepare("SELECT id, name FROM organizations WHERE id = ? LIMIT 1");
  $st->execute([$organizationId]);
  $row = $st->fetch();
  return $row ? [$row] : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedToken = (string)($_POST['csrf_token'] ?? '');
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    admin_teams_set_notice('bad', 'Action refusee: token invalide.');
    admin_teams_redirect();
  }

  $organizationRows = admin_teams_scope_organizations($pdo, $adminUser);
  $organizationMap = [];
  foreach ($organizationRows as $organizationRow) {
    $organizationMap[(int)($organizationRow['id'] ?? 0)] = (string)($organizationRow['name'] ?? '');
  }

  $action = (string)($_POST['action'] ?? '');
  if ($action === 'create_team') {
    $name = trim((string)($_POST['name'] ?? ''));
    $organizationId = (int)($_POST['organization_id'] ?? 0);
    if (!user_has_role($adminUser, 'ADMIN')) {
      $organizationId = user_organization_id($adminUser);
    }

    if ($name === '') {
      admin_teams_set_notice('bad', 'Nom equipe obligatoire.');
      admin_teams_redirect();
    }
    if (!isset($organizationMap[$organizationId])) {
      admin_teams_set_notice('bad', 'Organisation invalide.');
      admin_teams_redirect();
    }

    $baseSlug = auth_slugify_label($name);
    if ($baseSlug === '') {
      $baseSlug = 'equipe';
    }
    $slug = $baseSlug;
    $suffix = 2;
    while (true) {
      $check = $pdo->prepare("SELECT id FROM teams WHERE organization_id = ? AND slug = ? LIMIT 1");
      $check->execute([$organizationId, $slug]);
      if (!$check->fetch()) {
        break;
      }
      $slug = $baseSlug . '-' . $suffix++;
    }

    $insert = $pdo->prepare("INSERT INTO teams(organization_id, name, slug) VALUES(?, ?, ?)");
    $insert->execute([$organizationId, $name, $slug]);
    admin_teams_set_notice('ok', 'Equipe creee: ' . $name . '.');
    admin_teams_redirect();
  }

  if ($action === 'delete_team') {
    $teamId = (int)($_POST['team_id'] ?? 0);
    if ($teamId <= 0) {
      admin_teams_set_notice('bad', 'Equipe invalide.');
      admin_teams_redirect();
    }
    $st = $pdo->prepare("
      SELECT t.id, t.name, t.organization_id
      FROM teams t
      WHERE t.id = ?
      LIMIT 1
    ");
    $st->execute([$teamId]);
    $team = $st->fetch();
    if (!$team) {
      admin_teams_set_notice('bad', 'Equipe introuvable.');
      admin_teams_redirect();
    }
    if (!user_has_role($adminUser, 'ADMIN') && (int)($team['organization_id'] ?? 0) !== user_organization_id($adminUser)) {
      admin_teams_set_notice('bad', 'Action refusee hors de votre organisation.');
      admin_teams_redirect();
    }
    $pdo->prepare("DELETE FROM teams WHERE id = ?")->execute([$teamId]);
    admin_teams_set_notice('ok', 'Equipe supprimee: ' . (string)($team['name'] ?? '') . '.');
    admin_teams_redirect();
  }
}

$organizationRows = admin_teams_scope_organizations($pdo, $adminUser);
$notice = $_SESSION['admin_teams_notice'] ?? null;
unset($_SESSION['admin_teams_notice']);

$where = [];
$params = [];
if (!user_has_role($adminUser, 'ADMIN')) {
  $where[] = 't.organization_id = ?';
  $params[] = user_organization_id($adminUser);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = $pdo->prepare("
  SELECT
    t.id,
    t.name,
    t.slug,
    o.name AS organization_name,
    COUNT(DISTINCT u.id) AS member_count
  FROM teams t
  JOIN organizations o ON o.id = t.organization_id
  LEFT JOIN users u ON u.team_id = t.id
  $whereSql
  GROUP BY t.id, t.name, t.slug, o.name
  ORDER BY o.name ASC, t.name ASC, t.id ASC
");
$st->execute($params);
$teams = $st->fetchAll() ?: [];
?>
<!doctype html>
<html lang="fr">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title>Admin &middot; Equipes</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card admin-page-shell">
      <div class="admin-head admin-page-hero">
        <div class="admin-head-copy">
          <p class="admin-page-eyebrow">Administration</p>
          <h2 class="h1">Admin &middot; Equipes</h2>
          <p class="sub">Regroupe les utilisateurs cote client pour le suivi par owners et admins.</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('teams'); ?>
        </div>
      </div>

      <?php if (is_array($notice) && isset($notice['type'], $notice['text'])): ?>
        <div class="admin-notice <?= ((string)$notice['type'] === 'ok') ? 'is-ok' : 'is-bad' ?>">
          <?= h((string)$notice['text']) ?>
        </div>
      <?php endif; ?>

      <div class="admin-page-layout">
        <section class="admin-section-panel admin-section-panel-accent">
          <div class="section-head admin-section-head">
            <div>
              <h3 class="h1">Creer une equipe</h3>
            </div>
          </div>

          <form method="post" class="users-create-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="create_team">
            <div class="users-create-grid">
              <div>
                <label class="label" for="team-name">Nom</label>
                <input class="input" id="team-name" type="text" name="name" required>
              </div>
              <?php if (user_has_role($adminUser, 'ADMIN')): ?>
                <div>
                  <label class="label" for="team-organization">Organisation</label>
                  <select class="input" id="team-organization" name="organization_id">
                    <?php foreach ($organizationRows as $organizationRow): ?>
                      <option value="<?= (int)($organizationRow['id'] ?? 0) ?>"><?= h((string)($organizationRow['name'] ?? 'Organisation')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>
            </div>
            <div class="users-create-actions">
              <button class="btn" type="submit">Creer equipe</button>
            </div>
          </form>
        </section>

        <section class="admin-section-panel">
          <div class="section-head admin-section-head">
            <div>
              <h3 class="h1">Equipes existantes</h3>
              <p class="sub sessions-meta"><?= (int)count($teams) ?> equipe(s)</p>
            </div>
          </div>

          <div class="table-wrap admin-table-panel">
            <?php if (!$teams): ?>
              <p class="empty-state">Aucune equipe definie.</p>
            <?php else: ?>
              <table class="table questions-table teams-table">
                <thead>
                  <tr>
                    <th>Equipe</th>
                    <th>Organisation</th>
                    <th>Membres</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($teams as $team): ?>
                    <tr>
                      <td><?= h((string)($team['name'] ?? '')) ?></td>
                      <td><?= h((string)($team['organization_name'] ?? '')) ?></td>
                      <td><?= (int)($team['member_count'] ?? 0) ?></td>
                      <td class="actions-cell">
                        <a
                          class="btn ghost icon-btn"
                          href="<?= h(admin_teams_team_detail_url((int)($team['id'] ?? 0))) ?>"
                          aria-label="Voir la fiche equipe"
                          title="Voir la fiche equipe"
                        >
                          <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 5c5.5 0 9.5 4.6 10.8 6.3a1.2 1.2 0 0 1 0 1.4C21.5 14.4 17.5 19 12 19S2.5 14.4 1.2 12.7a1.2 1.2 0 0 1 0-1.4C2.5 9.6 6.5 5 12 5zm0 2C8 7 4.9 10.3 3.3 12 4.9 13.7 8 17 12 17s7.1-3.3 8.7-5C19.1 10.3 16 7 12 7zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5z"/>
                          </svg>
                        </a>
                        <form method="post" class="inline-action-form">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="action" value="delete_team">
                          <input type="hidden" name="team_id" value="<?= (int)($team['id'] ?? 0) ?>">
                          <button
                            class="btn ghost icon-btn danger"
                            type="submit"
                            onclick="return confirm('Supprimer cette equipe ? Les utilisateurs garderont leur compte mais ne seront plus rattaches a cette equipe.');"
                            aria-label="Supprimer cette equipe"
                            title="Supprimer"
                          >
                            <svg class="icon-trash" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                              <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                            </svg>
                          </button>
                        </form>
                      </td>
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
