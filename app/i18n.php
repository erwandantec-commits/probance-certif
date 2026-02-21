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
      'dash.cert' => 'Select a certification',
      'dash.cert_hint' => 'Selectionne une certification, puis choisis le mode.',
      'dash.no_active_packages' => 'Aucune certification active pour le moment.',
      'dash.session_type' => 'Choose the mode',
      'dash.session_type.exam' => 'Certification',
      'dash.session_type.training' => 'Test',
      'dash.session_type.exam_hint' => 'Mode officiel, resultat enregistre.',
      'dash.session_type.training_hint' => 'Mode entrainement, pour pratiquer.',
      'dash.session_type_hint' => 'Choisis le mode selon ton objectif: valider ta certification ou t entrainer.',
      'dash.start' => 'Démarrer',
      'dash.start_new' => 'Démarrer une nouvelle session',
      'dash.continue_current' => 'Reprendre la session en cours',
      'dash.confirm_overwrite_session' => 'Cette action va ecraser la session en cours pour cette certification. Voulez-vous continuer ?',
      'dash.start_new_exam' => 'Démarrer une nouvelle certification',
      'dash.start_new_training' => 'Démarrer un nouveau test',
      'dash.continue_current_exam' => 'Reprendre la certification en cours',
      'dash.continue_current_training' => 'Reprendre le test en cours',
      'dash.confirm_overwrite_session_exam' => 'Cette action va ecraser la certification en cours. Voulez-vous continuer ?',
      'dash.confirm_overwrite_session_training' => 'Cette action va ecraser le test en cours. Voulez-vous continuer ?',
      'dash.last_sessions' => 'Dernières sessions',
      'dash.none' => 'Aucune session pour le moment.',
      'dash.col.cert' => 'Certification',
      'dash.col.type' => 'Type',
      'dash.col.started' => 'Démarrée',
      'dash.col.status' => 'Statut',
      'dash.col.score' => 'Score',
      'dash.col.result' => 'Résultat',
      'dash.col.action' => 'Action',
      'dash.resume' => 'Reprendre',
      'dash.view' => 'Voir',
      'dash.delete_active' => 'Supprimer',
      'dash.delete_confirm' => 'Supprimer cette session en cours ?',
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
      'exam.validate' => 'Valider',
      'exam.pause' => 'Mettre en pause',
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
      'dash.cert' => 'Select a certification',
      'dash.cert_hint' => 'Select a certification, then choose the mode.',
      'dash.no_active_packages' => 'No active certification available right now.',
      'dash.session_type' => 'Choose the mode',
      'dash.session_type.exam' => 'Certification',
      'dash.session_type.training' => 'Training',
      'dash.session_type.exam_hint' => 'Official mode, result is recorded.',
      'dash.session_type.training_hint' => 'Practice mode for learning.',
      'dash.session_type_hint' => 'Choose the mode based on your goal: certify or train.',
      'dash.start' => 'Start',
      'dash.start_new' => 'Start a new session',
      'dash.continue_current' => 'Resume current session',
      'dash.confirm_overwrite_session' => 'This will overwrite the current session for this certification. Do you want to continue?',
      'dash.start_new_exam' => 'Start a new certification',
      'dash.start_new_training' => 'Start a new test',
      'dash.continue_current_exam' => 'Resume current certification',
      'dash.continue_current_training' => 'Resume current test',
      'dash.confirm_overwrite_session_exam' => 'This will overwrite the current certification. Do you want to continue?',
      'dash.confirm_overwrite_session_training' => 'This will overwrite the current test. Do you want to continue?',
      'dash.last_sessions' => 'Latest sessions',
      'dash.none' => 'No session yet.',
      'dash.col.cert' => 'Certification',
      'dash.col.type' => 'Type',
      'dash.col.started' => 'Started',
      'dash.col.status' => 'Status',
      'dash.col.score' => 'Score',
      'dash.col.result' => 'Result',
      'dash.col.action' => 'Action',
      'dash.resume' => 'Resume',
      'dash.view' => 'View',
      'dash.delete_active' => 'Delete',
      'dash.delete_confirm' => 'Delete this active session?',
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
      'exam.validate' => 'Validate',
      'exam.pause' => 'Pause',
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
      'dash.cert' => 'Select a certification',
      'dash.cert_hint' => '認定を選択してからモードを選択してください。',
      'dash.no_active_packages' => '現在、利用可能な認定はありません。',
      'dash.session_type' => 'Choose the mode',
      'dash.session_type.exam' => '認定',
      'dash.session_type.training' => 'トレーニング',
      'dash.session_type.exam_hint' => '公式モード（結果は保存されます）。',
      'dash.session_type.training_hint' => '学習用トレーニングモード。',
      'dash.session_type_hint' => '目的に応じてモードを選択してください。',
      'dash.start' => '開始',
      'dash.start_new' => '新しいセッションを開始',
      'dash.continue_current' => '進行中のセッションを再開',
      'dash.confirm_overwrite_session' => 'この操作を行うと、この認定の進行中セッションは上書きされます。続行しますか？',
      'dash.start_new_exam' => '新しい認定を開始',
      'dash.start_new_training' => '新しいテストを開始',
      'dash.continue_current_exam' => '進行中の認定を再開',
      'dash.continue_current_training' => '進行中のテストを再開',
      'dash.confirm_overwrite_session_exam' => 'この操作を行うと、進行中の認定は上書きされます。続行しますか？',
      'dash.confirm_overwrite_session_training' => 'この操作を行うと、進行中のテストは上書きされます。続行しますか？',
      'dash.last_sessions' => '最近のセッション',
      'dash.none' => 'セッションはありません。',
      'dash.col.cert' => '認定',
      'dash.col.type' => '種別',
      'dash.col.started' => '開始',
      'dash.col.status' => '状態',
      'dash.col.score' => 'スコア',
      'dash.col.result' => '結果',
      'dash.col.action' => '操作',
      'dash.resume' => '再開',
      'dash.view' => '表示',
      'dash.delete_active' => '削除',
      'dash.delete_confirm' => 'この進行中セッションを削除しますか？',
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
      'exam.validate' => '確定',
      'exam.pause' => '一時停止',
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
