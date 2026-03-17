# Comprendre l'outil

Cette documentation a ete redigee pour aider a comprendre rapidement le fonctionnement de `probance-certif`, cote utilisateur, cote admin, et cote technique.

## Vue d'ensemble

L'application permet de:

- gerer des comptes utilisateurs
- lancer des sessions de questions sur un package de certification
- distinguer les modes `EXAM` et `TRAINING`
- calculer un score et un statut de reussite
- suivre l'historique des sessions et des certifications
- administrer les packages, les questions, les utilisateurs et les analyses de performance

Techniquement, c'est une application PHP/MySQL sans framework, structuree autour de pages PHP et d'une base MariaDB.

## Parcours principal

Le parcours standard est le suivant:

1. un utilisateur cree un compte ou se connecte
2. il arrive sur son tableau de bord
3. il choisit un package et un type de session (`EXAM` ou `TRAINING`)
4. l'application cree une session et selectionne les questions
5. l'utilisateur repond dans `exam.php`
6. les reponses sont enregistrees dans `answer_options`
7. la session est terminee via `submit.php` ou a expiration du temps
8. le resultat est visible dans `result.php`

## Difference entre EXAM et TRAINING

Le mode `EXAM` correspond a une vraie tentative de certification:

- pas de feedback detaille question par question pendant le passage
- verification des regles de certification valide existante
- verification eventuelle d'un delai de cooldown apres echec
- score final compare au seuil du package

Le mode `TRAINING` est un mode entrainement:

- l'utilisateur peut valider une question pour voir le feedback immediat
- les bonnes et mauvaises reponses sont affichees tout de suite
- une revue detaillee est visible dans `result.php`

## Pages importantes

### Front office

- `app/register.php`: creation de compte
- `app/login.php`: authentification
- `app/dashboard.php`: page centrale utilisateur
- `app/start.php`: creation d'une session
- `app/exam.php`: passage question par question
- `app/submit.php`: finalisation de session
- `app/result.php`: affichage du resultat

### Administration

- `app/admin/index.php`: vue globale sessions
- `app/admin/contact.php`: fiche detaillee d'un candidat
- `app/admin/session.php`: detail d'une session
- `app/admin/packages.php`: liste des packages
- `app/admin/package_edit.php`: configuration d'un package et de ses questions
- `app/admin/questions.php`: banque de questions
- `app/admin/question_edit.php`: edition d'une question
- `app/admin/question_performance.php`: analyse agregée par question
- `app/admin/question_performance_failures.php`: zoom sur les sessions d'une question
- `app/admin/certifications.php`: suivi des certifications
- `app/admin/users.php`: gestion des utilisateurs
- `app/admin/import_questions.php`: import en masse

## Comment une session est creee

La creation de session passe par `app/start.php`.

Responsabilites principales:

- verifier que la requete est un `POST`
- charger le package choisi
- valider le type de session
- appliquer les regles de blocage metier en `EXAM`
- selectionner les questions
- creer l'entree `sessions`
- inserer les lignes dans `session_questions`

### Regles metier appliquees au demarrage

Pour une session `EXAM`, l'application peut bloquer le lancement si:

- une certification encore valide existe deja pour ce package
- un delai de cooldown est encore actif apres un echec

Ces regles sont gerees dans `app/start.php` avec l'aide de fonctions de `app/services/session_service.php`.

## Comment les questions sont selectionnees

La logique principale est dans `select_questions_for_package()` dans `app/services/session_service.php`.

Deux modes existent:

### 1. Selection par regles JSON

Si le package contient `selection_rules_json` et que les colonnes de categorisation existent, l'application peut tirer des questions par "buckets":

- `need`
- `level`
- `take`
- `target_total`

Le total final reste pilote par la configuration du package.

### 2. Fallback simple par package

Si les regles avancees ne sont pas disponibles, l'application choisit des questions rattachees au `package_id`.

### Anti-repetition

Le service essaie d'eviter de reproposer trop vite les memes questions via `recent_question_ids_for_user_package()`.

### Reordonnancement

Une fois les questions choisies, `reorder_questions_for_session()` essaye de varier l'ordre, notamment par theme si la colonne `theme` existe.

## Comment le score est calcule

La logique de scoring est dans `compute_session_score_snapshot()` dans `app/services/session_service.php`.

Regle metier importante:

- une question vaut `1 point` seulement si l'utilisateur selectionne exactement toutes les bonnes reponses et aucune mauvaise
- sinon la question vaut `0`

Ensuite:

- `raw_score` = nombre de questions parfaitement reussies
- `max_points` = nombre total de questions
- `score_percent` = pourcentage derive du ratio `raw_score / max_points`

## Cycle de vie d'une session

Une session peut etre:

- `ACTIVE`
- `TERMINATED`
- `EXPIRED`

Fonctions importantes:

- `mark_session_terminated()`
- `mark_session_expired()`
- `session_is_expired()`

Le timeout est base sur:

- `started_at`
- `duration_limit_minutes` du package

Si le temps est depasse, la session est basculee en expiration et le resultat est finalise.

## Que stocke la base de donnees

Tables principales:

- `users`: comptes applicatifs et roles
- `contacts`: identites email rattachees aux sessions
- `packages`: certifications ou parcours disponibles
- `questions`: enonces des questions
- `question_options`: reponses possibles
- `sessions`: tentative d'un utilisateur sur un package
- `session_questions`: questions posees dans une session donnee
- `answer_options`: choix reels de l'utilisateur
- `password_resets`: reinitialisation mot de passe

Tables optionnelles ou avancees:

- `certification_revocations`
- `exam_cooldown_overrides`
- `schema_version`
- `schema_migrations`

## Logique package

Un package porte une grande partie des regles metier:

- nom
- couleur d'affichage
- seuil de reussite
- duree limite
- nombre de questions
- mode de selection
- validite de certification
- cooldown apres echec
- image de badge

L'ecran central pour cela est `app/admin/package_edit.php`.

## Logique certifications

Les certifications ne semblent pas etre stockees dans une table dediee "certifications" classique.
Elles sont plutot deduites a partir des sessions reussies et de leur date de validite.

La fonction `certification_status_from_last_success()` calcule un statut comme:

- `NONE`
- `CERTIFIED`
- `SOON`
- `EXPIRED`

L'etat de revocation peut aussi entrer en jeu si la table correspondante existe.

## Logique performance

La page `app/admin/question_performance.php` produit une vue agregee par question:

- nombre de reponses
- taux de reussite
- taux d'echec
- tri et filtres analytiques

La page `app/admin/question_performance_failures.php` sert ensuite de zoom:

- quelles sessions ont repondu a cette question
- avec quel resultat
- acces rapide a la session source

## Particularites importantes du code

### Compatibilite schema

Le projet contient beaucoup de verifications dynamiques du schema:

- `sessions_column_exists()`
- `table_exists()`
- `table_column_exists()`

Cela permet au code de tolerer plusieurs etats de base selon les migrations disponibles.

### Pas de framework

Le routage est implicite:

- une page PHP = un point d'entree HTTP

La logique metier est donc repartie entre:

- les pages PHP
- `app/services/session_service.php`
- quelques fonctions utilitaires dans `app/utils.php`

### Internationalisation

Le projet est multilingue. Les textes passent souvent par:

- `get_lang()`
- `t()`
- `localize_text()`

Le rendu depend donc de la langue courante transmise dans l'URL ou la session.

## Comment lire le projet rapidement

Si tu veux comprendre l'outil sans tout lire, je recommande cet ordre:

1. `README.md`
2. `app/dashboard.php`
3. `app/start.php`
4. `app/services/session_service.php`
5. `app/exam.php`
6. `app/result.php`
7. `app/admin/_nav.php`
8. `app/admin/index.php`
9. `app/admin/package_edit.php`
10. `app/admin/question_performance.php`

## Reponse courte a "ou est la logique metier ?"

La logique metier est surtout concentree dans:

- `app/start.php` pour les regles de lancement
- `app/exam.php` pour le deroulement de session
- `app/result.php` pour la finalisation et l'affichage
- `app/services/session_service.php` pour la selection des questions, le scoring, les statuts et la compatibilite schema
- `app/admin/*` pour les outils de supervision et d'administration

## Limites de cette documentation

Cette doc est une synthese de lecture du code, pas une spec fonctionnelle officielle.
Elle est tres utile pour l'onboarding et la maintenance, mais certaines regles metier fines peuvent encore dependre:

- du contenu SQL des migrations
- des donnees reelles en base
- de conditions de schema optionnelles
