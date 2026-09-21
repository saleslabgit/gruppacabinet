# Task: TASK-2026-09-21-05

Status: planned
Created from: 2b9ff579d91c69cc2d7a6ecae6438415f93f06e1 (main)

## Title

Stage 7 — Implement real group CRUD, owner workflow, administrator moderation, history, and manual activation without payments

## Goal

Implement the complete Stage 7 internal group workflow from SPEC.md using the accepted Stage 3 Blade views and the Stage 4–6 authentication/access foundations.

This milestone must make groups fully usable inside the cabinet without WEBPAY:

- psychologist creates and owns groups;
- psychologist saves/edits draft or revision groups;
- psychologist submits draft/revision to moderation;
- psychologist sees own group list/detail and status history;
- psychologist can soft-delete permitted own groups;
- administrator lists/searches/filters/sorts groups;
- administrator creates/views/edits groups;
- administrator moderates moderation -> approved/revision/rejected;
- revision and rejection require comments/reasons;
- administrator manually activates approved groups and placement dates are calculated;
- administrator sees immutable public_uuid as “ID группы для gruppa.info” and can copy it;
- administrator can delete abandoned drafts;
- all owner/admin/IDOR/status boundaries are enforced.

Stage 7 deliberately has no payment flow. Even when owner free=false and the group snapshots free=false, the group starts at draft and may proceed through moderation. No gp_payments row is created and awaiting_payment is unreachable through real Stage 7 UI/actions.

## Facts

- Stage 6 is accepted through commit 2b9ff579d91c69cc2d7a6ecae6438415f93f06e1.
- Group model already:
  - generates immutable UUID v4 public_uuid;
  - snapshots current owner gp_users.free into gp_groups.free at creation;
  - derives compatibility accept centrally from status;
  - uses soft deletes.
- GroupStatus enum and GroupStatusTransitionService already enforce and record:
  - awaiting_payment -> draft;
  - draft -> moderation;
  - moderation -> approved/revision/rejected;
  - revision -> moderation;
  - approved -> active;
  - active -> expired;
  - expired -> approved.
- GroupStatusTransitionService locks the group, validates the transition, updates status, and writes gp_group_status_history transactionally.
- Stage 7 must use only the subset reachable in this stage:
  draft -> moderation -> revision/rejected/approved -> active,
  with revision -> moderation.
- active -> expired belongs to Stage 9 scheduler.
- expired -> approved belongs to Stage 9/14 extension flows.
- awaiting_payment belongs to later WEBPAY placement flow and is not reachable in Stage 7.
- SettingService already exposes placementDurationDays().
- Default placement duration seed is 30 days.
- Database timestamps are UTC; display/input conventions use Europe/Minsk.
- Group form fields already exist in approved Stage 3 views:
  title, description, schedule, format_id, meeting_duration_minutes,
  participant_capacity, gender_id, meeting_price.
- meeting_price is stored as integer minor units; float must not be used.
- Dictionaries format/group_format and gender exist as containers, but approved display items may be empty.
- Stage 3 final views already exist for:
  psychologist group list/form/detail,
  admin group list/form/detail,
  shared group data/history/actions.
- Stage 10 applications are not implemented yet.
- Stage 13/14 WEBPAY placement/extension behavior is not implemented yet.

## Product Assumptions

- For Stage 7, both free=true and free=false psychologists create groups directly in draft.
  The group free snapshot remains correct historical data; it does not gate this temporary no-payment workflow.
- No real route/action may transition a Stage 7 group into awaiting_payment.
- No real Stage 7 route/action may create gp_payments.
- A psychologist group owner is immutable after creation.
- Administrator chooses the owner when creating a group; owner reassignment of an existing group is not implemented in Stage 7 because it would change ownership semantics and conflict with historical tariff snapshot meaning.
- Admin create owner options are non-admin, non-deleted, approved psychologists. Disabled psychologists may remain visible only if needed to edit an existing association, but new groups should be created for currently enabled approved psychologists.
- “Abandoned draft” threshold is not specified by a business setting. For the Stage 7 admin quick filter, use a technical constant/configuration of 30 days. This is only an administrative cleanup threshold and is not placement duration. Document it clearly; do not add it to gp_settings in this stage.
- Administrator manual deletion in Stage 7 is limited to abandoned draft groups matching that threshold. Do not add general deletion of active/moderated/published groups.
- Rejection reason minimum length: use 10 characters as a technical validation minimum because SPEC requires a minimum but does not specify the exact number.
- Revision comment is required and non-blank; use the same 10-character minimum for consistent moderator feedback quality.
- Psychologist may soft-delete only draft or rejected own groups with no successful non-refunded payment. Stage 7 itself creates no payments, but preserve this safety check for existing/historical rows.

## Scope

### 1. Real psychologist group routes

Under existing account + role:psychologist protection, add stable real routes for:

- list/home at existing /
- create draft action
- group detail
- group edit form
- group update/save
- submit to moderation
- soft delete

Recommended route shape:

POST /groups
GET /groups/{group}
GET /groups/{group}/edit
PUT /groups/{group}
POST /groups/{group}/submit
DELETE /groups/{group}

Use route names under psychologist.groups.* or another clear existing convention.

“Добавить группу” on the real root should perform a CSRF-protected POST that creates a draft and redirects to its real edit form. Do not introduce a GET side effect.

No psychologist route accepts owner_id.

### 2. Create draft

Creating a psychologist group must:

- use authenticated psychologist as owner_id;
- create status=draft;
- let Group model generate public_uuid;
- let Group model snapshot current owner free value;
- never create a payment;
- never create awaiting_payment;
- create an initial gp_group_status_history entry:
  from_status = null,
  to_status = draft,
  actor_id = psychologist id,
  actor_type = user,
  comment = null.

Create group + initial history atomically.

The blank draft may have nullable form fields until saved.

After creation, redirect to edit.

### 3. Real psychologist group list

Connect approved psychologist/groups/index Blade to real owned groups.

Requirements:

- only current owner groups;
- no soft-deleted groups;
- newest first with deterministic ID tie-break;
- pagination, 20 per page;
- eager-load only needed dictionary relations;
- no N+1;
- show real status/free snapshot/dates/format;
- Stage 10 application counters must be truthful without querying applications:
  render unavailable/zero state and do not link to unimplemented application routes;
- real action URLs only;
- no prototype links;
- “Добавить группу” becomes enabled.

Do not query gp_payments or gp_group_applications in the Stage 7 real list.

### 4. Psychologist detail

Connect approved psychologist group detail to real owned group.

Show:

- all group fields;
- lifecycle status;
- disabled flag if present;
- historical free snapshot;
- created/published/expires dates;
- moderation/rejection current message where applicable;
- real status history;
- only actions allowed for current state.

The psychologist must never see or edit public_uuid in a writable field.
It may be omitted from psychologist UI entirely.

Applications section remains unavailable/empty until Stage 10 and must not link to prototype/future routes.

Payment section/actions must not appear in real Stage 7 psychologist UI.

### 5. Psychologist edit/save

Psychologist may edit only own groups in:

- draft;
- revision.

Editing must be blocked server-side in:

- moderation;
- approved;
- active;
- expired;
- awaiting_payment;
- rejected.

Rejected groups are view/delete only; no resubmission path.

Use policy + explicit status checks.

Editable fields are only the group questionnaire fields:
- title;
- description;
- schedule;
- format_id;
- meeting_duration_minutes;
- participant_capacity;
- gender_id;
- meeting_price.

Request must not write:
- owner_id;
- status;
- accept;
- free;
- public_uuid;
- disabled;
- published_at;
- expires_at;
- placement_days;
- expiry_warning_sent_at;
- moderator_comment;
- rejection_reason;
- deleted_at.

### 6. Group validation

Use Form Request validation.

At minimum:

- title required string max 255;
- description required string with sensible text limit matching DB text;
- schedule required string with sensible text limit;
- format_id required and must belong to group_format dictionary;
- gender_id required and must belong to gender dictionary;
- meeting_duration_minutes required positive integer;
- participant_capacity required positive integer;
- meeting_price required non-negative money input.

For dictionary selection:

- new forms offer active items only;
- edit keeps current inactive referenced item available so existing data is not lost;
- do not invent dictionary item values.

Money:

- accept normal BYN human input such as 35,00 or 35.00;
- convert to integer minor units without float;
- reject malformed values or >2 decimal places;
- display through existing money conventions;
- never store decimal/float in gp_groups.meeting_price.

### 7. Save vs submit

The real approved group form must support separate actions:

- Save draft/changes: update editable fields, keep status unchanged.
- Submit to moderation:
  - validate the complete form;
  - persist editable data;
  - transition via GroupStatusTransitionService:
    draft -> moderation, or revision -> moderation;
  - actor is current psychologist;
  - actor_type user.

Do not assign status directly.

A failed validation or failed transition must not partially save a state change/history row.

### 8. Revision flow

Administrator moderation -> revision requires moderator_comment.

Requirements:

- minimum 10 characters after trimming;
- store latest moderator_comment on group for current-state presentation;
- transition moderation -> revision via GroupStatusTransitionService;
- pass comment to transition service so a history row preserves the comment;
- actor = current admin;
- actor_type = user.

Psychologist in revision:

- sees current comment prominently;
- sees complete historical comments/history;
- may edit group;
- may save changes without status transition;
- may resubmit revision -> moderation through transition service.

Previous history comments must never be overwritten/deleted.

### 9. Rejection flow

Administrator moderation -> rejected requires rejection_reason.

Requirements:

- minimum 10 characters after trimming;
- store current rejection_reason on group;
- transition via GroupStatusTransitionService with comment/history;
- actor current admin;
- rejected group cannot be edited/resubmitted by psychologist in Stage 7;
- psychologist can view reason/history and may delete group if deletion rules allow.

Do not introduce refund/payment behavior in real Stage 7 UI, even when group.free=false.

### 10. Approve flow

Administrator moderation -> approved:

- explicit confirmed action;
- use GroupStatusTransitionService;
- actor current admin;
- no payment prerequisite in Stage 7;
- no date placement calculation yet;
- published_at/expires_at remain null until activation.

Invalid or repeated action must be safely rejected without duplicate history.

### 11. Manual activation

Administrator approved -> active:

- explicit confirmation;
- before action, admin detail visibly shows “Интеграция с gruppa.info”,
  “ID группы для gruppa.info”, immutable public_uuid, copy action, and reminder to save it on the public site;
- use current SettingService::placementDurationDays();
- within one coordinated transaction:
  - lock current group;
  - require status approved;
  - published_at = current UTC time;
  - placement_days = current configured duration;
  - expires_at = published_at + placement_days;
  - expiry_warning_sent_at = null;
  - transition approved -> active through domain transition service with admin actor.

Do not calculate from approval time.
Do not create/update a public-site record automatically.
Do not store a second public-site ID.
Do not call external services.

A duplicate activation attempt must fail and must not change dates/history twice.

### 12. Real status history

Replace fixture history on real pages with gp_group_status_history.

Show chronological history with:

- from/to human labels;
- actor identity where actor exists;
- actor type/system where relevant;
- comment/reason when present;
- created_at formatted through project date/time helpers.

Initial draft history row must appear.

Revision/rejection history must preserve each comment independently.

Avoid N+1 when loading actor/history.

Prototype history stays synthetic.

### 13. Psychologist delete

Allow psychologist soft delete only when:

- current user owns group;
- status is draft or rejected;
- group.disabled=false if existing UX requires it;
- no succeeded payment exists that has not been refunded.

Even though Stage 7 creates no payments, preserve historical payment safety by querying only for deletion authorization when needed.

Use explicit confirmation.
Soft delete only.
Do not physically delete status history/payments/applications.
After deletion, list no longer shows group.
IDOR must be impossible.

### 14. Group policy / IDOR

Add a GroupPolicy or equivalent clear policy.

At minimum cover:

- owner view;
- owner update only draft/revision;
- owner submit only draft/revision;
- owner delete only permitted states/conditions;
- admin view/manage;
- admin moderation;
- admin edit;
- admin abandoned-delete.

Psychologist A must receive 404 or 403 without data leakage when guessing psychologist B group ID.
Prefer owner-scoped lookup for psychologist routes so foreign group IDs return 404.

Administrator routes may use normal group binding plus policy.

### 15. Admin real group routes

Under account + role:admin add real routes for:

- group index;
- create/store;
- show;
- edit/update;
- approve;
- revision;
- reject;
- activate;
- delete abandoned draft.

Recommended prefix /admin/groups and names admin.groups.*.

Do not add payment/refund/extension/application routes.

### 16. Admin group list

Connect approved admin/groups/index to real data.

Show real:

- internal ID;
- title;
- psychologist;
- status;
- free/paid historical snapshot;
- created_at;
- published_at;
- expires_at.

Implement:

- search by numeric ID, title, psychologist name/email;
- status filter;
- free/paid filter;
- sorting by created_at, published_at, expires_at with safe allowlist;
- pagination 20;
- query preservation;
- no N+1, eager load owner and needed dictionary values.

Real Stage 7 UI must not offer successful-payment filter because payment flow is not connected.
Prototype retains its existing payment filter state.

Quick filters in real UI:

- approved — awaiting manual publication;
- abandoned — draft older than 30 days;
- expired may be visible only as ordinary status if historical data exists, but Stage 9 owns expiry/unpublish workflow.

Do not query gp_payments for normal Stage 7 list/filter behavior.

### 17. Admin create/edit

Administrator can create a group for an eligible psychologist and edit group content regardless lifecycle status.

Create:

- choose owner from real eligible psychologists;
- create status=draft;
- Group model snapshots owner free and generates public_uuid;
- create initial null -> draft history with admin actor;
- no payment;
- redirect to edit/detail.

Admin edit:

- may edit questionnaire fields regardless group status;
- owner cannot be changed after creation;
- public_uuid cannot be changed;
- free snapshot cannot be changed;
- lifecycle fields cannot be edited directly.

Show the existing approved warning that published group changes need manual synchronization to the public catalogue.

### 18. Admin detail/moderation

Connect approved admin/groups/show to real data.

Show:

- psychologist with link to real Stage 5 psychologist detail;
- group content/status/history;
- public_uuid integration block;
- real allowed moderation actions by status;
- activation action only when approved;
- admin edit action;
- abandoned-delete only when allowed.

Real Stage 7 page must not show fabricated payment data, WEBPAY refund warning, order numbers, or payment links.

For free=false groups, historical tariff can be displayed but it must not imply that payment is required in this stage.

### 19. Abandoned draft deletion

Use a technical config value such as GROUP_ABANDONED_DRAFT_DAYS=30 or equivalent project configuration.

Add the value to .env.example if using env.

Admin quick filter selects:

- status=draft;
- created_at <= now - threshold.

Admin delete action is allowed only for a draft meeting the same threshold.

Soft delete only.
Do not delete history/related records.
Do not broaden this to arbitrary group deletion.

### 20. Applications remain Stage 10

No real application list/detail/counters in Stage 7.

On group list/detail:

- do not query gp_group_applications;
- render counters as zero/unavailable in a truthful way;
- do not provide active production links to application pages.

Prototype application/counter variants remain unchanged.

### 21. Payments remain later

Hard gate:

- no gp_payments create/update/delete in Stage 7;
- no Payment service/controller integration;
- no WEBPAY routes/requests;
- no placement page in real workflow;
- no payment-success requirement for free=false;
- no transition into awaiting_payment;
- no extension behavior.

Add tests that a free=false psychologist creates a group in draft and can complete Stage 7 moderation/activation with zero gp_payments rows.

### 22. Preserve immutable public_uuid

Real forms must never contain editable public_uuid.

Tests must prove:

- UUID generated at creation;
- submitted public_uuid value is ignored/rejected;
- psychologist cannot mutate it;
- admin edit cannot mutate it;
- repeated save/status changes keep same UUID;
- admin detail copy control uses exact UUID.

Reuse existing copy JS/component behavior.

### 23. Transactions / workflow service

Prefer a small explicit GroupWorkflow service/orchestrator that composes:

- Group creation + initial history;
- GroupStatusTransitionService;
- moderator current fields;
- activation date changes;
- deletion eligibility where appropriate.

Do not duplicate status writes in controllers.
Do not create a generic workflow framework.

Where multiple DB effects belong to one action, coordinate them transactionally with row locking.

Be careful not to nest conflicting lock/query patterns around GroupStatusTransitionService; refactor the existing transition service only if necessary and preserve its accepted behavior/tests.

### 24. Blade integration

Reuse accepted views, not parallel templates.

Adapt existing views/components to support prototype and real modes:

- psychologist/groups/index;
- psychologist/groups/show;
- shared/group-form;
- shared/group-data;
- shared/group-summary;
- shared/group-history;
- psychologist/groups/_actions;
- shared/group-delete;
- admin/groups/index;
- admin/groups/show;
- admin/groups/form.

Real forms must use:
- real methods/actions;
- CSRF;
- server validation;
- old input;
- real dictionaries;
- real confirmation forms.

Prototype remains no-op and preserves all variants.

Do not redesign Stage 3.

### 25. Navigation

Psychologist navigation remains:
- Мои группы;
- Мои данные;
- Выход.

Admin navigation adds real “Группы” alongside:
- Главная;
- Психологи;
- Группы;
- Выход.

Do not add applications/payments/dictionaries/settings real navigation yet.

### 26. Tests

All tests use MySQL.

Add focused coverage for at least:

#### Psychologist create/list/detail
- create draft uses authenticated owner;
- free=true snapshot;
- free=false snapshot but status still draft and no payments;
- initial history row actor;
- unique immutable public_uuid;
- list only own non-deleted groups;
- pagination/no N+1;
- detail only own group;
- foreign owner IDOR.

#### Form/update
- draft edit succeeds;
- revision edit succeeds;
- moderation/approved/active/expired edit blocked;
- protected fields cannot be mass-written;
- owner/public_uuid/free/status/dates unchanged;
- dictionary active/inactive behavior;
- money input converted to minor units without float;
- malformed money rejected.

#### Submit/moderation
- draft -> moderation with psychologist actor history;
- revision -> moderation with psychologist actor history;
- invalid transition rejected;
- no direct status writes.

#### Admin moderation
- moderation -> revision requires 10-char comment and history comment;
- multiple revision cycles preserve earlier comments;
- moderation -> rejected requires 10-char reason/history;
- moderation -> approved succeeds;
- duplicate/invalid moderation actions no partial writes/history.

#### Activation
- approved -> active;
- placement duration read from SettingService;
- published_at UTC now;
- placement_days snapshot;
- expires_at exact plus duration;
- expiry_warning_sent_at reset;
- history admin actor;
- duplicate activation rejected without date/history duplication;
- public_uuid visible and unchanged.

#### Delete
- psychologist draft/rejected allowed when safe;
- other statuses blocked;
- succeeded unrefunded payment blocks deletion if such historical row exists;
- admin abandoned draft threshold enforced;
- soft delete preserves history/related rows.

#### Admin list
- search ID/title/owner;
- status/free filters;
- sort allowlist;
- pagination/query preservation;
- approved quick filter;
- abandoned 30-day quick filter;
- no admin/payment/application N+1 or per-row queries.

#### Authorization
- psychologist cannot use admin group routes;
- admin can view/edit groups;
- one psychologist cannot access another’s group;
- disabled/revoked user middleware regression.

#### Hard no-payment gate
- no gp_payments row for paid owner create/submit/moderate/activate;
- no real route into awaiting_payment;
- real group HTML contains no active WEBPAY/payment action.

#### Regression
- Stage 4–6 suites remain green;
- Stage 5 admin psychologist/documents remain green;
- 31 prototype groups / 249 variants remain;
- production excludes prototype/foundation routes.

### 27. Manual/runtime verification

Through real Docker HTTP/browser flow verify:

Psychologist:
1. login;
2. Add group creates draft;
3. fill form and save;
4. submit to moderation;
5. cannot edit while moderation.

Admin:
6. open real Groups list;
7. open moderation group;
8. send to revision with comment;
9. psychologist sees comment/history, edits and resubmits;
10. admin approves;
11. admin sees/copies public_uuid reminder;
12. admin activates;
13. dates appear correctly.

Also verify rejected path and abandoned-draft deletion.

Repeat the create/moderate/activate path with a free=false psychologist and confirm no payment screen/row is created.

Check representative 1440/1024/390 rendering for list/form/detail/admin moderation and no horizontal overflow.

### 28. Documentation

Update actual-state docs:

- docs/architecture.md — group policy/workflow/status-history/activation boundary;
- docs/development.md — manual Stage 7 workflow and abandoned threshold;
- docs/project-status.md — Stage 7 complete, Stage 8+ pending;
- docs/ui-pages.md — real group route wiring while retaining prototype catalogue.

Add env/config documentation for abandoned draft threshold if introduced.

Do not modify SPEC.md, WORKFLOW.md, or AGENTS.md.

## Explicit Out Of Scope

Do not implement:

- WEBPAY or any payment creation/check/return/notify;
- awaiting_payment real flow;
- placement/extension payment pages in production flow;
- scheduler active -> expired;
- expiry warning jobs/email;
- free or paid extension;
- participant application CRUD/counters;
- public API;
- HMAC integration;
- dictionary CRUD;
- settings CRUD;
- psychologist/profile editing;
- email/password onboarding;
- production deployment.

Do not create active real links to these future-stage features.

## Acceptance Criteria

1. Psychologist can create a draft group from real cabinet.
2. free=false owner still gets draft, with free=false snapshot and zero payments.
3. Group public_uuid is generated once and immutable.
4. Initial draft history exists with correct actor.
5. Psychologist list contains only own groups.
6. Psychologist can view only own groups.
7. Draft/revision can be edited; other lifecycle states cannot.
8. Save and submit are separate real actions.
9. Draft -> moderation uses domain transition service and history.
10. Revision -> moderation uses domain transition service and history.
11. Admin can list/search/filter/sort/paginate real groups without N+1.
12. Real admin list has approved and abandoned quick filters.
13. Real admin list does not expose payment filter/workflow.
14. Admin can create and edit group content using approved Blade form.
15. Existing group owner/free/public_uuid/lifecycle fields cannot be mass changed through edit.
16. moderation -> revision requires valid comment and stores full historical comment.
17. moderation -> rejected requires valid reason and stores history.
18. moderation -> approved works through domain service.
19. Invalid/repeated transitions produce no partial status/history.
20. approved -> active uses current placement duration and calculates dates from activation time.
21. Activation resets expiry warning marker.
22. Admin detail visibly contains immutable “ID группы для gruppa.info” and copy control before activation.
23. No external/public-site call is made on activation.
24. Psychologist deletion obeys draft/rejected/payment safety and is soft delete.
25. Admin deletion only removes abandoned drafts and is soft delete.
26. Group status history renders real chronological actors/comments.
27. Group dictionary fields use real active items and preserve current inactive referenced items.
28. meeting_price is validated/converted to integer minor units without float.
29. IDOR between psychologists is blocked.
30. Psychologist cannot access admin group routes.
31. No Stage 7 real route creates gp_payments or transitions to awaiting_payment.
32. No real group flow links to WEBPAY/applications/extensions.
33. Stage 4–6 access/profile/document behavior remains green.
34. All 31/249 prototype variants remain green.
35. Full MySQL suite passes.
36. Pint passes.
37. Larastan passes.
38. composer check-platform-reqs passes.
39. Blade compilation passes.
40. Real representative pages work at 1440/1024/390.
41. Documentation reflects actual Stage 7 behavior.
42. Final diff is limited to Stage 7 group CRUD/moderation/history/activation, necessary shared UI integration/config, tests/docs, and .ai/report.md.

## Verification Commands

Run and report exact results.

1. Confirm Docker services healthy.
2. Migrate/seed without destructive reset.
3. Verify real psychologist workflow through Docker HTTP/browser:
   create draft -> save -> submit.
4. Verify admin moderation:
   revision -> psychologist resubmit -> approve -> activate.
5. Verify rejection path.
6. Verify paid-owner free=false path creates no payment and no payment redirect.
7. Verify public_uuid copy and immutability.
8. Verify IDOR with second psychologist.
9. Verify admin abandoned filter/delete with records just inside/outside 30-day cutoff.
10. Verify no real group route queries/creates payment or application data where out of scope.
11. Run:
   docker compose exec -T php php artisan test
   docker compose exec -T php ./vendor/bin/pint --test
   docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress
   docker compose exec -T php composer check-platform-reqs
   docker compose exec -T php php artisan view:cache
12. Inspect route list and production isolation.
13. Inspect gp_group_status_history after full flow.
14. Inspect gp_payments remains unchanged/zero for Stage 7 smoke flow.
15. Inspect representative UI at 1440/1024/390.
16. Inspect git diff/status/staged files.
17. Confirm no secrets, real personal data, screenshots, browser artifacts, or unrelated files are staged.

## Hard Workflow Gate

Before changing files:

- read WORKFLOW.md, AGENTS.md, SPEC.md, docs/project-status.md, docs/ui-pages.md, and this .ai/task.md;
- run git log --oneline -5;
- run git status --short;
- confirm base commit 2b9ff579d91c69cc2d7a6ecae6438415f93f06e1;
- inspect existing Group model/enums/transition service and all Stage 3 group views;
- do not overwrite unknown local changes.

During implementation:

- stay strictly in Stage 7;
- never create payment/WEBPAY behavior;
- never make awaiting_payment reachable;
- use GroupStatusTransitionService for status changes;
- use owner-scoped psychologist access;
- preserve immutable public_uuid and free snapshot;
- preserve prototype fixtures/no-op behavior;
- preserve accepted design;
- do not alter .ai/task.md;
- do not change governance/spec files.

Before commit:

- run all required checks;
- perform full real psychologist/admin workflow smoke verification;
- update .ai/report.md with routes, policies, requests, workflow service, history/activation rules, no-payment evidence, tests/runtime checks, facts/assumptions/unknowns;
- inspect full diff and staged files;
- stage only Stage 7 files plus .ai/report.md;
- ensure no runtime/test artifacts are staged.

Completion:

- use Status: done only if all acceptance criteria are satisfied;
- otherwise use partial, blocked, or failed;
- if complete, commit with:

codex: TASK-2026-09-21-05 implement group CRUD moderation workflow

- do not create an accept commit.
