<?php
require_once __DIR__ . '/_auth.php';
require_admin_area();

header('Location: /admin/users.php');
exit;
