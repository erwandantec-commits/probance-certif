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

Ce document explique comment utiliser l'outil de certification Probance au quotidien, sans entrer dans les details techniques.

## A quoi sert l'outil

L'outil permet de:

- se connecter a son espace
- lancer un questionnaire de certification ou d'entrainement
- repondre aux questions dans un temps donne
- consulter son resultat
- suivre son historique de passages
- visualiser ses certifications obtenues ou a renouveler

## Resume rapide

Pour utiliser l'outil simplement:

1. connecte-toi
2. choisis un package
3. lance une session
4. reponds aux questions avant la fin du temps
5. consulte ton resultat
6. suis tes certifications depuis le tableau de bord

## Se connecter

Pour acceder a l'outil:

1. ouvre la page de connexion
2. saisis ton email et ton mot de passe
3. valide pour arriver sur ton tableau de bord

Si tu n'as pas encore de compte, utilise la page d'inscription.

Si tu as oublie ton mot de passe, utilise la fonction de reinitialisation.

## Tableau de bord

Le tableau de bord est la page principale de l'utilisateur.

Tu y retrouves:

- les certifications disponibles
- les boutons pour lancer une session
- ton historique recent
- tes resultats precedents
- l'etat de tes certifications

Depuis cette page, tu peux choisir un package puis demarrer une session.

## Les deux types de session

L'outil propose en general deux modes:

### Mode Exam

Le mode `Exam` sert a passer une vraie tentative.

Dans ce mode:

- tu reponds aux questions sans correction immediate
- le score final est calcule a la fin
- le resultat determine si la certification est obtenue ou non

### Mode Entrainement

Le mode `Entrainement` sert a s'entrainer.

Dans ce mode:

- tu peux valider question par question
- l'outil peut afficher un retour immediat
- tu peux apprendre de tes erreurs plus facilement

## Demarrer une session

Pour lancer une session:

1. rends-toi sur le tableau de bord
2. choisis le package souhaite
3. choisis le type de session
4. clique sur le bouton de demarrage

L'outil ouvre alors la session de questions.

Dans certains cas, une session de certification peut etre refusee:

- si une certification valide existe deja
- si un delai d'attente est en cours apres un echec

Dans ce cas, un message explicatif s'affiche.

## Repondre aux questions

Pendant une session:

- une question est affichee a la fois
- un chronometre indique le temps restant
- selon la question, une ou plusieurs reponses peuvent etre correctes

Conseils:

- lis bien l'enonce avant de repondre
- verifie si plusieurs choix sont attendus
- surveille le temps restant

Selon le mode de session:

- en mode `Exam`, tu avances sans correction immediate
- en mode `Entrainement`, tu peux voir un retour avant de passer a la suite

## Fin de session

Une session peut se terminer de plusieurs manieres:

- tu arrives a la derniere question et tu valides
- tu choisis de terminer la session
- le temps imparti est ecoule

Une fois terminee, tu es redirige vers la page de resultat.

## Comprendre le resultat

La page de resultat affiche generalement:

- le score obtenu
- le statut de la session
- le package concerne
- la date de passage
- le resultat obtenu: reussi, echoue ou expire

Si la session correspond a une certification:

- un succes peut valider la certification
- un echec signifie que le seuil n'a pas ete atteint
- une expiration signifie que le temps a ete depasse

## Revoir ses reponses

En mode entrainement, l'outil permet de revoir:

- les questions posees
- tes reponses
- les bonnes reponses
- le statut de chaque question

Cette fonction est utile pour progresser et comprendre ses erreurs.

## Historique et certifications

Depuis ton espace, tu peux consulter:

- l'historique de tes sessions
- tes scores precedents
- l'etat de tes certifications

Une certification peut apparaitre comme:

- valide
- bientot expirante
- expiree
- absente

Cela permet de savoir rapidement si une nouvelle tentative est necessaire.

## Changer la langue

L'outil peut proposer plusieurs langues d'affichage.

Si cette option est disponible:

- choisis la langue dans le selecteur prevu
- la page se recharge avec les textes correspondants

## En cas de probleme

Si tu rencontres un souci:

- verifie d'abord ton email et ton mot de passe
- recharge la page si un affichage semble bloque
- verifie que ta session n'a pas expire
- contacte un administrateur si tu penses qu'un acces ou une certification est bloquee a tort

## Questions frequentes

### Je ne peux pas lancer une certification, pourquoi ?

Ca peut arriver si:

- tu as deja une certification encore valide
- un delai d'attente est actif apres un echec
- le package n'est pas disponible

### Quelle difference entre entrainement et exam ?

L'entrainement sert a t'entrainer.
L'exam sert a evaluer officiellement ton resultat.

### Que se passe-t-il si je manque de temps ?

La session est consideree comme expiree et le resultat est calcule selon l'etat au moment de la fin.

### Puis-je reprendre plus tard ?

Cela depend du fonctionnement active dans l'outil et de tes droits.
Dans la plupart des cas utilisateur standards, il vaut mieux considerer qu'une session doit etre terminee dans la meme plage de travail.
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
