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
- `initdb/01_schema.sql`: schema SQL
- `initdb/02_seed.sql`: seed (minimal)
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

## Points de vigilance securite (etat actuel)

- `app/config.php` contient des credentials en dur
- `docker-compose.yml` contient les credentials DB en clair
- la reinitialisation mot de passe est configuree en mode local (pas d'email)

Recommande en production:

- passer les secrets par variables d'environnement (ou secret manager)
- configurer un vrai provider SMTP/transactionnel
- durcir les policies d'acces admin et auditer les logs

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
