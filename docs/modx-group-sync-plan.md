# MODX group synchronization implementation plan

Status: **MODX dictionary synchronization, Cabinet group schema/form, HTML sanitizer, progressive rich-text editor and private cover storage implemented; MODX cover transport, payload mapper, outbound create/update job and end-to-end outbound synchronization planned**

This document records the implemented Cabinet milestones and the remaining
MODX outbound implementation order. It separates current behavior from planned
transport, mapping and synchronization work.

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
- MODX cover transport, payload mapper, outbound create/update client/job and
  end-to-end outbound synchronization remain planned. Approval does not dispatch
  an outbound job, and no MODX resource-ID column exists in Cabinet yet.

## Target ownership model

### Cabinet is authoritative for group content

Group content is authored in Cabinet. Once outbound synchronization is
implemented, it may overwrite the following managed MODX TV values:

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

## Group schema — implemented and planned

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

`public_site_resource_id` remains planned for outbound synchronization; it is
not part of the current schema. Legacy nullable `schedule` is preserved only
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
implemented in TASK-2026-10-01-02. MODX cover transport remains planned.

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

The current mxHeadless JSON API defaults to a 1 MiB request limit. Before cover
sync, extend the MODX endpoint to accept bounded image content (planned base64
inside authenticated JSON for the low request volume) and raise/verify the
mxHeadless/hosting request limit sufficiently for base64 overhead.

## Phase 5 — Mapping builder (planned)

Owner: Codex after exact MODX option/multiple serialization is known.

One service (for example `GroupModxPayloadBuilder`) owns all MODX names and
formatting. Controllers/Blade/jobs do not know TV names.

Initial target mapping:

| Cabinet source | MODX target |
|---|---|
| title | Resource `pagetitle`, TV `title` |
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

Unresolved until separately verified:

- Cabinet meeting price versus MODX `price` / `price_usd`;
- exact MODX TV used for Cabinet `public_uuid`;
- SEO/image-display secondary TVs not explicitly requested;
- exact weekday serialization if the MODX `days` TV requires more than the
  verified single-value example.

## Phase 6 — Outbound create/update job (planned)

Owner: Codex.

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

## Task ordering

Phase 1 contract is recorded in TASK-2026-10-01-01 and Phase 2 is implemented.
Phase 3 and the Cabinet portion of Phase 4 are implemented in TASK-2026-10-01-02.
MODX cover transport and Phases 5–7 remain planned. No outbound payload mapper,
approval job, resource-ID column or group HTTP request exists.
