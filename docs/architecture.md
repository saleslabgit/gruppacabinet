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

`GET /` (`psychologist.home`) renders the current owner’s real paginated groups
and a CSRF-protected draft creation action (Stage 7). `GET /admin` (`admin.home`) reuses the
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
no-op. Group navigation is connected in Stage 7; there is no resend-invitation action.

## Stage 6 psychologist profile and owner documents

`GET /profile` (`psychologist.profile`) uses the authenticated account, with no
user ID in the route. It loads only education and documents and renders the
accepted `psychologist/profile/show`, `shared/profile-data` and shared document
table. `PsychologistPages::profile()` supplies the same explicit questionnaire
mapping used by administrators; credentials and internal columns are excluded.
Dates retain the existing shared display conventions. PsychologistCabinetPages
supplies Home/Profile navigation and POST logout for both real cabinet pages.
The root renders real owned groups through Stage 7; the profile still does not query groups.

`GET /profile/documents/{document}/view` and `/download` use `account` and
`role:psychologist`. The controller looks up the document through the current
user's documents relation before applying `UserDocumentPolicy::viewOwn`.
Cross-owner IDs return 404; admins receive 403 on these psychologist routes.
The policy also requires a non-deleted, approved, enabled, non-admin owner.
Existing middleware revokes ineligible sessions before profile/file handling.

Both owner and admin controllers authorize first, then call the shared
`PsychologistDocuments::response()` for private streaming, file existence and
MIME allowlist checks, safe filenames, inline/attachment disposition, nosniff,
private/no-store and sandbox headers. Admin authorization is unchanged.
The shared table receives explicit per-document view/download URLs and an
optional delete URL. Only admin management supplies delete; owner pages expose
no upload, delete or profile editing controls or mutation routes. Prototype
fixtures and no-op actions remain separate and unchanged.

## Stage 7 group workflow

Owner routes use `account` + `role:psychologist`; numeric group IDs are looked
up with `owner_id = authenticated user` before policy checks, including form
validation. Foreign and deleted IDs return 404. Admin routes use normal model
binding, `role:admin` and `GroupPolicy`. Policies recheck current lifecycle and
ownership inside each workflow transaction after locking the group row.

`GroupWorkflow` atomically creates draft + initial history, saves questionnaire
fields, submits, moderates, activates and soft-deletes. All later status writes
use the existing `GroupStatusTransitionService` and its history transaction on
the same connection/row. There is no duplicated transition matrix or generic
workflow framework. Invalid/repeated actions fail without partial content,
comments, dates or history. Revision/rejection comments require 10–16000 trimmed
characters and persist independently in history; current fields show the latest
message. History and actors (including soft-deleted actors) are eager loaded in
chronological timestamp/ID order.

Owner edits/submission require enabled draft/revision groups. Admin edits are
allowed in every lifecycle state. `GroupRequest` explicitly allows only the
questionnaire fields; owner, UUID, tariff snapshot, lifecycle fields and current
moderation messages cannot be edited. Creation uses the existing model UUID and
owner tariff snapshot invariants. Both free and paid owners start in draft.
Dictionary options use active `group_format`/`gender` items plus the current
inactive reference. Money validation accepts up to 16 whole decimal digits and
at most two fractional digits, dot or comma, then converts with integer/string
operations. Text limits are 16000 characters, within MySQL TEXT byte capacity.

Activation requires confirmed manual publication and reads the current
`SettingService::placementDurationDays()`. It snapshots duration and UTC now,
sets expiry to now plus duration, clears the warning marker, then transitions
approved → active with admin history. The integration UUID/copy/reminder block
uses the accepted template; no external request or second public-site ID exists.

Lists paginate 20 rows, eagerly load dictionaries/owners and never query
payments or applications. Admin search supports ID/title/owner name/email,
status/tariff filters, allowlisted descending date sorting with ID tie-break,
and approved/abandoned quick filters. `GroupIndexRequest` validates filters.
Deletion alone checks historical succeeded, unrefunded payments, including
soft-deleted payment records. Owner deletion requires enabled draft/rejected;
admin deletion requires draft created at or before the configured abandoned
cutoff. Both are soft deletes preserving all related records.

The technical cleanup threshold is `config/groups.php: abandoned_draft_days`
(default 30), separate from placement business settings. All destructive and
admin lifecycle actions require `confirmed` via `GroupActionRequest`. Modal
comment controls are associated with CSRF-protected confirmation forms through
the standard HTML `form` attribute.

Real views expose no payment actions or application links/counters; Stage 9
connects free extension to the accepted pages. Applications are explicitly unavailable. There are no payment
writes or transitions to awaiting_payment. Prototype fixtures and their no-op
variants remain available only in local/testing using the same Blade files.

## Stage 8 dictionary administration and business settings

`admin.dictionaries.*`, `admin.settings.*` and the GET-only `admin.payments.index`
use the existing account/admin middleware. DictionaryPolicy and SettingPolicy
require an approved, enabled, non-deleted administrator. Nested dictionary/item
routes use scoped model bindings through `Dictionary::items`; a mismatched
parent/item pair returns 404 before any mutation.

Dictionary codes are create-only lowercase `[a-z0-9_]` identifiers (64 characters).
Names remain editable. The core containers `education_type`, `group_format`,
`gender` cannot be deleted; custom containers must be empty. Item codes are
unique within their parent and immutable through update requests. Explicit
field allowlists exclude IDs, timestamps and foreign parent reassignment.
Lists paginate 20 rows, with code/ID or sort_order/ID ordering; counts and usage
are selected with subqueries rather than queries from Blade.

`DictionaryUsage` centralizes current schema references: education_type →
users.education_type_id, group_format → groups.format_id, gender → groups.gender_id.
Its queries deliberately include soft-deleted records. `DictionaryManagement`
locks the parent/item and checks usage inside its transaction; existing RESTRICT
foreign keys also prevent a concurrent reference from being silently removed.
Used items can be deactivated, never physically deleted. Unused items and empty
custom containers require explicit destructive confirmation. Activation is
idempotent. Editing an active item to inactive also requires confirmation.
Existing Stage 5/7 form queries immediately see additions/reactivation, hide
inactive items for new records, and preserve current inactive selections.

`SettingService::update(array $values, User $actor)` accepts exactly the seven
known keys with normalized PHP integers (null only for the two prices). It locks
existing rows in ID order, rejects missing rows/unknown keys/invalid types, and
writes integer values and minimal `setting.updated` AuditService entries in one
transaction. Metadata contains only key/old_value/new_value; unchanged values
produce no audit. Cache invalidation is registered with DB::afterCommit, including
when called inside an outer transaction. Reads within a transaction bypass the
shared cache so uncommitted values cannot leak into it; normal committed reads
retain the typed cached boundary. Tests of cache persistence and invalidation
use real commits rather than RefreshDatabase's enclosing test transaction.

Human BYN input is parsed by BynAmount using decimal strings, with comma or dot
and at most two fraction digits; there is no float conversion. The maximum is
PHP_INT_MAX minor units. sort_order is bounded by MySQL unsigned INT (4294967295).
Duration/count inputs are positive PHP integers, warning days must be less than
placement days. The placement duration has a technical maximum of whole days
remaining until MySQL TIMESTAMP's 2038-01-19 03:14:07 UTC ceiling at save time.
This is a storage/date boundary, not a product limit. Existing active group
snapshots are never rewritten; later activation reads the saved duration.

The shared confirmation accepts an optional existing form ID. Its submit button
belongs to that form and sends `confirmed=1`, preserving CSRF, method override,
all edited fields and browser validation without nested forms. Previous URL-based
confirmations and prototype no-op behavior remain supported.

The real Payments view is an informational pre-WEBPAY surface, with no filters,
synthetic rows, detail/refund links or mutations. Rendering does not query payment
or notification tables. Settings changes do not create or modify payments/groups,
and the temporary Stage 7 no-payment path for both tariffs remains unchanged.

## Stage 9 placement lifecycle and free extension

`groups:expire` runs every minute with a scheduler `withoutOverlapping` guard.
`GroupLifecycleService` selects due, non-deleted active IDs using the existing
status/expiry index in chunks of 200. Each candidate is re-read under a row lock
inside its own transaction. Only a still-active row with `expires_at <= now UTC`
transitions through `GroupStatusTransitionService` to expired. The history has
system actor, null actor ID and null comment. Disabled groups also expire;
null expiry, other statuses and soft-deleted groups are ignored. Correctness
comes from the lock/re-check, independently of the scheduler cache lock.

Presentation computes `max(0, ceil((expires_at - now) / 86400))` remaining days.
Warning applies to future expiry within the current `expiry_warning_days`.
Overdue active rows explicitly show that expiry has passed and status is awaiting
update. Page rendering and expiration never write `expiry_warning_sent_at`, send
mail, or queue jobs. Lists read the two lifecycle settings once per calculation
and use loaded owners (the authenticated current owner for the owner list).
The admin `quick=expired` filter remains paginated/sortable and reminds the admin
to unpublish on gruppa.info manually; no separate unpublication state is stored.

Owner-scoped GET/POST `/groups/{group}/extension` use account/psychologist
middleware and `GroupPolicy::extend`. Confirmation and CSRF protect the POST.
The service locks/re-reads the current owner, then the owned group, and checks
policy again. Only current `gp_users.free=true` can extend; `gp_groups.free`
remains the immutable historical tariff snapshot. Paid owners see information
about unavailable paid extension; direct POST is rejected without date/status
changes, payments, provider calls or payment routes.

Active extension adds the stored `placement_days` to the future expiry, keeps
status/published_at/duration and clears the warning marker. Missing/invalid dates
or duration and overflow beyond the MySQL TIMESTAMP ceiling are validation
errors. One application call performs one update; separate confirmed requests
are separate extensions. An overdue active row is deliberately rejected with a
refresh/wait message until the expiration command updates its status. It cannot
be extended from an already elapsed expiry as an active placement.

Expired extension evaluates the current `expired_extension_window_days` at
read/action time. The exact `expires_at + window` boundary is allowed. Later
requests are rejected and the UI offers creation of a new group. There is no
persisted deadline. Eligible extension transitions expired → approved with a
system actor, keeping old placement dates and all content/UUID/tariff fields.
Admin detail identifies re-publication from the latest real status history.
Existing manual activation then sets new UTC dates and snapshots the current
placement duration; moderation is not repeated. No Stage 9 path creates a
payment, email or queued warning job.

## Stage 10 internal participant applications

Owner endpoints are nested under `/groups/{group}/applications`. The account and
psychologist middleware run before an owner-scoped group lookup and a nested
application lookup. Foreign or mismatched IDs return 404. Existing applications
remain accessible regardless of group lifecycle status or disabled flag; deleted
groups are excluded from owner routes. GroupApplicationPolicy checks eligible
accounts and ownership through the group; administrators can read but cannot
process. Admin lists include historical soft-deleted groups/owners via explicit
eager loading, without changing global relationship scopes.

`processed_at` is the sole processing state. ApplicationWorkflow re-reads and
locks the owned group and application inside a transaction, authorizes the actor,
and changes the timestamp only on a state transition. Processing uses UTC;
repeated same-state requests leave both processed_at and updated_at unchanged.
POST forms use CSRF. No payment, email, audit, queue or group transition occurs.

Owner group lists use three aliased `withCount` subqueries in the main query;
detail loads the same counts and one latest application. Application lists use
20-row pagination, created_at DESC/id DESC and preserved query strings. Owner
rows reuse the resolved group; admin rows eager-load group and owner. The admin
search combines participant name, raw/canonical phone, group title and owner
name/email. No application surface queries payments.

PhoneNormalizer accepts explicit `+` or `00` international notation, removes
spaces/parentheses/hyphens/dots, and requires 7–15 digits starting with a nonzero
digit. Bare/local, malformed and overlong values fail explicitly without echoing
the phone in the exception. This is syntactic normalization, not a country or
subscriber validity check. No country code is guessed. digitsForSearch accepts
phone-like punctuation and yields comparable digits (stripping the international
00 prefix); arbitrary text yields an empty key and still uses textual search.
Stage 11 reuses this boundary. The synthetic factory requires an existing
group via `for($group)`, uses reserved fictional NANP numbers and derives the
canonical phone from its raw phone, including attribute overrides. Production
seeds do not create applications; signed intake is provided by Stage 11 below.

`applications:cleanup` reads the current typed retention-month setting once,
computes a UTC calendar-month cutoff without month overflow, and physically
deletes only created_at < cutoff. Exact equality remains. A chunkById scan of
500 IDs avoids deletion pagination gaps; each DELETE rechecks the cutoff and
concurrent deletes are harmless. It includes processed/unprocessed applications
and deleted parents, never deletes groups/users, and prints only the aggregate
count. The daily scheduler has withoutOverlapping; groups:expire remains every
minute. Running the scheduler/cron is still an operational prerequisite.

## Stage 11 incoming API boundary

`routes/api.php` registers stateless v1 intake separately from web sessions/CSRF.
The dedicated Laravel limiter runs before `AuthenticateIntegration`; it uses
source IP + endpoint, configured shared cache and a configurable default of
60/minute. Optional exact-IP allowlisting uses the trusted Laravel request IP.
`config/integration.php` is env-backed and fails closed without a secret.

Authentication binds method/path/timestamp/request ID/payload digest with HMAC
and `hash_equals`. JSON uses raw body bytes; standard FPM multipart parsing uses
the exact signed manifest field plus verified per-file hashes/sizes. Global text
normalizers skip the API so signed strings stay intact. `IntakeData` validates an
explicit field whitelist and normalizes fields for semantic fingerprinting;
unsigned query/form fields, undeclared files and protected fields are rejected.

`IntakeService` owns one MySQL transaction: no-op unique-ID upsert (waits for a
concurrent claimant), locked journal read, semantic conflict/replay decision,
business writes and exact serialized response completion. The case-sensitive
`gp_integration_requests.request_id` unique index is authoritative. Failed
transactions do not persist claims. Bounded retries handle MySQL deadlocks and
active-email insert races. No process-local lock or cache is authoritative.
The journal stores endpoint, SHA-256 fingerprint, response status/body, completion
and timestamps; no request payload/PII/file bytes/signature/secret. Retention is
not automatically shortened in Stage 11.

Questionnaire intake locks matching users including deleted rows, resolves
education by dictionary code, uses `UserStatusTransitionService` for rejected
resubmission, and reuses `PsychologistDocuments` with signed sanitized filenames.
New private paths are tracked through transaction completion and removed on
failure, including journal/storage failures. Application intake locks the group
by immutable UUID, requires active/enabled, uses `PhoneNormalizer`, and creates
through the group relationship. Existing owner/admin pages and policies provide
visibility and isolation; no alternate UI exists.

API exceptions render a safe JSON envelope; web handling is unchanged. API
reporting suppresses default exception logging because SQL/messages/trace arguments
may contain sensitive input. The renderer logs only reason, endpoint, safe request
ID, IP, UTC time and exception class/source location. No payload, names, phones,
file metadata, signatures or secrets are logged. Stage 11 has no email/password
invitation, job, payment or group lifecycle effect. See `integration.md` for the
external contract and deployment prerequisites.
