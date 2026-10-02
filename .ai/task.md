# Task: TASK-2026-10-02-01

Status: planned
Created from: a632e01cc5776c57d568d9592196782a18203d2b (main)

## Title

Add self-service password recovery and reusable admin password-link sending

## Goal

Close the current password-recovery gap without introducing a second token system.

A psychologist who can normally use the Cabinet must be able to recover access
without contacting an administrator:

1. open the login page;
2. choose “Forgot password?”;
3. submit the account email;
4. receive a one-time email link;
5. set a new password;
6. log in with the new password.

An administrator must also be able to send the same password-link flow from the
psychologist card at any time while that psychologist is currently eligible to
use the Cabinet, regardless of whether the psychologist has never set a password
or already has one.

Reuse the existing Laravel password-broker/token infrastructure from Stage 12.
Do not create a custom reset-token table or a parallel password-reset mechanism.

## Facts

- Current main HEAD is
  `a632e01cc5776c57d568d9592196782a18203d2b`.
- Existing first-password flow uses:
  - `PasswordSetupService`;
  - Laravel `PasswordBroker` + `DatabaseTokenRepository`;
  - existing `password_reset_tokens`;
  - configurable `password_setup_link_ttl_hours`;
  - `SendPasswordSetup` database-queue job;
  - `PasswordSetupMail`;
  - GET `/password/setup/{token}?email=...`;
  - POST `/password/setup`.
- Existing token replacement invalidates the previous link.
- Existing password completion is currently restricted to users whose
  `password === null`.
- Existing admin password action is also restricted to `password === null`.
- Existing login page has no “Forgot password?” path.
- Existing setup Blade page is the approved Stage 3 password page.
- Existing mail transport rules allow only SMTP/sendmail for password links and
  use the database queue.
- Password setup/recovery URLs and tokens are sensitive and must not be logged.
- `SessionInvalidator` already deletes the target user’s database sessions and
  rotates `remember_token`.
- Account login eligibility is currently:
  - non-admin psychologist;
  - status `approved`;
  - not disabled;
  - not soft-deleted.
- No migration is expected to be necessary.
- Do not touch unrelated MODX, WEBPAY, intake, route-405, HTTPS redirect, or
  production private artifacts.

## Product Decisions

### Eligible account

A password link may be issued for a psychologist only when the account is:

- non-admin;
- `approved`;
- enabled;
- not soft-deleted.

Password presence is **not** an eligibility condition anymore.

Thus both of these are supported:

- first password: `password === null`;
- password recovery/replacement: `password !== null`.

Pending, rejected, disabled, deleted and admin accounts do not receive a usable
password link.

“Admin can send at any time” means at any time for a currently login-eligible
psychologist, including when a password is already configured. It does not bypass
account access restrictions.

### Public privacy semantics

The public recovery request must not reveal whether an email exists, whether the
account is approved/enabled, or whether a password is currently configured.

After a syntactically valid request, return the same generic success state for:

- an eligible existing account;
- unknown email;
- pending/rejected/disabled/deleted/admin account.

Only the eligible account receives a token/job/email.

Do not expose different HTTP status, copy, redirect target, timing-dependent
application state, or validation detail that intentionally identifies account
existence.

Normal malformed-email validation may be shown because it describes request
syntax, not account existence.

### Link/token semantics

Use the same Laravel broker repository and configured TTL as the first-password
flow.

Issuing a new link from any source:

- public recovery;
- admin action;
- automatic post-approval invitation;

must replace the previous broker token for that email, making older links invalid.

The link remains one-time. Successful password submission deletes the token.

### Successful password change

On successful completion:

1. validate current account eligibility again under the existing safe boundary;
2. validate the one-time broker token;
3. hash/store the new password via the existing model cast;
4. invalidate all existing sessions for that user;
5. rotate remember-token state through the existing session invalidation service;
6. delete the broker token;
7. do not auto-login.

Other users’ sessions must remain untouched.

## Scope

### 1. Generalize the existing password-link domain service

Refactor the existing Stage 12 password setup implementation surgically so that
the same broker flow supports both first-password setup and recovery.

Do not add a second broker or token storage.

The shared account/link eligibility must no longer require `password === null`.

Preserve:

- approved/enabled/non-admin/non-deleted guards;
- configured TTL;
- one-time tokens;
- token replacement;
- database queue;
- SMTP/sendmail transport restrictions;
- safe logging;
- base-path-safe URLs;
- no auto-login.

Keep naming changes minimal. A broad rename/refactor of Stage 12 is not required
if the existing service can remain understandable with focused methods.

### 2. Add self-service recovery request page

Add a public recovery request route and page using the existing public auth
layout, tokens and Blade components.

Preferred route shape:

- GET `/password/forgot` — form;
- POST `/password/forgot` — request link.

Use stable Laravel route names under the existing `password.*` namespace so
the existing password exception redaction/rendering boundary still applies.

The page must include:

- page title for password recovery;
- email input;
- submit action;
- link back to login;
- validation state;
- generic sent/success state;
- rate-limit state.

The login page must expose a visible “Забыли пароль?” link.

Do not redesign the login page or create a new visual language.

### 3. Public recovery request behavior

For a valid email-shaped request:

- normalize using the same account-email semantics already used by auth;
- look up a possible user without exposing the result;
- if eligible, issue/replace the broker token and queue the password email;
- if ineligible or unknown, perform no password mutation and queue no email;
- return the same generic success message in either case.

Suggested user-facing meaning:

“Если аккаунт с таким email доступен для восстановления, мы отправили ссылку для установки нового пароля.”

Do not claim that an email definitely exists or was definitely sent.

Queue insertion / token replacement for an eligible user must retain the current
transactional safety properties of the Stage 12 invite flow.

Infrastructure failure for an eligible request must not leak account existence.
Use the same generic public result; log only safe technical context without
recipient/token/URL/body.

### 4. Rate limiting / abuse protection

Add explicit public recovery throttling.

Protect at least:

- by client IP;
- by normalized email-derived key, without logging/storing plaintext email in
  application diagnostics.

A practical target is:

- a small per-minute IP allowance for form submissions;
- no more than one issued request per minute for the same normalized email.

Use existing Laravel rate-limiter patterns.

Do not weaken the existing:

- password link GET/POST limiter;
- admin resend limiter;
- login limiter.

Do not return an account-existence-specific throttle result.

### 5. Reuse the existing password form for both first setup and recovery

The current `auth/password.blade.php` must work for a user who already has a
password.

Update its copy so it does not falsely claim that every operation is a “first
login”.

The normal form should clearly ask for a new password.

The invalid/expired state must tell the user that they may request another link
themselves from password recovery, rather than saying that only the administrator
can help.

Keep:

- email;
- token;
- new password;
- confirmation;
- existing password requirements;
- invalid/expired state;
- success state;
- login link;
- no auto-login.

### 6. Password email copy

Reuse the existing queued password-link mail path.

The email must be truthful for both:

- first-password onboarding;
- later recovery/reset.

It may either:

- use purpose-specific subject/body selected when the link is issued; or
- use one neutral password-link copy that is correct for both cases.

It must not falsely say “your questionnaire was just approved” for a later reset.

For a recovery-capable message, include the meaning:

- a link was created to set a new Cabinet password;
- the link is one-time;
- show configured TTL;
- if the recipient did not expect the message, they can ignore it;
- merely receiving/opening the email does not change the password.

Do not include passwords.

### 7. Admin action

Keep the action on the existing psychologist detail page and existing security
boundaries.

The administrator must be able to send a new password link for any currently
eligible psychologist even if `password !== null`.

The action must:

- use CSRF;
- require admin/account middleware and policy;
- retain the per-admin/target rate limiter;
- replace the prior token;
- queue mail asynchronously;
- never set a password directly;
- never expose the token in admin UI or logs.

Update button/copy from “first password setup” semantics to a truthful generic
meaning such as “Отправить ссылку для нового пароля”.

Update audit semantics so newly generated events do not falsely imply “first
password setup” when the user already had a password. Historical existing audit
action values must remain renderable; no migration/rewrite of old audit rows.

### 8. Successful reset invalidates existing sessions

Integrate the existing `SessionInvalidator` into password completion.

Prove that:

- all database sessions for the reset user are removed;
- `remember_token` rotates;
- another user’s sessions remain;
- the new password works;
- the old password no longer works.

This must also be safe for first-password setup where no prior authenticated
session normally exists.

### 9. New approved UI state

This feature necessarily adds one new public auth page that was not in the
original Stage 3 page catalogue.

Use the existing Blade/layout/component system and add development/testing
prototype coverage for the new page instead of inventing a parallel HTML mock.

Cover at least:

- normal;
- validation;
- sent/success;
- rate-limit.

Update the prototype/page catalogue and counts as required by the repository’s
existing catalogue conventions.

Do not redesign unrelated approved pages.

### 10. Preserve initial onboarding

Approval of a new eligible psychologist with `password === null` must still
queue the initial password link after commit.

Existing guarantees remain:

- approval is not rolled back by later mail infrastructure failure;
- stale/replaced queued token jobs become no-ops;
- configured TTL applies;
- initial password may still be set from the emailed link;
- login succeeds afterward.

## Tests

Add/update focused tests for all of the following.

### Public recovery

- login page contains “Forgot password?” link;
- GET recovery page renders approved real Blade UI;
- eligible approved/enabled psychologist with existing password can request a link;
- approved/enabled psychologist with null password can also request a link;
- unknown email gets the same public success state and queues nothing;
- pending, rejected, disabled, deleted and admin emails get the same success state
  and queue nothing;
- malformed email gets only syntax validation;
- public result does not expose whether an account exists;
- repeated issuance replaces the prior token;
- old link becomes invalid;
- newest link remains valid;
- rate limiting works by IP and normalized email;
- case-normalized email behavior matches login/account lookup behavior;
- infrastructure/queue failure does not disclose account existence.

### Link completion

For an account with an existing password:

- GET valid link renders the password form;
- POST valid token + confirmed password changes the password;
- old password stops authenticating;
- new password authenticates;
- token cannot be reused;
- expired/invalid link is rejected;
- disabled/rejected/deleted state after issuance invalidates the flow;
- no auto-login occurs;
- target user sessions are deleted;
- target remember token changes;
- unrelated user sessions remain.

Retain first-password setup coverage.

### Admin

- admin action is available for eligible `password === null`;
- admin action is also available for eligible `password !== null`;
- non-admin cannot call it;
- ineligible account states remain blocked;
- CSRF/form request/policy remain enforced;
- new action replaces an older public/admin token;
- queue failure returns safe admin error;
- audit entry contains no token/email/body/URL;
- historical `user.password_setup_resent` display remains supported if a new audit
  action name is introduced.

### Mail / security / deployment semantics

- queued mail contains a base-path-safe password URL;
- production APP_URL generates
  `https://gruppa.info/cabinet/...`;
- recovery/reset email copy is truthful for a previously configured password;
- no password/token/URL/recipient is written to application diagnostic logs;
- unsupported mail transports stay rejected;
- tests make no real SMTP/sendmail request.

### Regression

Re-run existing complete:

- PasswordSetupTest;
- AuthenticationTest;
- admin psychologist workflow/policy tests;
- prototype tests;
- session invalidation tests;
- mail/deployment preflight tests affected by the copy/flow.

## Documentation

Update implemented-state documentation at least in:

- `SPEC.md`;
- `docs/email.md`;
- `docs/project-status.md`;
- `docs/ui-pages.md`;
- `docs/architecture.md` / `docs/development.md` / `docs/deployment.md` where
  current password-flow or operational statements require correction.

Documentation must state:

- self-service forgot-password now exists;
- public request does not disclose account existence;
- the same Laravel broker/token table is reused;
- admin may send a link for an eligible account regardless of existing password;
- issuing a newer link invalidates the older link;
- successful password change invalidates prior sessions;
- initial onboarding remains supported;
- password-link mail remains queued through database queue and SMTP/sendmail;
- no plaintext password/reset token belongs in logs.

Correct stale documentation that currently explicitly states no forgot-password
or password replacement exists.

Do not perform unrelated documentation cleanup.

## Out Of Scope

Do NOT:

- add a custom token table;
- add SMS/phone recovery;
- add security questions;
- let an administrator assign or see a password;
- email plaintext/generated passwords;
- auto-login after reset;
- allow pending/rejected/disabled/deleted users to bypass account restrictions;
- change password complexity beyond the existing 8–255 confirmed rule unless
  required by an existing shared validator;
- change login authentication semantics unrelated to recovery;
- change MODX, WEBPAY, integration intake or group lifecycle behavior;
- read/change/commit production private env files such as `.env_save`;
- make a real mail send to a production recipient;
- add Node/Vite/packages;
- run `migrate:fresh`;
- create an `accept:` commit.

## Acceptance Criteria

1. Login page has a working self-service “Forgot password?” entry point.
2. An eligible psychologist with an existing password can request a one-time
   recovery link without administrator involvement.
3. Unknown/ineligible emails receive the same generic public response and no
   account existence is disclosed.
4. The recovery link uses the existing Laravel broker/token repository and
   configured TTL; no new token store exists.
5. A valid link can replace an already configured password.
6. Issuing a newer link invalidates the older link.
7. Successful password replacement invalidates all existing sessions for that
   user, rotates remember state and leaves other users’ sessions untouched.
8. The administrator can issue a new password link for any currently eligible
   psychologist regardless of whether the password is null or already configured.
9. Existing first-password onboarding after approval still works.
10. Mail remains queued and safe; no password/token/URL/recipient is leaked to
    application diagnostics.
11. New public UI uses the approved auth layout/components and has prototype
    state coverage.
12. Full regression/static checks pass and no unrelated behavior changes.

## Checks

Run and report exact results for:

1. focused password recovery/setup tests;
2. full `PasswordSetupTest`;
3. full `AuthenticationTest`;
4. affected admin psychologist/policy/session tests;
5. full `PrototypeTest`;
6. affected mail/preflight tests;
7. full MySQL test suite;
8. Pint;
9. PHPStan;
10. composer check-platform-reqs;
11. composer validate --no-check-publish;
12. artisan view:cache;
13. artisan route:list (verify recovery/setup/admin routes);
14. artisan schedule:list;
15. git diff --check;
16. final status/diff/staged secret/artifact review.

Do not make a real external email delivery call as part of automated acceptance.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and its parent is
  `a632e01cc5776c57d568d9592196782a18203d2b`;
- read `WORKFLOW.md`, `AGENTS.md`, this task and the previous report;
- inspect current PasswordSetupService/controller/job/mail, auth routes, login and
  password Blade, UserPolicy, PsychologistActions, SessionInvalidator,
  PasswordSetupTest, AuthenticationTest, docs/email.md and prototype catalogue;
- verify the local tree is clean/known;
- do not touch unknown local changes.

During implementation:

- work only within this task;
- do not edit `.ai/task.md`;
- preserve the single Laravel broker/token storage;
- preserve generic account-existence-safe public behavior;
- keep mail queued;
- never log password/token/link/recipient;
- preserve existing account eligibility boundaries;
- invalidate target sessions only after successful password completion;
- keep UI changes within approved auth components/layout;
- avoid unrelated refactors.

Before commit:

- run all required checks;
- inspect complete diff and staged files;
- verify no secrets, production data, env/private files, mail captures, queue
  payload dumps, storage/cache/log/vendor or temporary artifacts are staged;
- update `.ai/report.md` factually;
- explicitly state that no real external mail was sent.

If complete, commit with:

`codex: TASK-2026-10-02-01 add password recovery`

Do not create an accept commit.
