# Task: TASK-2026-09-20-02

Status: planned
Created from: 6461fc26b1f388b0a0b2bcff16fc09e64888a50d (main)

## Title

Stage 1 correction — run the Laravel test suite on MySQL in Docker, not SQLite

## Goal

Correct the Stage 1 foundation so the automated Laravel test environment follows the explicit project requirement in `SPEC.md`: tests must run against MySQL in Docker, not SQLite.

This is a narrow corrective iteration of Stage 1. Do not start Stage 2.

## Facts

- `TASK-2026-09-20-01` was implemented in commit `6461fc26b1f388b0a0b2bcff16fc09e64888a50d`.
- Review found one acceptance blocker.
- `application/phpunit.xml` currently forces `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.
- `SPEC.md` explicitly requires project tests to run on MySQL in Docker rather than SQLite because later behavior depends on MySQL-specific indexes, generated columns, locking, and transaction semantics.
- The local Docker stack already contains MySQL 8.4 and the application runtime uses MySQL successfully.
- The existing development database is `gruppa_cabinet`.
- Stage 2 has not started and no project domain migrations exist yet.

## Assumptions

- A separate local test database is preferable to running automated tests against the normal development database.
- Local Docker database credentials are development-only and may be used to provision the test database; no production credentials are involved.
- The exact provisioning mechanism is an implementation detail as long as it is simple, idempotent, works from a fresh checkout, and does not require manual host-side MySQL setup.

## Unknowns

- None that block this correction.

## Scope

### 1. Replace SQLite test configuration with MySQL

Update the committed PHPUnit/test configuration so that the normal project test command executed inside Docker:

```bash
docker compose exec -T php php artisan test
```

uses MySQL.

Requirements:

- remove the forced SQLite `:memory:` test configuration;
- set the test environment to use the Compose MySQL service;
- use a dedicated test database, not the normal development database;
- keep the configuration explicit enough that future Stage 2 tests cannot silently fall back to SQLite.

A suitable database name is `gruppa_cabinet_test`, unless an equally clear project-local name is already established during implementation.

### 2. Provision the test database in Docker

Ensure the dedicated MySQL test database exists automatically in the local Docker workflow.

The solution must:

- work from a fresh checkout / fresh Docker volume;
- be idempotent;
- not require the developer to manually create the test database;
- not depend on host PHP, Composer, or MySQL tools;
- not place Docker-specific provisioning inside `application/`;
- not use production credentials;
- avoid a fragile solution that works only on first-ever MySQL volume initialization if an existing local volume can no longer obtain the test database.

Keep the implementation minimal. Do not introduce unnecessary orchestration.

### 3. Prove the test suite is really using MySQL

Add or adjust a focused automated test so the suite fails if it is not actually connected to the dedicated MySQL test database.

At minimum verify during the Laravel test run that:

- the active database driver/connection is MySQL;
- the current database is the dedicated test database;
- a real simple query can be executed through Laravel against that connection.

Do not satisfy this requirement only by inspecting configuration strings.

### 4. Preserve the normal development database

The test suite must not use or destructively reset `gruppa_cabinet`.

The dedicated test database must be clearly separate so later migrations, `RefreshDatabase`, generated-column tests, transaction tests, and locking tests can run without risking normal local development data.

### 5. Documentation

Update only the documentation affected by this correction.

At minimum make the actual test database behavior clear in:

- `README.md` and/or `docs/development.md`, where developers run tests;
- `docs/project-status.md` if its Stage 1 verification description needs correction.

Documentation must state that automated tests run on MySQL in Docker and must not claim SQLite support as the project test path.

### 6. Report

Replace/update `.ai/report.md` for `TASK-2026-09-20-02`.

Report:

- the exact test database setup;
- files changed;
- exact commands run;
- proof that the test suite used MySQL and the dedicated test database;
- Pint/Larastan results after the correction;
- any remaining Stage 1 risk or unknown.

## Out Of Scope

Do not:

- implement Stage 2 migrations, models, enums, status services, settings, audit, sessions tables, queue tables, or seeders;
- add authentication or product functionality;
- change the Stage 1 UI beyond what is necessary for this correction;
- add WEBPAY, email, public-site API, scheduler business logic, or other later-stage behavior;
- redesign the Docker stack beyond what is necessary to provision and use the MySQL test database;
- change `SPEC.md`, `WORKFLOW.md`, or `AGENTS.md`;
- add SQLite as an alternate supported project test path.

## Constraints

- Follow `WORKFLOW.md` and `AGENTS.md`.
- Work only on this corrective task.
- Keep the diff narrow and related to the rejected Stage 1 acceptance point.
- Tests must run on MySQL in Docker.
- The normal development database and dedicated test database must be distinct.
- Docker-specific files remain outside `application/`.
- No production secrets or credentials.
- No Node/npm/Vite.
- Do not alter `.ai/task.md`.

## Acceptance Criteria

1. `application/phpunit.xml` no longer configures SQLite or `:memory:` as the project test database.
2. The standard Docker test command uses MySQL.
3. Tests use a dedicated MySQL test database distinct from `gruppa_cabinet`.
4. The test database is provisioned automatically and idempotently by the local Docker workflow.
5. A fresh-checkout/fresh-volume path can create the test database without manual MySQL setup.
6. An existing local Docker volume can also reach the required test-database state without requiring the developer to delete normal development data.
7. An automated test proves the active driver is MySQL, the selected database is the dedicated test database, and a real query succeeds.
8. Running the test suite does not use the normal development database.
9. `docker compose exec -T php php artisan test` passes.
10. Pint passes.
11. Larastan passes.
12. `composer check-platform-reqs` still passes.
13. No Stage 2 or unrelated product functionality is added.
14. Relevant developer/project-status documentation matches the corrected MySQL test setup.
15. `.ai/report.md` accurately reports this task as `done` only if all required checks are actually verified.

## Checks

Run and report at minimum:

1. Inspect the effective Laravel test database configuration inside the PHP container.
2. Verify the dedicated test database exists in MySQL and is different from `gruppa_cabinet`.
3. Run:
   - `docker compose exec -T php php artisan test`
   - `docker compose exec -T php ./vendor/bin/pint --test`
   - `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
   - `docker compose exec -T php composer check-platform-reqs`
4. Include a test assertion/query that proves the suite itself is connected to MySQL and to the dedicated test database.
5. Verify the normal development database still exists and was not selected as the PHPUnit database.
6. Verify the database provisioning path from a clean/fresh Docker state.
7. Verify the provisioning approach is also safe/idempotent when the MySQL volume already exists.
8. Inspect `git diff`, `git status --short`, and staged files before commit.

## Hard Workflow Gate

Before changing files:

- read `WORKFLOW.md`, `AGENTS.md`, and this `.ai/task.md`;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm this task corresponds to the current planner commit;
- do not overwrite or clean unknown local changes.

During implementation:

- stay strictly within the Stage 1 MySQL-test correction;
- do not begin Stage 2;
- do not alter `.ai/task.md`;
- do not change governance/specification files;
- do not add secrets, unrelated dependencies, or unrelated cleanup.

Before commit:

- run all required checks;
- prove the tests actually use MySQL, not merely that MySQL is available;
- update `.ai/report.md` for this task;
- inspect full diff and staged files;
- stage only files belonging to this correction plus `.ai/report.md`;
- confirm no secrets or unrelated files are staged.

Completion:

- use `Status: done` only if the MySQL test requirement is fully satisfied;
- otherwise report `partial`, `blocked`, or `failed`;
- if the gate passes, commit with:

```text
codex: TASK-2026-09-20-02 use MySQL for tests
```

- do not create an `accept:` commit.
