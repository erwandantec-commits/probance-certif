<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

function help_fallback_markdown(string $lang): string {
  return match ($lang) {
    'en' => <<<'MD'
# User guide

## Purpose

This area lets you:

- sign in to your account
- launch a certification or training session
- answer questions within a limited time
- review your result and history

## Dashboard

From the dashboard you can:

- choose a certification package
- choose a session mode
- start a new session
- resume an active session
- review your latest sessions

## Session modes

### Certification

Official mode. The result is recorded and may validate your certification.

### Training

Practice mode. It helps you train before taking the official certification.

## Results

At the end of a session you can review:

- the score
- the status
- the certification concerned
- the result obtained

## Need help?

If something looks blocked, contact an administrator.
MD,
    'es' => <<<'MD'
# Guia del usuario

## Objetivo

Este espacio te permite:

- iniciar sesion
- lanzar una certificacion o un entrenamiento
- responder preguntas en un tiempo limitado
- consultar el resultado y el historial

## Panel principal

Desde el panel puedes:

- elegir un paquete de certificacion
- elegir un modo de sesion
- iniciar una nueva sesion
- retomar una sesion activa
- consultar las ultimas sesiones

## Modos de sesion

### Certificacion

Modo oficial. El resultado se guarda y puede validar tu certificacion.

### Entrenamiento

Modo de practica. Te ayuda a prepararte antes del examen oficial.

## Resultados

Al final de una sesion puedes consultar:

- la puntuacion
- el estado
- la certificacion correspondiente
- el resultado obtenido

## Necesitas ayuda?

Si algo parece bloqueado, contacta con un administrador.
MD,
    'jp' => <<<'MD'
# ユーザーガイド

## この画面でできること

このスペースでは次の操作ができます。

- ログイン
- 認定またはトレーニングの開始
- 制限時間内での回答
- 結果と履歴の確認

## ダッシュボード

ダッシュボードでは次の操作ができます。

- 認定パッケージの選択
- セッションモードの選択
- 新しいセッションの開始
- 進行中セッションの再開
- 最近のセッションの確認

## セッションモード

### 認定

公式モードです。結果は保存され、認定の判定に使われます。

### トレーニング

練習用モードです。本番前の学習に使えます。

## 結果

セッション終了後、次の情報を確認できます。

- スコア
- 状態
- 対象の認定
- 合否結果

## 困ったとき

画面がブロックされているように見える場合は、管理者に連絡してください。
MD,
    default => <<<'MD'
# Guide utilisateur

## A quoi sert cet espace

Cet espace permet de :

- te connecter
- lancer une certification ou un entrainement
- repondre aux questions dans un temps limite
- consulter ton resultat et ton historique

## Tableau de bord

Depuis le tableau de bord, tu peux :

- choisir une certification
- choisir un mode de session
- lancer une nouvelle session
- reprendre une session en cours
- consulter tes dernieres sessions

## Modes de session

### Certification

Mode officiel. Le resultat est enregistre et peut valider ta certification.

### Entrainement

Mode de pratique. Il permet de t'exercer avant la certification officielle.

## Resultat

A la fin d'une session, tu peux consulter :

- le score
- le statut
- la certification concernee
- le resultat obtenu

## Besoin d'aide ?

Si quelque chose semble bloque, contacte un administrateur.
MD,
  };
}

$user = require_auth();
$lang = get_lang();
$guideSuffix = match ($lang) {
  'en' => '_EN',
  'es' => '_ES',
  'jp' => '_JP',
  default => '',
};
$guidePaths = [
  __DIR__ . '/docs/GUIDE_UTILISATEUR' . $guideSuffix . '.md',
  __DIR__ . '/docs/GUIDE_UTILISATEUR.md',
  dirname(__DIR__) . '/docs/GUIDE_UTILISATEUR' . $guideSuffix . '.md',
  dirname(__DIR__) . '/docs/GUIDE_UTILISATEUR.md',
  '/opt/certif/docs/GUIDE_UTILISATEUR' . $guideSuffix . '.md',
  '/opt/certif/docs/GUIDE_UTILISATEUR.md',
];
$guideMarkdown = '';
foreach ($guidePaths as $guidePath) {
  if (is_file($guidePath)) {
    $guideMarkdown = (string)file_get_contents($guidePath);
    break;
  }
}
if ($guideMarkdown === '') {
  $guideMarkdown = help_fallback_markdown($lang);
}

$docSections = [];
if ($guideMarkdown !== '' && preg_match_all('/^##\s+(.+)$/m', $guideMarkdown, $matches)) {
  foreach ($matches[1] as $heading) {
    $label = trim((string)$heading);
    $slug = strtolower($label);
    $slug = preg_replace('/[^[:alnum:]]+/u', '-', $slug);
    $slug = trim((string)$slug, '-');
    if ($slug !== '') {
      $docSections[] = ['label' => $label, 'slug' => $slug];
    }
  }
}

$guideHtml = app_markdown_to_html($guideMarkdown);

$helpTitle = match ($lang) {
  'en' => 'User Help',
  'es' => 'Ayuda del usuario',
  'jp' => 'ユーザーガイド',
  default => 'Aide utilisateur',
};

$helpSubtitle = match ($lang) {
  'en' => 'A practical guide to using the candidate area, finding the main actions and understanding the certification flow.',
  'es' => 'Guia practica para usar el espacio candidato, encontrar las acciones principales y entender el recorrido de certificacion.',
  'jp' => '候補者スペースの使い方、主な操作、認定の流れを分かりやすくまとめたガイドです。',
  default => "Guide pratique pour utiliser l'espace candidat, retrouver les principales actions et comprendre le parcours de certification.",
};

$helpKicker = match ($lang) {
  'en' => 'Help center',
  'es' => 'Centro de ayuda',
  'jp' => 'ヘルプセンター',
  default => "Centre d'aide",
};

$dashboardLabel = match ($lang) {
  'en' => 'Dashboard',
  'es' => 'Panel principal',
  'jp' => 'ダッシュボード',
  default => 'Tableau de bord',
};

$dashboardMeta = match ($lang) {
  'en' => 'Back to candidate area',
  'es' => 'Volver al espacio candidato',
  'jp' => '候補者スペースに戻る',
  default => 'Retour espace candidat',
};

$adminDocLabel = match ($lang) {
  'en' => 'Admin documentation',
  'es' => 'Documentacion admin',
  'jp' => '管理者ドキュメント',
  default => 'Documentation admin',
};

$adminDocMeta = match ($lang) {
  'en' => 'Open the administration guide',
  'es' => 'Ver la guia de administracion',
  'jp' => '管理者ガイドを開く',
  default => "Voir le guide d'administration",
};

$tocTitle = match ($lang) {
  'en' => 'Contents',
  'es' => 'Contenido',
  'jp' => '目次',
  default => 'Sommaire',
};

$languageLabel = match ($lang) {
  'en' => 'Language',
  'es' => 'Idioma',
  'jp' => '言語',
  default => 'Langue',
};
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>">
<head>
  <meta charset="utf-8">
  <title><?= h($helpTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= time() ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container doc-page">
  <div class="doc-topbar">
    <div class="doc-topbar-spacer"></div>
    <div class="doc-topbar-lang">
      <label class="lang-select-label doc-lang-label" for="help-lang"><?= h($languageLabel) ?></label>
      <select id="help-lang" class="input lang-select doc-lang-select" onchange="window.location.href='/help.php?lang=' + encodeURIComponent(this.value);">
        <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>><?= h(t('lang.fr', [], $lang)) ?></option>
        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>><?= h(t('lang.en', [], $lang)) ?></option>
        <option value="es" <?= $lang === 'es' ? 'selected' : '' ?>><?= h(t('lang.es', [], $lang)) ?></option>
        <option value="jp" <?= $lang === 'jp' ? 'selected' : '' ?>><?= h(t('lang.jp', [], $lang)) ?></option>
      </select>
    </div>
  </div>
  <div class="card dashboard-card doc-shell doc-hero">
    <div class="doc-hero-copy">
      <span class="doc-kicker"><?= h($helpKicker) ?></span>
      <h2 class="h1"><?= h($helpTitle) ?></h2>
      <p class="sub"><?= h($helpSubtitle) ?></p>
    </div>
    <div class="doc-hero-actions">
      <div class="doc-action-stack">
        <a class="doc-action-card" href="/dashboard.php?lang=<?= h(urlencode($lang)) ?>">
          <span class="doc-action-title"><?= h($dashboardLabel) ?></span>
          <span class="doc-action-meta"><?= h($dashboardMeta) ?></span>
        </a>
        <?php if (($user['role'] ?? 'USER') === 'ADMIN'): ?>
          <a class="doc-action-card" href="/admin/help.php">
            <span class="doc-action-title"><?= h($adminDocLabel) ?></span>
            <span class="doc-action-meta"><?= h($adminDocMeta) ?></span>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="doc-layout">
    <?php if ($docSections): ?>
      <aside class="card doc-toc">
        <p class="doc-toc-title"><?= h($tocTitle) ?></p>
        <nav aria-label="<?= h($tocTitle) ?>">
          <?php foreach ($docSections as $section): ?>
            <a class="doc-toc-link" href="#<?= h($section['slug']) ?>"><?= h($section['label']) ?></a>
          <?php endforeach; ?>
        </nav>
      </aside>
    <?php endif; ?>
    <div class="card dashboard-card doc-shell doc-body">
      <div class="doc-content">
        <?= $guideHtml ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
