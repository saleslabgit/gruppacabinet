# MODX / mxHeadless API for Gruppa Cabinet

## Status

The main public site at `https://gruppa.info/` runs MODX 3 with mxHeadless.
A MODX plugin named `GruppaCabinetApi` is registered on
`OnMxHeadlessRegister` and exposes a protected synchronization endpoint.

TASK-2026-10-01-03 implements Cabinet mapping, HTTPS transport, database queue,
Resource identity and admin resync. The product owner reports the external plugin
updated with the cover contract below. Codex/tests have made no real MODX calls;
production end-to-end acceptance and hosting settings remain **not verified**.

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
    "pagetitle": "Example group"
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

Cabinet saves the returned MODX Resource ID after
the first successful create and uses it for later updates.

### `resource`

Cabinet sends only `pagetitle`, plus `alias` on create. The alias is Laravel
`Str::slug(title)` (fallback `group`), truncated to fit a `-g<local ID>` suffix
inside 191 characters. Updates omit alias to preserve the public URL.

The installed endpoint enforces parent **3**, template **8**, context `web`,
`published=false` on create; Cabinet does not send or override those fields.
Updates require the same parent/template/context and preserve publication.
Cabinet never publishes, unpublishes or deletes a MODX Resource.

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
    "updated": false,
    "cover_path": "images/cabinet-group-412-synthetic.png",
    "cover_cleanup_warning": false
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
    "updated": true,
    "cover_path": null,
    "cover_cleanup_warning": false
  },
  "meta": []
}
```

Cabinet must treat `resource_id` as the technical MODX identifier and persist
it separately from Cabinet's immutable `public_uuid`.

## Idempotency and retries

mxHeadless applies its standard `Idempotency-Key` middleware to POST requests.

Create always uses `group-create:<public_uuid>` while the local Resource ID is
NULL. Update uses `group-update:<public_uuid>:<revision>`. No automatic create-key
rotation is allowed. The exact JSON bytes are serialized once and SHA-256 hashed;
only key/hash and state are persisted, never JSON/HTML/base64 copies. If rebuilding
the same attempted key would change the hash (including owner/dictionary changes),
Cabinet stops with `idempotency_conflict` before sending different bytes.

Same-key/same-body retries can replay the original response. Remote body conflicts
also become `conflict`; the administrator must reconcile with MODX, not generate a
new create key. A successful stale create still saves its Resource/cover identity
and leaves the newer revision pending. A non-null Resource ID is never replaced.

**External limit:** mxHeadless defaults to a 86400-second idempotency TTL. The
endpoint has no documented permanent UUID lookup. A stable key alone does not
prove duplicate prevention after remote cache expiry. Before retrying an ambiguous
initial create outside the configured TTL, the operator must check MODX and
reconcile identity; this task provides no arbitrary Resource-ID editing UI.

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
- The correct group parent for Cabinet creation is Resource ID `3`.

## Cabinet group transport and queue

`GroupModxPayloadBuilder` owns the mapping in `modx-group-sync-plan.md`.
`GroupClient` uses POST `/cabinet/resources/sync`, Accept/Content-Type JSON,
Bearer authentication and the exact caller key. HTTPS is required; credentials,
query strings and fragments in the base URL are rejected; redirects are refused.
`MODX_SYNC_TIMEOUT` defaults to 60 seconds (1–60, not less than connect timeout).
`MODX_CONNECT_TIMEOUT` is shared with dictionary reads (default 5 seconds).

2xx requires a positive integer Resource ID and complementary boolean-compatible
created/updated flags. A nullable cover path and boolean cleanup warning are
normalized; malformed contracts fail permanently. Connection/timeout, 429, 5xx
and the known in-progress 409 retry with bounded backoff. Authentication, other
4xx, redirects, malformed responses and boundary/not-found errors fail safely.
No original transport exception, URL, token, body, leader or image bytes are logged.

Approval commits before dispatch to the database queue. Group admin content edits
for an existing remote resource or currently approved initial create queue a new
revision; manual resync does the same. Draft saves, psychologist submit, revision,
rejection, activation, expiration, deletion and payments do not dispatch outbound
work. No transaction spans HTTP. See architecture/deployment for operational detail.

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
local. Group forms use this local cache; outbound synchronization does not alter
the inbound contract.

## Installed cover contract

`cover` is optional and contains `filename`, `mime_type`, `content_base64`.
Decoded size is bounded by 5242880 bytes (5 MiB), MIME JPEG/PNG/WebP.
Cabinet verifies the private file, metadata, size and actual MIME before sending.
The external plugin writes into `images` with prefix `cabinet-group-` and sets
TV `image` (id 29, image type, Media Source 1 Filesystem, base URL `/`).
Never send `tvs.image` together with `cover`.

A successful uploaded cover with a nonempty returned `cover_path` records both
remote path and the exact local source path. Failures preserve prior tracking.
An unchanged cover is not uploaded again: Cabinet sends `tvs.image` with the
tracked relative path. Replaced local covers upload again. Cleanup warnings are
safe flags visible to administrators; they do not contain paths or upstream text.

External deployment checklist (operator verification required):

- `mxheadless_max_body_bytes >= 8388608`;
- MODX `upload_maxsize >= 5242880`;
- PHP/hosting body limit at least ~10 MiB;
- Media Source writable;
- private API key with `cabinet.sync`;
- shared database queue/cache worker and scheduler running.

The external MODX plugin is maintained outside this repository. Automated tests
use synthetic HTTP/storage fakes; live upload/create/update has not been verified.
