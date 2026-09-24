# Incoming integration v1

Stage 11 exposes **public submission endpoints**, normally called by the backend
of the existing public site after a browser form submission. Direct callers can
also reach them: origin identity is not authenticated. No shared secret or HMAC
is required, and public-site secrets must not be introduced for these forms.
Validation, rate limiting and optional infrastructure controls mitigate abuse;
they do not prove trusted origin or eliminate spam. Cabinet remains authoritative
for validation and business rules. No session, CSRF or login cookie is required.

Production endpoints:

- `POST https://gruppa.info/cabinet/api/v1/psychologists`
- `POST https://gruppa.info/cabinet/api/v1/group-applications`

Local base: `http://localhost:8080/cabinet/api/v1` (Docker only).
Staging base is not known: configure it when the staging deployment exists.
Production transport must be HTTPS, terminated by the hosting infrastructure.

## Public request protocol

Required headers on every POST:

| Header | Contract |
|---|---|
| `X-Request-Id` | Non-secret idempotency token, **not authentication**. Case-sensitive, 1–128 ASCII characters; first character alphanumeric, `_` or `-`; remaining characters alphanumeric, `_`, `-`, `.`, `:`. |
| `Content-Type` | `application/json` for applications; `multipart/form-data; boundary=...` for questionnaires. |

No query parameters are accepted. `INTEGRATION_SECRET`,
`INTEGRATION_TIMESTAMP_TOLERANCE`, `X-Timestamp`, `X-Signature`, signing text and
client-computed hashes are no longer used. Legacy signature/timestamp headers
are ignored. Preserve the request ID and logical fields/files on retries.

## Psychologist questionnaire

Send exactly one scalar form field, `payload`, containing a JSON object:

```json
{"email":"synthetic@example.test","personal_data_consent_at":"2026-09-21T12:00:00Z","personal_data_consent_version":"synthetic-v1","trainings":[{"modality_program":"Synthetic program","training_center":"Synthetic center","graduation_year":2024,"training_hours":120}]}
```

Use UTF-8 JSON. Questionnaire fields belong directly in this object; additional
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
| `other_education`, `license_number` | Optional nullable text, max 255 each. |
| `groups_conducted_count` | Optional nullable integer, 0–4294967295. |
| `trainings` | Optional JSON list of training objects; omitted or `[]` means no current trainings. No application-level count cap. |
| `license_expires_at` | Optional nullable date `YYYY-MM-DD`. |
| `group_leading_experience` | Optional nullable text, max 16000. |
| `documents_confirmed`, `education_confirmed`, `live_session_ready` | Optional nullable booleans; JSON true/false preferred (0/1 also accepted). |

Each `trainings[N]` is a JSON object with only these optional nullable fields:
`modality_program` and `training_center` (strings, max 255), `graduation_year`
(integer 0–65535), `training_hours` (integer 0–4294967295). At least one meaningful
non-null value is required; zero is meaningful. Whitespace-only text becomes null.
Null/scalar/object collections, array items, empty objects, unknown keys and IDs
are rejected. List order defines zero-based `position`. The four legacy top-level
training fields are rejected as unexpected fields. HTTP/PHP size/input/upload
limits still apply; no arbitrary training-count limit is introduced.

Nullable text is trimmed; an empty string becomes null. Lifecycle, tariff,
access, password, `id`, `education_type_id` and other internal fields are rejected.

`education_type_code` maps to an item in the cabinet's `education_type`
dictionary. Obtain approved codes from the cabinet administrator; no production
codes are invented or seeded by this integration. New selections must be active.
A repeated pending/rejected questionnaire may retain its current inactive code.
Unknown codes and codes belonging only to another dictionary return 422. Never
send the dictionary's numeric ID.

Files are optional: zero uploads are accepted. Use unique flat multipart file fields:

| Field | Stored type |
|---|---|
| `diploma` | `diploma` |
| `certificate_0`, `certificate_1`, ... | `certificate`, linked to `trainings[N]` |
| `license` | `license` |
| `registration` | `registration` |

Certificate indices are nonnegative decimal integers without leading zeros;
gaps in uploaded certificates are allowed only where the corresponding training
exists. `certificate_N` must reference `trainings[N]` in the same request, otherwise
422 `validation_failed`. A training without a certificate is valid. Multipart
file order is irrelevant. Diploma/license/registration have no training link.
Arrays/nested files, unknown fields, invalid patterns and failed
uploads return 422 `validation_failed`. Do not send a `documents` manifest,
`questionnaire` wrapper, size, hash or document descriptors.

The server derives type from the field, sanitizes the actual upload's original
name (removes paths/control characters, limits to 255 characters), detects MIME
from contents and measures bytes. Allowed MIME: `application/pdf`, `image/jpeg`,
`image/png`; client MIME and filename extensions do not override detection.
The default maximum is **10240 KiB (10 MiB) per file**, configurable by the cabinet
administrator. PHP/Nginx total upload limits also apply. SHA-256 is computed only
on the server for semantic idempotency; callers do not compute or send it.

Standard PHP/Laravel multipart parsing is retained by explicit product decision;
no raw multipart parser or infrastructure change is required. Validation applies
at the observable Laravel boundary: unknown file fields, invalid `certificate_N`
names, unexpected arrays/structures and observable duplicate/ambiguous structures
return 422. Every file Laravel actually sees is validated on the server.

Send each field exactly once. Duplicate multipart field names that PHP collapses
before the request reaches Laravel cannot be reliably detected at the application
layer. These raw duplicates, and original names lost during PHP normalization,
are a known parsing limitation, not an application-level rejection guarantee.

Files use random private local paths. No public URL or storage path is returned.
Admins use existing protected document routes. Repeated submissions append files;
they do not remove/replace existing documents. Accepted pending/rejected
resubmissions replace the entire current training collection, including clearing
it when omitted. Removed training IDs cause old certificate links to become null;
new certificates link to the new current list. Unlinked historical certificates
remain usable. Profile/training replacement, uploads and journal completion share
one transaction. Idempotent replay recreates no trainings and appends nothing.
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

Repeat submission preserves access, tariff, admin and password fields. Intake
generates no mail or password token; existing Stage 12 admin approval/mail flows
remain unchanged.

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
to 255 characters. Phone accepts **any scalar string** that is nonempty after
trimming; no international prefix, country code or digit-count syntax is required.
Local values such as `80291234567` and `29 123-45-67`, short numbers and arbitrary
text are accepted. The trimmed submitted value is preserved in `phone`.
`phone_normalized` is only a best-effort search key: phone-like input yields digits
(with a leading international `00` removed); other text yields `''`. No country
code is inferred or prepended. Raw admin phone search remains available.

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
fingerprint includes the endpoint, normalized validated fields, ordered training
values and positions. Documents include type, training position for certificates,
sanitized original name, server-observed size and content hash, sorted independently
of multipart order. JSON formatting, object-key order and multipart boundaries do
not change the fingerprint. Adding/removing/reordering/changing trainings or
swapping certificate contents between training indices causes 409; changing any
diploma/license/registration file also conflicts. Participant phone punctuation is
ignored when a nonempty digit key exists; otherwise the trimmed raw phone is used,
so arbitrary text is accepted without collapsing different text into one request.

A completed same-ID/same-fingerprint request returns the **exact original status
and JSON body**, without rerunning business logic. This remains true if business
state has changed since acceptance. Same ID with a different endpoint or semantic
content returns 409 `idempotency_conflict`. Concurrent duplicates have one effect.
Business mutation and journal completion share one transaction. Failed transactions
leave no completed/poisoned entry. The journal contains no submitted payload,
PII, file bytes, secret or signature; success responses contain only status and ID.
No automatic journal expiry is configured in Stage 11.

Persist the ID and logical submission on the public-site backend before sending.
On a timeout, transport failure, 429 or 5xx, retry with backoff, the **same ID**
and identical logical fields/files. Use a new ID for an intentional new submission or corrected payload. Separate request IDs create
separate participant applications even with identical names/phone. A repeated
questionnaire with a new ID follows the email matrix and can append documents.
Fix 4xx contract/business errors before retrying. Preserve the original submission
when resolving an ambiguous timeout; do not generate a new ID just to bypass 409.

## Errors

All Laravel `/api/v1/*` errors use JSON, without login redirects or HTML:

```json
{"code":"validation_failed","message":"Validation failed.","errors":{"phone":["Invalid or missing value."]},"request_id":"example-application-001"}
```

`errors` appears for validation when available; `request_id` is null if missing
or syntactically unsafe. Messages are informational; branch on `code` and status.

| HTTP | Codes |
|---|---|
| 400 | `missing_request_id`, `invalid_request_id` |
| 403 | `source_not_allowed` |
| 404 | `group_not_found`, `not_found` (unknown API route) |
| 405 | `method_not_allowed` |
| 409 | `idempotency_conflict`, `psychologist_conflict` |
| 413 | `payload_too_large` (PHP/Laravel upload limit) |
| 415 | `unsupported_media_type` |
| 422 | `validation_failed`, `group_not_accepting_applications` |
| 429 | `rate_limited` |
| 500 | `internal_error`; no stack, SQL or internal message exposed |

An upstream proxy can reject requests before Laravel (e.g. total body size);
handle non-JSON transport failures as well. Do not interpret those as acceptance.

## Curl examples

Save the participant JSON shown above in `application.json`, using your synthetic
test group's real UUID. Generate a fresh ID per logical submission; retain it for
retries. No signing helper, secret, timestamp or file digest is needed.

```bash
curl --silent --show-error --include \
  'http://localhost:8080/cabinet/api/v1/group-applications' \
  -H 'Content-Type: application/json' \
  -H 'X-Request-Id: example-application-001' \
  --data-binary @application.json
```

For a psychologist, save the direct questionnaire object in `questionnaire.json`:

```bash
curl --silent --show-error --include \
  'http://localhost:8080/cabinet/api/v1/psychologists' \
  -H 'X-Request-Id: example-questionnaire-001' \
  --form 'payload=<questionnaire.json' \
  --form 'diploma=@synthetic.pdf' \
  --form 'certificate_0=@synthetic-certificate.pdf'
```

Omit file parts for a questionnaire-only submission. Let curl create its multipart
boundary. A retry may use a different boundary with the same logical content.

## Migration and deployment

Apply the additive `2026_09_24_000001_create_user_trainings` migration **before**
serving the new application code, with intake/admin writes quiesced during deployment.
It creates `gp_user_trainings` (unique user/position), adds nullable
`gp_user_documents.user_training_id` with `ON DELETE SET NULL`, and copies each
user's non-null legacy training set to position 0 (including soft-deleted users).
All that user's existing certificates are linked to that sole backfilled training;
certificates for users without legacy data remain unlinked. Users, documents and
private files are retained. Legacy values are copied exactly, including any old
empty strings; new intake/admin rows must contain a meaningful value.

The four old `gp_users` columns remain deprecated compatibility data, never read,
written or synchronized by runtime code. Current training data lives only in
`gp_user_trainings`. Rollback removes the new association/table but preserves
users/documents/files and the original legacy columns; it **cannot** encode new
multiple trainings in those stale columns. Back up/export new training data before
rolling back. Coordinate the external public-site handler's switch to `trainings`;
its implementation is outside this repository. Existing journal hashes are not
rewritten: retries recorded under the previous contract may return 409 after this
fingerprint change. Reconcile in-flight submissions before switching producers;
do not issue a new request ID blindly after an ambiguous prior success.

## Operations

Only non-secret intake settings remain:

- `INTEGRATION_RATE_PER_MINUTE=60` per source IP **and endpoint**, including invalid
  requests. Uses Laravel's configured shared cache; provision a common cache for
  multiple application instances. Requests over the limit return 429.
- `INTEGRATION_ALLOWED_IPS=` disables allowlisting by default. Otherwise provide
  comma-separated exact IP addresses (no CIDR); other sources return 403. This is
  optional and not required for public-form operation.

There is no integration secret to provision, rotate or synchronize, and no need
to clear cached configuration because a public-site secret changed. Normal
application deployment/configuration procedures still apply to code changes.

IP checks use Laravel's trusted request IP. Configure trusted proxies correctly
before using proxy-forwarded IPs; do not trust arbitrary forwarded headers. No
production IP is hardcoded. Never put PII/secrets in request IDs. Application
rejection logs contain only endpoint, validated ID, reason, IP, UTC time and
exception class/source location. Exception messages, SQL bindings and trace
arguments are excluded; class/location and request ID support diagnosis.

External work: implement plain requests/retries and durable outbound IDs on the
public site, agree actual dictionary codes, store/link `cabinet_group_uuid`, set
HTTPS/optional allowlist/proxy settings, and run a coordinated staging test. Public
site code, SMTP, password setup and WEBPAY are outside Stage 11.
