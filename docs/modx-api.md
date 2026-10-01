# MODX / mxHeadless API for Gruppa Cabinet

## Status

The main public site at `https://gruppa.info/` runs MODX 3 with mxHeadless.
A MODX plugin named `GruppaCabinetApi` is registered on
`OnMxHeadlessRegister` and exposes a protected synchronization endpoint.

The endpoint has been manually smoke-tested: it created a real **unpublished**
MODX Resource and successfully persisted ordinary TV values and a MIGX JSON
value. Cabinet-side synchronization code is not implemented yet.

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

## Out of scope for this API document

- deciding which Cabinet field maps to each MODX TV;
- changing the public site's templates or MIGX configuration;
- automatic publication;
- Cabinet UI/queue implementation;
- modifying mxHeadless core.
