# Owner guide

This guide covers everything you can do as a programme owner: monitoring sessions, managing certifications, importing and translating questions, administering packs, and managing your team.

All data you see is scoped to your programme. If you cannot access something described here, contact the administrator.

---

## Your role

As an owner, you manage your programme end to end. You can:

- monitor your candidates' sessions
- track and manage certifications
- import questions, update them, and translate them
- view pack status
- manage your team members

You cannot modify pack settings, create or delete packs, manage programmes themselves, or access global settings.

---

## Sessions

The `Sessions` page lists all exam attempts made within your programme.

### What you see

For each session:

- the candidate (name and email)
- the pack taken
- the type: `EXAM` (certification) or `TRAINING` (practice)
- the score achieved
- the status: in progress, completed, expired
- the result: passed or failed
- a link to the full detail

### Available filters

You can filter by candidate email, session type, pack, status, or result. Filters combine.

### Session detail

Clicking a session gives you access to:

- the list of questions asked during that attempt
- the answers chosen by the candidate
- the correct answers highlighted
- a link to the candidate profile

### CSV export

A button at the top of the list lets you export all sessions matching the active filters as a CSV file, ready for Excel or any spreadsheet tool.

---

## Certifications

The `Certifications` page provides a tracking view per candidate.

### What you see

For each row:

- candidate name and email
- pack concerned
- status: `Certified`, `Expiring soon` (less than 30 days), `Expired`, `Revoked`
- date of last pass
- expiry date
- link to the last certification session

### CSV export

Same logic as sessions: you can export the filtered view.

### Revoking or restoring a certification

From a certification detail, you can:

- **Revoke**: cancels a candidate's active certification (they will need to retake the exam)
- **Restore**: cancels a revocation if it was done by mistake

---

## Questions

The `Questions` page gives you access to your programme's question bank.

### What you see

For each question:

- its identifier and text
- its need and level (if applicable)
- its type: single choice, multiple choice, true/false
- the answer options (A to F)
- the packs it belongs to

### Filters

You can filter by need, level, or a need:level combination. A distribution chart shows the breakdown of your question bank by need and level.

---

## Question import

The `Import questions` page is the main tool for populating and maintaining your question bank.

### Accepted file

CSV or Excel (`.csv`, `.xlsx`). The CSV delimiter is detected automatically (comma, semicolon, or tab).

### The three import modes

#### Source mode (creation)

Creates new questions in your programme from the file. Each row becomes a question.

Expected columns (case-insensitive, dashes and spaces ignored):

- `external_id` — unique business identifier for the question
- `question` or `text` — question wording
- `correct` or `correct_answer` — letter(s) of the correct answer (e.g. `A` or `A,C`)
- `option_a` to `option_f` — answer labels (at minimum `option_a` to `option_c`)
- `need` — question category (optional)
- `level` — difficulty level (optional)
- `type` — `SINGLE`, `MULTI`, or `TRUE_FALSE` (auto-detected if absent)

Existing questions with the same `external_id` are skipped, not duplicated.

#### Update mode

Updates questions already in your bank. Only columns present in the file are modified. Questions not in the file are left untouched.

Uses the same format as Source mode. The `external_id` column is required to identify which question to update.

#### Translation mode

Adds or updates translations for a batch of questions. The file must contain the source question identifier and translation columns for the target language.

Expected columns:

- `external_id` — identifier of the source question
- `text_[lang]` or `question_[lang]` — translated text (e.g. `text_en`, `question_es`)
- `option_a_[lang]` to `option_f_[lang]` — translated options

The available target languages depend on the source language configured on your programme. For example, if the source is `fr`, target languages are `en`, `es`, and `jp`.

### Import flow

1. Select the mode (Source / Update / Translation)
2. Upload your file
3. The tool automatically detects columns and suggests a mapping
4. Adjust the mapping if needed
5. Run the import — a report shows rows created, updated, skipped, and in error

### FAQ

#### If I re-import a question with the same ID, what happens to the translations?

If the question is re-imported into the same programme with the same external ID, the import finds the same internal question. The translation of the question text remains linked to it, but it is marked as stale because the source has been modified.

The answer options, however, are deleted and recreated on a reset or update. Answer translations are therefore deleted and will need to be re-imported using Translation mode.

---

## Question translations

The `Translations` page gives you a matrix view of the translation status of all your questions.

### What you see

A table with one row per question and one column per target language. Each cell shows the translation status:

- **Complete** — the translation is up to date with the source
- **To review** — the source question has been modified since the last translation
- **Partial** — some fields are translated but not all
- **Missing** — no translation exists for this language

Counters at the top show overall coverage per language.

### Filters

You can filter by pack, need, language, or translation status.

### How to complete translations

Two ways:

1. **File import**: use the `Translation` mode on the import page (see above)
2. **Manual editing**: click a question in the list to open its edit form and enter translations directly

---

## Packs

The `Packs` page lists the certification packs available in your programme.

### What you see

For each pack:

- its name
- the number of available questions vs the number required per session
- the breakdown by need and level if selection rules are defined

### What you can do

- **Reorder**: use the up/down arrows to change the display order in the candidate space
- **Activate / Deactivate**: a deactivated pack is no longer shown to candidates

You cannot modify pack settings (threshold, duration, cooldown…) or delete a pack. Contact the administrator for such changes.

---

## Users

The `Users` page lets you manage your team's accounts.

### What you see

The list of users attached to your programme (via their `USER` or `OWNER` programme access).

For each user:

- name, email, role
- number of sessions completed
- number of exams passed
- date of last session

### What you can do

**Create an account** — enter email, name, first name, password, and role. You can assign `USER` (standard candidate) and `OWNER` (co-manager) roles.

**Edit an account** — from the user profile: name, first name, email, password, programme role. You cannot edit accounts that hold the system-level `ADMIN` role.

**Delete an account** — possible for `USER` and `OWNER` accounts within your scope.

### Programme roles

From a user's profile, you can set their access role for your programme:

- `USER` — can take exams in the candidate space
- `OWNER` — co-manager of the programme, accesses the admin area with the same rights as you

### Candidate profile

Clicking a user gives you access to their full profile: account information, session history, certification status.

---

## Analytics

The `Analytics` page gives an aggregated view of question performance across all sessions in your programme.

### What you see

A global statistics bar is displayed at the top of the page:

- **Questions** — number of distinct questions that received at least one answer
- **Answers** — total number of recorded answers
- **Success rate / Failure rate** — global percentages across all filtered sessions
- **Unchanged / Modified / Deleted** — state of questions relative to their version at the time of the sessions

### Available filters

**Scope:**

- session type (Exam, Training, or both)
- pack
- question category
- date range (session start date)
- external question ID
- question state (Unchanged, Modified, Deleted)

**Advanced metrics:** you can filter by success rate, failure rate or number of answers using comparators (`=`, `>=`, `<=`, `between`). Example: questions with a failure rate ≥ 60% and at least 10 answers.

### Questions table

Each row represents a question that received at least one answer within the filtered sessions.

- **Rank** — global ranking by descending success rate
- **State** — indicates whether the question is identical to what candidates saw, or has since been modified or deleted
- **% OK / % KO** — success and failure rates over the filtered period
- Click column headers to sort by any column

### Zoom: sessions linked to a question

The zoom button opens the detail view of sessions that included the question: date, candidate, pack, session type, result (OK / KO) and a link to the full session.

### Typical use cases

- **Identify problematic questions** — sort by `% KO` descending to spot frequently failed questions
- **Find rarely used questions** — sort by number of answers ascending
- **Monitor question state** — filter on `Modified` or `Deleted` after a content update
