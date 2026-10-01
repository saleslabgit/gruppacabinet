# MODX group synchronization implementation plan

Status: **MODX dictionary synchronization, Cabinet group schema/form, HTML sanitizer, progressive rich-text editor and private cover storage implemented; Cabinet cover transport, payload mapper, outbound create/update job implemented; real production acceptance pending**

This document records the implemented Cabinet milestones and the MODX outbound implementation. It separates current behavior from external production acceptance.

## Verified starting point

- Main site: MODX 3 + mxHeadless.
- Installed external plugin: `GruppaCabinetApi`.
- Verified endpoint: `POST /api/v1/cabinet/resources/sync`.
- Authentication: mxHeadless API key, scope `cabinet.sync`.
- Manual smoke already created an unpublished Resource and wrote/read ordinary
  TV values plus MIGX JSON.
- Canonical group Resource target: parent **3**, template **8**, context
  `web`, `published=false`.
- MODX dictionary synchronization is implemented for format, gender, group type,
  approaches and tags; the Cabinet form uses their local synchronized IDs.
- Cabinet group schema and shared form are implemented: short `description`,
  full HTML description, cover, structured meeting days/start time, frequency,
  city, format/gender/group type and multiple approaches/tags.
- Server-side HTML sanitizer, progressive rich-text editor and private Cabinet
  cover storage with authorized previews are implemented.
- Legacy free-text `schedule` remains only as a read-only fallback; it is not
  editable, parsed or overwritten by the current form.
- TASK-2026-10-01-03 implements outbound mapping, transport, state, queue and admin
  retry. External plugin updates were performed by the product owner, not here.
- Real production end-to-end acceptance remains **not verified**.

## Target ownership model

### Cabinet is authoritative for group content

Group content is authored in Cabinet. Synchronization may overwrite the following managed MODX TV values:

- title;
- short description;
- full sanitized HTML description;
- cover;
- owner-derived leader;
- meeting days/start time/frequency;
- city;
- duration;
- participant capacity;
- selected format/gender/group type/approaches/tags.

Manual edits to those managed MODX values are not authoritative and can be
overwritten by the next Cabinet synchronization.

### MODX is authoritative for option lists

MODX owns the selectable values and labels for:

| Cabinet dictionary | MODX TV | Cardinality |
|---|---|---|
| `group_format` | `format` | single |
| `gender` | `gender` | single |
| `group_type` | `groupType` | single |
| `group_approach` | `approaches` | multiple |
| `group_tag` | `tags` | multiple |

Cabinet stores local integer FKs for domain integrity and the exact MODX stored
value as `modx_value`. Display label changes do not change local FK identity.

## Group schema — implemented

The existing `description` column is retained as **short description**.
TASK-2026-10-01-02 added these nullable fields to `gp_groups`:

```text
full_description_html
cover_path
cover_original_name
cover_mime_type
cover_size
meeting_days           JSON
start_time             HH:MM
frequency
city
group_type_id
```

`public_site_resource_id` is nullable, unique and persisted on first success.
Sync revision/status/timestamps, safe error, key/hash and remote-cover tracking
are added by migration `2026_10_01_000003_add_group_modx_sync`. Legacy nullable `schedule` is preserved only
as a read-only fallback, without automatic parsing or form writes.

Implemented pivots:

```text
gp_group_approaches(group_id, dictionary_item_id)
gp_group_tags(group_id, dictionary_item_id)
```

No separate leader column: leader is derived from owner `first_name + last_name`
at outbound sync time.

## Target dictionary metadata

TASK-2026-10-01-01 implemented the following MODX-managed dictionary metadata
to identify the remote source without matching labels:

```text
gp_dictionaries:
  modx_tv_name nullable
  last_synced_at nullable

gp_dictionary_items:
  modx_value nullable
  last_synced_at nullable
```

Use a unique constraint for non-null identity by dictionary + `modx_value`.

Existing manually seeded `group_format` and `gender` need a one-time bootstrap:

1. match an imported option to an existing item only when the label match is
   unambiguous;
2. attach its `modx_value`;
3. create a new item when no safe match exists;
4. stop/report a conflict when more than one local candidate matches.

New `group_type`, `group_approach`, `group_tag` dictionaries start from the
MODX import.

## Phase 1 — MODX resolved dictionary API

Owner: public-site/MODX operator.

Extend `GruppaCabinetApi` with:

```text
GET /api/v1/cabinet/dictionaries
scope: cabinet.sync
```

The plugin has a fixed TV whitelist and resolves each TV exactly as MODX input
rendering does:

```text
elements
  -> processBindings(...)
  -> parseInputOptions(...)
  -> Label==storedValue
  -> {label, value, position}
```

Do not expose raw `@SELECT`/binding source and do not accept arbitrary TV names.

The endpoint is accepted when one authenticated request returns complete,
unambiguous ordered options for `format`, `gender`, `groupType`,
`approaches`, and `tags`. Save the JSON result for the next design check.

## Phase 2 — Cabinet dictionary synchronization

Implemented by TASK-2026-10-01-01 (automated verification uses synthetic HTTP fakes):

- schema metadata above;
- five managed dictionary containers; group relations/pivots were added later
  in Phase 3;
- mxHeadless read client;
- `ModxDictionarySyncService`;
- `php artisan modx:sync-dictionaries`;
- hourly scheduler with `withoutOverlapping`;
- admin manual sync action/status.

Sync algorithm:

1. fetch the complete remote dictionary document;
2. validate every required dictionary before writes;
3. start one local DB transaction;
4. for each option identify by `dictionary_id + modx_value`;
5. create/update label and sort order, set active;
6. deactivate previously synced values missing from the complete response;
7. never delete referenced/history values;
8. update last-success timestamps inside the transaction;
9. commit all dictionaries together.

Network/HTTP/parse/partial failures make no local dictionary changes. A shared
cache lock surrounds fetch plus transaction for CLI, scheduler and admin runs.
The migration preserves existing container names, item IDs/codes and group FKs.
Legacy matching uses only Unicode lowercase and collapsed/trimmed whitespace;
ambiguous matches or generated-code collisions roll back the entire import.
Unmatched legacy values become inactive; missing remote values are retained and
reactivated under the same identity when they return. Manual managed-item CRUD
is rejected by the domain service, and the existing admin views display TV/value
and last-success state. Education and custom dictionaries keep local CRUD.

Runtime configuration and operations are in `docs/modx-api.md`. No real MODX
request or production deployment is part of automated verification.

## Phase 3 — Group schema and form (implemented)

Implemented in TASK-2026-10-01-02 through the existing shared Blade form.
Nullable additions preserve legacy rows; group type uses a local FK and
approaches/tags use local-ID pivots. Complete content is required for owner
moderation submit and admin create; ordinary legacy updates remain possible.

Fields:

- title;
- short description (current `description`);
- full description rich editor;
- cover upload;
- meeting days;
- start time;
- frequency;
- city;
- format;
- gender;
- group type;
- multiple approaches;
- multiple tags;
- existing duration/capacity/meeting price.

Legacy free-text `schedule` is displayed only as a read-only fallback. Its
contents are never parsed into structured values. The current moderation submit
requires structured meeting days and start time; ordinary legacy saves remain possible.

## Phase 4 — HTML safety and cover storage

Cabinet HTML sanitization, progressive editor and private cover storage are
implemented in TASK-2026-10-01-02. Cabinet base64 cover transport is implemented in TASK-2026-10-01-03.

Rich HTML:

- server-side allowlist sanitizer;
- semantic formatting tags only;
- no `class`, `style`, `id`, `data-*`, scripts, iframes or event handlers;
- sanitizer behavior covered by security/feature tests;
- do not rely on editor output cleanliness.

Cover:

- JPEG/PNG/WebP;
- default product max 5 MiB, configuration-backed;
- byte/MIME validation;
- private Cabinet storage;
- replacement deletes superseded private files only after safe state transition.

The external plugin accepts bounded base64 cover data. Production must set
`mxheadless_max_body_bytes >= 8388608`, `upload_maxsize >= 5242880`, PHP/hosting
body limits >= ~10 MiB and writable Media Source. These live settings remain
unverified. Cabinet sends bytes only when the local source differs from the
last successful remote cover; otherwise it reasserts `tvs.image`.

## Phase 5 — Mapping builder (implemented)

`GroupModxPayloadBuilder` implements the verified contract.

`GroupModxPayloadBuilder` owns all MODX names and
formatting. Controllers/Blade/jobs do not know TV names.

Implemented mapping:

| Cabinet source | MODX target |
|---|---|
| title | Resource `pagetitle`, TV `title` |
| immutable public_uuid | TV `groupid` (84, text) |
| meeting_price minor BYN / 100 | TV `price` (34, number, no decimals) |
| short description | TV `shortDescription` |
| sanitized full HTML | TV `desc` |
| cover | TV `image` |
| owner first + last name | MIGX TV `leader` |
| meeting days | TV `days` |
| start time | TV `startAt` |
| frequency | TV `frequency` |
| city | TV `city` |
| format.modx_value | TV `format` |
| group_type.modx_value | TV `groupType` |
| approach.modx_value list | TV `approaches` |
| tag.modx_value list | TV `tags` |
| duration minutes | TV `duration` |
| participant capacity | TV `participantsCount` |
| gender.modx_value | TV `gender` |

`days` (45, tag) maps mon/tue/wed/thu/fri/sat/sun to
`пн,вт,ср,чт,пт,сб,вс`, using canonical Monday–Sunday order without spaces.
`startAt` receives unchanged `HH:MM`. Approaches/tags join exact `modx_value`
with `||` in dictionary sort_order/id order. Leader is exactly one MIGX row,
`{"MIGX_id":"1","text":"FirstName LastName"}`, with whitespace collapsed and
no middle name. Create-only deterministic alias is slug + `-g<local ID>`, max 191.

`groupid` stores the immutable UUID by integration decision; legacy samples did
not use this field. Whole BYN price is an integer decimal string: minor price
must be divisible by 100. `price_usd` (81, text) is a separate secondary currency
line from nullable `meeting_price_currency`: nonempty trimmed text is sent, empty values omit the TV and preserve its remote value. SEO/showOnMainPage/other unlisted TVs are omitted.

Submit, admin create, approval and manual resync require complete content,
owner first/last name, active correctly linked MODX dictionary values and a
valid existing private cover. Ordinary legacy edits retain inactive selections
and do not acquire new completeness requirements. Outbound jobs validate again.

## Phase 6 — Outbound create/update job (implemented)

`GroupModxSyncScheduler`, `SyncGroupToModx` and `Modx\GroupClient` implement this flow.

After committed `moderation -> approved`:

1. dispatch database queue job after commit;
2. reload group + owner + dictionary relationships;
3. build the complete payload;
4. create unpublished MODX Resource when no remote ID exists;
5. persist returned `public_site_resource_id`;
6. later syncs update the same Resource;
7. use stable idempotency for one logical request/retry;
8. do not roll the group back from `approved` on transient MODX failure;
9. expose safe admin sync status/retry diagnostics.

## Phase 7 — End-to-end outbound acceptance (planned)

Use synthetic/non-public data and verify:

- adding an option in MODX -> sync -> selectable in Cabinet;
- renaming a MODX option -> same Cabinet item/FK, new label;
- removing option -> inactive, old group remains readable;
- re-adding same value -> reactivated, not duplicated;
- form validation for all new fields;
- sanitizer strips forbidden HTML attributes/elements;
- image limit/MIME/replacement;
- multiple approaches/tags serialize exactly as MODX expects;
- approval creates one unpublished Resource under parent 3/template 8;
- complete fields and leader MIGX are present;
- repeated sync updates same Resource;
- MODX outage leaves approved group and local dictionaries intact;
- no API key, full HTML payload, image bytes/base64 or personal data is logged.

## Implementation and acceptance status

Phases 1–6 are implemented (external MODX plugin maintained by its owner).
Cabinet verification uses Laravel HTTP fakes only. Phase 7 production acceptance
remains pending the product owner's deployment test.

Create key: `group-create:<public_uuid>`; update key:
`group-update:<public_uuid>:<revision>`. No create-key rotation. Exact request
hash protects retries against payload drift without storing content snapshots.
See `modx-api.md` for the external TTL limitation and conflict recovery boundary.

Jobs serialize group/revision only, re-read current state and skip stale revisions
before HTTP. Per-group cache overlap protection covers transport and persistence.
Stale successful responses still preserve learned Resource/cover identity; stale
failures cannot overwrite a newer revision. Admin status/manual resync uses the
same asynchronous pipeline, including after a successful sync.

## Pause and publication lifecycle (TASK-2026-10-01-04)

Only the non-admin owner can pause an enabled active group with an existing MODX
Resource and future expires_at. POST `/groups/{group}/pause` confirms active → paused,
records paused_at and requests unpublished after commit. Pause controls publication
only: expires_at never changes on pause/resume, and placement time keeps counting down.
Paused groups reject participant applications and extensions, but visible paused groups
receive normal expiry warnings and automatically transition paused → expired at the
original deadline. Expiration clears paused_at and queues a newer unpublished revision.
Psychologist-hidden groups are excluded from warning selection/delivery/marker updates
and expiration selection/row-locked rechecks; their retained admin/audit state remains.

POST `/groups/{group}/resume` requests published for the same immutable Resource ID
only while expires_at is future. Local status stays paused until confirmed publication
or expiration. A successful current publication job rechecks the deadline under lock,
transitions paused → active as system and clears paused_at, preserving expires_at and
expiry_warning_sent_at. If already due before HTTP, publish is skipped and the group
expires. If due during HTTP, the normal system expiration transition and a newer
unpublished revision commit, then remote unpublish is queued; the old response cannot
reactivate the group or overwrite the newer intent. History and publication markers
commit atomically. Duplicate pending resume requests reuse the current revision.
Initial approved activation and expired renewal still require manual publication;
manual activation records published locally without HTTP.

Pause/delete/expiry request unpublished; Resource deletion is never used. Owner
delete hides any visible group with psychologist_deleted_at; admin delete is soft
with audit. Payments/history survive; no automatic refund or payment blocker.

Publication intent has an incrementing revision, desired published/unpublished,
status pending/syncing/published/unpublished/failed/conflict and safe timestamps/code.
Jobs carry group ID/revision/expected Resource ID, run after commit on the database
queue, and share the `modx-group:<id>` lock across job classes via shared(). No DB
transaction crosses HTTP. Stale revisions skip before HTTP and cannot overwrite
newer intent afterward; delete wins over pending resume. Queue failure preserves
the local lifecycle and marks safe failure. Conflicts require operator reconciliation;
resume never rotates a conflicting revision key to hide an error.

The form keeps Bold/Italic/UL/OL/Remove formatting, accessible native fallback and
safe legacy headings/quotes/links. Optional `meeting_price_currency` is trimmed,
nullable, max 255; nonempty maps to price_usd, empty omits the TV. BYN is unchanged.

The external publication endpoint is maintained by the MODX operator outside this
repository. Automated work uses HTTP fakes only. Live endpoint acceptance remains
manual and unverified.
