<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';

$pdo = db();

$packages = $pdo->query("SELECT id, name FROM packages ORDER BY id DESC")->fetchAll();
$pkg = (int)($_GET['package_id'] ?? 0);

$need = strtoupper(trim((string)($_GET['need'] ?? '')));
$level = (int)($_GET['level'] ?? 0);

$params = [];
$conds = [];

if ($pkg > 0) { $conds[] = "q.package_id=?"; $params[] = $pkg; }
if (in_array($need, ['PONE', 'PHM', 'PPM'], true)) { $conds[] = "q.need=?"; $params[] = $need; }
if ($level >= 1 && $level <= 3) { $conds[] = "q.level=?"; $params[] = $level; }

$where = $conds ? ("WHERE " . implode(" AND ", $conds)) : "";

$stmt = $pdo->prepare("
  SELECT
    q.id, q.text, q.need, q.level, q.question_type, q.allow_skip,
    COALESCE(p.name, '(Banque globale)') AS package_name,
    (SELECT COUNT(*) FROM question_options qo WHERE qo.question_id=q.id) AS opt_count
  FROM questions q
  LEFT JOIN packages p ON p.id=q.package_id
  $where
  ORDER BY q.id DESC
");
$stmt->execute($params);
$questions = $stmt->fetchAll();

$distStmt = $pdo->query("
  SELECT need, level, COUNT(*) c
  FROM questions
  GROUP BY need, level
");

$distribution = [];
foreach ($distStmt->fetchAll() as $row) {
  $distribution[$row['need']][(int)$row['level']] = (int)$row['c'];
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Questions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1">Admin &middot; Questions</h2>
        <p class="sub">Ajouter / modifier / supprimer</p>
      </div>
      <div class="admin-head-actions">
        <a class="btn ghost" href="/admin/index.php">&larr; Retour</a>
        <a class="btn ghost admin-logout-btn" href="/logout.php">D&eacute;connexion</a>
      </div>
    </div>

    <hr class="separator">

    <form method="get" class="filters-grid">
      <div>
        <label class="label" for="package_id">Package</label>
        <select id="package_id" name="package_id">
          <option value="0">Tous</option>
          <?php foreach ($packages as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $pkg === (int)$p['id'] ? 'selected' : '' ?>>
              <?= h($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="label" for="need">Need</label>
        <select id="need" name="need">
          <option value="">Tous</option>
          <?php foreach (['PONE', 'PHM', 'PPM'] as $n): ?>
            <option value="<?= h($n) ?>" <?= ($need === $n) ? 'selected' : '' ?>><?= h($n) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="label" for="level">Level</label>
        <select id="level" name="level">
          <option value="0">Tous</option>
          <?php for ($i = 1; $i <= 3; $i++): ?>
            <option value="<?= $i ?>" <?= ($level === $i) ? 'selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="filters-actions">
        <button class="btn" type="submit">Filtrer</button>
        <a class="btn ghost" href="/admin/questions.php">Reset</a>
        <a class="btn ghost" href="/admin/question_edit.php?new=1<?= $pkg ? '&package_id=' . $pkg : '' ?>">+ Ajouter</a>
        <a class="btn ghost" href="/admin/import_questions.php<?= $pkg ? '?package_id=' . $pkg : '' ?>">&uarr; Importer</a>
      </div>
    </form>

    <div class="distribution-wrap">
      <p class="distribution-title">R&eacute;partition actuelle</p>
      <div class="distribution-grid">
        <?php foreach (['PONE', 'PHM', 'PPM'] as $n): ?>
          <div class="distribution-card">
            <p class="distribution-need"><?= $n ?></p>
            <div class="distribution-levels">
              <?php for ($i = 1; $i <= 3; $i++):
                $c = $distribution[$n][$i] ?? 0;
              ?>
                <span class="distribution-chip">L<?= $i ?> <b><?= $c ?></b></span>
              <?php endfor; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="table-wrap">
      <?php if (!$questions): ?>
        <p class="empty-state">Aucune question.</p>
      <?php else: ?>
        <table class="table questions-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Package</th>
              <th>Need</th>
              <th>Level</th>
              <th>Type</th>
              <th>Options</th>
              <th>&Eacute;nonc&eacute;</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($questions as $q): ?>
              <tr>
                <td><?= (int)$q['id'] ?></td>
                <td><?= h($q['package_name']) ?></td>
                <td><?= h($q['need']) ?></td>
                <td><?= (int)$q['level'] ?></td>
                <td>
                  <?= ($q['question_type'] === 'TRUE_FALSE') ? 'Vrai/Faux' : 'Choix multiple' ?>
                </td>
                <td>
                  <?= (int)$q['opt_count'] ?> option<?= ((int)$q['opt_count'] > 1) ? 's' : '' ?>
                </td>
                <td><?= h(mb_strimwidth((string)$q['text'], 0, 90, '...', 'UTF-8')) ?></td>
                <td class="actions-cell">
                  <a class="btn ghost" href="/admin/question_edit.php?id=<?= (int)$q['id'] ?>">Modifier</a>
                  <a class="btn ghost" href="/admin/question_delete.php?id=<?= (int)$q['id'] ?>"
                     onclick="return confirm('Supprimer cette question ?');">Supprimer</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
