<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';

$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$isNew = isset($_GET['new']);
$prefPkg = (int)($_GET['package_id'] ?? 0);

$packages = $pdo->query("SELECT id, name FROM packages ORDER BY id DESC")->fetchAll();

$question = [
  'id' => 0,
  'package_id' => $prefPkg ?: (int)($packages[0]['id'] ?? 1),
  'text' => '',
  'question_type' => 'SINGLE',
  'allow_skip' => 1,
];

$optionsByLabel = []; // 'A' => ['option_text'=>..., 'is_correct'=>..., 'score_value'=>...]

if ($id > 0) {
  $st = $pdo->prepare("SELECT id, package_id, text, question_type, allow_skip FROM questions WHERE id=?");
  $st->execute([$id]);
  $q = $st->fetch();
  if (!$q) { http_response_code(404); echo "Question not found"; exit; }

  $question = [
    'id' => (int)$q['id'],
    'package_id' => (int)$q['package_id'],
    'text' => (string)$q['text'],
    'question_type' => (string)($q['question_type'] ?? 'SINGLE'),
    'allow_skip' => (int)($q['allow_skip'] ?? 1),
  ];

  $os = $pdo->prepare("SELECT label, option_text, is_correct, score_value FROM question_options WHERE question_id=? ORDER BY label ASC");
  $os->execute([$id]);
  foreach ($os->fetchAll() as $o) {
    $optionsByLabel[(string)$o['label']] = $o;
  }
}

$labels = ['A','B','C','D','E','F'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $question['package_id'] = (int)($_POST['package_id'] ?? $question['package_id']);
  $question['text'] = trim((string)($_POST['text'] ?? ''));
  $question['question_type'] = (string)($_POST['question_type'] ?? 'SINGLE');
  $question['allow_skip'] = isset($_POST['allow_skip']) ? 1 : 0;

  if ($question['text'] === '') $errors[] = "Énoncé obligatoire.";
  if (!in_array($question['question_type'], ['SINGLE','MULTI','TRUE_FALSE'], true)) $errors[] = "Type invalide.";

  $optText = $_POST['opt'] ?? [];
  $correct = $_POST['correct'] ?? [];
  $score = $_POST['score'] ?? [];

  $rows = [];
  foreach ($labels as $L) {
    $t = trim((string)($optText[$L] ?? ''));
    if ($t === '') continue;

    $isCorrect = isset($correct[$L]) ? 1 : 0;

    // default scoring
    $sv = $isCorrect ? 1 : -1;

    // NSP convenience
    if (mb_strtoupper($t, 'UTF-8') === 'NSP') $sv = 0;

    // optional manual override
    if (isset($score[$L]) && $score[$L] !== '') {
      $maybe = filter_var($score[$L], FILTER_VALIDATE_INT);
      if ($maybe !== false) $sv = (int)$maybe;
    }

    $rows[] = ['label'=>$L, 'text'=>$t, 'is_correct'=>$isCorrect, 'score_value'=>$sv];
  }

  if (count($rows) < 2) $errors[] = "Il faut au moins 2 options.";
  if (count($rows) > 6) $errors[] = "Max 6 options.";

  $nbCorrect = array_sum(array_map(fn($r)=>$r['is_correct'], $rows));
  if ($question['question_type'] === 'SINGLE' || $question['question_type'] === 'TRUE_FALSE') {
    if ($nbCorrect !== 1) $errors[] = "Type {$question['question_type']} : exactement 1 bonne réponse.";
  } else {
    if ($nbCorrect < 1) $errors[] = "Type MULTI : au moins 1 bonne réponse.";
  }

  if (!$errors) {
    $pdo->beginTransaction();
    try {
      if ($question['id'] > 0) {
        $up = $pdo->prepare("UPDATE questions SET package_id=?, text=?, question_type=?, allow_skip=? WHERE id=?");
        $up->execute([$question['package_id'], $question['text'], $question['question_type'], $question['allow_skip'], $question['id']]);
        $qid = $question['id'];
      } else {
        $ins = $pdo->prepare("INSERT INTO questions(package_id, text, question_type, allow_skip) VALUES(?,?,?,?)");
        $ins->execute([$question['package_id'], $question['text'], $question['question_type'], $question['allow_skip']]);
        $qid = (int)$pdo->lastInsertId();
      }

      $pdo->prepare("DELETE FROM question_options WHERE question_id=?")->execute([$qid]);

      $io = $pdo->prepare("INSERT INTO question_options(question_id,label,option_text,is_correct,score_value) VALUES(?,?,?,?,?)");
      foreach ($rows as $r) {
        $io->execute([$qid, $r['label'], $r['text'], $r['is_correct'], $r['score_value']]);
      }

      $pdo->commit();
      header("Location: /admin/questions.php");
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = "Erreur DB: " . $e->getMessage();
    }
  }
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title><?= $question['id'] ? "Modifier question #".(int)$question['id'] : "Ajouter une question" ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="container">
  <div class="card" style="margin-top:30px;">
    <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
      <div>
        <h2 class="h1" style="margin:0;"><?= $question['id'] ? "Modifier question #".(int)$question['id'] : "Ajouter une question" ?></h2>
        <p class="sub" style="margin:6px 0 0;">Options A..F · correct=+1 · mauvais=-1 · NSP=0</p>
      </div>
      <a class="btn ghost" href="/admin/questions.php">← Retour</a>
    </div>

    <?php if ($errors): ?>
      <div class="card" style="box-shadow:none;border:1px solid #f3b; margin-top:14px;">
        <b>Erreurs :</b>
        <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" style="margin-top:14px;">
      <label>Package</label><br>
      <select name="package_id" required>
        <?php foreach ($packages as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $question['package_id']===(int)$p['id']?'selected':'' ?>>
            <?= h($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div style="height:10px;"></div>

      <label>Type</label><br>
      <select name="question_type">
        <?php foreach (['SINGLE','MULTI','TRUE_FALSE'] as $t): ?>
          <option value="<?= h($t) ?>" <?= $question['question_type']===$t?'selected':'' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>

      <label style="margin-left:12px;">
        <input type="checkbox" name="allow_skip" <?= ((int)$question['allow_skip']===1)?'checked':'' ?>>
        Autoriser “ne pas répondre”
      </label>

      <div style="height:10px;"></div>

      <label>Énoncé</label><br>
      <textarea name="text" rows="4" style="width:100%;" required><?= h($question['text']) ?></textarea>

      <div style="height:14px;"></div>

      <b>Options</b>
      <p class="small" style="margin-top:6px;">Coche la/les bonnes. Laisse vide une option si tu n’en as pas besoin.</p>

      <?php foreach ($labels as $L):
        $cur = $optionsByLabel[$L] ?? null;
        $t = $cur['option_text'] ?? '';
        $c = (int)($cur['is_correct'] ?? 0) === 1;
        $sv = $cur['score_value'] ?? '';
      ?>
        <div style="display:flex; gap:10px; align-items:center; margin:8px 0;">
          <b style="width:24px;"><?= h($L) ?>.</b>
          <input type="text" name="opt[<?= h($L) ?>]" value="<?= h($t) ?>" style="flex:1;" placeholder="Texte option <?= h($L) ?>">
          <label style="white-space:nowrap;">
            <input type="checkbox" name="correct[<?= h($L) ?>]" <?= $c?'checked':'' ?>> Correct
          </label>
          <input type="number" name="score[<?= h($L) ?>]" value="<?= h($sv) ?>" style="width:90px;" placeholder="score">
        </div>
      <?php endforeach; ?>

      <div style="margin-top:14px; display:flex; gap:10px;">
        <button class="btn" type="submit">Enregistrer</button>
        <a class="btn ghost" href="/admin/questions.php">Annuler</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
