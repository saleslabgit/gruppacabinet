# Task: TASK-2026-09-21-06

Status: planned
Created from: 1f5182039fadcfe53bb737c30748104c2535308c (main)

## Title

Stage 8 — Implement real dictionary administration, typed business settings, and pre-WEBPAY payment surface

## Goal

Implement Stage 8 from SPEC.md using the accepted Stage 3 admin Blade views.

After this milestone an authenticated administrator must be able to manage the reusable dictionary values and all defined business-setting values entirely through the cabinet, without direct MySQL access or source edits.

Also expose the approved admin Payments page as a truthful pre-WEBPAY informational surface only. It must not create, mutate, verify, simulate, or otherwise behave like a payment integration.

This milestone must unblock realistic manual group/profile testing by allowing real values for:
- education_type;
- group_format;
- gender;
and real configuration of placement/extension prices and lifecycle timing settings.

## Facts

- Stages 1–7 are accepted through commit 1f5182039fadcfe53bb737c30748104c2535308c.
- gp_dictionaries and gp_dictionary_items already exist.
- Seeded dictionary containers are:
  - education_type;
  - group_format;
  - gender.
- No approved item display values are currently seeded; this is intentional.
- Stage 5 psychologist forms already query education_type active items and preserve a current inactive selection.
- Stage 7 group forms already query group_format/gender active items and preserve current inactive selections.
- gp_settings already contains the seven typed integer settings:
  - placement_price_minor_units, nullable;
  - extension_price_minor_units, nullable;
  - placement_duration_days;
  - expiry_warning_days;
  - expired_extension_window_days;
  - participant_application_retention_months;
  - password_setup_link_ttl_hours.
- SettingService is already the typed cached read boundary.
- Stage 7 activation reads SettingService::placementDurationDays().
- Existing active groups snapshot placement_days and expires_at when activated.
- AuditService and gp_audit_log already exist.
- SPEC requires audit of critical business-setting changes.
- Approved Stage 3 views already exist:
  - admin/dictionaries/index.blade.php;
  - admin/dictionaries/items.blade.php;
  - admin/settings/index.blade.php;
  - admin/payments/index.blade.php;
  - admin/payments/show.blade.php prototype.
- Stage 13 is the first real WEBPAY integration stage.
- No real payment record is created by Stages 1–8.

## Product / Architecture Decisions

### Stable dictionary codes

Dictionary and dictionary-item codes are stable identifiers.

- Code is supplied when creating a dictionary/item.
- Code is immutable after creation.
- Editing changes display name and other mutable presentation fields, not code.
- The core dictionary container codes education_type, group_format, gender must not be deleted or renamed because application forms depend on them.
- Custom dictionary containers may be created.
- A custom dictionary container may be physically deleted only when it contains no items and is not referenced by application data.

### Dictionary item removal

- Used dictionary items are never physically deleted.
- Used items are deactivated instead.
- Deactivated items disappear from new forms but remain visible in existing records/edit forms that already reference them.
- Unused items may be physically deleted through an explicit confirmation action.
- Inactive items may be reactivated.

### Settings keys

The seven setting keys are application-defined typed configuration keys, not user-defined records.

- Admin may edit their values.
- Admin must not create arbitrary new setting keys through UI.
- Admin must not rename or delete required setting keys.
- All reads/writes go through the typed SettingService boundary.
- gp_settings remains the source of business-setting values.

### Payment surface

Stage 8 adds a real admin route to the approved Payments index view only.

The real page is informational/pre-WEBPAY:
- explains that WEBPAY is not connected yet;
- contains no synthetic payment rows;
- contains no provider actions;
- contains no refund actions;
- does not expose a real payment-detail route;
- does not query or mutate gp_payments for the normal page.

Prototype payment list/detail variants remain unchanged and synthetic.

## Scope

### 1. Admin navigation and routes

Under existing account + role:admin protection add real routes for:

#### Dictionaries
- list/create;
- edit/update dictionary metadata;
- delete eligible custom empty dictionary;
- item list/create;
- item edit/update;
- activate;
- deactivate;
- delete eligible unused item.

Use stable names under admin.dictionaries.*.

#### Settings
- GET /admin/settings;
- PUT/PATCH /admin/settings.

#### Payments
- GET /admin/payments only.

Update real admin navigation to:
- Главная;
- Психологи;
- Группы;
- Платежи;
- Справочники;
- Настройки;
- Выход.

Do not add real Applications navigation until Stage 10.
Do not add a real payment detail route in Stage 8.

### 2. Real dictionary list

Connect admin/dictionaries/index.blade.php to gp_dictionaries.

Display:
- stable code;
- name;
- item count;
- active item count;
- actions.

Requirements:
- deterministic ordering by code/id;
- pagination, 20 per page;
- no N+1;
- real empty state;
- create form;
- edit form using the same approved page/view, not a parallel page;
- prototype mode remains no-op.

### 3. Dictionary validation

Use Form Requests.

Dictionary create:
- code required;
- lowercase stable machine code;
- allow a-z, 0-9 and underscore;
- max 64;
- unique;
- name required string max 255.

Dictionary update:
- code must not be writable;
- name may change.

Do not permit mass assignment of id/timestamps or unrelated fields.

### 4. Core dictionary protection

Protect core containers:
- education_type;
- group_format;
- gender.

For core dictionaries:
- code immutable;
- physical delete forbidden.

Their names may be edited.

Custom empty dictionaries may be deleted only after explicit confirmation.

A dictionary with items may not be deleted; admin must manage/deactivate/remove eligible items first.

### 5. Real dictionary item list

Connect admin/dictionaries/items.blade.php to one real parent dictionary.

Display:
- code;
- name;
- sort_order;
- active/inactive;
- whether the item is currently used by application data;
- actions available for that state.

Requirements:
- parent dictionary identity shown truthfully;
- order by sort_order then id;
- pagination, 20 per page;
- nested ownership/scoping: changing dictionary ID while retaining another dictionary’s item ID must fail safely;
- no N+1.

### 6. Dictionary item validation / CRUD

Create:
- stable code required, [a-z0-9_], max 64;
- code unique within parent dictionary;
- name required max 255;
- sort_order non-negative integer;
- active boolean, default true if omitted.

Update:
- code immutable;
- name/sort_order/active mutable, subject to safe lifecycle rules.

Activation/deactivation:
- explicit actions;
- deactivation has confirmation;
- reactivation supported;
- repeated activation/deactivation must not create misleading state.

Delete:
- explicit destructive confirmation;
- unused item may be physically deleted;
- used item cannot be physically deleted and must instead be deactivated.

Do not silently cascade or rewrite existing users/groups.

### 7. Dictionary usage detection

Usage checks must correctly cover current core references:

- education_type item -> gp_users.education_type_id;
- group_format item -> gp_groups.format_id;
- gender item -> gp_groups.gender_id.

Soft-deleted users/groups still count as historical usage and must prevent physical deletion.

Do not infer usage only from current visible records.

For custom dictionaries not referenced by current schema, items are considered unused unless another known relation is introduced.

Keep this logic centralized in a small service/helper rather than scattering raw checks through Blade.

### 8. Integration with existing forms

Prove existing Stage 5/7 behavior with real dictionary mutations:

- active item appears in new psychologist/group forms;
- deactivated item disappears from new forms;
- an existing record that references a now-inactive item still shows/preserves it in edit/detail;
- reactivation restores it to new forms;
- no source-code change/seed rerun is needed after adding values through admin UI.

This is a core Stage 8 acceptance requirement.

### 9. Real settings page

Connect admin/settings/index.blade.php to real typed values.

Display/edit:

Prices:
- placement_price_minor_units as human BYN placement_price;
- extension_price_minor_units as human BYN extension_price;
- blank means unconfigured/null.

Durations:
- placement_duration_days;
- expiry_warning_days;
- expired_extension_window_days;
- participant_application_retention_months;
- password_setup_link_ttl_hours.

Real form:
- POST method override PUT/PATCH as appropriate;
- CSRF;
- server validation;
- old input;
- approved validation/error areas;
- explicit confirmation before committing changes.

Prototype form remains no-op.

### 10. Settings validation

Use a dedicated Form Request.

Prices:
- nullable;
- non-negative;
- normal human input such as 50, 50.0, 50,00;
- max 2 fractional digits;
- convert to integer minor units without float;
- reject negatives, exponent notation, malformed values, >2 decimals, overflow.

Integer settings:
- required positive integers.

Cross-field:
- expiry_warning_days must be strictly less than placement_duration_days.

Do not introduce product-specific arbitrary maxima unless required for safe integer/date handling; if technical bounds are required, document them.

### 11. Typed SettingService write boundary

Extend SettingService with an explicit typed write/update API for the known seven settings.

Controllers must not update gp_settings directly.

The write boundary must:
- accept only known setting keys/typed normalized values;
- reject unknown keys;
- keep type=integer;
- update existing required rows transactionally;
- not create arbitrary missing keys silently;
- preserve nullable behavior only for the two price settings;
- invalidate cached values only after a successful DB commit;
- expose fresh values immediately after save.

Do not create a generic untyped settings repository.

### 12. Settings audit

Every changed Stage 8 business setting must create gp_audit_log entries using AuditService.

Use a stable action such as:
- setting.updated

Recommended fields:
- entity_type = setting;
- entity_id = gp_settings.id;
- actor = current administrator;
- metadata contains only:
  - key;
  - old_value;
  - new_value.

Values should be normalized stored integers/null:
- prices in minor units;
- durations/counts in integer units.

Do not audit unchanged values.
Do not store form payloads or unrelated data.

Settings update + audit must be transactional.
A failed audit must roll back setting changes.
Cache invalidation must not expose rolled-back values.

### 13. Placement duration snapshot regression

Explicitly prove:

- an already active group retains its existing placement_days/published_at/expires_at after placement_duration_days is changed;
- a later activation of another approved group uses the new SettingService placement duration.

Do not retroactively update existing group dates.

### 14. Prices before WEBPAY

Stage 8 may configure placement/extension prices even though WEBPAY is not connected.

Requirements:
- prices persist as minor units through SettingService;
- display back correctly as BYN;
- no payment is created when saving prices;
- changing price does not alter any existing payment/group record;
- Stage 7 free=false temporary no-payment group flow remains unchanged until Stage 13.

### 15. Payment informational surface

Add real GET /admin/payments using the existing admin.payments.index Blade.

Real mode must render a truthful pre-WEBPAY state, for example:
“Платежи ещё не подключены”.

Requirements:
- no synthetic order number, transaction, amount, status, owner or group;
- no search/filter controls that imply working payment data unless clearly disabled/unavailable;
- no real payment detail links;
- no refund control;
- no POST/PATCH/DELETE payment routes;
- no gp_payments / gp_payment_notifications query required for normal rendering;
- no provider/API call;
- no WEBPAY credentials/config added.

Prototype admin-payments/admin-payment variants remain unchanged.

### 16. Admin home/navigation truthfulness

Navigation now exposes Payments, Dictionaries, Settings because they have real Stage 8 routes.

Do not add Applications until Stage 10.

Admin home may remain the current truthful unavailable work-queue shell unless a simple real link/count is useful.
Do not fabricate counts.

### 17. Confirmation behavior

Dangerous actions require real confirmation:
- deactivate item;
- delete unused item;
- delete eligible custom empty dictionary;
- settings save/change.

Ensure confirmation modal submits the intended real form/data.

Avoid nested forms and ensure textarea/input values belong to the submitted form.
If shared confirmation component needs a small extension for “submit existing form”, preserve all prior usages and prototype behavior.

### 18. Authorization / IDOR

All Stage 8 routes require active authenticated admin.

Psychologists receive 403.

Nested item routes must not allow:
- dictionary A + item from dictionary B;
- editing/activating/deactivating/deleting another dictionary’s item by guessed ID.

Soft-deleted/disabled/rejected admin access continues to be revoked by existing account middleware.

### 19. Tests

All tests run on MySQL.

Cover at minimum:

#### Dictionary containers
- admin-only access;
- list/query count/pagination;
- create custom dictionary;
- unique/invalid code validation;
- code immutable after create;
- edit name;
- core dictionary delete forbidden;
- non-empty custom dictionary delete forbidden;
- empty custom dictionary delete works after confirmation.

#### Dictionary items
- create;
- uniqueness scoped to parent;
- edit name/sort;
- code immutable;
- deactivate/reactivate;
- used item cannot physically delete;
- unused item can delete;
- soft-deleted user/group references still count as use;
- nested dictionary/item IDOR;
- pagination/order/no N+1.

#### Existing-form integration
- new active education item appears in psychologist create form;
- inactive education item hidden for new records but retained for existing record;
- new active format/gender appears in group form;
- inactive format/gender hidden for new groups but retained for existing group;
- reactivation restores options.

#### Settings
- real values render;
- nullable price save/display;
- valid money conversion without float;
- malformed/negative/overflow price rejected;
- positive integer validation;
- warning_days < placement_days rule;
- known keys only;
- cache invalidation returns fresh saved value;
- unchanged settings produce no audit;
- changed settings produce actor/action/minimal old/new audit;
- simulated audit failure rolls back DB and cache-visible values.

#### Placement duration regression
- existing active group unchanged after setting edit;
- new activation uses updated duration.

#### Payment surface
- admin GET works and reuses approved view;
- psychologist 403;
- no fake payment identifiers/data/actions;
- no payment detail/mutation routes;
- rendering issues no gp_payments/gp_payment_notifications queries;
- settings price updates create no payment rows.

#### Regression
- Stage 4–7 tests remain green;
- group creation/edit still uses database dictionaries;
- all 31 / 249 prototype variants remain;
- production excludes prototype/foundation routes.

### 20. Manual/runtime verification

Through real Docker browser flow:

1. Admin logs in.
2. Open Dictionaries.
3. Add real synthetic/local test values to:
   - group_format;
   - gender;
   - education_type.
4. Confirm those values immediately appear in psychologist/group forms.
5. Deactivate one used value and confirm:
   - it disappears for new records;
   - an existing record still displays/retains it.
6. Reactivate it.
7. Open Settings.
8. Configure placement/extension prices and timing values.
9. Confirm saved values round-trip correctly.
10. Activate a new approved group after changing placement duration and verify the new duration is used, while an older active group remains unchanged.
11. Open Payments and verify only truthful pre-WEBPAY informational state is shown.
12. Verify psychologist role cannot access any Stage 8 admin route.
13. Check representative 1440/1024/390 rendering for dictionaries/items/settings/payment info page.

Use only synthetic/local values; do not treat them as approved production dictionary content.

### 21. Documentation

Update:
- docs/architecture.md — dictionary lifecycle/usage protection and typed settings write/audit/cache boundary;
- docs/development.md — how to populate local dictionaries and configure settings through UI;
- docs/project-status.md — Stage 8 completed and Stage 9+ pending;
- docs/ui-pages.md — real Stage 8 route wiring while preserving prototype catalogue.

Do not modify SPEC.md, WORKFLOW.md, or AGENTS.md.

## Explicit Out Of Scope

Do not implement:
- WEBPAY config/credentials/provider requests;
- real payment list/detail data;
- payment creation/status/refund;
- payment notifications;
- applications;
- Stage 9 expiration scheduler;
- free/paid extension;
- expiry warning email/jobs;
- public API;
- HMAC integration;
- email/password onboarding;
- psychologist self-edit;
- production deployment.

Do not create active real routes/actions for these future stages.

## Constraints

- Follow WORKFLOW.md and AGENTS.md.
- Reuse accepted Stage 3 views/components.
- Preserve Stage 4–7 auth/admin/group/document behavior.
- Dictionary codes are stable.
- Core dictionary containers cannot be deleted.
- Used dictionary items are deactivated, not physically deleted.
- Settings writes go through SettingService.
- Audit changed business settings.
- Money stored only as integer minor units; never float.
- No payment/provider behavior in Stage 8.
- Tests remain MySQL-only.
- Preserve all 249 prototype variants.
- No Node/npm/Vite or frontend framework.
- No secrets or real production dictionary/personal/payment data.
- Do not alter .ai/task.md.

## Acceptance Criteria

1. Real admin navigation exposes Payments, Dictionaries and Settings.
2. Only active admins can access Stage 8 routes.
3. Admin can create/edit dictionary containers without source changes.
4. Dictionary/container codes are immutable after creation.
5. Core containers education_type/group_format/gender cannot be deleted.
6. Admin can add/edit/reorder dictionary items.
7. Item codes are unique per dictionary and immutable.
8. Admin can deactivate/reactivate items.
9. Used items cannot be physically deleted.
10. Unused items can be deleted after confirmation.
11. Nested dictionary/item IDOR is blocked.
12. Active items appear immediately in real Stage 5/7 forms.
13. Deactivated item disappears from new forms.
14. Existing records retain/display a referenced inactive item.
15. Real Settings page reads all seven values through typed SettingService.
16. Admin can update all seven defined values; arbitrary keys cannot be created.
17. Prices support nullable human BYN input and persist as integer minor units without float.
18. Integer settings and warning<placement validation work.
19. Setting caches are invalidated only after successful commit and reads return fresh values.
20. Changed settings generate minimal setting.updated audit entries with current admin actor.
21. Unchanged values generate no audit entry.
22. Audit failure rolls back settings and does not expose stale/rolled-back cache values.
23. Existing active placement dates remain unchanged after placement duration setting change.
24. Later activation uses the new placement duration.
25. Saving prices creates no payment/provider behavior.
26. Real /admin/payments reuses approved payment-list Blade in truthful pre-WEBPAY mode.
27. Real payment page shows no fake orders/transactions/actions.
28. No real payment detail or payment mutation route exists.
29. Normal payment informational rendering does not query payment tables.
30. No WEBPAY config/credential/provider code is introduced.
31. Stage 4–7 regression remains green.
32. All 31 page groups / 249 prototype variants remain green.
33. Full MySQL suite passes.
34. Pint passes.
35. Larastan passes.
36. composer check-platform-reqs passes.
37. Blade compilation passes.
38. Representative real Stage 8 pages work at 1440/1024/390.
39. Documentation reflects actual Stage 8 state.
40. Final diff is limited to Stage 8 dictionaries/settings/payment-info UI, necessary shared integration, tests/docs, and .ai/report.md.

## Verification Commands

Run and report exact results.

1. Confirm Docker services healthy.
2. Migrate/seed without destructive reset.
3. Verify real admin dictionary create/edit/item/deactivate/reactivate/delete flows.
4. Verify real Stage 5/7 forms react immediately to dictionary changes.
5. Verify settings update, validation, audit and cache behavior.
6. Verify old active group dates are unchanged and later activation uses the new duration.
7. Verify /admin/payments is informational only and performs no payment table query/provider action.
8. Verify psychologist gets 403 for Stage 8 routes.
9. Run:
   - docker compose exec -T php php artisan test
   - docker compose exec -T php ./vendor/bin/pint --test
   - docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress
   - docker compose exec -T php composer check-platform-reqs
   - docker compose exec -T php php artisan view:cache
10. Inspect route list and production route isolation.
11. Inspect audit rows for setting updates.
12. Inspect query counts for dictionary/item/payment-info lists.
13. Inspect representative UI at 1440/1024/390.
14. Inspect git diff/status/staged files.
15. Confirm no secrets, real personal/payment data, screenshots, browser artifacts, or unrelated files are staged.

## Hard Workflow Gate

Before changing files:

- read WORKFLOW.md, AGENTS.md, SPEC.md, docs/project-status.md, docs/ui-pages.md, and this .ai/task.md;
- run git log --oneline -5;
- run git status --short;
- confirm base commit 1f5182039fadcfe53bb737c30748104c2535308c;
- inspect existing dictionary/settings/payment prototype views and SettingService/AuditService;
- do not overwrite unknown local changes.

During implementation:

- stay strictly in Stage 8;
- do not begin Stage 9 lifecycle or Stage 10 applications;
- do not add WEBPAY/provider behavior;
- preserve accepted views/prototypes;
- keep dictionary/settings changes transactional and explicit;
- do not alter .ai/task.md;
- do not change governance/spec files.

Before commit:

- run all required checks;
- perform real browser/admin Stage 8 smoke verification;
- update .ai/report.md with routes/controllers/requests/services, dictionary lifecycle, settings audit/cache behavior, payment-info evidence, tests/runtime checks, facts/assumptions/unknowns;
- inspect full diff and staged files;
- stage only Stage 8 files plus .ai/report.md;
- ensure no runtime/test artifacts are staged.

Completion:

- use Status: done only if all acceptance criteria are satisfied;
- otherwise use partial, blocked, or failed;
- if complete, commit with:

codex: TASK-2026-09-21-06 implement dictionaries settings admin

- do not create an accept commit.
