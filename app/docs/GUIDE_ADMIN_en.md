# Admin guide

This guide explains how the main administration screens work, without going into the code.

## Role of the admin area

The admin area is used to:

- track sessions taken by candidates
- view a candidate's details
- manage certifications
- manage packs
- manage questions
- analyze question performance
- administer users

## Sessions

The `Sessions` page lets you monitor recent attempts.

You can see:

- the candidate
- the pack
- the session type
- the score
- the status
- a link to the details

The session details then let you:

- review the questions asked
- see the candidate's answers
- check the correct answers
- quickly open the question record
- open the question performance analysis

## Candidate

The candidate record centralizes the view for one person.

It lets you view:

- candidate information
- their session history
- their certifications
- certain admin settings linked to their path

## Certifications

The `Certifications` page provides a tracking view by candidate and by pack.

It usually shows:

- the certification status
- the last award date
- the expiration date
- access to the details of the last session

## Users

The `Users` page is used to manage application accounts.

It lets you:

- view the account list
- edit certain user information
- adjust the role if needed

### Deleting a user

Deleting an account is irreversible. Here is what happens:

- **Permanently deleted**: the account (email, password, role), program access, and any pack unlocks granted to that user.
- **Kept but anonymised**: all sessions and exam results remain in the database but are no longer linked to an account (user_id set to NULL). The history stays visible in the admin via the contact's email.
- **Certifications**: remain accessible in the Certifications tab via the contact's email, as long as the contact itself is not deleted.

## Packs

The `Packs` page is used to manage the certifications available in the tool.

A pack defines:

- its name
- its display color
- its duration
- its pass threshold
- its selection tiers (see below)
- its certification validity period
- the cooldown after failure

### Selection tiers (draw rules)

Tiers define which questions are drawn during an exam. For each tier, you specify a category (need), target levels (L1/L2/L3), and the number of questions to draw.

**The total of all tiers is the number of questions in the exam** — there is no longer a separate "Number of questions" field.

When saving:

- If no tiers are configured: a popup blocks saving and explains the pack cannot be used.
- If tiers request more questions than exist in the database: a warning popup lets you fix or save anyway.

### Pack statuses

| Badge | Meaning |
|---|---|
| **OK** | The pack is ready; questions in the database cover the tiers |
| **Incomplete** | Tiers are configured but there are not enough questions in the database |
| **No tiers** | No tiers configured — the pack cannot be used |

From `Edit pack`, you can:

- adjust pack settings
- configure selection tiers
- review associated questions (only visible when tiers are configured)
- open question editing
- open a question's performance view

## Questions

The `Questions` page is the global question bank.

It lets you:

- search for questions
- filter them
- edit them
- delete them
- open a question's performance

## Question import

The import page is used to insert or update questions in bulk.

Three modes are available:

- `Reset`: creates new questions and replaces existing questions/answers based on their ID. Only rows present in the file are processed. Questions missing from the file are not deleted. Existing translations are marked for review, and answer translations are deleted when answers are recreated.
- `Update`: creates or updates only the reference questions/answers based on their ID. This mode does not read translation columns.
- `Translations`: updates only the translated texts for the selected language, for questions that already exist in the source. This mode does not modify the source, correct answers, categories, or levels.

In reset or update mode, if an existing question is modified, its answers are deleted and then recreated.

The source language is defined on the program. In `Reset` and `Update` modes, the import forces this language and indicates the expected language for the file. In `Translations` mode, the language list excludes the program's source language.

### FAQ

#### If I reimport a question with the same ID, what happens to the translations?

If the question is reimported in the same program with the same external ID, the import finds the same internal question. The statement translation therefore remains linked to the question, but it is marked for review because the source was modified.

The answers, however, are deleted and then recreated during a reset or update. Answer translations are therefore deleted and will need to be reimported in `Translations` mode.

## Analytics

The `Analytics` page gives an aggregated view by question across all completed or expired sessions.

### Global statistics

A summary bar at the top displays key indicators for the active filters:

- **Questions** — number of distinct questions that received at least one answer
- **Answers** — total number of recorded answers
- **Success rate** — global percentage of correct answers
- **Failure rate** — global percentage of incorrect answers
- **Unchanged** — questions whose text is identical to what was seen during the sessions
- **Modified** — questions whose text was updated after some sessions
- **Deleted** — questions removed from the bank but still present in session history

### Scope filters

| Filter | Purpose |
|--------|---------|
| Question ID | finds a specific question by its external identifier |
| Session type | limits to Exam, Training, or both |
| Pack | isolates a specific pack |
| Question state | filters on Unchanged, Modified, or Deleted |
| Category | filters by `knowledge_required` value |
| Date from / to | targets a time range on the session start date |

### Advanced metric filters

For each metric, a comparator lets you narrow the list:

- `=` exact value
- `>=` greater than or equal
- `<=` less than or equal
- `between` — two values bounding a range

Available metrics: success rate (%), failure rate (%), number of answers.

Example: show only questions with a failure rate >= 60% and at least 10 answers.

### Questions table

The table is sorted by the chosen column (click the header) and paginated at 20 rows per page.

| Column | Description |
|--------|-------------|
| Rank | Global ranking by descending success rate, computed across all questions |
| ID | External question identifier |
| Question | Text truncated to 110 characters |
| Category | `knowledge_required` value |
| State | Pill: Unchanged / Modified / Deleted |
| Answers | Number of times the question was submitted |
| % OK | Success rate |
| % KO | Failure rate |
| Actions | Open the question editor · Zoom into sessions |

### Snapshot logic

Sessions record a snapshot of the question text at the time of the attempt. This means:

- a **Modified** question keeps its historical statistics — the figures remain valid, but the text shown is the current version
- a **Deleted** question remains visible in analytics as long as it has at least one recorded answer

### Zoom: session detail for a question

The zoom button opens the detail view that lists every session that included the question:

- date and time of the attempt
- candidate (email)
- pack and session type
- result: OK (correct answer) or KO (incorrect)
- link to the full session detail

Session type and date range filters from the main page are automatically carried over.

### Typical use cases

- **Hard questions** — sort by `% KO` descending to spot problematic questions and review their wording or answers
- **Rarely used questions** — sort by `Answers` ascending to find questions that are almost never drawn
- **Outdated bank** — filter on `Modified` or `Deleted` to manage questions that no longer match the source
- **Period effect** — combine date and pack filters to compare cohorts or assess the impact of a question update
