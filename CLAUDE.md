# CLAUDE.md — Probance Certification Platform

## Project Overview

**probance-certif** is a proof-of-concept (POC) web-based QCM (Multiple Choice Questions) certification platform. It allows users to take timed certification exams across various packages/topics, view results with detailed answer breakdowns, and provides an admin dashboard for monitoring sessions.

- **Type:** Single-Page Application (SPA)
- **Stack:** Vanilla HTML5, CSS3, JavaScript (no frameworks, no build tools)
- **Backend:** Google Apps Script Web App (external, not in this repo)
- **Deployment:** Static file serving — the entire application is a single `index.html` file

## Repository Structure

```
probance-certif/
├── assets/
│   └── probance-logo.png    # Brand logo (displayed in header)
├── index.html               # Complete application (HTML + CSS + JS, ~850 lines)
├── README.md                # Brief project description
└── CLAUDE.md                # This file
```

## Architecture

### Single-File SPA

The entire application lives in `index.html` with three embedded sections:
1. **`<style>`** (lines 7–360) — All CSS including CSS custom properties for theming
2. **`<body>` HTML** (lines 362–435) — Six screen views toggled via `.active` class
3. **`<script>`** (lines 437–843) — All JavaScript logic, API calls, and state management

### Screen Views

The app uses a screen-based navigation pattern where only one `<div class="screen">` is visible at a time:

| Screen ID | Purpose |
|---|---|
| `loginScreen` | Email-based user sign-in |
| `adminLoginScreen` | Password-based admin access |
| `packageScreen` | Certification package selection |
| `examScreen` | Timed QCM exam with question navigation |
| `resultsScreen` | Score display and detailed answer review |
| `adminScreen` | Admin dashboard for session monitoring |

Screen switching is handled by `showScreen(screenId)` which toggles the `.active` CSS class.

### State Management

All state is held in module-level JavaScript variables (line 444–452):

```javascript
let currentUser = null;        // Logged-in user email
let selectedPackage = null;    // Chosen certification package object
let currentSession = null;     // Active exam session ID
let questions = [];            // Current exam questions array
let currentQuestionIndex = 0;  // Current question pointer
let userAnswers = {};          // Map of questionId -> selected answer
let timerInterval = null;      // Timer interval reference
let isAdmin = false;           // Admin mode flag
```

State is entirely in-memory — refreshing the page resets everything.

### API Communication

All backend communication goes through `apiCall(action, data)` (line 455) which POSTs JSON to a Google Apps Script endpoint. Uses `Content-Type: text/plain` to avoid CORS preflight.

**API actions used:**
- `login` — Authenticate user by email
- `getPackages` — Fetch available certification packages
- `startSession` — Begin an exam session
- `saveAnswer` — Persist a single answer
- `finishSession` — Complete exam and get score
- `getResults` — Get detailed results for a session
- `adminGetSessions` — List all sessions (admin)
- `adminGetSessionDetail` — Get details for a specific session (admin)

**API endpoint URL** is hardcoded at line 439.

### CSS Theming

The app uses CSS custom properties defined in `:root` (lines 10–26) for consistent theming:

| Variable | Usage |
|---|---|
| `--bg` | Page background |
| `--text` | Primary text color |
| `--muted` | Secondary/muted text |
| `--primary` | Primary button color |
| `--header` | Header/accent background |
| `--card` | Card background |
| `--border` | Border color |
| `--pill` / `--pillText` | Answer option pills |

## Development Workflow

### No Build Step

This project has **no build system, no package manager, no bundler**. To work on it:

1. Edit `index.html` directly
2. Open the file in a browser or serve it with any static file server
3. Commit changes

### Serving Locally

Any static file server works:
```bash
python3 -m http.server 8000
# or
npx serve .
```

### No Automated Tests

There is no test suite, test framework, or CI/CD pipeline configured.

### No Linting/Formatting

There are no linting tools (ESLint, Prettier, etc.) configured. Follow the existing code style:
- 4-space indentation in HTML/CSS/JS
- Single quotes for JavaScript strings
- `camelCase` for function and variable names
- Template literals for HTML generation in JavaScript
- Semicolons at end of statements

## Key Conventions

### Code Organization Within index.html

The JavaScript section follows this ordering convention:
1. **Configuration constants** (`API_URL`, `ADMIN_PASSWORD`)
2. **State variables**
3. **API helper** (`apiCall`)
4. **Authentication functions** (`login`, `adminLogin`, `showAdminLogin`)
5. **Package management** (`loadPackages`, `selectPackage`)
6. **Exam flow** (`startExam`, `startTimer`, `displayQuestion`, `selectAnswer`, navigation)
7. **Results** (`finishExam`, `displayResults`)
8. **Admin functions** (`loadAdminDashboard`, `viewSessionDetail`)
9. **Utilities** (`showScreen`, `logout`, `backToPackages`)

### HTML Event Handling

All event handlers use inline `onclick` attributes rather than `addEventListener`. This is consistent throughout the codebase.

### Dynamic HTML Generation

UI updates use `innerHTML` with template literals. No templating library or virtual DOM.

## Known Issues and Security Notes

- **Hardcoded admin password** (`admin123`) at line 442 — visible in client-side source code
- **No persistent client state** — page refresh loses all session data
- **Sequential answer saving** — `finishExam` saves answers one at a time in a loop (lines 675–681), which could be slow with many questions
- **No input sanitization** — question/option text from the API is inserted directly via `innerHTML`, which is an XSS risk if the API returns malicious content
- **Reference to non-existent element** — `logout()` and `loadAdminDashboard()` reference `headerSubtitle` element ID which doesn't exist in the current HTML

## Git Conventions

- **Default branch:** `master`
- **Commit style:** Short descriptive messages (e.g., "Update index.html")
- **No `.gitignore`** configured
