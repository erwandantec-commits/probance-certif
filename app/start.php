<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

$pdo = db();
$packages = $pdo->query("SELECT id, name FROM packages WHERE is_active=1 ORDER BY id")->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $package_id = (int)($_POST['package_id'] ?? 0);

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "Email invalide";
  } else {
    // Upsert contact
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

    // Package
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1");
    $stmt->execute([$package_id]);
    $pkg = $stmt->fetch();
    if (!$pkg) {
      $error = "Package introuvable";
    } else {
      $session_id = uuidv4();

      // Create session
      $ins = $pdo->prepare("INSERT INTO sessions(id, contact_id, package_id, session_type) VALUES(?,?,?, 'EXAM')");
      $ins->execute([$session_id, $contact_id, $package_id]);

      // Pick random questions
      $limit = (int)$pkg['selection_count'];

      $q = $pdo->prepare("SELECT id FROM questions WHERE package_id=? ORDER BY RAND() LIMIT $limit");
      $q->execute([$package_id]);

      $qids = $q->fetchAll();

      if (count($qids) < (int)$pkg['selection_count']) {
        $error = "Pas assez de questions en base pour ce package.";
      } else {
        $pos = 1;
        $insq = $pdo->prepare("INSERT INTO session_questions(session_id, question_id, position) VALUES(?,?,?)");
        foreach ($qids as $row) {
          $insq->execute([$session_id, (int)$row['id'], $pos++]);
        }
        header("Location: /exam.php?sid=" . urlencode($session_id) . "&p=1");
        exit;
      }
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Démarrer certification</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>

<body>
  <div class="container">

    <div class="card">

      <div class="header">
        <div>
          <h2 class="h1">Démarrer une certification</h2>
          <p class="sub">Démo interne Probance — V1</p>
        </div>

        <a class="btn ghost" href="/admin/index.php">Admin</a>
      </div>

      <?php if (!empty($error)): ?>
        <p class="error"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post">

        <label class="label">Email</label>
        <input class="input"
               type="email"
               name="email"
               placeholder="contact@exemple.com"
               required>

        <br><br>

        <label class="label">Package</label>
        <select name="package_id" required>
          <?php foreach ($packages as $p): ?>
            <option value="<?= (int)$p['id'] ?>">
              <?= h($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <br><br>

        <button class="btn">Démarrer</button>

      </form>

    </div>

    <p class="small" style="margin-top:14px;">
      © Probance — Certification Platform
    </p>

  </div>
</body>
</html>
