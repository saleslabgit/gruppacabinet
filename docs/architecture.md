# Architecture

## Stage 2 data and domain foundation

The application persists its business data in the `gp_*` MySQL tables. Users,
groups, and payments use string-backed PHP enums for lifecycle status; each enum
owns its explicit transition matrix. Application code performs lifecycle changes
through the corresponding small transition service. A group status change and
its `gp_group_status_history` record are committed in one transaction.

`status` is the lifecycle source of truth. The legacy `accept` columns on users
and groups are derived on every model save by the central
`AcceptFromStatus` mapping and are not an independent workflow input.

Active user email uniqueness is enforced by the MySQL stored generated column
`gp_users.active_email`: it contains the email for non-deleted rows and `NULL`
for soft-deleted rows. A unique index on that technical column allows an email
to be reused after soft deletion while preventing two active matches.

Groups receive a random UUID v4 integration identifier when created. The model
rejects subsequent changes to that identifier. Internal numeric IDs remain the
keys for database relationships.

Settings are stored in `gp_settings` and read through `SettingService`, which
provides unit-specific typed methods, cached reads, and explicit invalidation.
Unknown prices remain `NULL`; they are never interpreted as zero. Non-status
administrative events can be written through `AuditService` using stable entity
and action codes with limited, non-sensitive metadata.

Soft deletion applies to users, groups, and payments. Foreign keys do not
cascade-delete payment, application, document, audit, or status-history data.
Database-backed session and queue tables are part of the application schema.

## Stage 1 topology

The repository has a strict runtime boundary:

```text
browser -> localhost:8080/cabinet/ -> Nginx -> PHP-FPM -> Laravel -> MySQL
```

`application/` is a standalone Laravel 12 application and is the only deployable source tree. `compose.yaml` and `docker/` create a local Nginx, PHP 8.2, Composer, and MySQL environment; none of that container configuration belongs to the production artifact.

MySQL data is stored in the Compose named volume `mysql-data`, outside `application/`. The Laravel directory is bind-mounted into PHP and read-only into Nginx during local development.

## Public base path

The application public URL includes `/cabinet`. Local Nginx maps only `/cabinet/` to `application/public/`, forwards front-controller requests to PHP-FPM with `/cabinet/index.php` as the script name, and redirects the local domain root to `/cabinet/`. Laravel receives the correct base URL and uses its route and asset helpers rather than hardcoded root-relative application paths.

Local `APP_URL` is `http://localhost:8080/cabinet`. The future production value can be `https://gruppa.info/cabinet`; the hosting rewrite and real production deployment remain unverified.

## Time and money

Laravel and the PHP container use UTC for application/runtime time. `App\Support\DateTimeFormatter` converts display values to `Europe/Minsk`.

Money remains integer minor units. `App\Support\MoneyFormatter` splits and groups the integer's decimal representation and never converts an amount through floating-point arithmetic.

## Frontend delivery

There is no frontend build pipeline. Bootstrap 5.3.8 CSS and bundle JS are committed under `application/public/vendor/bootstrap/5.3.8/`; project-owned `app.css` and `app.js` are also served directly from `public/`. Node.js, npm, Vite, and runtime asset compilation are not used.
