<?php

function render_admin_tabs(string $active = ''): void
{
  $candidateTab = ['key' => 'candidate', 'href' => '/dashboard.php', 'label' => 'Espace candidat'];
  $logoutTab = ['key' => 'logout', 'href' => '/logout.php', 'label' => 'D&eacute;connexion', 'extra_class' => 'admin-logout-btn'];
  $tabs = [
    ['key' => 'sessions', 'href' => '/admin/index.php', 'label' => 'Sessions'],
    ['key' => 'certifications', 'href' => '/admin/certifications.php', 'label' => 'Certifications'],
    ['key' => 'packages', 'href' => '/admin/packages.php', 'label' => 'Packages'],
    ['key' => 'questions', 'href' => '/admin/questions.php', 'label' => 'Questions'],
  ];

  echo '<nav class="admin-tabs" aria-label="Navigation administration">';
  echo '<a class="btn ghost admin-tab admin-tab-candidate" href="' . $candidateTab['href'] . '">' . $candidateTab['label'] . '</a>';
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
    echo '<a class="' . $classes . '" href="' . $tab['href'] . '">' . $tab['label'] . '</a>';
  }
  echo '</div>';
  echo '<a class="btn ghost admin-tab ' . $logoutTab['extra_class'] . '" href="' . $logoutTab['href'] . '">' . $logoutTab['label'] . '</a>';
  echo '</nav>';
}
