# Task: TASK-2026-09-21-13

Status: planned
Created from: 094e94e1734707cdf9c607c989eaa03461da2893 (main)

## Title

Stage 13 local WEBPAY correction — complete real payment-era admin/group integration and staging prerequisites

## Goal

Correct the remaining integration gaps found during acceptance review of TASK-2026-09-21-12.

The core local WEBPAY implementation is structurally sound and must be preserved:

- protocol-v2 form/signature;
- signed notify as the trusted merchant-order binding;
- standalone get_transaction cannot bind a local payment;
- lost-notify/no-binding stays pending/manual review;
- central idempotent confirmation;
- paid placement and paid extension;
- bounded trusted-bound recovery;
- manual refund accounting;
- current UI design baseline.

This correction remains narrow in business behavior. In addition, prepare the application runtime/deployment baseline for ordinary shared PHP hosting without rewriting the payment architecture.

## Base

Use exactly:

`094e94e1734707cdf9c607c989eaa03461da2893`

Do not revert the accepted Stage 1–12 behavior or the local WEBPAY implementation.

## Acceptance Review Findings

### 1. Real abandoned-group workflow does not include awaiting_payment

Paid placement now creates real `awaiting_payment` groups.

However current admin behavior still reflects the pre-payment era:

- quick filter `abandoned` selects only `draft`;
- admin delete policy allows only old `draft`;
- therefore an abandoned unpaid `awaiting_payment` group cannot be found through the intended quick filter and cannot be manually soft-deleted by admin.

SPEC requires abandoned records to include both:

- `awaiting_payment`;
- `draft`.

Correction:

- admin quick filter `abandoned` must include both statuses, using the existing abandoned age threshold;
- admin delete eligibility must include old `awaiting_payment` and old `draft`;
- existing payment safety remains authoritative:
  - any succeeded unrefunded payment blocks deletion;
  - refund accounting does not otherwise bypass status/age rules;
- deletion remains soft delete;
- historical payments/notifications remain intact.

Do not add automatic cleanup.

### 2. Successful-payment filter is still prototype-only

Now that `gp_payments` is real, the real admin Groups list must expose the SPEC filter for presence of a successful unrefunded payment.

Current view hides `successful_payment` in real mode and GroupIndexRequest/controller do not implement it.

Correction:

Add real filter:

- `successful_payment=yes`
- `successful_payment=no`

Definition for this list:

- “yes” = group has at least one payment with status `succeeded` and `refunded_at IS NULL`;
- “no” = no such payment.

Use `whereHas` / `whereDoesntHave` or equivalent SQL.

Requirements:

- no per-row payment queries;
- normal group list without this filter should preserve existing constant-query behavior;
- filter composes correctly with status/free/search/quick/sort/pagination;
- query string persists through pagination;
- do not treat `refunded` as successful-unrefunded.

### 3. Real awaiting_payment copy is stale and contradictory

Current real group summary renders:

`Историческая запись. Действия пока недоступны.`

for `awaiting_payment`, while the same screen exposes real payment actions.

That text belonged to the pre-WEBPAY state and is now false.

Correction:

Use truthful current-state wording, for example:

`Ожидается оплата размещения. Заполнение анкеты группы станет доступно после доверенного подтверждения WEBPAY.`

Requirements:

- owner real group show/list must not call awaiting_payment “historical”;
- payment CTA remains available;
- do not redesign the page;
- prototype variants may retain their intended demonstration semantics only if still accurate.

Where practical, from the real group detail link directly to the current/latest placement payment.

Do not introduce a psychologist payment-history section.

### 4. Staging prerequisites omit unsuccessful WEBPAY notifications

Official WEBPAY documentation currently states:

- standard notify is sent after the provider has a result;
- by default notifications are sent only for successful operations;
- receiving notifications for unsuccessful payments requires contacting WEBPAY technical support.

The local code safely supports trusted signed provider types:

- 2 -> failed;
- 8 -> failed;
- 7 -> cancelled/voided while still pending.

But because browser cancel is untrusted and standalone get_transaction cannot establish merchant-order binding, a merchant configured for success-only notify cannot safely transition a failed/cancelled unbound attempt to a terminal state. Such an attempt intentionally remains pending/manual review and retry stays blocked.

This is not a reason to weaken the trust boundary.

Correction to docs/staging checklist:

- explicitly state that if the product is expected to automatically obtain trusted `failed/cancelled` states and enable normal retry after unsuccessful card attempts, WEBPAY Sandbox/production account must be configured to deliver signed notifications for unsuccessful operations;
- instruct operator to request this from WEBPAY support and verify it during Sandbox acceptance;
- if unsuccessful notifications are not enabled/delivered, document the safe fallback:
  - browser cancel/failed page is not trusted;
  - standalone get_transaction cannot bind the attempt;
  - payment remains pending/manual review;
  - no new retry is allowed merely from browser outcome;
- do not invent a manual “mark failed/cancelled/succeeded” financial action;
- do not adopt a different provider API in this correction.

Official source to re-check before implementation:

`https://docs.webpay.by/paymentIntegration/cardIntegration/paymentNotification/`

At review time the documentation says unsuccessful payment notifications can be enabled by contacting `support@webpay.by`.

## Scope

### Admin group index

Update:

- request validation;
- controller query;
- real Blade filter;
- pagination/query persistence.

Implement successful-payment yes/no filter.

Update abandoned quick filter to include both awaiting_payment and draft older than the existing configured threshold.

### Admin group deletion

Update GroupPolicy/admin behavior so old abandoned:

- awaiting_payment;
- draft;

may be deleted by an authorized admin if no succeeded unrefunded payment blocks deletion.

Preserve psychologist deletion rules unchanged.

### Real owner group UI

Correct awaiting_payment wording.

Verify:

- real list;
- real detail;
- payment CTA/navigation;
- disabled state behavior.

No visual redesign.

### Documentation

Update at minimum:

- `docs/webpay.md`;
- `docs/deployment.md`;
- `docs/project-status.md` only if wording needs correction;
- `docs/development.md` where local/shared-hosting runtime commands differ;
- `.env.example` for database cache/shared-hosting placeholders;
- `.ai/report.md`.

Explicitly add unsuccessful-notify provider prerequisite/fallback.

## HostER.by / Shared-Hosting Readiness

The target test/production hosting class is ordinary shared PHP hosting (HostER.by / ispmanager-style environment), not a VPS with Docker/systemd/Supervisor.

Do not hardcode HostER account paths or assume optional panel rights before the real account exists.

At planning time current ispmanager documentation confirms the platform can support:

- Cron jobs that execute shell/PHP CLI commands;
- selectable installed PHP versions/handlers;
- optional Shell/SSH access granted per user;
- optional PHP Composer access granted per user/site.

Exact HostER account capabilities, installed PHP binaries, DB engine/version, symlink permission and cron limits remain deployment-time facts.

The application must therefore be portable to shared hosting with the following architecture.

### A. Database cache and distributed locks

Production/shared-hosting mode must not depend on the local file cache for cross-process locks.

The project currently has Laravel database-cache configuration but no cache tables.

Add the standard Laravel-compatible database cache tables:

- `cache`;
- `cache_locks`.

Requirements:

- additive migration only;
- MySQL-compatible;
- no Redis dependency;
- preserve local/testing ability to select another cache store when a test explicitly needs it;
- update `.env.example` so the documented staging/shared-hosting choice is `CACHE_STORE=database`;
- shared web PHP, scheduler and cron queue workers must see the same cache/lock rows;
- Stage 12 unique mail/warning locks and Stage 13 WEBPAY recovery locks must remain correct under database cache.

Add tests proving at minimum:

- database cache read/write works;
- a cache lock acquired in one application/process context prevents a second acquisition;
- warning/payment unique-lock behavior remains green with database cache.

Do not make Redis a production prerequisite for this project.

### B. Cron-driven queue mode

Do not require a permanently running `queue:work` daemon for shared-hosting deployment.

Keep the database queue.

Document and verify a finite queue-drain mode suitable for cron, for example:

`php artisan queue:work database --stop-when-empty --tries=3 --timeout=45 --max-time=50`

Exact PHP binary/path is deployment-specific.

Requirements:

- jobs remain the same jobs; no synchronous-mail/payment fallback;
- queue failures still use `failed_jobs`;
- no business behavior depends on Supervisor/systemd;
- the existing Docker persistent worker remains valid for local development;
- add a local verification using the finite `--stop-when-empty` worker and database cache;
- document that if the host cannot schedule every minute, the actual supported cron frequency must be measured and accepted before production because it affects queue latency and scheduler precision.

It is acceptable for multiple database workers to be briefly concurrent; existing idempotency/unique locks must protect duplicate effects.

Do not introduce an HTTP/web-triggered queue runner.

### C. Laravel scheduler on shared hosting

Production/shared hosting should use one Cron entry invoking:

`php /absolute/path/to/application/artisan schedule:run`

once per minute when the hosting plan allows it.

The Laravel scheduler remains authoritative for:

- group expiry;
- participant cleanup;
- expiry-warning queueing;
- WEBPAY recovery queueing.

Requirements:

- no duplicate host-level cron entries for each business command;
- `schedule:list` remains the source of the internal cadence;
- docs must include the fallback decision gate if HostER's minimum cron interval is greater than one minute: do not silently weaken timing requirements; record the actual limitation during staging acceptance.

### D. Shared-hosting /cabinet deployment layout

The application must remain deployable under:

`https://gruppa.info/cabinet/`

while the main public site occupies the same host.

Never require the full Laravel source, `.env`, `vendor`, private documents or `storage` to be web-accessible.

Document two supported layouts:

#### Preferred: symlink/alias allowed

- full Laravel application stored outside public web root;
- public web path `.../public_html/cabinet` points to `application/public` through an allowed symlink/hosting alias;
- only Laravel public files are reachable.

#### Fallback: symlink unavailable

- application remains outside web root;
- copy only Laravel public assets/front controller into `public_html/cabinet`;
- deployment-specific front controller paths reference the private application `vendor/autoload.php` and `bootstrap/app.php`;
- do not duplicate application source into `public_html`;
- document exactly which generated/deployment file needs path substitution.

Do not hardcode `/var/www/...`, account names or HostER-specific home paths in committed application code.

Existing URL generation/base-path behavior must remain green with `APP_URL=https://gruppa.info/cabinet`.

Add/retain automated checks for:

- asset URLs under `/cabinet`;
- routes/redirects under `/cabinet`;
- WEBPAY return/cancel/notify URLs under `/cabinet`;
- password setup URLs under `/cabinet`;
- no prototype routes in production.

### E. Production artifact without server-side Composer dependency

Composer may be available in ispmanager, but the application must not require Composer access on the hosting account.

Document a supported release preparation flow performed locally/CI:

`composer install --no-dev --prefer-dist --optimize-autoloader`

using the committed `composer.lock`.

Deployment artifact must include `vendor/`.

Requirements:

- no dev dependencies in production artifact;
- no Node/npm/Vite;
- `composer check-platform-reqs` must be run against the target hosting PHP before activation when CLI access exists;
- if Composer is available on HostER it is optional convenience, not an architecture dependency.

### F. Shared-hosting preflight command

Add a read-only Artisan command, recommended:

`php artisan deployment:preflight`

It must print only non-sensitive technical status and exit non-zero on hard blockers.

Check at minimum:

- PHP version satisfies project requirement;
- required PHP extensions from composer requirements are loaded;
- DB connection works;
- DB driver/server identity and version are displayed without credentials;
- actual DB engine is MySQL-compatible;
- writable `storage` and `bootstrap/cache`;
- session driver is database for staging/production;
- queue connection is database;
- cache store is database for shared-hosting staging/production;
- database cache/lock acquisition succeeds;
- `APP_URL` is HTTPS and contains the expected `/cabinet` base path in staging/production;
- `APP_DEBUG=false` in staging/production;
- mail configuration is non-log/non-array for real staging delivery;
- WEBPAY environment/config presence is reported without printing values/secrets;
- integration-secret presence is reported as configured/missing only;
- scheduler/queue tables required by the app exist.

Important:

- never print DB password, APP_KEY, integration secret, WEBPAY keys/passwords or SMTP password;
- do not make outbound WEBPAY payment/API requests;
- do not send email;
- do not mutate business data;
- DB vendor/version mismatch should be a clear blocker/warning according to current project MySQL contract, not silently treated as equivalent.

Tests must cover redaction and representative pass/fail states.

### G. Target hosting unknowns to record, not guess

Update deployment docs with a HostER/shared-hosting staging discovery checklist.

At first account access determine and record:

- web PHP version;
- CLI PHP executable/version;
- enabled required extensions;
- exact DB engine and version via SQL;
- SSH/Shell availability;
- Composer availability;
- minimum Cron interval;
- cron maximum runtime;
- symlink/alias permission;
- filesystem paths and writable permissions;
- outbound HTTPS to WEBPAY;
- outbound SMTP;
- incoming WEBPAY POST reachability/WAF behavior.

These are external staging facts.

Do not block this local task merely because they are currently unknown.

### H. Documentation

Extend `docs/deployment.md` (or a dedicated shared-hosting section) with:

- HostER.by / ispmanager shared-hosting target;
- database cache/locks;
- finite cron-driven database queue;
- scheduler Cron;
- preferred/fallback `/cabinet` layouts;
- production artifact preparation;
- preflight command;
- first-login hosting discovery checklist;
- exact placeholders showing where real absolute PHP/application paths are substituted;
- rollback/backup steps.

Keep the existing VPS/supervisor-style option documented as an alternative where available; shared hosting becomes the required portable baseline.

### I. Additional shared-hosting verification

Before completing this task also run:

1. migrations including cache tables;
2. tests with `CACHE_STORE=database`;
3. finite database worker `--stop-when-empty` processing:
   - password setup mail job with fake/local SMTP;
   - expiry warning job;
   - WEBPAY recovery job fixture where applicable;
4. `deployment:preflight` in local synthetic shared-hosting configuration;
5. verify no secret appears in preflight output;
6. verify `APP_URL=https://gruppa.info/cabinet` URL generation;
7. existing full MySQL regression.

### J. Additional acceptance criteria

The correction is not complete until all of the following also hold:

27. Standard database cache/cache_locks tables exist through additive migration.
28. Shared-hosting configuration can use `CACHE_STORE=database`.
29. Cross-process/database cache locking behavior is tested.
30. No Redis/Supervisor/systemd dependency is required for production correctness.
31. Database queue can be drained safely with a finite cron worker.
32. Local Docker persistent worker remains supported for development.
33. One host-level scheduler cron is sufficient for Laravel scheduled business commands.
34. Deployment docs contain preferred and no-symlink `/cabinet` layouts with private application files outside web root.
35. Production artifact can be built with vendor locally; server-side Composer is optional.
36. `deployment:preflight` exists and reveals no secrets.
37. Preflight checks PHP/extensions/DB/writable dirs/session/queue/cache locks/APP_URL/debug/mail/config presence.
38. Deployment docs explicitly list HostER account facts that must be discovered rather than assumed.
39. Existing Stage 11/12/WEBPAY idempotency behavior remains green using database cache.
40. No HostER-specific absolute filesystem path is hardcoded in application code.

## Out Of Scope

Do NOT change:

- trusted-binding architecture;
- standalone get_transaction restriction;
- notify signature algorithm;
- payment form signing;
- payment state machine except where needed for the described admin filters/deletion;
- recovery schedule;
- extension semantics;
- refund API behavior;
- WEBPAY credentials;
- real Sandbox network calls;
- actual deployment/DNS/TLS/account provisioning (deployment readiness changes above are in scope);
- UI design;
- Stage 11/12 flows.

Do NOT implement:

- manual “mark paid”;
- manual “mark failed”;
- manual “mark cancelled”;
- automatic refund;
- alternative WEBPAY APIs;
- payment-history page for psychologists.

## Tests

Use MySQL.

Add/adjust focused coverage:

### Abandoned workflow

- admin quick abandoned includes old awaiting_payment;
- admin quick abandoned includes old draft;
- recent awaiting_payment excluded;
- recent draft excluded;
- unrelated statuses excluded;
- pagination works;
- admin can soft-delete eligible old awaiting_payment;
- admin can soft-delete eligible old draft;
- succeeded unrefunded payment blocks deletion;
- refunded payment does not by itself block deletion;
- psychologist deletion rules unchanged.

### Successful-payment filter

- yes includes group with succeeded + refunded_at null;
- yes excludes refunded;
- yes excludes pending/failed/cancelled;
- no is inverse for the relevant groups;
- composes with status/free/search;
- pagination keeps filter query;
- no N+1;
- normal list query count remains constant and does not add per-row payment queries.

### awaiting_payment real UI

- real owner list/show does not contain `Историческая запись`;
- truthful awaiting-payment text appears;
- payment CTA exists;
- group detail leads to the current placement payment;
- cross-owner access unchanged.

### WEBPAY staging docs

Automated textual/document check if project conventions support it, otherwise inspect manually:

- default success-only notify behavior documented;
- support request for unsuccessful notifications documented;
- safe pending/manual-review fallback documented;
- no weakening of signed binding/get_transaction rules.

### Regression

Run:

- WebpayTest;
- WebpayConcurrencyTest;
- GroupWorkflowTest;
- group admin/index/policy tests;
- full MySQL suite;
- Stage 11/12 focused regression;
- prototype 31/249;
- production isolation.

## Required Checks

Report exact results:

1. `docker compose ps`
2. non-destructive migrate/seed if needed (no new migration expected)
3. focused correction tests
4. existing WEBPAY focused/concurrency tests
5. full MySQL test suite
6. Pint
7. Larastan
8. composer check-platform-reqs
9. view:cache
10. route inspection
11. `git diff --check`
12. final staged/secrets/artifact review
13. database-cache/lock focused tests
14. finite cron-worker smoke using `--stop-when-empty`
15. `deployment:preflight` pass/fail/redaction checks
16. `/cabinet` production URL-generation check

## Acceptance Criteria

1. Abandoned quick filter includes both old awaiting_payment and old draft.
2. Recent awaiting_payment/draft are not classified abandoned.
3. Authorized admin can soft-delete eligible abandoned awaiting_payment.
4. Existing succeeded-unrefunded payment protection remains.
5. Psychologist deletion rules are unchanged.
6. Real admin group list exposes successful-payment yes/no filter.
7. Successful-payment filter treats only succeeded + unrefunded as yes.
8. Refunded/failed/cancelled/pending are not counted as successful-unrefunded.
9. Filters compose and paginate correctly.
10. No N+1/per-row payment lookup is introduced.
11. Real awaiting_payment UI no longer calls the record historical.
12. Real owner can navigate from awaiting_payment group to its current placement payment.
13. Current design baseline is preserved.
14. docs/webpay.md explicitly states WEBPAY default success-only notify behavior.
15. Staging checklist explicitly says to request/verify unsuccessful signed notifications when automatic failed/cancelled retry behavior is required.
16. Docs preserve fail-closed behavior when those notifications are unavailable.
17. Browser cancel remains untrusted.
18. Standalone get_transaction remains unable to bind local payment.
19. No manual financial-success/failure override is added.
20. Existing local WEBPAY focused/concurrency tests remain green.
21. Stage 11 API remains green.
22. Stage 12 mail/queue remains green.
23. Full MySQL suite passes.
24. Pint/Larastan/composer/view checks pass.
25. 31/249 prototypes and production isolation remain green.
26. No credentials/secrets/real financial data/unrelated artifacts are committed.

## Hard Workflow Gate

Before changing files:

- read WORKFLOW.md;
- read AGENTS.md;
- read this task;
- inspect GroupPolicy, admin GroupController/GroupIndexRequest, real group list/show/_actions, current WEBPAY docs;
- inspect cache/queue/session config, migrations, compose worker, deployment docs and current `/cabinet` URL handling;
- re-check official WEBPAY notification documentation;
- run git log/status;
- confirm base `094e94e1734707cdf9c607c989eaa03461da2893`;
- do not overwrite unknown changes.

During implementation:

- keep payment/business correction narrow; shared-hosting changes must be infrastructure/deployment-only;
- preserve payment trust architecture;
- no external provider calls required;
- no UI redesign;
- do not edit `.ai/task.md`, SPEC, WORKFLOW or AGENTS.

Before commit:

- run required checks;
- update `.ai/report.md`;
- inspect full diff/staged files;
- remove temporary artifacts.

If complete, commit with:

`codex: TASK-2026-09-21-13 complete webpay admin integration`

Do not create an accept commit.
