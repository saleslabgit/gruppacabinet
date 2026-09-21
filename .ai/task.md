# Task: TASK-2026-09-21-02

Status: planned
Created from: 04395636b1eb34d9754b5b3eae2198f77120f913 (main)

## Title

Stage 4 — Implement session authentication, role boundaries, access revocation, and authenticated application entry points

## Goal

Implement the complete Stage 4 authentication/access foundation from `SPEC.md` on top of the accepted Stage 2 domain model and accepted Stage 3 Blade interface.

This task must provide:

- real Laravel session authentication on guard `web`;
- the real login/logout flow using the approved Stage 3 login/navigation UI;
- protected psychologist and administrator entry points;
- per-request account-state validation;
- role boundaries;
- database-session invalidation support for later administrative actions;
- login throttling;
- local/testing credentials for one approved psychologist in addition to the existing development administrator.

This is an authentication/access milestone only. Do not start psychologist/admin CRUD, group CRUD, password invitation email, public API, or WEBPAY work.

## Facts

- Stage 2 domain foundation is complete.
- Stage 3 frontend and its visual revision are accepted by the product owner as the baseline for backend integration.
- Current HEAD is `04395636b1eb34d9754b5b3eae2198f77120f913`.
- The application uses Laravel 12 / PHP 8.2 / MySQL.
- The default auth guard is already the Laravel session guard `web` backed by `App\Models\User`.
- `gp_users` contains:
  - nullable password;
  - `remember_token`;
  - lifecycle `status`;
  - independent `disabled`;
  - independent `admin`;
  - soft delete.
- User status values are `pending`, `approved`, and `rejected`.
- Only an active, non-deleted, `approved`, `disabled=false` user may access protected application routes.
- `admin=true` identifies administrators; approved non-admin users are psychologists.
- Database session storage and the `sessions` table already exist.
- Normal local configuration already uses `SESSION_DRIVER=database`.
- Stage 3 already contains the final login, psychologist, and admin layouts/views. Backend integration must reuse them rather than creating alternative visual implementations.
- Prototype routes must remain local/testing only and continue to work with synthetic data.
- The current application root `/` still serves the old Stage 1 diagnostic page and must now become the protected psychologist application root.
- Production base URL remains `https://gruppa.info/cabinet/`; local base URL remains under `/cabinet/`.

## Product Behavior

### Login

Real login page:

`GET /login`

Real login submission:

`POST /login`

Use the existing `resources/views/auth/login.blade.php`.

The production login form must:

- submit to the Laravel login endpoint;
- include CSRF protection;
- use server-side validation;
- use the existing approved field/error/alert areas;
- not create a parallel login template.

Authentication identifier is email.

A successful login redirects:

- administrator → `/admin`;
- psychologist → `/`.

Do not add a “remember me” option in this stage because it is not part of the approved UI/spec.

### Generic login failure

Pre-authentication failures must not disclose whether an email exists or whether an account is pending/rejected/disabled.

Use the same generic authentication failure message for:

- nonexistent email;
- wrong password;
- `pending`;
- `rejected`;
- `disabled=true`;
- soft-deleted account.

The existing prototype-specific disabled/error variants may remain for visual catalog coverage, but the real login flow must not reveal account state before authentication.

### Psychologist entry point

The real application route:

`GET /`

is psychologist-only and corresponds to:

`https://gruppa.info/cabinet/`

in production.

At Stage 4 it must reuse the approved psychologist layout/view and provide a truthful authenticated shell without inventing later functionality.

Use the approved “Мои группы” view as the psychologist landing surface.

Because group CRUD is Stage 7:

- do not query or implement the real group workflow yet;
- render the approved empty-state form of the page;
- do not present an active “Добавить группу” action that points to an unimplemented or prototype-only route;
- adapt the existing view through explicit availability data/props rather than creating a new alternative page.

The Stage 6 task will connect real psychologist profile/documents; Stage 7 will connect real groups/create actions.

### Administrator entry point

Real admin route:

`GET /admin`

is administrator-only and corresponds to:

`https://gruppa.info/cabinet/admin`

in production.

Reuse the approved admin layout and `admin.home` view.

Do not fabricate business work-queue counts or link production users to prototype routes.

If the actual Stage 5+ work-queue data is not yet implemented, adapt the existing admin home view to render a truthful Stage 4 authenticated-shell state using injected data/state, while preserving the prototype catalog’s synthetic work-queue version.

Do not create a parallel admin dashboard.

### Logout

Real logout:

`POST /logout`

Requirements:

- POST only;
- normal Laravel CSRF protection;
- call Laravel logout;
- invalidate the current session;
- regenerate the CSRF token;
- redirect to the login page;
- `GET /logout` must not perform logout.

Use the existing approved navigation components.

Prototype navigation must remain no-op.

Real authenticated navigation must render a real POST logout form/control without duplicating the navigation layout.

## Scope

### 1. Authentication controller/request foundation

Implement a small conventional Laravel authentication layer.

Prefer:

- one session/auth controller;
- one dedicated login Form Request or equivalent explicit request class;
- normal Laravel `Auth` / guard APIs.

Do not install Breeze, Jetstream, Fortify, or another auth package merely for this task.

The login request must validate at least:

- email;
- password.

Do not add registration fields.

### 2. Login throttle

Add an explicit login rate limit.

Use a simple deterministic policy:

- maximum 5 failed attempts per 60 seconds;
- key by normalized email + client IP;
- clear the throttle counter after successful authentication.

When throttled:

- do not attempt authentication;
- do not create a session;
- render/redirect to the approved login UI with the rate-limit state/message;
- do not disclose whether the account exists.

Cover the threshold and reset behavior with tests.

### 3. Credential/access check

A successful credential check requires all of:

- active non-soft-deleted User;
- valid password;
- `status=approved`;
- `disabled=false`.

The authentication response must not distinguish which precondition failed.

Do not use compatibility `accept` as an auth condition.

Use `status` as the lifecycle truth.

### 4. Session security

On successful login:

- regenerate the session ID;
- clear the login throttle key;
- establish the authenticated guard session.

On logout:

- logout;
- invalidate session;
- regenerate CSRF token.

Add tests proving session fixation protection / session ID regeneration.

### 5. Per-request account access middleware

Add reusable middleware that runs on every protected psychologist/admin request after authentication.

It must verify the authenticated User is still:

- present/active;
- `status=approved`;
- `disabled=false`.

If an already-authenticated account becomes ineligible:

- revoke access immediately on the next protected request;
- terminate the current authenticated session;
- redirect to login with a safe message that access is no longer available;
- do not continue rendering protected content.

A pre-auth login failure remains generic; the revoked-session message is allowed because the user had already authenticated previously.

### 6. Role middleware / boundaries

Add explicit role boundaries.

Psychologist routes:

- require authenticated active approved user;
- require `admin=false`.

Admin routes:

- require authenticated active approved user;
- require `admin=true`.

Expected behavior:

- psychologist requesting `/admin` → HTTP 403;
- administrator requesting psychologist-only `/` → HTTP 403;
- guest requesting either protected route → redirect to login.

Do not introduce a general roles/permissions package.

### 7. Reusable SessionInvalidator

Implement a small reusable service for immediate access revocation in later admin tasks.

It must accept a user and:

- delete all database session records belonging to that user;
- rotate/invalidate the user’s `remember_token` so existing recaller credentials cannot remain valid;
- not delete sessions for other users.

This service will be reused by Stage 5 when disabling/rejecting/deleting psychologists.

Do not wire Stage 5 admin actions in this task.

Add direct MySQL-backed tests proving:

- all sessions for target user are removed;
- sessions for another user remain;
- remember token changes/is invalidated.

### 8. Access-state revocation tests

Cover existing authenticated sessions when the user changes state.

At minimum test:

- `approved → disabled=true`: next protected request loses access;
- approved account changed to `rejected` directly for test setup: next protected request loses access;
- soft-deleted account: next protected request does not receive protected content;
- normal approved account continues to work.

Do not add an invalid `approved → rejected` domain transition to the state machine; tests may update persistence directly to simulate externally changed access state because that transition is intentionally not a normal product workflow.

### 9. Routes

Implement stable named routes for real auth/access:

- `GET /login` — `login`;
- `POST /login` — a clear login submission route name;
- `POST /logout` — `logout`;
- `GET /` — psychologist home;
- `GET /admin` — admin home.

The auth middleware’s guest redirect must resolve correctly through the application base path.

All generated real URLs/actions must include `/cabinet` under the configured base URL.

Do not expose production routes that point into `/_prototype`.

### 10. Stage 1 diagnostic route cleanup

The root `/` can no longer be the public Stage 1 foundation page.

Preserve the diagnostic only if still useful by moving it to a clearly technical route available only in `local`/`testing`, for example:

- `/_foundation`.

The old diagnostic and redirect-check routes must not conflict with real production auth routes.

Update affected Stage 1 tests accordingly.

Do not expose a database-connectivity diagnostic publicly in production.

### 11. Approved Stage 3 UI integration

Reuse existing UI files.

#### Login view

Adapt the existing login view so:

- prototype requests remain no-op;
- real requests use POST + CSRF;
- real validation/auth/rate-limit errors appear in the approved UI;
- there is still one shared login markup structure.

#### Button component

If required, extend the existing button component to support semantic button types such as `submit` without breaking prototype buttons.

#### Navbar/sidebar

Adapt existing shared navigation so:

- prototypes keep the existing no-op “Выход” control;
- authenticated production views use the real POST logout action;
- no second production navigation template is introduced.

#### Psychologist home

Reuse `psychologist.groups.index`.

Make later-stage actions explicitly unavailable rather than linking authenticated users into prototype routes.

#### Admin home

Reuse `admin.home`.

Prototype fixture mode must retain its full synthetic work queue.

Real Stage 4 mode must not show fake counts or prototype URLs.

Do not perform a new visual redesign during backend integration.

### 12. Local/testing psychologist seed

The existing development administrator remains:

- email: `admin@gruppa.test`;
- password: `password`.

Add one idempotent local/testing psychologist:

- email: `psychologist@gruppa.test`;
- password: `password`;
- `status=approved`;
- `admin=false`;
- `disabled=false`.

Requirements:

- use normal Laravel password hashing;
- do not set compatibility `accept` manually;
- let existing status mapping derive it;
- do not create known-password users in production;
- repeated seeding must not duplicate the account.

Do not add product passwords for manually created real users; that belongs to later onboarding stages.

### 13. Documentation

Update actual-state documentation:

- `docs/architecture.md` — auth/access middleware boundary and session invalidator;
- `docs/development.md` — local login URLs and development credentials;
- `docs/project-status.md` — Stage 4 implemented state and remaining Stage 5+ scope.

Update `docs/ui-pages.md` only if required to explain real-route wiring; avoid unrelated design churn.

## Explicit Out Of Scope

Do not implement:

- public registration;
- external psychologist questionnaire intake;
- password invitation/setup broker flow;
- password reset;
- SMTP/email;
- admin psychologist CRUD;
- approve/reject UI actions;
- tariff changes;
- document upload/download;
- “Мои данные” real backend;
- psychologist profile route/data;
- real group list or group create/edit;
- group moderation;
- applications;
- dictionary/settings mutations;
- public-site API;
- scheduler/jobs business logic;
- WEBPAY;
- production deployment.

Do not add temporary product passwords beyond the documented local/testing seed accounts.

Do not create production links to unimplemented Stage 5–7 actions.

Do not change `SPEC.md`, `WORKFLOW.md`, or `AGENTS.md`.

## Constraints

- Follow `WORKFLOW.md` and `AGENTS.md`.
- Use the accepted Stage 3 views/components; no parallel auth/admin/psychologist UI.
- Keep authentication conventional Laravel session auth.
- Use database sessions.
- Keep authorization simple; no permissions framework.
- `status`, not `accept`, is the access lifecycle source of truth.
- Never reveal account existence/status through login failure messages.
- Preserve prototype routes and all 249 Stage 3 variants.
- Preserve the accepted Stage 3 visual design.
- Preserve `/cabinet` base-path compatibility.
- Tests remain on MySQL in Docker.
- No Node/npm/Vite.
- No new external authentication package.
- No secrets or production credentials.
- Do not alter `.ai/task.md`.

## Acceptance Criteria

1. `GET /login` renders the approved login Blade UI.
2. Valid development psychologist credentials authenticate and redirect to `/`.
3. Valid development administrator credentials authenticate and redirect to `/admin`.
4. Successful login regenerates the session ID.
5. Invalid email/password creates no authenticated session and shows the same generic failure.
6. `pending`, `rejected`, `disabled`, and soft-deleted accounts cannot log in and receive no account-existence/status disclosure.
7. Login is throttled after 5 failed attempts per email+IP within 60 seconds.
8. Successful login clears the relevant throttle counter.
9. Guest access to `/` and `/admin` redirects to the real login route inside the cabinet base path.
10. Psychologist access to `/admin` returns 403.
11. Administrator access to psychologist-only `/` returns 403.
12. An approved enabled psychologist can access `/`.
13. An approved enabled administrator can access `/admin`.
14. If an authenticated user becomes disabled, rejected, or soft-deleted, the next protected request no longer renders protected content.
15. The reusable SessionInvalidator deletes all database sessions for the target user and leaves other users’ sessions intact.
16. SessionInvalidator invalidates/rotates the target user’s remember token.
17. `POST /logout` logs out, invalidates the current session, regenerates CSRF token, and redirects to login.
18. `GET /logout` does not log the user out and is not an allowed logout endpoint.
19. The real psychologist root reuses the approved “Мои группы” view in a truthful Stage 4 empty/unavailable-action state.
20. The real admin root reuses the approved admin home/layout without fake counts and without prototype URLs.
21. Real navigation uses POST logout; prototype navigation remains no-op.
22. Existing `/_prototype` catalog and all 249 variants still render.
23. Prototype routes remain absent in production.
24. The old Stage 1 root diagnostic no longer occupies `/`; any retained diagnostic is local/testing only.
25. Development/testing seed creates exactly one approved psychologist and the existing admin, with known credentials only outside production.
26. No Stage 5+ CRUD/profile/group/payment/email/API behavior is introduced.
27. Existing Stage 1–3 tests remain green after necessary route/test updates.
28. Full MySQL test suite passes.
29. Pint passes.
30. Larastan passes.
31. `composer check-platform-reqs` passes.
32. Blade compilation passes.
33. Documentation reflects the actual Stage 4 implementation.
34. Final diff is limited to auth/access foundation, necessary approved-view integration, tests/docs, and `.ai/report.md`.

## Required Tests

Add focused feature/integration coverage for at least:

### Authentication

- psychologist successful login;
- admin successful login;
- session ID regeneration;
- wrong password;
- unknown email;
- pending user;
- rejected user;
- disabled user;
- soft-deleted user;
- generic identical auth failure semantics;
- rate-limit threshold;
- rate-limit clear after success.

### Route boundaries

- guest → login for `/`;
- guest → login for `/admin`;
- psychologist → `/` 200;
- psychologist → `/admin` 403;
- admin → `/admin` 200;
- admin → `/` 403.

### Access revocation

- authenticated then disabled;
- authenticated then rejected;
- authenticated then soft-deleted;
- approved/enabled unchanged remains authenticated.

### Session invalidation

Use the real MySQL `sessions` table to prove target-user sessions are deleted without deleting another user’s rows and that remember token is invalidated.

### Logout

- POST logout;
- guest state after logout;
- current session invalidated;
- GET logout not accepted.

### UI / base path

- login form uses real POST action in non-prototype mode;
- prototype login remains no-op;
- real navigation renders a POST logout form;
- prototype navigation remains no-op;
- generated login/logout/home/admin URLs respect `/cabinet`;
- psychologist Stage 4 root does not expose an active create-group link to a prototype/unimplemented route;
- admin Stage 4 root contains no prototype URL/fake fixture count.

### Regression

- 31 prototype groups / 249 variants remain;
- production has no prototype routes;
- existing domain/MySQL tests remain green.

## Verification Commands

Run and report exact results.

1. Ensure Docker services are healthy.
2. Seed local/testing development accounts idempotently.
3. Verify both real login flows manually through the real Docker HTTP runtime:
   - psychologist login → `/cabinet/`;
   - admin login → `/cabinet/admin`.
4. Verify logout through the real UI.
5. Verify denied role boundary in browser or equivalent HTTP runtime.
6. Run:
   - `docker compose exec -T php php artisan test`
   - `docker compose exec -T php ./vendor/bin/pint --test`
   - `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
   - `docker compose exec -T php composer check-platform-reqs`
   - `docker compose exec -T php php artisan view:cache`
7. Inspect route list and confirm:
   - real auth routes exist;
   - logout is POST-only;
   - protected routes have intended middleware;
   - production route list has no `_prototype`;
   - production route list has no public Stage 1 DB diagnostic.
8. Verify database-session invalidator against MySQL.
9. Verify no login response distinguishes nonexistent/pending/rejected/disabled users.
10. Verify no Stage 5+ routes/controllers/actions were added.
11. Inspect `git diff`, `git status --short`, and staged files.
12. Confirm no secrets, real user data, temporary browser files, or unrelated changes are staged.

## Hard Workflow Gate

Before changing files:

- read `WORKFLOW.md`, `AGENTS.md`, `SPEC.md`, `docs/project-status.md`, `docs/ui-pages.md`, and this `.ai/task.md`;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm the current planner task is based on `04395636b1eb34d9754b5b3eae2198f77120f913`;
- inspect the approved Stage 3 login/layout/navigation/psychologist/admin views before modifying them;
- do not overwrite unknown local changes.

During implementation:

- implement only authentication/access foundation;
- reuse approved views;
- do not redesign Stage 3;
- do not start Stage 5–7 functionality;
- keep prototype behavior intact;
- do not alter `.ai/task.md`;
- do not change governance/spec files;
- do not add auth packages or frontend build tooling.

Before commit:

- run all required checks;
- manually verify the real psychologist/admin login/logout flows under `/cabinet`;
- update `.ai/report.md` with exact implementation, routes, middleware, tests, runtime checks, facts, assumptions, unknowns, and next step;
- inspect full diff and staged files;
- stage only task-related files plus `.ai/report.md`;
- confirm no secrets or unrelated artifacts are staged.

Completion:

- use `Status: done` only if the authentication/access acceptance criteria are fully satisfied;
- otherwise use `partial`, `blocked`, or `failed`;
- if complete, commit with:

```text
codex: TASK-2026-09-21-02 implement authentication access foundation
```

- do not create an `accept:` commit.
