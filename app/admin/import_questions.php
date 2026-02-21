<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_nav.php';

$pdo = db();

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function package_label_style_local(string $packageName): string {
  $name = strtoupper(trim($packageName));
  $color = match ($name) {
    'GREEN' => '#16a34a',
    'BLUE' => '#2563eb',
    'RED' => '#dc2626',
    'BLACK' => '#111827',
    'SILVER' => '#64748b',
    'GOLD' => '#d4af37',
    default => '#334155',
  };
  return 'color:' . $color . ';font-weight:700;';
}

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

  if (!in_array($needDefault, ['PONE', 'PHM', 'PPM'], true)) {
    $needDefault = 'PONE';
  }
  if ($levelDefault < 1 || $levelDefault > 3) {
    $levelDefault = 1;
  }

  if ($bulk === '') {
    $report['errors'][] = "Aucun contenu colle.";
  } else {
    $lines = preg_split('/\r\n|\r|\n/', $bulk);
    $norm = static function (string $s): string {
      $s = trim($s);
      $s = preg_replace('/\s+/u', ' ', $s);
      return mb_strtolower($s, 'UTF-8');
    };

    $labels = ['A', 'B', 'C', 'D', 'E', 'F'];

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
        if ($line === '') {
          $report['skipped_empty']++;
          continue;
        }

        $cols = explode("\t", $line);
        $first = $norm($cols[0] ?? '');
        if ($first === 'questions' || $first === 'question') {
          continue;
        }

        $questionText = trim((string)($cols[0] ?? ''));
        if ($questionText === '') {
          $report['errors'][] = "Ligne $lineNo : question vide.";
          continue;
        }

        $opts = [];
        for ($i = 1; $i <= 6; $i++) {
          $option = trim((string)($cols[$i] ?? ''));
          if ($option !== '') {
            $opts[] = $option;
          }
        }

        if (count($opts) < 2) {
          $report['errors'][] = "Ligne $lineNo : moins de 2 reponses (question ignoree).";
          continue;
        }

        $rawCorrect = trim((string)($cols[7] ?? ''));
        $rawNeed = strtoupper(trim((string)($cols[8] ?? '')));
        $rawLevel = trim((string)($cols[9] ?? ''));

        $need = in_array($rawNeed, ['PONE', 'PHM', 'PPM'], true) ? $rawNeed : $needDefault;
        $level = (int)$rawLevel;
        if ($level < 1 || $level > 3) {
          $level = $levelDefault;
        }

        if ($rawCorrect === '') {
          $report['errors'][] = "Ligne $lineNo : colonne 'Bonnes reponses' manquante (apres les 6 reponses).";
          continue;
        }

        $correctIdx = [];
        $parts = preg_split('/[;,\s]+/', $rawCorrect);
        foreach ($parts as $p) {
          $p = trim($p);
          if ($p === '') {
            continue;
          }
          if (!ctype_digit($p)) {
            $report['errors'][] = "Ligne $lineNo : format bonnes reponses invalide ($rawCorrect).";
            $correctIdx = [];
            break;
          }
          $n = (int)$p;
          if ($n < 1 || $n > 6) {
            $report['errors'][] = "Ligne $lineNo : bonne reponse hors limite (1..6): $n.";
            $correctIdx = [];
            break;
          }
          $correctIdx[$n] = true;
        }

        if (count($correctIdx) === 0) {
          $report['errors'][] = "Ligne $lineNo : aucune bonne reponse detectee (mets '1' ou '2;3' etc).";
          continue;
        }

        $dupStmt->execute([$packageId, $questionText]);
        if ($dupStmt->fetch()) {
          $report['skipped_duplicates']++;
          continue;
        }

        $nbCorrect = count($correctIdx);
        $questionType = 'MULTI';
        $upperOpts = array_map(fn($x) => mb_strtoupper(trim($x), 'UTF-8'), $opts);
        $isTF = false;
        if (count($upperOpts) >= 2) {
          $hasVrai = in_array('VRAI', $upperOpts, true);
          $hasFaux = in_array('FAUX', $upperOpts, true);
          $onlyAllowed = true;
          foreach ($upperOpts as $u) {
            if (!in_array($u, ['VRAI', 'FAUX', 'NSP'], true)) {
              $onlyAllowed = false;
              break;
            }
          }
          if ($hasVrai && $hasFaux && $onlyAllowed) {
            $isTF = true;
          }
        }
        if ($isTF && $nbCorrect === 1) {
          $questionType = 'TRUE_FALSE';
        }

        $insertQ->execute([$packageId, $questionText, $need, $level, $questionType, 1]);
        $qid = (int)$pdo->lastInsertId();

        $deleteOpts->execute([$qid]);
        for ($k = 0; $k < count($opts) && $k < 6; $k++) {
          $label = $labels[$k];
          $text = $opts[$k];
          $isCorrect = isset($correctIdx[$k + 1]) ? 1 : 0;

          $upper = mb_strtoupper(trim($text), 'UTF-8');
          if ($upper === 'NSP') {
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
  <title>Admin &middot; Import questions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card">
    <div class="admin-head">
      <div class="admin-head-copy">
        <h2 class="h1">Admin &middot; Importer des questions</h2>
        <p class="sub">Colle depuis Excel (TSV)</p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('questions'); ?>
      </div>
    </div>

    <hr class="separator">

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
      <div class="import-report">
        <div class="import-report-title">Rapport d'import</div>
        <div class="import-report-stats">
          <span class="pill info">Inserees: <?= (int)$report['inserted'] ?></span>
          <span class="pill">Doublons ignores: <?= (int)$report['skipped_duplicates'] ?></span>
          <span class="pill">Lignes vides: <?= (int)$report['skipped_empty'] ?></span>
        </div>
        <?php if ($report['errors']): ?>
          <div class="import-report-errors">
            <b>Erreurs (lignes non importees)</b>
            <ul>
              <?php foreach ($report['errors'] as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="import-form">
      <div class="import-help">
        <div class="import-help-head">
          <span class="import-help-tag">Guide</span>
          <strong>Comment les questions sont utilisees</strong>
        </div>
        <div class="import-help-grid">
          <div class="import-help-block">
            <div class="import-help-block-title">Utilise pour le tirage</div>
            <p class="import-help-text"><code>Need</code> + <code>Level</code></p>
          </div>
          <div class="import-help-block">
            <div class="import-help-block-title">Utilise pour organiser</div>
            <p class="import-help-text"><code>Package</code> (admin + detection de doublons)</p>
          </div>
        </div>
      </div>

      <div class="import-fields">
        <div class="import-field import-field-full">
          <label class="label">Package (organisation admin)</label>
	          <select name="package_id" required>
	            <?php foreach ($packages as $p): ?>
	              <option value="<?= (int)$p['id'] ?>" <?= $packageId === (int)$p['id'] ? 'selected' : '' ?> style="<?= h(package_label_style_local((string)$p['name'])) ?>">
	                <?= h($p['name']) ?>
	              </option>
	            <?php endforeach; ?>
	          </select>
        </div>

        <div class="import-field">
          <label class="label">Need par defaut (tirage)</label>
          <select name="need_default" required>
            <?php foreach (['PONE', 'PHM', 'PPM'] as $n): ?>
              <option value="<?= h($n) ?>"><?= h($n) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="import-field">
          <label class="label">Level par defaut (tirage)</label>
          <select name="level_default" required>
            <?php for ($i = 1; $i <= 3; $i++): ?>
              <option value="<?= $i ?>"><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>

      <div class="import-field">
        <label class="label">Contenu (copier/coller depuis Excel)</label>
        <textarea name="bulk" rows="16" class="import-bulk" placeholder="Questions,Reponses,etc&#10;..."></textarea>
      </div>

      <div class="import-actions">
        <button class="btn" type="submit">Importer</button>
        <a class="btn ghost" href="/admin/questions.php<?= $packageId ? '?package_id=' . (int)$packageId : '' ?>">Annuler</a>
      </div>
    </form>

    <p class="small import-note">
      Notes :<br>
      - Bonnes reponses accepte <code>1</code> ou <code>2;3</code> (separateur <code>;</code>, <code>,</code> ou espace).<br>
      - Type auto : 1 bonne reponse ou plusieurs -> question choix multiple, Vrai/Faux -> question vrai ou faux.
    </p>
  </div>
</div>
<script src="/assets/package-colors.js"></script>
</body>
</html>
