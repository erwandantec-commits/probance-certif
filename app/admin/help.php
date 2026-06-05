<?php
require_once __DIR__ . '/_auth.php';
require_team_reporting();
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/_nav.php';

function admin_help_fallback_markdown(string $lang = 'fr'): string {
  if ($lang === 'en') {
    return <<<'MD'
# Admin guide

This guide explains the main administration screens without going into the code.

## Role of the admin area

The admin area is used to:

- monitor sessions taken by candidates
- view a candidate's profile
- manage certifications
- manage packs
- manage questions
- analyse question performance
- manage users

## Sessions

The `Sessions` page lets you monitor recent exam attempts.

You can see:

- the candidate
- the pack
- the session type
- the score
- the status
- a link to the session detail

The session detail lets you:

- review the questions asked
- see the candidate's answers
- check the correct answers
- quickly open the question editor
- open the question performance analysis

## Candidate

The candidate profile centralises everything about one person.

It lets you view:

- candidate information
- session history
- certifications
- admin parameters related to their learning path

## Certifications

The `Certifications` page provides a tracking view by candidate and pack.

It shows:

- certification status
- last passed date
- expiry date
- link to the last session detail

## Users

The `Users` page is used to manage user accounts.

It lets you:

- browse the account list
- edit user information
- adjust roles as needed

## Packs

The `Packs` page manages the certifications available in the tool.

A pack can define:

- its name
- display colour
- duration
- pass threshold
- number of questions
- selection logic
- certification validity
- cooldown after failure

From `Edit pack`, you can:

- adjust pack settings
- review associated questions
- open the question editor
- open the question performance view

## Questions

The `Questions` page is the global question bank.

It lets you:

- search questions
- filter them
- edit them
- delete them
- open a question's performance

## Import questions

The import page is used to inject or update questions in bulk.

## Analytics

The `Analytics` page gives an aggregated view per question.

It helps identify:

- questions that are answered correctly most often
- questions that are frequently failed
- questions that are rarely used
- the effect of date, pack or session type filters

The zoom button opens the detail of sessions linked to a given question.
MD;
  }

  if ($lang === 'es') {
    return <<<'MD'
# Guía de administración

Esta guía explica el funcionamiento de las principales pantallas de administración, sin entrar en el código.

## Rol del espacio admin

El espacio admin sirve para:

- seguir las sesiones de los candidatos
- consultar el perfil de un candidato
- gestionar las certificaciones
- gestionar los paquetes
- gestionar las preguntas
- analizar el rendimiento de las preguntas
- administrar los usuarios

## Sesiones

La página `Sesiones` permite supervisar los intentos recientes.

Puedes ver:

- el candidato
- el paquete
- el tipo de sesión
- la puntuación
- el estado
- un acceso al detalle

El detalle de una sesión permite:

- revisar las preguntas planteadas
- ver las respuestas del candidato
- verificar las respuestas correctas
- abrir rápidamente la ficha de pregunta
- abrir el análisis de rendimiento de la pregunta

## Candidato

La ficha del candidato centraliza la visión por persona.

Permite consultar:

- la información del candidato
- su historial de sesiones
- sus certificaciones
- ciertos parámetros de administración relacionados con su recorrido

## Certificaciones

La página `Certificaciones` proporciona una vista de seguimiento por candidato y por paquete.

Muestra:

- el estado de la certificación
- la fecha de última obtención
- la fecha de vencimiento
- el acceso al detalle de la última sesión

## Usuarios

La página `Usuarios` sirve para gestionar las cuentas de usuario.

Permite:

- consultar la lista de cuentas
- modificar información de usuario
- ajustar el rol si es necesario

## Paquetes

La página `Paquetes` gestiona las certificaciones disponibles en la herramienta.

Un paquete puede definir:

- su nombre
- su color de visualización
- su duración
- su umbral de aprobación
- su número de preguntas
- su lógica de selección
- su validez de certificación
- el tiempo de espera tras el fallo

Desde `Editar paquete`, es posible:

- ajustar los parámetros del paquete
- revisar las preguntas asociadas
- abrir el editor de preguntas
- abrir la vista de rendimiento de una pregunta

## Preguntas

La página `Preguntas` es el banco de preguntas global.

Permite:

- buscar preguntas
- filtrarlas
- modificarlas
- eliminarlas
- abrir el rendimiento de una pregunta

## Importar preguntas

La página de importación sirve para inyectar o actualizar preguntas en masa.

## Análisis

La página `Análisis` ofrece una vista agregada por pregunta.

Ayuda a identificar:

- las preguntas con mayor tasa de acierto
- las preguntas frecuentemente falladas
- las preguntas poco utilizadas
- los efectos de un filtro de fechas, paquete o tipo de sesión

El botón de zoom abre el detalle de las sesiones vinculadas a una pregunta.
MD;
  }

  if ($lang === 'jp') {
    return <<<'MD'
# 管理者ガイド

このガイドでは、コードに入らずに主要な管理画面の使い方を説明します。

## 管理スペースの役割

管理スペースは以下の目的で使用します：

- 受験者のセッションを追跡する
- 受験者のプロフィールを確認する
- 認定を管理する
- パックを管理する
- 問題を管理する
- 問題のパフォーマンスを分析する
- ユーザーを管理する

## セッション

`セッション`ページでは、最近の受験状況を監視できます。

以下を確認できます：

- 受験者
- パック
- セッションの種類
- スコア
- ステータス
- 詳細へのリンク

セッションの詳細では以下が可能です：

- 出題された問題を確認する
- 受験者の回答を確認する
- 正解を確認する
- 問題編集画面をすばやく開く
- 問題のパフォーマンス分析を開く

## 受験者

受験者プロフィールは、1名の受験者に関するすべての情報を集約します。

以下を確認できます：

- 受験者情報
- セッション履歴
- 認定
- 学習経路に関連する管理パラメータ

## 認定

`認定`ページでは、受験者とパック別の追跡ビューを提供します。

以下が表示されます：

- 認定ステータス
- 最終合格日
- 有効期限
- 最終セッション詳細へのリンク

## ユーザー

`ユーザー`ページはユーザーアカウントの管理に使用します。

以下が可能です：

- アカウント一覧を閲覧する
- ユーザー情報を編集する
- 必要に応じてロールを調整する

## パック

`パック`ページでは、ツールで利用可能な認定を管理します。

パックで定義できる項目：

- 名前
- 表示色
- 時間
- 合格ライン
- 問題数
- 選択ロジック
- 認定の有効期間
- 不合格後の待機期間

`パック編集`から以下が可能です：

- パック設定を調整する
- 関連問題を確認する
- 問題エディタを開く
- 問題のパフォーマンスビューを開く

## 問題

`問題`ページはグローバルな問題バンクです。

以下が可能です：

- 問題を検索する
- フィルタリングする
- 編集する
- 削除する
- 問題のパフォーマンスを開く

## 問題インポート

インポートページは、問題を一括で追加または更新するために使用します。

## 分析

`分析`ページでは、問題別の集計ビューを提供します。

以下の特定に役立ちます：

- 正答率が高い問題
- よく間違われる問題
- あまり使われていない問題
- 日付、パック、セッション種類フィルターの効果

ズームボタンをクリックすると、特定の問題に関連するセッションの詳細が表示されます。
MD;
  }

  // Default: French
  return <<<'MD'
# Guide administrateur

Ce guide explique le fonctionnement des principaux écrans d'administration, sans entrer dans le code.

## Rôle de l'espace admin

L'espace admin sert à :

- suivre les sessions passées par les candidats
- consulter le détail d'un candidat
- gérer les certifications
- gérer les packs
- gérer les questions
- analyser la performance des questions
- administrer les utilisateurs

## Sessions

La page `Sessions` permet de surveiller les passages récents.

Tu peux y voir :

- le candidat
- le pack
- le type de session
- le score
- le statut
- un accès au détail

Le détail d'une session permet ensuite de :

- revoir les questions posées
- voir les réponses du candidat
- vérifier les bonnes réponses
- ouvrir rapidement la fiche question
- ouvrir l'analyse de performance de la question

## Candidat

La fiche candidat centralise la vision par personne.

Elle permet notamment de consulter :

- les informations du candidat
- son historique de sessions
- ses certifications
- certains paramètres admin liés à son parcours

## Certifications

La page `Certifications` donne une vue de suivi par candidat et par pack.

On y retrouve en général :

- l'état de la certification
- la date de dernière obtention
- la date d'expiration
- l'accès au détail de la dernière session

## Utilisateurs

La page `Utilisateurs` sert à gérer les comptes applicatifs.

Elle permet de :

- consulter la liste des comptes
- modifier certaines informations utilisateur
- ajuster le rôle si nécessaire

## Packs

La page `Packs` sert à gérer les certifications disponibles dans l'outil.

Un pack peut définir :

- son nom
- sa couleur d'affichage
- sa durée
- son seuil de réussite
- son nombre de questions
- sa logique de sélection
- sa validité de certification
- le cooldown après échec

Depuis `Modifier pack`, il est possible de :

- ajuster les paramètres du pack
- revoir les questions associées
- ouvrir l'édition d'une question
- ouvrir la vue performance d'une question

## Questions

La page `Questions` est la banque de questions globale.

Elle permet de :

- rechercher des questions
- les filtrer
- les modifier
- les supprimer
- ouvrir la performance d'une question

## Import de questions

La page d'import sert à injecter ou mettre à jour des questions en masse.

## Analyse

La page `Analyse` donne une vue agrégée par question.

Elle aide à repérer :

- les questions très réussies
- les questions souvent échouées
- les questions peu utilisées
- les effets d'un filtre de dates, pack ou type de session

Le bouton de zoom ouvre ensuite le détail des sessions liées à une question donnée.
MD;
}

$docsDirs = [dirname(__DIR__) . '/docs', '/opt/certif/docs'];
$guideMarkdown = '';
foreach ($docsDirs as $docsDir) {
  // Try language-specific file first (e.g. GUIDE_ADMIN_en.md)
  $langFile = $docsDir . '/GUIDE_ADMIN_' . $lang . '.md';
  if (is_file($langFile)) {
    $guideMarkdown = (string)file_get_contents($langFile);
    break;
  }
  // Fall back to default file
  $defaultFile = $docsDir . '/GUIDE_ADMIN.md';
  if (is_file($defaultFile)) {
    $guideMarkdown = (string)file_get_contents($defaultFile);
    break;
  }
}
if ($guideMarkdown === '') {
  $guideMarkdown = admin_help_fallback_markdown($lang);
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
