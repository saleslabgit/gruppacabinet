# Task: TASK-2026-09-24-03

Status: planned
Created from: 7e3ed67fd8c700549dc998bb4f1886aa930fc600 (main)

## Title

Hotfix psychologist intake for MySQL 8.0 prepared-statement failure

## Goal

Fix the production Stage 11 psychologist intake failure introduced with multiple trainings.

Production on shared hosting runs MySQL 8.0.46. A synthetic request against the deployed code reproduced:

```
SQLSTATE[HY000]: General error: 1615 Prepared statement needs to be re-prepared
```

The failing SQL is the training replacement delete:

```sql
delete from `gp_user_trainings`
where `gp_user_trainings`.`user_id` = ?
  and `gp_user_trainings`.`user_id` is not null
order by `position` asc
```

The public form and the new `trainings` contract are not the cause. The fix belongs in Gruppa Cabinet and must be committed normally; do not patch production manually.

Preserve all TASK-2026-09-24-02 behavior.

## Facts

- Current HEAD is `7e3ed67fd8c700549dc998bb4f1886aa930fc600`.
- Local Docker uses MySQL 8.4; the full TASK-02 suite passed there.
- Production preflight reports MySQL 8.0.46.
- Production schema migration is applied:
  - `gp_user_trainings` exists;
  - `gp_user_documents.user_training_id` exists.
- The deployed intake reaches Laravel and fails inside `IntakeService::psychologist()`.
- A production-safe synthetic diagnostic reproduced errno 1615 on `$user->trainings()->delete()`.
- `User::trainings()` carries `orderBy('position')`, so calling relation `delete()` inherits the ORDER BY.
- The current code calls the delete even for a newly-created user, where there are no existing trainings to delete.
- Site-side retries repeat the same 500; this is not a validation, email, WEBPAY, routing or file-upload error.

## Scope

### 1. Remove the problematic ordered prepared DELETE

Change only the psychologist training replacement path as needed.

Required behavior:

- for a newly-created psychologist, do not execute any training DELETE before creating the submitted trainings;
- for an existing pending/rejected psychologist resubmission, delete the current `gp_user_trainings` rows without inheriting the relationship `ORDER BY position`;
- the production-compatibility delete must not rely on a server-side prepared statement that reproduces MySQL 8.0.46 errno 1615;
- if direct/unprepared SQL is used, interpolate only a strictly cast integer user ID; do not interpolate caller-controlled strings;
- keep the delete inside the existing DB transaction;
- preserve the existing `ON DELETE SET NULL` behavior for historical certificate links;
- immediately recreate the submitted ordered training collection as before.

A narrow `DB::unprepared()` delete with an integer-cast user ID is acceptable and preferred over changing global PDO behavior.

Do **not** globally enable PDO emulated prepares and do not change the database connection defaults for the whole application.

### 2. Preserve intake semantics

Do not change:

- `payload.trainings` contract;
- `certificate_N -> trainings[N]` mapping;
- idempotency fingerprint;
- pending/rejected/approved/disabled/deleted conflict matrix;
- document retention/private storage;
- integration journal transaction behavior;
- public phone behavior;
- group application endpoint;
- rate limiting/allowlist;
- API error envelope.

New psychologist submission must still return 201 and persist the submitted trainings.

Existing pending/rejected resubmission must still:

- replace the current training rows;
- null old certificate links rather than delete documents/files;
- create/link new training certificates;
- remain transactional;
- roll back trainings, links and new files on failure.

Same-ID replay must still do no duplicate work.

### 3. Regression tests

Add focused regression coverage that would catch the code path responsible for the production failure even though local MySQL 8.4 does not reproduce errno 1615.

At minimum verify:

- new psychologist intake does not issue a training DELETE before first creation;
- existing psychologist resubmission replaces trainings;
- the replacement DELETE does not contain `ORDER BY position`;
- the replacement path uses the production-compatible non-prepared/direct execution chosen by the implementation;
- old certificate links become null and files remain;
- rollback behavior remains correct;
- same-ID replay remains unchanged.

Use synthetic data only.

If a reliable disposable MySQL 8.0.46 verification can be run without changing project dependencies or committing environment-specific infrastructure, run it and report the exact result. Do not make that an artificial blocker if the environment cannot provide it.

### 4. Documentation/report

Public API behavior is unchanged, so do not rewrite SPEC/integration docs unless a factual current-state sentence truly needs correction.

Update `.ai/report.md` with:

- exact root cause;
- exact code change;
- exact focused/full checks;
- whether MySQL 8.0.46 itself was reproduced locally or only production evidence is available;
- deployment instruction: normal `git pull` + cache clear; no new migration is required.

## Out Of Scope

Do NOT:

- modify the external public-site `form_cabinet.php`;
- change the Stage 11 API contract;
- add or alter database migrations;
- change `gp_user_trainings` schema;
- change global PDO/MySQL configuration;
- change WEBPAY;
- change mail/password setup;
- change login/session/authentication;
- change group lifecycle;
- change admin training UX;
- introduce new dependencies;
- add a new retry policy for unrelated SQL errors;
- expose/log questionnaire PII or SQL bindings from production requests.

## Constraints

- Laravel 12 / PHP ^8.2 remains unchanged.
- MySQL 8.4 local tests must continue to pass.
- The fix must be compatible with production MySQL 8.0.46.
- Keep the diff surgical.
- No migration is required.
- No frontend/build change.
- No real external network/mail/WEBPAY calls in tests.
- Do not edit `.ai/task.md`.

## Acceptance Criteria

1. New psychologist intake no longer executes the problematic training DELETE.
2. Existing pending/rejected resubmission deletes old training rows without inherited `ORDER BY position`.
3. The compatibility delete does not use the server-side prepared-statement path that produced errno 1615 on MySQL 8.0.46.
4. No global PDO/database behavior is changed.
5. New psychologist with multiple trainings persists successfully.
6. Resubmission still replaces trainings and preserves historical documents/files with null old links.
7. Certificate-to-training association for new uploads remains correct.
8. Transaction rollback and file cleanup remain correct.
9. Idempotent replay remains unchanged.
10. Existing conflict/status rules remain unchanged.
11. No schema migration is added.
12. Focused integration tests pass.
13. Full MySQL suite passes.
14. Pint and Larastan pass.
15. No unrelated files, secrets, logs or artifacts are committed.

## Checks

Run and report exact results for:

1. focused `IntegrationIntakeTest`, including new regression assertions;
2. `IntegrationConcurrencyTest`;
3. `UserTrainingMigrationTest` to ensure schema/backfill semantics remain unaffected;
4. relevant psychologist/document regression tests;
5. full MySQL suite;
6. Pint;
7. Larastan;
8. `composer check-platform-reqs`;
9. `php artisan view:cache`;
10. `git diff --check`;
11. final staged-file/secrets/artifact review.

Database suites sharing the test database must run sequentially.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and its parent is `7e3ed67fd8c700549dc998bb4f1886aa930fc600`;
- read `WORKFLOW.md`;
- read `AGENTS.md`;
- read this `.ai/task.md`;
- read current `.ai/report.md`;
- inspect `IntakeService`, `User::trainings()`, `UserTraining`, the training migration and focused intake tests;
- inspect the production evidence recorded in this task;
- do not overwrite unknown local changes.

During implementation:

- work only within this hotfix;
- do not edit `.ai/task.md`;
- do not broaden into public-site changes or DB configuration changes;
- preserve transaction/file cleanup semantics;
- use only a strictly cast numeric identifier in any direct SQL;
- keep tests deterministic and synthetic.

Before commit:

- run all applicable checks above;
- inspect full diff and staged files;
- verify no migration, env/config secret, log, real PII/upload or unrelated artifact is staged;
- update `.ai/report.md` with factual results only.

If complete, commit with:

`codex: TASK-2026-09-24-03 fix MySQL 8.0 training replacement`

If blocked/partial/failed, record the real status and reason in `.ai/report.md`; do not present incomplete work as done.

Do not create an accept commit.
