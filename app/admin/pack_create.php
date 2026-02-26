<?php
require_once __DIR__ . '/_auth.php';
require_admin();
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
$pdo = db();

function pack_create_rule_templates(): array {
  return [
    'GREEN' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PONE', 'levels' => [2, 3], 'take' => 10],
        ['need' => 'PONE', 'levels' => [1], 'take' => 50, 'target_total' => 50],
      ],
    ],
    'BLUE' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PONE', 'levels' => [2, 3], 'take' => 30],
        ['need' => 'PONE', 'levels' => [1], 'take' => 50, 'target_total' => 50],
      ],
    ],
    'RED' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PHM', 'levels' => [1], 'take' => 40],
        ['need' => 'PONE', 'levels' => [3], 'take' => 3, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [2], 'take' => 3, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10, 'target_total' => 50],
      ],
    ],
    'BLACK' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PHM', 'levels' => [2, 3], 'take' => 30],
        ['need' => 'PHM', 'levels' => [1], 'take' => 40, 'target_total' => 40],
        ['need' => 'PONE', 'levels' => [3], 'take' => 3, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [2], 'take' => 3, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10, 'target_total' => 50],
      ],
    ],
    'SILVER' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PPM', 'levels' => [1], 'take' => 40],
        ['need' => 'PHM', 'levels' => [3], 'take' => 2, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [3], 'take' => 2, 'target_total' => 50],
        ['need' => 'PHM', 'levels' => [2], 'take' => 2, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [2], 'take' => 2, 'target_total' => 50],
        ['need' => 'PHM', 'levels' => [1], 'take' => 5, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10, 'target_total' => 50],
      ],
    ],
    'GOLD' => [
      'max' => 50,
      'buckets' => [
        ['need' => 'PPM', 'levels' => [2, 3], 'take' => 30],
        ['need' => 'PPM', 'levels' => [1], 'take' => 40, 'target_total' => 40],
        ['need' => 'PHM', 'levels' => [3], 'take' => 2, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [3], 'take' => 2, 'target_total' => 50],
        ['need' => 'PHM', 'levels' => [2], 'take' => 2, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [2], 'take' => 2, 'target_total' => 50],
        ['need' => 'PHM', 'levels' => [1], 'take' => 5, 'target_total' => 50],
        ['need' => 'PONE', 'levels' => [1], 'take' => 10, 'target_total' => 50],
      ],
    ],
  ];
}

function pack_create_rule_rows_from_post(): array {
  $needs = $_POST['rule_need'] ?? [];
  $takes = $_POST['rule_take'] ?? [];
  $targets = $_POST['rule_target_total'] ?? [];
  $level1 = $_POST['rule_level_1'] ?? [];
  $level2 = $_POST['rule_level_2'] ?? [];
  $level3 = $_POST['rule_level_3'] ?? [];

  if (!is_array($needs) || !is_array($takes) || !is_array($targets)) {
    return [];
  }

  $rows = [];
  $total = count($needs);
  for ($i = 0; $i < $total; $i++) {
    $need = strtoupper(trim((string)($needs[$i] ?? '')));
    $take = (int)($takes[$i] ?? 0);
    $targetTotal = (int)($targets[$i] ?? 0);
    $levels = [];
    if ((string)($level1[$i] ?? '') === '1') {
      $levels[] = 1;
    }
    if ((string)($level2[$i] ?? '') === '1') {
      $levels[] = 2;
    }
    if ((string)($level3[$i] ?? '') === '1') {
      $levels[] = 3;
    }

    if (!in_array($need, ['PONE', 'PHM', 'PPM'], true) || $take <= 0 || !$levels) {
      continue;
    }

    $rows[] = [
      'need' => $need,
      'levels' => $levels,
      'take' => $take,
      'target_total' => $targetTotal > 0 ? $targetTotal : 0,
    ];
  }

  return $rows;
}

function pack_create_rule_rows_to_json(array $rows, int $selectionCount): string {
  $buckets = [];
  foreach ($rows as $row) {
    $bucket = [
      'need' => (string)$row['need'],
      'levels' => array_values(array_map('intval', $row['levels'] ?? [])),
      'take' => (int)$row['take'],
    ];
    $targetTotal = (int)($row['target_total'] ?? 0);
    if ($targetTotal > 0) {
      $bucket['target_total'] = $targetTotal;
    }
    $buckets[] = $bucket;
  }

  return (string)json_encode([
    'max' => $selectionCount,
    'buckets' => $buckets,
  ], JSON_UNESCAPED_UNICODE);
}

$error = '';
$name = '';
$threshold = 80;
$duration = 120;
$count = 10;
$isActive = 1;
$nameColorHex = '#334155';
$ruleTemplates = pack_create_rule_templates();
$selectedTemplate = '';
$ruleRows = [];

$hasNameColorColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'name_color_hex'
")->fetchColumn();
$hasRulesColumn = (bool)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packages'
    AND COLUMN_NAME = 'selection_rules_json'
")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim((string)($_POST['name'] ?? ''));
  $threshold = (int)($_POST['pass_threshold_percent'] ?? 80);
  $duration = (int)($_POST['duration_limit_minutes'] ?? 120);
  $count = (int)($_POST['selection_count'] ?? 10);
  $isActive = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;
  $selectedTemplate = strtoupper(trim((string)($_POST['rule_template'] ?? '')));
  if (!isset($ruleTemplates[$selectedTemplate])) {
    $selectedTemplate = '';
  }
  $ruleRows = pack_create_rule_rows_from_post();
  $postedColor = trim((string)($_POST['name_color_hex'] ?? ''));
  $normalizedColor = normalize_hex_color($postedColor);
  if ($normalizedColor !== null) {
    $nameColorHex = $normalizedColor;
  }

  $nameLen = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
  if ($name === '') {
    $error = 'Le nom du pack est obligatoire.';
  } elseif ($nameLen > 255) {
    $error = 'Nom de pack trop long (max 255 caracteres).';
  } elseif ($threshold < 0 || $threshold > 100) {
    $error = 'Seuil invalide (0 a 100).';
  } elseif ($duration < 1 || $duration > 600) {
    $error = 'Durée invalide (1 à 600 minutes).';
  } elseif ($count < 1 || $count > 200) {
    $error = 'Nombre de questions invalide (1 a 200).';
  } elseif ($hasRulesColumn && !empty($ruleRows) && count($ruleRows) > 20) {
    $error = 'Maximum 20 paliers de regles.';
  } elseif ($hasNameColorColumn && $normalizedColor === null) {
    $error = 'Couleur invalide.';
  } else {
    $existsStmt = $pdo->prepare("SELECT id FROM packages WHERE UPPER(name)=UPPER(?) LIMIT 1");
    $existsStmt->execute([$name]);
    if ($existsStmt->fetch()) {
      $error = 'Un pack avec ce nom existe deja.';
    } else {
      $selectionRulesJson = null;
      if ($hasRulesColumn && !empty($ruleRows)) {
        $selectionRulesJson = pack_create_rule_rows_to_json($ruleRows, $count);
        if ($selectionRulesJson === '') {
          $error = 'Règles de tirage invalides.';
        }
      }

      if ($error !== '') {
        // keep form state
      } elseif ($hasNameColorColumn && $hasRulesColumn) {
        $ins = $pdo->prepare("
          INSERT INTO packages(name, name_color_hex, pass_threshold_percent, duration_limit_minutes, selection_count, selection_rules_json, is_active)
          VALUES(?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$name, $nameColorHex, $threshold, $duration, $count, $selectionRulesJson, $isActive]);
      } elseif ($hasNameColorColumn) {
        $ins = $pdo->prepare("
          INSERT INTO packages(name, name_color_hex, pass_threshold_percent, duration_limit_minutes, selection_count, is_active)
          VALUES(?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$name, $nameColorHex, $threshold, $duration, $count, $isActive]);
      } elseif ($hasRulesColumn) {
        $ins = $pdo->prepare("
          INSERT INTO packages(name, pass_threshold_percent, duration_limit_minutes, selection_count, selection_rules_json, is_active)
          VALUES(?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$name, $threshold, $duration, $count, $selectionRulesJson, $isActive]);
      } else {
        $ins = $pdo->prepare("
          INSERT INTO packages(name, pass_threshold_percent, duration_limit_minutes, selection_count, is_active)
          VALUES(?, ?, ?, ?, ?)
        ");
        $ins->execute([$name, $threshold, $duration, $count, $isActive]);
      }
      if ($error === '') {
        header('Location: /admin/packages.php?created=1');
        exit;
      }
    }
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Cr&eacute;er un pack</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
  <div class="container admin-container">
    <div class="card admin-card">
      <div class="admin-head">
        <div class="admin-head-copy">
          <h2 class="h1">Admin &middot; Cr&eacute;er un pack</h2>
          <p class="sub">Ajout d'un nouveau pack d Exam.</p>
        </div>
        <div class="admin-head-actions">
          <?php render_admin_tabs('packages'); ?>
        </div>
      </div>

      <hr class="separator">

      <?php if ($error !== ''): ?>
        <p class="error" style="margin:0 0 10px;"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post" class="users-create-form">
        <div class="users-create-grid">
          <div>
            <label class="label" for="create-pack-name">Nom du pack</label>
            <input class="input" id="create-pack-name" name="name" type="text" maxlength="255" required value="<?= h($name) ?>">
          </div>
          <div>
            <label class="label" for="create-pack-threshold">Seuil (%)</label>
            <input class="input" id="create-pack-threshold" name="pass_threshold_percent" type="number" min="0" max="100" required value="<?= (int)$threshold ?>">
          </div>
          <div>
            <label class="label" for="create-pack-duration">Dur&eacute;e (minutes)</label>
            <input class="input" id="create-pack-duration" name="duration_limit_minutes" type="number" min="1" max="600" required value="<?= (int)$duration ?>">
          </div>
          <div>
            <label class="label" for="create-pack-count">Questions</label>
            <input class="input" id="create-pack-count" name="selection_count" type="number" min="1" max="200" required value="<?= (int)$count ?>">
          </div>
          <?php if ($hasNameColorColumn): ?>
            <div>
              <label class="label" for="create-pack-color">Couleur du nom</label>
              <input class="input" id="create-pack-color" name="name_color_hex" type="color" value="<?= h($nameColorHex) ?>">
            </div>
          <?php endif; ?>
          <div>
            <label class="label" for="create-pack-active">Statut</label>
            <select class="input" id="create-pack-active" name="is_active">
              <option value="1" <?= $isActive === 1 ? 'selected' : '' ?>>Actif</option>
              <option value="0" <?= $isActive === 0 ? 'selected' : '' ?>>Inactif</option>
            </select>
          </div>
        </div>

        <?php if ($hasRulesColumn): ?>
          <section class="rule-builder">
            <h3 class="distribution-title rule-builder-title">R&egrave;gles de tirage des questions</h3>
            <p class="small rule-builder-sub">
              D&eacute;finis l'ordre des paliers. Chaque ligne prend "jusqu'&agrave; X" questions. La colonne "Cible cumul&eacute;e" permet de stopper un palier quand le total atteint la cible.
            </p>
            <div class="rule-toolbar">
              <div class="rule-template-group">
                <label class="label" for="rule-template">Mod&egrave;le</label>
                <select class="input" id="rule-template" name="rule_template">
                  <option value="">Aucun</option>
                  <?php foreach (array_keys($ruleTemplates) as $tplName): ?>
                    <option value="<?= h($tplName) ?>" <?= $selectedTemplate === $tplName ? 'selected' : '' ?>><?= h($tplName) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="rule-toolbar-actions">
                <button class="btn ghost rule-action-btn rule-action-apply" type="button" id="apply-rule-template">Appliquer le mod&egrave;le</button>
                <button class="btn ghost rule-action-btn rule-action-add" type="button" id="add-rule-row">Ajouter un palier</button>
              </div>
            </div>

            <div class="table-wrap rule-table-wrap">
              <table class="table questions-table rules-table" id="rule-rows-table">
                <thead>
                  <tr>
                    <th>Connaissances requises</th>
                    <th>Niveaux</th>
                    <th>Prendre (max)</th>
                    <th>Cible cumul&eacute;e</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="rule-rows-body">
                  <?php foreach ($ruleRows as $row): ?>
                    <?php
                      $levelsMap = [];
                      foreach (($row['levels'] ?? []) as $lv) {
                        $levelsMap[(int)$lv] = true;
                      }
                    ?>
                    <tr class="rule-row">
                      <td>
                        <select class="input rule-need" name="rule_need[]">
                          <?php foreach (['PONE', 'PHM', 'PPM'] as $needOpt): ?>
                            <option value="<?= h($needOpt) ?>" <?= ($row['need'] ?? '') === $needOpt ? 'selected' : '' ?>><?= h($needOpt) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td class="rule-levels-cell">
                        <input type="hidden" class="rule-level-1-input" value="<?= !empty($levelsMap[1]) ? '1' : '0' ?>">
                        <label class="rule-level-check"><input type="checkbox" class="rule-level-1-check" <?= !empty($levelsMap[1]) ? 'checked' : '' ?>> L1</label>
                        <input type="hidden" class="rule-level-2-input" value="<?= !empty($levelsMap[2]) ? '1' : '0' ?>">
                        <label class="rule-level-check"><input type="checkbox" class="rule-level-2-check" <?= !empty($levelsMap[2]) ? 'checked' : '' ?>> L2</label>
                        <input type="hidden" class="rule-level-3-input" value="<?= !empty($levelsMap[3]) ? '1' : '0' ?>">
                        <label class="rule-level-check"><input type="checkbox" class="rule-level-3-check" <?= !empty($levelsMap[3]) ? 'checked' : '' ?>> L3</label>
                      </td>
                      <td><input class="input rule-take" type="number" name="rule_take[]" min="1" max="200" value="<?= (int)($row['take'] ?? 0) ?>"></td>
                      <td><input class="input rule-target-total" type="number" name="rule_target_total[]" min="0" max="200" value="<?= (int)($row['target_total'] ?? 0) ?>"></td>
                      <td><button class="btn ghost rule-remove rule-remove-btn" type="button">Supprimer</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endif; ?>

        <div class="users-create-actions">
          <button class="btn" type="submit">Cr&eacute;er le pack</button>
          <a class="btn ghost" href="/admin/packages.php">Annuler</a>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function () {
    <?php if ($hasRulesColumn): ?>
    var ruleTemplates = <?= json_encode($ruleTemplates, JSON_UNESCAPED_UNICODE) ?>;
    var tbody = document.getElementById('rule-rows-body');
    var addRowBtn = document.getElementById('add-rule-row');
    var applyTemplateBtn = document.getElementById('apply-rule-template');
    var templateSelect = document.getElementById('rule-template');
    var countInput = document.querySelector('input[name="selection_count"]');
    var form = document.querySelector('form[method="post"]');

    function bindRowActions(row) {
      var removeBtn = row.querySelector('.rule-remove');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          row.remove();
        });
      }
    }

    function buildRowHtml(data) {
      var need = data.need || 'PONE';
      var take = data.take || 1;
      var targetTotal = data.target_total || 0;
      var levels = Array.isArray(data.levels) ? data.levels : [1];
      var hasL1 = levels.indexOf(1) !== -1;
      var hasL2 = levels.indexOf(2) !== -1;
      var hasL3 = levels.indexOf(3) !== -1;

      return '' +
        '<tr class="rule-row">' +
          '<td>' +
            '<select class="input rule-need">' +
              '<option value="PONE"' + (need === 'PONE' ? ' selected' : '') + '>PONE</option>' +
              '<option value="PHM"' + (need === 'PHM' ? ' selected' : '') + '>PHM</option>' +
              '<option value="PPM"' + (need === 'PPM' ? ' selected' : '') + '>PPM</option>' +
            '</select>' +
          '</td>' +
          '<td class="rule-levels-cell">' +
            '<input type="hidden" class="rule-level-1-input" value="' + (hasL1 ? '1' : '0') + '">' +
            '<label class="rule-level-check"><input type="checkbox" class="rule-level-1-check"' + (hasL1 ? ' checked' : '') + '> L1</label>' +
            '<input type="hidden" class="rule-level-2-input" value="' + (hasL2 ? '1' : '0') + '">' +
            '<label class="rule-level-check"><input type="checkbox" class="rule-level-2-check"' + (hasL2 ? ' checked' : '') + '> L2</label>' +
            '<input type="hidden" class="rule-level-3-input" value="' + (hasL3 ? '1' : '0') + '">' +
            '<label class="rule-level-check"><input type="checkbox" class="rule-level-3-check"' + (hasL3 ? ' checked' : '') + '> L3</label>' +
          '</td>' +
          '<td><input class="input rule-take" type="number" min="1" max="200" value="' + take + '"></td>' +
          '<td><input class="input rule-target-total" type="number" min="0" max="200" value="' + targetTotal + '"></td>' +
          '<td><button class="btn ghost rule-remove rule-remove-btn" type="button">Supprimer</button></td>' +
        '</tr>';
    }

    function addRuleRow(data) {
      if (!tbody) return;
      var wrap = document.createElement('tbody');
      wrap.innerHTML = buildRowHtml(data || {});
      var row = wrap.firstElementChild;
      tbody.appendChild(row);
      bindRowActions(row);
    }

    function syncRuleInputNames() {
      if (!tbody) return;
      var rows = tbody.querySelectorAll('.rule-row');
      rows.forEach(function (row, idx) {
        var need = row.querySelector('.rule-need');
        var take = row.querySelector('.rule-take');
        var target = row.querySelector('.rule-target-total');
        var l1Input = row.querySelector('.rule-level-1-input');
        var l2Input = row.querySelector('.rule-level-2-input');
        var l3Input = row.querySelector('.rule-level-3-input');
        var l1Check = row.querySelector('.rule-level-1-check');
        var l2Check = row.querySelector('.rule-level-2-check');
        var l3Check = row.querySelector('.rule-level-3-check');

        if (need) need.name = 'rule_need[' + idx + ']';
        if (take) take.name = 'rule_take[' + idx + ']';
        if (target) target.name = 'rule_target_total[' + idx + ']';
        if (l1Input) {
          l1Input.name = 'rule_level_1[' + idx + ']';
          l1Input.value = (l1Check && l1Check.checked) ? '1' : '0';
        }
        if (l2Input) {
          l2Input.name = 'rule_level_2[' + idx + ']';
          l2Input.value = (l2Check && l2Check.checked) ? '1' : '0';
        }
        if (l3Input) {
          l3Input.name = 'rule_level_3[' + idx + ']';
          l3Input.value = (l3Check && l3Check.checked) ? '1' : '0';
        }
      });
    }

    if (tbody) {
      tbody.querySelectorAll('.rule-row').forEach(bindRowActions);
      syncRuleInputNames();
    }

    if (addRowBtn) {
      addRowBtn.addEventListener('click', function () {
        addRuleRow({ need: 'PONE', levels: [1], take: 1, target_total: 0 });
      });
    }

    if (applyTemplateBtn) {
      applyTemplateBtn.addEventListener('click', function () {
        var key = templateSelect ? templateSelect.value : '';
        if (!key || !ruleTemplates[key] || !Array.isArray(ruleTemplates[key].buckets) || !tbody) return;
        tbody.innerHTML = '';
        ruleTemplates[key].buckets.forEach(function (bucket) {
          addRuleRow(bucket);
        });
        if (countInput && ruleTemplates[key].max) {
          countInput.value = String(ruleTemplates[key].max);
        }
        syncRuleInputNames();
      });
    }

    if (form) {
      form.addEventListener('submit', function () {
        syncRuleInputNames();
      });
    }
    <?php endif; ?>
  })();
  </script>
</body>
</html>
