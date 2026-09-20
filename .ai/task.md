# Task: TASK-2026-09-20-03

Status: planned
Created from: 90de1e6bbe2e63289c2c1aa48cd90c325486c094 (main)

## Title

Stage 2 — Build the MySQL schema, Eloquent domain model, state machines, audit/history foundation, settings, and seeds

## Goal

Implement the complete Stage 2 database/domain foundation from `SPEC.md` on top of the accepted Stage 1 runtime.

The result must provide the real MySQL schema and Eloquent model layer needed by later UI/backend stages: users, documents, groups, status history, audit log, payments, payment notifications, participant applications, dictionaries, settings, database sessions, database queues, enums/state transitions, group UUID generation, settings access, and idempotent seed data.

This task must stop at the domain/data foundation. Do not implement Stage 3 UI prototypes, authentication flows, CRUD controllers, public API, email, scheduler business behavior, or WEBPAY requests.

## Facts

- Stage 1 is accepted through commit `90de1e6bbe2e63289c2c1aa48cd90c325486c094`.
- The application is Laravel 12 on PHP 8.2.32 with MySQL 8.4 in Docker.
- Project tests run on the dedicated MySQL database `gruppa_cabinet_test`; SQLite is not an allowed project test path.
- `application/` is the deployable Laravel application; Docker infrastructure stays outside it.
- The project uses UTC internally and `Europe/Minsk` for display.
- Monetary values are stored as integer minor units; float/decimal money storage is prohibited.
- `status` is the lifecycle source of truth. `accept` is only a compatibility field and must never drive business logic.
- `disabled`, `free`, and `admin` are independent flags and are not lifecycle statuses.
- Current tariff for future operations is `gp_users.free`; `gp_groups.free` is the historical snapshot taken when the group is created.
- No WEBPAY request or provider credential is allowed before Stage 13.

## Assumptions

- Use conventional English snake_case column names that directly represent the fields defined by `SPEC.md`.
- User questionnaire fields are nullable where necessary because administrators share `gp_users`, pending psychologists have no password yet, and technical group drafts may exist before all group content is filled.
- `email` itself is required for a user; `password` is nullable until the later onboarding/password-setup flow.
- `remember_token` belongs on `gp_users` because later access invalidation explicitly relies on it.
- Compatibility `accept` is derived centrally and never written by controllers/forms/seeds:
  - user: true only for `approved`;
  - group: true after moderation acceptance, i.e. `approved`, `active`, and `expired`; false for all pre-acceptance/rejected states.
  If implementation evidence shows this mapping conflicts with a repository fact, stop and report instead of silently changing it.
- Physical delete cascades must not erase historical/payment/application data. Prefer restrictive/non-cascading foreign-key behavior for records that must survive parent soft deletion.
- Known default settings from the specification may be seeded. Placement and extension prices have no specified numeric defaults and must not be invented.

## Unknowns

- Exact user-facing dictionary item values for education type, group format, gender, and other reusable choices are not specified yet. Create/seed the required dictionary definitions/codes, but do not invent user-facing item values.
- Placement and extension price values are not specified. Their setting keys must exist in an explicitly unconfigured/null state; do not interpret missing/null/zero as an approved product price.
- UI validation requirements and exact required/optional field rules beyond schema invariants are implemented in later CRUD/Form Request stages.

## Scope

### 1. MySQL migrations

Create migrations for:

- `gp_dictionaries`;
- `gp_dictionary_items`;
- `gp_users`;
- `gp_user_documents`;
- `gp_groups`;
- `gp_group_status_history`;
- `gp_audit_log`;
- `gp_payments`;
- `gp_payment_notifications`;
- `gp_group_applications`;
- `gp_settings`;
- Laravel database `sessions`;
- Laravel database queue tables, including `jobs` and any standard batching table required by the framework;
- `failed_jobs`.

Order migrations so all foreign keys can be created cleanly from an empty database.

Do not create unrelated Stage 3+ tables.

### 2. `gp_users`

Implement the psychologist/admin schema described in §§5–7.

Include the questionnaire/profile fields required by `SPEC.md`, including:

- name parts;
- phone;
- email;
- education type reference and other-education field;
- modality/program;
- training center;
- graduation year;
- training hours;
- license number and expiry;
- group-leading experience;
- groups-conducted count;
- the specified declaration/confirmation flags;
- webinar/live-session readiness flag;
- personal-data consent timestamp and version;
- `status`;
- compatibility `accept`;
- `disabled`;
- `free`;
- `admin`;
- nullable `password`;
- `remember_token`;
- timestamps;
- soft delete.

Requirements:

- `User` explicitly uses table `gp_users`;
- status is a varchar/string column, not a MySQL enum;
- status casts to the PHP user-status enum;
- boolean flags cast to boolean;
- relevant date fields cast to date/datetime;
- questionnaire fields that cannot exist for an administrator are nullable;
- password hashing uses Laravel conventions when a password is assigned.

#### Active-email uniqueness

Implement the MySQL generated-column strategy from §5:

- technical generated column, e.g. `active_email`, evaluates to `email` only while `deleted_at IS NULL`, otherwise `NULL`;
- unique index on that generated column;
- do not use unique `(email, deleted_at)`;
- do not use a permanently unique index on `email`;
- the generated column is technical only and must not become business logic or a normal writable model field.

Add MySQL tests proving:

- two active rows with the same email cannot coexist;
- after soft deleting the active row, a new active row with the same email can be created.

### 3. User documents

Create `gp_user_documents` and its Eloquent model/relations with the §6 fields:

- `user_id`;
- `type`;
- `path`;
- `original_name`;
- `mime_type`;
- `size`;
- timestamps.

This task defines the data model only. Do not implement upload/download controllers or file validation yet.

### 4. Groups

Create `gp_groups` and its model with the §11 fields and relationships.

At minimum include:

- `public_uuid`;
- `owner_id`;
- `status`;
- `disabled`;
- compatibility `accept`;
- `free`;
- title/name;
- description;
- schedule;
- group-format dictionary reference;
- meeting duration in minutes;
- participant capacity/count;
- gender dictionary reference;
- meeting price in integer minor units;
- latest moderator comment;
- rejection reason;
- `published_at`;
- `expires_at`;
- `expiry_warning_sent_at`;
- `placement_days`;
- timestamps;
- soft delete.

Content fields must be nullable where required to support a technical `awaiting_payment` or initial `draft` group before the form is complete.

#### public_uuid

- generate a random UUID v4 automatically when a group is created;
- unique index it;
- do not use the internal numeric id as integration id;
- once created, changing `public_uuid` through normal Eloquent save/update must be rejected rather than silently accepted;
- test generation, uniqueness, and immutability.

### 5. Group status history

Create `gp_group_status_history` exactly as the status-history foundation from §4.8:

- group;
- nullable `from_status`;
- `to_status`;
- nullable actor id;
- actor type (`user` / `system`);
- nullable comment;
- created timestamp.

Every real group status transition performed by the domain transition service must create a history row in the same database transaction as the status change.

Do not build UI for history in this stage.

### 6. Audit log

Create `gp_audit_log` and a small explicit audit service.

Schema must support:

- nullable actor id;
- actor type;
- stable entity type;
- nullable entity id;
- stable action code;
- nullable JSON metadata;
- created timestamp.

Requirements:

- metadata casts to array/json;
- no full questionnaire/document/payment secrets should be introduced into audit metadata;
- the service should make it straightforward for later stages to record the required admin actions without direct table writes;
- add a focused test that records a representative non-sensitive admin action and reads it back correctly.

Do not implement all later admin actions/controllers in this stage.

### 7. Payments

Create `gp_payments` and model according to §19.6, without performing any WEBPAY calls.

Include:

- `owner_id`;
- `group_id`;
- `type` as a string supporting `placement` / `extension`;
- unique `order_number`, max length 64;
- nullable unique `transaction_id`;
- `amount` as unsigned bigint/integer minor units;
- currency, default `BYN`;
- payment status;
- `paid_at`;
- `refunded_at`;
- `refund_comment`;
- nullable normalized `provider_response` JSON;
- `last_status_check_at`;
- `status_check_attempts`, default 0;
- timestamps;
- soft delete.

Requirements:

- payment status casts to the payment-status enum;
- amount casts to integer and must never use float;
- provider response casts to array/json;
- multiple null `transaction_id` values are allowed, while duplicate non-null values are rejected by MySQL;
- `order_number` is unique and not derived from the auto-increment id.

Do not generate provider signatures, call WEBPAY, or implement payment confirmation effects yet.

### 8. Payment notifications

Create `gp_payment_notifications` with the §19.8 technical-journal fields and indexes:

- nullable payment relation;
- nullable order number;
- nullable transaction id;
- JSON payload;
- `signature_valid`;
- `processed`;
- short technical result;
- created timestamp.

This is only the storage model. No notify endpoint or WEBPAY signature handling in this stage.

### 9. Participant applications

Create `gp_group_applications` with the §17 data model:

- group relation;
- last name;
- first name;
- phone;
- normalized phone field;
- nullable `processed_at`;
- timestamps.

No public API, ownership UI, normalization request flow, processed/unprocessed controller, or retention command yet.

### 10. Dictionaries

Create `gp_dictionaries` and `gp_dictionary_items`.

Use stable codes. At minimum seed dictionary definitions for:

- education type;
- group format;
- gender.

Requirements for items:

- dictionary relation;
- stable `code`;
- display name;
- sort order;
- active flag;
- unique `(dictionary_id, code)`;
- deactivation is supported by schema/model; do not implement CRUD yet.

Do not invent user-facing item values that are absent from the specification. It is acceptable for the required dictionary containers to exist without speculative items until those values are supplied/approved.

### 11. Settings and typed cached access

Create `gp_settings` and a simple `SettingService` (or equivalently small service) that is the application access point for business settings.

The service must:

- read settings through one place, rather than views/controllers querying the model directly later;
- provide typed access for the known setting definitions;
- cache reads;
- provide an explicit cache invalidation path suitable for Stage 8 updates;
- fail clearly or return an explicit nullable/unconfigured result for settings whose product value is not yet defined;
- never silently coerce an unconfigured price to zero/free.

Seed the known setting keys:

- placement price — unconfigured/null, because no numeric value is specified;
- extension price — unconfigured/null;
- placement duration — 30 days;
- expiry warning — 3 days;
- expired extension window — 30 days;
- participant application retention — 12 months;
- password setup-link TTL — 72 hours.

Use stable units in key names/service methods so later code cannot confuse days, months, hours, or minor currency units.

Add tests for typed reads, defaults/seeds, caching, and invalidation behavior.

### 12. Status enums and transition matrices

Create string-backed PHP enums and Laravel casts for:

#### User status
- `pending`;
- `approved`;
- `rejected`.

Allowed transitions:
- `pending → approved`;
- `pending → rejected`;
- `rejected → pending`.

#### Group status
- `awaiting_payment`;
- `draft`;
- `moderation`;
- `revision`;
- `rejected`;
- `approved`;
- `active`;
- `expired`.

Allowed transitions exactly as §12:
- `awaiting_payment → draft`;
- `draft → moderation`;
- `moderation → approved`;
- `moderation → revision`;
- `moderation → rejected`;
- `revision → moderation`;
- `approved → active`;
- `active → expired`;
- `expired → approved`.

#### Payment status
- `created`;
- `pending`;
- `succeeded`;
- `failed`;
- `cancelled`;
- `refunded`.

Allowed transitions:
- `created → pending`;
- `pending → succeeded`;
- `pending → failed`;
- `pending → cancelled`;
- `succeeded → refunded`.

Each enum must expose an explicit transition check such as `canTransitionTo()`.

Add matrix tests that cover all allowed transitions and prove every disallowed pair is rejected.

### 13. Domain transition services

Implement small explicit transition services for user, group, and payment statuses.

Requirements:

- callers do not need to assign status directly to perform a lifecycle transition;
- invalid transitions throw a clear domain exception;
- group status transition + status-history insert happen in one DB transaction;
- transition services do not perform any WEBPAY, email, scheduler, publication, or UI side effects at this stage;
- services are small and do not become a generic workflow framework.

For group transitions, accept actor context and optional comment so later moderation stages can reuse the same service.

The Stage 2 service does not need to implement Stage 7 Form Request validation, but must preserve the transition matrix invariant regardless of caller.

### 14. Compatibility `accept`

Keep `accept` out of business decisions, queries, and transition matrices.

Implement its derivation in exactly one central place (model observer or equally central domain mechanism) for both users and groups. Seeds and tests must set status and let the central mechanism derive `accept`; they must not manually maintain it in multiple places.

Test the assumed compatibility mapping documented above.

### 15. Model relationships and casts

Implement the Eloquent relationships needed by the schema, including at minimum:

- User → documents;
- User → groups;
- User → payments;
- Group → owner;
- Group → applications;
- Group → payments;
- Group → status history;
- Payment → owner;
- Payment → group;
- Payment → notifications;
- dictionary → items;
- relevant item references from user/group;
- audit/history actor relationships where appropriate.

Use explicit `$table` values for `gp_*` models.

Add focused relationship tests sufficient to prove the Stage 2 acceptance chain works.

### 16. Required indexes and constraints

Implement and verify the §28 indexes, including:

- `gp_users`: generated active-email unique index, indexes on status/disabled/free;
- `gp_groups`: unique public UUID; owner/status/expires indexes; `(status, expires_at)`; `(owner_id, status)`;
- `gp_payments`: unique order number; nullable unique transaction id; indexes on owner/group/status/type/created_at;
- `gp_payment_notifications`: payment/order/transaction/created_at indexes;
- `gp_group_applications`: group/processed indexes; `(group_id, processed_at)`; created_at;
- `gp_group_status_history`: group index;
- `gp_audit_log`: `(entity_type, entity_id)`, actor, created_at;
- `gp_dictionary_items`: unique `(dictionary_id, code)`.

Because tests run on MySQL, add schema/integration coverage for MySQL-specific invariants rather than only asserting Laravel migration definitions.

### 17. Database sessions and queue foundation

Create the standard Laravel database tables needed for:

- sessions;
- queued jobs;
- failed jobs;
- standard job batching if required by the framework queue schema.

Update the project environment example/configuration so normal local application behavior is aligned with the specification:

- `SESSION_DRIVER=database`;
- `QUEUE_CONNECTION=database`.

Testing may continue to override session/queue drivers where isolation is appropriate, but all database/schema tests remain on MySQL.

Do not create mail jobs, scheduler jobs, or business queues yet.

### 18. Seeders

Create idempotent seeders for:

- required dictionary definitions;
- the known business settings above;
- one local/testing administrator.

Administrator seed requirements:

- only create fixed known development credentials in `local`/`testing`;
- do not create a known-password administrator in production;
- use Laravel password hashing;
- administrator status is `approved`, `admin=true`, `disabled=false`;
- questionnaire-only fields remain nullable;
- do not write `accept` directly; let central derivation handle it.

Seeders must be safe to run repeatedly without duplicate dictionaries/settings/admin rows.

### 19. Documentation

Update documentation that changed with Stage 2, at minimum:

- `docs/architecture.md` for the database/domain boundaries and state-machine approach;
- `docs/development.md` for migration/seed commands and database-session/queue local setup;
- `docs/project-status.md` for the actual completed Stage 2 state.

Update README only if necessary for actual developer setup changes.

Documentation must describe implemented state only.

## Out Of Scope

Do not implement:

- Stage 3 full Blade prototypes or visual redesign;
- authentication/login/logout middleware and role route groups;
- psychologist/admin CRUD controllers/forms;
- document upload/download endpoints;
- group CRUD/moderation controllers;
- actual free/paid group creation user flows;
- group activation/expiry/extension business effects;
- participant application API or processing UI;
- dictionary/settings admin CRUD;
- email, password setup links, SMTP, queue jobs, or scheduler commands;
- public-site HMAC/API integration;
- WEBPAY service/adapter, form generation, notify/return endpoints, API checks, payment confirmation, refunds, or credentials;
- production deployment.

Do not add unrequested packages unless a Stage 2 requirement cannot be implemented reasonably with Laravel/PHP itself.

Do not change `SPEC.md`, `WORKFLOW.md`, or `AGENTS.md` unless a real blocking contradiction is found. Stop and report such a contradiction instead.

## Constraints

- Follow `WORKFLOW.md` and `AGENTS.md`.
- Work only on this Stage 2 task.
- MySQL in Docker is the only supported automated database-test path.
- Do not use SQLite for tests.
- Use migrations; no manual database changes.
- Use varchar/string columns for lifecycle statuses, never MySQL enum.
- Use integer minor units for money; no float/decimal money storage.
- `status` is lifecycle truth; `accept` is compatibility-only.
- Multi-entity status/history writes are transactional.
- Keep code explicit and simple; do not build a generalized workflow/audit/settings framework.
- Preserve the accepted Stage 1 `/cabinet`, Docker, tooling, and repository boundaries.
- No secrets, production data, WEBPAY credentials, or unrelated artifacts.
- Do not alter `.ai/task.md`.

## Acceptance Criteria

1. A clean `migrate:fresh` on the dedicated MySQL test database creates the complete Stage 2 schema without errors.
2. Required `gp_*`, session, jobs/batching, and failed-jobs tables exist with the required indexes/constraints.
3. `migrate:fresh --seed` creates the approved local/testing admin, dictionary definitions, and settings.
4. Re-running migrations/seeders does not create duplicates or break the application.
5. `gp_users` enforces one active row per email through the generated-column unique-index strategy; after soft delete the same email can be used by a new active row.
6. `User`, `Group`, `Payment`, and related models have the required casts and relationships.
7. User, group, and payment status enums implement the exact allowed matrices, and automated tests reject every disallowed transition.
8. Invalid domain-service transitions throw a clear exception and do not partially change persisted state.
9. A group transition changes status and writes `gp_group_status_history` atomically.
10. The audit service records a representative non-sensitive action in `gp_audit_log`.
11. `accept` is derived centrally from status and is not independently maintained by seeds/business code.
12. Every new group automatically receives a unique UUID v4 `public_uuid`, and an attempted later mutation is rejected.
13. Payment `order_number` is unique; multiple null transaction ids are valid; duplicate non-null transaction ids are rejected.
14. Money fields are stored/cast as integer minor units and existing display formatting continues to work without float conversion.
15. Required dictionaries/settings are seeded without inventing unspecified dictionary item values or price amounts.
16. Known setting defaults are typed correctly; unconfigured prices are explicit and cannot silently become zero/free; cache/invalidation behavior is tested.
17. Local configuration uses database sessions and database queues; required Laravel tables exist.
18. MySQL schema tests verify the required indexes and MySQL-specific generated-column behavior.
19. No Stage 3+ controllers/UI/integrations/payment-provider behavior are implemented.
20. `php artisan test`, Pint, Larastan, and `composer check-platform-reqs` pass.
21. `docs/project-status.md` and relevant architecture/development documentation reflect the actual Stage 2 implementation.
22. Final diff is limited to Stage 2 data/domain foundation, related docs, and `.ai/report.md`.

## Checks

Run and report exact results. At minimum:

1. Confirm Docker services are healthy and the dedicated MySQL test database is selected.
2. On `gruppa_cabinet_test`, run a clean schema/seed verification using an appropriate isolated command such as:
   - `php artisan migrate:fresh --seed --env=testing` with the effective test DB configuration explicitly verified, or an equivalent safe test-database-only procedure.
3. Re-run migration/seed commands in a way that proves idempotency without touching `gruppa_cabinet`.
4. Run the full MySQL test suite:
   - `docker compose exec -T php php artisan test`
5. Include focused automated coverage for:
   - active email generated-column uniqueness + soft-delete reuse;
   - all enum transition matrices, including all forbidden pairs;
   - group transition/history atomicity;
   - audit write;
   - UUID generation/uniqueness/immutability;
   - nullable-unique payment transaction id semantics;
   - relationships;
   - settings typed/cache behavior;
   - test connection still being `mysql` / `gruppa_cabinet_test`.
6. Inspect MySQL metadata (`SHOW CREATE TABLE`, `information_schema`, or equivalent) for the generated column and required indexes.
7. Verify the normal development database `gruppa_cabinet` still exists and was not reset by tests.
8. Run:
   - `docker compose exec -T php ./vendor/bin/pint --test`
   - `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
   - `docker compose exec -T php composer check-platform-reqs`
9. Inspect the repository for accidental secrets, SQLite test fallback, WEBPAY code/credentials, frontend build tooling, or Stage 3+ scope.
10. Inspect `git diff`, `git status --short`, and staged files before commit.

If any required invariant cannot be implemented or verified without an unresolved product/architecture decision, mark the task `blocked` rather than inventing behavior.

## Hard Workflow Gate

Before changing files:

- read `WORKFLOW.md`, `AGENTS.md`, `SPEC.md`, `docs/project-status.md`, and this `.ai/task.md`;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm this task belongs to the latest relevant `planner:` commit;
- do not modify unknown local changes.

During implementation:

- stay within Stage 2 data/domain scope;
- do not begin Stage 3 or later features;
- do not alter `.ai/task.md`;
- do not change governance/spec files unless a real contradiction blocks the task;
- do not invent unspecified prices or user-facing dictionary values;
- do not add secrets or unrelated dependencies;
- keep all tests on MySQL.

Before commit:

- run the required checks above;
- update `.ai/report.md` with actual changed files, migrations, seeders, checks, facts, assumptions, unknowns, and risks/next step;
- inspect complete diff and staged files;
- stage only files belonging to this task plus `.ai/report.md`;
- confirm no secrets, generated runtime artifacts, or unrelated files are staged.

Completion:

- use `Status: done` only if the Stage 2 acceptance criteria are actually satisfied;
- otherwise use `partial`, `blocked`, or `failed`;
- if the gate passes, commit with:

```text
codex: TASK-2026-09-20-03 build database domain foundation
```

- do not create an `accept:` commit.
