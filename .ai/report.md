# Report: TASK-2026-09-20-02

Status: done

## Summary

Corrected the Stage 1 Laravel test environment to use the dedicated `gruppa_cabinet_test` database on the Compose MySQL 8.4 service. SQLite and `:memory:` were removed from the committed PHPUnit configuration.

Added an idempotent one-shot Compose provisioning service outside `application/`. It creates the test database and grants the local `cabinet` user access on every stack start, including when the normal MySQL volume already exists. The PHP service starts only after provisioning succeeds.

## Changed Files

- `compose.yaml`: added `mysql-provision` and gated PHP startup on successful provisioning.
- `docker/mysql/provision-test-database.sh`: idempotent test-database creation and local-user grant.
- `application/phpunit.xml`: forced MySQL connection parameters and the dedicated test database for PHPUnit.
- `application/tests/Feature/TestDatabaseConnectionTest.php`: runtime proof of the MySQL driver, selected database, and real query.
- `README.md`, `docs/development.md`, `docs/project-status.md`: documented the Docker MySQL test path and separation from development data.
- `.ai/report.md`: this report.

## Test Database Setup

- Development database: `gruppa_cabinet`.
- Automated-test database: `gruppa_cabinet_test`.
- Both use the local-only `cabinet` / `cabinet_local` Compose account.
- `mysql-provision` connects with the existing local-only Compose root credential, runs `CREATE DATABASE IF NOT EXISTS`, and grants access only to `gruppa_cabinet_test`.
- PHPUnit uses forced `<server>` values because the PHP container already exposes development DB values in `$_SERVER`; this prevents the standard `php artisan test` command from inheriting `gruppa_cabinet`.

## Checks

- `docker compose config -q`: passed.
- Existing-volume provisioning via `docker compose up --force-recreate mysql-provision`: exited 0; rerunning normal `docker compose up -d` started and completed the provisioner again.
- Existing-volume schema query: returned both `gruppa_cabinet` and `gruppa_cabinet_test` after repeated provisioning.
- Fresh-volume provisioning under isolated Compose project `gruppacabinet-fresh-check`: created a new MySQL volume, MySQL became healthy, provisioner exited 0, and both databases existed. The `cabinet` user connected to `gruppa_cabinet_test` and `SELECT DATABASE(), 1` returned `gruppa_cabinet_test` and `1`. The isolated containers, network, and volume were then removed.
- `docker compose exec -T php php artisan test`: passed, 8 tests and 15 assertions.
- `TestDatabaseConnectionTest` proved from inside the Laravel suite that the driver is `mysql`, `Connection::getDatabaseName()` is `gruppa_cabinet_test`, `SELECT DATABASE()` returns `gruppa_cabinet_test`, and a real `SELECT 1` succeeds.
- `docker compose exec -T php ./vendor/bin/pint --test`: passed, 24 files.
- `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`: passed, no errors.
- `docker compose exec -T php composer check-platform-reqs`: passed on PHP 8.2.32, including `pdo_mysql`.

## Facts

- The standard Docker test command now uses MySQL and cannot silently select SQLite.
- The normal development database and test database are distinct and both remained present during verification.
- Provisioning is Docker-only and no Docker-specific file was added inside `application/`.
- No Stage 2 schema, model, seeder, authentication, UI, or product functionality was added.

## Assumptions

- The committed Compose credentials remain local-development-only as established in Stage 1.

## Unknowns / Risks

- Production deployment and production database provisioning remain outside Stage 1 and were not tested.
- Later schema tests must continue to use the dedicated test database; the new runtime assertion will fail if configuration regresses to another database.
