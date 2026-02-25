<?php
if (!function_exists('admin_tab_icon_svg')) {
  function admin_tab_icon_svg(string $key): string
  {
    return match ($key) {
      'candidate' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 16l4-4-4-4M19 12H9"/></svg>',
      'sessions' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 4h12M8 11h8M8 15h5"/></svg>',
      'certifications' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3.2c1.2 0 2.1 1 3.1 1.3 1 .3 2.3 0 3.1.6.8.6 1 1.9 1.7 2.8.7.8 1.9 1.5 2.2 2.5.3 1-.4 2.2-.4 3.2s.7 2.2.4 3.2c-.3 1-1.5 1.7-2.2 2.5-.7.9-.9 2.2-1.7 2.8-.8.6-2.1.3-3.1.6-1 .3-1.9 1.3-3.1 1.3s-2.1-1-3.1-1.3c-1-.3-2.3 0-3.1-.6-.8-.6-1-1.9-1.7-2.8-.7-.8-1.9-1.5-2.2-2.5-.3-1 .4-2.2.4-3.2s-.7-2.2-.4-3.2c.3-1 1.5-1.7 2.2-2.5.7-.9.9-2.2 1.7-2.8.8-.6 2.1-.3 3.1-.6 1-.3 1.9-1.3 3.1-1.3Z"/><circle cx="12" cy="12" r="4.5"/><path d="m10.1 12.1 1.6 1.6 2.9-2.9M8 18.6l-2.9 4.6 3-.6 1.4 2.8 2.2-4.1M16 18.6l2.9 4.6-3-.6-1.4 2.8-2.2-4.1"/></svg>',
      'users' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm10 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3ZM7 13c-3.3 0-6 1.7-6 3.8V19h12v-2.2C13 14.7 10.3 13 7 13Zm10 0c-1.1 0-2.2.2-3.1.6A4.8 4.8 0 0 1 16 16.8V19h7v-2.2c0-2.1-2.7-3.8-6-3.8Z"/></svg>',
      'packages' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7.5 12 3l9 4.5-9 4.5-9-4.5Zm0 4.5 9 4.5 9-4.5M3 16.5 12 21l9-4.5"/></svg>',
      'questions' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 18h.01M9.1 9a2.9 2.9 0 1 1 4.8 2.2c-.9.7-1.4 1.3-1.4 2.3v.5M4 4h16v16H4z"/></svg>',
      'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 16l4-4-4-4M19 12H9"/></svg>',
      default => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 4h16v16H4z"/></svg>',
    };
  }
}

function render_admin_tabs(string $active = ''): void
{
  $candidateTab = ['key' => 'candidate', 'href' => '/dashboard.php', 'label' => 'Espace candidat'];
  $logoutTab = ['key' => 'logout', 'href' => '/logout.php', 'label' => 'D&eacute;connexion', 'extra_class' => 'admin-logout-btn'];
  $tabs = [
    ['key' => 'sessions', 'href' => '/admin/index.php', 'label' => 'Sessions'],
    ['key' => 'certifications', 'href' => '/admin/certifications.php', 'label' => 'Certifications'],
    ['key' => 'users', 'href' => '/admin/users.php', 'label' => 'Utilisateurs'],
    ['key' => 'packages', 'href' => '/admin/packages.php', 'label' => 'Packs'],
    ['key' => 'questions', 'href' => '/admin/questions.php', 'label' => 'Questions'],
  ];

  echo '<nav class="admin-tabs" aria-label="Navigation administration">';
  echo '<a class="btn ghost admin-tab admin-tab-candidate" href="' . $candidateTab['href'] . '">';
  echo '<span class="admin-tab-icon">' . admin_tab_icon_svg($candidateTab['key']) . '</span>';
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
  echo '<a class="btn ghost admin-tab ' . $logoutTab['extra_class'] . '" href="' . $logoutTab['href'] . '">';
  echo '<span class="admin-tab-icon">' . admin_tab_icon_svg($logoutTab['key']) . '</span>';
  echo '<span class="admin-tab-label">' . $logoutTab['label'] . '</span>';
  echo '</a>';
  echo '</nav>';
  echo "<script>document.body.classList.add('admin-with-sidebar');</script>";
}
