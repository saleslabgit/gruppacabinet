# Task: TASK-2026-10-01-03

Status: planned
Created from: a1009c8736064347a3e6df5b9f1048ff8f1a3061 (main)

## Title

Complete Cabinet → MODX group synchronization end to end

## Goal

Implement the complete outbound group synchronization milestone so the product
owner can deploy once and manually test the real flow end to end:

1. MODX-managed dictionaries are already cached locally.
2. A psychologist fills the complete Cabinet group form.
3. The psychologist submits it to moderation.
4. An administrator approves it.
5. After the approval transaction commits, Cabinet queues an outbound sync.
6. Cabinet creates exactly one unpublished MODX group Resource.
7. Cabinet persists the returned MODX Resource ID.
8. All approved Cabinet-managed fields, TVs, MIGX leader and cover are present.
9. Later approved/active content edits update that same MODX Resource.
10. An administrator can see sync state and request a safe retry/resync.

This task completes the Cabinet implementation of MODX mapping, transport, queue
and retry/status behavior. Real production MODX acceptance remains an external
manual test after deployment.

## Facts

### Accepted Cabinet base

- Accepted base commit:
  `a1009c8736064347a3e6df5b9f1048ff8f1a3061`.
- TASK-2026-10-01-01 implemented MODX → Cabinet dictionary synchronization.
- TASK-2026-10-01-02 implemented:
  - short and sanitized full group descriptions;
  - private JPEG/PNG/WebP covers up to 5 MiB by default;
  - structured weekdays/start time/frequency/city;
  - group type;
  - multiple approaches/tags;
  - complete-content moderation validation;
  - local-only MODX-managed dictionary relations.
- Current `meeting_price` is stored in Cabinet as integer **minor BYN units**.
- No Cabinet outbound group HTTP client/job or MODX Resource ID exists yet.
- Database queue infrastructure and queue-job patterns already exist.
- Automated tests globally prevent stray Laravel HTTP requests.

### Installed external MODX contract

The product owner has replaced the external MODX `GruppaCabinetApi` plugin.
Do not modify that plugin in this repository.

The following protected endpoint is installed:

```text
POST /api/v1/cabinet/resources/sync
scope: cabinet.sync
```

Request shape:

```json
{
  "resource_id": 123,
  "resource": {
    "pagetitle": "..."
  },
  "tvs": {
    "title": "...",
    "groupid": "..."
  },
  "cover": {
    "filename": "cover.jpg",
    "mime_type": "image/jpeg",
    "content_base64": "..."
  }
}
```

- `resource_id` is omitted for create and supplied for update.
- `cover` is optional.
- Do not send `tvs.image` together with `cover`.
- TV arrays are JSON-encoded by the MODX endpoint; use an array only for MIGX.
- Scalar TV values are saved as strings.
- The endpoint returns:

```json
{
  "data": {
    "resource_id": 123,
    "created": true,
    "updated": false,
    "cover_path": "images/cabinet-group-123-....jpg",
    "cover_cleanup_warning": false
  },
  "meta": []
}
```

On create, the endpoint itself enforces:

- parent = **3**;
- template = **8**;
- context = `web`;
- published = **false**.

Cabinet must not attempt to override those four values. On update the endpoint
requires the Resource to remain under parent 3/template 8/context web and does
not change its published flag.

Cover contract:

- field: `cover`;
- transfer: base64 in JSON;
- decoded maximum: **5 MiB / 5242880 bytes**;
- MIME: JPEG, PNG, WebP;
- MODX media container: `images`;
- Cabinet-owned remote filename prefix: `cabinet-group-`;
- mxHeadless request-body target setting: at least **8388608** bytes;
- MODX `upload_maxsize` target setting: at least **5242880** bytes.
- TV `image` is TV id 29, type `image`, Media Source 1 `Filesystem`,
  base URL `/`.

The product owner was instructed to configure those MODX request/upload limits;
the live deployment check remains external and must be documented as such.

### Verified remaining TV contract

The external read-only contract endpoint returned:

| TV | id | type | meaning / contract |
|---|---:|---|---|
| `days` | 45 | `tag` | weekday tags; example `вт` |
| `groupid` | 84 | `text` | currently unused on legacy sample |
| `price` | 34 | `number` | BYN; `allowDecimals=0`; sample `100` |
| `price_usd` | 81 | `text` | free secondary-currency text; sample `2500 RUB, 30 USD` |
| `image` | 29 | `image` | relative Media Source path |

The public site currently renders weekday abbreviations
`пн`, `вт`, `ср`, `чт`, `пт`, `сб`, `вс`; it renders multiple
days such as `Сб, Вс`. MODX's standard `tag` input renderer splits stored
tag values on commas. Therefore the exact outbound weekday mapping for this
integration is:

| Cabinet | MODX stored tag |
|---|---|
| `mon` | `пн` |
| `tue` | `вт` |
| `wed` | `ср` |
| `thu` | `чт` |
| `fri` | `пт` |
| `sat` | `сб` |
| `sun` | `вс` |

Store multiple MODX day tags as a comma-separated string with no added spaces,
in Cabinet canonical Monday→Sunday order.

Current public pages use both dot and colon historical start-time formatting;
new Cabinet values are canonical `HH:MM`, and this task sends that value
unchanged to `startAt`.

Public-site evidence confirms that MODX `price=100` renders as **100 BYN**.
`price_usd` is rendered as a separate optional secondary-currency line.
Cabinet has no field/source-of-truth for that secondary-currency text.

### Existing verified group TV mapping

Use this mapping:

| Cabinet source | MODX target |
|---|---|
| title | Resource `pagetitle` |
| title | TV `title` |
| public UUID | TV `groupid` |
| short description | TV `shortDescription` |
| sanitized full HTML | TV `desc` |
| owner first + last name | MIGX TV `leader` |
| weekdays | TV `days` |
| start time | TV `startAt` |
| frequency | TV `frequency` |
| format.modx_value | TV `format` |
| city | TV `city` |
| group type.modx_value | TV `groupType` |
| approach modx values | TV `approaches` |
| duration minutes | TV `duration` |
| participant capacity | TV `participantsCount` |
| gender.modx_value | TV `gender` |
| meeting price | TV `price` |
| tag modx values | TV `tags` |
| private Cabinet cover | `cover` request field → TV `image` |

Do **not** write:

- `price_usd`;
- `seoTitle`;
- `seoDescription`;
- `showOnMainPage`;
- other unlisted MODX fields/TVs.

### Mapping decisions fixed by this task

These are integration decisions, even where legacy data did not historically
use the field:

- TV `groupid` stores the immutable Cabinet `public_uuid`.
- One Cabinet group has one owner, therefore `leader` is one MIGX row:

```json
[
  {
    "MIGX_id": "1",
    "text": "FirstName LastName"
  }
]
```

  Build `text` from trimmed owner `first_name + last_name`; collapse internal
  whitespace. Do not include middle name. A missing/empty result is not syncable.
- `approaches` and `tags` are `listbox-multiple`; serialize exact
  `modx_value` strings joined by `||` in relationship order
  (dictionary sort_order/id).
- `price` receives whole BYN, not Cabinet minor units. A syncable group must
  have `meeting_price % 100 === 0`; send `meeting_price / 100` as an
  integer decimal string.
- `price_usd` is not Cabinet-owned and must be omitted. Updates must therefore
  preserve any manually maintained MODX secondary-currency text.
- Cabinet is authoritative for the cover.
- For deterministic collision-free Resource creation, send an alias **only on
  create**:
  - base = Laravel `Str::slug(group title)`;
  - fallback base = `group`;
  - append `-g{Cabinet numeric group id}`;
  - keep the final alias within MODX's 191-character field limit.
  On later updates omit `alias` so the public URL remains stable when a title
  changes.

### mxHeadless idempotency behavior

Installed mxHeadless supports `Idempotency-Key` on POST:

- valid keys are cached with the 2xx response;
- request-body hash is stored;
- a retry with the same key + same body replays the response;
- reusing the same key with a different body returns conflict;
- default idempotency TTL is 86400 seconds unless production setting differs.

Use this behavior deliberately. Never silently generate a new initial-create key
after an ambiguous create outcome.

## Assumptions

- Real MODX dictionary values stored in Cabinet remain the authoritative remote
  values for format/gender/group type/approaches/tags.
- A Resource created by Cabinet remains the same remote identity for the life of
  that Cabinet group.
- MODX publication remains a manual/public-site operation. Cabinet approval
  creates/updates content but never publishes/unpublishes the MODX Resource.
- Existing Cabinet group activation/expiration/deletion behavior remains
  unchanged.
- No automatic MODX delete is required when a Cabinet group is deleted.
- A permanent outbound failure must not roll back an already committed
  `approved` Cabinet status.
- Administrator visibility/retry is required; psychologist-facing sync status is
  not required.

## Unknowns / external acceptance

The repository cannot prove:

- actual production `mxheadless_max_body_bytes`;
- actual production MODX `upload_maxsize`;
- PHP/web-server POST body limits;
- production API key validity;
- real queue/cron availability after deployment;
- real MODX Media Source write permissions.

Do not block implementation on these external facts. Document them in deployment
acceptance and use HTTP/storage/queue fakes locally.

## Scope

### 1. Add outbound synchronization state

Create one additive migration; do not modify prior migrations.

Add to `gp_groups` at minimum:

- nullable unsigned `public_site_resource_id`;
- unsigned bigint `modx_sync_revision`, default 0;
- nullable string `modx_sync_status` (max 32);
- nullable timestamp `modx_sync_requested_at`;
- nullable timestamp `modx_sync_started_at`;
- nullable timestamp `modx_synced_at`;
- nullable timestamp `modx_sync_failed_at`;
- nullable string `modx_sync_error_code` (max 64);
- nullable string `modx_remote_cover_path`;
- nullable string `modx_remote_cover_source_path`;
- nullable char(64) `modx_sync_payload_hash`.

Add a unique index on non-null `public_site_resource_id`.

Use only these status values:

```text
pending
syncing
synced
failed
conflict
```

NULL means never requested/not applicable.

Do not store:

- API tokens;
- full outbound JSON;
- HTML copies;
- base64 cover data;
- remote response bodies.

Cast sync timestamps and numeric fields appropriately on `Group`.

### 2. Tighten sync-ready completeness without breaking legacy saves

Ordinary legacy draft/admin edits continue to work as today.

For:

- psychologist submit to moderation;
- admin create;
- admin approval of any pre-existing/legacy moderation group;

require outbound-syncable data:

- all currently required complete group fields;
- owner first name + last name produce a nonempty leader text;
- format/gender/group type each have a non-null exact `modx_value`;
- every approach/tag has a non-null exact `modx_value`;
- selections used for a new moderation submission are currently active in their
  MODX-managed dictionaries;
- local private cover metadata/path exists;
- private cover file exists and matches stored allowed MIME/size assumptions;
- `meeting_price` is divisible by 100.

A previously selected inactive value may still be retained during an ordinary
edit, but it must block a new moderation submission/approval until replaced by a
currently available MODX value.

Give user/admin validation messages that identify the field/category, not remote
secrets or raw payloads.

### 3. Implement one central MODX payload builder

Create a focused service such as `GroupModxPayloadBuilder`.

Controllers, jobs and views must not hardcode TV names or serialization rules.

It must load/require:

- group;
- owner;
- format;
- gender;
- group type;
- approaches;
- tags.

Build exact payload:

```text
resource.pagetitle = group.title
resource.alias = deterministic alias only on create

tvs.groupid = group.public_uuid
tvs.title = group.title
tvs.shortDescription = group.description
tvs.desc = group.full_description_html
tvs.leader = [{"MIGX_id":"1","text":"<first last>"}]
tvs.days = comma-joined Russian weekday tags
tvs.startAt = group.start_time
tvs.duration = decimal minutes
tvs.frequency = group.frequency
tvs.format = group.format.modx_value
tvs.city = group.city
tvs.groupType = group.groupType.modx_value
tvs.approaches = modx_value joined with ||
tvs.participantsCount = decimal capacity
tvs.gender = group.gender.modx_value
tvs.price = whole BYN decimal string
tvs.tags = modx_value joined with ||
```

Do not include `price_usd` or any unlisted TV.

No value may be inferred from display labels when an exact `modx_value` exists.

#### Cover mapping

If there is no stored remote cover or
`group.cover_path !== group.modx_remote_cover_source_path`:

- read the current private Cabinet cover from the configured private disk;
- verify it still exists;
- verify byte size and MIME against the stored/current cover contract;
- add:

```json
"cover": {
  "filename": "<safe original filename>",
  "mime_type": "<stored verified MIME>",
  "content_base64": "<base64 bytes>"
}
```

- do not include `tvs.image`.

If the current local cover is already represented by
`modx_remote_cover_source_path` and `modx_remote_cover_path`:

- do not upload bytes again;
- include `tvs.image = modx_remote_cover_path` so Cabinet still reasserts the
  managed image TV value without generating a new file.

Return enough normalized metadata separately from the HTTP body to let the job
know which local cover path the request represents.

Compute SHA-256 of the exact raw JSON request body for diagnostics/state only;
do not store the body itself.

### 4. Implement the outbound mxHeadless client

Create a dedicated client under the existing MODX integration boundary; do not
put resource mutation into `DictionaryClient`.

Configuration:

- reuse `MODX_BASE_URL` and `MODX_TOKEN`;
- reuse/connect timeout or add a clearly named sync timeout;
- if adding a separate timeout, default to 60 seconds and document it;
- HTTPS only;
- no redirects.

POST:

```text
/cabinet/resources/sync
```

Headers:

- `Accept: application/json`;
- `Content-Type: application/json`;
- `Authorization: Bearer ...`;
- exact caller-supplied `Idempotency-Key`.

Requirements:

- serialize JSON once and use that exact raw body for the request and local hash;
- never log/tokenize/dump the body;
- never log Authorization, full HTML or base64;
- reject malformed/non-2xx responses;
- require a positive integer `data.resource_id`;
- require boolean-compatible created/updated semantics;
- accept nullable returned `cover_path`;
- expose `cover_cleanup_warning` as a safe diagnostic flag.

Classify failures into safe codes:

Retryable at minimum:

- connection/timeout;
- HTTP 429;
- HTTP 5xx;
- known mxHeadless 409 "idempotent request is already in progress".

Permanent at minimum:

- authentication/authorization;
- validation/contract 4xx;
- malformed successful response;
- boundary/resource-not-found errors.

Special conflict:

- mxHeadless 409 "Idempotency-Key was reused with a different request body"
  → safe code `idempotency_conflict`, no automatic new key.

Do not keep original transport exceptions as previous exceptions if they may
contain URL/header/body details.

### 5. Queue synchronization after committed approval

Create a database-queue job, e.g. `SyncGroupToModx`.

Recommended operational defaults:

- `ShouldQueue`;
- finite tries (for example 4);
- timeout sufficient for a bounded ~8 MiB request;
- bounded backoff (for example 60, 300, 900 seconds);
- use queue/cache overlap protection per group;
- never hold a DB transaction open across HTTP.

#### Scheduling a logical revision

Create one central service, e.g. `GroupModxSyncScheduler`, responsible for
marking a group pending and dispatching after commit.

Inside the same DB transaction as the local business change:

1. increment `modx_sync_revision`;
2. set status `pending`;
3. set requested_at;
4. clear safe failure code/timestamps as appropriate;
5. register an after-commit dispatch with `group_id + revision`.

Use it when:

- admin successfully changes status `moderation -> approved`;
- admin successfully edits content of a group that already has a MODX resource;
- admin edits content of a currently `approved` group whose initial create is
  still pending/failed;
- admin explicitly requests manual resync/retry.

Do **not** dispatch on:

- psychologist draft/revision saves;
- psychologist moderation submission;
- admin revision/rejection;
- activation alone;
- expiration;
- deletion;
- payment changes.

Approval transaction failure must queue nothing.

### 6. Idempotency and exactly-one initial Resource behavior

For requests with no local `public_site_resource_id`, always use the same
stable create key:

```text
group-create:<public_uuid>
```

Do not rotate this key automatically, including after timeout/retry/failure.

This is intentional:

- same key + same body can replay the original 2xx create response;
- if a prior ambiguous create succeeded but local content changed before recovery,
  mxHeadless returns an idempotency conflict instead of silently creating a
  duplicate Resource.

For updates with a known Resource ID, use:

```text
group-update:<public_uuid>:<revision>
```

A retry of the same queued revision must reuse the same key/body.

Never respond to an idempotency conflict by generating a new create key.

### 7. Job state/concurrency behavior

The job must re-read current DB state and relationships.

Before HTTP:

- group must still exist;
- status must be one of `approved`, `active`, `expired`;
- the job revision must not be newer than the DB revision;
- stale jobs older than a newer queued revision should not overwrite newer sync
  status.

Set safe `syncing` state without holding a transaction across network I/O.

After a successful response, in a short row-locked transaction:

- if local Resource ID is NULL, persist returned `resource_id`;
- if local Resource ID exists, returned ID must equal it;
- never replace one non-null local remote ID with a different ID;
- if a cover was uploaded and response returns a nonempty remote cover path,
  set:
  - `modx_remote_cover_path` to returned path;
  - `modx_remote_cover_source_path` to the local cover path represented by
    that request;
- if no new cover was uploaded, preserve existing remote-cover tracking;
- persist payload SHA-256;
- if this job revision is still current, mark `synced`, set
  `modx_synced_at`, clear failure state;
- if a newer revision appeared while HTTP was in flight, preserve the returned
  Resource/cover identity but leave/restore current state as pending for the
  newer revision.

A successful initial response must persist the remote Resource ID even if the
request became stale while in flight; this prevents the next revision from
creating another Resource.

Transient failures:

- throw only sanitized retryable exceptions so the queue retries;
- do not clear an already-known Resource ID.

When retries are exhausted, mark the current revision `failed` with only a safe
error code.

Permanent failures:

- mark current revision `failed` without pointless retries.

Idempotency-body conflict:

- mark `conflict`;
- code `idempotency_conflict`;
- do not issue another create request under a new key.

If a newer revision exists, an older failure must not overwrite the newer
pending/synced state.

### 8. Admin synchronization status and manual retry

Extend the existing admin group detail UI minimally; do not create a new design.

Show a MODX synchronization section with:

- status;
- MODX Resource ID when known;
- requested/started/success/failure timestamps as applicable;
- safe failure code / localized safe explanation;
- cover cleanup warning if persisted as safe state, if implementation stores it.

Add an admin-only POST action:

```text
/admin/groups/{group}/sync-modx
```

Behavior:

- authorize using existing admin/group boundaries;
- run local sync-ready validation;
- mark a new revision pending;
- dispatch after commit;
- do not perform the remote HTTP request in the web request;
- give safe flash feedback.

Allow manual resync even after a successful sync so an administrator can
reassert Cabinet-owned values.

Psychologists must not access the action or see secrets/remote diagnostics.

Do not add arbitrary manual remote Resource-ID editing in this task.

### 9. Preserve publication/lifecycle boundaries

This task must **not**:

- set MODX `published=true`;
- unpublish MODX automatically;
- delete a MODX Resource;
- change Cabinet activation/expiration rules;
- make Cabinet approval depend on synchronous network success.

Approval outcome:

- local transition to `approved` commits first;
- outbound work is asynchronous;
- MODX outage leaves the group approved with visible failed/pending sync state.

The external MODX endpoint itself guarantees new Resources are unpublished.

### 10. Logging and sensitive-data boundary

Allowed structured log context:

- Cabinet group ID;
- MODX Resource ID when already non-secret;
- revision;
- attempt;
- safe status/error code;
- HTTP status class/code if useful;
- whether a cover upload was attempted.

Never log:

- API token;
- Authorization header;
- full URL if it can include credentials/query secrets;
- raw request/response body;
- sanitized or unsanitized full description;
- short description/title if not operationally necessary;
- leader name;
- cover bytes/base64;
- local private cover path;
- original cover filename;
- MODX TV payload.

Transport exceptions should be wrapped into safe application exceptions.

### 11. Tests

All HTTP must use `Http::fake()`; real gruppa.info calls are forbidden.

Add focused coverage for at least:

#### Mapping

- exact Resource pagetitle;
- create-only deterministic alias;
- immutable UUID → `groupid`;
- short/full descriptions;
- leader one-row MIGX with first + last name only;
- all seven weekday mappings and canonical comma order;
- `HH:MM` startAt unchanged;
- format/gender/group type exact modx_value;
- approaches/tags exact `||` serialization and stable order;
- duration/capacity;
- whole-BYN price conversion;
- `price_usd` absent;
- unlisted SEO/showOnMainPage TVs absent.

#### Sync-ready validation

- fractional-BYN minor amount blocks submit/admin approval;
- missing owner first/last blocks approval;
- inactive/missing/unlinked MODX dictionary choices block new moderation/approval;
- missing private cover file blocks approval/resync safely;
- ordinary legacy edit remains possible without triggering outbound behavior.

#### Cover transport

- initial create sends one base64 cover object and no `tvs.image`;
- unchanged local cover on later sync sends remote image path and no bytes;
- replaced local cover uploads bytes again;
- successful response updates remote-cover tracking;
- failed upload/sync does not falsely update cover tracking;
- 5 MiB/local content limits remain enforced.

#### Approval/queue

- approval commits and queues exactly one revision after commit;
- rollback/failing approval queues nothing;
- revision/rejection/psychologist submit do not queue outbound sync;
- admin edit of remote/approved group queues update after commit;
- activation/expiration/deletion do not publish/unpublish/delete remotely;
- HTTP failure never rolls approved state back.

#### Idempotency/concurrency

- create key is stable `group-create:<uuid>`;
- repeated same create revision reuses exact key/body;
- update key contains revision;
- returned initial Resource ID is persisted exactly once;
- stale success persists newly learned Resource ID but cannot mark newer revision
  synced;
- a mismatched returned Resource ID is a conflict and never overwrites local ID;
- idempotency body conflict marks `conflict` and never rotates create key;
- stale failures do not overwrite newer status;
- overlap protection prevents concurrent remote writes for the same group.

#### Client/error/security

- 2xx response validation;
- malformed JSON/contract;
- 401/403/404/422 permanent failures;
- 429/5xx/connection retry behavior;
- in-progress 409 retry behavior;
- body-conflict 409 permanent conflict behavior;
- redirect refusal;
- timeout config;
- token/body/HTML/base64/name/path absent from logs, CLI/test exceptions and UI.

#### Admin UI/action

- status panel reflects pending/syncing/synced/failed/conflict safely;
- admin can queue manual resync;
- psychologist cannot call manual action;
- action does not synchronously call MODX;
- no N+1 regression on existing group lists/details.

Use realistic synthetic responses only. Never include the real API key or the
product owner's real production JSON dump as a committed fixture.

### 12. Documentation

Update current facts in:

- `SPEC.md`;
- `docs/modx-api.md`;
- `docs/modx-group-sync-plan.md`;
- `docs/project-status.md`;
- `docs/architecture.md`;
- `docs/development.md`;
- `docs/deployment.md`;
- `docs/ui-pages.md` only for the admin sync-status/action extension.

Documentation must record:

- exact verified days/groupid/price/price_usd/image contract;
- public_uuid → groupid integration decision;
- price_usd intentionally unmanaged;
- whole-BYN constraint for outbound sync;
- create/update idempotency keys;
- asynchronous approval behavior;
- private cover → base64 → MODX image flow;
- parent 3/template 8/web/unpublished create invariant;
- external production settings/checklist:
  - `mxheadless_max_body_bytes >= 8388608`;
  - `upload_maxsize >= 5242880`;
  - hosting/PHP request body limit >= ~10 MiB;
  - database queue scheduler/worker running;
  - real API credential configured privately;
  - Media Source writable.

After implementation, mark mapping/outbound phases implemented but keep real
production end-to-end acceptance explicitly **not verified** until the product
owner runs it.

## Out Of Scope

Do NOT:

- modify the external MODX plugin from this repository;
- make a real MODX request from Codex/tests;
- publish or unpublish MODX Resources;
- delete MODX Resources;
- manage `price_usd`;
- manage SEO/show-on-main TVs;
- change the five-dictionary inbound sync contract except where shared config
  refactoring is strictly necessary;
- add a second queue technology;
- add manual arbitrary MODX Resource-ID editing;
- change WEBPAY, email, participant intake, password setup, 405/route-cache
  behavior or unrelated code;
- expose the API token or cover bytes anywhere;
- create an accept commit.

## Constraints

- Laravel 12 / PHP ^8.2 / MySQL 8.
- Database queue is the existing queue transport.
- No Node/npm/Vite.
- No new Composer package is expected.
- Keep HTTP faked in automated tests.
- Use the existing synchronized local dictionary values; never call MODX while
  rendering/editing the group form.
- Do not hold database locks/transactions over network calls.
- A remote failure must not roll back committed approval.
- A local non-null `public_site_resource_id` is immutable unless the same ID is
  returned again.
- Initial-create idempotency key is stable for the group and may not be silently
  rotated.
- Do not edit `.ai/task.md`.

## Acceptance Criteria

1. Approving a complete moderation group commits locally and queues outbound sync
   after commit.
2. A successful initial job creates one unpublished canonical MODX Resource and
   saves its ID.
3. The exact approved mapping reaches Resource/TV/MIGX payload, including
   public_uuid/groupid, days, integer BYN price and cover.
4. `price_usd` and unowned TVs are never written.
5. Later approved/active admin content edits update the same remote Resource.
6. Unchanged covers are not re-uploaded; changed covers are uploaded and remote
   image tracking is updated safely.
7. Transient failures retry without changing approved state.
8. Permanent failures/conflicts are visible as safe admin sync state.
9. Initial create cannot silently duplicate by rotating an idempotency key.
10. Admin manual resync uses the same async pipeline; psychologists cannot invoke
    it.
11. MODX publication/deletion remains manual and unchanged.
12. Existing dictionary sync, group content, lifecycle, payment, auth and
    prototype behavior remains passing.
13. No real external HTTP occurs in automated verification.
14. No secret, HTML body, personal leader data, local cover path or base64 is
    logged/committed.
15. Documentation clearly distinguishes implemented code from external live
    acceptance still pending.

## Checks

Run and report exact results for:

1. focused new MODX outbound mapping/client/job/state/admin tests;
2. full existing `ModxDictionarySyncTest`;
3. full group content/cover/sanitizer tests;
4. full `GroupWorkflowTest`;
5. relevant moderation/lifecycle/concurrency tests;
6. `DictionaryAdminTest`;
7. `PrototypeTest`;
8. full MySQL test suite;
9. `php ./vendor/bin/pint --test`;
10. `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`;
11. `composer check-platform-reqs`;
12. `composer validate --no-check-publish`;
13. `php artisan view:cache`;
14. `php artisan schedule:list`;
15. `git diff --check`;
16. final `git status --short`, complete diff, staged-file and
    secret/upload/artifact review.

If practical, add a deterministic database-queue worker test for at least one
successful outbound job and one retry/failure case. It must use HTTP fakes only.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and its parent is
  `a1009c8736064347a3e6df5b9f1048ff8f1a3061`;
- read `WORKFLOW.md`;
- read `AGENTS.md`;
- read this `.ai/task.md`;
- read current `.ai/report.md`;
- read current Stage 17/group/MODX sections of `SPEC.md`;
- read `docs/modx-api.md`, `docs/modx-group-sync-plan.md`,
  `docs/project-status.md`, `docs/architecture.md`, `docs/deployment.md`;
- inspect current:
  - Group model/migrations;
  - GroupRequest/GroupContent/GroupWorkflow/GroupStatusTransitionService;
  - group admin and psychologist controllers/policies/views;
  - GroupPages;
  - GroupCovers;
  - DictionaryClient/ModxDictionarySyncService/dictionary models;
  - queue jobs and existing queue tests;
  - database queue/cache config and console schedule;
  - current group/dictionary/content/cover/moderation/concurrency tests;
- verify there are no unknown local changes.

During implementation:

- work only within this task;
- do not edit `.ai/task.md`;
- do not call real MODX;
- do not put remote I/O inside approval DB transactions;
- do not rotate initial create idempotency key after ambiguity/conflict;
- do not infer dictionary remote identity from labels;
- do not write unowned TVs such as price_usd/SEO/showOnMainPage;
- do not log outbound payload or private/sensitive values;
- preserve existing publication/lifecycle/payment behavior;
- keep admin UI changes minimal and within the existing group detail page;
- do not perform unrelated refactors.

Before commit:

- run all applicable checks above;
- inspect full diff and staged files;
- verify no real API call or real production fixture was introduced;
- verify no `.env`, API token, production JSON dump, private cover, base64,
  storage/cache/log/vendor or temporary artifact is staged;
- verify external plugin code itself is not added to this repository;
- update `.ai/report.md` with factual results and explicitly state real MODX
  acceptance was not run by Codex/tests.

If complete, commit with:

`codex: TASK-2026-10-01-03 complete MODX group sync`

If blocked/partial/failed, record the real status/reason in `.ai/report.md` and
do not present incomplete work as done.

Do not create an accept commit.
