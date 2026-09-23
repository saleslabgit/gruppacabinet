# Task: TASK-2026-09-23-01

Status: planned
Created from: 1d6b9b21e2ee6e2ed756f4e505f0d18cfd45b62c (main)

## Title

Shared-hosting mail correction — support sendmail and add safe delivery diagnostics

## Goal

Make the existing queued email flows work with the production shared-hosting mail setup that uses the local sendmail-compatible MTA without SMTP authentication, while preserving the current SMTP/Mailpit local workflow.

Add safe operational logging so an operator can distinguish:

- invitation/warning job queued or started;
- application handed the message to the configured mail transport;
- the application-level send failed;
- downstream inbox delivery, which remains outside Laravel and must be diagnosed in the host MTA logs.

This task is a deployment/mail portability correction only.

Do not change password setup business rules, expiry-warning semantics, queue architecture, WEBPAY behavior, group workflows, or authentication behavior.

## Facts

- Current HEAD is exactly:
  `1d6b9b21e2ee6e2ed756f4e505f0d18cfd45b62c`.
- Laravel mail configuration already defines both:
  - `smtp`;
  - `sendmail` with `MAIL_SENDMAIL_PATH`, defaulting to `/usr/sbin/sendmail -bs -i`.
- Local development currently uses SMTP/Mailpit and must continue to do so.
- Production HostER configuration is intended to use:
  - `MAIL_MAILER=sendmail`;
  - `MAIL_SENDMAIL_PATH="/usr/sbin/sendmail -bs -i"`;
  - a real `MAIL_FROM_ADDRESS`.
- On the real host, a manual MTA/SMTP test returned `250 OK id=...`, which proves only that the local MTA accepted the message. It does not prove inbox delivery.
- `SendPasswordSetup` currently rejects every transport except exact `smtp` before calling Laravel Mail.
- `SendExpiryWarning` has the same SMTP-only guard.
- `deployment:preflight` currently fails production mail unless the configured transport is exact `smtp`.
- Existing mail exceptions are deliberately sanitized to avoid leaking setup URLs, recipients, credentials or raw transport data.
- Database queue, database cache/locks and PCNTL are already accepted on the real host.
- Password setup email is queued after psychologist approval and on admin resend.
- Expiry-warning email is queued by the scheduler and marks `expiry_warning_sent_at` only after successful application-level mail transport acceptance.
- Standard Laravel production logging writes to `storage/logs/laravel.log` with the current `single` channel configuration when selected.

## Assumptions

- A local sendmail-compatible binary is a valid production mail transport for this application when explicitly configured and available on the host.
- Laravel/Symfony transport acceptance means only that the application successfully handed the message to the configured transport; it is not proof of remote inbox delivery.
- Local Docker does not need a sendmail binary merely to test this task. Sendmail capability/preflight paths must be testable deterministically without changing the local Docker image.
- SMTP remains a supported production option. This task adds sendmail; it does not replace SMTP globally.

## Unknowns

- HostER outbound delivery result after the local MTA accepts a message.
- HostER Exim/sendmail queue/log access available to the account.
- SPF/DKIM/DMARC/reputation state for real outbound delivery.
- Whether the hosting MTA later defers, rejects or successfully relays messages to a destination provider.

These are staging/hosting facts and must not be invented or treated as application acceptance.

## Scope

### 1. Allow only safe real delivery transports for the two business mail jobs

Update:

- `application/app/Jobs/SendPasswordSetup.php`;
- `application/app/Jobs/SendExpiryWarning.php`.

Both jobs must allow exactly the production delivery transports intentionally supported by this project:

- `smtp`;
- `sendmail`.

Continue to reject transports that can expose sensitive content or are not real production delivery for these flows, including at minimum:

- `log`;
- `array`;
- failover/round-robin configurations that are not explicitly guaranteed to contain only allowed delivery transports.

Do not weaken the existing protection that prevents a live password-setup URL from being written by a log mailer.

Do not add a custom mail transport and do not add a Composer package.

### 2. Preserve password setup security and queue semantics

For `SendPasswordSetup` preserve all current behavior:

- database queue;
- three tries;
- 45-second job timeout;
- backoff 60/300;
- current eligibility check immediately before send;
- current token validity check immediately before send;
- stale/invalid/resend-old jobs remain no-op;
- retry uses the same valid token;
- no password in email;
- no auto-login;
- no synchronous fallback.

Approval and admin resend must continue to queue the job through `PasswordSetupService`.

Do not expose token, setup URL or recipient email in operational logs.

### 3. Preserve expiry warning semantics

For `SendExpiryWarning` preserve:

- database queue;
- `ShouldBeUnique`;
- current unique key;
- three tries;
- 45-second timeout;
- backoff 60/300;
- all current eligibility/revalidation rules;
- marker remains null when sending fails;
- marker is written only after successful application-level transport acceptance and the existing locked recheck;
- group expiration remains independent of mail;
- no synchronous fallback.

Do not change warning thresholds, scheduling frequency, group lifecycle or uniqueness semantics.

### 4. Add safe application-level mail diagnostics

Add structured operational logging sufficient to trace each job without logging sensitive message contents.

For password setup, record at minimum:

- `mail.password_setup.started`;
- `mail.password_setup.accepted_by_transport`;
- `mail.password_setup.failed`.

Also add a queued diagnostic for password setup if it can be emitted only after the database job insert has actually succeeded, without changing transaction/dispatch semantics:

- `mail.password_setup.queued`.

If implementing this queued event would make the invite transaction or current after-commit behavior less safe/clear, document why it is omitted; do not fake a queued log before successful insertion.

For expiry warning, record at minimum:

- `mail.expiry_warning.started`;
- `mail.expiry_warning.accepted_by_transport`;
- `mail.expiry_warning.failed`.

The existing scheduler aggregate logging/counting remains the primary queueing diagnostic for warnings; do not add noisy per-candidate logs in the scheduler command.

Allowed log context should be minimal and technical, for example:

Password setup:
- `user_id`;
- configured mailer name;
- resolved transport name;
- queue attempt number where available;
- exception class on failure.

Expiry warning:
- `group_id`;
- configured mailer name;
- resolved transport name;
- queue attempt number where available;
- exception class on failure.

Do NOT log:

- password setup token;
- setup URL;
- recipient email;
- message body or rendered MIME;
- SMTP/sendmail transcript;
- raw transport exception message;
- transport command output;
- SMTP username/password;
- integration secret;
- WEBPAY secrets/signatures;
- serialized job payload.

Keep the externally thrown job exception generic/sanitized.

Do NOT chain the raw transport exception into the replacement RuntimeException if doing so would make its message/context reachable through Laravel worker exception logging.

The `accepted_by_transport` event must be documented as application transport acceptance only, not inbox delivery.

### 5. Production preflight must support SMTP and sendmail

Update `application/app/Support/DeploymentPreflight.php` and related command/tests.

Replace the SMTP-only deployment check with a generic mail-delivery configuration check.

Expected concept:

- SMTP:
  - supported;
  - configured SMTP host;
  - configured from address;
  - existing timeout configuration remains applicable.
- sendmail:
  - supported;
  - configured sendmail command/path;
  - configured from address;
  - preflight verifies the executable capability safely without sending mail.

For sendmail executable detection:

- do not execute a mail command;
- do not send a probe email;
- do not invoke arbitrary shell;
- do not print the full command/path if that can unnecessarily disclose private server layout;
- isolate capability detection behind a testable support method so tests do not depend on `/usr/sbin/sendmail` being installed in local Docker;
- an invalid/missing/non-executable configured sendmail binary must be a hard deployment FAIL.

Unsupported/default-dangerous transports such as `log` and `array` remain hard deployment FAIL in staging/production.

The check label should no longer claim SMTP specifically. Use a stable generic label such as:

`Mail delivery configured`

The detail may safely identify only the selected transport, for example:

- `smtp`;
- `sendmail`;
- `unsupported`.

Do not print credentials, recipient addresses, setup URLs, message contents or raw command output.

Preflight must still perform no real mail delivery.

### 6. Clarify timeout/safety behavior for sendmail

Documentation must not falsely apply `MAIL_TIMEOUT` to sendmail.

Document clearly:

- `MAIL_TIMEOUT` is the SMTP socket timeout;
- sendmail is a local process transport and is not protected by the SMTP socket timeout;
- PCNTL remains preferred for the Laravel hard job timeout;
- the real HostER currently reports PCNTL available, but project documentation must remain portable;
- without PCNTL, sendmail also depends on the verified host process-runtime/retry_after safety gate;
- `accepted_by_transport` only establishes application-to-local-transport acceptance;
- downstream MTA queue/relay/inbox delivery must be diagnosed using hosting/MTA facilities.

Do not invent HostER MTA log paths or account capabilities.

### 7. Environment example and local development

Keep local development defaulting to SMTP/Mailpit.

Do not switch `.env.example` globally to sendmail.

Add/document `MAIL_SENDMAIL_PATH` as an optional production/shared-hosting setting if it is currently absent from `.env.example`, without putting a real private account path or secret into Git.

Do not add sendmail to the local Docker image merely for this task.

### 8. Tests

Add/update deterministic automated tests for all changed behavior.

At minimum cover:

#### Password setup
- SMTP remains accepted.
- sendmail is accepted.
- log/array or another unsupported transport remains rejected.
- successful sendmail-configured flow reaches Laravel Mail and retains token/eligibility behavior.
- started and accepted logs are emitted on success.
- failed log is emitted on transport failure.
- logs do not contain token, setup URL, recipient email, synthetic transport secret/error message or serialized message content.
- raw transport exception is not leaked by the replacement job exception.
- old queued job after resend remains no-op.
- approval/resend queue behavior remains correct.

#### Expiry warning
- SMTP remains accepted.
- sendmail is accepted.
- unsupported transport remains rejected.
- marker is written only after successful send.
- marker remains null on failed send.
- started/accepted/failed logs behave correctly and contain no sensitive content.
- uniqueness/retry/current-period behavior remains unchanged.

#### Preflight
- SMTP configuration passes.
- sendmail configuration passes when deterministic executable capability is true.
- sendmail fails when command/path is missing/invalid or executable capability is false.
- unsupported mail transport fails.
- mail check output contains no secrets/private message data.
- preflight performs no actual mail/sendmail process execution.
- existing PASS/WARN/FAIL semantics, PCNTL warning behavior and all existing hard blockers remain intact.

Use fakes/test support rather than relying on a real local sendmail binary or external network.

### 9. Documentation/spec consistency

Update as applicable:

- `docs/email.md`;
- `docs/deployment.md`;
- `docs/development.md`;
- `docs/project-status.md`;
- `application/.env.example`;
- the narrow mail-transport wording in `SPEC.md` where it currently says MVP email is SMTP-only/configured through SMTP settings.

The updated specification/docs must state the actual accepted architecture:

- standard Laravel mail infrastructure;
- database queue;
- real delivery transport may be SMTP or explicitly configured local sendmail;
- local development remains SMTP/Mailpit;
- log/array mailers are not valid production delivery for password setup/expiry warnings;
- transport acceptance is not inbox delivery.

Do not broaden this into a general provider/API-mail redesign.

### 10. Report

Update `.ai/report.md` with:

- exact files changed;
- exact behavior implemented;
- exact tests/checks run and results;
- whether local tests used SMTP/Mailpit and deterministic sendmail fakes/capability injection;
- explicit statement that no real external mail was sent by automated tests;
- remaining external HostER delivery unknowns;
- manual staging verification steps after deployment.

## Out Of Scope

Do NOT:

- modify WEBPAY trust/signature/binding/recovery logic;
- modify payment state transitions;
- modify group state-machine behavior;
- modify psychologist approval eligibility/business rules;
- redesign UI;
- change routes or password setup UX except if a test/document correction strictly requires it;
- add password reset/forgot-password functionality;
- replace database queue;
- add a synchronous mail fallback;
- add an HTTP queue runner;
- add Mailgun/SES/Postmark/Resend or another provider;
- add a Composer package;
- add sendmail/MTA packages to Docker;
- configure real HostER MTA/DNS/SPF/DKIM/DMARC;
- claim external inbox delivery based on Laravel success;
- log secrets, tokens, recipient addresses or message bodies;
- commit runtime `.env`, logs, queue payloads or hosting-specific private paths.

## Constraints

- Production PHP contract remains `^8.2`.
- MySQL remains the production database.
- Database queue remains mandatory.
- Database cache/locks remain mandatory.
- Local Mailpit SMTP workflow remains intact.
- Keep the change minimal and aligned with Laravel 12/Symfony Mailer already installed.
- No frontend/build changes.
- No new package unless separately approved.
- Preserve current exception sanitization boundary.
- Preserve current queue retries/timeouts/backoff unless a failing framework test proves a required compatibility correction; do not change them merely for convenience.
- Do not execute sendmail or external mail from deployment preflight.
- Do not make real network/mail calls in automated tests.

## Acceptance Criteria

1. `SendPasswordSetup` works with both configured SMTP and sendmail transports.
2. `SendExpiryWarning` works with both configured SMTP and sendmail transports.
3. Log/array and other unapproved mail transports remain blocked for these sensitive business emails.
4. Local SMTP/Mailpit behavior remains unchanged.
5. Production preflight accepts a valid sendmail configuration and no longer reports the misleading SMTP-only failure.
6. Production preflight rejects missing/non-executable sendmail capability without sending mail or invoking arbitrary shell.
7. Mail jobs produce safe started/accepted/failed diagnostics sufficient to distinguish application mail processing from downstream MTA delivery.
8. Logs contain no setup token, setup URL, recipient email, body, credentials, raw transport error text or serialized payload.
9. Password invitation token/resend/no-op behavior is unchanged.
10. Expiry warning uniqueness and `expiry_warning_sent_at` semantics are unchanged.
11. No sync/request-driven mail fallback is introduced.
12. Existing queue/PCNTL/shared-hosting safety architecture remains intact.
13. Documentation and the narrow SPEC mail wording match the implemented SMTP-or-sendmail behavior.
14. Focused mail/preflight tests pass.
15. Full MySQL suite passes.
16. Pint and Larastan pass.
17. No credentials, runtime env files, logs or unrelated artifacts are committed.

## Checks

Run and report exact results for:

1. focused `PasswordSetupTest`;
2. focused `ExpiryWarningTest`;
3. focused `DeploymentPreflightTest`;
4. shared-hosting runtime/finite-worker regression;
5. Stage 11/12 focused regression;
6. WEBPAY focused/concurrency regression to prove no collateral mail/queue changes;
7. full MySQL test suite;
8. Pint;
9. Larastan;
10. `composer check-platform-reqs`;
11. `php artisan view:cache`;
12. `php artisan deployment:preflight` in the normal local test/dev configuration as applicable, recording environment limitations rather than pretending local sendmail exists;
13. `git diff --check`;
14. final git status/staged-file review;
15. secrets/artifact review.

Do not run a real external sendmail/SMTP delivery as an automated test.

## Manual Staging Verification To Document

After deployment to HostER, the report/docs must give a concise operator sequence:

1. configure runtime `.env` with `MAIL_MAILER=sendmail`, the verified `MAIL_SENDMAIL_PATH`, from address/name and normal queue/log settings;
2. `php artisan optimize:clear`;
3. `php artisan deployment:preflight` and verify `PASS Mail delivery configured: sendmail`;
4. monitor private `storage/logs/laravel.log`;
5. trigger one approved psychologist password-setup resend;
6. run one finite/once database worker manually;
7. verify `mail.password_setup.started` followed by either `accepted_by_transport` or `failed`;
8. inspect `queue:failed` if needed;
9. if Laravel reports `accepted_by_transport` but the inbox remains empty, continue diagnosis in the HostER MTA/Exim facilities/support using the hosting-side message ID; do not change Laravel business state to pretend delivery.

No actual HostER change is part of this Codex task.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD/planner lineage is based on `1d6b9b21e2ee6e2ed756f4e505f0d18cfd45b62c`;
- read `WORKFLOW.md`;
- read `AGENTS.md`;
- read `SPEC.md`, especially queue, technical logging, psychologist password setup and email sections;
- read this `.ai/task.md`;
- read current `.ai/report.md`;
- inspect `config/mail.php`;
- inspect `SendPasswordSetup`, `SendExpiryWarning`, `PasswordSetupService`, psychologist approval/resend flow;
- inspect `DeploymentPreflight` command/support/tests;
- inspect existing password setup, expiry warning and shared-hosting tests;
- inspect email/deployment/development docs;
- do not overwrite unknown local changes.

During implementation:

- work only within this task;
- do not broaden scope;
- preserve the security boundary around password setup tokens and transport errors;
- do not change unrelated business logic;
- do not edit `.ai/task.md`;
- do not make real external mail or WEBPAY requests;
- keep tests deterministic;
- use migrations only if schema changes are actually required; no schema change is expected for this task.

Before commit:

- run all applicable checks above;
- inspect full diff;
- inspect staged files;
- verify no `.env`, logs, credentials, message bodies, queue payloads, hosting secrets or unrelated artifacts are staged;
- update `.ai/report.md` with factual results only.

If complete, commit with:

`codex: TASK-2026-09-23-01 support sendmail and safe mail diagnostics`

If blocked/partial/failed, record the real status and reason in `.ai/report.md`; do not present an incomplete implementation as done.

Do not create an accept commit.
