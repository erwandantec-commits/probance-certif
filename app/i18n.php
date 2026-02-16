<?php

function normalize_lang(?string $lang): string {
  $lang = strtolower(trim((string)$lang));
  if ($lang === 'ja') {
    $lang = 'jp';
  }
  if (!in_array($lang, ['fr', 'en', 'jp'], true)) {
    $lang = 'fr';
  }
  return $lang;
}

function get_lang(): string {
  $lang = normalize_lang($_GET['lang'] ?? $_POST['lang'] ?? $_COOKIE['lang'] ?? 'fr');
  if (!headers_sent()) {
    setcookie('lang', $lang, time() + 3600 * 24 * 365, '/');
  }
  return $lang;
}

function html_lang_code(string $lang): string {
  return $lang === 'jp' ? 'ja' : $lang;
}

function t(string $key, array $vars = [], ?string $langOverride = null): string {
  $lang = normalize_lang($langOverride ?? get_lang());

  $dict = [
    'fr' => [
      'lang.fr' => 'FR',
      'lang.en' => 'EN',
      'lang.jp' => 'JP',

      'login.title' => 'Connexion',
      'login.subtitle' => 'Accede a ton espace certifications.',
      'login.email' => 'Email',
      'login.password' => 'Mot de passe',
      'login.submit' => 'Se connecter',
      'login.forgot' => 'Mot de passe oublie ?',
      'login.no_account' => 'Pas de compte ?',
      'login.create_account' => 'Creer un compte',
      'login.bad_credentials' => 'Email ou mot de passe incorrect.',

      'dash.title' => 'Mon espace',
      'dash.hello' => 'Salut {{name}}',
      'dash.subtitle' => 'Ton espace certifications',
      'dash.logout' => 'Déconnexion',
      'dash.admin' => 'Admin',
      'dash.attempts' => 'Tentatives',
      'dash.completed' => 'Terminées',
      'dash.passed' => 'Réussies',
      'dash.start_cert' => 'Passer une certification',
      'dash.cert' => 'Certification',
      'dash.start' => 'Démarrer',
      'dash.last_sessions' => 'Dernières sessions',
      'dash.none' => 'Aucune session pour le moment.',
      'dash.col.cert' => 'Certification',
      'dash.col.started' => 'Démarrée',
      'dash.col.status' => 'Statut',
      'dash.col.score' => 'Score',
      'dash.col.result' => 'Résultat',
      'dash.resume' => 'Reprendre',
      'dash.view' => 'Voir',
      'dash.status.terminated' => 'Terminé',
      'dash.status.active' => 'En cours',
      'dash.status.expired' => 'Expiré',
      'dash.result.passed' => 'Réussi',
      'dash.result.failed' => 'Échoué',
      'dash.na' => '-',

      'exam.title' => 'Question {{p}} / {{total}}',
      'exam.skip' => 'Ne pas repondre',
      'exam.prev' => 'Retour',
      'exam.next' => 'Suivant',
      'exam.finish' => 'Terminer',
      'exam.score_hint' => 'Score calcule a la fin. Mauvaise reponse = -1.',
      'exam.timer_prefix' => 'Temps',
      'exam.missing_sid' => 'Session manquante.',
      'exam.session_not_found' => 'Session introuvable.',
      'exam.question_not_found' => 'Question introuvable.',
      'exam.min' => 'm',
      'exam.sec' => 's',

      'result.title' => 'Resultat',
      'result.status_label' => 'Statut',
      'result.started' => 'Debut',
      'result.ended' => 'Fin',
      'result.score' => 'Score',
      'result.threshold' => 'seuil {{value}}%',
      'result.candidate_space' => 'Espace candidat',
      'result.admin_view' => "Voir dans l'admin",
      'result.admin' => 'Admin',
      'result.resume' => 'Reprendre',
      'result.back' => 'Retour',
      'result.in_progress' => 'Session en cours...',
      'result.expired_message' => 'La session a expire (temps depasse).',
      'result.answers_admin_only' => 'Les reponses sont visibles uniquement cote admin.',
      'result.badge.passed' => 'Reussi',
      'result.badge.failed' => 'Echec',
      'result.badge.expired' => 'Expiree',
      'result.badge.active' => 'En cours',

      'start.err.invalid_package' => 'Package invalide.',
      'start.err.package_not_found' => 'Package introuvable.',
      'start.err.not_enough_tagged' => 'Pas assez de questions taguees pour generer cette certification.',
      'start.err.not_enough_package' => 'Pas assez de questions pour ce package.',
      'start.err.create_failed' => 'Erreur creation session.',
    ],
    'en' => [
      'lang.fr' => 'FR',
      'lang.en' => 'EN',
      'lang.jp' => 'JP',

      'login.title' => 'Login',
      'login.subtitle' => 'Access your certification space.',
      'login.email' => 'Email',
      'login.password' => 'Password',
      'login.submit' => 'Sign in',
      'login.forgot' => 'Forgot password?',
      'login.no_account' => 'No account?',
      'login.create_account' => 'Create an account',
      'login.bad_credentials' => 'Invalid email or password.',

      'dash.title' => 'My Space',
      'dash.hello' => 'Hi {{name}}',
      'dash.subtitle' => 'Your certification space',
      'dash.logout' => 'Logout',
      'dash.admin' => 'Admin',
      'dash.attempts' => 'Attempts',
      'dash.completed' => 'Completed',
      'dash.passed' => 'Passed',
      'dash.start_cert' => 'Start a certification',
      'dash.cert' => 'Certification',
      'dash.start' => 'Start',
      'dash.last_sessions' => 'Latest sessions',
      'dash.none' => 'No session yet.',
      'dash.col.cert' => 'Certification',
      'dash.col.started' => 'Started',
      'dash.col.status' => 'Status',
      'dash.col.score' => 'Score',
      'dash.col.result' => 'Result',
      'dash.resume' => 'Resume',
      'dash.view' => 'View',
      'dash.status.terminated' => 'Completed',
      'dash.status.active' => 'In progress',
      'dash.status.expired' => 'Expired',
      'dash.result.passed' => 'Passed',
      'dash.result.failed' => 'Failed',
      'dash.na' => '-',

      'exam.title' => 'Question {{p}} / {{total}}',
      'exam.skip' => 'Skip',
      'exam.prev' => 'Back',
      'exam.next' => 'Next',
      'exam.finish' => 'Finish',
      'exam.score_hint' => 'Score is calculated at the end. Wrong answer = -1.',
      'exam.timer_prefix' => 'Time',
      'exam.missing_sid' => 'Missing session.',
      'exam.session_not_found' => 'Session not found.',
      'exam.question_not_found' => 'Question not found.',
      'exam.min' => 'm',
      'exam.sec' => 's',

      'result.title' => 'Result',
      'result.status_label' => 'Status',
      'result.started' => 'Start',
      'result.ended' => 'End',
      'result.score' => 'Score',
      'result.threshold' => 'threshold {{value}}%',
      'result.candidate_space' => 'Candidate area',
      'result.admin_view' => 'View in admin',
      'result.admin' => 'Admin',
      'result.resume' => 'Resume',
      'result.back' => 'Back',
      'result.in_progress' => 'Session in progress...',
      'result.expired_message' => 'The session has expired (time limit reached).',
      'result.answers_admin_only' => 'Answers are visible only in admin.',
      'result.badge.passed' => 'Passed',
      'result.badge.failed' => 'Failed',
      'result.badge.expired' => 'Expired',
      'result.badge.active' => 'In progress',

      'start.err.invalid_package' => 'Invalid package.',
      'start.err.package_not_found' => 'Package not found.',
      'start.err.not_enough_tagged' => 'Not enough tagged questions to build this certification.',
      'start.err.not_enough_package' => 'Not enough questions for this package.',
      'start.err.create_failed' => 'Session creation failed.',
    ],
    'jp' => [
      'lang.fr' => 'FR',
      'lang.en' => 'EN',
      'lang.jp' => 'JP',

      'login.title' => 'ログイン',
      'login.subtitle' => '認定スペースにアクセスします。',
      'login.email' => 'メール',
      'login.password' => 'パスワード',
      'login.submit' => 'ログイン',
      'login.forgot' => 'パスワードをお忘れですか？',
      'login.no_account' => 'アカウントがありませんか？',
      'login.create_account' => 'アカウント作成',
      'login.bad_credentials' => 'メールまたはパスワードが正しくありません。',

      'dash.title' => 'マイスペース',
      'dash.hello' => 'こんにちは {{name}}',
      'dash.subtitle' => '認定スペース',
      'dash.logout' => 'ログアウト',
      'dash.admin' => '管理',
      'dash.attempts' => '受験回数',
      'dash.completed' => '完了',
      'dash.passed' => '合格',
      'dash.start_cert' => '認定を開始',
      'dash.cert' => '認定',
      'dash.start' => '開始',
      'dash.last_sessions' => '最近のセッション',
      'dash.none' => 'セッションはありません。',
      'dash.col.cert' => '認定',
      'dash.col.started' => '開始',
      'dash.col.status' => '状態',
      'dash.col.score' => 'スコア',
      'dash.col.result' => '結果',
      'dash.resume' => '再開',
      'dash.view' => '表示',
      'dash.status.terminated' => '完了',
      'dash.status.active' => '進行中',
      'dash.status.expired' => '期限切れ',
      'dash.result.passed' => '合格',
      'dash.result.failed' => '不合格',
      'dash.na' => '-',

      'exam.title' => '問題 {{p}} / {{total}}',
      'exam.skip' => '回答しない',
      'exam.prev' => '戻る',
      'exam.next' => '次へ',
      'exam.finish' => '終了',
      'exam.score_hint' => 'スコアは最後に計算されます。不正解 = -1。',
      'exam.timer_prefix' => '残り時間',
      'exam.missing_sid' => 'セッションがありません。',
      'exam.session_not_found' => 'セッションが見つかりません。',
      'exam.question_not_found' => '問題が見つかりません。',
      'exam.min' => '分',
      'exam.sec' => '秒',

      'result.title' => '結果',
      'result.status_label' => '状態',
      'result.started' => '開始',
      'result.ended' => '終了',
      'result.score' => 'スコア',
      'result.threshold' => '合格基準 {{value}}%',
      'result.candidate_space' => '受験者ページ',
      'result.admin_view' => '管理で表示',
      'result.admin' => '管理',
      'result.resume' => '再開',
      'result.back' => '戻る',
      'result.in_progress' => 'セッション進行中...',
      'result.expired_message' => 'セッションの制限時間が終了しました。',
      'result.answers_admin_only' => '回答は管理画面でのみ表示されます。',
      'result.badge.passed' => '合格',
      'result.badge.failed' => '不合格',
      'result.badge.expired' => '期限切れ',
      'result.badge.active' => '進行中',

      'start.err.invalid_package' => '無効なパッケージです。',
      'start.err.package_not_found' => 'パッケージが見つかりません。',
      'start.err.not_enough_tagged' => 'この認定を生成するためのタグ付き問題が不足しています。',
      'start.err.not_enough_package' => 'このパッケージの問題数が不足しています。',
      'start.err.create_failed' => 'セッション作成に失敗しました。',
    ],
  ];

  $text = $dict[$lang][$key] ?? $dict['fr'][$key] ?? $key;
  foreach ($vars as $k => $v) {
    $text = str_replace('{{' . $k . '}}', (string)$v, $text);
  }
  return $text;
}

function localize_text(string $raw, string $lang): string {
  $lang = normalize_lang($lang);
  $value = trim($raw);

  if ($value === '') {
    return '';
  }

  // JSON payload format: {"fr":"...","en":"...","jp":"..."}
  if ($value[0] === '{') {
    $arr = json_decode($value, true);
    if (is_array($arr)) {
      $pick = $arr[$lang] ?? $arr['fr'] ?? reset($arr);
      if (is_string($pick) && $pick !== '') {
        return $pick;
      }
    }
  }

  // Inline format: FR || EN || JP
  if (strpos($value, '||') !== false) {
    $parts = array_map('trim', explode('||', $value));
    if (count($parts) >= 3) {
      $idx = $lang === 'fr' ? 0 : ($lang === 'en' ? 1 : 2);
      if (!empty($parts[$idx])) {
        return $parts[$idx];
      }
      return $parts[0];
    }
  }

  return $value;
}
