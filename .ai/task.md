# Task: TASK-2026-09-21-07

Status: planned
Created from: 28aabaab3bcc84622959f9e81088d0844c90fc5f (main)

## Title

Stage 9 — Implement placement lifecycle expiration, remaining-time UI, expired admin filter, and free extensions without email or WEBPAY

## Goal

Implement Stage 9 from SPEC.md on top of the accepted Stage 7 group workflow and Stage 8 typed settings.

This milestone completes the time-based lifecycle of an already activated group without email and without WEBPAY:

- active groups automatically become expired when expires_at is reached;
- psychologist/admin pages show truthful remaining/expiry state;
- administrator has a real expired quick filter for manual unpublication on gruppa.info;
- a psychologist whose **current** gp_users.free=true can extend active or expired owned groups without payment;
- active free extension shifts expires_at by the group’s snapshotted placement_days and resets the warning marker;
- expired free extension transitions expired -> approved without moderation and waits for manual re-publication;
- manual activation after expired extension recalculates a fresh placement period using the **current** placement_duration_days setting;
- extension-window rules use the typed expired_extension_window_days setting;
- paid owners (current gp_users.free=false) receive no payment flow yet and cannot bypass it with direct POST.

Do not implement email warning jobs, SMTP, WEBPAY, payment creation, public API, or participant applications.

## Facts

- Stages 1–8 are accepted through commit 28aabaab3bcc84622959f9e81088d0844c90fc5f.
- Stage 7 already implements:
  - draft/moderation/revision/rejected/approved/active workflow;
  - manual approved -> active activation;
  - published_at set at activation;
  - placement_days snapshotted from SettingService::placementDurationDays();
  - expires_at = published_at + placement_days;
  - expiry_warning_sent_at reset to null on activation;
  - real owner/admin group list/detail views and history.
- GroupStatus enum already permits:
  - active -> expired;
  - expired -> approved.
- GroupStatusTransitionService already performs lockForUpdate, transition validation and status-history persistence.
- SettingService already exposes:
  - placementDurationDays();
  - expiryWarningDays();
  - expiredExtensionWindowDays().
- Stage 8 lets admin change these settings through typed/audited UI.
- gp_groups has indexed status/expires_at and composite (status, expires_at) for scheduler work.
- placement_days is the historical duration snapshot of the current placement period.
- gp_groups.free is only the creation-time tariff snapshot and MUST NOT decide extension tariff.
- Extension tariff source of truth is current gp_users.free at the moment the extension attempt is processed.
- No Stage 1–8 real flow creates payment records.
- Existing Stage 3 views already include active/warning/expired/outside-window and free/paid extension prototype variants.

## Product / Architecture Decisions

### Active free extension duration

For an active group, free extension uses the group’s existing placement_days snapshot:

expires_at = expires_at + placement_days

It does NOT re-read the current placement_duration_days setting for that active period.

Changing placement_duration_days therefore:
- does not alter an already active period;
- does not alter the duration added by extending that active period;
- DOES apply when an approved group is manually activated/re-published into a new placement period.

### Expired free extension

For an expired group inside the extension window:

- transition expired -> approved;
- do not run moderation;
- do not create payment;
- do not create new published_at/expires_at at extension time;
- retain the old dates as the previous placement period until admin re-activates;
- admin manually republishes on gruppa.info and uses the existing approved -> active action;
- that activation overwrites published_at/expires_at and snapshots the then-current placement_duration_days setting.

### Current tariff

Every extension attempt must read current owner gp_users.free from the database.

- current free=true -> free Stage 9 extension logic;
- current free=false -> no extension is applied in Stage 9;
- historical gp_groups.free is ignored for this decision.

### Extension window boundary

Expired extension is allowed while:

now <= expires_at + expired_extension_window_days

It is rejected after that exact boundary.

### Warning state

Stage 9 warning is presentation/lifecycle state only.

- For active group with a future expires_at, use current SettingService::expiryWarningDays().
- warning=true when remaining time is within the configured threshold.
- Do NOT queue email.
- Do NOT set expiry_warning_sent_at merely because the warning is displayed.
- Stage 12 owns warning email jobs and successful-send marker behavior.

### Scheduler frequency

Add an explicit Artisan command for expiration and schedule it every minute through Laravel Scheduler.

The command must be safe to run repeatedly and concurrently:
- indexed candidate query;
- bounded/chunked processing;
- row lock + re-check before each transition;
- active -> expired only once;
- no duplicate history;
- command-level withoutOverlapping is useful but row-level correctness must not depend on it.

Production cron configuration itself remains deployment work; document the standard schedule:run requirement.

## Scope

### 1. Lifecycle service

Add a small explicit service, for example GroupLifecycleService, responsible for:

- expireDueGroups();
- expireOne()/equivalent locked expiration;
- extension availability calculations;
- free extension application;
- remaining/warning presentation data where appropriate.

Do not create a generic workflow engine.

Reuse GroupStatusTransitionService for every status transition:
- active -> expired with actor_type=system, actor=null;
- expired -> approved with actor_type=system, actor=null.

Do not assign status directly.

### 2. Expiration command

Add an Artisan command such as:

php artisan groups:expire

Behavior:

- select non-deleted groups with status=active and expires_at <= now UTC;
- ignore active groups whose expires_at is null;
- process in bounded chunks (e.g. chunkById);
- for each candidate, transactionally lock and re-read the row;
- re-check status=active and expires_at <= now after lock;
- transition active -> expired through GroupStatusTransitionService;
- history actor_id=null, actor_type=system, comment=null;
- do not alter published_at, expires_at or placement_days;
- do not touch payments/applications;
- do not queue email/jobs.

Command must emit/log only a small aggregate result such as processed/expired count, no personal/group content.

A repeated run must produce zero additional transitions/history for already expired groups.

### 3. Scheduler registration

Register groups:expire in Laravel Scheduler every minute.

Use withoutOverlapping or equivalent command-level guard.

Do not add warning-email schedule/job in Stage 9.

Do not add WEBPAY recovery schedule.

### 4. Scheduler / race safety

Test and implement correctness for:

- before expires_at: no transition;
- exactly at expires_at: transition permitted;
- after expires_at: transition;
- repeated command run: exactly one active -> expired history row;
- two lifecycle attempts observing the same candidate do not create duplicate transitions;
- soft-deleted groups ignored;
- non-active groups ignored;
- disabled active groups still expire based on time because disabled is independent of lifecycle status.

Do not rely only on candidate query state; re-check under lock.

### 5. Remaining time / warning presentation

Extend real GroupPages/presenter data without changing prototype fixtures.

For active groups with expires_at:

- expose the exact expiry date already shown;
- expose a computed remaining duration/day count suitable for UI;
- compute warning using current expiry_warning_days setting;
- show a visible warning when within threshold.

Use a deterministic rule:
- remaining_days = max(0, ceil((expires_at - now) / 86400 seconds));
- when remaining_days > 0, real UI may show “До окончания размещения: N дн.”;
- when expiry is due/past but scheduler has not yet transitioned the record, show a truthful due/expired-soon state rather than a negative value.

Do not hardcode “3 дня” in real mode.
Prototype warning variant may retain its synthetic wording/state.

Stage 9 must not set expiry_warning_sent_at from page rendering.

### 6. Expired presentation

For real expired group owner detail/list:

- status “Закончена”;
- show historical expires_at;
- show whether extension window is still open;
- if outside window, show approved existing warning “Срок продления закончился. Создайте новую группу.”;
- no edit action.

Admin real group detail/list for expired rows should clearly state that public unpublication is manual.

Do not automatically call/update gruppa.info.

### 7. Admin expired quick filter

Extend real /admin/groups quick filters with:

quick=expired

It must select non-deleted status=expired groups requiring manual removal/unpublication from the public site.

UI label should remain consistent with approved prototype wording, e.g. “Снять с публикации”.

Requirements:
- no WEBPAY/payment query;
- query preserved through pagination/sort where applicable;
- no N+1;
- prototype filter behavior unchanged.

Do not add a fake “unpublished” state or database flag; manual public-site work remains outside the cabinet.

### 8. Real extension page

Connect the existing approved psychologist.groups.extension Blade view to real Stage 9 data rather than creating a new page.

Add owner-only GET route, recommended:

GET /groups/{group}/extension

Only owned, non-deleted group IDs are resolved.

The page must support truthful real states:

#### Current owner free=true, group active
- show free extension;
- show current expires_at;
- show duration that will be added = group.placement_days;
- real confirmed action available.

#### Current owner free=true, group expired and inside window
- show free extension;
- explain it returns group to “Одобрена, ожидает публикации”;
- explain admin must manually republish and activate;
- real confirmed action available.

#### Expired outside window
- no extension POST action;
- tell psychologist to create a new group.

#### Current owner free=false
- no payment form;
- no payment record;
- no WEBPAY link;
- explain paid extension will become available after payment integration is connected;
- no effective POST action.

Use the CURRENT owner tariff in all real extension display logic.
Do not use group.free to choose free/paid extension presentation.

Prototype extension variants remain unchanged.

### 9. Free extension action

Add owner-only POST route, e.g.:

POST /groups/{group}/extension

Use a dedicated Form Request or explicit confirmed action request.

Requirements:
- CSRF;
- confirmation;
- owner-scoped group lookup;
- existing account + role:psychologist;
- reject group.disabled=true;
- only active/expired groups;
- current owner free must be true;
- current owner row should be re-read transactionally;
- no payment creation;
- no email/job creation.

### 10. Active free extension

Within one transaction/row lock:

- re-read current owner tariff;
- lock/re-read group;
- require status=active;
- require expires_at not null;
- require placement_days positive/non-null;
- require the current placement not already effectively expired.

Then:

- expires_at = existing expires_at + placement_days;
- status remains active;
- published_at remains unchanged;
- placement_days remains unchanged;
- expiry_warning_sent_at = null.

No status-history row is required because status does not change.

Repeated explicit POSTs are separate user actions and each valid request may add one duration; however a single request must not apply twice because of retries inside the same application action.

No payment row.

### 11. Overdue active race behavior

If an extension request reaches an active group whose expires_at <= now before the scheduler has marked it expired, it must NOT receive active-extension semantics.

Choose one safe deterministic implementation:

Preferred:
- under lock transition active -> expired as system first;
- then apply the expired extension rules in the same coordinated operation if still inside the extension window.

Acceptable alternative:
- reject the request with a clear validation result and require refresh/scheduler transition before retry.

Whichever approach is used must be explicitly tested and documented.
Do not extend from an already elapsed expires_at as though it were still active.

### 12. Expired free extension

Within a transaction:

- current owner free must be true;
- group status must be expired;
- expires_at must exist;
- compute deadline = expires_at + current expired_extension_window_days;
- reject when now > deadline;
- transition expired -> approved through GroupStatusTransitionService;
- actor_type=system, actor=null, because this lifecycle transition is defined as a system transition;
- no moderation;
- no payment;
- do not overwrite public_uuid/free/owner/content;
- do not create new published_at/expires_at values at this moment.

After transition:
- psychologist detail shows approved waiting for manual publication;
- admin approved quick filter includes it;
- admin detail should identify it as a re-publication/extension case using real history, without introducing a new DB flag unless absolutely necessary.

### 13. Re-publication activation

Reuse Stage 7 approved -> active activation.

When the approved group came from expired -> approved:

- admin still sees public_uuid/reminder;
- activation uses CURRENT SettingService::placementDurationDays();
- published_at becomes current UTC time;
- placement_days becomes current setting;
- expires_at = new published_at + current duration;
- expiry_warning_sent_at = null;
- status history gets approved -> active with admin actor.

Add explicit regression proving the old placement_days may differ from the new period.

Do not rerun moderation.

### 14. Extension window UI/data

Use current SettingService::expiredExtensionWindowDays() at read/action time.

Expose on real pages as needed:
- extension_deadline;
- outside_window boolean;
- can_extend boolean;
- current owner tariff mode.

Do not persist a duplicate deadline column.

A later settings change affects whether a currently expired group is still inside the extension window because the setting is evaluated at the extension attempt/page read.

### 15. Current tariff regression

Explicitly test both directions:

- group was created with group.free=false, current owner.free=true -> Stage 9 free extension works;
- group was created with group.free=true, current owner.free=false -> Stage 9 extension does NOT apply and no payment is created.

The historical gp_groups.free value must remain unchanged in both cases.

### 16. Paid-owner Stage 9 boundary

For current owner.free=false:

- active/expired extension page is informational only;
- direct POST cannot extend or change status/dates;
- no gp_payments row;
- no payment route/redirect/provider request;
- no extension_price usage is required to create an operation.

Stage 14 will implement paid extension.

### 17. No email/job boundary

Hard gate:

- do not create warning mail classes/jobs;
- do not queue jobs when a group enters warning window;
- do not set expiry_warning_sent_at due to warning UI/scheduler;
- do not add SMTP behavior.

Stage 12 owns warning email.

Tests should use Mail::fake()/Queue::fake() where useful to prove no warning dispatch.

### 18. Admin/list query behavior

Real owner/admin lists must remain paginated and no-N+1.

Adding lifecycle presentation must not introduce:
- one Setting query per group;
- one owner query per group;
- payment/application queries.

Read the relevant settings once per request/service calculation and map over loaded rows.

Admin expired quick filter and owner list query counts should remain constant as row count grows.

### 19. Policy / IDOR

Extend GroupPolicy or add a narrow extension ability.

Psychologist may access extension routes only for:
- own group;
- non-deleted group;
- group not disabled;
- status active or expired;
- normal active account through existing middleware.

Foreign group ID should return 404 through owner-scoped lookup.

Admin cannot use psychologist-only extension route because role middleware remains psychologist-only.

Paid/free eligibility may be enforced by service/action rather than static policy because it depends on current tariff/settings.

### 20. Blade integration

Reuse existing views/components:

- psychologist/groups/index;
- psychologist/groups/show;
- psychologist/groups/extension;
- psychologist/groups/_actions;
- shared/group-summary;
- admin/groups/index;
- admin/groups/show;
- shared/group-history where needed.

Real action behavior:

- active/expired owner action routes to real extension page when appropriate;
- expired outside window routes to/create-new behavior as approved;
- paid owner gets truthful unavailable extension UX, not prototype WEBPAY;
- real warning text uses configured threshold/remaining data;
- admin expired filter is real.

Prototype variants:
- active/warning/expired/outside-window;
- free-active/free-expired/paid-active/paid-expired/outside-window/pending
remain synthetic/no-op and unchanged.

Do not redesign Stage 3.

### 21. Tests

All tests use MySQL.

Add focused coverage for at least:

#### Scheduler/expiration
- command registered;
- schedule registered every minute;
- before expires_at unchanged;
- exactly at expires_at -> expired;
- after expires_at -> expired;
- history system actor;
- repeated command no duplicate history;
- non-active/null-expires/soft-deleted ignored;
- disabled active still expires;
- candidate re-check/locking prevents duplicate transition.

#### Remaining/warning
- active remaining_days computed deterministically;
- no negative display;
- warning starts at configured threshold;
- outside threshold no warning;
- changing expiry_warning_days affects presentation immediately;
- page render does not change expiry_warning_sent_at;
- no mail/job queued.

#### Active free extension
- current owner.free=true required;
- extends by group.placement_days, not current global placement duration;
- published_at/placement_days unchanged;
- expiry_warning_sent_at reset;
- status remains active;
- no payment/history transition;
- no mail/job;
- direct IDOR blocked.

#### Expired free extension
- inside window -> approved;
- history expired -> approved with system actor;
- no moderation/payment;
- exact boundary allowed;
- after boundary rejected;
- old dates remain until activation;
- outside-window UI has no effective extension action.

#### Current tariff
- historical group.free=false + current owner.free=true -> free extension works;
- historical group.free=true + current owner.free=false -> no extension;
- group.free never changed by extension.

#### Re-publication
- expired free extension -> approved appears in admin approved filter;
- activation recalculates fresh dates using current placement_duration_days;
- new placement_days may differ from previous snapshot;
- public_uuid unchanged.

#### Paid boundary
- paid owner extension GET informational only;
- direct POST rejected;
- gp_payments count unchanged;
- no WEBPAY/payment route/action/link.

#### Admin expired filter
- quick=expired selects expired only;
- pagination/sort works;
- real UI reminder for manual unpublication;
- no payment/application queries.

#### Access/regression
- foreign owner group 404;
- admin 403 on psychologist extension;
- revoked account middleware remains;
- Stage 4–8 suites remain green;
- all 31/249 prototype variants remain;
- production route isolation remains.

### 22. Runtime/manual verification

Using real Docker HTTP/browser:

1. Create/activate a free-owner group.
2. Verify active dates and remaining-time display.
3. Move time / prepare a near-expiry group and verify warning UI only, no email.
4. Run groups:expire before expiry -> no change.
5. Run at/after expiry -> status expired once.
6. Verify admin “Снять с публикации” quick filter.
7. Free active extension -> expiry shifts by stored placement_days.
8. Free expired extension inside window -> approved.
9. Admin republishes/activates -> new dates use current setting.
10. Change current owner tariff after group creation and verify current tariff, not group.free, selects behavior.
11. Paid current owner sees unavailable paid-extension state and cannot POST around it.
12. Verify zero new gp_payments and zero queued warning-email jobs.
13. Check representative active/warning/expired/extension/admin pages at 1440/1024/390.

### 23. Documentation

Update:

- docs/architecture.md — lifecycle scheduler/locking, warning calculation, current-tariff extension boundary;
- docs/development.md — groups:expire command, scheduler local verification, free-extension manual scenarios;
- docs/project-status.md — Stage 9 completed and Stage 10+ pending;
- docs/ui-pages.md — real extension/expired/warning route wiring while preserving prototypes.

Document production requirement for normal Laravel schedule:run cron, but do not implement deployment.

Do not modify SPEC.md, WORKFLOW.md, or AGENTS.md.

## Explicit Out Of Scope

Do not implement:

- warning email/job;
- SMTP;
- password/email onboarding;
- WEBPAY;
- placement payments;
- paid extension payments;
- payment notifications/recovery polling;
- refund behavior;
- applications/counters;
- public API/HMAC;
- automatic public-site publish/unpublish;
- Stage 10 retention cleanup;
- production deployment.

Do not create active routes/actions for these future-stage behaviors.

## Constraints

- Follow WORKFLOW.md and AGENTS.md.
- Reuse accepted Stage 3 views.
- Use GroupStatusTransitionService for active -> expired and expired -> approved.
- Use current gp_users.free for extension eligibility.
- Never use gp_groups.free to choose extension tariff.
- Active free extension uses stored group.placement_days.
- Re-publication activation uses current placement_duration_days.
- Use current expired_extension_window_days at extension time.
- Scheduler correctness must be transactional/lock-safe and idempotent.
- No email or payment side effects.
- Tests remain MySQL-only.
- Preserve all 249 prototype variants.
- No Node/npm/Vite.
- No secrets/real data.
- Do not alter .ai/task.md.

## Acceptance Criteria

1. groups:expire exists and is scheduled every minute.
2. Active group remains active before expires_at.
3. At/after expires_at active -> expired through domain transition service.
4. Expiration history has system actor and is created once.
5. Repeated/concurrent lifecycle processing does not duplicate transition/history.
6. Soft-deleted/non-active/null-expiry records are ignored.
7. Disabled active group still expires.
8. Active real UI shows correct remaining/expiry state.
9. Warning uses current expiry_warning_days and is not hardcoded.
10. Warning UI does not queue email or set expiry_warning_sent_at.
11. Real admin quick=expired filter lists groups requiring manual unpublication.
12. Free active extension uses current owner.free=true and stored placement_days.
13. Active extension keeps status/published_at/placement_days and resets expiry_warning_sent_at.
14. Free expired extension inside current extension window transitions expired -> approved.
15. expired -> approved history uses system actor.
16. Exact extension-window boundary is allowed; after it direct POST is rejected.
17. Expired extension does not run moderation or create payment.
18. Old expired period dates remain until reactivation.
19. Re-publication activation recalculates dates using current placement_duration_days.
20. Current owner tariff, not gp_groups.free, controls extension mode.
21. Historical gp_groups.free remains unchanged.
22. Paid current owner cannot extend in Stage 9 and gets truthful unavailable UX.
23. Paid direct POST creates no state/date/payment changes.
24. No Stage 9 flow creates gp_payments or uses WEBPAY.
25. No warning mail/job is introduced or queued.
26. Foreign group IDOR is blocked; admin cannot use psychologist extension routes.
27. Real UI uses existing Stage 3 group/extension/admin views.
28. Prototype warning/extension variants and all 31/249 entries remain green.
29. Stage 4–8 regression remains green.
30. Full MySQL suite passes.
31. Pint passes.
32. Larastan passes.
33. composer check-platform-reqs passes.
34. Blade compilation passes.
35. Representative pages work at 1440/1024/390.
36. Documentation reflects actual Stage 9 behavior.
37. Final diff is limited to lifecycle/extension/scheduler/UI integration/tests/docs and .ai/report.md.

## Verification Commands

Run and report exact results.

1. Confirm Docker services healthy.
2. Migrate/seed without destructive reset.
3. Inspect scheduler list and confirm groups:expire cadence/overlap guard.
4. Run groups:expire against before/due/after fixtures.
5. Verify repeated execution creates one expiration history only.
6. Verify active/expired free extension real browser flows.
7. Verify paid current tariff cannot create payment/extension effect.
8. Verify current-tariff changes override historical group.free for extension decision.
9. Verify expired quick filter/manual-unpublish reminder.
10. Verify no Mail/Queue warning dispatch and no gp_payments changes.
11. Run:
   - docker compose exec -T php php artisan test
   - docker compose exec -T php ./vendor/bin/pint --test
   - docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress
   - docker compose exec -T php composer check-platform-reqs
   - docker compose exec -T php php artisan view:cache
12. Inspect route list and production isolation.
13. Inspect group status history/dates around expiration/extension/republication.
14. Inspect representative UI at 1440/1024/390.
15. Inspect git diff/status/staged files.
16. Confirm no secrets, real data, browser artifacts, screenshots or unrelated files are staged.

## Hard Workflow Gate

Before changing files:

- read WORKFLOW.md, AGENTS.md, SPEC.md, docs/project-status.md, docs/ui-pages.md, and this .ai/task.md;
- run git log --oneline -5;
- run git status --short;
- confirm base commit 28aabaab3bcc84622959f9e81088d0844c90fc5f;
- inspect GroupWorkflow, GroupStatusTransitionService, SettingService, GroupPolicy and existing extension/group views;
- do not overwrite unknown local changes.

During implementation:

- stay strictly in Stage 9;
- do not implement Stage 10 applications;
- do not implement Stage 12 email warning jobs;
- do not implement Stage 13/14 WEBPAY/payment extension;
- use domain transitions and row locks;
- preserve prototype behavior/accepted design;
- do not alter .ai/task.md;
- do not change governance/spec files.

Before commit:

- run all required checks;
- perform real browser/command lifecycle smoke verification;
- update .ai/report.md with command/schedule, locking/idempotency, warning UI calculation, extension rules/current tariff, no-email/no-payment evidence, tests/runtime checks, facts/assumptions/unknowns;
- inspect full diff and staged files;
- stage only Stage 9 files plus .ai/report.md;
- ensure no runtime/test artifacts are staged.

Completion:

- use Status: done only if all acceptance criteria are satisfied;
- otherwise use partial, blocked, or failed;
- if complete, commit with:

codex: TASK-2026-09-21-07 implement group lifecycle free extension

- do not create an accept commit.
