<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

function help_fallback_markdown(string $lang): string {
  return match ($lang) {
    'en' => <<<'MD'
# User guide

This document explains how to use the Probance certification tool day to day, without going into technical detail.

## Quick summary

To use the tool simply:

1. sign in
2. choose a pack
3. start a session
4. answer the questions before time runs out
5. check your result
6. track your certifications from the dashboard

## Signing in

To access the tool:

1. open the sign-in page
2. enter your email and password
3. submit to reach your dashboard

If you don't have an account yet, use the sign-up page.

If you forgot your password, use the reset function.

## Dashboard

The dashboard is the user's main page.

You will find:

- the certifications available
- buttons to start a session
- your recent history
- your previous results
- the status of your certifications

From this page, you can choose a pack then start a session.

Packs and certifications are grouped by **program**. If you have access to more than one program, a switcher appears at the top of the dashboard: switch programs to see the packs and certifications that belong to it. If you only have access to one program, this switcher does not appear.

## The two session modes

The tool generally offers two modes:

### Exam mode

`Exam` mode is the official mode: it is the one that counts towards your certification.

In this mode:

- you answer questions without immediate feedback
- the final score is calculated at the end
- the result determines whether the certification is obtained or not
- an **Abandon** button is available: if you use it, no score is calculated and the session is marked **Abandoned** — it never counts towards your certification, even if you had already answered many questions correctly

For a question with several correct answers, the point is only earned if you select exactly all the correct answers and none of the wrong ones. Selecting one too many, or missing a correct one, makes the question worth 0, even if the rest was correct.

### Training mode

`Training` mode lets you practise before taking the Exam.

In this mode:

- you can validate question by question
- the tool immediately shows whether your answer is correct
- you can learn from your mistakes more easily
- you can pause the session (**Pause** button): the timer stops and you can resume later from the dashboard, on the same question, with the remaining time kept
- this mode never counts towards your certification, whatever your score

## Starting a session

To start a session:

1. go to the dashboard
2. choose the pack you want
3. choose the session type
4. click the start button

The tool then opens the question session.

In some cases, a certification session may be refused:

- if a valid certification already exists for this pack
- if a waiting period is in progress after a failure
- if the pack is not ready yet (not enough questions configured) — contact an administrator in that case

If so, an explanatory message is shown.

## Answering questions

During a session:

- one question is shown at a time
- a timer shows the time remaining
- depending on the question, one or several answers may be correct

Tips:

- read the wording carefully before answering
- check whether several choices are expected
- keep an eye on the remaining time

Depending on the session mode:

- in `Exam` mode, you proceed without immediate feedback
- in `Training` mode, you can see feedback before moving on

## End of session

A session can end in several ways:

- you reach the last question and submit it
- you choose to finish the session
- the allotted time runs out

Once finished, you are redirected to the result page.

## Understanding the result

The result page generally shows:

- the score obtained
- the session status
- the pack concerned
- the date of the attempt
- the result obtained: passed, failed, or expired

If the session corresponds to a certification:

- a pass may validate the certification
- a failure means the threshold was not reached
- an expiry means the time was exceeded

## Reviewing your answers

In training mode, the tool lets you review:

- the questions asked
- your answers
- the correct answers
- the status of each question

This feature is useful to progress and understand your mistakes.

## History and certifications

From your space, you can view:

- your session history
- your previous scores
- the status of your certifications

A certification can appear as:

- valid
- expiring soon
- expired
- not obtained

This lets you quickly know whether a new attempt is needed.

## Changing language

The tool offers several display languages via the selector (flag) at the top of every page.

- as soon as you choose a language, it is remembered and stays your default language on every page, even on your next visits
- you can change language at any time, including during an ongoing Exam or Training session: the page reloads on the same question, only the displayed text changes
- changing language mid-session does not erase answers you already gave and does not restart the session

## If something goes wrong

If you run into an issue:

- first check your email and password
- reload the page if a display seems stuck
- check that your session has not expired
- contact an administrator if you think an access or certification is wrongly blocked

## Frequently asked questions

### I cannot start a certification, why?

Three possible reasons:

- you already have a still-valid certification for this pack
- a waiting period is active after a recent failure
- the pack is not ready yet on the admin side (not enough questions configured) — you'll be asked to contact an administrator

A message on the dashboard tells you which one applies.

### What is the difference between Training and Exam?

`Training` mode is for practice: you validate each question one by one and immediately see whether your answer is correct. This mode never counts towards your certification, whatever your score.

`Exam` mode is the official assessment: you answer all questions with no feedback during the attempt, the score is only calculated at the end, and this result determines whether you obtain or keep your certification. Abandoning during an Exam calculates no score and never counts, even with correct answers already given.

### Is my score correct if I partially selected the right answers?

Yes, the rule applies. A question is only worth 1 point if you select exactly all the correct answers and none of the wrong ones. If you tick one too many or miss a correct one, the question is worth 0 — even if you were largely right.

### What happens if I run out of time?

The session is automatically closed and considered expired. The score is calculated on the questions you had answered so far — you may pass or fail depending on that score against the pack's threshold.

### What happens if I abandon?

If you click the **Abandon** button (available in Exam mode), the session is immediately closed with no score calculated at all. The status shown is **Abandoned** and it never counts towards your certification, regardless of how many correct answers you had already given.

### Can I resume a session later?

It depends on the mode:

- in `Training` mode, yes: the **Pause** button stops the timer, and you can resume later from the dashboard, on the same question, with the remaining time kept
- in `Exam` mode, no: the session must be completed in one go. If you close the page without pausing (not available in Exam) or abandoning, the session stays active until the allotted time runs out — the score will then be calculated on the questions already answered. If you click Abandon, the session is closed immediately with no score.
MD,
    'es' => <<<'MD'
# Guia del usuario

Este documento explica como usar la herramienta de certificacion Probance en el dia a dia, sin entrar en detalles tecnicos.

## Resumen rapido

Para usar la herramienta de forma sencilla:

1. inicia sesion
2. elige un pack
3. lanza una sesion
4. responde a las preguntas antes de que se acabe el tiempo
5. consulta tu resultado
6. sigue tus certificaciones desde el panel

## Iniciar sesion

Para acceder a la herramienta:

1. abre la pagina de inicio de sesion
2. introduce tu email y tu contrasena
3. valida para llegar a tu panel

Si aun no tienes cuenta, usa la pagina de registro.

Si has olvidado tu contrasena, usa la funcion de restablecimiento.

## Panel principal

El panel principal es la pagina principal del usuario.

Aqui encontraras:

- las certificaciones disponibles
- los botones para iniciar una sesion
- tu historial reciente
- tus resultados anteriores
- el estado de tus certificaciones

Desde esta pagina, puedes elegir un pack y luego iniciar una sesion.

Los packs y certificaciones estan agrupados por **programa**. Si tienes acceso a varios programas, aparece un selector en la parte superior del panel: cambia de programa para ver los packs y certificaciones que le corresponden. Si solo tienes acceso a un programa, este selector no aparece.

## Los dos tipos de sesion

La herramienta ofrece en general dos modos:

### Modo Examen

El modo `Examen` es el modo oficial: es el que cuenta para tu certificacion.

En este modo:

- respondes a las preguntas sin correccion inmediata
- la puntuacion final se calcula al terminar
- el resultado determina si obtienes la certificacion o no
- hay disponible un boton **Abandonar**: si lo usas, no se calcula ninguna puntuacion y la sesion queda marcada como **Abandonada** — nunca cuenta para tu certificacion, aunque ya hubieras respondido correctamente a muchas preguntas

En una pregunta con varias respuestas correctas, el punto solo se obtiene si seleccionas exactamente todas las respuestas correctas y ninguna incorrecta. Marcar una de mas, u olvidar una correcta, hace que la pregunta valga 0, aunque el resto fuera correcto.

### Modo Entrenamiento

El modo `Entrenamiento` sirve para practicar antes de hacer el Examen.

En este modo:

- puedes validar pregunta por pregunta
- la herramienta muestra de inmediato si tu respuesta es correcta
- puedes aprender de tus errores mas facilmente
- puedes pausar la sesion (boton **Pausa**): el temporizador se detiene y puedes retomarla mas tarde desde el panel, en la misma pregunta, con el tiempo restante conservado
- este modo nunca cuenta para tu certificacion, sea cual sea tu puntuacion

## Iniciar una sesion

Para lanzar una sesion:

1. ve al panel principal
2. elige el pack deseado
3. elige el tipo de sesion
4. haz clic en el boton de inicio

La herramienta abre entonces la sesion de preguntas.

En algunos casos, una sesion de certificacion puede ser rechazada:

- si ya existe una certificacion valida para ese pack
- si hay un periodo de espera en curso tras un fallo
- si el pack aun no esta listo (no hay suficientes preguntas configuradas) — en ese caso, contacta con un administrador

En ese caso, se muestra un mensaje explicativo.

## Responder a las preguntas

Durante una sesion:

- se muestra una pregunta a la vez
- un temporizador indica el tiempo restante
- segun la pregunta, una o varias respuestas pueden ser correctas

Consejos:

- lee bien el enunciado antes de responder
- comprueba si se esperan varias opciones
- vigila el tiempo restante

Segun el modo de sesion:

- en modo `Examen`, avanzas sin correccion inmediata
- en modo `Entrenamiento`, puedes ver una correccion antes de continuar

## Fin de la sesion

Una sesion puede terminar de varias formas:

- llegas a la ultima pregunta y la validas
- decides terminar la sesion
- se acaba el tiempo asignado

Una vez terminada, se te redirige a la pagina de resultado.

## Entender el resultado

La pagina de resultado suele mostrar:

- la puntuacion obtenida
- el estado de la sesion
- el pack correspondiente
- la fecha del intento
- el resultado obtenido: aprobado, suspenso o expirado

Si la sesion corresponde a una certificacion:

- un exito puede validar la certificacion
- un fallo significa que no se alcanzo el umbral
- una expiracion significa que se supero el tiempo

## Revisar tus respuestas

En modo entrenamiento, la herramienta te permite revisar:

- las preguntas planteadas
- tus respuestas
- las respuestas correctas
- el estado de cada pregunta

Esta funcion es util para progresar y entender tus errores.

## Historial y certificaciones

Desde tu espacio, puedes consultar:

- el historial de tus sesiones
- tus puntuaciones anteriores
- el estado de tus certificaciones

Una certificacion puede aparecer como:

- valida
- proxima a expirar
- expirada
- no obtenida

Esto te permite saber rapidamente si necesitas un nuevo intento.

## Cambiar de idioma

La herramienta ofrece varios idiomas de visualizacion mediante el selector (bandera) situado en la parte superior de cada pagina.

- en cuanto eliges un idioma, se memoriza y sigue siendo tu idioma por defecto en todas las paginas, incluso en tus proximas visitas
- puedes cambiar de idioma en cualquier momento, incluso durante una sesion de Examen o Entrenamiento en curso: la pagina se recarga en la misma pregunta, solo cambian los textos mostrados
- cambiar de idioma durante una sesion no borra las respuestas ya dadas ni reinicia la sesion

## Si tienes algun problema

Si tienes un problema:

- comprueba primero tu email y tu contrasena
- recarga la pagina si una pantalla parece bloqueada
- comprueba que tu sesion no haya expirado
- contacta con un administrador si crees que un acceso o una certificacion esta bloqueado por error

## Preguntas frecuentes

### No puedo iniciar una certificacion, ¿por que?

Tres razones posibles:

- ya tienes una certificacion aun valida para ese pack
- hay un periodo de espera activo tras un fallo reciente
- el pack aun no esta listo por parte de administracion (no hay suficientes preguntas configuradas) — se te pedira contactar con un administrador

Un mensaje en el panel te indica cual de ellas se aplica.

### ¿Que diferencia hay entre Entrenamiento y Examen?

El modo `Entrenamiento` sirve para practicar: validas cada pregunta una a una y ves de inmediato si tu respuesta es correcta. Este modo nunca cuenta para tu certificacion, sea cual sea tu puntuacion.

El modo `Examen` es la evaluacion oficial: respondes a todas las preguntas sin ninguna correccion durante el intento, la puntuacion solo se calcula al final, y ese resultado determina si obtienes o conservas tu certificacion. Abandonar durante un Examen no calcula ninguna puntuacion y nunca cuenta, aunque ya hubieras dado respuestas correctas.

### ¿Es correcto mi resultado si seleccione parcialmente las respuestas correctas?

Si, la regla se aplica. Una pregunta solo vale 1 punto si seleccionas exactamente todas las respuestas correctas y ninguna incorrecta. Si marcas una de mas o olvidas una correcta, la pregunta vale 0 — aunque hayas acertado en gran parte.

### ¿Que ocurre si se me acaba el tiempo?

La sesion se cierra automaticamente y se considera expirada. La puntuacion se calcula sobre las preguntas que habias respondido hasta ese momento — puedes aprobar o suspender segun esa puntuacion respecto al umbral del pack.

### ¿Que ocurre si abandono?

Si haces clic en el boton **Abandonar** (disponible en modo Examen), la sesion se cierra de inmediato sin calcular ninguna puntuacion. El estado mostrado es **Abandonada** y nunca cuenta para tu certificacion, sea cual sea el numero de respuestas correctas ya dadas.

### ¿Puedo retomar una sesion mas tarde?

Depende del modo:

- en modo `Entrenamiento`, si: el boton **Pausa** detiene el temporizador, y puedes retomarla mas tarde desde el panel, en la misma pregunta, con el tiempo restante conservado
- en modo `Examen`, no: la sesion debe completarse de una sola vez. Si cierras la pagina sin pausar (no disponible en Examen) ni abandonar, la sesion permanece activa hasta que se acabe el tiempo asignado — entonces se calculara la puntuacion sobre las preguntas ya respondidas. Si haces clic en Abandonar, la sesion se cierra de inmediato sin puntuacion.
MD,
    'jp' => <<<'MD'
# ユーザーガイド

このドキュメントでは、技術的な詳細に立ち入らずに、Probance認定ツールの日常的な使い方を説明します。

## クイックサマリー

ツールをシンプルに使うには:

1. ログインする
2. パックを選ぶ
3. セッションを開始する
4. 時間内に問題に回答する
5. 結果を確認する
6. ダッシュボードから認定を確認する

## ログイン

ツールにアクセスするには:

1. ログインページを開く
2. メールアドレスとパスワードを入力する
3. 送信してダッシュボードに移動する

まだアカウントがない場合は、登録ページを利用してください。

パスワードを忘れた場合は、再設定機能を利用してください。

## ダッシュボード

ダッシュボードはユーザーのメイン画面です。

ここには以下が表示されます:

- 利用可能な認定
- セッションを開始するボタン
- 最近の履歴
- これまでの結果
- 認定のステータス

このページからパックを選び、セッションを開始できます。

パックと認定は**プログラム**単位でグループ化されています。複数のプログラムにアクセスできる場合は、ダッシュボード上部に切り替えセレクターが表示されます。プログラムを切り替えると、そのプログラムに属するパックと認定が表示されます。アクセスできるプログラムが1つだけの場合、このセレクターは表示されません。

## 2つのセッションモード

ツールは基本的に2つのモードを提供します:

### 試験モード

`試験`モードは公式モードです。認定に反映されるのはこのモードです。

このモードでは:

- 即時フィードバックなしで問題に回答します
- 最終スコアは終了時に計算されます
- 結果によって認定を取得できるかどうかが決まります
- **放棄**ボタンが利用できます。使用すると、スコアは計算されずセッションは**放棄**としてマークされます — すでに多くの問題に正しく回答していても、認定には一切反映されません

複数の正解がある問題では、すべての正解を選び、かつ不正解を1つも選ばなかった場合にのみ得点になります。1つ多く選んだり、正解を1つ見逃したりすると、その問題は0点になります。たとえ残りが正しくても同じです。

### トレーニングモード

`トレーニング`モードは、試験を受ける前に練習するためのものです。

このモードでは:

- 問題ごとに回答を確定できます
- ツールがすぐに回答の正誤を表示します
- 自分のミスから学びやすくなります
- セッションを一時停止できます（**一時停止**ボタン）。タイマーが止まり、後でダッシュボードから同じ問題を、残り時間を保持したまま再開できます
- スコアに関係なく、このモードは認定には一切反映されません

## セッションの開始

セッションを開始するには:

1. ダッシュボードに移動する
2. 希望のパックを選ぶ
3. セッション種別を選ぶ
4. 開始ボタンをクリックする

これで問題のセッションが開始されます。

次の場合、認定セッションが拒否されることがあります:

- すでに有効な認定がある場合
- 不合格後の待機期間が進行中の場合
- パックがまだ準備できていない場合（問題数が不足）— この場合は管理者に連絡してください

該当する場合は、説明メッセージが表示されます。

## 問題への回答

セッション中:

- 問題は1問ずつ表示されます
- タイマーが残り時間を示します
- 問題によって、正解が1つまたは複数あります

ヒント:

- 回答する前に問題文をよく読む
- 複数の選択が必要かどうか確認する
- 残り時間に注意する

セッションモードによって:

- `試験`モードでは、即時フィードバックなしで進みます
- `トレーニング`モードでは、次に進む前にフィードバックを確認できます

## セッションの終了

セッションは次のいずれかの方法で終了します:

- 最後の問題に到達して送信する
- セッションを終了することを選ぶ
- 制限時間が切れる

終了すると、結果ページに移動します。

## 結果の理解

結果ページには通常次の内容が表示されます:

- 取得したスコア
- セッションのステータス
- 対象のパック
- 受験日
- 得られた結果：合格、不合格、または期限切れ

セッションが認定に対応している場合:

- 合格すると認定が有効になることがあります
- 不合格は合格基準に達しなかったことを意味します
- 期限切れは制限時間を超えたことを意味します

## 回答の見直し

トレーニングモードでは、ツールで次の内容を見直せます:

- 出題された問題
- 自分の回答
- 正解
- 各問題のステータス

この機能は、上達し自分のミスを理解するのに役立ちます。

## 履歴と認定

自分のスペースから、次の内容を確認できます:

- セッション履歴
- これまでのスコア
- 認定のステータス

認定は次のいずれかとして表示されます:

- 有効
- まもなく期限切れ
- 期限切れ
- 未取得

これにより、再受験が必要かどうかをすぐに把握できます。

## 言語の変更

ツールでは、各ページ上部にあるセレクター（国旗）から複数の表示言語を選択できます。

- 言語を選ぶとすぐに記憶され、次回以降の訪問でもすべてのページであなたの既定の言語になります
- 進行中の試験またはトレーニングセッションの最中を含め、いつでも言語を変更できます。ページは同じ問題のまま再読み込みされ、表示テキストのみが変わります
- セッション中に言語を変更しても、すでに入力した回答は消えず、セッションが再開始されることもありません

## 困ったときは

問題が発生した場合:

- まずメールアドレスとパスワードを確認してください
- 表示が止まっているように見える場合はページを再読み込みしてください
- セッションが期限切れになっていないか確認してください
- アクセスや認定が誤ってブロックされていると思われる場合は、管理者に連絡してください

## よくある質問

### 認定を開始できません。なぜですか？

考えられる理由は3つです:

- そのパックの有効な認定がすでにある
- 最近の不合格後の待機期間が有効になっている
- パックが管理側でまだ準備できていない（問題数が不足） — その場合は管理者への連絡を案内されます

どちらが該当するかは、ダッシュボードのメッセージで確認できます。

### トレーニングと試験の違いは何ですか？

`トレーニング`モードは練習用です。問題を1つずつ確定し、回答が正しいかどうかをすぐに確認できます。スコアに関係なく、このモードは認定には一切反映されません。

`試験`モードは公式の評価です。受験中はフィードバックなしですべての問題に回答し、スコアは終了時にのみ計算され、この結果によって認定を取得または維持できるかどうかが決まります。試験中に放棄すると、スコアは計算されず、すでに正解していても一切反映されません。

### 一部の正解だけを選んだ場合、スコアは正しいですか？

はい、そのルールが適用されます。問題は、すべての正解を選び、不正解を1つも選ばなかった場合にのみ1点になります。1つ多く選んだり正解を見逃したりすると、大部分が正しくても0点になります。

### 時間が切れるとどうなりますか？

セッションは自動的に終了し、期限切れとして扱われます。スコアは、それまでに回答した問題に基づいて計算されます — パックの合格基準に対するスコアによって合格または不合格になります。

### 放棄するとどうなりますか？

**放棄**ボタン（試験モードで利用可能）をクリックすると、セッションは即座に終了し、スコアは一切計算されません。ステータスは**放棄**と表示され、すでにどれだけ正解していても認定には一切反映されません。

### 後でセッションを再開できますか？

モードによって異なります:

- `トレーニング`モードでは可能です。**一時停止**ボタンでタイマーが止まり、後でダッシュボードから同じ問題を、残り時間を保持したまま再開できます
- `試験`モードでは不可能です。セッションは一度で完了する必要があります。一時停止（試験では利用不可）も放棄もせずにページを閉じた場合、セッションは制限時間が切れるまでアクティブのままで、その後回答済みの問題に基づいてスコアが計算されます。放棄ボタンをクリックした場合は、スコアなしで即座に終了します。
MD,
    default => <<<'MD'
# Guide utilisateur

Ce document explique comment utiliser l'outil de certification Probance au quotidien, sans entrer dans les details techniques.

## Resume rapide

Pour utiliser l'outil simplement:

1. connecte-toi
2. choisis un pack
3. lance une session
4. reponds aux questions avant la fin du temps
5. consulte ton resultat
6. suis tes certifications depuis le tableau de bord

## Se connecter

Pour acceder a l'outil:

1. ouvre la page de connexion
2. saisis ton email et ton mot de passe
3. valide pour arriver sur ton tableau de bord

Si tu n'as pas encore de compte, utilise la page d'inscription.

Si tu as oublie ton mot de passe, utilise la fonction de reinitialisation.

## Tableau de bord

Le tableau de bord est la page principale de l'utilisateur.

Tu y retrouves:

- les certifications disponibles
- les boutons pour lancer une session
- ton historique recent
- tes resultats precedents
- l'etat de tes certifications

Depuis cette page, tu peux choisir un pack puis demarrer une session.

Les packs et certifications sont regroupes par **programme**. Si tu as acces a plusieurs programmes, un selecteur apparait en haut du tableau de bord: change de programme pour voir les packs et certifications qui lui sont propres. Si tu n'as acces qu'a un seul programme, ce selecteur n'apparait pas.

## Les deux types de session

L'outil propose en general deux modes:

### Mode Exam

Le mode `Exam` est le mode officiel: c'est celui qui compte pour ta certification.

Dans ce mode:

- tu reponds aux questions sans correction immediate
- le score final est calcule a la fin
- le resultat determine si la certification est obtenue ou non
- un bouton **Abandonner** est disponible: si tu l'utilises, aucun score n'est calcule et la session est marquee **Abandonnee** — elle ne compte jamais pour ta certification, meme si tu avais deja repondu correctement a de nombreuses questions

Pour une question a plusieurs bonnes reponses, le point n'est acquis que si tu coches exactement toutes les bonnes reponses et aucune mauvaise. Une reponse cochee en trop ou une bonne reponse oubliee fait que la question vaut 0, meme si le reste etait correct.

### Mode Entrainement

Le mode `Entrainement` sert a t'entrainer avant de passer l'Exam.

Dans ce mode:

- tu peux valider question par question
- l'outil affiche immediatement si ta reponse est correcte
- tu peux apprendre de tes erreurs plus facilement
- tu peux mettre la session en pause (bouton **Pause**): le chronometre s'arrete et tu peux reprendre plus tard depuis le tableau de bord, a la meme question, avec le temps restant conserve
- ce mode ne compte jamais pour ta certification, quel que soit ton score

## Demarrer une session

Pour lancer une session:

1. rends-toi sur le tableau de bord
2. choisis le pack souhaite
3. choisis le type de session
4. clique sur le bouton de demarrage

L'outil ouvre alors la session de questions.

Dans certains cas, une session de certification peut etre refusee:

- si une certification valide existe deja pour ce pack
- si un delai d'attente est en cours apres un echec
- si le pack n'est pas encore pret (pas assez de questions configurees) — dans ce cas, contacte un administrateur

Dans ce cas, un message explicatif s'affiche.

## Repondre aux questions

Pendant une session:

- une question est affichee a la fois
- un chronometre indique le temps restant
- selon la question, une ou plusieurs reponses peuvent etre correctes

Conseils:

- lis bien l'enonce avant de repondre
- verifie si plusieurs choix sont attendus
- surveille le temps restant

Selon le mode de session:

- en mode `Exam`, tu avances sans correction immediate
- en mode `Entrainement`, tu peux voir un retour avant de passer a la suite

## Fin de session

Une session peut se terminer de plusieurs manieres:

- tu arrives a la derniere question et tu valides
- tu choisis de terminer la session
- le temps imparti est ecoule

Une fois terminee, tu es redirige vers la page de resultat.

## Comprendre le resultat

La page de resultat affiche generalement:

- le score obtenu
- le statut de la session
- le pack concerne
- la date de passage
- le resultat obtenu: reussi, echoue ou expire

Si la session correspond a une certification:

- un succes peut valider la certification
- un echec signifie que le seuil n'a pas ete atteint
- une expiration signifie que le temps a ete depasse

## Revoir ses reponses

En mode entrainement, l'outil permet de revoir:

- les questions posees
- tes reponses
- les bonnes reponses
- le statut de chaque question

Cette fonction est utile pour progresser et comprendre ses erreurs.

## Historique et certifications

Depuis ton espace, tu peux consulter:

- l'historique de tes sessions
- tes scores precedents
- l'etat de tes certifications

Une certification peut apparaitre comme:

- valide
- bientot expirante
- expiree
- absente

Cela permet de savoir rapidement si une nouvelle tentative est necessaire.

## Changer la langue

L'outil propose plusieurs langues d'affichage via le selecteur (drapeau) present en haut de chaque page.

- des que tu choisis une langue, elle est memorisee et reste ta langue par defaut sur toutes les pages, meme lors de tes prochaines visites
- tu peux changer de langue a tout moment, y compris pendant une session Exam ou Entrainement en cours: la page se recharge sur la meme question, seuls les textes affiches changent
- changer de langue en cours de session n'efface pas tes reponses deja donnees et ne redemarre pas la session

## En cas de probleme

Si tu rencontres un souci:

- verifie d'abord ton email et ton mot de passe
- recharge la page si un affichage semble bloque
- verifie que ta session n'a pas expire
- contacte un administrateur si tu penses qu'un acces ou une certification est bloquee a tort

## Questions frequentes

### Je ne peux pas lancer une certification, pourquoi ?

Deux raisons possibles:

- tu as deja une certification encore valide pour ce pack
- un delai d'attente est actif apres un echec recent
- le pack n'est pas encore pret cote administration (pas assez de questions configurees) — un message t'invite alors a contacter un administrateur

Un message sur le tableau de bord t'indique laquelle de ces raisons s'applique.

### Quelle difference entre entrainement et exam ?

Le mode `Entrainement` sert a pratiquer: tu valides chaque question une par une et vois immediatement si ta reponse est correcte. Ce mode ne compte jamais pour ta certification, quel que soit ton score.

Le mode `Exam` est l'evaluation officielle: tu reponds a toutes les questions sans aucune correction pendant le passage, le score n'est calcule qu'a la fin, et c'est ce resultat qui determine si tu obtiens ou conserves ta certification. Un abandon en cours d'Exam ne calcule aucun score et ne compte jamais, meme avec de bonnes reponses deja donnees.

### Mon score est-il juste si j'ai coche une reponse partiellement correcte ?

Oui, la regle s'applique. Une question ne vaut 1 point que si tu coches exactement toutes les bonnes reponses et aucune mauvaise. Si tu en coches une de trop ou oublies une bonne reponse, la question vaut 0 — meme si tu etais en grande partie correct.

### Que se passe-t-il si je manque de temps ?

La session est cloturee automatiquement et consideree comme expiree. Le score est calcule sur les questions auxquelles tu as repondu jusque-la — tu peux reussir ou echouer selon ce score par rapport au seuil du pack.

### Que se passe-t-il si j'abandonne ?

Si tu cliques sur le bouton **Abandonner** (disponible en mode Exam), la session est immediatement cloturee sans aucun calcul de score. Le statut affiche est **Abandonnee** et cela ne compte jamais pour ta certification, quel que soit le nombre de bonnes reponses deja donnees.

### Puis-je reprendre une session plus tard ?

Ca depend du mode:

- en mode `Entrainement`, oui: le bouton **Pause** arrete le chronometre, et tu peux reprendre plus tard depuis le tableau de bord, a la meme question, avec le temps restant conserve
- en mode `Exam`, non: la session doit etre terminee en une seule fois. Si tu fermes la page sans faire Pause (indisponible en Exam) ni Abandonner, la session reste active jusqu'a expiration du temps imparti — le score sera alors calcule sur les questions deja repondues. Si tu cliques sur Abandonner, la session est cloturee immediatement sans score.
MD,
  };
}

$user = require_auth();
$lang = get_lang();
$guideSuffix = match ($lang) {
  'en' => '_EN',
  'es' => '_ES',
  'jp' => '_JP',
  default => '',
};
$guidePaths = [
  __DIR__ . '/docs/GUIDE_UTILISATEUR' . $guideSuffix . '.md',
  __DIR__ . '/docs/GUIDE_UTILISATEUR.md',
  dirname(__DIR__) . '/docs/GUIDE_UTILISATEUR' . $guideSuffix . '.md',
  dirname(__DIR__) . '/docs/GUIDE_UTILISATEUR.md',
  '/opt/certif/docs/GUIDE_UTILISATEUR' . $guideSuffix . '.md',
  '/opt/certif/docs/GUIDE_UTILISATEUR.md',
];
$guideMarkdown = '';
foreach ($guidePaths as $guidePath) {
  if (is_file($guidePath)) {
    $guideMarkdown = (string)file_get_contents($guidePath);
    break;
  }
}
if ($guideMarkdown === '') {
  $guideMarkdown = help_fallback_markdown($lang);
}

$docSections = [];
if ($guideMarkdown !== '' && preg_match_all('/^##\s+(.+)$/m', $guideMarkdown, $matches)) {
  foreach ($matches[1] as $heading) {
    $label = trim((string)$heading);
    $slug = app_markdown_slugify($label);
    if ($slug !== '') {
      $docSections[] = ['label' => $label, 'slug' => $slug];
    }
  }
}

$guideHtml = app_markdown_to_html($guideMarkdown);

$helpTitle = match ($lang) {
  'en' => 'User Help',
  'es' => 'Ayuda del usuario',
  'jp' => 'ユーザーガイド',
  default => 'Aide utilisateur',
};

$helpSubtitle = match ($lang) {
  'en' => 'A practical guide to using the candidate area, finding the main actions and understanding the certification flow.',
  'es' => 'Guia practica para usar el espacio candidato, encontrar las acciones principales y entender el recorrido de certificacion.',
  'jp' => '候補者スペースの使い方、主な操作、認定の流れを分かりやすくまとめたガイドです。',
  default => "Guide pratique pour utiliser l'espace candidat, retrouver les principales actions et comprendre le parcours de certification.",
};

$helpKicker = match ($lang) {
  'en' => 'Help center',
  'es' => 'Centro de ayuda',
  'jp' => 'ヘルプセンター',
  default => "Centre d'aide",
};

$dashboardLabel = match ($lang) {
  'en' => 'Back to candidate area',
  'es' => 'Volver al espacio candidato',
  'jp' => '候補者スペースに戻る',
  default => 'Retour espace candidat',
};

$dashboardMeta = match ($lang) {
  'en' => 'Back to candidate area',
  'es' => 'Volver al espacio candidato',
  'jp' => '候補者スペースに戻る',
  default => 'Retour espace candidat',
};

$isOwnerNotAdmin = user_can_access_reporting_area($user) && !user_has_role($user, 'ADMIN');
$adminDocLabel = $isOwnerNotAdmin
  ? match ($lang) {
      'en' => 'Owner documentation',
      'es' => 'Documentación owner',
      'jp' => 'オーナードキュメント',
      default => 'Documentation owner',
    }
  : match ($lang) {
      'en' => 'Admin documentation',
      'es' => 'Documentacion admin',
      'jp' => '管理者ドキュメント',
      default => 'Documentation admin',
    };

$adminDocMeta = $isOwnerNotAdmin
  ? match ($lang) {
      'en' => 'Open the owner guide',
      'es' => 'Ver la guía del owner',
      'jp' => 'オーナーガイドを開く',
      default => "Voir le guide owner",
    }
  : match ($lang) {
      'en' => 'Open the administration guide',
      'es' => 'Ver la guia de administracion',
      'jp' => '管理者ガイドを開く',
      default => "Voir le guide d'administration",
    };

$tocTitle = match ($lang) {
  'en' => 'Contents',
  'es' => 'Contenido',
  'jp' => '目次',
  default => 'Sommaire',
};

$languageLabel = match ($lang) {
  'en' => 'Language',
  'es' => 'Idioma',
  'jp' => '言語',
  default => 'Langue',
};
 
$backToTopLabel = match ($lang) {
  'en' => 'Back to top',
  'es' => 'Volver arriba',
  'jp' => 'ä¸Šã«æˆ»ã‚‹',
  default => 'Remonter',
};

$backToTopMeta = match ($lang) {
  'en' => 'Return to the top of the guide',
  'es' => 'Volver al inicio de la guia',
  'jp' => 'ã‚¬ã‚¤ãƒ‰ã®ä¸€ç•ªä¸Šã«æˆ»ã‚‹',
  default => 'Retourner en haut du guide',
};
$backToTopLabel = $lang === 'jp' ? 'Top' : $backToTopLabel;
?>
<!doctype html>
<html lang="<?= h(html_lang_code($lang)) ?>" id="doc-top">
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="utf-8">
  <title><?= h($helpTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>">
  <script src="/assets/theme-toggle.js?v=1"></script>
</head>
<body>
<div class="container doc-page">
  <div class="card dashboard-card doc-shell doc-hero">
    <div class="doc-hero-copy">
      <h2 class="h1"><?= h($helpTitle) ?></h2>
      <p class="sub"><?= h($helpSubtitle) ?></p>
    </div>
    <div class="doc-hero-actions">
      <div class="doc-hero-lang">
        <?php render_flag_lang_picker($lang, "'/help.php?lang={lang}'"); ?>
      </div>
      <div class="doc-action-stack">
        <a class="btn ghost dashboard-admin-btn" href="/dashboard.php?lang=<?= h(urlencode($lang)) ?>"><?= h($dashboardLabel) ?></a>
        <?php if (user_can_access_reporting_area($user)): ?>
          <?php $adminHelpHref = user_has_role($user, 'ADMIN') ? '/admin/help.php' : '/admin/help_owner.php'; ?>
          <a class="btn ghost dashboard-admin-btn" href="<?= h($adminHelpHref) ?>">
            <?= h($adminDocLabel) ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:6px;opacity:.75"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="doc-layout">
    <?php if ($docSections): ?>
      <aside class="card doc-toc">
        <p class="doc-toc-title"><?= h($tocTitle) ?></p>
        <nav aria-label="<?= h($tocTitle) ?>">
          <?php foreach ($docSections as $section): ?>
            <a class="doc-toc-link" href="#<?= h($section['slug']) ?>"><?= h($section['label']) ?></a>
          <?php endforeach; ?>
        </nav>
      </aside>
    <?php endif; ?>
    <div class="card dashboard-card doc-shell doc-body">
      <div class="doc-content">
        <?= $guideHtml ?>
      </div>
    </div>
  </div>
</div>
<a class="doc-scroll-top" href="#doc-top" aria-label="<?= h($backToTopLabel) ?>" title="<?= h($backToTopLabel) ?>">
  <span aria-hidden="true">↑</span>
  <span><?= h($backToTopLabel) ?></span>
</a>
</body>
</html>
