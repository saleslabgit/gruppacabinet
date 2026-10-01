# Task: TASK-2026-10-01-04

Status: planned
Created from: 344d6a6ed5d372d6852c54ea7e8a1676861f40bb (accepted main)

## Title

Polish group form, add secondary-currency price, pause groups, and automate MODX publication lifecycle

## Goal

Complete one user-visible lifecycle milestone:

- simplify the full-description editor controls and remove unwanted helper copy;
- add the secondary-currency meeting-price field to Cabinet and MODX mapping;
- let psychologist owners and administrators delete groups under explicit local semantics;
- let a psychologist pause an already published/active group and later resume it;
- pausing/deleting/expiration must remove the existing MODX Resource from publication without deleting it;
- psychologist resume must republish that same MODX Resource automatically, then reactivate the local group;
- preserve the existing initial moderation → manual MODX publication → admin activation flow.

This task includes Cabinet code/tests/docs. The external MODX plugin is maintained outside this repository. Implement against the fixed publication-control endpoint contract below using HTTP fakes only.

## Facts

- Accepted base commit is `344d6a6ed5d372d6852c54ea7e8a1676861f40bb`.
- Previous planner iteration for this task is `fb108792cdfb8902342b6f0ab79afeff27bdaad8`; no Codex implementation for TASK-2026-10-01-04 has been accepted yet.
- TASK-2026-10-01-03 implemented outbound Cabinet → MODX content sync.
- Current MODX content endpoint creates/updates Resources but deliberately never changes publication state.
- Current initial publication flow is:
  - psychologist submits;
  - admin approves;
  - Cabinet creates/updates an **unpublished** MODX Resource;
  - admin manually publishes it in MODX;
  - admin clicks Cabinet activation;
  - local status becomes `active` and placement dates run.
- Therefore `active` is the Cabinet state that means moderation has passed and an administrator has confirmed publication.
- Current local expiration runs every minute through `groups:expire`: active groups with `expires_at <= now()` atomically transition to `expired` with history; currently no MODX publication change occurs.
- Current expired-group UI asks an administrator to unpublish manually.
- Current psychologist delete policy allows only draft/rejected groups and current delete policy blocks successful unrefunded payments.
- Current administrator delete soft-deletes and preserves history.
- Existing content sync is asynchronous, database-queue based, after-commit and uses per-group overlap key `modx-group:<groupId>`.
- Existing remote Resource identity is immutable `public_site_resource_id`.
- Existing required `meeting_price` is the primary BYN price.
- Verified MODX TV `price_usd` is TV id 81, type text; legacy content may look like `2500 RUB, 30 USD`.
- Earlier integration intentionally left `price_usd` unmanaged because Cabinet had no field. This request supersedes that decision.
- Current rich editor toolbar includes Paragraph, H2, H3, Bold, Italic, UL, OL, Blockquote, Link and Remove formatting.
- Existing stored sanitized HTML may contain p/h2/h3/blockquote/a and must remain safely readable.

## Product decisions

### Secondary-currency price

Add nullable:

```text
gp_groups.meeting_price_currency string(255)
```

UI label exactly:

```text
Стоимость встречи (В валюте)
```

Helper exactly:

```text
Цена одной встречи с указанием валюты
```

Semantics:

- optional plain text;
- trim input;
- max 255;
- whitespace-only normalizes to null/empty;
- examples: `2500 RUB`, `30 USD`, `2500 RUB, 30 USD`;
- do not parse amounts/currency codes;
- show on read-only detail only when nonempty;
- primary BYN `meeting_price` remains required and unchanged;
- nonempty value maps to MODX TV `price_usd`;
- null/empty omits `price_usd` from outbound content payload so an existing legacy/manual MODX value is preserved;
- this field is not sync-readiness-required.

### Delete semantics

Allow delete for psychologist owner and administrator for any visible non-deleted lifecycle status, including `paused`, subject only to normal account/ownership authorization.

Remove:

- lifecycle-status delete restrictions;
- successful-unrefunded-payment delete blocker.

Deletion does not refund/cancel/alter payments.

Local behavior:

- psychologist delete always sets `psychologist_deleted_at`; do not soft-delete the row from admin visibility;
- administrator delete remains normal soft delete;
- never hard-delete.

Remote behavior:

- no remote ID → no publication request;
- remote ID exists → desired remote state becomes unpublished;
- never delete the MODX Resource;
- local delete commits independently of MODX availability.

### Expiration semantics

Keep current local expiration:

```text
active + expires_at <= now() -> expired
```

After commit, if a remote Resource exists, desired remote state becomes unpublished.

MODX/queue failure never rolls back local `expired`.

Existing extension/republish flow remains:

```text
expired -> approved
admin manually publishes existing MODX Resource
admin marks active
```

No automatic publish is added to expired renewal.

### Pause semantics

Add a new lifecycle status:

```text
paused
```

Only the non-admin psychologist owner can request pause/resume.

Pause is available only when:

- group status is exactly `active`;
- group is not disabled;
- `public_site_resource_id` exists;
- `expires_at` exists and is still in the future.

This enforces the product rule: a group can be paused only after moderation has passed and the administrator has published/activated it.

Pause action:

```text
active -> paused
```

- record `paused_at = now()`;
- local transition/history commits immediately;
- desired remote publication state becomes unpublished;
- queue publication-control job after commit;
- local pause does not wait for MODX.

While paused:

- group is not considered active;
- `groups:expire` must ignore it;
- placement time is frozen;
- expiry warning mail/scheduling must not treat it as active;
- participant application acceptance must not treat it as active;
- extension action is not offered/allowed;
- content/admin visibility/history remain intact;
- delete remains allowed.

Resume is available only when:

- non-admin owner;
- status exactly `paused`;
- group not disabled;
- remote Resource ID still exists;
- `paused_at` and `expires_at` are valid.

Resume action does **not** change local status to active immediately.

Instead:

1. desired remote publication state becomes published;
2. publication job is queued after commit;
3. group remains `paused` while publish is pending/retrying;
4. after successful remote publish for the current publication revision:
   - add exact pause duration `now - paused_at` to `expires_at`;
   - clear `paused_at`;
   - clear/reset expiry-warning marker as appropriate;
   - transition `paused -> active`;
   - write normal status history;
5. if remote publish fails, group remains paused and placement time stays frozen.

This means paid/free placement time does not burn while a group is paused.

Repeated pause/resume cycles must preserve remaining placement time exactly.

If a newer local publication intent (for example delete while resume is in flight) appears, the older remote result must not reactivate or overwrite newer state.

### Initial activation remains manual

Do not change the initial moderation/publication contract:

```text
moderation -> approved
Cabinet sync creates unpublished MODX Resource
admin manually publishes MODX Resource
admin clicks "Отметить активной"
approved -> active
```

Admin activation should record the local expectation that the remote Resource is published, but it must not make a publish HTTP request.

Psychologist pause/resume is the only new automatic publish/unpublish toggle for a living placement.

## External MODX prerequisite / fixed contract

The previous planner's unpublish-only endpoint contract is superseded before implementation.

External `GruppaCabinetApi` must expose one idempotent publication-control endpoint:

```text
POST /api/v1/cabinet/resources/publication
scope: cabinet.sync
```

Request body:

```json
{
  "resource_id": 123,
  "published": false
}
```

or:

```json
{
  "resource_id": 123,
  "published": true
}
```

Cabinet sends:

- Accept JSON;
- Content-Type JSON;
- existing Bearer token;
- exact caller-supplied `Idempotency-Key`.

Endpoint must:

1. validate positive integer `resource_id`;
2. validate boolean `published`;
3. load Resource;
4. require Cabinet boundary:
   - parent = 3;
   - template = 8;
   - context = web;
5. never delete Resource;
6. if current state already equals requested state, return idempotent success with `changed=false`;
7. for `published=false`, apply normal MODX-equivalent unpublish behavior:
   - published=false;
   - clear pub_date/unpub_date;
   - clear publishedby/publishedon;
   - update edited metadata;
   - save;
   - fire normal unpublish event / clear Resource cache equivalently;
8. for `published=true`, apply normal MODX-equivalent publish behavior:
   - validate publishability / duplicate friendly URL as normal MODX publication does;
   - published=true;
   - clear scheduled pub_date/unpub_date;
   - update edited/published metadata;
   - save;
   - fire normal publish event / clear Resource cache equivalently;
9. return only:

```json
{
  "data": {
    "resource_id": 123,
    "published": false,
    "changed": true
  },
  "meta": []
}
```

or the corresponding `published=true`.

No Cabinet code may be added to the external plugin repository by Codex; no real endpoint call in tests.

## Scope

### 1. Simplify full-description editor

Remove enhanced toolbar actions:

- Paragraph;
- H2;
- H3;
- Blockquote / quote;
- Link.

Keep:

- Bold;
- Italic;
- unordered list;
- ordered list;
- Remove formatting.

Do not redesign.

Do not destructively rewrite existing sanitized HTML merely because toolbar controls are reduced. Keep existing wider safe server sanitizer grammar for legacy/stored content unless a security reason requires otherwise.

### 2. Remove helper copy

Remove everywhere:

`Без JavaScript доступен ввод текста или семантического HTML. Форматирование очищается при сохранении.`

Remove everywhere:

`Без JavaScript используйте Ctrl/Command или Shift.`

Keep native textarea/select fallback controls functional and accessible.

### 3. Add secondary-currency field

Create additive migration and update:

- Group;
- GroupWorkflow fields;
- GroupRequest normalization/validation;
- shared form;
- read-only detail;
- prototype fixtures;
- tests.

Validation: nullable string, trim, max 255, whitespace-only null/empty.

Place under **Условия участия** next to/below primary price.

Preserve old input.

### 4. MODX price_usd mapping

Update only central `GroupModxPayloadBuilder`:

- nonempty `meeting_price_currency` → `tvs.price_usd`;
- empty/null → omit `price_usd`;
- primary `price` unchanged.

Update docs that previously say price_usd is unmanaged.

### 5. Add paused lifecycle

Update `GroupStatus` and every exhaustive status match/list/validation/presentation affected by a new enum case.

Allowed new transitions:

```text
active -> paused
paused -> active
```

The `paused -> active` transition must happen only from the successful publication job, not directly in the psychologist web request.

Add nullable timestamp:

```text
gp_groups.paused_at
```

Use exact pause duration to extend expiry on successful resume.

Update UI/status copy for psychologist/admin/prototypes as needed.

### 6. Publication-control state

Replace the earlier unpublish-only tracking design with generalized publication intent/state.

Add additive fields to `gp_groups`:

- unsigned bigint `modx_publication_revision`, default 0;
- nullable string(16) `modx_publication_desired`;
- nullable string(32) `modx_publication_status`;
- nullable timestamp `modx_publication_requested_at`;
- nullable timestamp `modx_publication_started_at`;
- nullable timestamp `modx_publication_synced_at`;
- nullable timestamp `modx_publication_failed_at`;
- nullable string(64) `modx_publication_error_code`.

Allowed desired values:

```text
published
unpublished
```

Allowed status values:

```text
pending
syncing
published
unpublished
failed
conflict
```

NULL means no publication-control request/known state yet.

Never store request/response bodies or remote error text.

When admin performs the existing manual `approved -> active` activation, set the local publication state to published/current without making an HTTP request.

### 7. MODX publication client

Extend the dedicated group MODX integration boundary; do not use DictionaryClient.

POST:

`/cabinet/resources/publication`

Serialize exact raw JSON once.

Idempotency key must be unique per logical publication revision so repeated pause/resume cycles cannot replay an old result:

```text
group-publication:<public_uuid>:<resource_id>:<revision>
```

The exact same queued revision must reuse the exact same key/body.

Requirements:

- reuse HTTPS-only MODX config/timeouts;
- no redirects;
- returned positive resource_id must equal requested ID;
- returned `published` must equal requested state;
- `changed` boolean-compatible;
- sanitized safe errors only;
- retry connection/timeout, 429, 5xx and known in-progress 409;
- auth/validation/not-found/malformed success/body-conflict 409 permanent;
- never rotate a revision key to hide conflict.

### 8. Publication scheduler/job

Create focused service/job, e.g.:

- `GroupModxPublicationScheduler`;
- `SetGroupModxPublication`.

Do not overload the content-sync job.

Scheduler inside caller's DB transaction:

1. increment `modx_publication_revision`;
2. set desired published/unpublished;
3. set status pending/requested_at;
4. clear previous safe failure state as appropriate;
5. dispatch `group_id + revision + expected_resource_id` only after commit.

Job:

- database queue;
- finite retry/backoff comparable to content sync;
- use the **same per-group overlap key** as content sync (`modx-group:<id>`) so content and publication writes cannot race remotely;
- no DB transaction across HTTP;
- use `withTrashed()` for admin-deleted rows;
- verify expected Resource ID still matches;
- stale publication revision must skip before HTTP;
- after HTTP, stale result must not overwrite newer desired state;
- already-correct remote publication is success;
- if no remote ID exists, no-op.

For current successful revision:

- mark published/unpublished accordingly;
- set synced timestamp;
- clear publication failure.

For retryable failure:

- preserve pending/current desired state and retry.

After exhaustion/permanent failure:

- failed or conflict with safe code only.

### 9. Psychologist pause action

Add owner-only POST action, for example:

```text
/groups/{group}/pause
```

Use FormRequest/policy or equivalent established authorization style.

Inside transaction:

- row lock;
- authorize;
- require active, enabled, remote ID, future expires_at;
- transition active -> paused with user actor/history;
- set paused_at now;
- schedule desired unpublished after commit.

No synchronous HTTP.

UI:

- active psychologist group shows **Поставить на паузу**;
- confirmation explains group will disappear from public site and placement time will stop;
- once paused, show paused status and resume action;
- admin can see paused status but does not need a new pause button unless required for existing admin lifecycle consistency.

### 10. Psychologist resume action

Add owner-only POST action, e.g.:

```text
/groups/{group}/resume
```

Inside transaction:

- lock;
- authorize;
- require paused/enabled/remote ID/paused_at/expires_at;
- if a current publish request is already pending/syncing, do not create unbounded duplicate revisions;
- schedule desired published;
- **do not transition to active yet**.

UI:

- button text such as **Возобновить публикацию**;
- while publish pending/syncing, show truthful pending state and avoid duplicate action;
- if publish failed/conflict, keep paused and show safe retry feedback/action.

Successful current publish job:

- lock row;
- recheck current revision/desire/status/remote ID;
- compute exact paused duration from `paused_at`;
- extend `expires_at` by that duration;
- clear paused_at;
- reset `expiry_warning_sent_at`;
- transition paused -> active as system (or another consistent actor strategy documented in code/tests);
- mark publication status published.

If local post-HTTP transition fails, job retry must remain idempotent: re-publishing an already published Resource is safe and local state must be reconciled without double-extending `expires_at`.

Implement a guard so expiry extension happens exactly once per successful resume revision.

### 11. Publication intent from delete

Extend central delete workflow.

Inside local transaction:

1. authorize/lock;
2. perform local delete/hide semantics;
3. remote ID exists → schedule desired unpublished;
4. after-commit job only.

Psychologist delete:

- any owned visible status including paused;
- always set psychologist_deleted_at;
- admin retains visibility/history.

Admin:

- any visible non-deleted group;
- soft delete + audit.

Remove successful-payment blocker.

Deletion never refunds payments.

Rollback/audit failure queues nothing.

Queue/MODX failure cannot undo local deletion.

Update confirmation text:

- automatic removal from publication for synchronized groups;
- no automatic payment refund;
- remove manual-unpublish instructions.

### 12. Publication intent from expiration

Extend `GroupLifecycleService::expireOne`:

1. current lock/recheck;
2. active -> expired;
3. remote ID → schedule desired unpublished;
4. dispatch after commit.

Paused groups are excluded from expiration because their clock is frozen.

Transition failure queues nothing.

Remote/queue failure does not revert expired.

Remove the manual expired warning and render truthful automatic publication state.

### 13. Preserve lifecycle behavior around pause

Update all affected behavior:

- participant intake accepts only `active`; paused remains non-accepting;
- expiry warnings apply only to active;
- extension remains only active/expired under existing rules, not paused;
- admin content edit/manual content resync of a paused group with remote ID remains allowed and must update the same **unpublished** Resource;
- update `SyncGroupToModx` eligibility to include paused where needed;
- admin MODX content-sync action/panel supports paused group as an existing remote Resource;
- initial manual activation remains unchanged;
- after pause/resume successful publish, no admin activation click is required;
- delete remains allowed while pause/resume publication work is pending.

### 14. Admin publication status UI

Extend existing admin MODX panel minimally:

- desired publication state;
- pending/syncing/published/unpublished/failed/conflict;
- requested/started/synced/failed timestamps where useful;
- safe error code/explanation.

Psychologist page should show only product-relevant pause/resume state, not transport secrets or low-level diagnostics.

Do not create a new visual system.

### 15. Tests

Cover at least:

#### Editor/helper
- no Paragraph/H2/H3/Quote/Link buttons;
- Bold/Italic/UL/OL/Remove remain;
- native fallback remains;
- both removed helper strings absent;
- existing safe H2/H3/blockquote/link HTML still renders safely.

#### Currency
- nullable migration;
- trim/max/null validation;
- old input/form/detail/prototype;
- nonempty maps to price_usd;
- empty omits price_usd;
- primary BYN unchanged.

#### Pause/resume
- only owner psychologist can pause/resume;
- only active can pause;
- approved/moderation/draft/revision/rejected/expired/awaiting_payment cannot pause;
- disabled group cannot pause/resume;
- pause requires remote Resource and future expiry;
- pause commits active -> paused/history and queues unpublished after commit;
- rollback queues nothing;
- paused group does not expire while wall clock passes;
- remaining placement time is preserved;
- resume leaves local paused until remote publish succeeds;
- successful publish transitions paused -> active and extends expires_at exactly by pause duration once;
- remote publish retry cannot double-extend expiry;
- failed publish keeps paused and time frozen;
- repeated pause/resume cycles preserve time;
- pause/resume publication revisions use new idempotency keys;
- resume requested while unpublish job queued makes old revision stale;
- delete while publish resume is pending wins as newer desired unpublished state;
- stale publish success must never reactivate a deleted/newer-state group;
- participant applications unavailable while paused;
- expiry warning not queued/sent while paused.

#### Delete
Representative lifecycle statuses including paused:
- owner delete available;
- psychologist_deleted_at only;
- admin can still see;
- payments/history survive;
- succeeded unrefunded payment does not block;
- remote ID schedules unpublished;
- no remote ID no job;
- admin soft delete/audit preserved;
- rollback queues nothing.

#### Expiration
- current active -> expired behavior;
- remote ID publication-unpublished revision after commit;
- no remote ID no job;
- transition failure no job;
- paused excluded;
- repeated/stale safe;
- remote failure leaves expired;
- manual-unpublish copy removed.

#### Publication client/job
- exact endpoint/body/key;
- publish and unpublish;
- already-correct `changed=false`;
- retry/permanent/conflict classification;
- returned ID/state mismatch rejected;
- exact revision key/body reused on retry;
- no token/content/base64/path leakage;
- withTrashed admin delete works;
- shared overlap lock with content sync;
- stale jobs/results do not overwrite newer intent;
- queue worker retry/exhaustion safe state;
- no real MODX HTTP.

#### Existing regressions
- content sync create/update unchanged;
- dictionary sync;
- lifecycle/extension;
- payment/history;
- prototype/status rendering;
- auth/IDOR.

### 16. Documentation

Update:

- `SPEC.md`;
- `docs/modx-api.md`;
- `docs/modx-group-sync-plan.md`;
- `docs/project-status.md`;
- `docs/architecture.md`;
- `docs/development.md`;
- `docs/deployment.md`;
- `docs/ui-pages.md` as needed.

Document:

- optional currency → price_usd when nonempty;
- paused lifecycle and frozen placement clock;
- active is prerequisite for psychologist pause;
- resume auto-publishes same MODX Resource and only then reactivates locally;
- initial publication remains manual;
- delete/expiry/pause unpublish but never delete remote Resource;
- delete does not refund payments;
- publication revision/idempotency behavior;
- external publication endpoint operator-managed outside repo;
- real endpoint acceptance remains manual.

## Out Of Scope

Do NOT:

- delete MODX Resources;
- auto-publish initial approved groups;
- auto-publish expired renewal before psychologist resume (resume applies only paused lifecycle);
- auto-refund/cancel WEBPAY;
- add restore UI;
- hard-delete groups;
- add external plugin code to Cabinet repo;
- make real MODX HTTP requests in automated work;
- redesign editor/page;
- destructively remove safe legacy sanitizer support;
- change unrelated mail/intake/405 behavior;
- create an accept commit.

## Constraints

- Laravel 12 / PHP ^8.2 / MySQL 8.
- Existing database queue/cache locks.
- No Node/npm/Vite/new package.
- All external HTTP faked.
- No DB transaction over network.
- Local pause/delete/expiration independent from MODX availability.
- Resume only becomes active after confirmed remote publish.
- MODX Resource ID immutable.
- Publication operations idempotent and revision-scoped.
- Do not edit `.ai/task.md`.

## Acceptance Criteria

1. Full-description toolbar is simplified and both specified helper texts are gone.
2. Optional currency field is stored/displayed; nonempty maps to MODX price_usd and empty preserves remote value.
3. Psychologist can delete any owned visible group; admin retains it/audit; admin delete remains soft.
4. Successful unrefunded payments no longer block delete and no automatic refund occurs.
5. Delete and expiration automatically request remote unpublished state; remote Resource is never deleted.
6. Psychologist can pause only an active, enabled, published/remote-backed group.
7. Pause immediately changes local active -> paused, freezes placement time and asynchronously unpublishes remote Resource.
8. Paused groups do not expire and do not accept participant applications.
9. Resume asynchronously republishes the **same** MODX Resource; only successful publish changes paused -> active.
10. Resume extends expires_at by the exact pause duration exactly once.
11. Repeated pause/resume/delete/expiry operations are revision-safe; stale remote results cannot overwrite newer lifecycle intent.
12. Remote/queue failures never roll back local pause/delete/expired state; publish failure leaves resume safely paused.
13. Initial publication after moderation remains manual; no automatic initial publish is introduced.
14. Existing content sync, dictionary sync, payment, extension, auth and prototype regressions pass.
15. No secrets/private payloads/real production data leak.
16. Live external publication endpoint acceptance remains manual.

## Checks

Run and report exact results for:

1. focused editor/form/currency tests;
2. focused pause/resume/publication client/job tests;
3. focused delete/expiry publication tests;
4. full `GroupWorkflowTest`;
5. full `GroupLifecycleTest` + lifecycle concurrency tests;
6. existing `ModxGroupSyncTest` + `ModxGroupClientTest`;
7. GroupContent/GroupCover/GroupHtmlSanitizer tests;
8. participant intake/application acceptance tests affected by paused status;
9. expiry warning tests;
10. `PrototypeTest`;
11. relevant payment/history regressions;
12. full MySQL suite;
13. Pint;
14. PHPStan;
15. composer check-platform-reqs;
16. composer validate --no-check-publish;
17. artisan view:cache;
18. artisan schedule:list;
19. git diff --check;
20. final status/diff/staged secret/artifact review.

No automated check may contact real MODX.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and its parent is `fb108792cdfb8902342b6f0ab79afeff27bdaad8`;
- confirm accepted implementation base remains `344d6a6ed5d372d6852c54ea7e8a1676861f40bb`;
- read WORKFLOW.md, AGENTS.md, this task/report;
- inspect relevant Stage 17/lifecycle/delete/payment/application sections of SPEC/docs;
- inspect shared group form/data/delete/actions/rich-text/multi-select, ui.js/CSS;
- inspect GroupStatus, Group model/migrations/request/workflow/policy/controllers;
- inspect GroupLifecycleService/groups:expire and expiry-warning job/scheduler;
- inspect participant-intake group eligibility;
- inspect MODX builder/client/content scheduler/job/status panel;
- inspect payment relations/delete safety and relevant tests;
- verify clean/known local tree.

During implementation:

- work only within task;
- do not edit task;
- no real MODX;
- no external plugin source in repo;
- publication control async + after commit;
- same per-group remote overlap lock for content/publication jobs;
- never remote-delete;
- never auto-publish initial approved/expired-renewal groups;
- preserve payment history/no auto-refund;
- preserve safe legacy HTML;
- ensure pause time cannot burn or be double-added on retry;
- avoid unrelated refactors.

Before commit:

- run all applicable checks;
- inspect complete diff/staged files;
- ensure no API key/production fixture/upload/base64/log/cache/vendor/private file is staged;
- update report factually;
- explicitly state live publication endpoint was not called.

Commit if complete:

`codex: TASK-2026-10-01-04 polish form and add pause lifecycle`

No accept commit.
