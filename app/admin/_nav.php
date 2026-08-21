<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

if (!function_exists('admin_tab_icon_svg')) {
  function admin_tab_icon_svg(string $key): string
  {
    return match ($key) {
      'candidate' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 3.35 1.42.96 1.7.02.8 1.5 1.55.68.02 1.7 1.1 1.28-.48 1.63.48 1.63-1.1 1.28-.02 1.7-1.55.68-.8 1.5-1.7.02-1.42.96-1.42-.96-1.7-.02-.8-1.5-1.55-.68-.02-1.7-1.1-1.28.48-1.63-.48-1.63 1.1-1.28.02-1.7 1.55-.68.8-1.5 1.7-.02L12 3.35Z"/><circle cx="12" cy="10.8" r="4.15"/><path d="m10.1 10.8 1.38 1.4 2.72-2.73"/><path d="m8.35 16.45-1.1 4.2 2-.95 1.15 1.75 1.1-3.95"/><path d="m15.65 16.45 1.1 4.2-2-.95-1.15 1.75-1.1-3.95"/></svg>',
      'sessions' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 4h12M8 11h8M8 15h5"/></svg>',
      'certifications' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 3.35 1.42.96 1.7.02.8 1.5 1.55.68.02 1.7 1.1 1.28-.48 1.63.48 1.63-1.1 1.28-.02 1.7-1.55.68-.8 1.5-1.7.02-1.42.96-1.42-.96-1.7-.02-.8-1.5-1.55-.68-.02-1.7-1.1-1.28.48-1.63-.48-1.63 1.1-1.28.02-1.7 1.55-.68.8-1.5 1.7-.02L12 3.35Z"/><circle cx="12" cy="10.8" r="4.15"/><path d="m10.1 10.8 1.38 1.4 2.72-2.73"/><path d="m8.35 16.45-1.1 4.2 2-.95 1.15 1.75 1.1-3.95"/><path d="m15.65 16.45 1.1 4.2-2-.95-1.15 1.75-1.1-3.95"/></svg>',
      'users' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm10 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3ZM7 13c-3.3 0-6 1.7-6 3.8V19h12v-2.2C13 14.7 10.3 13 7 13Zm10 0c-1.1 0-2.2.2-3.1.6A4.8 4.8 0 0 1 16 16.8V19h7v-2.2c0-2.1-2.7-3.8-6-3.8Z"/></svg>',
      'programs' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16v4H4zM4 14h10v4H4zM16 14h4v4h-4z"/></svg>',
      'packages' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7.5 12 3l9 4.5-9 4.5-9-4.5Zm0 4.5 9 4.5 9-4.5M3 16.5 12 21l9-4.5"/></svg>',
      'questions' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.35 9.2a2.65 2.65 0 1 1 5.15.9c0 1.75-2.5 2.52-2.5 4.15"/><path d="M12 16.95h.01"/><path d="M4 4h16v16H4z"/></svg>',
      'translations' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h7v2H4zM4 11h7v2H4zM4 16h7v2H4zM14 6h6v2h-6zM14 11h6v2h-6zM14 16h6v2h-6z"/><path d="M12 4v16"/></svg>',
      'performance' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 19h14M7 17V9m5 8V5m5 12v-6"/></svg>',
      'global_settings' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.15 7.15 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.49-.42h-3.84a.5.5 0 0 0-.49.42l-.36 2.54c-.58.23-1.13.54-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.7 8.84a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L2.82 14.52a.5.5 0 0 0-.12.64l1.92 3.32a.5.5 0 0 0 .6.22l2.39-.96c.5.4 1.05.71 1.63.94l.36 2.54a.5.5 0 0 0 .49.42h3.84a.5.5 0 0 0 .49-.42l.36-2.54c.58-.23 1.13-.54 1.63-.94l2.39.96a.5.5 0 0 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58ZM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7Z"/></svg>',
      'help' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.3"/><path d="M9.4 9.35a2.6 2.6 0 1 1 5.05.87c0 1.72-2.45 2.47-2.45 4.08"/><path d="M12 16.95h.01"/></svg>',
      'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 16l4-4-4-4M19 12H9"/></svg>',
      default => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 4h16v16H4z"/></svg>',
    };
  }
}

if (!function_exists('render_admin_program_switcher')) {
  function render_admin_program_switcher(): void
  {
    $user = current_user();
    if (!$user) {
      return;
    }

    $pdo = db();
    $programs = auth_manageable_programs($pdo, $user);
    if (!$programs) {
      return;
    }

    $activeProgramId = auth_admin_program_context($pdo, $user, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
    if (count($programs) === 1) {
      $program = $programs[0];
      $programName = trim((string)($program['name'] ?? ''));
      if ($programName === '') {
        $programName = 'Programme #' . (int)($program['id'] ?? $activeProgramId);
      }
      $monitorSvg = '<svg class="admin-program-switcher-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 4h18v12H3z"/><path d="M10 16h4v3h4v2H6v-2h4z"/></svg>';
      echo '<div class="admin-program-switcher admin-program-current">';
      echo '<div class="admin-program-switcher-head">';
      echo $monitorSvg;
      echo '<span class="admin-program-switcher-copy">';
      echo '<span class="admin-program-switcher-label">' . h(t('admin.common.program', [], function_exists('get_lang') ? get_lang() : 'fr')) . '</span>';
      echo '</span>';
      echo '</div>';
      echo '<div class="admin-program-current-name">' . h($programName) . '</div>';
      echo '</div>';
      return;
    }

    $currentPath = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/admin/index.php'), PHP_URL_PATH));
    if ($currentPath === '') {
      $currentPath = '/admin/index.php';
    }

    echo '<form class="admin-program-switcher" method="get" action="' . h($currentPath) . '">';
    foreach ($_GET as $key => $value) {
      if ($key === 'program_id') {
        continue;
      }
      if (is_array($value)) {
        continue;
      }
      echo '<input type="hidden" name="' . h((string)$key) . '" value="' . h((string)$value) . '">';
    }
    $monitorSvg = '<svg class="admin-program-switcher-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 4h18v12H3z"/><path d="M10 16h4v3h4v2H6v-2h4z"/></svg>';
    echo '<div class="admin-program-switcher-head">';
    echo $monitorSvg;
    echo '<span class="admin-program-switcher-copy">';
    echo '<label class="admin-program-switcher-label" for="admin-program-id">' . h(t('admin.common.program', [], function_exists('get_lang') ? get_lang() : 'fr')) . '</label>';
    echo '</span>';
    echo '</div>';
    echo '<select class="input admin-program-switcher-select" id="admin-program-id" name="program_id" onchange="this.form.submit()">';
    foreach ($programs as $program) {
      $programId = (int)($program['id'] ?? 0);
      $programName = (string)($program['name'] ?? '');
      echo '<option value="' . $programId . '"' . ($programId === $activeProgramId ? ' selected' : '') . '>' . h($programName) . '</option>';
    }
    echo '</select>';
    echo '</form>';
  }
}

if (!function_exists('render_admin_tab_link')) {
  function render_admin_tab_link(array $tab, string $active): void
  {
    $isActive = ($active !== '' && $active === ($tab['key'] ?? ''));
    $classes = 'btn ghost admin-tab';
    if (!empty($tab['extra_class'])) {
      $classes .= ' ' . $tab['extra_class'];
    }
    if ($isActive) {
      $classes .= ' is-active';
    }

    $href = (string)($tab['href'] ?? '#');
    if (str_starts_with($href, '/admin/')) {
      $user = current_user();
      if ($user) {
        $pdo = db();
        $activeProgramId = auth_admin_program_context($pdo, $user, isset($_GET['program_id']) ? (int)$_GET['program_id'] : null);
        if ($activeProgramId > 0) {
          $parts = parse_url($href);
          $path = (string)($parts['path'] ?? $href);
          $query = [];
          if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
          }
          if (!isset($query['program_id'])) {
            $query['program_id'] = $activeProgramId;
          }
          $fragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';
          $href = $path . ($query ? ('?' . http_build_query($query)) : '') . $fragment;
        }
      }
    }

    echo '<a class="' . $classes . '" href="' . h($href) . '">';
    echo '<span class="admin-tab-icon">' . admin_tab_icon_svg((string)($tab['key'] ?? '')) . '</span>';
    echo '<span class="admin-tab-label">' . ($tab['label'] ?? '') . '</span>';
    echo '</a>';
  }
}

if (!function_exists('render_admin_tab_group')) {
  function render_admin_tab_group(string $label, string $iconKey, array $tabs, string $active): void
  {
    $groupIsActive = false;
    foreach ($tabs as $tab) {
      if (($tab['key'] ?? '') === $active) {
        $groupIsActive = true;
        break;
      }
    }

    echo '<details class="admin-tab-group"' . ($groupIsActive ? ' open' : '') . '>';
    echo '<summary class="admin-tab-group-summary">';
    echo '<span class="admin-tab-group-summary-main">';
    echo '<span class="admin-tab-icon admin-tab-group-icon">' . admin_tab_icon_svg($iconKey) . '</span>';
    echo '<span class="admin-tab-group-label">' . h($label) . '</span>';
    echo '</span>';
    echo '<span class="admin-tab-group-chevron" aria-hidden="true">';
    echo '<svg viewBox="0 0 24 24" focusable="false"><path d="m8 10 4 4 4-4"/></svg>';
    echo '</span>';
    echo '</summary>';
    echo '<div class="admin-tab-group-links">';
    foreach ($tabs as $tab) {
      render_admin_tab_link($tab, $active);
    }
    echo '</div>';
    echo '</details>';
  }
}

function render_admin_tabs(string $active = ''): void
{
  $user = current_user() ?? ['role' => 'USER'];
  $candidateTab = ['key' => 'candidate', 'href' => '/dashboard.php', 'label' => 'Espace candidat'];
  $helpHref = user_has_role($user, 'ADMIN') ? '/admin/help.php' : '/admin/help_owner.php';
  $helpTab = ['key' => 'help', 'href' => $helpHref, 'label' => 'Documentation'];
  $logoutTab = ['key' => 'logout', 'href' => '/logout.php', 'label' => 'D&eacute;connexion', 'extra_class' => 'admin-logout-btn'];
  $navLang = function_exists('get_lang') ? get_lang() : 'fr';
  $reportingTabs = [
    ['key' => 'sessions', 'href' => '/admin/index.php', 'label' => h(t('admin.nav.sessions', [], $navLang))],
    ['key' => 'certifications', 'href' => '/admin/certifications.php', 'label' => h(t('admin.nav.certifications', [], $navLang))],
  ];
  $globalAdminTabs = [];
  if (user_can_access_admin_area($user)) {
    $globalAdminTabs[] = ['key' => 'users', 'href' => '/admin/users.php', 'label' => h(t('admin.nav.users', [], $navLang))];
  }
  if (user_can_manage_program_catalog($user)) {
    $globalAdminTabs[] = ['key' => 'programs', 'href' => '/admin/programs.php', 'label' => h(t('admin.nav.programs', [], $navLang))];
  }
  if (user_has_role($user, 'ADMIN')) {
    $globalAdminTabs[] = ['key' => 'global_settings', 'href' => '/admin/global_settings.php', 'label' => h(t('admin.nav.settings', [], $navLang))];
  }
  $contentTabs = user_can_access_admin_area($user)
    ? [
        ['key' => 'questions', 'href' => '/admin/questions.php', 'label' => h(t('admin.nav.questions', [], $navLang))],
        ['key' => 'packages', 'href' => '/admin/packages.php', 'label' => h(t('admin.nav.packages', [], $navLang))],
        ['key' => 'translations', 'href' => '/admin/question_translations.php', 'label' => h(t('admin.nav.translations', [], $navLang))],
        ['key' => 'performance', 'href' => '/admin/question_performance.php', 'label' => h(t('admin.nav.performance', [], $navLang))],
      ]
    : [];

  $adminLang = function_exists('get_lang') ? get_lang() : 'fr';
  $currentUri = (string)($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
  $uriParts = parse_url($currentUri);
  $uriPath = (string)($uriParts['path'] ?? '/admin/index.php');
  $uriQuery = [];
  if (!empty($uriParts['query'])) {
    parse_str((string)$uriParts['query'], $uriQuery);
  }

  $flagMap = ['fr' => 'fr', 'en' => 'gb', 'es' => 'es', 'jp' => 'jp'];
  $activeFlag = $flagMap[$adminLang] ?? 'fr';

  echo '<nav class="admin-tabs" aria-label="Navigation administration">';
  echo '<div class="admin-tabs-top">';
  echo '<a class="btn ghost admin-tab admin-tab-candidate" href="' . $candidateTab['href'] . '">';
  echo '<span class="admin-tab-label">' . h(t('admin.nav.candidate_space', [], $adminLang)) . '</span>';
  echo '</a>';
  echo '<div class="admin-lang-picker" id="adminLangPicker">';
  echo '<button class="admin-lang-picker-btn" id="adminLangPickerBtn" type="button" aria-haspopup="true" aria-expanded="false">';
  echo '<img src="https://flagcdn.com/20x15/' . $activeFlag . '.png" width="20" height="15" alt="' . h(strtoupper($adminLang)) . '" style="border-radius:2px;">';
  echo '</button>';
  echo '<div class="admin-lang-picker-dropdown" id="adminLangPickerDropdown" role="menu">';
  foreach ($flagMap as $code => $flagCode) {
    $q = $uriQuery;
    $q['lang'] = $code;
    $href = $uriPath . '?' . http_build_query($q);
    $isActive = $adminLang === $code;
    echo '<a class="admin-lang-picker-option' . ($isActive ? ' is-active' : '') . '" href="' . h($href) . '" role="menuitem">';
    echo '<img src="https://flagcdn.com/20x15/' . $flagCode . '.png" width="20" height="15" alt="' . h(strtoupper($code)) . '" style="border-radius:2px;">';
    echo '</a>';
  }
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '<div class="admin-tabs-quick-actions">';
  echo '<a class="btn ghost admin-tab admin-quick-action admin-logout-btn" href="' . h((string)$logoutTab['href']) . '">';
  echo '<span class="admin-tab-icon">' . admin_tab_icon_svg('logout') . '</span>';
  echo '<span class="admin-tab-label">' . h(t('admin.nav.logout', [], $adminLang)) . '</span>';
  echo '</a>';
  echo '<a class="btn ghost dashboard-help-btn admin-quick-action admin-help-icon-btn" href="' . h((string)$helpTab['href']) . '" aria-label="' . h(t('admin.nav.documentation', [], $adminLang)) . '" title="' . h(t('admin.nav.documentation', [], $adminLang)) . '">';
  echo '<svg class="help-inline-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
  echo '<circle cx="12" cy="12" r="8.3"/>';
  echo '<path d="M9.4 9.35a2.6 2.6 0 1 1 5.05.87c0 1.72-2.45 2.47-2.45 4.08"/>';
  echo '<path d="M12 16.95h.01"/>';
  echo '</svg>';
  echo '</a>';
  echo '</div>';
  echo '<div class="admin-tabs-main">';
  if (!empty($globalAdminTabs)) {
    render_admin_tab_group(t('admin.nav.group_admin', [], $navLang), 'global_settings', $globalAdminTabs, $active);
  }
  echo '<div class="admin-nav-divider" aria-hidden="true"></div>';
  echo '<div class="admin-nav-context">';
  render_admin_program_switcher();
  if (user_can_access_reporting_area($user) && $reportingTabs) {
    render_admin_tab_group(t('admin.nav.group_reporting', [], $navLang), 'sessions', $reportingTabs, $active);
  }
  if ($contentTabs) {
    render_admin_tab_group(t('admin.nav.group_content', [], $navLang), 'packages', $contentTabs, $active);
  }
  echo '</div>';
  echo '</div>';

  echo '</nav>';
  echo "<script>document.body.classList.add('admin-with-sidebar');
(function(){
  var btn=document.getElementById('adminLangPickerBtn');
  var dd=document.getElementById('adminLangPickerDropdown');
  if(!btn||!dd)return;
  btn.addEventListener('click',function(e){
    e.stopPropagation();
    var open=dd.classList.toggle('is-open');
    btn.setAttribute('aria-expanded',open?'true':'false');
  });
  document.addEventListener('click',function(){
    dd.classList.remove('is-open');
    btn.setAttribute('aria-expanded','false');
  });
})();
</script>";
}
