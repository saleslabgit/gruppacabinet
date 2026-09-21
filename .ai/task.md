# Task: TASK-2026-09-21-04

Status: planned
Created from: 2f82b0e525f5c8633d79c2a69d7dab1922314ab0 (main)

## Title

Stage 6 — Connect the real psychologist cabinet profile and owner-only private document access

## Goal

Implement Stage 6 from SPEC.md using the accepted Stage 3 psychologist Blade views and the Stage 4 authentication/access foundation.

After this task, an approved enabled psychologist can:

- enter the cabinet at `/`;
- see the existing Stage 6 placeholder/empty “Мои группы” page until Stage 7;
- navigate to “Мои данные”;
- see only their own real questionnaire/profile data;
- see only their own real document list;
- securely view/download only their own private documents;
- log out through the existing real POST logout control.

This is a read-only psychologist profile/document milestone.

Do not implement psychologist self-editing, document upload/delete, group CRUD, group queries/workflow, applications, payments, email, or admin changes.

## Facts

- Stage 5 is accepted through commit `2f82b0e525f5c8633d79c2a69d7dab1922314ab0`.
- Stage 4 already provides:
  - real session login/logout;
  - per-request account eligibility checks;
  - psychologist/admin role separation;
  - psychologist root `/`;
  - real POST logout.
- Stage 5 already provides:
  - real psychologist questionnaire data in `gp_users`;
  - private document metadata in `gp_user_documents`;
  - private storage under `storage/app/private`;
  - MIME-safe authorized admin document streaming;
  - document configuration and safe filename handling.
- The accepted Stage 3 psychologist profile view is:
  - `resources/views/psychologist/profile/show.blade.php`.
- Shared profile/document partials already exist:
  - `shared/profile-data.blade.php`;
  - `shared/documents.blade.php`.
- The Stage 3 psychologist prototype navigation already contains:
  - “Мои группы”;
  - “Мои данные”;
  - “Выход”.
- The real Stage 4 psychologist navigation currently exposes only “Мои группы”.
- Independent psychologist questionnaire editing is not required in MVP.
- SPEC requires private document viewing/downloading by either the owner or administrator after authorization.
- Stage 6 acceptance explicitly requires that the psychologist:
  - sees only self and own data;
  - has no admin access;
  - sees an empty group list until Stage 7;
  - cannot retrieve another psychologist’s data/documents;
  - works on mobile;
  - reuses approved Stage 3 templates.

## Product Routes

Add real psychologist routes behind existing `account` + `role:psychologist` middleware:

```text
GET /                         psychologist.home
GET /profile                  psychologist.profile
GET /profile/documents/{document}/view
GET /profile/documents/{document}/download
```

Names should be stable and explicit, e.g.:

```text
psychologist.profile
psychologist.documents.view
psychologist.documents.download
```

Do not put a psychologist/user ID into the psychologist profile URL.

The current authenticated user is always the profile owner.

## Scope

### 1. Real psychologist navigation

Create/reuse one shared psychologist navigation builder/data contract for real psychologist pages.

Real psychologist navigation must contain:

- “Мои группы” → `psychologist.home`;
- “Мои данные” → `psychologist.profile`;
- “Выход” → existing CSRF-protected POST logout.

Correct `aria-current` state must be rendered on both pages.

Prototype navigation must remain unchanged and continue using prototype routes.

Do not add future group/application/payment routes.

### 2. Keep “Мои группы” truthful until Stage 7

The real root `/` must continue rendering the accepted `psychologist.groups.index` view.

For Stage 6:

- keep the list empty even if development/test database contains groups;
- do not query or expose group data yet;
- keep group creation unavailable;
- do not link to prototype or future create-group routes;
- update only navigation/data plumbing required for Stage 6.

Stage 7 will connect real groups.

### 3. Real “Мои данные”

Add a small psychologist cabinet/profile controller or equivalent conventional controller.

`GET /profile` must:

- get the authenticated user from the request/guard;
- not accept a user ID;
- load only relations needed for this page:
  - education type;
  - documents;
- render the existing `psychologist.profile.show` view;
- reuse `shared.profile-data` and `shared.documents`.

Display the current psychologist’s stored questionnaire data, including nullable fields already covered by the shared profile partial:

- surname/name/patronymic;
- phone/email;
- education type / other education;
- modality/program;
- training center;
- graduation year;
- training hours;
- license number/expiry;
- group-leading experience;
- groups conducted count;
- documents/education confirmation flags;
- webinar/live-session readiness;
- personal-data consent date;
- personal-data consent version.

Do not expose:

- password;
- remember token;
- active_email generated column;
- raw filesystem paths;
- internal session identifiers.

Do not add edit buttons or self-service update actions.

### 4. Profile data presentation

Reuse the same safe profile mapping/presentation logic where practical instead of creating divergent field interpretation between admin and psychologist pages.

A small refactor of `PsychologistPages::profile()` into a neutral shared presenter/helper is allowed if it materially improves reuse.

Do not introduce a generalized presentation framework.

Date/time display must continue using the project’s timezone/display conventions.

### 5. Psychologist document list

The profile page must show only documents where:

```text
gp_user_documents.user_id = authenticated_user.id
```

Use real document metadata:

- business type label;
- original filename;
- size;
- created date;
- actions: View, Download.

Psychologist must not see Delete or Upload actions.

Admin Stage 5 document management must remain unchanged.

Empty document state must use the approved Stage 3 empty state.

### 6. Adapt shared document partial safely

The existing `shared.documents` real mode currently assumes admin document routes and delete controls.

Refactor the shared partial/component contract so it can render at least three contexts without duplicate page markup:

1. prototype psychologist/admin fixture mode;
2. real admin management mode:
   - view;
   - download;
   - delete;
3. real psychologist owner read-only mode:
   - view;
   - download;
   - no delete.

Prefer explicit passed route URLs/capabilities over detecting role implicitly inside the Blade template.

Do not create a second psychologist-only document table with duplicated markup.

### 7. Owner-only document authorization

Extend/reuse `UserDocumentPolicy` or use an equally explicit policy method for owner read access.

Psychologist document authorization must require:

- authenticated user is a non-admin psychologist;
- user is approved/enabled by the existing account middleware;
- `document.user_id === authenticated_user.id`.

The psychologist routes themselves must not permit an administrator simply because they are an admin; admin access stays on the existing admin routes.

For a document belonging to another psychologist, no file content or metadata may be returned.

Prefer a 404 for cross-owner document lookup where practical to avoid exposing another user’s document existence.

### 8. Owner document lookup / IDOR defense

Do not trust only a globally bound `UserDocument $document`.

Use an owner-scoped lookup such as:

```text
currentUser->documents()->whereKey($documentId)->firstOrFail()
```

or an equivalent route binding explicitly scoped to the authenticated owner.

This ensures:

- another psychologist’s document ID returns 404;
- an administrator account cannot use psychologist-only routes because of role middleware;
- soft-deleted/disabled/non-approved owners lose access through Stage 4 middleware before document handling.

Add explicit IDOR tests.

### 9. Secure document view/download

Reuse the existing Stage 5 private-file response/security behavior.

For both owner endpoints:

- authorize/owner-scope first;
- verify private file exists;
- verify stored MIME is still in the configured allowlist;
- serve through Laravel/controller only;
- set safe Content-Type;
- set `X-Content-Type-Options: nosniff`;
- set private/no-store cache headers;
- use safe filename handling;
- do not reveal filesystem path;
- do not create public or temporary URLs.

View should use inline disposition.
Download should use attachment disposition.

If useful, extract the duplicated secure streaming logic from the admin controller into a very small shared document response service so admin and owner paths cannot drift.

Do not weaken Stage 5 admin authorization while refactoring.

### 10. No psychologist document mutations

Do not add psychologist routes/actions for:

- upload;
- delete;
- rename;
- replace;
- change document type.

A psychologist may only list, view, and download documents in Stage 6.

The admin retains full Stage 5 document management.

### 11. No self-edit flow

Do not add PATCH/PUT/POST profile mutations.

The psychologist profile is read-only in MVP at this stage.

No button should imply that the psychologist can edit their questionnaire.

### 12. Authorization boundaries

Tests and implementation must prove:

- guest → login for `/profile`;
- admin → 403 for psychologist `/profile`;
- psychologist → 200 for own profile;
- psychologist → 403 for admin routes remains unchanged;
- disabled/rejected/soft-deleted psychologist loses profile/document access via Stage 4 middleware;
- psychologist cannot choose another user via URL/request data because no user identifier is accepted;
- document IDOR returns no content for another psychologist.

### 13. Mobile / accepted UI

Do not redesign Stage 3.

Verify the real profile/document page at:

- desktop ~1440;
- tablet ~1024;
- mobile ~390.

Requirements:

- no page-level horizontal overflow;
- long email/document names wrap;
- profile detail grid collapses correctly;
- document table uses approved mobile card transformation;
- View/Download actions remain visible and usable;
- navigation with “Мои группы”, “Мои данные”, and “Выход” fits/wraps intentionally.

No screenshots need to be committed.

### 14. Prototype regression

All existing prototype behavior must remain:

- 31 page groups;
- 249 variants;
- profile prototype variants:
  - normal;
  - long;
  - no-documents;
  - permission;
- prototype document actions remain no-op;
- prototype routes remain local/testing only.

Do not replace fixture data with database data inside `/_prototype`.

### 15. Tests

All tests run on MySQL.

Add focused tests for at least:

#### Profile
- real psychologist profile route renders approved view;
- current user’s real questionnaire values are shown;
- nullable/missing values render safely;
- education relation is loaded correctly;
- password/remember token/internal values are not present;
- no edit/upload/delete controls exist;
- no query for groups is introduced on real Stage 6 root/profile unless required by existing middleware.

#### Navigation
- root and profile both show real “Мои группы” / “Мои данные” URLs;
- current nav state is correct;
- POST logout remains real;
- root remains empty/unavailable groups until Stage 7.

#### Documents
- own documents appear;
- other users’ documents do not appear;
- authorized inline view;
- authorized download;
- correct MIME/disposition/security headers;
- another psychologist’s document ID returns 404/no content;
- missing physical file returns 404;
- disallowed stored MIME returns 404;
- no private storage path/public URL in HTML or response headers.

#### Role/access
- guest redirect;
- admin 403 on psychologist profile;
- psychologist 403 on admin;
- disabled/rejected/deleted access revocation regression remains green.

#### Regression
- Stage 4 auth tests remain green;
- Stage 5 admin psychologist/document tests remain green;
- all 31 / 249 prototype variants remain;
- production has no prototype/foundation routes.

### 16. Documentation

Update actual-state docs:

- `docs/architecture.md` — psychologist self-profile owner-only boundary and shared private document streaming;
- `docs/development.md` — how to manually verify “Мои данные” with the local psychologist account;
- `docs/project-status.md` — Stage 6 implemented, Stage 7 groups still pending;
- `docs/ui-pages.md` only as needed to document real `/profile` wiring.

Do not modify SPEC.md, WORKFLOW.md, or AGENTS.md.

## Explicit Out Of Scope

Do not implement:

- psychologist questionnaire editing;
- psychologist document upload/delete;
- profile password/change-password UI;
- invitation/password setup email;
- public questionnaire/API intake;
- real psychologist group listing;
- group create/edit/delete;
- moderation;
- applications;
- group history;
- payments;
- dictionaries/settings;
- scheduler/jobs;
- WEBPAY;
- production deployment.

Do not create routes or links for these future stages.

## Constraints

- Follow `WORKFLOW.md` and `AGENTS.md`.
- Reuse accepted Stage 3 views/components.
- Preserve Stage 4 auth/access behavior.
- Preserve Stage 5 admin CRUD/document behavior.
- Psychologist profile routes use current authenticated user, never a user ID.
- Owner document access must be explicit and IDOR-safe.
- Private files remain outside public web root.
- No alternate frontend or redesign.
- Tests remain MySQL-only.
- No Node/npm/Vite or new frontend framework.
- No new dependency unless absolutely required; none is expected.
- No secrets or real personal data.
- Do not alter `.ai/task.md`.

## Acceptance Criteria

1. Real psychologist navigation contains “Мои группы”, “Мои данные”, and POST logout.
2. `GET /profile` is protected by active psychologist access rules.
3. Psychologist sees only the authenticated user’s questionnaire data.
4. No user ID is accepted/needed for the profile route.
5. Profile remains read-only; no profile update endpoint is introduced.
6. Real profile uses approved `psychologist.profile.show` and shared profile/document partials.
7. Psychologist document list contains only own documents.
8. Psychologist can view own private PDF/JPEG/PNG through Laravel.
9. Psychologist can download own private documents through Laravel.
10. Another psychologist’s document ID returns no file content and is not exposed.
11. Admin cannot use psychologist-only profile/document routes; existing admin routes remain functional.
12. Psychologist has no upload/delete document action.
13. No private path or direct public URL is exposed.
14. Missing file/disallowed stored MIME fails safely.
15. Root “Мои группы” remains empty and no real group query/CRUD is introduced before Stage 7.
16. Admin access boundaries remain unchanged.
17. Disabled/rejected/deleted access revocation remains unchanged.
18. Real profile works at 1440/1024/390 without horizontal overflow.
19. Prototype profile variants and all 31/249 prototype catalogue entries remain green.
20. Stage 5 admin CRUD/document tests remain green.
21. Full MySQL test suite passes.
22. Pint passes.
23. Larastan passes.
24. `composer check-platform-reqs` passes.
25. Blade compilation passes.
26. Documentation matches actual Stage 6 behavior.
27. Final diff is limited to Stage 6 psychologist profile/document read access, necessary shared refactor, tests/docs, and `.ai/report.md`.

## Verification Commands

Run and report exact results.

1. Confirm Docker services healthy.
2. Login with local psychologist:
   - `psychologist@gruppa.test` / `password`.
3. Verify real HTTP/browser flows:
   - `/cabinet/` shows empty groups;
   - navigation opens `/cabinet/profile`;
   - profile shows current account data;
   - own document View/Download works;
   - no edit/upload/delete controls.
4. Create/attach synthetic test document through existing admin flow, then verify owner can see/view/download it.
5. Verify a second psychologist cannot access that document by guessed ID.
6. Verify administrator receives 403 on psychologist-only profile/document routes.
7. Verify psychologist still receives 403 on admin routes.
8. Verify no group query/CRUD route was introduced.
9. Verify mobile rendering at 390px and no horizontal overflow.
10. Run:
   - `docker compose exec -T php php artisan test`
   - `docker compose exec -T php ./vendor/bin/pint --test`
   - `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
   - `docker compose exec -T php composer check-platform-reqs`
   - `docker compose exec -T php php artisan view:cache`
11. Inspect route list and production route isolation.
12. Inspect HTML/headers for private path leakage.
13. Inspect `git diff`, `git status --short`, and staged files.
14. Confirm no uploaded test files, .env, real personal data, screenshots, secrets, or temporary artifacts are staged.

## Hard Workflow Gate

Before changing files:

- read `WORKFLOW.md`, `AGENTS.md`, `SPEC.md`, `docs/project-status.md`, `docs/ui-pages.md`, and this task;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm base commit `2f82b0e525f5c8633d79c2a69d7dab1922314ab0`;
- inspect Stage 3 profile/documents views and Stage 4/5 access/document code;
- do not overwrite unknown local changes.

During implementation:

- stay strictly in Stage 6;
- do not implement real group functionality;
- do not add profile/document mutations for psychologist;
- use owner-scoped document access;
- preserve admin document routes;
- preserve prototype fixtures/no-op behavior;
- preserve accepted visual design;
- do not alter `.ai/task.md`;
- do not change governance/spec files.

Before commit:

- run all required checks;
- perform real owner profile/document HTTP/browser smoke verification;
- update `.ai/report.md` with routes, authorization, owner-scoping, shared-view changes, tests/runtime verification, facts/assumptions/unknowns;
- inspect full diff and staged files;
- stage only Stage 6 files plus `.ai/report.md`;
- ensure no private uploads/runtime artifacts are staged.

Completion:

- use `Status: done` only if all acceptance criteria are satisfied;
- otherwise use `partial`, `blocked`, or `failed`;
- if complete, commit with:

```text
codex: TASK-2026-09-21-04 connect psychologist profile cabinet
```

- do not create an `accept:` commit.
