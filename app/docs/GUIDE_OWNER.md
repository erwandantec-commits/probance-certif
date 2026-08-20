# Guide owner

Bienvenue ! Ce guide t'accompagne dans la prise en main de ton rôle d'owner : comment l'outil est organisé, quoi faire dès ta prise de fonction, puis le détail de chaque écran pour t'y retrouver au fil de l'utilisation.

Toutes les données que tu vois sont limitées à ton programme. Si tu n'as pas accès à un élément décrit ici, contacte l'administrateur.

---

## Comprendre l'organisation de l'outil

Avant de te lancer, voici comment s'articulent les éléments que tu vas manipuler :

- **Programme** — c'est ton périmètre. Tout ce que tu gères (packs, questions, candidats, sessions) est rattaché à ton programme. Tu ne vois jamais les données d'un autre programme.
- **Pack** — une certification proposée à tes candidats (ex. "Certification Vente niveau 1"). Un pack définit une durée, un seuil de réussite et des règles de tirage qui déterminent quelles questions sont posées à l'examen.
- **Question** — un élément de la banque, rattaché à un besoin (catégorie) et un niveau. Les règles de tirage d'un pack piochent dedans.
- **Session** — un passage d'examen (`EXAM`) ou d'entraînement (`TRAINING`) par un candidat.
- **Certification** — le résultat consolidé : un candidat certifié sur un pack, avec une date d'expiration.

En résumé : **Programme → Packs → Questions → Sessions → Certifications**. Un pack a besoin d'assez de questions correspondant à ses règles de tirage pour être utilisable par tes candidats.

### Ce que tu peux faire

- suivre les sessions et gérer les certifications de ton programme
- importer, mettre à jour et traduire les questions
- consulter l'état des packs (OK / À compléter / Sans règles de tirage)
- créer, modifier et supprimer les comptes `USER` et `OWNER` de ton programme

### Ce qui reste à l'administrateur

- créer ou supprimer des packs, modifier leurs paramètres (seuil, durée, règles de tirage)
- créer ou supprimer des programmes
- accéder aux paramètres globaux de l'application

Dès que tu es bloqué par un droit qui te manque, la solution est presque toujours la même : contacter l'administrateur.

---

## Premiers pas : que faire quand tu deviens owner ?

Voici l'ordre recommandé pour ta première connexion à l'espace admin.

### 1. Repérer l'état des packs de ton programme

Va dans l'onglet **Packs** et regarde le badge de chaque pack :

| Badge | Ce que ça veut dire | Ce que tu dois faire |
|---|---|---|
| **OK** | Le pack est prêt à être utilisé | Rien, passe à l'étape suivante |
| **À compléter** | Les règles de tirage sont définies mais la banque de questions n'en couvre pas assez | Importer des questions (étape 2) |
| **Sans règles de tirage** | Personne n'a encore configuré de règles de tirage sur ce pack | Contacter l'administrateur — lui seul configure les règles de tirage, la durée et le seuil d'un pack |

Ne communique jamais d'accès à tes candidats tant qu'un pack n'affiche pas **OK**.

### 2. Vérifier / compléter la banque de questions

Va dans l'onglet **Questions** pour voir ce qui existe déjà, filtré par besoin et niveau, et repérer ce qui manque par rapport aux règles de tirage de tes packs.

S'il manque des questions, utilise **Import de questions** (mode *Source*) avec un fichier CSV ou Excel — voir la section **Import de questions** plus bas pour le format attendu.

### 3. Traduire si ton programme est multilingue

Si tes candidats ne parlent pas tous la langue source de ton programme, va dans l'onglet **Traductions** pour voir la couverture par langue et compléter ce qui manque (import de fichier ou saisie manuelle).

### 4. Créer les comptes de ton équipe

Va dans l'onglet **Utilisateurs** et crée un compte pour chaque candidat (rôle `USER`). Si une autre personne doit co-gérer le programme avec toi, donne-lui le rôle `OWNER`.

### 5. Faire un essai avant de communiquer les accès

Avant d'inviter tes candidats, vérifie toi-même (ou via un compte `USER` de test) qu'un passage se déroule comme prévu : bon nombre de questions, bonne langue, seuil de réussite cohérent.

### 6. Communiquer les accès et suivre les résultats

Une fois vérifié, transmets les identifiants à tes candidats. Utilise ensuite les onglets **Sessions**, **Certifications** et **Analyse** pour suivre l'activité au fil de l'eau (détail de chacun ci-dessous).

---

## Sessions

La page `Sessions` liste tous les passages d'examen effectués dans ton programme.

### Ce que tu vois

Pour chaque session :

- le candidat (nom + email)
- le pack passé
- le type : `EXAM` (certification) ou `TRAINING` (entraînement)
- le score obtenu
- le statut : en cours, terminé, expiré
- le résultat : réussi ou échoué
- un lien vers le détail complet

### Filtres disponibles

Tu peux filtrer par email candidat, type de session, pack, statut ou résultat. Les filtres se combinent.

### Détail d'une session

En cliquant sur une session tu accèdes à :

- la liste des questions posées lors de ce passage
- les réponses choisies par le candidat
- les bonnes réponses mises en évidence
- un lien vers la fiche du candidat

### Export CSV

Un bouton en haut de la liste permet d'exporter toutes les sessions correspondant aux filtres actifs au format CSV, utilisable dans Excel ou tout tableur.

---

## Certifications

La page `Certifications` donne une vue de suivi par candidat.

### Ce que tu vois

Pour chaque ligne :

- nom et email du candidat
- pack concerné
- statut : `Certifié`, `Expire bientôt` (moins de 30 jours), `Expiré`, `Révoqué`
- date de dernière réussite
- date d'expiration
- lien vers la dernière session de certification

### Export CSV

Même logique que pour les sessions : tu peux exporter la vue filtrée.

### Révoquer ou restaurer une certification

Depuis la fiche d'une certification, tu peux :

- **Révoquer** : annule la certification active d'un candidat (il devra repasser)
- **Restaurer** : annule une révocation si elle a été faite par erreur

---

## Questions

La page `Questions` te donne accès à la banque de questions de ton programme.

### Ce que tu vois

Pour chaque question :

- son identifiant et texte
- son besoin (`need`) et niveau (`level`) si applicable
- son type : choix unique, choix multiple, vrai/faux
- les options de réponse (A à F)
- les packs auxquels elle est rattachée

### Filtres

Tu peux filtrer par besoin, niveau ou combinaison besoin:niveau. Un graphique de distribution te montre la répartition de ta banque par besoin et niveau.

---

## Import de questions

La page `Import de questions` est l'outil principal pour alimenter et maintenir ta banque de questions.

### Fichier accepté

CSV ou Excel (`.csv`, `.xlsx`). Le délimiteur CSV est détecté automatiquement (virgule, point-virgule ou tabulation).

### Les trois modes d'import

#### Mode Source (création)

Crée de nouvelles questions dans ton programme à partir du fichier. Chaque ligne du fichier devient une question.

Colonnes attendues (insensible à la casse, tirets et espaces ignorés) :

- `id_externe` ou `external_id` — identifiant métier unique de la question
- `texte` ou `question` — énoncé de la question
- `bonne_reponse` ou `correct` — lettre(s) de la bonne réponse (ex. `A` ou `A,C`)
- `option_a` à `option_f` — libellés des choix (au moins `option_a` à `option_c`)
- `besoin` ou `need` — catégorie de la question (optionnel)
- `niveau` ou `level` — niveau de difficulté (optionnel)
- `type` — `SINGLE`, `MULTI` ou `TRUE_FALSE` (détecté automatiquement si absent)

Les questions existantes avec le même `id_externe` ne sont pas recréées, elles sont ignorées.

#### Mode Update (mise à jour)

Met à jour des questions déjà présentes dans ta banque. Seuls les champs présents dans le fichier sont modifiés. Les questions non présentes dans le fichier ne sont pas touchées.

Utilise le même format que le mode Source. L'identifiant `id_externe` est obligatoire pour retrouver la question à mettre à jour.

#### Mode Translation (traduction)

Ajoute ou met à jour les traductions d'un lot de questions. Le fichier doit contenir l'identifiant de la question source et les colonnes de traduction pour la langue cible.

Colonnes attendues :

- `id_externe` — identifiant de la question originale
- `texte_[langue]` ou `question_[langue]` — texte traduit (ex. `texte_en`, `question_es`)
- `option_a_[langue]` à `option_f_[langue]` — options traduites

Les langues disponibles dépendent de la langue source configurée sur ton programme. Par exemple, si la source est `fr`, les langues cibles sont `en`, `es` et `jp`.

### Déroulement d'un import

1. Sélectionne le mode (Source / Update / Translation)
2. Charge ton fichier
3. L'outil détecte automatiquement les colonnes et te propose un mapping
4. Corrige le mapping si nécessaire
5. Lance l'import — un rapport indique les lignes créées, mises à jour, ignorées et en erreur

### FAQ

#### Si je réimporte une question avec le même ID, que se passe-t-il avec les traductions ?

Si la question est réimportée dans le même programme avec le même ID externe, l'import retrouve la même question interne. La traduction de l'énoncé reste donc liée à la question, mais elle est marquée à revoir car la source a été modifiée.

Les réponses, elles, sont supprimées puis recréées lors d'une réinitialisation ou d'une mise à jour. Les traductions des réponses sont donc supprimées et devront être réimportées en mode Traductions.

---

## Traductions des questions

La page `Traductions` te donne une vue matricielle de l'état de traduction de toutes tes questions.

### Ce que tu vois

Un tableau avec une ligne par question et une colonne par langue cible. Chaque cellule indique le statut de la traduction :

- **Complet** — la traduction est à jour par rapport à la source
- **À revoir** — la question source a été modifiée depuis la dernière traduction
- **Partiel** — certains champs sont traduits mais pas tous
- **Manquant** — aucune traduction pour cette langue

Des compteurs en haut te donnent la couverture globale par langue.

### Filtres

Tu peux filtrer par pack, par besoin (`need`), par langue ou par statut de traduction.

### Comment compléter les traductions

Deux façons :

1. **Import fichier** : utilise le mode `Translation` de la page d'import (voir ci-dessus)
2. **Édition manuelle** : clique sur une question dans la liste pour ouvrir son formulaire d'édition et saisir les traductions directement

---

## Packs

La page `Packs` liste les packs de certification disponibles dans ton programme.

### Ce que tu vois

Pour chaque pack :

- son nom
- la durée et le seuil de réussite
- le nombre de questions tirées par session (défini par les règles de tirage)
- son statut de configuration

### Statuts d'un pack

| Badge | Signification |
|---|---|
| **OK** | Le pack est prêt, les questions en base couvrent les règles de tirage |
| **À compléter** | Les règles de tirage sont configurées mais il n'y a pas assez de questions en base |
| **Sans règles de tirage** | Aucune règle de tirage configurée — le pack ne peut pas être utilisé |

Un pack affiché **Sans règles de tirage** ou **À compléter** ne sera pas proposé correctement aux candidats.

### Ce que tu peux faire

- **Réordonner** : utilise les flèches haut/bas pour changer l'ordre d'affichage dans l'espace candidat
- **Activer / Désactiver** : un pack désactivé n'est plus proposé aux candidats

Tu ne peux pas modifier les paramètres d'un pack (seuil, durée, règles de tirage…) ni le supprimer. Contacte l'administrateur pour ce type de modification.

---

## Utilisateurs

La page `Utilisateurs` te permet de gérer les comptes de ton équipe.

### Ce que tu vois

La liste des utilisateurs rattachés à ton programme (par leur accès programme `USER` ou `OWNER`).

Pour chaque utilisateur :

- nom, email, rôle
- nombre de sessions effectuées
- nombre d'examens réussis
- date de dernière session

### Ce que tu peux faire

**Créer un compte** — en renseignant email, nom, prénom, mot de passe et rôle. Tu peux assigner les rôles `USER` (candidat standard) et `OWNER` (co-gestionnaire).

**Modifier un compte** — depuis la fiche utilisateur : nom, prénom, email, mot de passe, rôle programme. Tu ne peux pas modifier les comptes qui ont le rôle `ADMIN` au niveau système.

**Supprimer un compte** — possible pour les comptes `USER` et `OWNER` de ton périmètre.

### Rôles programme

Depuis la fiche d'un utilisateur, tu peux définir son rôle d'accès à ton programme :

- `USER` — peut passer des examens dans l'espace candidat
- `OWNER` — co-gestionnaire du programme, accède à l'espace admin avec les mêmes droits que toi

### Fiche candidat

En cliquant sur un utilisateur tu accèdes à sa fiche complète : informations de compte, historique de sessions, état des certifications.

---

## Analyse

La page `Analyse` donne une vue agrégée des performances par question sur l'ensemble des sessions de ton programme.

### Ce que tu vois

Un bandeau de statistiques globales s'affiche en haut de page :

- **Questions** — nombre de questions distinctes ayant reçu au moins une réponse
- **Réponses** — nombre total de réponses enregistrées
- **Taux de réussite / Taux d'échec** — pourcentages globaux sur l'ensemble des sessions filtrées
- **Inchangées / Modifiées / Supprimées** — état des questions par rapport à leur version au moment des sessions

### Filtres disponibles

**Portée :**

- type de session (Examen, Entraînement ou tous)
- pack
- catégorie de question
- plage de dates (date de début de session)
- ID de question externe
- état de la question (Inchangée, Modifiée, Supprimée)

**Métriques avancées :** tu peux filtrer par taux de réussite, taux d'échec ou nombre de réponses avec des comparateurs (`=`, `>=`, `<=`, `entre`). Exemple : questions avec un taux d'échec ≥ 60 % et au moins 10 réponses.

### Tableau des questions

Chaque ligne représente une question ayant reçu au moins une réponse dans les sessions filtrées.

- **Rang** — classement global par taux de réussite décroissant
- **État** — indique si la question est identique à celle vue en session, ou si elle a été modifiée / supprimée depuis
- **% OK / % KO** — taux de réussite et d'échec sur la période filtrée
- Clic sur les en-têtes pour trier par n'importe quelle colonne

### Zoom : sessions liées à une question

Le bouton loupe ouvre le détail des sessions ayant inclus la question : date, candidat, pack, type de session, résultat (OK / KO) et lien vers la session complète.

### Cas d'usage typiques

- **Identifier les questions problématiques** — trier par `% KO` décroissant pour détecter les questions souvent ratées
- **Repérer les questions peu utilisées** — trier par nombre de réponses croissant
- **Surveiller l'état des questions** — filtrer sur `Modifiée` ou `Supprimée` après une mise à jour du contenu
