# Task: TASK-2026-09-21-14

Status: planned
Created from: 4e425eb932e4bcbea6aebbc4fe9b9eca11b952be (main)

## Title

Shared-hosting portability correction — make PCNTL optional without weakening queue safety

## Goal

Fix the one remaining acceptance blocker in TASK-2026-09-21-13.

The shared-hosting baseline is intended for ordinary PHP hosting where the account owner may not be able to install/enable CLI `pcntl`.

Current implementation makes `pcntl` a hard blocker in `deployment:preflight`.

That is too strict:

- Laravel 12 requires PCNTL to enforce queue worker/job timeouts;
- Laravel queue workers themselves can still run without PCNTL;
- on shared hosting we use a finite cron-driven database worker, not a permanent daemon;
- SMTP and WEBPAY HTTP operations already have explicit transport timeouts;
- actual hosting cron/process limits are an external staging acceptance fact.

Preserve all current payment, mail, queue, scheduler and database-cache behavior.

Do not change WEBPAY trust architecture.

## Base

Use exactly:

`4e425eb932e4bcbea6aebbc4fe9b9eca11b952be`

## Required Behavior

### 1. PCNTL must not be a universal hard preflight blocker

Change `deployment:preflight` so:

- missing required Composer production extensions remains a hard failure;
- missing `pcntl` by itself does NOT make the whole preflight fail;
- output clearly reports whether hard worker timeout support is:
  - available; or
  - unavailable.

Use an explicit advisory/warning capability rather than disguising missing PCNTL as PASS.

Recommended check output concept:

- `PASS CLI worker hard timeout (pcntl): available`
- `WARN CLI worker hard timeout (pcntl): unavailable; verify cron process limit and shared-hosting fallback`

Do not print secrets.

If necessary, extend the preflight check structure from only `ok` to a severity/status model such as:

- pass;
- warn;
- fail.

Only fail checks affect process exit status.

### 2. Keep PCNTL path as preferred

If `pcntl` exists:

Use the current finite worker recommendation:

`php artisan queue:work database --stop-when-empty --tries=3 --timeout=45 --max-time=50`

Existing Docker/local runtime with pcntl remains valid.

Do not remove pcntl from the local Docker image.

### 3. Document safe no-PCNTL shared-hosting fallback

If target shared hosting does not expose PCNTL:

- finite database worker remains allowed;
- Laravel hard job timeout is unavailable;
- `--stop-when-empty` and finite worker lifetime remain the operating model;
- explicit provider transport timeouts remain mandatory:
  - SMTP timeout;
  - WEBPAY connect/request timeout;
- MySQL/database operations remain bounded by hosting/database configuration;
- hosting cron maximum process runtime must be discovered and verified;
- queue `retry_after` must be comfortably greater than the maximum expected/allowed in-flight job duration;
- do not allow two workers to process the same still-running job because `retry_after` was too small.

Do not invent a fixed HostER runtime limit.

Document an operator decision gate:

- if PCNTL unavailable but HostER provides a bounded cron process runtime compatible with our jobs and retry_after, shared-hosting mode is acceptable;
- if neither PCNTL nor a safe bounded process runtime is available, hosting is not accepted for this application until the hosting capability changes.

Do not fall back to synchronous mail/payment jobs.

Do not add an HTTP-triggered queue runner.

### 4. Shared-hosting queue configuration guidance

Review `DB_QUEUE_RETRY_AFTER`.

The documentation must explain:

- with PCNTL, worker timeout must remain below retry_after;
- without PCNTL, retry_after must exceed the verified maximum reasonable job/process duration with a safety margin;
- actual value must be finalized during HostER staging discovery.

You may add `DB_QUEUE_RETRY_AFTER` to `.env.example` with a safe documented default if justified, but do not invent hosting-specific values as facts.

Do not change queue semantics merely for tests.

### 5. Preflight remains strict on actual hard blockers

Do not weaken these existing hard checks:

- PHP version;
- required production PHP extensions such as DOM/XML/PDO/etc.;
- MySQL 8 verified contract;
- writable directories;
- database session/queue/cache requirements in deployment;
- cache/lock tables and lock functionality;
- HTTPS `/cabinet` APP_URL;
- APP_DEBUG=false;
- SMTP configuration presence;
- integration secret presence;
- WEBPAY configuration presence;
- required DB tables.

PCNTL is the only capability being reclassified from universal hard blocker to deployment warning/capability.

### 6. Tests

Add/update tests for preflight severity behavior.

At minimum:

- a warning does not cause non-zero exit code when all hard checks pass;
- a hard failure still returns non-zero even when warnings also exist;
- warning output contains no secrets;
- current environment with PCNTL still reports available;
- support layer can deterministically test the missing-PCNTL path without actually uninstalling the extension (inject/provide capability detection cleanly rather than environment hacks);
- no mail/HTTP/business writes;
- full database cache/lock tests remain green;
- finite worker smoke remains green.

### 7. Documentation

Update:

- `docs/deployment.md`;
- `docs/development.md` only if needed;
- `docs/project-status.md` if it currently says PCNTL is mandatory;
- `.ai/report.md`.

Docs must clearly distinguish:

- PCNTL preferred for Laravel hard job timeout;
- PCNTL not required for the queue worker to execute jobs;
- external HostER cron/process-limit acceptance required when PCNTL is unavailable.

Do not overstate HostER capabilities before the real account is inspected.

## Out Of Scope

Do NOT modify:

- WEBPAY signatures/binding/recovery;
- payment state transitions;
- group workflows;
- mail business behavior;
- scheduler frequencies;
- cache driver architecture;
- admin payment/group correction;
- production deployment;
- real HostER settings;
- Docker deployment.

## Required Checks

Report exact results:

1. focused preflight tests;
2. shared-hosting runtime tests;
3. WEBPAY focused/concurrency regression;
4. Stage 11/12 focused regression;
5. full MySQL suite;
6. Pint;
7. Larastan;
8. composer check-platform-reqs;
9. view:cache;
10. finite worker smoke;
11. git diff --check;
12. final staged/secrets/artifact review.

## Acceptance Criteria

1. Missing PCNTL alone does not fail deployment:preflight.
2. Missing PCNTL is clearly shown as WARN/advisory, not hidden.
3. Existing hard blockers still fail preflight.
4. PCNTL-enabled runtime continues to use/enforce the preferred timeout path.
5. Finite database worker remains the shared-hosting baseline.
6. Docs explain the no-PCNTL safety gate around host process runtime and retry_after.
7. No sync/request-driven queue fallback is introduced.
8. Database cache/locks remain the shared lock backend.
9. Existing WEBPAY/mail/group behavior is unchanged.
10. Full MySQL suite and quality checks pass.
11. No credentials/secrets/unrelated artifacts are committed.

## Hard Workflow Gate

Before editing:

- read WORKFLOW.md;
- read AGENTS.md;
- read this task;
- inspect DeploymentPreflight command/support/tests and deployment docs;
- inspect current Laravel 12 Worker timeout behavior in installed framework source;
- confirm base `4e425eb932e4bcbea6aebbc4fe9b9eca11b952be`;
- run git status/log;
- do not overwrite unknown changes.

During implementation:

- keep change limited to shared-hosting portability/preflight/docs/tests;
- do not edit `.ai/task.md`, SPEC, WORKFLOW or AGENTS.

If complete, commit with:

`codex: TASK-2026-09-21-14 make pcntl optional for shared hosting`

Do not create an accept commit.
