<?php
require_once __DIR__ . '/_auth.php';
require_team_reporting();
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/_nav.php';

function admin_help_fallback_markdown(): string {
  return <<<'MD'
# Guide administrateur

Ce guide explique le fonctionnement des principaux ecrans d'administration, sans entrer dans le code.

## Role de l'espace admin

L'espace admin sert a:

- suivre les sessions passees par les candidats
- consulter le detail d'un candidat
- gerer les certifications
- gerer les packs
- gerer les questions
- analyser la performance des questions
- administrer les utilisateurs

## Sessions

La page `Sessions` permet de surveiller les passages recents.

Tu peux y voir:

- le candidat
- le pack
- le type de session
- le score
- le statut
- un acces au detail

Le detail d'une session permet ensuite de:

- revoir les questions posees
- voir les reponses du candidat
- verifier les bonnes reponses
- ouvrir rapidement la fiche question
- ouvrir l'analyse de performance de la question

## Candidat

La fiche candidat centralise la vision par personne.

Elle permet notamment de consulter:

- les informations du candidat
- son historique de sessions
- ses certifications
- certains parametres admin lies a son parcours

## Certifications

La page `Certifications` donne une vue de suivi par candidat et par pack.

On y retrouve en general:

- l'etat de la certification
- la date de derniere obtention
- la date d'expiration
- l'acces au detail de la derniere session

## Utilisateurs

La page `Utilisateurs` sert a gerer les comptes applicatifs.

Elle permet de:

- consulter la liste des comptes
- modifier certaines informations utilisateur
- ajuster le role si necessaire

## Packs

La page `Packs` sert a gerer les certifications disponibles dans l'outil.

Un pack peut definir:

- son nom
- sa couleur d'affichage
- sa duree
- son seuil de reussite
- son nombre de questions
- sa logique de selection
- sa validite de certification
- le cooldown apres echec

Depuis `Modifier pack`, il est possible de:

- ajuster les parametres du pack
- revoir les questions associees
- ouvrir l'edition d'une question
- ouvrir la vue performance d'une question

## Questions

La page `Questions` est la banque de questions globale.

Elle permet de:

- rechercher des questions
- les filtrer
- les modifier
- les supprimer
- ouvrir la performance d'une question

## Import de questions

La page d'import sert a injecter ou mettre a jour des questions en masse.

## Analyse

La page `Analyse` donne une vue agregee par question.

Elle aide a reperer:

- les questions tres reussies
- les questions souvent echouees
- les questions peu utilisees
- les effets d'un filtre de dates, pack ou type de session

Le bouton de zoom ouvre ensuite le detail des sessions liees a une question donnee.
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
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
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
