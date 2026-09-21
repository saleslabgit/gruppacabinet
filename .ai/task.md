# Task: TASK-2026-09-21-11

Status: planned
Created from: 7bb1c7410e9e6db2d3225c5b8637681a7459ee8f (main)

## Title

Stage 12 — Implement queued email, one-time password setup, admin resend, and expiry warning notifications

## Goal

Implement Stage 12 from SPEC.md on top of the accepted Stage 11 incoming integration.

This milestone completes the first real email flows:

1. an approved psychologist with no password receives a queued one-time password-setup link;
2. an administrator can resend the setup link, invalidating the previous one;
3. the psychologist sets their own password through the existing password Blade page and can then log in;
4. active group owners receive one queued expiry-warning email per placement period;
5. warning mail failures never break group lifecycle expiration and never mark a warning as sent.

Use Laravel's password-broker/token infrastructure and the existing database queue.

Do not implement WEBPAY or any Stage 13+ behavior.

## Current Base

Use current main HEAD exactly:

`7bb1c7410e9e6db2d3225c5b8637681a7459ee8f`

This includes:

- accepted Stages 1–11;
- current redesigned UI baseline;
- signed incoming API;
- database queue tables;
- Stage 9 expiry lifecycle and `expiry_warning_sent_at`;
- typed settings including:
  - `expiry_warning_days`;
  - `password_setup_link_ttl_hours`.

Do not revert current UI or Stage 11 integration behavior.

## Facts

- `gp_users.password` is nullable and hashed by the User model cast.
- Login already requires approved + enabled account and valid password.
- Login already has rate limiting.
- Stage 11 questionnaire intake creates pending users with password=null.
- Admin approval is implemented in `PsychologistActions`.
- No email is currently sent after approval.
- `auth.passwords.users` exists, but the standard `password_reset_tokens` table has not yet been migrated.
- Existing `auth.password` Blade view/prototype already contains normal, validation, expired/invalid and success states.
- Database queue tables `jobs`, `job_batches`, `failed_jobs` already exist.
- Default queue connection is database.
- Current local Docker has no SMTP capture service or persistent queue worker.
- `gp_groups.expiry_warning_sent_at` exists.
- Stage 9 activation and active free extension reset `expiry_warning_sent_at=null`.
- Stage 9 expiration runs every minute.
- Stage 8 typed setting `expiry_warning_days` is current warning threshold.
- Stage 8 typed setting `password_setup_link_ttl_hours` is the business source of truth for setup-token lifetime.
- Stage 12 must not change group placement/extension semantics.
- Stage 12 must not create or mutate payments.

## Product / Architecture Decisions

### 1. This is setup, not a public forgot-password feature

Stage 12 implements one-time first-password setup for approved psychologists.

Do NOT add:

- a public “forgot password” request form;
- a public email-address reset-request endpoint;
- self-service resend.

Only an administrator can explicitly resend a setup invitation.

The setup page itself is public because the one-time token authenticates that flow.

### 2. Eligibility for password setup

A setup link is valid only for a user who is currently:

- non-admin;
- approved;
- not disabled;
- not soft-deleted;
- password is null.

If account state becomes ineligible after the email was queued/sent, the link must stop working.

If the password is already set, setup/resend is unavailable.

Do not clear or replace an existing password through this flow.

### 3. Laravel Password Broker is mandatory

Use Laravel framework password-broker/token classes.

Do not implement:

- custom random token format;
- custom token hashing;
- plaintext token storage;
- JWT setup links.

Add the standard `password_reset_tokens` table required by Laravel.

Because the authoritative TTL is the typed database setting, do not rely on the static 60-minute value currently present in `config/auth.php`.

Create a small dedicated setup-broker service/factory that uses Laravel's standard `PasswordBroker` / `DatabaseTokenRepository` (or an equivalent framework-supported construction after inspecting the installed Laravel 12 source) with:

`SettingService::passwordSetupLinkTtlHours()`

converted to the framework expiration unit.

Requirements:

- token generation/hashing/verification/deletion is performed by Laravel framework code;
- current typed TTL is read for setup-token operations;
- it works correctly in normal FPM requests and long-running queue workers;
- do not cache a broker forever with stale TTL;
- do not duplicate Laravel token cryptography in application code.

Document the chosen framework construction in `.ai/report.md`.

### 4. TTL semantics

The current `password_setup_link_ttl_hours` setting is authoritative when token validity is checked.

No additional TTL snapshot column is required.

Tests must prove that changing the typed setting affects setup-token validity consistently.

### 5. Setup-link contract

Recommended routes:

- `GET /password/setup/{token}`
- `POST /password/setup`

The email link includes:

- token in the route/path;
- psychologist email as a query parameter.

POST includes:

- token;
- email;
- password;
- password_confirmation.

Do not put token values in application logs, audit metadata or flash messages.

### 6. Password validation

Use server-side validation:

- password required;
- string;
- minimum 8 characters;
- reasonable maximum;
- confirmed.

Do not invent materially stricter password rules in this milestone.

Password is written through the existing hashed User cast / Laravel hashing.

On success:

- set the password;
- rotate remember_token;
- consume/delete the setup token through Password Broker;
- do not auto-login;
- show the existing password success state or redirect to login with a clear success notice.

The consumed link must not work again.

### 7. Setup rate limiting

Add a dedicated setup limiter.

Protect both GET validation and POST submission from brute-force token attempts.

Recommended technical default:

- 10 requests/minute per source IP for the setup routes.

POST may additionally include a non-sensitive hash of normalized email in the limiter key.

Do not use the raw token as a rate-limit key or log value.

Keep existing login throttling unchanged.

### 8. Approval-triggered invitation

When admin successfully performs:

`pending -> approved`

and the psychologist has no password:

- approval/business audit commits first;
- issue a fresh setup token;
- queue the setup email.

Do not make SMTP part of the approval transaction.

No mail should be queued if the approval transition rolls back.

A queue/SMTP infrastructure failure must not revert an already committed approval.

If invitation issue/queue dispatch fails after approval:

- log a safe technical error without token/email/password;
- leave the account approved;
- admin resend remains the recovery path.

Do not send an invitation for an account that already has a password.

### 9. Admin resend action

Add a real protected admin action, recommended:

`POST /admin/psychologists/{psychologist}/password-setup`

Requirements:

- existing account + role:admin middleware;
- policy authorization;
- CSRF;
- explicit Form Request;
- only eligible approved/enabled/non-deleted/non-admin/password-null psychologist;
- rate limit accidental repeated admin sends;
- invalidate/delete any previous setup token first;
- create a fresh token;
- queue a fresh invitation.

The previous link must become invalid immediately after successful resend.

Use a concise secondary action in the current psychologist detail UI.

Suggested wording:

`Отправить ссылку установки пароля`

Do not expose whether a token currently exists.

Once password is set, hide/disable this action truthfully.

### 10. Stale queued invitation protection

A resend may happen while an older invitation job is still queued.

Do not allow the stale job to send an obsolete link later.

The queued invitation job must re-check before sending:

- account still eligible;
- the token carried by this job is still the current valid token.

If the token has been invalidated by resend or the account became ineligible:

- job exits successfully without sending;
- no exception/retry is necessary.

If SMTP sending itself fails:

- let the queue retry according to configured tries/backoff;
- do not create a new token on each retry;
- keep the same link/token for that job.

### 11. Password setup email

Use a queued job plus a dedicated Mailable (or equivalent Laravel queued-mail architecture).

Email must include:

- psychologist-facing Russian subject/body;
- one setup URL;
- link expiration duration in human-readable hours;
- clear statement that the user chooses their own password;
- no password in email;
- no questionnaire/document data.

Generate URLs with Laravel route helpers so production uses:

`https://gruppa.info/cabinet/...`

when APP_URL is production.

No external image/CDN dependency is needed.

### 12. Warning scheduler

Add an explicit command, recommended:

`php artisan groups:queue-expiry-warnings`

Schedule it:

- hourly;
- `withoutOverlapping`.

Keep:

- `groups:expire` every minute unchanged;
- `applications:cleanup` daily unchanged.

The warning scheduler must read `expiry_warning_days` once per run.

Candidate group:

- non-deleted;
- status=active;
- expires_at not null;
- expires_at > now;
- expires_at <= now + current warning-days threshold;
- expiry_warning_sent_at is null.

For email delivery, owner must still be:

- non-admin psychologist;
- approved;
- enabled;
- non-deleted.

A disabled group may be excluded from email delivery, but this must not alter lifecycle expiration and must leave `expiry_warning_sent_at=null` so a later re-enable before expiry can still receive the warning.

Do not query/send per row with N+1 owner lookups.

Process candidates in bounded chunks.

Command output/logging:

- aggregate queued count only;
- no group title;
- no email;
- no owner identity.

### 13. Expiry warning unique period key

Use Laravel queue uniqueness/locking so at most one queued/running warning job exists for one placement period.

The idempotency/unique key must bind at minimum:

- group_id;
- exact current expires_at value.

Recommended:

`group-expiry-warning:{group_id}:{expires_at_utc}`

Use `ShouldBeUnique` (not `ShouldBeUniqueUntilProcessing`) or an equally strong mechanism that retains the uniqueness lock while the job is queued and running.

If the deployment can run multiple app/worker instances, document that the configured cache lock store must be shared.

Do not create a new warning-history DB table unless the queue uniqueness contract cannot be implemented safely with Laravel's lock semantics.

### 14. Warning job re-check

The queued job carries:

- group ID;
- expected expires_at/period identity.

Immediately before sending, re-read current DB state.

Send only if:

- group still exists;
- status still active;
- group is not disabled;
- expires_at exactly matches expected period;
- expires_at is still in the future;
- group is currently inside the current `expiry_warning_days` threshold;
- expiry_warning_sent_at is still null;
- owner is approved/enabled/non-deleted/non-admin.

If any check fails:

- exit successfully;
- do not send;
- do not change marker.

This protects queued stale jobs after extension/republication/account changes.

### 15. Warning email content

Send to the group's current owner.

Email contains only necessary information:

- group title;
- expiration date/time in Europe/Minsk;
- current remaining/warning context;
- protected cabinet link to the group.

Do not include participant data, questionnaire data, documents or payment information.

### 16. Warning marker semantics

`expiry_warning_sent_at` is set only after the mail transport reports successful send.

Required ordering:

1. re-check eligibility;
2. send mail;
3. only after successful send, update marker for the same expected placement period.

If mail throws/fails:

- marker remains null;
- queue retry handles the retry;
- group status/dates are unchanged;
- expiration scheduler remains independent.

Before writing the marker, lock/re-read the group and verify:

- same expected expires_at;
- status active;
- marker still null.

Do not mark a new/extended placement because an old-period email finished late.

### 17. Warning duplicate behavior

Tests must prove:

- scheduler repeated while job is queued -> no duplicate job;
- scheduler repeated while job is running -> no duplicate job;
- successful mail -> marker set once;
- scheduler after successful marker -> no new job;
- SMTP failure -> marker remains null;
- queue retry can send successfully later;
- active extension changes expires_at and resets marker -> new period has a new unique key and can warn again;
- reactivation/republication resets marker -> new period can warn;
- an old queued job from previous expiry cannot set marker/send for the new period;
- if activation puts a group already inside threshold, first applicable scheduler run queues exactly one warning.

### 18. Lifecycle independence

Stage 12 warning delivery must not be required for:

- active -> expired;
- free extension;
- re-publication activation.

Explicitly test:

- SMTP failure does not stop `groups:expire`;
- failed warning job cannot keep an expired group active;
- expiration command does not wait for queue worker/mail.

### 19. Email / queue retry configuration

Use database queue.

Jobs should have explicit practical:

- tries;
- backoff;
- timeout where appropriate.

Avoid infinite retry loops.

Do not put password setup tokens, email bodies or sensitive data in logs.

Laravel database queue payload may necessarily serialize the one-time token for the invitation job; treat the queue database as sensitive application infrastructure and never expose/log its payload.

### 20. Local SMTP development verification

Add a local SMTP capture service to Docker, preferably Mailpit or an equivalent lightweight test SMTP server.

Requirements:

- Docker only; no application runtime dependency;
- pin a concrete image tag, not `latest`;
- SMTP port accessible to PHP container;
- optional web UI exposed only for local development;
- no real SMTP credentials;
- no production dependency on this container.

Configure local Docker PHP/worker environment to use the capture SMTP service.

### 21. Local queue worker

Add a dedicated local queue-worker Compose service or an equally reproducible documented one-command local worker.

Preferred:

- same application image;
- `php artisan queue:work`;
- database queue;
- finite sensible retry/timeout config;
- same mounted application/storage;
- depends on MySQL and local SMTP capture.

Production supervisor/cron configuration remains deployment work.

Do not place Docker files inside `application/`.

### 22. SMTP / queue runtime smoke

Using only synthetic local addresses:

Verify through real Docker services:

#### Password setup

1. Create/identify pending password-null synthetic psychologist.
2. Approve through real admin POST.
3. Confirm one database queue job.
4. Worker processes job.
5. Confirm message appears in SMTP capture.
6. Open actual setup URL from message.
7. Set password.
8. Confirm consumed link fails on reuse.
9. Confirm real login works.

#### Resend

1. Issue initial link.
2. Trigger admin resend.
3. Old link invalid immediately.
4. Old queued job, if still pending, sends nothing.
5. New queued message/link works.

#### Expiry warning

1. Create active group outside threshold -> scheduler queues zero.
2. Move/freeze time inside threshold -> queues one.
3. Re-run scheduler before worker -> still one effective queued job.
4. Worker sends captured warning.
5. Marker set after send.
6. Re-run scheduler -> zero.
7. Simulate SMTP failure -> marker stays null and group expiration still succeeds.

Clean synthetic smoke records/messages/jobs where practical.

### 23. No setup token leakage

Inspect:

- application logs;
- audit metadata;
- failed validation output;
- exception pages;
- flash/session messages.

No raw setup token may appear there.

Do not assert that database queue payload itself contains no token, because a queued invitation job may legitimately need it; instead ensure queue storage is not exposed and token never appears in logs/UI/audit.

### 24. Audit behavior

Keep existing approval audit.

Add a minimal audit event for explicit admin resend, e.g.:

`user.password_setup_resent`

Requirements:

- actor admin;
- entity user ID;
- no email;
- no token;
- no password;
- no link URL.

Update admin history wording for this action.

Automatic approval-triggered invitation does not need a second redundant audit row.

### 25. UI integration

Reuse the current redesigned UI baseline.

Connect the existing real Blade view:

`auth/password.blade.php`

to production routes/data.

Minor changes are allowed only to support real mode:

- CSRF/form action;
- real validation;
- success/expired states;
- current breadcrumbs/return conventions where applicable.

On admin psychologist detail:

- connect the resend action;
- show it only when eligible;
- keep current action hierarchy/icons.

Do not redesign the cabinet again.

All 31/249 prototype variants remain functional.

### 26. Mail templates

Add dedicated reusable mail Blade/text templates.

Keep them:

- simple;
- accessible;
- Russian;
- compatible with common mail clients;
- no frontend app CSS dependency;
- no external assets.

### 27. Documentation

Create/update:

- `docs/email.md` — setup-link flow, resend, TTL, warning scheduler/job, SMTP/queue deployment requirements;
- `docs/development.md` — local SMTP capture + worker commands/ports and smoke instructions;
- `docs/architecture.md` — broker/queue/uniqueness boundaries;
- `docs/project-status.md` — Stage 12 complete; Stage 13 WEBPAY pending;
- `docs/ui-pages.md` — password prototype now wired to real setup flow, admin resend action;
- `.env.example` — SMTP/queue variables only as placeholders/defaults.

Document production needs:

- real SMTP configuration;
- queue worker via supervisor/systemd/cron strategy;
- scheduler cron;
- shared cache/lock store if multiple app/worker instances;
- correct `APP_URL=https://gruppa.info/cabinet`.

No real SMTP credentials in Git.

## Out Of Scope

Do NOT implement:

- forgot-password/self-service reset request;
- changing password for an already configured account;
- email verification;
- participant emails;
- application intake email;
- admin notifications unrelated to password setup;
- WEBPAY;
- payment placement;
- paid extension;
- refunds;
- public-site code changes;
- production deployment;
- UI redesign;
- automatic public-site publication/unpublication.

## Constraints

- Follow WORKFLOW.md and AGENTS.md.
- Base is `7bb1c7410e9e6db2d3225c5b8637681a7459ee8f`.
- Use Laravel Password Broker/token repository; no custom token crypto.
- Current typed TTL setting is authoritative.
- Password is never emailed.
- Setup link is one-time.
- Resend invalidates previous token.
- Email is queued.
- SMTP is never called inside the approval transaction.
- Warning marker is written only after successful send.
- Warning unique key binds group + exact expiry period.
- Email failure cannot break lifecycle expiration.
- Use database queue.
- Tests remain MySQL-only.
- Preserve current Stage 11 API behavior.
- Preserve current UI design baseline.
- No Node/npm/Vite.
- No real email addresses/credentials/secrets.
- Do not alter `.ai/task.md`.
- Do not modify SPEC/WORKFLOW/AGENTS.

## Tests

Run on MySQL.

Cover at minimum:

### Password broker foundation

- standard password-reset token table exists;
- framework PasswordBroker/DatabaseTokenRepository is used;
- token is hashed at rest, not plaintext;
- typed TTL controls validity;
- valid just-before-expiry token works;
- exact/after expiry boundary is deterministic;
- changing TTL setting affects validity as documented;
- token deletion/invalidation works.

### Approval invitation

- pending password-null -> approve commits;
- exactly one invitation queued;
- approval rollback -> no invitation;
- approved user with existing password -> no setup invitation;
- queue dispatch failure does not revert approved status;
- invitation job sees ineligible account -> no mail.

### Resend

- admin-only;
- CSRF;
- foreign/non-admin role denied;
- approved+enabled+password-null accepted;
- pending/rejected/disabled/deleted/admin/password-set rejected;
- previous token immediately invalid;
- fresh token valid;
- previous queued job does not send after resend;
- resend audit contains actor/action only, no sensitive values;
- resend throttling works.

### Setup form

- GET valid link renders real form;
- invalid token generic invalid/expired state;
- expired token generic invalid/expired state;
- disabled/rejected/deleted/pending user cannot use a formerly valid token;
- password-set user cannot reuse setup flow;
- POST requires token/email/password/confirmation;
- minimum 8;
- mismatch rejected;
- rate limit works;
- successful setup hashes password;
- remember token rotates;
- broker token consumed;
- second POST/reuse fails;
- no auto login;
- normal login succeeds afterward.

### Setup email

- queued, not synchronously sent during approval;
- correct recipient;
- correct base-path-safe setup URL;
- no plaintext password;
- TTL hours displayed;
- stale token job no-op;
- SMTP failure retries with same token, not new token.

### Warning scheduler

- outside threshold no job;
- exact threshold eligible;
- inside threshold eligible;
- due/past no job;
- non-active no job;
- already-marked no job;
- ineligible owner no job;
- disabled group behavior matches documented choice and leaves marker null;
- bounded/no-N+1 candidate processing;
- command aggregate output contains no PII.

### Warning uniqueness

- repeated scheduler before processing creates one effective unique job;
- uniqueness remains while job running;
- unique ID includes expected expires_at;
- changed expiry creates a different period key;
- completed marker blocks future dispatch for same period.

### Warning job

- stale changed status -> no mail/no marker;
- stale changed expires_at -> no mail/no marker;
- stale already marker -> no mail;
- successful send -> marker set;
- marker timestamp only after Mail send succeeds;
- failure -> marker remains null;
- retry success -> marker set once;
- extension/new period -> warning can send again;
- republish/new period -> warning can send again.

### Lifecycle isolation

- SMTP failure does not break groups:expire;
- active due group expires even with failed warning job;
- warning send never changes status/expires_at/placement_days;
- no payment rows/effects.

### Regression

- Stage 4–11 suites green;
- Stage 11 API signatures/idempotency unaffected;
- current routes/role boundaries remain;
- full prototype suite 31/249 green;
- production prototype isolation green.

## Required Runtime / Manual Verification

Use Docker and synthetic addresses only.

1. Start local SMTP capture.
2. Start/confirm database queue worker.
3. Confirm scheduler list includes:
   - groups:expire every minute;
   - applications:cleanup daily;
   - groups:queue-expiry-warnings hourly.
4. Approve synthetic pending psychologist through real admin UI.
5. Observe queued job and captured setup email.
6. Use captured real link to set password.
7. Log in with that password.
8. Verify link reuse blocked.
9. Exercise resend and old-link invalidation.
10. Create synthetic near-expiry group and queue warning.
11. Confirm captured warning and marker.
12. Force SMTP failure and verify marker remains null.
13. Run groups:expire while SMTP is unavailable and confirm expiration still works.
14. Inspect logs/audit for token/password absence.
15. Clean smoke-only records/messages/jobs as practical.

## Required Checks

Report exact results:

1. `docker compose ps`
2. non-destructive migrate/seed
3. scheduler list
4. queue/failed-job inspection
5. focused password-setup tests
6. focused expiry-warning tests
7. real SMTP capture smoke
8. real queue-worker smoke
9. `docker compose exec -T php php artisan test`
10. `docker compose exec -T php ./vendor/bin/pint --test`
11. `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
12. `docker compose exec -T php composer check-platform-reqs`
13. `docker compose exec -T php php artisan view:cache`
14. route inspection in local and production env
15. `git diff --check`
16. inspect final diff/staged files
17. confirm no credentials, tokens, captured messages, test mailbox data, screenshots or runtime artifacts are staged

## Required .ai/report.md

Include:

- Status;
- password-broker architecture and how current typed TTL is applied;
- migration/table used;
- setup routes and limiter;
- approval-triggered queue behavior;
- resend/invalidation behavior;
- queued-job stale-token guard;
- SMTP/queue architecture;
- warning command/schedule;
- warning unique key/lock mechanism;
- successful-send marker ordering;
- lifecycle isolation evidence;
- Mailpit/local SMTP details;
- production SMTP/worker/scheduler prerequisites;
- tests/checks/runtime smoke;
- facts/assumptions/unknowns;
- remaining Stage 13 prerequisites.

Never include:

- raw setup token;
- password;
- SMTP password;
- captured message body containing a live token.

## Acceptance Criteria

1. Standard Laravel password-reset token storage exists.
2. Laravel Password Broker/token repository performs token crypto/verification.
3. Typed `password_setup_link_ttl_hours` is authoritative.
4. Pending password-null approval queues one setup invitation after commit.
5. Approval is not rolled back by mail/queue failure.
6. Invitation contains one-time setup link, never a password.
7. Setup link works only for approved/enabled/non-deleted/non-admin/password-null user.
8. Setup password is hashed and login works.
9. Consumed setup link cannot be reused.
10. Expired token cannot be used.
11. Admin can resend only for eligible psychologist.
12. Resend immediately invalidates previous link.
13. Stale old invitation job sends nothing after resend.
14. Admin resend is audited without token/email/password.
15. Setup routes are rate limited.
16. Existing login rate limit remains.
17. No public forgot-password request endpoint exists.
18. Local SMTP capture receives real queued setup email.
19. Database queue worker processes mail asynchronously.
20. `groups:queue-expiry-warnings` exists.
21. Warning command is scheduled hourly with overlap protection.
22. Warning threshold uses current `expiry_warning_days`.
23. Only future active eligible periods are queued.
24. Warning queued/running duplicate protection binds group + exact expires_at.
25. Repeated scheduler cannot create duplicate effective job for same period.
26. Warning job rechecks current group/owner/period before sending.
27. Successful warning email sets `expiry_warning_sent_at`.
28. Marker is never set before successful send.
29. SMTP failure leaves marker null.
30. Queue retry can subsequently send and mark.
31. After active extension/new period, a fresh warning can be sent.
32. After republish/new period, a fresh warning can be sent.
33. Old-period job cannot mark the new period.
34. SMTP/queue failure does not prevent active -> expired lifecycle.
35. Warning flow never mutates group status/expires_at/placement_days.
36. Stage 12 creates no payment effects.
37. Current Stage 11 incoming API remains green.
38. Current redesigned UI remains baseline; only required password/admin integration changes.
39. Full MySQL suite passes.
40. Pint passes.
41. Larastan passes.
42. Composer platform check passes.
43. Blade compilation passes.
44. All 31/249 prototype variants remain green.
45. Production prototype isolation remains.
46. Documentation describes production SMTP, queue worker, scheduler and shared lock prerequisites.
47. Final diff is limited to Stage 12 auth/mail/jobs/scheduler/local Docker/UI wiring/tests/docs/report.
48. No credentials, password, raw token or unrelated artifacts are committed.

## Hard Workflow Gate

Before changing files:

- read WORKFLOW.md;
- read AGENTS.md;
- read this `.ai/task.md`;
- read SPEC only around password setup, §15, Stage 12 and queue duplicate rules;
- read current `docs/project-status.md`;
- inspect current auth config, User model, PsychologistActions, SettingService, group lifecycle, scheduler, queue/mail config and password Blade view;
- inspect installed Laravel 12 PasswordBroker/DatabaseTokenRepository source before choosing dynamic-TTL construction;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm base `7bb1c7410e9e6db2d3225c5b8637681a7459ee8f`;
- do not overwrite unknown local changes.

During implementation:

- stay strictly in Stage 12;
- do not implement WEBPAY;
- do not add forgot-password self service;
- do not redesign UI;
- keep Stage 11 API unchanged;
- keep lifecycle expiration independent of mail;
- never log tokens/passwords;
- do not edit `.ai/task.md`;
- do not edit SPEC/WORKFLOW/AGENTS.

Before commit:

- run all required automated checks;
- perform real local SMTP/queue smoke;
- inspect logs/audit for sensitive values;
- inspect complete diff and staged files;
- remove test mailbox/runtime artifacts;
- update `.ai/report.md`;
- stage only Stage 12 files + report.

Completion:

- use Status: done only if acceptance criteria are satisfied;
- otherwise partial / blocked / failed.

If complete, commit with:

`codex: TASK-2026-09-21-11 implement email password setup warnings`

Do not create an accept commit.
