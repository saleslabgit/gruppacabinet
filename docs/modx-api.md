# MODX / mxHeadless API for Gruppa Cabinet

## Status

The main public site at `https://gruppa.info/` runs MODX 3 with mxHeadless.
A MODX plugin named `GruppaCabinetApi` is registered on
`OnMxHeadlessRegister` and exposes a protected synchronization endpoint.

The endpoint has been manually smoke-tested: it created a real **unpublished**
MODX Resource and successfully persisted ordinary TV values and a MIGX JSON
value. Cabinet-side outbound group synchronization is not implemented yet.

This document defines the transport/API boundary only. The final
Cabinet-field → MODX Resource/TV/MIGX mapping is intentionally documented
separately before Cabinet integration is implemented.

## Base URL

```text
https://gruppa.info/api/v1
```

## Endpoint

```http
POST /api/v1/cabinet/resources/sync
Authorization: Bearer <mxHeadless API key>
Content-Type: application/json
Idempotency-Key: <stable request key>
```

The endpoint is private and requires mxHeadless scope:

```text
cabinet.sync
```

Use a dedicated API key for Cabinet. Never commit or log the key.

## Request body

```json
{
  "resource_id": 412,
  "resource": {
    "pagetitle": "Example group",
    "parent": 3,
    "template": 8,
    "context_key": "web",
    "published": false
  },
  "tvs": {
    "title": "Example group",
    "desc": "<p>Example</p>",
    "leader": [
      {
        "MIGX_id": "1",
        "text": "Example leader"
      }
    ]
  }
}
```

### `resource_id`

Optional.

- absent/null: create a new MODX Resource;
- present: update that existing Resource;
- unknown ID: validation failure.

The future Cabinet integration must save the returned MODX Resource ID after
the first successful create and use it for later updates.

### `resource`

Object with an allowlisted set of scalar `modResource` fields. The installed
plugin currently allows:

```text
pagetitle
longtitle
description
alias
published
pub_date
unpub_date
parent
isfolder
introtext
content
richtext
template
menuindex
searchable
cacheable
menutitle
content_dispo
hidemenu
class_key
context_key
content_type
uri
uri_override
hide_children_in_tree
show_in_tree
```

Arrays/objects are rejected for Resource fields.

For a newly created Resource, the endpoint defaults:

```text
type         = document
class_key    = MODX\Revolution\modDocument
context_key  = web        (when omitted)
content_type = 1          (when omitted)
```

For Cabinet group drafts the canonical target is:

```text
parent      = 3
template    = 8
context_key = web
published   = false
```

**Do not use parent 308 for Cabinet group creation.**

## TV and MIGX values

`tvs` is an object keyed by existing MODX TV name.

Before any database write, the endpoint verifies that every supplied TV name
exists. An unknown TV fails the request.

Value normalization:

- array → JSON with unescaped Unicode/slashes; this is the transport for MIGX;
- `null` → empty string;
- boolean → `"1"` / `"0"`;
- other scalar → string.

Example ordinary TV:

```json
{
  "duration": "90"
}
```

Example MIGX TV:

```json
{
  "leader": [
    {
      "MIGX_id": "1",
      "text": "First leader"
    },
    {
      "MIGX_id": "2",
      "text": "Second leader"
    }
  ]
}
```

The endpoint JSON-encodes that array and saves it through MODX
`setTVValue()`.

## Transaction

Resource and TV writes are one operation:

```text
BEGIN
  create/update modResource
  set TV values
  set MIGX JSON TV values
COMMIT
```

On an exception the endpoint rolls back the transaction. A request must not
leave a deliberately accepted partially populated Resource.

## Response

Create:

```json
{
  "data": {
    "resource_id": 412,
    "created": true,
    "updated": false
  },
  "meta": []
}
```

Update:

```json
{
  "data": {
    "resource_id": 412,
    "created": false,
    "updated": true
  },
  "meta": []
}
```

Cabinet must treat `resource_id` as the technical MODX identifier and persist
it separately from Cabinet's immutable `public_uuid`.

## Idempotency and retries

mxHeadless applies its standard `Idempotency-Key` middleware to POST requests.

Use a stable key for retries of the same logical create request. A successful
same-key/same-body replay can return the original response. Reusing the same
key with a different body conflicts.

Important current boundary: the custom endpoint itself does **not** yet perform
a permanent lookup by Cabinet `public_uuid`. Therefore durable de-duplication
after the mxHeadless idempotency TTL depends on Cabinet persisting
`resource_id` and using update for later synchronization. The final Cabinet
retry contract must be fixed before implementation.

## Authentication and CORS

Production Cabinet calls this endpoint server-to-server over HTTPS. CORS is not
required for that flow.

For the temporary local browser smoke page only, mxHeadless CORS was configured
for the exact local origin:

```text
http://127.0.0.1:5500
```

This local browser allowance is not part of the production Cabinet protocol.

## Verified MODX facts

From the live mxHeadless schema/resource inspection:

- Resources are readable/creatable/updatable/deletable through mxHeadless.
- TV definitions are read-only through the generic schema, which is why the
  custom endpoint is required for TV/MIGX writes.
- A real group Resource uses template ID `8`.
- A real group exposes ordinary TV values and MIGX JSON through
  `GET /resources/{id}?include=tv`.
- The correct group parent for future Cabinet creation is Resource ID `3`.

## Cabinet integration target

The follow-up Cabinet implementation must:

1. Call this endpoint after committed `moderation → approved`.
2. Run the external call through a database queue job, not inside the moderation
   database transaction.
3. Create the MODX Resource as `published=false`.
4. Persist the returned MODX Resource ID.
5. On retry/update, target the same Resource.
6. Send Resource/TV/MIGX values according to a separately approved mapping.
7. Keep final publication on the MODX site manual.
8. Keep `approved → active` in Cabinet as the action that starts placement dates.
9. Never access MODX database tables directly.

## Verified dictionary endpoint

The protected `GET /api/v1/cabinet/dictionaries` endpoint is installed and was
manually verified on 2026-10-01 with scope `cabinet.sync`.

Verified live MODX contract:

| Key | TV id | Type | Count | Stored values |
|---|---:|---|---:|---|
| `format` | 35 | `listbox` | 2 | `Офлайн`, `Онлайн` |
| `gender` | 39 | `listbox` | 3 | display strings |
| `groupType` | 37 | `listbox` | 23 | numeric strings |
| `approaches` | 36 | `listbox-multiple` | 23 | numeric strings |
| `tags` | 30 | `listbox-multiple` | 105 | numeric strings |

The endpoint returns resolved ordered `{value,label,position}` options and
`data.complete=true`. MODX `listbox-multiple` stores selected values joined
with `||`.

Labels are not identities. The live tag data contains duplicate labels with
different values, including `зависимость` (51/105), `психосоматика` (30/57)
and `подростки` (88/102). Cabinet must identify remote options by the owning
dictionary plus exact stored `value`, never by label.

Cabinet-side dictionary synchronization is implemented by TASK-2026-10-01-01.
The authoritative response is `data.complete === true`, with
`data.dictionaries` an object keyed by TV name. Each definition contains
`tv_id`, `tv_name`, `type`, and an `options` JSON array of
`{value: string, label: string, position: integer}`. TV names/types are checked;
TV numeric IDs are informational. Empty option arrays are accepted only inside
an otherwise complete, valid document. Unknown extra dictionary keys are ignored;
only the five fixed lists are imported.

### Cabinet dictionary operations

Apply the additive migration before serving the new code. It ensures the five
managed containers without changing existing items or container names. Clean
seeding is idempotent and does not invent remote options. The `modx_value`
column uses MySQL 8 `utf8mb4_0900_bin` (NO PAD) for exact string identity;
multiple NULL legacy values remain legal. Migration rollback removes metadata,
not historical containers/items; re-import is required if metadata is removed.

Set private runtime values (never commit or log them):

- `MODX_BASE_URL`: HTTPS mxHeadless API base URL including `/api/v1`;
- `MODX_TOKEN`: dedicated Bearer credential with `cabinet.sync`;
- `MODX_CONNECT_TIMEOUT`: integer seconds, default 5;
- `MODX_TIMEOUT`: integer seconds, default 30, maximum 60 and at least the
  connect timeout.

Missing/invalid config refuses the request. Redirects are not followed. The
client uses GET `/cabinet/dictionaries` only; there are no automatic HTTP retries.
Run `php artisan modx:sync-dictionaries`, or use **Обновить из MODX** on the
admin dictionary page. The command is scheduled hourly with `withoutOverlapping`.
CLI failures return nonzero with sanitized diagnostics; admin failures use the
existing validation feedback. Neither path exposes upstream errors/bodies.

Web, cron and CLI must share the configured cache store and cache prefix
(production baseline: database cache/locks). All entry points use the same
`modx:sync-dictionaries` lock with a 600-second lease around fetch and transaction;
contention fails safely, and lost ownership is checked before commit. Successful
output contains aggregate counts only. Dictionary last-sync is the last committed
complete import; item last-sync is its last confirmed presence in MODX. A failed
run leaves timestamps/data unchanged. Missing/legacy values remain stored but
inactive; labels are used only for one-time unambiguous legacy attachment.

The existing dictionary administration shows TV/value and timestamps and blocks
manual mutations of managed items. Container display names remain editable;
managed containers cannot be deleted. Education/custom dictionary CRUD stays
local. No group-form or outbound synchronization is introduced.

## Planned cover transport

The current sync endpoint is JSON-only. mxHeadless
`ContentNegotiationMiddleware` accepts JSON/form-urlencoded mutations but not
multipart, and `BodyLimitMiddleware` defaults
`mxheadless_max_body_bytes` to **1 MiB**.

The target group form allows JPEG/PNG/WebP covers up to 5 MiB. Therefore cover
transport must be explicitly added before Cabinet outbound sync. The preferred
low-volume design is bounded base64 image data inside the authenticated JSON
sync request, with:

- decoded-size validation at 5 MiB;
- MIME verification from bytes;
- a managed MODX image directory and collision-safe filename;
- TV `image` set to the resulting relative path;
- cleanup/compensation when the associated DB operation fails;
- an mxHeadless body limit large enough for base64 overhead (at least 8 MiB for
  a 5 MiB product limit), after verifying the actual hosting/PHP request limits.

This extension is planned, not installed/verified yet.

## Out of scope for this API document

- deciding which Cabinet field maps to each MODX TV;
- changing the public site's templates or MIGX configuration;
- automatic publication;
- Cabinet UI/queue implementation;
- modifying mxHeadless core.
