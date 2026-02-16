<?php
// app/i18n.php

function get_lang(): string {
  $lang = strtolower($_GET['lang'] ?? $_COOKIE['lang'] ?? 'fr');
  $allowed = ['fr', 'en', 'ja'];
  if (!in_array($lang, $allowed, true)) $lang = 'fr';

  // setcookie seulement si possible
  if (!headers_sent()) {
    setcookie('lang', $lang, time() + 3600*24*365, '/');
  }

  return $lang;
}

function t(string $key, array $vars = []): string {
  static $dict = null;
  static $lang = null;

  if ($dict === null) {
    $lang = get_lang();

    $dict = [
      'fr' => [
        'admin.sessions' => 'Admin — Sessions (démo)',
        'filters.type' => 'Type',
        'filters.status' => 'Statut',
        'filters.result' => 'Résultat',
        'filters.email' => 'Email…',
        'btn.filter' => 'Filtrer',
        'btn.export' => 'Exporter CSV',
        'btn.reset' => 'Reset',
        'badge.done' => 'Terminé',
        'badge.expired' => 'Expiré',
        'badge.active' => 'Actif',
        'badge.passed' => 'Réussi',
        'badge.failed' => 'Échoué',
        'th.started_at' => 'Date début',
        'th.status' => 'Statut',
        'th.result' => 'Résultat',
      ],
      'en' => [
        'admin.sessions' => 'Admin — Sessions (demo)',
        'filters.type' => 'Type',
        'filters.status' => 'Status',
        'filters.result' => 'Result',
        'filters.email' => 'Email…',
        'btn.filter' => 'Filter',
        'btn.export' => 'Export CSV',
        'btn.reset' => 'Reset',
        'badge.done' => 'Completed',
        'badge.expired' => 'Expired',
        'badge.active' => 'Active',
        'badge.passed' => 'Passed',
        'badge.failed' => 'Failed',
        'th.started_at' => 'Start date',
        'th.status' => 'Status',
        'th.result' => 'Result',
      ],
      'ja' => [
        'admin.sessions' => '管理 — セッション（デモ）',
        'filters.type' => '種別',
        'filters.status' => 'ステータス',
        'filters.result' => '結果',
        'filters.email' => 'メール…',
        'btn.filter' => '絞り込み',
        'btn.export' => 'CSV出力',
        'btn.reset' => 'リセット',
        'badge.done' => '完了',
        'badge.expired' => '期限切れ',
        'badge.active' => '進行中',
        'badge.passed' => '合格',
        'badge.failed' => '不合格',
        'th.started_at' => '開始日',
        'th.status' => 'ステータス',
        'th.result' => '結果',
      ],
    ];
  }

  $text = $dict[$lang][$key] ?? $dict['fr'][$key] ?? $key;

  foreach ($vars as $k => $v) {
    $text = str_replace('{{'.$k.'}}', (string)$v, $text);
  }
  return $text;
}
