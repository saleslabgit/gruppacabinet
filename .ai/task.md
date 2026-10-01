# Task: TASK-2026-10-01-04

Status: planned
Created from: 344d6a6ed5d372d6852c54ea7e8a1676861f40bb (main)

## Title

Polish group form, add secondary-currency price, and automate MODX unpublish on delete/expiry

## Goal

Complete one user-visible lifecycle milestone:

- simplify the full-description editor controls and remove unwanted helper copy;
- add the secondary-currency meeting-price field to Cabinet and MODX mapping;
- let both psychologist owners and administrators delete groups under explicit local semantics;
- when a group is deleted locally, never delete its MODX Resource — only unpublish it asynchronously;
- when an active placement expires, automatically unpublish the corresponding MODX Resource;
- preserve the existing manual MODX publication / Cabinet activation flow.

This task includes Cabinet code/tests/docs. The external MODX plugin is maintained outside this repository; implement against the fixed unpublish endpoint contract below using HTTP fakes only.

## Facts

- Accepted base commit is `344d6a6ed5d372d6852c54ea7e8a1676861f40bb`.
- TASK-2026-10-01-03 implemented outbound Cabinet → MODX content sync.
- Current MODX content endpoint creates/updates Resources but deliberately never changes publication state.
- Current local expiration already runs every minute through `groups:expire`: active groups with `expires_at <= now()` are atomically transitioned to `expired` with history; currently there is no MODX request.
- Current expired-group UI asks an administrator to unpublish manually.
- Current psychologist delete policy allows only draft/rejected groups and current delete policy blocks groups with a successful unrefunded payment.
- Current administrator delete policy allows any visible group but has the same payment guard.
- Current administrator delete soft-deletes the group and preserves history.
- Existing remote content sync is asynchronous, database-queue based and after-commit.
- Existing remote Resource identity is `public_site_resource_id`.
- Existing required `meeting_price` is the primary BYN price.
- Verified MODX TV `price_usd` is TV id 81, type text; legacy content may look like `2500 RUB, 30 USD`.
- Earlier integration intentionally left `price_usd` unmanaged because Cabinet had no field. This user request supersedes that decision.
- Current rich editor toolbar includes Paragraph, H2, H3, Bold, Italic, UL, OL, Blockquote, Link and Remove formatting.
- Existing stored sanitized HTML may contain p/h2/h3/blockquote/a and must remain safely readable.

## Product decisions

### Secondary-currency price

Add nullable `gp_groups.meeting_price_currency` string(255).

UI label exactly:

`Стоимость встречи (В валюте)`

Helper text exactly:

`Цена одной встречи с указанием валюты`

Semantics:

- optional plain text;
- trim input;
- max 255 characters;
- examples may be `2500 RUB`, `30 USD`, `2500 RUB, 30 USD`;
- do not parse amounts/currency codes;
- show on read-only detail only when nonempty;
- existing required BYN `meeting_price` stays unchanged;
- nonempty value maps to MODX TV `price_usd`;
- null/empty omits `price_usd` from outbound payload, preserving an existing legacy/manual MODX value;
- do not add this field to sync-ready completeness.

### Delete semantics

Allow delete for:

- psychologist owner;
- administrator;

for any visible non-deleted lifecycle status, including moderation/approved/active/expired/awaiting-payment, subject only to normal account/ownership authorization.

Remove lifecycle-status and successful-unrefunded-payment blockers from group deletion.

Deletion does not refund/cancel/alter payments.

Local behavior:

- psychologist delete always sets `psychologist_deleted_at`, so the group disappears from that psychologist's Cabinet but remains visible/auditable to administrators;
- administrator delete remains a soft delete;
- no hard delete.

Remote behavior:

- no remote ID → no MODX lifecycle request;
- remote ID exists → queue **unpublish**, never delete;
- local deletion commits independently of MODX availability.

### Expiration semantics

Keep current:

`active + expires_at <= now() -> expired`

After commit:

- remote ID exists → queue unpublish;
- no remote ID → no remote request;
- remote failure never rolls back `expired`.

Extension remains:

`expired -> approved -> manual MODX publish -> admin marks active`

No automatic publish.

## External MODX prerequisite / fixed contract

External `GruppaCabinetApi` must expose:

`POST /api/v1/cabinet/resources/unpublish`

scope: `cabinet.sync`

Request:

```json
{"resource_id":123}
```

Cabinet headers:

- Accept JSON;
- Content-Type JSON;
- existing Bearer token;
- `Idempotency-Key: group-unpublish:<public_uuid>:<resource_id>`.

Endpoint contract:

1. validate positive resource_id;
2. load Resource;
3. require parent=3, template=8, context=web;
4. never delete it;
5. already unpublished is idempotent success;
6. otherwise set MODX-equivalent unpublish state:
   - published=false;
   - clear pub_date/unpub_date;
   - clear publishedby/publishedon;
   - update edited metadata;
   - save;
   - fire normal unpublish event and clear relevant Resource cache;
7. response:

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

`changed=false` is valid when already unpublished.

Codex must not add external plugin source to this repository and must not call the real endpoint.

## Scope

### 1. Simplify full-description editor

Remove enhanced toolbar actions:

- Paragraph;
- H2;
- H3;
- Blockquote;
- Link.

Keep:

- Bold;
- Italic;
- unordered list;
- ordered list;
- Remove formatting.

Do not redesign.

Do not destructively rewrite already stored sanitized HTML merely because toolbar buttons are removed. Preserving the existing wider safe server sanitizer grammar for legacy content is preferred.

### 2. Remove helper copy

Remove everywhere it is emitted:

`Без JavaScript доступен ввод текста или семантического HTML. Форматирование очищается при сохранении.`

Remove everywhere it is emitted:

`Без JavaScript используйте Ctrl/Command или Shift.`

Keep native textarea/select fallback controls functional and accessible.

### 3. Add secondary-currency field

Add additive migration and update:

- Group;
- GroupWorkflow fields;
- GroupRequest validation/normalization;
- shared form;
- read-only detail;
- prototype fixtures;
- tests.

Validation: nullable string, trim, max 255, whitespace-only normalized empty/null.

Place under **Условия участия** next to/below the existing primary price field.

Preserve old input.

### 4. MODX price_usd mapping

Update only central `GroupModxPayloadBuilder`:

- nonempty `meeting_price_currency` → `tvs.price_usd`;
- empty/null → omit `price_usd`;
- primary `price` mapping remains unchanged.

Update docs that previously say price_usd is unmanaged.

### 5. MODX unpublish client

Extend the dedicated group MODX client or a tightly related lifecycle client.

POST `/cabinet/resources/unpublish`.

Use exact raw JSON once and stable idempotency key:
`group-unpublish:<public_uuid>:<resource_id>`.

Requirements:

- reuse HTTPS-only MODX config/timeouts;
- no redirects;
- require returned positive resource_id equal requested ID;
- require `published === false`;
- require boolean-compatible `changed`;
- safe sanitized errors only;
- retry connection/timeout, 429, 5xx and known in-progress 409;
- auth/validation/not-found/malformed/body-conflict are permanent;
- never rotate key.

### 6. Async unpublish service/job

Create focused service/job such as:

- `GroupModxUnpublishScheduler`;
- `UnpublishGroupInModx`.

Do not overload content-sync job.

Requirements:

- database queue;
- after-commit;
- finite retry/backoff comparable to content sync;
- per-group overlap protection compatible with content sync;
- no DB transaction across HTTP;
- use `withTrashed()` when needed for admin soft-deleted groups;
- psychologist-hidden groups remain queryable;
- expected remote ID must still match;
- absent remote ID is no-op;
- already-unpublished response is success.

### 7. Safe unpublish state

Add additive group fields:

- nullable string(32) `modx_unpublish_status`;
- nullable timestamp `modx_unpublish_requested_at`;
- nullable timestamp `modx_unpublished_at`;
- nullable timestamp `modx_unpublish_failed_at`;
- nullable string(64) `modx_unpublish_error_code`.

Statuses only:

- pending;
- unpublished;
- failed;
- conflict.

NULL = never requested/not applicable.

Never store bodies or remote text.

Extend existing admin MODX panel minimally for visible groups so expiration unpublish state/failure is observable.

### 8. Unpublish on delete

Extend central delete workflow:

1. authorize and lock;
2. perform local delete/hide semantics transactionally;
3. remote ID exists → mark unpublish pending;
4. dispatch only after commit.

Local delete must not wait on MODX.

Rollback/audit failure queues nothing.

Queue insertion failure after local commit must leave safe failed state where possible.

Update confirmation copy:

- remove manual-unpublish instructions;
- state synchronized main-site group will be automatically queued for removal from publication;
- state deletion does not automatically refund payments.

### 9. Unpublish on expiration

Extend `GroupLifecycleService::expireOne`:

1. existing lock/recheck;
2. transition active -> expired;
3. remote ID exists → mark unpublish pending;
4. after-commit dispatch.

Preserve every existing scheduler/recheck/history behavior.

Queue/MODX failures cannot revert expiration.

Remove the current manual warning:
`Снимите группу с публикации на gruppa.info вручную.`

Replace with truthful pending/success/failure automatic-state UI.

### 10. Delete authorization/UI

Psychologist:

- owner can delete any owned visible group regardless lifecycle status;
- group-level disabled alone must not prevent owner delete;
- disabled/deleted actor and cross-owner boundaries remain;
- local action always sets `psychologist_deleted_at`, never soft-deletes.

Admin:

- may delete any visible non-deleted group;
- keep soft delete + audit.

Remove successful-unrefunded-payment as authorization blocker.

Payments/history remain and are not refunded.

Expose delete action consistently in list/detail/shared modal.

### 11. Preserve republish flow

Do not auto-publish.

After expiry/unpublish:

- extension still produces approved;
- admin manually publishes existing Resource;
- admin marks active;
- placement dates/status behavior unchanged.

Activation may clear stale UI presentation of the previous unpublish state if needed, but makes no remote publish request.

### 12. Tests

Cover at least:

#### Editor/helper
- no Paragraph/H2/H3/Quote/Link buttons;
- Bold/Italic/UL/OL/Remove remain;
- textarea fallback remains;
- both removed helper texts absent;
- legacy safe H2/H3/blockquote/link content remains renderable.

#### Currency field
- nullable additive migration;
- trim/max/null validation;
- old input/form/detail/prototype;
- nonempty maps exactly to price_usd;
- empty omits price_usd;
- primary BYN unchanged.

#### Delete
Psychologist representative statuses: moderation/approved/active/expired/awaiting_payment:
- delete available;
- sets psychologist_deleted_at;
- admin can still see row;
- payments/history survive;
- succeeded unrefunded payment does not block;
- remote ID queues after-commit unpublish;
- no remote ID queues nothing;
- IDOR/account boundaries safe.

Admin:
- any lifecycle status;
- soft delete;
- audit/payment/history preserved;
- remote unpublish after commit.

Rollback/audit failure queues nothing.

#### Expiration
- existing due active -> expired unchanged;
- remote ID queues exactly one after-commit unpublish;
- no remote ID queues nothing;
- transition failure queues nothing;
- repeated/stale expiry safe;
- remote failure does not revert local expired;
- manual-unpublish UI copy gone.

#### Unpublish transport/job
- exact endpoint/body/key;
- already-unpublished success;
- retry/permanent/conflict classification;
- returned ID mismatch blocked;
- no secret/content/base64/path leakage;
- withTrashed deleted admin group still unpublishes;
- overlap protection with content sync;
- worker retry/exhaustion safe state;
- no real MODX HTTP.

### 13. Documentation

Update:

- `SPEC.md`;
- `docs/modx-api.md`;
- `docs/modx-group-sync-plan.md`;
- `docs/project-status.md`;
- `docs/architecture.md`;
- `docs/development.md`;
- `docs/deployment.md`;
- `docs/ui-pages.md` as needed.

Record:

- optional currency field → price_usd when nonempty;
- delete semantics and no automatic refund;
- remote delete never used;
- auto-unpublish endpoint/job;
- expiry auto-unpublish;
- extension/manual republish/activation unchanged;
- external endpoint operator-managed outside repo;
- real unpublish endpoint acceptance remains manual.

## Out Of Scope

Do NOT:

- delete MODX Resources;
- auto-publish MODX;
- auto-refund/cancel WEBPAY;
- add restore UI;
- hard-delete groups;
- add external plugin code to this repository;
- make real MODX HTTP requests in automated work;
- redesign editor/page;
- remove safe legacy sanitizer support just because toolbar controls disappear;
- change unrelated mail/intake/405 code;
- create an accept commit.

## Constraints

- Laravel 12 / PHP ^8.2 / MySQL 8.
- Existing database queue/cache locks.
- No Node/npm/Vite/new package.
- All external HTTP faked.
- No DB transaction over network.
- Local delete/expiration independent from MODX availability.
- Remote Resource ID immutable.
- Unpublish idempotent, never delete.
- Do not edit `.ai/task.md`.

## Acceptance Criteria

1. Full-description toolbar is simplified and both specified helper texts are gone.
2. Optional currency field is stored/displayed and nonempty values map to MODX price_usd.
3. Empty currency field does not overwrite legacy/manual MODX price_usd.
4. Psychologist can delete any owned visible group; group stays visible/auditable to admin.
5. Admin can soft-delete any visible group.
6. Successful unrefunded payments no longer block delete; no payment is refunded/removed.
7. Deleting synchronized groups queues remote unpublish, never remote delete.
8. Scheduled expiration queues remote unpublish automatically.
9. MODX/queue failure never rolls back local delete/expired state.
10. Remote unpublish is retryable/idempotent/observable with safe errors.
11. Extension/manual publication/activation remains unchanged; no auto-publish.
12. Existing sync/dictionary/payment/lifecycle/auth regressions pass.
13. No secrets/private payloads/real production data leak.
14. Live external endpoint acceptance remains manual.

## Checks

Run and report exact results for:

1. focused editor/form/currency tests;
2. focused MODX unpublish client/job/delete/expiry tests;
3. full GroupWorkflowTest;
4. full GroupLifecycleTest + lifecycle concurrency tests;
5. existing ModxGroupSyncTest + ModxGroupClientTest;
6. GroupContent/GroupCover/GroupHtmlSanitizer tests;
7. PrototypeTest;
8. relevant payment/history regressions;
9. full MySQL suite;
10. Pint;
11. PHPStan;
12. composer check-platform-reqs;
13. composer validate --no-check-publish;
14. artisan view:cache;
15. artisan schedule:list;
16. git diff --check;
17. final status/diff/staged secret/artifact review.

No check may contact real MODX.

## Hard Workflow Gate

Before editing:

- git log --oneline -5;
- git status --short;
- confirm HEAD is this planner commit and parent is `344d6a6ed5d372d6852c54ea7e8a1676861f40bb`;
- read WORKFLOW.md, AGENTS.md, task/report;
- inspect relevant Stage 17/lifecycle/delete/payment SPEC/docs;
- inspect shared group form/data/delete/actions/rich-text/multi-select, ui.js/CSS;
- inspect Group model/migrations/request/workflow/policy/controllers;
- inspect GroupLifecycleService and groups:expire;
- inspect MODX builder/client/schedulers/jobs/status panel;
- inspect payment relations/delete safety and relevant tests;
- verify clean/known local tree.

During implementation:

- work only within task;
- do not edit task;
- no real MODX;
- no external plugin source in repo;
- remote unpublish async + after commit;
- never remote-delete/auto-publish;
- preserve payment history/no auto-refund;
- preserve safe legacy HTML;
- avoid unrelated refactors.

Before commit:

- run all applicable checks;
- inspect complete diff/staged files;
- ensure no API key/production fixture/upload/base64/log/cache/vendor/private file is staged;
- update report factually;
- explicitly state live unpublish endpoint was not called.

Commit if complete:

`codex: TASK-2026-10-01-04 polish form and automate unpublish`

No accept commit.
