<?php
session_start();
require_once __DIR__ . '/../config.php';

function require_admin() {
  if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header("Location: /admin/login.php");
    exit;
  }
}
