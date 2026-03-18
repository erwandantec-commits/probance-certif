# Certif App

Application web PHP/MySQL pour gerer des sessions de certification (exam/training), le suivi des resultats et une interface d'administration.

## Stack technique

- PHP 8.2 + Apache
- MariaDB 11
- Docker Compose
- Frontend HTML/CSS/JS sans framework

## Demarrage rapide (local)

Prerequis:

- Docker
- Docker Compose

Lancer l'application:

```bash
docker compose up -d --build
```

Acces local:

- App web: http://localhost:8080
- Base MariaDB: `localhost:3306`

Comptes:

- Inscription via `/register.php`
- Connexion via `/login.php`

## Architecture du projet

- `app/`: code applicatif PHP (auth, dashboard, exam, admin, assets)
- `app/admin/`: pages d'administration (sessions, certifications, packages, questions)
- `app/services/`: logique metier (sessions)
- `docs/GUIDE_ADMIN.md`: guide non technique pour l'espace d'administration
- `docs/GUIDE_UTILISATEUR.md`: guide non technique pour les utilisateurs finaux
- `docs/COMPRENDRE_OUTIL.md`: documentation fonctionnelle et technique pour comprendre rapidement l'outil
- `initdb/01_schema.sql`: schema SQL
- `initdb/02_seed.sql`: seed (minimal)
- `db_schema/`: migrations SQL versionnees (`NNN_description.sql`)
- `data/`: volume de donnees MariaDB (persistance locale)
- `docker-compose.yml`: orchestration web + db
- `Dockerfile`: image PHP/Apache + extensions PDO MySQL

## Fonctionnalites principales

- Inscription/connexion utilisateur
- Demarrage de sessions de certification (`EXAM` ou `TRAINING`)
- Passage des questions et calcul du resultat
- Historique des sessions et cartes de certifications
- Interface admin pour:
  - supervision des sessions
  - gestion packages
  - gestion/import des questions
  - suivi des certifications

## Role admin

Le role admin est stocke dans la table `users` (colonne `role` avec valeurs `USER` ou `ADMIN`).

Par defaut, l'inscription cree un utilisateur avec `role='USER'`.

Promouvoir un utilisateur en admin:

```sql
UPDATE users
SET role = 'ADMIN'
WHERE email = 'adresse@domaine.com';
```

Verifier:

```sql
SELECT id, email, role
FROM users
WHERE email = 'adresse@domaine.com';
```

Exemple via Docker:

```bash
docker compose exec -T db mariadb -ucertif_user -pcertif_pass certif -e "UPDATE users SET role='ADMIN' WHERE email='adresse@domaine.com';"
```

Note: si l'utilisateur est deja connecte, il doit se deconnecter/reconnecter pour recuperer le role en session.

## Base de donnees

Le schema est initialise automatiquement au premier demarrage via:

- `initdb/01_schema.sql`
- `initdb/02_seed.sql`

Ensuite, a chaque demarrage du conteneur `db`, les migrations SQL sont appliquees automatiquement via:

- `db/db-entrypoint.sh`
- `db/migrate-on-start.sh`
- `db_schema/` (scripts versionnes executes dans l'ordre de version)

Versionning de schema:

- `schema_version` (une ligne, version courante)
- `schema_migrations` (historique des scripts appliques)

Tables centrales:

- `users`: comptes applicatifs + role
- `contacts`: identite email pour les sessions
- `packages`: certifications disponibles
- `questions`, `question_options`: banque de questions/reponses
- `sessions`, `session_questions`, `answer_options`: execution des sessions
- `password_resets`: liens de reinitialisation

## Reset mot de passe

- `/forgot-password.php` genere un token de reset
- `/reset-password.php` applique le nouveau mot de passe

Important: en local, le lien de reset est affiche dans la page et pointe vers `http://localhost:8080/...`.
En production, il faut brancher un envoi d'email et utiliser l'URL publique.

## Deploiement

Ce projet est deploye en production derriere une URL publique (exemple: `https://certif.intranet.probance.com/`).

Bonnes pratiques avant/pendant deploiement:

- definir des mots de passe forts pour la base
- ne pas laisser de secrets en dur dans le code
- activer HTTPS uniquement
- sauvegarder regulierement la base
- limiter l'acces reseau a MariaDB
- verifier que le conteneur `db` termine les migrations au demarrage (logs `[migrate]`)

Workflow de release recommande:

Principe:

- les scripts operationnels s'executent uniquement depuis l'interieur des conteneurs
- ne pas executer de script de release/migration depuis le poste du developpeur
- entrer dans le conteneur cible, puis lancer le script Bash adapte

1. depuis le conteneur `web`, creer la migration necessaire:

```bash
/opt/certif/scripts/new-migration.sh add_new_column
```

Ce script cree `db_schema/NN_description.sql` directement dans le volume partage.

2. implementer et tester le SQL dans `db_schema/NN_description.sql`
3. mettre a jour `app/version.txt` avec la version de release (ex: `1`, `2` ou `1.18.1`)
4. redemarrer les conteneurs selon votre procedure de deploiement

Au demarrage du conteneur `db`, l'entrypoint:

- attend que MariaDB soit joignable
- applique automatiquement les migrations manquantes
- verifie que `schema_version` correspond a la derniere migration versionnee
- verifie que `app/version.txt` est present et non vide
- echoue le demarrage du conteneur si un controle ou une migration echoue

Version applicative:

- `app/version.txt` est la source de verite de la version visible dans l'app
- le footer affiche automatiquement `Probance Certif Tool - V <version> - Probance <annee>`
- `/version.php` expose un JSON minimal avec `app_version`, `schema_version` et `db_status`
- `/opt/certif/scripts/verify-release.sh` reste disponible pour un controle manuel ponctuel si besoin

## Import BDD existante puis futures migrations

Si vous importez une base existante pour la prochaine release, il suffit maintenant de redemarrer le conteneur `db` apres import.

Important:

- utiliser ce workflow uniquement si la base importee correspond deja au schema applicatif cible
- les migrations de `db_schema/` doivent rester idempotentes
- aucune action manuelle supplementaire n'est necessaire dans le conteneur `db`

Procedure:

1. importer la base dans MariaDB
2. redemarrer le conteneur `db`
3. laisser l'entrypoint executer les controles et migrations automatiquement

Le script `/opt/certif/scripts/baseline-imported-db.sh` reste disponible uniquement pour un restamp manuel exceptionnel.

## Regles strictes de migration BDD

Pour tout futur changement de schema:

1. Ne jamais modifier ou supprimer une migration deja versionnee.
2. Ajouter un nouveau script `db_schema/NNN_description.sql` (`NNN` strictement > version actuelle).
3. Rendre la migration idempotente (`IF EXISTS` / `IF NOT EXISTS` / `UPDATE` cible).
4. Inclure la migration de donnees necessaire (pas seulement le DDL).
5. Tester sur une base existante avec donnees reelles avant de deployer.
6. Verifier apres demarrage:
   - `SELECT version FROM schema_version WHERE id = 1;`
   - `SELECT version, script_name, applied_at FROM schema_migrations ORDER BY version;`

Important:

- `01_schema.sql` et `02_seed.sql` servent a l'initialisation d'une base vide.
- `db_schema/` contient exclusivement les migrations incrementales de production.
- Les evolutions de schema en production passent desormais uniquement par migrations versionnees.
- les scripts `*.sh` sont prevus pour etre lances depuis les conteneurs (`web` ou `db`), pas depuis le poste hote

## Points de vigilance securite (etat actuel)

- `app/config.php` contient des credentials en dur
- `docker-compose.yml` contient les credentials DB en clair
- la reinitialisation mot de passe est configuree en mode local (pas d'email)

Recommande en production:

- passer les secrets par variables d'environnement (ou secret manager)
- configurer un vrai provider SMTP/transactionnel
- durcir les policies d'acces admin et auditer les logs

Configuration SMTP actuelle:

- serveur: `smtp-gw.company.lan`
- port: `25`
- expediteur: `certif@company.tld`
- authentification SMTP: desactivee
- TLS: desactive

## Commandes utiles

Arreter:

```bash
docker compose stop
```

Voir les logs:

```bash
docker compose logs -f web
docker compose logs -f db
```

Redemarrer uniquement le web:

```bash
docker compose up -d --build web
```

## Sauvegarde locale

Pour une sauvegarde locale simple "au cas ou", utiliser:

```powershell
.\scripts\backup.ps1
```

Ce script cree dans `backups/`:

- un dump SQL de la base `certif`
- une archive ZIP du projet utile (`app/`, `docs/`, `db_schema/`, `initdb/`, `scripts/`, fichiers racine)

Options utiles:

```powershell
.\scripts\backup.ps1 -IncludeDataSnapshot
.\scripts\backup.ps1 -IncludeDataSnapshot -StopDbForDataSnapshot
.\scripts\backup.ps1 -KeepDays 30
.\scripts\backup.ps1 -DryRun
```

Notes:

- `-IncludeDataSnapshot` ajoute une archive du dossier `data/`
- `-StopDbForDataSnapshot` arrete temporairement `db` pour une copie a froid plus fiable
- les sauvegardes restent locales et ne doivent pas etre committees
