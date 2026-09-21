# Incoming integration v1

Stage 11 receives submissions from the **backend** of the existing public site.
The browser submits to that backend; the backend signs and sends HTTPS requests
to the cabinet. Same-host deployment does not make this a browser API. Never put
the secret or signature generation in HTML/browser JavaScript. No session, CSRF,
login cookie, CORS integration, email, password invitation or payment is involved.

Production endpoints:

- `POST https://gruppa.info/cabinet/api/v1/psychologists`
- `POST https://gruppa.info/cabinet/api/v1/group-applications`

Local base: `http://localhost:8080/cabinet/api/v1` (Docker only).
Staging base is not known: configure it when the staging deployment exists.
Production transport must be HTTPS, terminated by the hosting infrastructure.

## Authentication

Required headers on every POST:

| Header | Contract |
|---|---|
| `X-Request-Id` | Case-sensitive opaque token, 1–128 ASCII characters; first character alphanumeric, `_` or `-`; remaining characters alphanumeric, `_`, `-`, `.`, `:`. |
| `X-Timestamp` | Unix UTC seconds, decimal integer without leading zeros. Default tolerance is inclusive ±300 seconds. ±301 is rejected. |
| `X-Signature` | 64 lowercase hexadecimal characters, HMAC-SHA256 below. |
| `Content-Type` | `application/json` for applications; `multipart/form-data; boundary=...` for questionnaires. |

No query parameters are accepted. Canonical signing text consists of these six
lines, UTF-8, separated by LF, **without a trailing newline**:

```text
v1
POST
/api/v1/<endpoint>
<X-Timestamp>
<X-Request-Id>
<payload-sha256>
```

The endpoint is `psychologists` or `group-applications`. The canonical path
**excludes `/cabinet`** regardless of deployment. Compute:

```text
X-Signature = lowercase_hex(HMAC-SHA256(signing_text, INTEGRATION_SECRET))
```

For JSON, `payload-sha256` is lowercase hexadecimal SHA-256 of the exact raw JSON
body bytes. Serialize once, sign those bytes, send the same bytes.

For multipart, it is SHA-256 of the exact string value of the `payload` form
field. Every uploaded file is bound by its signed descriptor. The raw multipart
body, MIME boundary and multipart filename/headers are **not signed**. Keep
standard PHP/FPM parsing enabled (`enable_post_data_reading=1`). No custom parser
or proxy body-signing module is needed.

A retry can use a fresh timestamp and regenerated signature; preserve its request
ID and logical content. Endpoint, timestamp, ID and payload digest are all bound
by HMAC. The server compares signatures using `hash_equals`.

## Psychologist questionnaire

Send exactly one scalar form field, `payload`, containing a JSON object:

```json
{"questionnaire":{"email":"synthetic@example.test","personal_data_consent_at":"2026-09-21T12:00:00Z","personal_data_consent_version":"synthetic-v1"},"documents":[]}
```

Use UTF-8 JSON. All questionnaire values belong under `questionnaire`; unsigned
form fields and unknown questionnaire fields are rejected. This is a **full
submission**, not PATCH: omitted optional values become null, including optional
booleans. Send the complete current questionnaire on resubmission.

| Questionnaire field | Type and bounds |
|---|---|
| `email` | Required valid email, max 255 characters; trimmed and lowercased. |
| `personal_data_consent_at` | Required UTC timestamp exactly `YYYY-MM-DDTHH:mm:ssZ`; from 1970-01-02 inclusive to 2038-01-19 exclusive. Stored UTC. |
| `personal_data_consent_version` | Required nonempty text, max 255. |
| `last_name`, `first_name`, `middle_name`, `phone` | Optional nullable text, max 255 each. Questionnaire phone is not required to satisfy the participant-phone rule. |
| `education_type_code` | Optional nullable stable dictionary item code, max 255. See mapping below. |
| `other_education`, `modality_program`, `training_center`, `license_number` | Optional nullable text, max 255 each. |
| `graduation_year` | Optional nullable integer, 0–65535. |
| `training_hours`, `groups_conducted_count` | Optional nullable integer, 0–4294967295. |
| `license_expires_at` | Optional nullable date `YYYY-MM-DD`. |
| `group_leading_experience` | Optional nullable text, max 16000. |
| `documents_confirmed`, `education_confirmed`, `live_session_ready` | Optional nullable booleans; JSON true/false preferred (0/1 also accepted). |

Nullable text is trimmed; an empty string becomes null. Lifecycle, tariff,
access, password, `id`, `education_type_id` and other internal fields are rejected.

`education_type_code` maps to an item in the cabinet's `education_type`
dictionary. Obtain approved codes from the cabinet administrator; no production
codes are invented or seeded by this integration. New selections must be active.
A repeated pending/rejected questionnaire may retain its current inactive code.
Unknown codes and codes belonging only to another dictionary return 422. Never
send the dictionary's numeric ID.

`documents` is a required JSON array outside `questionnaire`; an empty array is
allowed. Each document has a separate, flat multipart file field, e.g.
`document_0`, declared exactly once:

```json
{
  "field": "document_0",
  "type": "diploma",
  "original_name": "synthetic-diploma.pdf",
  "size": 12345,
  "sha256": "<64 lowercase hexadecimal characters: SHA-256 of actual file bytes>"
}
```

Descriptor fields are required. `field` matches `document_[0-9]+`; no nested
file arrays. Each descriptor must have one uploaded file, and every file must
have one descriptor. Duplicate descriptors, missing or undeclared files and
mismatched byte length/hash are rejected before business mutation. Allowed
`type`: `diploma`, `certificate`, `license`, `registration`. Maximum original name
length: 255 characters; the server removes path components/control characters.
Only the **signed** original name is authoritative. The server detects actual
content MIME: `application/pdf`, `image/jpeg`, `image/png`; an extension or
client-supplied MIME cannot override it. Default maximum is **10240 KiB (10 MiB)
per file**, configurable by the cabinet administrator. PHP/Nginx total upload
limits also apply (see development documentation).

Files use random private local paths. No public URL or storage path is returned.
Admins use existing protected document routes. Repeated submissions append files;
they do not remove/replace existing documents. Idempotent replay appends nothing.
A failed transaction cleans the files written by that request, including failures
after multiple uploads or during journal completion.

| Existing normalized email | Result |
|---|---|
| No matching active/deleted user | 201: create pending, non-admin, enabled, `free=false`, password null. |
| Pending | 200: replace questionnaire, append documents. |
| Rejected | 200: replace questionnaire, transition to pending, append documents. |
| Approved | 409 `psychologist_conflict`; unchanged. |
| Disabled | 409 `psychologist_conflict`; unchanged. |
| Any soft-deleted match | 409 `psychologist_conflict`; no restore/new row. |

Repeat submission preserves access, tariff, admin and password fields. No mail or
password token is generated, including later admin approval in Stage 11.

Example success (201 or 200):

```json
{"data":{"status":"pending"},"request_id":"example-questionnaire-001"}
```

## Participant application

Send a JSON object with **only** these four required fields:

```json
{"group_uuid":"11111111-1111-4111-8111-111111111111","last_name":"Synthetic","first_name":"Participant","phone":"+1 (202) 555-0100"}
```

Use a real cabinet `public_uuid` for your synthetic test group; the UUID above is
an illustrative placeholder. Names and phone are trimmed, nonempty strings up
to 255 characters. Phone must contain an explicit international prefix `+` or
`00`, followed by 7–15 digits with a nonzero first digit. Spaces, parentheses,
periods and hyphens are allowed and removed in the normalized phone. No country
code is inferred. Original display formatting is retained on first submission.

The public-site administrator copies **ID группы для gruppa.info** from the
cabinet's group page into the public site's `cabinet_group_uuid` field (or its
equivalent). That field should be nullable before linking and unique when filled.
Send it as `group_uuid`. The cabinet looks up only immutable `public_uuid`, checks
`status=active` and `disabled=false`, and derives ownership from the matched
group's `owner_id`. **Never send `psychologist_id`, `owner_id`, `group_id`, IDs,
status or processed flags**; unknown fields produce 422.

Unknown/deleted UUID: 404 `group_not_found`. Non-active or disabled group: 422
`group_not_accepting_applications`. Success: 201, one unprocessed application,
immediately visible in the existing owner list/counters and admin list. Other
psychologists cannot access it. The group lifecycle is unchanged.

```json
{"data":{"status":"accepted"},"request_id":"example-application-001"}
```

## Idempotency and retries

The MySQL journal has a globally unique, case-sensitive request ID. Its semantic
fingerprint includes the endpoint and normalized validated fields. Documents
include type, sanitized original name, verified size and hash, sorted independently
of multipart order/field names. JSON formatting and multipart boundaries do not
change the fingerprint; participant phone display punctuation is normalized.

A completed same-ID/same-fingerprint request returns the **exact original status
and JSON body**, without rerunning business logic. This remains true if business
state has changed since acceptance. Same ID with a different endpoint or semantic
content returns 409 `idempotency_conflict`. Concurrent duplicates have one effect.
Business mutation and journal completion share one transaction. Failed transactions
leave no completed/poisoned entry. The journal contains no submitted payload,
PII, file bytes, secret or signature; success responses contain only status and ID.
No automatic journal expiry is configured in Stage 11.

Persist the ID and logical submission on the public-site backend before sending.
On a timeout, transport failure, 429 or 5xx, retry with backoff, the **same ID**,
fresh timestamp/signature and identical logical fields/files. Use a new ID for an
intentional new submission or corrected payload. Separate request IDs create
separate participant applications even with identical names/phone. A repeated
questionnaire with a new ID follows the email matrix and can append documents.
Fix 4xx contract/business errors before retrying. Preserve the original submission
when resolving an ambiguous timeout; do not generate a new ID just to bypass 409.

## Errors

All Laravel `/api/v1/*` errors use JSON, without login redirects or HTML:

```json
{"code":"validation_failed","message":"Validation failed.","errors":{"phone":["An explicit international phone is required."]},"request_id":"example-application-001"}
```

`errors` appears for validation when available; `request_id` is null if missing
or syntactically unsafe. Messages are informational; branch on `code` and status.

| HTTP | Codes |
|---|---|
| 400 | `missing_request_id`, `invalid_request_id` |
| 401 | `missing_timestamp`, `invalid_timestamp`, `expired_timestamp`, `missing_signature`, `invalid_signature` (also file integrity failures) |
| 403 | `source_not_allowed` |
| 404 | `group_not_found`, `not_found` (unknown API route) |
| 405 | `method_not_allowed` |
| 409 | `idempotency_conflict`, `psychologist_conflict` |
| 413 | `payload_too_large` (PHP/Laravel upload limit) |
| 415 | `unsupported_media_type` |
| 422 | `validation_failed`, `group_not_accepting_applications` |
| 429 | `rate_limited` |
| 500 | `internal_error`; no stack, SQL or internal message exposed |
| 503 | `integration_unavailable` when secret is absent |

An upstream proxy can reject requests before Laravel (e.g. total body size);
handle non-JSON transport failures as well. Do not interpret those as acceptance.

## Signing and curl examples

This Python standard-library helper writes only the signature to stdout. Keep it
on the public-site **server**, never in browser code. `payload.bin` is either the
exact JSON body or exact manifest string. Do not pretty-print/change it after
signing. Supply `INTEGRATION_SECRET` securely in the process environment.

```python
import hashlib, hmac, os, pathlib
payload = pathlib.Path('payload.bin').read_bytes()
envelope = '\n'.join([
    'v1', 'POST', '/api/v1/' + os.environ['ENDPOINT'],
    os.environ['TIMESTAMP'], os.environ['REQUEST_ID'],
    hashlib.sha256(payload).hexdigest(),
]).encode('utf-8')
print(hmac.new(os.environ['INTEGRATION_SECRET'].encode('utf-8'), envelope,
               hashlib.sha256).hexdigest())
```

For each file, before serializing a multipart manifest:

```python
content = pathlib.Path('synthetic.pdf').read_bytes()
descriptor = dict(field='document_0', type='diploma',
                  original_name='synthetic.pdf', size=len(content),
                  sha256=hashlib.sha256(content).hexdigest())
```

Assuming you saved the signing helper as `sign.py`, set a local synthetic secret
interactively (same value temporarily configured on the cabinet; do not commit it):

```bash
read -rsp 'Local synthetic integration secret: ' INTEGRATION_SECRET; echo
export INTEGRATION_SECRET
export ENDPOINT=group-applications REQUEST_ID=example-application-001
export TIMESTAMP="$(date +%s)"
SIGNATURE="$(python3 sign.py)"
curl --silent --show-error --include \
  'http://localhost:8080/cabinet/api/v1/group-applications' \
  -H 'Content-Type: application/json' \
  -H "X-Request-Id: $REQUEST_ID" -H "X-Timestamp: $TIMESTAMP" \
  -H "X-Signature: $SIGNATURE" --data-binary @payload.bin
```

For multipart, put the manifest in `payload.bin`, change `ENDPOINT=psychologists`,
choose/preserve the appropriate request ID and regenerate timestamp/signature:

```bash
curl --silent --show-error --include \
  'http://localhost:8080/cabinet/api/v1/psychologists' \
  -H "X-Request-Id: $REQUEST_ID" -H "X-Timestamp: $TIMESTAMP" \
  -H "X-Signature: $SIGNATURE" \
  --form 'payload=<payload.bin' \
  --form 'document_0=@synthetic.pdf;type=application/pdf'
```

For no documents, omit the file part and use `"documents":[]` in the signed
manifest. Let curl create its boundary. A retry may create a new boundary.

## Operations

`INTEGRATION_SECRET` is server-only, env/config-backed, with no committed default.
An empty secret fails closed. Config defaults:

- `INTEGRATION_TIMESTAMP_TOLERANCE=300` seconds; synchronize both server clocks.
- `INTEGRATION_RATE_PER_MINUTE=60` per source IP **and endpoint**, including failed
  authentication. Uses Laravel's configured shared cache; provision a common
  cache for multiple application instances. Requests over the limit return 429.
- `INTEGRATION_ALLOWED_IPS=` disables allowlisting. Otherwise provide comma-separated
  exact IP addresses (no CIDR); requests outside the list return 403.

IP checks use Laravel's trusted request IP. Configure trusted proxies correctly
before using proxy-forwarded IPs; do not trust arbitrary forwarded headers. No
production IP is hardcoded. Never put PII/secrets in request IDs. Application
rejection logs contain only endpoint, validated ID, reason, IP, UTC time and
exception class/source location. Exception messages, SQL bindings and trace
arguments are excluded; class/location and request ID support diagnosis.

External work: implement signing/retries and durable outbound IDs on the public
site, agree actual dictionary codes, store/link `cabinet_group_uuid`, provision
secret/HTTPS/allowlist/proxy settings, and run a coordinated staging test. Public
site code, SMTP, password setup and WEBPAY are outside Stage 11.
