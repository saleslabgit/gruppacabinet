# Report: TASK-2026-09-20-04

Status: done

## Summary

Corrected both rejected Stage 2 group-creation invariants at the Eloquent model
creation boundary.

Every new group now loads its persisted owner by `owner_id` and snapshots the
owner's current `free` value. Every creation also unconditionally replaces any
candidate `public_uuid` with a new application-generated UUID v4. Existing
post-create UUID mutation protection remains unchanged.

No migration, documentation change, Stage 3 work, controller, payment flow, or
other product behavior was added.

## Changed Files

- `application/app/Models/Group.php`: guarded application-owned `free` and
  `public_uuid`; creation now resolves the persisted owner, copies its current
  tariff, and always generates a fresh UUID v4.
- `application/tests/Feature/Domain/GroupCreationInvariantTest.php`: focused
  MySQL-backed coverage for tariff snapshots, caller input, tariff changes,
  generated UUID ownership, uniqueness, format, immutability, persisted values,
  and missing-owner failure.
- `.ai/report.md`: this report.

## Checks

- `docker compose exec -T php php artisan test
  tests/Feature/Domain/GroupCreationInvariantTest.php`: passed, 8 tests and 11
  assertions.
- `docker compose exec -T php php artisan test`: passed, 139 tests and 257
  assertions.
- The full suite's `TestDatabaseConnectionTest` passed and confirmed the
  `mysql` driver with database `gruppa_cabinet_test`.
- `docker compose exec -T php ./vendor/bin/pint --test`: passed, 52 files.
- `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`:
  passed, no errors.
- `docker compose exec -T php composer check-platform-reqs`: passed on PHP
  8.2.32, including `pdo_mysql`.
- `git diff --check` and `git diff --cached --check`: passed. Staged inspection
  contains only the model correction, focused test, and this report.
- Scope scan found no migration, Stage 3/UI file, WEBPAY code, credential,
  dependency, or unrelated product change.

## Facts

- `Group::$guarded` now includes `public_uuid` and `free`, so normal mass
  assignment does not accept caller ownership of those fields.
- The `creating` model event uses `User::query()->findOrFail(owner_id)`, so the
  snapshot comes from the persisted owner and a missing owner fails clearly.
- The same event assigns `Str::uuid()` unconditionally and copies the persisted
  owner's boolean `free` value immediately before INSERT. This also overrides
  values assigned directly before the first save.
- Tests refresh persisted models before asserting snapshot and UUID values.
- Changing a user's tariff does not update existing group snapshots; groups
  created afterward receive the new tariff.
- The existing `saving` check still throws when `public_uuid` is changed after
  creation, and the database value remains unchanged.
- The existing UUID unique index and all Stage 2 migrations are unchanged.
- `docs/project-status.md` already describes generated immutable UUIDs at the
  appropriate high level and remains truthful, so it was not changed.

## Assumptions

- A soft-deleted owner is not a valid owner for new group creation because the
  normal `User` query scope does not resolve it.

## Unknowns

- None for this correction.

## Risks / Next Step

Both review blockers are corrected. Stage 2 can be reviewed again; Stage 3 was
not started.
