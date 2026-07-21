# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

**Start (first run or rebuild):**
```bash
docker compose up -d --build
```

**Restart web only (after PHP changes):**
```bash
docker compose up -d --build web
```

**Logs:**
```bash
docker compose logs -f web
docker compose logs -f db
```

**Run SQL against the DB:**
```bash
docker compose exec -T db mariadb -ucertif_user -pcertif_pass certif -e "SELECT version FROM schema_version;"
```

**Create a new migration (from inside the web container):**
```bash
docker compose exec web bash
/opt/certif/scripts/new-migration.sh description_of_change
```

**Bump version:**
```bash
# Edit app/version.txt directly — only when explicitly pushing to prod, not after every change
```

There is no linter, test suite, or build step. Changes to `app/` are live-reloaded via the bind mount.

## Architecture

### Request flow

Every page follows the same pattern: include `auth.php` at the top (starts the session and enforces login), then include `db.php` to get a `$pdo` singleton, then render HTML inline. There is no router, no MVC framework, no templating engine — PHP files are the routes.

### Key shared files

| File | Role |
|------|------|
| `app/config.php` | All constants (`DB_*`, `SMTP_*`, `APP_BASE_URL`, `APP_VERSION`) read from env vars with hardcoded fallbacks |
| `app/db.php` | `db(): PDO` — lazy singleton, UTF-8, exceptions on error |
| `app/auth.php` | Session management, `current_user()`, role checks (`user_has_role()`, `user_can_access_admin_area()`) |
| `app/utils.php` | `h()` (HTML escape), `uuidv4()`, `package_label_style()`, `normalize_question_need()` |
| `app/i18n.php` | `t($key, $vars, $lang)` — inline translation dictionary for `fr`, `en`, `es`, `jp`. Language resolved from GET/POST/cookie/Accept-Language |
| `app/services/session_service.php` | All session business logic: question selection, score computation, certification status, cooldown rules |

### User-facing pages

`dashboard.php` → `start.php` → `exam.php` → `submit.php` → `result.php`

Sessions are identified by a UUID stored in `sessions.id`. `exam.php` reads `?sid=` and `?p=` (page/question index). `submit.php` finalises the session and calls `mark_session_terminated()`.

### Admin area (`app/admin/`)

All admin pages start with `require_once __DIR__ . '/_auth.php'` which delegates to the main `auth.php`. Access requires `ADMIN` or `OWNER` role. `_nav.php` renders the shared navigation and calls `render_admin_tabs()`.

Admin pages with noteworthy scope:
- `index.php` — session dashboard with filters, pagination, CSV export
- `packages.php` / `pack_create.php` / `package_edit.php` — certification package CRUD
- `programs.php` / `program_edit.php` — program management (scopes packages + questions)
- `questions.php` / `question_edit.php` / `import_questions.php` — question bank
- `certifications.php` / `certification_revoke.php` — certification tracking
- `teams.php` / `team_overview.php` / `team_profile.php` — team/user management
- `global_settings.php` — app-wide settings stored in the `global_settings` table
- `question_translations.php` — per-question translations for multi-language support

### Database migrations

- `initdb/01_schema.sql` + `02_seed.sql`: initial schema for a blank DB (run once by MariaDB on first start)
- `db_schema/NNN_description.sql`: incremental migrations applied automatically on container start via `db/migrate-on-start.sh`
- Schema version tracked in `schema_version` (single row) and `schema_migrations` (history)

**Migration rules:**
1. Never modify or delete an existing versioned migration
2. New migration number must be strictly greater than the current version
3. All migrations must be idempotent (`IF EXISTS` / `IF NOT EXISTS`)
4. Scripts run inside containers; never execute them from the host

### Question selection logic (`session_service.php`)

`select_questions_for_package()` handles two modes:
- **Bucket rules** (when `packages.selection_rules_json` is set and questions have `need`/`level` columns): distributes questions across defined need/level buckets
- **Simple random** (fallback): selects `selection_count` random questions from the package scope

Questions are scoped via `program_question_links` (preferred) or `questions.package_id` (legacy). Anti-repeat logic excludes questions seen in the last N sessions.

### Roles

`users.role` values: `USER`, `ADMIN`, `OWNER`. Role checks use `user_has_role()` in `auth.php`. Admin area requires `ADMIN` or `OWNER`. Program catalog management requires `ADMIN` only.

### Snapshot pattern

`session_questions` and `answer_options` optionally store snapshots of question text and answer labels at session-start time. `session_service.php` uses runtime column-existence checks (`table_column_exists()` with static cache) to support both old and new schema without breaking on unrun migrations.

### Programs and ownership model (lot 3)

The `programs` table is the top-level scope. Each program has an **owner** (`OWNER` role) who can see their program's sessions/certifications but cannot manage the global catalog. `ADMIN` manages everything.

Key auth helpers in `app/auth.php`:
- `auth_manageable_programs($pdo, $user)` — returns programs the current user can manage (all for `ADMIN`, owned ones for `OWNER`)
- `auth_admin_program_context($pdo, $user, $programId)` — resolves the active program filter from `?program_id=`
- `auth_program_package_scope_sql($pdo, $programId, $alias, $includeWhere)` — builds the SQL fragment to scope packages/sessions to a program

The admin nav (`_nav.php`) auto-propagates `?program_id=` across all tab links via `render_admin_tab_link()` and renders the program switcher with `render_admin_program_switcher()`.

### Question import (`admin/import_questions.php`)

CSV import is scoped to the active program. The importer:
- Auto-detects delimiter (`,`, `;`, tab) via `detect_delimiter()`
- Normalises headers with `normalize_header()` (lowercase, ASCII, strip non-alphanum)
- Associates imported questions to the active program via `program_question_links`
- Requires a program to be selected before import is allowed

### Question translations (`admin/question_translations.php`)

Translations are stored per-question for target languages derived from the program's source language (`program_source_lang`). `question_translation_target_langs($sourceLang)` returns the languages to translate into. Schema helpers `ensure_question_translation_schema()` and `ensure_program_source_language_schema()` run at page load to handle missing columns gracefully.

## Git rules

- **NEVER commit or push without an explicit instruction from the user.** Finish the code changes, then stop. Wait for the user to say "commit", "push", or "commit et push".
- Push to the GitLab remote (`gitlab`), not just GitHub origin
- If push is rejected because remote has advanced: `fetch` + `rebase` + `push`
- No temporary files, debug artifacts, or local backups in commits
- **Do not bump `app/version.txt` after every change.** The user bumps it themselves only when actually pushing to prod. Only touch it if explicitly asked ("bump la version", "on push en prod").
