# Report: TASK-2026-09-21-07

Status: done

## Summary

Implemented Stage 9 placement expiration, remaining/warning presentation, admin
expired filter and confirmed owner-only free extension using the current owner
tariff. Existing Blade pages and all prototype branches are preserved. All required checks, concurrent MySQL verification and real browser scenarios
have passed.

## Changed Files

- `application/app/Services/GroupLifecycleService.php`: chunked due selection,
  transactional expiration re-check, current-owner locking, extension and
  presentation calculations.
- `application/app/Console/Commands/ExpireGroups.php`, `application/routes/console.php`:
  aggregate-only `groups:expire` command and every-minute overlap-protected schedule.
- Owner/admin GroupControllers, GroupActionRequest, GroupIndexRequest, GroupPolicy,
  GroupPages and `application/routes/web.php`: owner-scoped extension endpoints,
  confirmation/authorization, expired filtering, loaded list data and history-based
  re-publication presentation.
- Group/GroupStatusHistory model annotations describe their existing casts for
  static analysis; schema and cast behavior are unchanged.
- Existing psychologist extension/actions, shared group-summary and admin group
  index/show Blade files: real extension/warning/expired states and reminders.
- GroupLifecycleTest and GroupLifecycleConcurrencyTest: MySQL lifecycle/access/
  tariff/window/query/side-effect coverage and independent-process row-lock tests.
  GroupWorkflowTest adds lifecycle settings fixtures and expects the new action.
- `docs/architecture.md`, `docs/development.md`, `docs/project-status.md`,
  `docs/ui-pages.md`: implemented Stage 9 behavior and verification instructions.
- `.ai/report.md`: this report. Task/spec/governance files are unchanged.

## Checks

- Initial `git status --short`: clean. Planner HEAD `7dbacb8`; its parent matches
  `28aabaab3bcc84622959f9e81088d0844c90fc5f` exactly.
- `docker compose ps`: php/mysql healthy, web running.
- `docker compose exec -T php php artisan migrate --seed`: nothing to migrate;
  idempotent seed completed, no destructive reset of the development database.
- `docker compose exec -T php php artisan schedule:list`: `groups:expire` has
  `* * * * *` cadence. The schedule test verifies `withoutOverlapping` as well.
- `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`:
  no errors.
- `docker compose exec -T php ./vendor/bin/pint --test`: final run passed,
  108 files.
- `docker compose exec -T php composer check-platform-reqs`: all requirements
  pass on PHP 8.2.32.
- `docker compose exec -T php php artisan view:cache`: success.
- `docker compose exec -T php php artisan route:list --json`: 94 routes,
  including the owner extension GET/POST with web/account/psychologist middleware.
- `docker compose exec -T -e APP_ENV=production php php artisan route:list --json`:
  60 routes; no prototype/foundation routes. Extension routes remain protected.
- `docker compose exec -T php php artisan test --filter=GroupLifecycle`: 21 passed
  (895 assertions), 35.29 seconds, including both independent-process races.
- Full `docker compose exec -T php php artisan test`: **289 passed, 3695 assertions,
  249.41 seconds**. This includes Stage 4–8 regression, all 31 page groups / 249
  prototype variants and production route isolation.
  The previous run had 287 passed / 2 failed due to a synchronization bug in the
  new test harness (a ready signal already in the output buffer was missed). That
  harness is corrected and both concurrency cases now pass. Initial focused test
  issues in stale Stage 7 expectations/model comparison were also corrected.
- `git diff --check`: passed. Full implementation/test/doc diff reviewed; only
  Stage 9 files and this report are selected for staging. No secrets, local data,
  browser artifacts or unrelated changes are included.

### Real Docker HTTP/browser verification

External Chromium/Playwright scripts and screenshots are only in `/tmp`, not
repository files or dependencies. The installed browser was used directly because
both browser MCP configurations point to missing Chromium builds.

- Created a separate synthetic approved psychologist and two groups using domain
  creation/moderation; activated the first through real admin HTTP confirmation.
- Checked active dates/remaining display and a near-expiry warning.
- Ran real `groups:expire`: before expiry `Expired groups: 0`; after preparing a
  due record `Expired groups: 1`; repeated run `Expired groups: 0`.
- Checked real admin expired filter and manual-unpublish reminder.
- Free active HTTP extension added exactly 17 stored days, preserved published_at,
  and reset expiry_warning_sent_at.
- Free expired HTTP extension changed expired → approved with system history;
  old dates/duration remained. The approved admin filter included the group and
  detail identified re-publication. Real activation then used the current 30 days
  instead of the previous 17, preserving public_uuid and historical free=false.
- A current-free owner extended a historically paid group. After switching the
  current tariff to paid, a historically free group showed unavailable paid
  extension; a direct confirmed POST returned HTTP 422 with no changes.
- Final payment and database-job counts remained 0 → 0. Mail/Queue fakes in the
  focused MySQL tests also assert no sent/queued work.
- Verified active, warning, expired, free-active/free-expired extension,
  re-publication admin detail, expired admin list, paid and outside-window states
  at 1440/1024/390, with no horizontal overflow. Owner list checked at all three
  sizes; confirmation modal checked inside the 390px viewport.
- Real POST without CSRF returned 419; with CSRF but without confirmation returned
  422. Browser scripts completed successfully with no page errors.

## Facts

- Expiration uses bounded chunks and locks/re-reads each candidate before a system
  domain transition; disabled active rows expire. No date/marker is changed.
- Extension locks the current owner then the owned group and repeats policy
  checks. Historical group.free never selects eligibility or changes.
- Active extension uses stored placement_days, not the current global duration.
  Independent confirmed requests are separate actions; one service call updates once.
- Chosen overdue-active behavior: reject with a clear wait/refresh validation
  message until the scheduler marks expired; never extend an elapsed active period.
- Expired extension allows the exact current-window deadline; later attempts fail.
  It creates only expired → approved system history, retaining old period dates.
- Re-publication uses the existing admin activation and current duration setting.
- Remaining days use non-negative ceiling; warning/window settings are read once
  per list calculation. Queries do not grow per row or touch payments/applications.
- No email/job classes, payment integration, migrations, new flags/deadline columns,
  dependencies, CSS redesign, or Stage 10+ functionality were added.

## Assumptions

- Normal deployment will run the Laravel scheduler every minute; cron setup is
  documented and intentionally outside this task.
- Public-site unpublication/re-publication remains a manual administrator action.

## Unknowns

- No unresolved Stage 9 implementation or verification blockers. Production
  scheduler operation and actual public-site actions are outside the local scope.

## Risks / Next Step

Ready for review in the required `codex:` commit. Synthetic development records
remain local only; no screenshots, scripts, credentials or database records are
included. Normal production scheduler cron must be configured at deployment;
email warning jobs and paid extension remain later-stage work.
