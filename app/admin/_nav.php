<?php
if (!function_exists('admin_tab_icon_svg')) {
  function admin_tab_icon_svg(string $key): string
  {
    return match ($key) {
      'candidate' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 3.35 1.42.96 1.7.02.8 1.5 1.55.68.02 1.7 1.1 1.28-.48 1.63.48 1.63-1.1 1.28-.02 1.7-1.55.68-.8 1.5-1.7.02-1.42.96-1.42-.96-1.7-.02-.8-1.5-1.55-.68-.02-1.7-1.1-1.28.48-1.63-.48-1.63 1.1-1.28.02-1.7 1.55-.68.8-1.5 1.7-.02L12 3.35Z"/><circle cx="12" cy="10.8" r="4.15"/><path d="m10.1 10.8 1.38 1.4 2.72-2.73"/><path d="m8.35 16.45-1.1 4.2 2-.95 1.15 1.75 1.1-3.95"/><path d="m15.65 16.45 1.1 4.2-2-.95-1.15 1.75-1.1-3.95"/></svg>',
      'sessions' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 4h12M8 11h8M8 15h5"/></svg>',
      'certifications' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 3.35 1.42.96 1.7.02.8 1.5 1.55.68.02 1.7 1.1 1.28-.48 1.63.48 1.63-1.1 1.28-.02 1.7-1.55.68-.8 1.5-1.7.02-1.42.96-1.42-.96-1.7-.02-.8-1.5-1.55-.68-.02-1.7-1.1-1.28.48-1.63-.48-1.63 1.1-1.28.02-1.7 1.55-.68.8-1.5 1.7-.02L12 3.35Z"/><circle cx="12" cy="10.8" r="4.15"/><path d="m10.1 10.8 1.38 1.4 2.72-2.73"/><path d="m8.35 16.45-1.1 4.2 2-.95 1.15 1.75 1.1-3.95"/><path d="m15.65 16.45 1.1 4.2-2-.95-1.15 1.75-1.1-3.95"/></svg>',
      'users' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm10 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3ZM7 13c-3.3 0-6 1.7-6 3.8V19h12v-2.2C13 14.7 10.3 13 7 13Zm10 0c-1.1 0-2.2.2-3.1.6A4.8 4.8 0 0 1 16 16.8V19h7v-2.2c0-2.1-2.7-3.8-6-3.8Z"/></svg>',
      'packages' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7.5 12 3l9 4.5-9 4.5-9-4.5Zm0 4.5 9 4.5 9-4.5M3 16.5 12 21l9-4.5"/></svg>',
      'questions' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.35 9.2a2.65 2.65 0 1 1 5.15.9c0 1.75-2.5 2.52-2.5 4.15"/><path d="M12 16.95h.01"/><path d="M4 4h16v16H4z"/></svg>',
      'performance' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 19h14M7 17V9m5 8V5m5 12v-6"/></svg>',
      'help' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.3"/><path d="M9.4 9.35a2.6 2.6 0 1 1 5.05.87c0 1.72-2.45 2.47-2.45 4.08"/><path d="M12 16.95h.01"/></svg>',
      'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 16l4-4-4-4M19 12H9"/></svg>',
      default => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 4h16v16H4z"/></svg>',
    };
  }
}

function render_admin_tabs(string $active = ''): void
{
  $candidateTab = ['key' => 'candidate', 'href' => '/dashboard.php', 'label' => 'Espace candidat'];
  $helpTab = ['key' => 'help', 'href' => '/admin/help.php', 'label' => 'Documentation'];
  $logoutTab = ['key' => 'logout', 'href' => '/logout.php', 'label' => 'D&eacute;connexion', 'extra_class' => 'admin-logout-btn'];
  $tabs = [
    ['key' => 'sessions', 'href' => '/admin/index.php', 'label' => 'Sessions'],
    ['key' => 'certifications', 'href' => '/admin/certifications.php', 'label' => 'Certifications'],
    ['key' => 'users', 'href' => '/admin/users.php', 'label' => 'Utilisateurs'],
    ['key' => 'packages', 'href' => '/admin/packages.php', 'label' => 'Packs'],
    ['key' => 'questions', 'href' => '/admin/questions.php', 'label' => 'Questions'],
    ['key' => 'performance', 'href' => '/admin/question_performance.php', 'label' => 'Analyse'],
  ];

  echo '<nav class="admin-tabs" aria-label="Navigation administration">';
  echo '<a class="btn ghost admin-tab admin-tab-candidate" href="' . $candidateTab['href'] . '">';
  echo '<span class="admin-tab-label">' . $candidateTab['label'] . '</span>';
  echo '</a>';
  echo '<div class="admin-tabs-main">';
  foreach ($tabs as $tab) {
    $isActive = ($active !== '' && $active === $tab['key']);
    $classes = 'btn ghost admin-tab';
    if (!empty($tab['extra_class'])) {
      $classes .= ' ' . $tab['extra_class'];
    }
    if ($isActive) {
      $classes .= ' is-active';
    }
    echo '<a class="' . $classes . '" href="' . $tab['href'] . '">';
    echo '<span class="admin-tab-icon">' . admin_tab_icon_svg((string)$tab['key']) . '</span>';
    echo '<span class="admin-tab-label">' . $tab['label'] . '</span>';
    echo '</a>';
  }
  echo '</div>';
  echo '<div class="admin-tabs-bottom">';
  $helpClasses = 'btn ghost admin-tab';
  if ($active === $helpTab['key']) {
    $helpClasses .= ' is-active';
  }
  echo '<a class="' . $helpClasses . '" href="' . $helpTab['href'] . '">';
  echo '<span class="admin-tab-icon">' . admin_tab_icon_svg($helpTab['key']) . '</span>';
  echo '<span class="admin-tab-label">' . $helpTab['label'] . '</span>';
  echo '</a>';
  echo '<a class="btn ghost admin-tab ' . $logoutTab['extra_class'] . '" href="' . $logoutTab['href'] . '">';
  echo '<span class="admin-tab-icon">' . admin_tab_icon_svg($logoutTab['key']) . '</span>';
  echo '<span class="admin-tab-label">' . $logoutTab['label'] . '</span>';
  echo '</a>';
  echo '</div>';
  echo '</nav>';
  echo "<script>document.body.classList.add('admin-with-sidebar');</script>";
}
