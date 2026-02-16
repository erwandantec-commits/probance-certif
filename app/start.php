<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';

$user = require_auth();
$uid = (int)$user['id'];
$email = $user['email'];

$pdo = db();
$packages = $pdo->query("SELECT id, name FROM packages WHERE is_active=1 ORDER BY id")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // email vient du user connecté (pas du formulaire)
  $package_id = (int)($_POST['package_id'] ?? 0);

  if ($package_id <= 0) {
    $error = "Package invalide";
  } else {
    // Load package
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1");
    $stmt->execute([$package_id]);
    $pkg = $stmt->fetch();

    if (!$pkg) {
      $error = "Package introuvable";
    } else {
      $limit = (int)$pkg['selection_count'];
      if ($limit < 1) $limit = 1;

      // Pick eligible questions: must have >=2 options
      $q = $pdo->prepare("
        SELECT q.id
        FROM questions q
        JOIN question_options qo ON qo.question_id = q.id
        WHERE q.package_id=?
        GROUP BY q.id
        HAVING COUNT(qo.id) >= 2
        ORDER BY RAND()
        LIMIT $limit
      ");
      $q->execute([$package_id]);
      $qids = $q->fetchAll();

      if (count($qids) < $limit) {
        $error = "Pas assez de questions (avec options) en base pour ce package.";
      } else {
        $pdo->beginTransaction();
        try {
          // Upsert contact (garde ton modèle existant)
          $stmt = $pdo->prepare("SELECT id FROM contacts WHERE email=?");
          $stmt->execute([$email]);
          $contact = $stmt->fetch();

          if (!$contact) {
            $ins = $pdo->prepare("INSERT INTO contacts(email) VALUES(?)");
            $ins->execute([$email]);
            $contact_id = (int)$pdo->lastInsertId();
          } else {
            $contact_id = (int)$contact['id'];
          }

          $session_id = uuidv4();

          // IMPORTANT: on stocke user_id
          $ins = $pdo->prepare("
            INSERT INTO sessions(id, contact_id, user_id, package_id, session_type)
            VALUES(?,?,?,?,'EXAM')
          ");
          $ins->execute([$session_id, $contact_id, $uid, $package_id]);

          $pos = 1;
          $insq = $pdo->prepare("INSERT INTO session_questions(session_id, question_id, position) VALUES(?,?,?)");
          foreach ($qids as $row) {
            $insq->execute([$session_id, (int)$row['id'], $pos++]);
          }

          $pdo->commit();

          header("Location: /exam.php?sid=" . urlencode($session_id) . "&p=1");
          exit;

        } catch (Throwable $e) {
          $pdo->rollBack();
          $error = "Erreur création session: " . $e->getMessage();
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Démarrer certification</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
</head>
<body>
  <div class="container">
    <div class="card">

      <div class="header">
        <div>
          <h2 class="h1">Démarrer une certification</h2>
          <p class="sub">Démo interne Probance — V1</p>
        </div>
        <div style="display:flex; gap:10px;">
          <?php if (($user['role'] ?? 'USER') === 'ADMIN'): ?>
            <a class="btn ghost" href="/admin/index.php">Admin</a>
          <?php endif; ?>
          <a class="btn ghost" href="/dashboard.php">Mon espace</a>
        </div>
      </div>

      <?php if (!empty($error)): ?>
        <p class="error"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post">
        <label class="label">Email</label>
        <input class="input" type="email" value="<?= h($email) ?>" readonly>

        <br><br>

        <label class="label">Package</label>
        <select name="package_id" required>
          <?php foreach ($packages as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <br><br>

        <button class="btn" type="submit">Démarrer</button>
      </form>
    </div>

    <p class="small" style="margin-top:14px;">© Probance — Certification Platform</p>
  </div>
</body>
</html>
