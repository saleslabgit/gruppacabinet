# Report: TASK-2026-09-21-03

Status: done

## Summary

Implemented Stage 5 administrator psychologist CRUD, confirmed moderation,
tariff/access management, soft deletion, private documents, audit history and
immediate database-session revocation. The accepted Stage 3 Blade pages are
shared by real routes and the unchanged synthetic prototype catalogue.

Startup confirmed planner `20f85c7` and its parent/base
`2fdf26b751c27ec6e0a60e419085d5ecab2a43cc`; the working tree was clean.
No migrations, packages or frontend build tooling were added. `.ai/task.md`,
AGENTS.md, WORKFLOW.md and SPEC.md were not changed.

## Changed Files

- Controllers: `application/app/Http/Controllers/Admin/PsychologistController.php`,
  `PsychologistDocumentController.php`, and the existing `HomeController.php`.
- Requests: `application/app/Http/Requests/Admin/PsychologistRequest.php`,
  `PsychologistActionRequest.php`, `DocumentRequest.php`.
- Policies: `application/app/Policies/UserPolicy.php`, `UserDocumentPolicy.php`.
- Services/display data: `application/app/Services/PsychologistActions.php`,
  `PsychologistDocuments.php`, `application/app/Support/PsychologistPages.php`.
- Existing User model: relationship/cast type annotations only.
- Routing/config: `application/routes/web.php`,
  `application/config/psychologist_documents.php`, `filesystems.php`,
  `application/.env.example`.
- Existing views: all four `admin/users/*` pages, admin home, shared documents/profile data,
  and the shared confirmation component. No parallel page implementations or
  shared CSS/JavaScript redesign.
- Tests: `application/tests/Feature/PsychologistAdminTest.php`,
  `PsychologistDocumentTest.php`.
- Local upload transport ceilings: `compose.yaml`,
  `docker/nginx/default.conf`, `docker/php/uploads.ini`.
- Documentation: `docs/architecture.md`, `development.md`, `project-status.md`,
  `ui-pages.md`, and this report.

## Implementation Facts

### Routes, authorization and forms

`admin.psychologists.*` registers 17 real routes under `/admin/psychologists`:
index, create/store, show, edit/update, approve, reject, enable, disable, tariff,
destroy, documents index/store/view/download/destroy. All use `web`, `account`
and `role:admin`. Policies reject admin targets, inactive actors and mismatched
document parents. Normal binding excludes soft-deleted psychologists.

Real navigation is Home / Psychologists / Logout; there are no production
links into prototypes or future group/payment/settings/email stages.
CSRF-protected forms use Form Requests, old input, field errors and success
notices. Status/tariff/access/delete actions require explicit confirmation.

Profile requests whitelist questionnaire fields and normalize email. Create
forces pending, non-admin, enabled, null password; only creation accepts the
initial tariff. Updates cannot write status/accept/admin/credentials/tariff/
access/deletion fields. Active-email validation retains the MySQL unique
constraint and catches duplicate-key races. Education options use active DB
items plus the existing inactive selection; no item values were invented.
Consent input converts Minsk time to UTC; display uses the shared formatter.

### List and detail

Search covers individual/full name, email and phone; status/free-paid filters
and 20-row pagination retain query parameters. Ordering is created_at DESC,
id DESC. Admin and soft-deleted rows are excluded. The query regression compares
small and populated lists: exactly 3 queries including the request-level
account lookup (two for pagination plus one account eligibility query).
Detail shows nullable questionnaire/education/license/confirmation/consent data,
registration, tariff/access/status, document/group counts and chronological
human-readable audit with actor and relevant minimal state changes. Credentials
are not passed into page data. There is no working resend-password action.

### Actions, audit and sessions

PsychologistActions locks the target and coordinates existing
UserStatusTransitionService, AuditService and SessionInvalidator in a MySQL
transaction. Invalid transitions and repeated access/tariff changes return
validation errors without duplicate audit. Approve/reject never assign a
password or send mail. Disable/reject/delete rotate remember_token and delete
only target sessions. Enable does not create sessions. Tariff changes preserve
existing group snapshots. Soft deletion preserves historical relations.

All six mandatory audit actions use the current admin actor and only old/new
status, access or tariff values; deletion has no questionnaire metadata.
Injected audit failure verifies rollback of state, token and session deletion.

### Documents and filesystem behavior

Configuration defines the private local disk, business document types and
actual PDF/JPEG/PNG MIME allowlist. `PSYCHOLOGIST_DOCUMENT_MAX_KB=10240` is a
technical ceiling. Random storage paths are independent of original names;
safe original names, detected MIME and byte size are metadata. Automatic local
storage serving is disabled. Only authorized controller responses serve files,
with safe MIME/disposition, nosniff, private/no-store and sandbox headers.

Failed row persistence removes the just-written file. Confirmed deletion
checks physical deletion before deleting the row; failure does not report
success. Already absent files permit stale-row cleanup. No public URL or
Storage temporary URL is used.

Local nginx/PHP defaults were too small for the specified 10 MiB ceiling, so
Docker now permits 10 MiB files and 12 MiB multipart requests. Production
hosting configuration is separate and documented.

## Checks

Executed on local Docker/MySQL; no SQLite or destructive reset of the normal
development database was used.

- `docker compose ps`: MySQL/PHP healthy, nginx running.
- `docker compose up -d --no-deps php web`: applied upload-limit configuration.
- `docker compose exec -T php php artisan migrate --force`: nothing to migrate.
- `docker compose exec -T php php artisan db:seed --force`: successful,
  idempotent local seeding.
- Full `docker compose exec -T php php artisan test`: passed 190 tests / 1645
  assertions (final full run, 134.47 seconds).
- Targeted CRUD/documents plus Stage 4 authentication: 42 passed / 491 assertions.
- Final focused search/filter/pagination/query-count test, including search `0`:
  1 passed / 34 assertions; exactly 3 queries for both small/populated lists.
- Prototype suite includes all 31 groups / 249 variants without business DB
  queries and checks production route isolation.
- `docker compose exec -T php ./vendor/bin/pint --test`: PASS, 77 files.
- `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`: PASS,
  no errors. An earlier cold run exhausted 128 MiB; rerun with
  `--memory-limit=512M` passed, and later exact standard commands also passed.
- `docker compose exec -T php composer check-platform-reqs`: all requirements
  passed, PHP 8.2.32.
- `docker compose exec -T php php artisan view:cache`: successful.
- `docker compose exec -T php php artisan route:list --path=admin -v`: all
  real Stage 5 routes have account/admin middleware.
- `docker compose exec -T php php artisan route:list --env=production --except-vendor`:
  22 application routes; no prototype, foundation or public-storage routes.
- Inspected real private/public storage: only their `.gitignore` files remained
  after smoke cleanup; zero document rows and zero smoke users remained.
- Inspected representative runtime audit records: all six action names,
  correct current-admin actor, minimal state-only metadata, no sensitive data.

### Real Docker HTTP/browser smoke

Executed external Playwright/installed Chromium against
`http://localhost:8080/cabinet`, using temporary scripts/artifacts under `/tmp`.
The configured MCP browser executable was missing, so an already installed
Chromium was used directly; no project dependency was added.

Passed real login/navigation, create/edit, search/status/tariff filters,
approve/reject, tariff, disable/enable/soft-delete, document upload/view/download/
delete, CSRF forms and modal submission. Verified target session rows disappear
on disable/reject/delete, approved accounts retain null password, and queue
row count does not change. Mail/queue fakes also assert no dispatch in tests.

Actual 10 MiB PDF upload succeeded through nginx/PHP. A 10 MiB + 1 byte file
and forged text/PHP content named `.pdf` were rejected. Actual PDF/JPEG/PNG
contents were separately tested using real temporary files, not MIME-guessing
upload fakes. Checked private physical file presence and public absence.
Authorized view/download returned correct content and disposition; substituted
parent IDs, administrator target IDs and psychologist-role requests returned
403; guessed public storage and soft-deleted profile paths returned 404.

Inspected browser screenshots at 1440/1024/390 px and the detail page on mobile;
no horizontal overflow or browser JS errors. A focused final browser check
passed Russian duplicate-email/MIME messages and chronological audit order.
Only newly created synthetic smoke records/uploads were cleaned up.

### Intermediate failures and verification limits

An initial existing throttle-expiry test failed once, then passed in the
42-test regression and complete 190-test rerun without authentication changes.
An early new-test run used the wrong test base URL; tests now configure it
consistently with the repository. MySQL JSON key ordering and file-fake MIME
behavior were corrected in tests. One run was interrupted by the local
container recreation and was fully rerun. None is reported as a passing run.

Production hosting, real mail and external systems were not exercised; they
are outside Stage 5. No production data or real personal data was used.

## Assumptions

The deployed Stage 4 database session store uses the same MySQL connection as
business tables; this makes state/audit/session revocation transactional.
The technical upload ceiling must be aligned with PHP and web-server limits
when hosting configuration or the ceiling changes.

## Unknowns

Approved education dictionary values are still unavailable; empty real DB
options are intentional. Invitation/resend email, restore and later-stage
psychologist/group/payment/API features remain out of scope.

## Risks / Next Step

MySQL and filesystem deletion cannot be one atomic transaction. If the DB
commit fails after physical deletion, a stale row may remain; the operation
fails rather than reporting success, and retry removes the stale row. This
behavior and storage-failure handling are documented. No restore is provided.

Implementation and verification are complete. Diff inspection is limited to
Stage 5 code/config/tests/docs and this report; no uploads, `.env`, real data,
secrets, screenshots or temporary browser scripts are included. The prescribed
`codex:` commit records this iteration; no `accept:` commit is created.

Manual acceptance: log in at `/cabinet/login` as the development administrator,
open «Психологи», create/edit a synthetic profile, exercise the confirmed
actions and the document upload/view/download/delete flow. Email setup and
other later stages remain intentionally unavailable.
