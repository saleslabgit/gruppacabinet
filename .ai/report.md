# Report: TASK-2026-09-21-02

Status: done

## Summary

Implemented Stage 4 authentication/access on the accepted Stage 3 Blade views.
Laravel's `web` guard authenticates approved, enabled, non-deleted users; all
credential/account-state failures share the same message. Login normalizes
email, limits failed attempts to five per email/IP in 60 seconds, clears the
counter on success and regenerates the session ID. No remember-me option.

Protected requests authenticate, reload the account, revoke ineligible sessions
and enforce psychologist/admin role boundaries. Deleted/missing users with an
old guard session also have that session invalidated. SessionInvalidator rotates
the remember token and removes all database sessions belonging to its target.

The psychologist home uses the approved empty groups view with creation disabled;
the admin home uses its existing view with the work queue explicitly unavailable.
Shared navigation submits CSRF-protected POST logout forms. Prototypes retain
no-op behavior, synthetic queues and all 249 variants. No Stage 5+ actions added.

## Routes and middleware

| Method | Path (relative to /cabinet) | Name | Middleware |
| --- | --- | --- | --- |
| GET/HEAD | /login | login | web |
| POST | /login | login.store | web; LoginRequest validation/throttle |
| POST | /logout | logout | web, including CSRF |
| GET/HEAD | / | psychologist.home | web, account, role:psychologist |
| GET/HEAD | /admin | admin.home | web, account, role:admin |

`account` is EnsureAccountAccess, extending Laravel Authenticate: authentication
with `web` runs before the fresh eligibility check and role middleware. Guest
redirects use the named login route. Revocation/logout invalidate the current
session and regenerate its CSRF token. Cross-role access is 403; GET logout is 405.
`/_foundation`, `/redirect-check` and `/_prototype/*` exist only in local/testing.

## Changed Files

- `application/app/Http/Controllers/HomeController.php`
- `application/app/Http/Controllers/SessionController.php`
- `application/app/Http/Requests/LoginRequest.php`
- `application/app/Http/Middleware/EnsureAccountAccess.php`
- `application/app/Http/Middleware/RequireRole.php`
- `application/app/Services/SessionInvalidator.php`
- `application/bootstrap/app.php`
- `application/routes/web.php`
- `application/database/seeders/DatabaseSeeder.php`
- `application/resources/views/auth/login.blade.php`
- `application/resources/views/admin/home.blade.php`
- `application/resources/views/psychologist/groups/index.blade.php`
- `application/resources/views/layouts/surface.blade.php`
- `application/resources/views/components/button.blade.php`
- `application/resources/views/components/navbar.blade.php`
- `application/resources/views/components/sidebar.blade.php`
- `application/phpunit.xml`
- `application/tests/Feature/AuthenticationTest.php`
- `application/tests/Feature/Domain/SettingsAndSeedTest.php`
- `application/tests/Feature/FoundationPageTest.php`
- `application/tests/Feature/PrototypeTest.php`
- `docs/architecture.md`
- `docs/development.md`
- `docs/project-status.md`
- `docs/ui-pages.md`
- `.ai/report.md`

No new migrations, dependencies, frontend tooling, CSS or domain transitions.
The ignored local `application/.env` was changed only from `SESSION_DRIVER=file`
to `SESSION_DRIVER=database`; it is not included in the commit.

## Checks

- `docker compose ps`: MySQL and PHP healthy; web running on port 8080.
- Initial `docker compose exec -T php php artisan db:seed` found that the local
  development schema had not been migrated. Ran
  `docker compose exec -T php php artisan migrate --no-interaction`: existing
  `2026_09_20_000001_create_domain_tables` completed successfully. No reset used.
- `docker compose exec -T php php artisan db:seed`: passed twice after migration.
  A bootstrapped read-only query confirmed exactly one admin and one psychologist.
- `docker compose exec -T php php artisan test`: **170 passed, 1283 assertions**,
  87.86 seconds. Includes MySQL domain regression, 31 groups/249 prototype variants,
  production route isolation, seed idempotency and production seed exclusion.
- Authentication coverage: both roles, generic failures (including nullable
  password), validation, fifth/sixth attempt threshold, no authentication when
  throttled, 60-second expiry, success reset, email/IP isolation, base-path URLs,
  role boundaries, disabled/rejected/pending/deleted/missing account revocation,
  unchanged approved sessions, MySQL session deletion, token rotation and logout.
- After strengthening fixation coverage to explicitly replay the original cookie
  and verify old MySQL session deletion plus preservation of session data, ran
  `docker compose exec -T php php artisan test tests/Feature/AuthenticationTest.php --filter=login_regenerates_session_and_obeys_role_boundaries`:
  **2 passed, 44 assertions**, 12.09 seconds. Application code was unchanged.
- `docker compose exec -T php ./vendor/bin/pint --test`: **PASS, 64 files**.
  Final modified tests additionally passed targeted Pint (2 files).
- `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`:
  **OK, no errors**.
- `docker compose exec -T php composer check-platform-reqs`: **all passed**,
  PHP 8.2.32.
- `docker compose exec -T php php artisan view:cache`: **passed**.
- `docker compose exec -T php php artisan route:list -vv --except-vendor`:
  real routes and intended account/role middleware verified; POST-only logout.
- `docker compose exec -T -e APP_ENV=production php php artisan route:list -vv --except-vendor`:
  **5 application routes**, no prototype, foundation diagnostic or redirect-check.
- Real Docker HTTP verification: temporary Python standard-library client with
  cookie jars parsed and submitted the actual rendered login/logout forms at
  `http://localhost:8080/cabinet`. Both seed accounts logged in, reached their own
  homes (200), received 403 at the other role's home, received 405 for GET logout,
  logged out and then redirected to login as guests. Missing CSRF tokens produced
  419 on both login and logout. Real home HTML contained no prototype URLs.
  Both flows were repeated successfully after switching the stale local .env to
  database sessions; a bootstrapped config check confirmed `database`.
- Full diff and new files inspected; `git diff --check` passed. Task/spec/governance
  files unchanged. No secrets, user data, .env, caches or temporary HTTP artifacts
  are included in the task files.

Initial verification failures were resolved: test request base URLs, Compose's
APP_ENV=local overriding PHPUnit (now forced to testing), explicit session-cookie
replay in database session tests, the production seed test's console confirmation,
and a SessionGuard type annotation for Larastan. Pint's `--dirty` cannot operate
inside the container without .git; explicit task paths were formatted instead.

## Facts

- Started from planner `95fe4df`; its parent is exactly
  `04395636b1eb34d9754b5b3eae2198f77120f913`. Initial working tree was clean.
- `status`, not compatibility `accept`, determines access.
- No real group queries, profile flow, CRUD, mail, API or WEBPAY behavior added.
- Existing fixture views and CSS remain the approved visual basis.
- Development credentials: `admin@gruppa.test` and `psychologist@gruppa.test`,
  password `password`, only seeded in local/testing.
- The local runtime initially had file sessions despite the task's database-session
  assumption. It now uses the existing MySQL session table.

## Assumptions

- Unimplemented management and group workflows are represented by explicit
  availability data, as authorized in the task; no additional product decisions.

## Unknowns

- Production hosting and deployment remain unverified and outside this task.
- Runtime verification used equivalent HTTP flows, not a new screenshot/responsive
  audit. The shared CSS and approved layout structure were preserved.

## Risks / Next Step

Stage 5 can reuse SessionInvalidator for administrative access revocation.
Stage 6 will connect profile/documents; Stage 7 will connect real groups and
creation. SMTP, onboarding, public API, WEBPAY and production credentials remain
outside this milestone.

Manual verification: open `http://localhost:8080/cabinet/login`, sign in with
either development account, check the corresponding home and opposite-role 403,
then click «Выход». The full prototype catalog remains at `/cabinet/_prototype/`.
