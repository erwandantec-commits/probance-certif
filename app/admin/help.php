<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/_nav.php';

function admin_help_fallback_markdown(): string {
  return <<<'MD'
# Guide administrateur

## Role de l'espace admin

L'espace admin permet de :

- suivre les sessions des candidats
- consulter les certifications
- gerer les packs
- gerer les questions
- administrer les utilisateurs

## Sessions

La page `Sessions` permet de consulter :

- le candidat
- le pack
- le type de session
- le score
- le statut

## Certifications

La page `Certifications` donne une vue de suivi par candidat et par pack.

## Utilisateurs

La page `Utilisateurs` permet de consulter les comptes et d'ajuster les roles si besoin.

## Packs

La page `Packs` sert a configurer les certifications disponibles, leurs regles et leurs parametres.

## Questions

La page `Questions` permet de rechercher, modifier, supprimer et analyser les questions.

## Analyse

La page `Analyse` aide a identifier les questions faciles, difficiles ou peu utilisees.
MD;
}

$guidePaths = [
  dirname(__DIR__) . '/docs/GUIDE_ADMIN.md',
  '/opt/certif/docs/GUIDE_ADMIN.md',
];
$guideMarkdown = '';
foreach ($guidePaths as $guidePath) {
  if (is_file($guidePath)) {
    $guideMarkdown = (string)file_get_contents($guidePath);
    break;
  }
}
if ($guideMarkdown === '') {
  $guideMarkdown = admin_help_fallback_markdown();
}
$docSections = [];
if (preg_match_all('/^##\s+(.+)$/m', $guideMarkdown, $matches)) {
  foreach ($matches[1] as $heading) {
    $label = trim((string)$heading);
    $slug = strtolower($label);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string)$slug, '-');
    if ($slug !== '') {
      $docSections[] = ['label' => $label, 'slug' => $slug];
    }
  }
}
$guideHtml = app_markdown_to_html($guideMarkdown);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Admin &middot; Documentation</title>
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
        <h2 class="h1">Admin &middot; Documentation</h2>
        <p class="sub">Guide de prise en main des ecrans, bonnes pratiques et points de repere pour administrer l'outil proprement.</p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('help'); ?>
      </div>
    </div>
    <div class="doc-layout doc-layout-admin">
      <?php if ($docSections): ?>
        <aside class="card doc-toc">
          <p class="doc-toc-title">Sommaire</p>
          <nav aria-label="Sommaire du guide administrateur">
            <?php foreach ($docSections as $section): ?>
              <a class="doc-toc-link" href="#<?= h($section['slug']) ?>"><?= h($section['label']) ?></a>
            <?php endforeach; ?>
          </nav>
        </aside>
      <?php endif; ?>
      <section class="admin-section-panel doc-body">
        <div class="doc-content">
          <?= $guideHtml ?>
        </div>
      </section>
    </div>
  </div>
</div>
</body>
</html>
