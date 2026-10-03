# Task: TASK-2026-10-03-01

Status: planned
Created from: 5250b3d24114b438381774e86c85c5b087b73713 (main)

## Title

Block manual activation of renewed approved groups

## Goal

Correct one lifecycle gap found during review of:

`5250b3d24114b438381774e86c85c5b087b73713`
`codex: TASK-2026-10-03-01 post-manual-testing improvements`

The large manual-testing improvement batch is otherwise accepted.

The remaining bug affects an expired group that has been renewed:

`expired -> approved`

When `public_site_resource_id` is missing, the current `GroupPolicy::activate()`
still permits the old manual administrator action “Отметить активной”.

That allows `GroupWorkflow::activate()` to:

- set new `published_at`;
- start a new `expires_at`;
- mark publication as published;
- transition `approved -> active`;

even though no MODX Resource exists and no remote publication was confirmed.

This violates the accepted renewal rule:

**after expired renewal, a new placement period may start only after successful
publication confirmation.**

Close this bypass without changing the rest of TASK-2026-10-03-01.

## Authoritative Behavior

### Initial moderation approval

Initial:

`moderation -> approved`

remains the existing manual-publication flow.

Administrator may still use “Отметить активной” after the external initial
publication has actually been performed.

Do not remove or redesign initial manual activation.

### Expired renewal

Current renewal identity is defined by the current/latest status history:

`expired -> approved`

For such a group, manual `activate` must be forbidden **regardless of whether
`public_site_resource_id` is present or missing**.

Cases:

#### Renewal with Resource ID

- automatic publication/retry flow remains authoritative;
- successful current publication job performs:
  `approved -> active`;
- new placement clock starts only on that remote success.

#### Renewal without Resource ID

- group remains `approved`;
- no new placement dates start;
- manual `activate` is forbidden;
- admin UI explains that MODX synchronization/publication must be restored;
- after a valid Resource ID exists, existing renewal retry/publication flow is
  used;
- only successful remote publication starts the new placement period.

No fake “published” marker is allowed.

## Required Correction

### 1. Policy

Change `GroupPolicy::activate()` so any current renewal-approved group is denied:

conceptually:

`status === approved && renewalHistoryId() === null`

for manual initial activation.

Do **not** condition this denial on Resource ID presence.

Preserve all other current authorization checks.

### 2. Workflow defense in depth

Do not rely only on a Blade button or route policy state captured before a row lock.

Inside `GroupWorkflow::activate()`, after row-locking the current group, recheck
that it is an initial/manual activation candidate and not a current renewal
`expired -> approved`.

If it is a renewal-approved group, reject safely before mutating:

- `published_at`;
- `expires_at`;
- `placement_days`;
- publication desired/status/revision;
- group status/history.

Use the existing authorization/domain exception style consistently.

This protects against:

- stale authorization models;
- direct service calls;
- concurrent state/history change before the locked mutation.

### 3. Admin UI

For renewal-approved groups, never render “Отметить активной”.

This includes renewal with missing Resource ID.

Keep the existing truthful renewal notice:

- with Resource ID: publication/retry is queued/available;
- without Resource ID: synchronization/publication must be restored manually.

Do not introduce a misleading activation button through prototype/shared paths.

### 4. Automatic renewal publication

Do not change the accepted implementation:

- free expired renewal -> approved;
- paid expired renewal -> approved;
- no new placement dates before remote success;
- Resource ID present -> publication scheduler/retry;
- successful publication -> fresh dates + active;
- missing Resource ID -> approved/manual recovery state;
- initial moderation approval never auto-publishes.

### 5. Tests

Add explicit regression coverage.

At minimum:

#### Missing Resource ID renewal

Create:

- expired group;
- renewal transition `expired -> approved`;
- `public_site_resource_id = null`.

Prove:

- admin policy cannot activate;
- POST `/admin/groups/{id}/activate` is forbidden;
- direct/service activation is rejected under the locked current state;
- status stays `approved`;
- `published_at`, `expires_at`, `placement_days` stay unchanged;
- no new publication revision/desired/status pretending success;
- no extra status history is created;
- admin detail does not render “Отметить активной”;
- truthful missing-resource renewal notice remains.

#### Resource ID renewal

Prove manual activate remains forbidden there too and only the publication job
can activate it.

#### Initial approval

Prove normal `moderation -> approved` initial group:

- still permits existing manual admin activation;
- existing initial publication semantics/regression remain intact.

#### Concurrency/stale safety

Add a focused test where an initially activatable approved model becomes a
renewal-approved state before the locked workflow mutation.

The locked workflow must reject it without starting dates.

Do not use wall-clock timing.

## Preserve Accepted TASK-2026-10-03-01 Behavior

Do not regress or redesign:

- Telegram feedback;
- admin Telegram notifications;
- moderation emails;
- provider-neutral payment UI;
- terminal failed/cancelled payment UX;
- psychologist group UI corrections;
- direct submit-to-moderation;
- application hide/admin retention;
- group deletion retention;
- admin group applications;
- HTTPS hardening;
- mobile admin header menu;
- admin withdraw/restore;
- automatic expired renewal publication;
- initial manual publication;
- payment trust/idempotency.

Do not implement payment deletion.

## Out Of Scope

Do NOT:

- add migrations;
- change MODX API contract;
- change renewal duration;
- auto-create a missing MODX Resource during activation;
- auto-publish initial approval;
- alter payment confirmation rules;
- add packages;
- read/change/commit production private files including `.env_save`;
- make real Telegram/mail/MODX/WEBPAY calls;
- run `migrate:fresh`;
- create an `accept:` commit.

## Acceptance Criteria

1. Any current `expired -> approved` renewal is ineligible for manual admin
   activation, regardless of Resource ID.
2. Missing-Resource renewal cannot start a new placement clock manually.
3. Workflow rechecks the current locked renewal identity before mutating dates or
   status.
4. Initial `moderation -> approved` manual activation still works exactly as
   before.
5. Renewal auto-publication/retry remains the only path that starts a new period
   for synchronized renewed groups.
6. Admin UI never shows “Отметить активной” for a renewal-approved group.
7. Full affected and full regression suites pass.

## Checks

Run and report exact results for:

1. new focused renewal/manual-activation tests;
2. full `GroupPublicationTest`;
3. full `GroupWorkflowTest`;
4. full `GroupLifecycleTest`;
5. group policy/admin UI tests;
6. `CabinetImprovementsUiTest`;
7. payment renewal regression (`WebpayTest`, `WebpayConcurrencyTest`,
   `PaymentEraGroupsTest`);
8. full MySQL suite;
9. Pint;
10. PHPStan;
11. composer check-platform-reqs;
12. composer validate --no-check-publish;
13. artisan view:cache;
14. artisan route:list;
15. artisan schedule:list;
16. `node --check application/public/ui.js` if Node is available;
17. `git diff --check`;
18. final staged/secret/artifact review.

No real external calls.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this corrective planner commit and parent is
  `5250b3d24114b438381774e86c85c5b087b73713`;
- read WORKFLOW.md, AGENTS.md, this task and current report;
- inspect:
  - `GroupPolicy::activate()`;
  - `GroupWorkflow::activate()`;
  - `Group::renewalHistoryId()`;
  - admin group show Blade;
  - renewal publication tests;
- verify clean/known local tree;
- do not touch unknown local changes.

During implementation:

- work only on this activation bypass;
- do not edit `.ai/task.md`;
- keep renewal auto-publication and initial manual activation semantics intact;
- add locked defense in depth;
- avoid unrelated refactors/UI/docs cleanup.

Before commit:

- run all required checks;
- inspect full diff/staged files;
- verify no secrets/private env/production data/logs/cache/vendor/temp artifacts;
- update `.ai/report.md` factually;
- explicitly state no real external calls were made.

If complete, commit with:

`codex: TASK-2026-10-03-01 block renewal manual activation`

Do not create an accept commit.
