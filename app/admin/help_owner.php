<?php
require_once __DIR__ . '/_auth.php';
$user = require_team_reporting();
// ADMINs have their own full guide — redirect them there
if (user_has_role($user, 'ADMIN')) {
  header('Location: /admin/help.php');
  exit;
}
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/_nav.php';

function admin_help_owner_fallback_markdown(string $lang = 'fr'): string {
  if ($lang === 'en') {
    return <<<'MD'
# Owner guide

This guide explains what you can do as a programme owner: monitor sessions, view certifications, and manage your team members.

## Your role

As an owner, you have access to the administration area scoped to your programme. You can track your candidates' activity, follow their progress, and manage who belongs to your team.

You do not manage packs, questions, or global settings — those are handled by the administrator.

## Sessions

The `Sessions` page lists all exam attempts made within your programme.

For each session you can see:

- the candidate
- the pack taken
- the session type (practice or certification)
- the score achieved
- the status (passed, failed, in progress…)
- a link to the full detail

The session detail lets you review the questions asked, the candidate's answers, the correct answers, and open the candidate profile.

The available filters (dates, pack, status) let you narrow the list to what you need. You can also export the filtered list as a CSV file.

## Certifications

The `Certifications` page provides a tracking view by candidate and by pack.

For each row you will find:

- the candidate's name
- the pack concerned
- the certification status (active, expired, never obtained)
- the date of last success
- the expiry date
- a link to the last certification session

## Users

The `Users` page lets you manage the accounts in your team.

You can browse the list of members attached to your programme, open a user's profile, and change a user's role between `USER` and `OWNER`.

You cannot create or delete accounts, modify an `ADMIN` account, or access users from other programmes.

## Candidate profile

Clicking on a candidate's name opens their full profile with their account information, their full session history in your programme, and the status of their certifications.
MD;
  }

  if ($lang === 'es') {
    return <<<'MD'
# Guía del owner

Esta guía explica lo que puedes hacer como owner de un programa: supervisar las sesiones, consultar las certificaciones y gestionar los miembros de tu equipo.

## Tu rol

Como owner, tienes acceso al espacio de administración limitado a tu programa. Puedes seguir la actividad de tus candidatos, acompañar su progresión y gestionar quién pertenece a tu equipo.

No gestionas los paquetes, las preguntas ni los parámetros globales — esos elementos corresponden al administrador.

## Sesiones

La página `Sesiones` lista todos los intentos de examen realizados dentro de tu programa.

Para cada sesión puedes ver el candidato, el paquete realizado, el tipo de sesión, la puntuación obtenida, el estado y un acceso al detalle completo.

El detalle te permite repasar las preguntas planteadas, ver las respuestas del candidato, identificar las respuestas correctas y abrir la ficha del candidato. Puedes exportar la lista filtrada en formato CSV.

## Certificaciones

La página `Certificaciones` proporciona una vista de seguimiento por candidato y por paquete.

Para cada línea encontrarás el nombre del candidato, el paquete, el estado de la certificación, la fecha de última aprobación, la fecha de vencimiento y un acceso a la última sesión.

## Usuarios

La página `Usuarios` te permite gestionar las cuentas de tu equipo.

Puedes consultar la lista de miembros, acceder a la ficha de un usuario y cambiar su rol entre `USER` y `OWNER`.

No puedes crear ni eliminar cuentas, modificar una cuenta `ADMIN` ni acceder a los usuarios de otros programas.

## Perfil del candidato

Al hacer clic en el nombre de un candidato accedes a su ficha completa con su información de cuenta, su historial de sesiones en tu programa y el estado de sus certificaciones.
MD;
  }

  if ($lang === 'jp') {
    return <<<'MD'
# オーナーガイド

このガイドでは、プログラムオーナーとしてできることを説明します：セッションの監視、認定の確認、チームメンバーの管理。

## あなたの役割

オーナーとして、あなたは自分のプログラムに限定された管理エリアにアクセスできます。候補者の活動を追跡し、進捗を確認し、チームのメンバーを管理できます。

パック、問題、グローバル設定の管理は行いません — それらは管理者が担当します。

## セッション

`セッション`ページには、あなたのプログラムで行われたすべての受験が一覧表示されます。各セッションで候補者名、パック、セッション種類、スコア、ステータス、詳細へのリンクを確認できます。

詳細画面では出題された問題、候補者の回答、正解を確認し、候補者プロフィールを開けます。フィルタリングした一覧をCSV形式でエクスポートすることもできます。

## 認定

`認定`ページでは、候補者とパック別の追跡ビューを確認できます。各行に候補者名、パック、認定ステータス、最終合格日、有効期限、最後のセッションへのリンクが表示されます。

## ユーザー

`ユーザー`ページでは、チームのアカウントを管理できます。

メンバーの一覧を確認し、ユーザーのプロフィールを開き、ロールを`USER`と`OWNER`の間で変更できます。

アカウントの作成・削除、`ADMIN`アカウントの変更、他のプログラムのユーザーへのアクセスはできません。

## 候補者プロフィール

候補者名をクリックするとプロフィールが開き、アカウント情報、セッション履歴、認定の取得状況を確認できます。
MD;
  }

  // Default: French
  return <<<'MD'
# Guide owner

Ce guide explique ce que tu peux faire en tant qu'owner de programme : suivre les sessions, consulter les certifications et gérer les membres de ton équipe.

## Ton rôle

En tant qu'owner, tu as accès à l'espace d'administration limité à ton programme. Tu peux surveiller l'activité de tes candidats, valider leur progression et gérer qui appartient à ton équipe.

Tu ne gères pas les packs, les questions ni les paramètres globaux — ces éléments sont du ressort de l'administrateur.

## Sessions

La page `Sessions` liste tous les passages d'examen effectués dans ton programme.

Pour chaque session tu vois le candidat, le pack passé, le type de session, le score obtenu, le statut et un lien vers le détail complet.

Le détail te permet de revoir les questions posées, voir les réponses du candidat, identifier les bonnes réponses et ouvrir la fiche candidat. Tu peux aussi exporter la liste filtrée en CSV.

## Certifications

La page `Certifications` donne une vue de suivi par candidat et par pack.

Pour chaque ligne tu trouves le nom du candidat, le pack concerné, le statut de certification, la date de dernière réussite, la date d'expiration et un lien vers la dernière session.

## Utilisateurs

La page `Utilisateurs` te permet de gérer les comptes de ton équipe.

Tu peux consulter la liste des membres, accéder à la fiche d'un utilisateur et modifier son rôle entre `USER` et `OWNER`.

Tu ne peux pas créer ni supprimer des comptes, modifier un compte `ADMIN`, ni accéder aux utilisateurs d'autres programmes.

## Profil candidat

En cliquant sur le nom d'un candidat tu accèdes à sa fiche complète avec ses informations de compte, l'historique de ses sessions dans ton programme et l'état de ses certifications.
MD;
}

$docsDirs = [dirname(__DIR__) . '/docs', '/opt/certif/docs'];
$guideMarkdown = '';
foreach ($docsDirs as $docsDir) {
  // Try language-specific file first (e.g. GUIDE_OWNER_en.md)
  $langFile = $docsDir . '/GUIDE_OWNER_' . $lang . '.md';
  if (is_file($langFile)) {
    $guideMarkdown = (string)file_get_contents($langFile);
    break;
  }
  // Fall back to default file
  $defaultFile = $docsDir . '/GUIDE_OWNER.md';
  if (is_file($defaultFile)) {
    $guideMarkdown = (string)file_get_contents($defaultFile);
    break;
  }
}
if ($guideMarkdown === '') {
  $guideMarkdown = admin_help_owner_fallback_markdown($lang);
}
$docSections = [];
if (preg_match_all('/^##\s+(.+)$/m', $guideMarkdown, $matches)) {
  foreach ($matches[1] as $heading) {
    $label = trim((string)$heading);
    $slug = app_markdown_slugify($label);
    if ($slug !== '') {
      $docSections[] = ['label' => $label, 'slug' => $slug];
    }
  }
}
$guideHtml = app_markdown_to_html($guideMarkdown);
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h(t('admin.help.title', [], $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container admin-container">
  <div class="card admin-card admin-page-shell">
    <div class="admin-head admin-page-hero">
      <div class="admin-head-copy">
        <p class="admin-page-eyebrow"><?= h(t('admin.nav.group_admin', [], $lang)) ?></p>
        <h2 class="h1"><?= h(t('admin.help.title', [], $lang)) ?></h2>
        <p class="sub"><?= h(t('admin.help.subtitle', [], $lang)) ?></p>
      </div>
      <div class="admin-head-actions">
        <?php render_admin_tabs('help'); ?>
      </div>
    </div>
    <div class="doc-layout doc-layout-admin">
      <?php if ($docSections): ?>
        <aside class="card doc-toc">
          <p class="doc-toc-title"><?= h(t('admin.help.toc_title', [], $lang)) ?></p>
          <nav aria-label="<?= h(t('admin.help.toc_aria', [], $lang)) ?>">
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
