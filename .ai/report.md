# Report: TASK-2026-09-20-03

Status: done

## Summary

Implemented the Stage 2 MySQL data and domain foundation. The application now
has the required `gp_*`, database-session, and database-queue schema; Eloquent
models and relationships; explicit lifecycle enums and transition services;
atomic group status history; business audit storage; immutable generated group
UUIDs; centralized compatibility `accept` derivation; typed cached settings;
and idempotent local/testing seed data.

No Stage 3+ UI, controllers, authentication flow, public integration, mail,
scheduler behavior, WEBPAY requests, provider logic, or credentials were added.

## Changed Files

- `application/database/migrations/2026_09_20_000001_create_domain_tables.php`:
  complete ordered Stage 2 schema, foreign keys, generated active-email column,
  required indexes, sessions, jobs, batches, and failed jobs.
- `application/app/Models/*`: models, explicit `gp_*` tables, casts, soft deletes,
  and required relationships.
- `application/app/Enums/*`: exhaustive user, group, and payment transition
  matrices.
- `application/app/Services/*`: user/group/payment transition services,
  `AuditService`, and typed cached `SettingService`.
- `application/app/Domain/Compatibility/AcceptFromStatus.php`: single mapping
  for compatibility `accept` values.
- `application/app/Exceptions/InvalidStatusTransition.php`: clear invalid
  transition exception.
- `application/database/seeders/DatabaseSeeder.php`: idempotent dictionary
  containers, typed settings, and local/testing administrator.
- `application/tests/Unit/Enums/StatusTransitionMatrixTest.php`: every allowed
  and forbidden status pair.
- `application/tests/Feature/Domain/*`: MySQL schema/index/generated-column,
  constraint, UUID, transaction, relationship, audit, settings, and seed tests.
- `application/.env.example`: database session and queue defaults.
- `docs/architecture.md`, `docs/development.md`, `docs/project-status.md`: actual
  Stage 2 architecture, setup, seed behavior, scope, and remaining unknowns.
- `.ai/report.md`: this report.

## Checks

- Docker services: MySQL and PHP healthy; web running.
- Effective destructive-check target, bootstrapped through Laravel:
  `mysql gruppa_cabinet_test`.
- `APP_ENV=testing DB_DATABASE=gruppa_cabinet_test php artisan migrate:fresh
  --seed --force`: passed on the dedicated test database.
- Repeated `php artisan migrate --force`: `Nothing to migrate`.
- Repeated `php artisan db:seed --force`: passed without duplicates. Metadata
  query after repeated seeds returned 3 dictionaries, 7 settings, and 1 admin.
- `docker compose exec -T php php artisan test`: passed, 131 tests and 246
  assertions, using MySQL `gruppa_cabinet_test`.
- `docker compose exec -T php ./vendor/bin/pint --test`: passed, 51 files.
- `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`:
  passed, no errors.
- `docker compose exec -T php composer check-platform-reqs`: passed on PHP
  8.2.32, including `pdo_mysql`.
- `docker compose config -q`: passed.
- MySQL `information_schema` inspection confirmed `active_email` is `STORED
  GENERATED`, has the expected expression and unique index, and confirmed the
  required domain indexes.
- MySQL schema inspection confirmed both `gruppa_cabinet` and
  `gruppa_cabinet_test` still exist; only the test database was reset.
- Repository searches found no WEBPAY credentials/provider code, frontend
  tooling, or Stage 3+ implementation in the new scope. PHPUnit remains forced
  to MySQL `gruppa_cabinet_test`; no SQLite test path was introduced.
- `git diff --check` and `git diff --cached --check`: passed. Staged inspection
  contains 32 task files only; governance/spec/task files, secrets, and runtime
  artifacts are not staged.

Intermediate verification found and resolved two test/schema issues (JSON key
order and explicitly required standalone indexes) plus Larastan enum property
typing. The final checks above are the post-fix results.

## Facts

- Active-email uniqueness is enforced by MySQL rather than application-only
  validation, and soft-deleted emails can be reused.
- Group status changes and history inserts share one database transaction and
  a failed history write rolls back the status change.
- `accept` is derived from status during model saves; seeds and services do not
  set it independently.
- Payment and meeting money fields use unsigned integer minor units and integer
  casts; no float/decimal money storage was added.
- Multiple `NULL` payment transaction IDs are allowed; duplicate non-null IDs
  and duplicate order numbers are rejected by unique indexes.
- Local/testing seed credentials are intentionally fixed only outside
  production and are documented in `docs/development.md`.

## Assumptions

- The task-approved column names are the conventional English snake_case names
  implemented in the migration.
- `participant_capacity` represents the single group-size field specified as
  “количество человек”.
- A payment always belongs to the group whose placement or extension it pays
  for, so `gp_payments.group_id` is non-nullable.

## Unknowns

- Placement and extension price values remain explicitly `NULL`.
- Dictionary item display values remain unseeded because no approved values are
  specified.
- Production infrastructure, SMTP, public-site integration, queue worker, and
  WEBPAY behavior remain unverified and out of scope.

## Risks / Next Step

Stage 2 is complete. The next planned milestone may build the approved Stage 3
Blade prototypes on this schema without introducing real authentication, CRUD,
or WEBPAY behavior prematurely.
