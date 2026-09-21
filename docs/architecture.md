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


## Stage 3 Blade frontend

The product views live in `resources/views/auth`, `errors`, `psychologist`, and
`admin`. Public, psychologist and admin layouts share `layouts/surface`.
`components` contains the actual reused controls, panels, status badges,
responsive tables, confirmations and formatting components; `shared` contains
reused product sections. `prototype` contains only the navigation catalog.
The Stage 1 diagnostic page and its assets remain separate.

Product layouts load local `ui.css`, `ui.js`, Bootstrap 5.3.8, and static
Montserrat 500/600 WOFF2 files with Cyrillic. Font source, SHA-256 and SIL OFL
license are under `public/fonts/montserrat`. There is no frontend build step.
Date and money components use the existing shared formatters.

`routes/prototype.php` registers GET-only routes under `/_prototype` only in
`local` or `testing`; production has no such routes. The development catalog
and fixture provider supply deterministic synthetic arrays, navigation links
and visual variants directly to the final product views. They do not query
models or write data. Prototype routes omit session/error-sharing/CSRF
middleware as a unit, so their GET rendering neither needs database sessions
nor emits session-dependent CSRF cookies. Other routes keep their middleware.

The views show final forms, state-dependent actions and confirmations, but
mutating controls are explicitly no-op. JavaScript only handles prototype
feedback, suppression of form submission, Bootstrap confirmation examples and
UUID clipboard copying. Prototype pages do not authenticate users, perform CRUD or call providers.
Later controllers must supply actual view data/links and connect validation,
authorization and actions to these same templates. See `docs/ui-pages.md` for
all direct variants; page structure is the accepted Stage 3 baseline.


## Stage 4 authentication and access

`GET /login` and `POST /login` (`login`, `login.store`) use the shared approved
login view, a LoginRequest and Laravel's `web` session guard. Email is trimmed
and lowercased. Authentication requires a non-deleted, approved, enabled user;
`accept` is never an access condition. All credential/account-state failures
share one generic message. Five failed attempts per normalized email + IP are
allowed in 60 seconds; the sixth is rejected before authentication. Success
clears that key, regenerates the session ID and redirects by role.

Protected routes use `account` (EnsureAccountAccess), which extends Laravel's
Authenticate middleware: it authenticates with `web`, reloads the account and
checks its current status/disabled flag, then permits the role check. An old
session whose user has disappeared or been soft-deleted is also invalidated.
Revocation logs out, invalidates the current session, regenerates the CSRF token
and redirects to login with a safe access-revoked message.
`role:psychologist` requires `admin=false`; `role:admin` requires `admin=true`.
Cross-role requests return 403. Guests redirect to the named login route.

`GET /` (`psychologist.home`) reuses the groups view in empty mode with creation
unavailable, without querying groups. `GET /admin` (`admin.home`) reuses the
admin home with its work queue unavailable. Both use real navigation URLs and
POST logout forms. There are no links to prototypes or future CRUD actions.
`POST /logout` uses normal web CSRF middleware, logs out, invalidates the session
and regenerates its CSRF token. GET logout is not registered.

Sessions use the existing database store. `SessionInvalidator::invalidate(User)`
rotates the user's remember token and deletes all their session rows from the
configured session connection/table, leaving other users and guests intact.
Stage 5 disable, reject and soft-delete actions call it within their database
transaction. No remember-me option is exposed.

The old database diagnostic is now `/_foundation`; it and `/redirect-check`
are registered only in local/testing, like the unchanged prototype catalog.
All real URLs are generated with Laravel helpers and retain `/cabinet`.


## Stage 5 psychologist administration

`admin.psychologists.*` routes under `/admin/psychologists` use the Stage 4
`account` and `role:admin` middleware. UserPolicy permits only active approved
administrators and non-admin, non-deleted targets. UserDocumentPolicy also
compares the document's user ID with the route parent on every view, download
and delete; changing either ID cannot bypass ownership.

PsychologistController reuses `admin/users/index`, `show`, and `form`.
PsychologistPages supplies explicit display fields (never credentials), real
navigation and validation/flash state. The list uses two pagination queries
plus request authentication, with no row-dependent queries; its ordering is
`created_at DESC, id DESC`. Search includes individual and combined name parts,
email and phone. Profile Form Requests whitelist questionnaire fields; only
creation accepts an initial tariff. Active-email validation is backed by the
existing generated-column unique index, with duplicate races translated into
an email validation error. Education choices come from active `education_type`
items; the target's existing inactive item remains selectable.

PsychologistActions locks the target and coordinates lifecycle transitions,
access/tariff updates, session revocation and minimal AuditService records in
one transaction on the existing MySQL/session connection. Approve/reject use
UserStatusTransitionService; invalid/repeated actions produce validation errors
without audit/state changes. Soft deletion preserves related records. Tariff
changes never rewrite group snapshots. Human-readable audit history includes
actors (including soft-deleted actors), dates and relevant state changes.
Create/approve do not set a password, send mail or dispatch jobs.

PsychologistDocumentController and PsychologistDocuments use the private
`local` disk at `storage/app/private`. Automatic local storage serving is
disabled; only policy-authorized controllers serve files. Upload validation
uses detected PDF/JPEG/PNG MIME, a configured size ceiling and an allowed
business document type. Random paths are independent of original names.
Original names are sanitized metadata; MIME and byte size are persisted.
Responses use explicit Content-Type, nosniff, private/no-store and sandbox
headers. No public or temporary storage URL is generated.

Failed DB persistence compensates by removing the newly written file. Delete
locks the document row, deletes the file then the row, and reports storage
failure rather than claiming success; a missing file can still have its stale
row removed on retry. Filesystem operations cannot share a MySQL transaction:
a commit failure after physical deletion may leave a stale row, removable by
retry. Restoring deleted documents is not part of this stage.

Production and prototype modes share the original views/components. Confirmed
real actions have CSRF-protected forms; prototype forms and buttons remain
no-op. There are no real Stage 6/7+ links or resend-invitation action.
