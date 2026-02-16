<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';

$pdo = db();

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$packages = $pdo->query("SELECT id, name FROM packages ORDER BY id DESC")->fetchAll();
$defaultPkg = (int)($packages[0]['id'] ?? 1);

$packageId = (int)($_POST['package_id'] ?? ($_GET['package_id'] ?? $defaultPkg));

$report = [
  'inserted' => 0,
  'skipped_duplicates' => 0,
  'skipped_empty' => 0,
  'errors' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $bulk = trim((string)($_POST['bulk'] ?? ''));

  $needDefault = strtoupper(trim((string)($_POST['need_default'] ?? 'PONE')));
  $levelDefault = (int)($_POST['level_default'] ?? 1);

  if (!in_array($needDefault, ['PONE','PHM','PPM'], true)) $needDefault = 'PONE';
  if ($levelDefault < 1 || $levelDefault > 3) $levelDefault = 1;

  if ($bulk === '') {
    $report['errors'][] = "Aucun contenu collé.";
  } else {
    $lines = preg_split('/\r\n|\r|\n/', $bulk);

    // helper: normalize for duplicate detection
    $norm = function(string $s): string {
      $s = trim($s);
      $s = preg_replace('/\s+/u', ' ', $s);
      return mb_strtolower($s, 'UTF-8');
    };

    $labels = ['A','B','C','D','E','F'];

    // duplicate check statement (same package + same text)
    $dupStmt = $pdo->prepare("SELECT id FROM questions WHERE package_id=? AND text=? LIMIT 1");

    $insertQ = $pdo->prepare("
      INSERT INTO questions(package_id, text, need, level, question_type, allow_skip)
      VALUES(?,?,?,?,?,?)
    ");

    $deleteOpts = $pdo->prepare("DELETE FROM question_options WHERE question_id=?");
    $insertOpt = $pdo->prepare("
      INSERT INTO question_options(question_id, label, option_text, is_correct, score_value)
      VALUES(?,?,?,?,?)
    ");

    $pdo->beginTransaction();
    try {
      foreach ($lines as $idx => $line) {
        $lineNo = $idx + 1;
        $line = trim($line);
        if ($line === '') { $report['skipped_empty']++; continue; }

        // Excel copy/paste = TSV
        $cols = explode("\t", $line);

        // If header row
        $first = $norm($cols[0] ?? '');
        if ($first === 'questions' || $first === 'question') {
          continue;
        }

        $questionText = trim((string)($cols[0] ?? ''));
        if ($questionText === '') {
          $report['errors'][] = "Ligne $lineNo : question vide.";
          continue;
        }

        // collect options 1..6
        $opts = [];
        for ($i = 1; $i <= 6; $i++) {
          $t = trim((string)($cols[$i] ?? ''));
          if ($t !== '') $opts[] = $t;
        }

        if (count($opts) < 2) {
          $report['errors'][] = "Ligne $lineNo : moins de 2 réponses (question ignorée).";
          continue;
        }

        // Expected columns:
        // 0=Question, 1..6=Options, 7=Bonnes réponses, 8=Need (optional), 9=Level (optional)
        $rawCorrect = trim((string)($cols[7] ?? ''));
        $rawNeed = strtoupper(trim((string)($cols[8] ?? '')));
        $rawLevel = trim((string)($cols[9] ?? ''));

        $need = in_array($rawNeed, ['PONE','PHM','PPM'], true) ? $rawNeed : $needDefault;

        $level = (int)$rawLevel;
        if ($level < 1 || $level > 3) $level = $levelDefault;

        if ($rawCorrect === '') {
          $report['errors'][] = "Ligne $lineNo : colonne 'Bonnes réponses' manquante (après les 6 réponses).";
          continue;
        }

        // Parse correct indices like "2;3" or "3"
        $correctIdx = [];
        if ($rawCorrect !== '') {
          $parts = preg_split('/[;,\s]+/', $rawCorrect);
          foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (!ctype_digit($p)) {
              $report['errors'][] = "Ligne $lineNo : format bonnes réponses invalide ($rawCorrect).";
              $correctIdx = [];
              break;
            }
            $n = (int)$p;
            if ($n < 1 || $n > 6) {
              $report['errors'][] = "Ligne $lineNo : bonne réponse hors limite (1..6): $n.";
              $correctIdx = [];
              break;
            }
            $correctIdx[$n] = true;
          }
        }

        if ($rawCorrect === '' || count($correctIdx) === 0) {
          $report['errors'][] = "Ligne $lineNo : aucune bonne réponse détectée (mets '1' ou '2;3' etc).";
          continue;
        }

        // dedupe: exact text match in same package
        $dupStmt->execute([$packageId, $questionText]);
        if ($dupStmt->fetch()) {
          $report['skipped_duplicates']++;
          continue;
        }

        // Decide question_type
        $nbCorrect = count($correctIdx);
        $questionType = 'MULTI';

        // If looks like TRUE_FALSE (+ optionally NSP): Vrai/Faux/(NSP)
        $upperOpts = array_map(fn($x)=>mb_strtoupper(trim($x), 'UTF-8'), $opts);
        $isTF = false;
        if (count($upperOpts) >= 2) {
          $hasVrai = in_array('VRAI', $upperOpts, true);
          $hasFaux = in_array('FAUX', $upperOpts, true);
          $onlyAllowed = true;
          foreach ($upperOpts as $u) {
            if (!in_array($u, ['VRAI','FAUX','NSP'], true)) { $onlyAllowed = false; break; }
          }
          if ($hasVrai && $hasFaux && $onlyAllowed) $isTF = true;
        }
        if ($isTF && $nbCorrect === 1) $questionType = 'TRUE_FALSE';

        // Insert question
        $insertQ->execute([$packageId, $questionText, $need, $level, $questionType, 1]);
        $qid = (int)$pdo->lastInsertId();

        // Insert options A..F for the non-empty options in order
        // scoring: correct=+1, wrong=-1, NSP=0
        $deleteOpts->execute([$qid]); // just in case, safe

        for ($k = 0; $k < count($opts) && $k < 6; $k++) {
          $label = $labels[$k];
          $text = $opts[$k];
          $isCorrect = isset($correctIdx[$k+1]) ? 1 : 0;

          $u = mb_strtoupper(trim($text), 'UTF-8');
          if ($u === 'NSP') {
            $isCorrect = 0;
            $scoreValue = 0;
          } else {
            $scoreValue = $isCorrect ? 1 : -1;
          }

          $insertOpt->execute([$qid, $label, $text, $isCorrect, $scoreValue]);
        }

        $report['inserted']++;
      }

      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      $report['errors'][] = "Erreur DB: " . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin · Import questions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="container">
  <div class="card" style="margin-top:30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
      <div>
        <h2 class="h1" style="margin:0;">Importer des questions</h2>
        <p class="sub" style="margin:6px 0 0;">Colle depuis Excel (TSV)</p>
      </div>
      <a class="btn ghost" href="/admin/questions.php<?= $packageId ? '?package_id='.(int)$packageId : '' ?>">← Retour</a>
    </div>

    <hr style="border:none;border-top:1px solid var(--border); margin:14px 0;">

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
      <div class="card" style="box-shadow:none;border:1px solid var(--border);">
        <b>Rapport</b>
        <ul style="margin:8px 0 0;">
          <li>Insérées : <?= (int)$report['inserted'] ?></li>
          <li>Doublons ignorés : <?= (int)$report['skipped_duplicates'] ?></li>
          <li>Lignes vides ignorées : <?= (int)$report['skipped_empty'] ?></li>
        </ul>
        <?php if ($report['errors']): ?>
          <div style="margin-top:10px;">
            <b>Erreurs (les lignes concernées n’ont pas été importées)</b>
            <ul>
              <?php foreach ($report['errors'] as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" style="margin-top:14px;">
      <label>Package :</label><br>
      <select name="package_id" required>
        <?php foreach ($packages as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $packageId===(int)$p['id']?'selected':'' ?>>
            <?= h($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div style="height:10px;"></div>

      <label>Need (défaut)</label><br>
      <select name="need_default" required>
        <?php foreach (['PONE','PHM','PPM'] as $n): ?>
          <option value="<?= h($n) ?>"><?= h($n) ?></option>
        <?php endforeach; ?>
      </select>

      <label style="margin-left:12px;">Level (défaut)</label>
      <select name="level_default" required>
        <?php for ($i=1; $i<=3; $i++): ?>
          <option value="<?= $i ?>"><?= $i ?></option>
        <?php endfor; ?>
      </select>

      <div style="height:10px;"></div>

      <label>Contenu (copier/coller depuis Excel)</label>
      <textarea name="bulk" rows="16" style="width:100%;" placeholder="Questions,Réponses,etc&#10;..."></textarea>

      <div style="margin-top:12px; display:flex; gap:10px;">
        <button class="btn" type="submit">Importer</button>
        <a class="btn ghost" href="/admin/questions.php<?= $packageId ? '?package_id='.(int)$packageId : '' ?>">Annuler</a>
      </div>
    </form>

    <p class="small" style="margin-top:12px;">
      Notes :<br>
      • Bonnes réponses accepte <code>1</code> ou <code>2;3</code> (séparateur <code>;</code>, <code>,</code> ou espace).<br>
      • Type auto : 1 bonne réponse ou plusieurs → question choix multiple, Vrai/Faux → question vrai ou faux.
    </p>
  </div>
</div>
</body>
</html>
