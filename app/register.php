<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';

$pdo = db();

$error = '';
$email = '';
$firstName = '';
$lastName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $firstName = trim((string)($_POST['first_name'] ?? ''));
  $lastName = trim((string)($_POST['last_name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $password2 = (string)($_POST['password2'] ?? '');

  if ($firstName === '' || $lastName === '') {
    $error = 'Prénom et nom obligatoires.';
  } elseif (strlen($firstName) > 100 || strlen($lastName) > 100) {
    $error = 'Prénom/nom trop longs (max 100 caractères).';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Email invalide.';
  } elseif (strlen($password) < 8) {
    $error = 'Mot de passe trop court (min 8 caractères).';
  } elseif ($password !== $password2) {
    $error = 'Les mots de passe ne correspondent pas.';
  } else {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
      $error = 'Un compte existe déjà avec cet email.';
    } else {
      $fullName = trim($firstName . ' ' . $lastName);
      $hash = password_hash($password, PASSWORD_DEFAULT);

      $pdo->beginTransaction();
      try {
        $ins = $pdo->prepare("INSERT INTO users(email, password_hash, name, role) VALUES(?, ?, ?, 'USER')");
        $ins->execute([$email, $hash, $fullName]);
        $uid = (int)$pdo->lastInsertId();

        $contactUpsert = $pdo->prepare("
          INSERT INTO contacts(email, first_name, last_name)
          VALUES(?, ?, ?)
          ON DUPLICATE KEY UPDATE
            first_name = VALUES(first_name),
            last_name = VALUES(last_name)
        ");
        $contactUpsert->execute([$email, $firstName, $lastName]);

        $pdo->commit();

        $_SESSION['user'] = [
          'id' => $uid,
          'email' => $email,
          'name' => $fullName,
          'role' => 'USER',
        ];

        header('Location: /dashboard.php');
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $error = "Impossible de créer le compte pour l'instant.";
      }
    }
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Créer un compte</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container">
  <div class="card">
    <h2 class="h1">Créer un compte</h2>
    <p class="sub">Inscris-toi pour accéder à tes Exams.</p>

    <?php if ($error): ?>
      <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="post">
      <label class="label">Prénom</label>
      <input class="input" name="first_name" type="text" required maxlength="100" value="<?= h($firstName) ?>" autocomplete="given-name">

      <div style="height:10px"></div>

      <label class="label">Nom</label>
      <input class="input" name="last_name" type="text" required maxlength="100" value="<?= h($lastName) ?>" autocomplete="family-name">

      <div style="height:10px"></div>

      <label class="label">Email</label>
      <input class="input" name="email" type="email" required value="<?= h($email) ?>" autocomplete="email">

      <div style="height:10px"></div>

      <label class="label">Mot de passe</label>
      <input class="input" name="password" type="password" required minlength="8" autocomplete="new-password">

      <div style="height:10px"></div>

      <label class="label">Confirmer le mot de passe</label>
      <input class="input" name="password2" type="password" required minlength="8" autocomplete="new-password">

      <div style="height:14px"></div>

      <button class="btn" type="submit">Créer mon compte</button>

      <p class="small" style="margin-top:10px;">
        Déjà un compte ? <a href="/login.php">Se connecter</a>
      </p>
    </form>
  </div>
</div>
</body>
</html>
