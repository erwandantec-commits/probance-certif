<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

$lang = get_lang();
$target = current_user() ? '/dashboard.php' : '/login.php';

header('Location: ' . $target . '?lang=' . urlencode($lang));
exit;
