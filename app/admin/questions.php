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
if (in_array($need, ['PONE','PHM','PPM'], true)) { $conds[] = "q.need=?"; $params[] = $need; }
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
  <title>Admin · Questions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="container">
  <div class="card" style="margin-top:30px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
      <div>
        <h2 class="h1" style="margin:0;">Admin · Questions</h2>
        <p class="sub" style="margin:6px 0 0;">Ajouter / modifier / supprimer</p>
      </div>
      <div style="display:flex; gap:10px;">
        <a class="btn ghost" href="/admin/index.php">← Retour</a>
        <a class="btn ghost" href="/logout.php">Déconnexion</a>
      </div>
    </div>

    <hr style="border:none;border-top:1px solid var(--border); margin:14px 0;">

    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <label>Package :</label>
      <select name="package_id">
        <option value="0">Tous</option>
        <?php foreach ($packages as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $pkg===(int)$p['id']?'selected':'' ?>>
            <?= h($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
            
      <label>Need :</label>
      <select name="need">
        <option value="">Tous</option>
        <?php foreach (['PONE','PHM','PPM'] as $n): ?>
          <option value="<?= h($n) ?>" <?= ($need===$n)?'selected':'' ?>><?= h($n) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Level :</label>
      <select name="level">
        <option value="0">Tous</option>
        <?php for ($i=1; $i<=3; $i++): ?>
          <option value="<?= $i ?>" <?= ($level===$i)?'selected':'' ?>><?= $i ?></option>
        <?php endfor; ?>
      </select>

      <button class="btn ghost" type="submit">Filtrer</button>
      <a class="btn" href="/admin/question_edit.php?new=1<?= $pkg? '&package_id='.$pkg : '' ?>">+ Ajouter</a>
      <a class="btn ghost" href="/admin/import_questions.php<?= $pkg ? '?package_id='.$pkg : '' ?>">Importer</a>
    </form>

    <div style="margin:12px 0; font-size:14px;">
      <b>Répartition actuelle :</b><br>
      <?php foreach (['PONE','PHM','PPM'] as $n): ?>
        <div>
          <?= $n ?> :
          <?php for ($i=1; $i<=3; $i++): 
            $c = $distribution[$n][$i] ?? 0;
          ?>
            <span style="margin-right:10px;">
              L<?= $i ?>: <b><?= $c ?></b>
            </span>
          <?php endfor; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:14px;">
      <?php if (!$questions): ?>
        <p>Aucune question.</p>
      <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr style="text-align:left;">
              <th>ID</th>
              <th>Package</th>
              <th>Need</th>
              <th>Level</th>
              <th>Type</th>
              <th>Options</th>
              <th>Énoncé</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($questions as $q): ?>
              <tr style="border-top:1px solid var(--border);">
                <td><?= (int)$q['id'] ?></td>
                <td><?= h($q['package_name']) ?></td>
                <td><?= h($q['need']) ?></td>
                <td><?= (int)$q['level'] ?></td>
                <td>
                  <?= ($q['question_type']==='TRUE_FALSE') ? 'Vrai/Faux' : 'Choix multiple' ?>
                  <?= ((int)$q['allow_skip']===1) ? ' · skip' : '' ?>
                </td>
                <td>
                  <?= (int)$q['opt_count'] ?> option<?= ((int)$q['opt_count'] > 1) ? 's' : '' ?>
                </td>
                <td><?= h(mb_strimwidth((string)$q['text'], 0, 90, '…', 'UTF-8')) ?></td>
                <td style="white-space:nowrap;">
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
