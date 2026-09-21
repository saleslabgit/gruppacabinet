# Task: TASK-2026-09-21-08

Status: planned
Created from: 96b64c9323ba78ff434e35f2bdeadf15c623e344 (main)

## Title

Stage 10 — Implement internal participant application lists, ownership/IDOR, counters, processing state, phone search, and retention cleanup

## Goal

Implement Stage 10 from SPEC.md using the accepted Stage 3 psychologist/admin application Blade views.

This milestone makes participant applications fully usable **inside the cabinet** using synthetic/factory data, while deliberately not implementing the external public-site API yet.

After this task:

- a psychologist sees applications only for groups they own;
- a psychologist can open an application of their own group;
- a psychologist can mark it processed and return it to unprocessed;
- group list/detail counters show real new/processed/all counts;
- an administrator can see/search/filter all applications across all groups/psychologists;
- an administrator can open any application read-only;
- application lists are paginated and no-N+1;
- phone search uses normalized phone data;
- a scheduled cleanup command permanently deletes applications strictly older than the configured retention period;
- all real application UI uses approved Stage 3 views.

External /api/v1 intake, HMAC, request idempotency and public-site integration remain Stage 11.

## Facts

- Stages 1–9 are accepted through commit 96b64c9323ba78ff434e35f2bdeadf15c623e344.
- gp_group_applications already exists with:
  - id;
  - group_id;
  - last_name;
  - first_name;
  - phone;
  - phone_normalized;
  - processed_at;
  - timestamps.
- Indexes already exist on:
  - group_id;
  - processed_at;
  - (group_id, processed_at);
  - created_at.
- GroupApplication model already belongsTo Group.
- Group belongsTo owner User.
- SettingService already exposes participantApplicationRetentionMonths().
- Default retention setting is 12 months.
- Stage 7/9 psychologist group list/detail currently use placeholder zero/unavailable application data.
- Stage 3 approved views already exist:
  - psychologist/applications/index.blade.php;
  - psychologist/applications/show.blade.php;
  - admin/applications/index.blade.php;
  - admin/applications/show.blade.php;
  - shared/application-list.blade.php;
  - shared/application-detail.blade.php;
  - shared/application-counters.blade.php.
- Prototype catalogue includes real-looking application states but they remain synthetic/no-op.
- Stage 11 will later create applications from the public site by group public_uuid.
- Stage 10 must not expose a cabinet form/action that creates participant applications.

## Product / Architecture Decisions

### No real application creation route in Stage 10

Stage 10 is internal read/update UI only.

Do not add POST /applications create behavior.
Do not add public API routes.
Do not add a psychologist/admin “create application” action.

Tests use model factories.
Manual/local verification may create synthetic fixture records directly/factory/script, but no production UI endpoint is introduced.

### Processing state

processed_at is the only source of truth:

- processed_at = null -> new/unprocessed;
- processed_at != null -> processed.

Mark processed:
- set processed_at to current UTC time.

Return to unprocessed:
- set processed_at = null.

Repeated action should be safe/idempotent:
- marking an already processed application does not keep changing its timestamp;
- returning an already unprocessed application remains null.

No separate status column.

### Phone normalization boundary

Stage 10 needs reusable normalization for storage/search, but must not invent a default country for ambiguous local numbers.

Add a small reusable PhoneNormalizer suitable for Stage 11:

- trim whitespace;
- remove formatting separators such as spaces, parentheses, hyphens and dots;
- accept an international + followed by digits;
- convert 00-prefixed international notation to +;
- a bare number already beginning with country digits may be canonicalized to +digits only when it is unambiguous under the implemented rule;
- do not silently assume a country for arbitrary local numbers;
- normalized storage value should be canonical +digits where an international number is available;
- search normalization should compare digits independent of display punctuation.

Synthetic Stage 10 fixtures should use explicit international phone numbers.

If the existing SPEC does not define enough information to convert an ambiguous local number to international form, do not guess. Stage 11 API validation may tighten accepted input when integration requirements are known.

### Retention cutoff

Use current SettingService::participantApplicationRetentionMonths() at command run time.

Delete applications where:

created_at < current UTC time minus configured retention months

Exact cutoff equality is retained; only strictly older rows are removed.

Deletion is physical/permanent, because gp_group_applications currently has no soft-delete column and SPEC explicitly requires permanent retention cleanup.

Applications belonging to soft-deleted groups are still subject to retention cleanup.

## Scope

### 1. Model / factory foundation

Improve GroupApplication model typing/relationships as needed.

Add GroupApplicationFactory for tests and local synthetic setup.

Factory should:
- require/associate a Group;
- create clearly synthetic names/phones;
- populate phone and phone_normalized consistently through the reusable normalizer or a centralized creation invariant;
- support processed/unprocessed states.

Do not seed fake production applications in production.

Do not commit real personal data.

### 2. Reusable phone normalizer

Add a small support/service class, not controller-specific parsing.

It should provide at least:
- normalizeForStorage(string): canonical normalized value or explicit validation/error for unsupported ambiguous input;
- digitsForSearch(string): digits-only comparable search key.

Tests must cover:
- +375 (29) 123-45-67;
- 00375 29 123 45 67;
- already normalized international input;
- punctuation/whitespace;
- malformed non-phone text;
- ambiguous local numbers are not silently assigned a country.

Do not add a third-party phone package in this stage.

Stage 11 must be able to reuse the same normalization service.

### 3. Psychologist group counters

Replace Stage 7 placeholder counters with real counts on psychologist group list/detail.

For every listed group expose:
- new_count = applications where processed_at is null;
- processed_count = applications where processed_at is not null;
- all_count = total applications.

Use Eloquent withCount/subqueries in the main group query.
No per-group application query.

Group list:
- display real shared.application-counters;
- active link “Открыть заявки” to that group’s real application list.

Group detail:
- display real counters;
- show truthful empty/latest snippet as appropriate without a per-group N+1;
- active link “Все заявки группы”.

Do not restrict viewing existing applications based on current group lifecycle status; owners may still need historical applications after expiry. Ownership is the access boundary.

### 4. Owner application routes

Under account + role:psychologist add nested owner routes, recommended:

GET /groups/{group}/applications
GET /groups/{group}/applications/{application}
POST /groups/{group}/applications/{application}/processed
POST /groups/{group}/applications/{application}/unprocessed

Names under psychologist.applications.* or psychologist.groups.applications.* are acceptable if consistent.

Use owner-scoped lookup:
- group.owner_id = authenticated user id;
- application.group_id = resolved group id.

Foreign group/application combinations return 404 without leaking data.

Do not globally bind an application and trust only policy afterward.

### 5. Psychologist application list

Connect psychologist.applications.index to real data.

Requirements:
- only one owned group selected by route;
- real group title/counters;
- filter processed:
  - all;
  - new;
  - processed;
- pagination 20;
- newest first with deterministic id DESC tie-break;
- query-string preservation;
- real empty/no-results state;
- no prototype links/actions;
- no search field is required for psychologist list unless the approved view naturally supports it;
- no N+1.

Application rows show:
- participant full name from last_name + first_name;
- phone;
- created_at;
- processed/new status;
- open;
- mark processed / return unprocessed.

### 6. Psychologist application detail

Connect psychologist.applications.show to real data.

Show:
- participant name;
- phone;
- group link;
- received date;
- updated date;
- processed date/status;
- real process/unprocess action;
- back link to the group’s application list.

Do not expose:
- group owner ID as editable data;
- phone_normalized as a hidden internal field unless needed for search/debug;
- unrelated participant/application rows.

### 7. Process / unprocess workflow

Add a small ApplicationWorkflow/ApplicationService or equivalent explicit service.

Within transaction/row lock:
- re-resolve application under owned group;
- authorize owner access;
- mark processed only if currently null;
- unprocess only if currently non-null;
- repeated same-state action returns success without changing processed_at again.

Use current UTC time.
No audit is required by SPEC for participant processing state.
No email/job/API side effect.

Do not allow administrator mutation in Stage 10; admin application detail is read-only.

### 8. Application policy / IDOR

Add GroupApplicationPolicy or equivalent.

Psychologist:
- view only if application.group.owner_id == actor.id;
- process/unprocess only own group applications.

Administrator:
- can viewAny/view all;
- no process/unprocess action required in Stage 10.

Still prefer scoped queries/routes for owner endpoints so guessed foreign IDs return 404.

Revoked psychologist/admin access continues through existing account middleware.

### 9. Admin navigation

Add real “Заявки” to admin navigation now that Stage 10 routes exist.

Real admin nav becomes:
- Главная;
- Психологи;
- Группы;
- Заявки;
- Платежи;
- Справочники;
- Настройки;
- Выход.

Psychologist navigation remains:
- Мои группы;
- Мои данные;
- Выход.

No separate global psychologist Applications nav item is required; applications are entered from a group.

### 10. Admin routes

Under account + role:admin add:

GET /admin/applications
GET /admin/applications/{application}

No admin create/update/delete/process routes.

### 11. Admin application list

Connect admin.applications.index to real data.

Implement:
- search field over:
  - participant last_name;
  - participant first_name;
  - combined participant name where practical;
  - raw phone;
  - normalized phone;
  - group title;
  - psychologist full name/email;
- processed filter:
  - all;
  - new;
  - processed;
- pagination 20;
- newest first with deterministic id DESC;
- query preservation;
- eager-load group + owner;
- no N+1.

Phone search:
- normalize user-entered phone-like query to digits and match normalized phone;
- textual search still searches participant/group/psychologist fields;
- do not make phone normalization cause SQL errors for arbitrary text.

Rows show:
- participant;
- phone;
- group with real admin group link;
- psychologist with real Stage 5 profile link;
- created date;
- status;
- open detail.

### 12. Admin application detail

Connect admin.applications.show to real data.

Show:
- participant;
- phone;
- group;
- psychologist;
- received/updated/processed times;
- status.

Real links:
- group -> admin.groups.show;
- psychologist -> admin.psychologists.show;
- back -> admin.applications.index.

No admin processed/unprocessed action in Stage 10.

### 13. Group/application counters in admin context

At minimum, psychologist group surfaces must have real counters per SPEC.

If existing admin group detail naturally contains application counters, connect them truthfully too, with a link to admin applications filtered by group where practical.

Do not add expensive per-row admin counter queries.

### 14. Retention cleanup service

Add a small ApplicationRetentionService or equivalent.

Use current SettingService::participantApplicationRetentionMonths() once per run.

Behavior:
- cutoff calculated in UTC;
- delete only created_at < cutoff;
- exact cutoff retained;
- permanent physical DELETE;
- process in bounded chunks;
- application/group status does not exempt records;
- records for soft-deleted groups are included;
- do not delete groups/users;
- do not log participant names/phones.

Return only aggregate deleted count.

### 15. Retention command

Add an Artisan command such as:

php artisan applications:cleanup

Output/log only aggregate count, e.g.:
Deleted applications: N

No participant PII.

Repeated run after deletion should report 0.

### 16. Scheduler registration

Schedule applications:cleanup once daily with withoutOverlapping.

Use Laravel Scheduler; exact wall-clock minute is not a product requirement, so standard daily() in app timezone/UTC is sufficient.

Keep existing groups:expire every-minute schedule unchanged.

Do not add Stage 11 API or Stage 12 warning-mail schedules.

### 17. Retention race / boundary safety

Tests must cover:
- record one second older than cutoff deleted;
- exact cutoff retained;
- one second newer retained;
- retention setting change affects next run;
- processed and unprocessed old applications both deleted;
- application attached to soft-deleted group still deleted;
- repeated command idempotent;
- cleanup never deletes groups/users;
- aggregate output contains no PII.

Use transaction/chunk strategy safe for normal scheduler concurrency.
Command-level withoutOverlapping is required; DB-level duplicate deletion is naturally safe but avoid chunk pagination bugs while deleting.

### 18. No external intake boundary

Hard gate:

- no /api/v1 application endpoint;
- no HMAC;
- no X-Timestamp;
- no X-Request-Id;
- no public-site secret;
- no rate limit specific to integration;
- no browser/public application form inside cabinet;
- no create route for participant applications.

Stage 11 will create incoming applications.

### 19. No payment/email side effects

Stage 10 application read/process/cleanup must not:
- create/update payments;
- invoke WEBPAY;
- send/queue email;
- alter group lifecycle.

Add regression assertions where useful.

### 20. Blade integration

Reuse existing:
- psychologist/applications/index;
- psychologist/applications/show;
- admin/applications/index;
- admin/applications/show;
- shared/application-list;
- shared/application-detail;
- shared/application-counters;
- psychologist/groups/index/show;
- group action/link surfaces as needed.

Adapt shared application partials with explicit real URLs/actions/capabilities rather than role guessing when practical.

Prototype modes remain:
- synthetic;
- no-op;
- all existing variants unchanged.

Do not redesign Stage 3.
A global UI/UX audit is intentionally planned after Stage 10 as a separate task.

### 21. Pagination and query performance

All application lists paginate 20.

Tests must verify constant query counts as rows/groups grow.

Psychologist group list:
- counters via withCount/subqueries;
- no application query per group.

Application lists:
- no group/owner query per row.

No payment queries should be introduced by application surfaces.

### 22. Tests

All tests run on MySQL.

Add focused coverage for at least:

#### Factory / normalization
- factory creates valid synthetic application;
- phone normalization canonical cases;
- formatted phone search matches normalized record;
- invalid/ambiguous phone normalization handled explicitly;
- no real personal data.

#### Psychologist counters
- 0/new/processed/all counts correct;
- counts update immediately after process/unprocess;
- multiple groups do not create N+1;
- group detail/list link to correct application list.

#### Owner application list/detail
- only own group applications;
- all/new/processed filters;
- pagination/query preservation;
- deterministic ordering;
- empty state;
- owner can open own;
- foreign group/application IDOR returns 404;
- cross-group application substitution returns 404.

#### Process/unprocess
- new -> processed sets processed_at once;
- repeated processed action keeps original timestamp;
- processed -> unprocessed sets null;
- repeated unprocessed remains null;
- unauthorized actor cannot mutate;
- admin has no mutation route.

#### Admin list/detail
- sees all groups’ applications;
- search participant name;
- search raw/formatted phone;
- search normalized phone digits;
- search group title;
- search psychologist name/email;
- processed filters;
- pagination/query preservation;
- real group/profile links;
- constant query count/no N+1.

#### Retention
- command exists;
- daily schedule + withoutOverlapping;
- older than cutoff deleted;
- exact cutoff retained;
- newer retained;
- current retention setting used;
- processed/unprocessed both covered;
- soft-deleted group application covered;
- repeated run zero;
- group/user records preserved;
- output contains aggregate only/no participant PII.

#### Boundaries/regression
- psychologist cannot use admin application routes;
- admin cannot use psychologist owner routes;
- disabled/rejected/deleted access revoked;
- no API/create application route;
- no payment/email/group lifecycle side effects;
- Stage 4–9 suites remain green;
- all 31/249 prototype variants remain green;
- production prototype isolation remains.

### 23. Runtime/manual verification

Using real Docker/browser with synthetic fixture rows:

1. Create or identify two approved psychologists with separate groups.
2. Insert synthetic applications through factory/local fixture setup only.
3. Login as psychologist A:
   - group counters correct;
   - open applications for own group;
   - filter new/processed;
   - open detail;
   - mark processed;
   - return unprocessed.
4. Guess psychologist B group/application IDs -> no data/404.
5. Login admin:
   - Applications nav appears;
   - global list includes both owners;
   - search by name/phone/group/psychologist;
   - filters/pagination;
   - detail links to group and psychologist.
6. Create retention boundary fixtures and run applications:cleanup.
7. Confirm only strictly older rows were deleted.
8. Confirm groups/users/payments/lifecycle unchanged.
9. Verify no mail/queue work.
10. Check representative group-with-counters/application-list/detail/admin pages at 1440/1024/390.

Keep all synthetic data clearly non-production and clean up smoke-only fixtures where practical.

### 24. Documentation

Update:

- docs/architecture.md — application ownership/policy, processing state, phone normalization boundary, retention cleanup;
- docs/development.md — creating synthetic application fixtures and manual Stage 10 verification;
- docs/project-status.md — Stage 10 complete; Stage 11 incoming integration pending;
- docs/ui-pages.md — real application route wiring while preserving prototype catalogue.

Document that the global UI/UX audit is the recommended next separate product task before Stage 11 if requested by product owner.

Do not modify SPEC.md, WORKFLOW.md, or AGENTS.md.

## Explicit Out Of Scope

Do not implement:

- public /api/v1;
- HMAC/signature/timestamp/idempotency integration;
- public-site application intake;
- participant application create form in cabinet;
- email;
- password onboarding;
- WEBPAY/payment changes;
- group moderation/lifecycle changes beyond reading counters;
- automatic public-site behavior;
- global UI redesign/audit in this task;
- production deployment.

## Constraints

- Follow WORKFLOW.md and AGENTS.md.
- Reuse accepted Stage 3 views.
- Owner access is derived from application.group.owner_id.
- Owner routes must use scoped lookup for 404-style IDOR protection.
- processed_at is the processing source of truth.
- Search normalization must not invent a country code for ambiguous local numbers.
- Retention uses current typed setting.
- Cleanup is permanent and PII-safe in output/logs.
- Tests remain MySQL-only.
- Preserve all 249 prototype variants.
- No Node/npm/Vite.
- No secrets/real participant data.
- Do not alter .ai/task.md.

## Acceptance Criteria

1. Real psychologist group list/detail show correct application counters.
2. Counters use aggregate queries/no N+1.
3. Owner can open real application list for own group.
4. Owner list supports all/new/processed filters and pagination.
5. Owner can open only own group application detail.
6. Foreign/cross-group IDs return no application data.
7. Owner can mark new application processed.
8. Repeated process action does not change original processed_at again.
9. Owner can return processed application to unprocessed.
10. Repeated unprocess is idempotent.
11. Admin Applications navigation and real global list exist.
12. Admin sees applications across psychologists.
13. Admin search works for participant/group/psychologist and normalized phone.
14. Admin processed filters/pagination work.
15. Admin detail links to real group and psychologist.
16. Admin has no application mutation route in Stage 10.
17. Phone normalization/search is centralized and reusable for Stage 11.
18. Ambiguous local phone numbers are not silently assigned a country.
19. applications:cleanup exists and uses current retention setting.
20. Cleanup physically deletes only rows strictly older than cutoff.
21. Exact cutoff/newer records remain.
22. Processed/unprocessed and soft-deleted-parent records are covered.
23. Cleanup output/log is aggregate-only and contains no participant PII.
24. Cleanup is scheduled daily with overlap protection.
25. Groups/users are never removed by retention cleanup.
26. No real application creation/public API route exists.
27. No payment/email/group-lifecycle side effects are introduced.
28. Psychologist/admin role boundaries and revoked-account behavior remain.
29. Real application/group pages reuse Stage 3 Blade views/components.
30. Representative real pages work at 1440/1024/390.
31. All 31 page groups / 249 prototype variants remain green.
32. Stage 4–9 regression remains green.
33. Full MySQL suite passes.
34. Pint passes.
35. Larastan passes.
36. composer check-platform-reqs passes.
37. Blade compilation passes.
38. Production route isolation remains correct.
39. Documentation reflects actual Stage 10 behavior.
40. Final diff is limited to Stage 10 applications/counters/normalization/cleanup/UI integration/tests/docs and .ai/report.md.

## Verification Commands

Run and report exact results.

1. Confirm Docker services healthy.
2. Migrate/seed without destructive reset.
3. Inspect application routes: no create/API route.
4. Inspect scheduler list: groups:expire unchanged + applications:cleanup daily overlap-protected.
5. Create synthetic application fixtures via factory/local setup.
6. Verify owner counters/list/detail/process/unprocess and cross-owner IDOR.
7. Verify admin list/search/filter/detail.
8. Verify phone search with formatted and normalized input.
9. Run retention cutoff fixtures and applications:cleanup twice.
10. Confirm groups/users remain and no PII appears in command output.
11. Confirm no payments/mail/jobs/lifecycle changes.
12. Run:
   - docker compose exec -T php php artisan test
   - docker compose exec -T php ./vendor/bin/pint --test
   - docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress
   - docker compose exec -T php composer check-platform-reqs
   - docker compose exec -T php php artisan view:cache
13. Inspect route list and production isolation.
14. Inspect query counts for group counters and application lists.
15. Inspect representative UI at 1440/1024/390.
16. Inspect git diff/status/staged files.
17. Confirm no secrets, real participant data, screenshots, browser artifacts or unrelated files are staged.

## Hard Workflow Gate

Before changing files:

- read WORKFLOW.md, AGENTS.md, SPEC.md, docs/project-status.md, docs/ui-pages.md, and this .ai/task.md;
- run git log --oneline -5;
- run git status --short;
- confirm base commit 96b64c9323ba78ff434e35f2bdeadf15c623e344;
- inspect GroupApplication, Group/owner relations, GroupPages, application prototype/shared views, current scheduler and SettingService;
- do not overwrite unknown local changes.

During implementation:

- stay strictly in Stage 10;
- do not begin Stage 11 external API/HMAC;
- do not add application creation UI;
- do not implement email/WEBPAY;
- use scoped owner access and aggregate counters;
- preserve prototype behavior/accepted design;
- do not alter .ai/task.md;
- do not change governance/spec files.

Before commit:

- run all required checks;
- perform real browser/command Stage 10 smoke verification;
- update .ai/report.md with routes/policies/services, counters/query behavior, processing idempotency, phone normalization, retention command/schedule, no-API/no-PII evidence, tests/runtime checks, facts/assumptions/unknowns;
- inspect full diff and staged files;
- stage only Stage 10 files plus .ai/report.md;
- ensure no runtime/test artifacts are staged.

Completion:

- use Status: done only if all acceptance criteria are satisfied;
- otherwise use partial, blocked, or failed;
- if complete, commit with:

codex: TASK-2026-09-21-08 implement internal applications workflow

- do not create an accept commit.
