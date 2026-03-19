<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

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

$guideHtml = $guideMarkdown !== ''
  ? app_markdown_to_html($guideMarkdown)
  : '<p class="empty-state">Le guide utilisateur est indisponible pour le moment.</p>';

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
