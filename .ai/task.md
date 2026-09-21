# Task: TASK-2026-09-21-10

Status: planned
Created from: ccd546b0a484e085345aa8ddd30bc00123d05288 (main)

## Title

Stage 11 — Implement signed incoming API integration for psychologist questionnaires and group applications

## Goal

Implement Stage 11 from SPEC.md: the cabinet becomes the secure server-to-server receiving side for the existing public site `https://gruppa.info/`.

Add two versioned incoming API flows:

1. psychologist questionnaire submission;
2. participant application submission for a published group.

Both flows must use the same integration security boundary:

- HMAC-SHA256 over a canonical signed envelope; JSON signs a raw-body digest, while multipart signs a JSON manifest that cryptographically binds every uploaded file by SHA-256;
- `X-Timestamp` with a ±5 minute acceptance window;
- constant-time signature comparison with `hash_equals`;
- required `X-Request-Id`;
- durable MySQL idempotency;
- rate limiting;
- unified JSON errors;
- PII-safe technical logging;
- no browser/session/CSRF authentication.

The API must integrate with already accepted Stages 5 and 10:

- incoming psychologist questionnaires appear in the existing admin psychologist workflow;
- incoming group applications appear immediately in the existing owner/admin Stage 10 application UI and counters.

Do not implement Stage 12 email/password onboarding or any WEBPAY behavior.

## Current Base

Use the current main HEAD exactly:

`ccd546b0a484e085345aa8ddd30bc00123d05288`

This includes the accepted Stage 1–10 backend and the latest Stage 10 UI/design corrections.

Do not revert or overwrite the current UI baseline.

## Facts

- Laravel 12 / PHP 8.2+ / MySQL.
- Application production base path is `/cabinet`.
- API production base is `https://gruppa.info/cabinet/api/v1/...`.
- Current bootstrap registers web + console routes only; no public API route file exists yet.
- Current public/site integration endpoints do not exist.
- `gp_users` already stores the full questionnaire, consent, lifecycle/access/tariff fields and soft delete.
- Active email uniqueness is already enforced by generated `active_email`.
- Existing repeat-email rules are defined in SPEC §5.
- `gp_user_documents` and `PsychologistDocuments` already provide private storage, MIME validation conventions and safe filenames.
- Document types are:
  - diploma;
  - certificate;
  - license;
  - registration.
- Existing private file MIME allowlist is PDF/JPEG/PNG and max size comes from `config/psychologist_documents.php`.
- `education_type` is an existing dictionary whose item codes are stable.
- `gp_groups.public_uuid` is immutable and unique.
- Stage 10 already implements real group applications, owner/admin access, counters, process/unprocess and phone normalization/search.
- `PhoneNormalizer` already exists and must be reused for participant phone storage.
- Group applications may only be accepted for `status=active` and `disabled=false`.
- The public site must not submit `psychologist_id` or `owner_id` for a group application.
- Stage 12 owns approval email/password setup; Stage 11 sends no email.
- Current UI must remain the design baseline; this task is API/backend integration, not another UI redesign.

## Architecture Correction — PHP 8.2/FPM Multipart Signing

This section is authoritative and replaces any earlier requirement to HMAC the raw serialized multipart body.

The implementation was blocked before code changes because standard PHP 8.2 FPM consumes `POST multipart/form-data` before userland when `enable_post_data_reading=1`: Laravel receives parsed fields/files, but `php://input` is not available for verifying the original multipart bytes.

Production decision:

- keep standard PHP/FPM multipart handling;
- keep `enable_post_data_reading=1`;
- do NOT add a custom multipart parser;
- do NOT require an Nginx/OpenResty body-HMAC module;
- do NOT upgrade PHP solely for this task;
- do NOT disable automatic POST parsing for the whole cabinet;
- questionnaire multipart integrity is provided by a signed JSON manifest plus verified file hashes.

This preserves the existing admin multipart upload behavior and remains compatible with PHP 8.2.32 FPM.

### Canonical signing envelope

Both Stage 11 endpoints use this exact signing string, UTF-8/ASCII, with LF (`\n`) separators and no trailing newline:

```text
v1
POST
/api/v1/<endpoint>
<X-Timestamp>
<X-Request-Id>
<payload-sha256>
```

Then:

```text
X-Signature = lowercase_hex(HMAC-SHA256(signing_string, INTEGRATION_SECRET))
```

The canonical path intentionally excludes the deployment prefix `/cabinet` so the same contract works in local/staging/production. It is exactly one of:

- `/api/v1/psychologists`
- `/api/v1/group-applications`

Because timestamp, request ID and endpoint are inside the HMAC, an intercepted signed payload cannot be replayed with a fresh timestamp/request ID or against another endpoint.

### JSON application payload digest

For `POST /api/v1/group-applications`:

`payload-sha256 = lowercase_hex(SHA-256(EXACT_RAW_JSON_BODY_BYTES))`

The server may read `php://input` / Laravel raw content because this request is `application/json`.

### Multipart psychologist payload digest

For `POST /api/v1/psychologists` keep normal PHP multipart parsing.

The multipart request contains:

- exactly one scalar form field named `payload`;
- zero or more file parts whose field names are declared inside the signed payload manifest.

`payload` is a compact UTF-8 JSON string. The exact string value of that form field is signed:

`payload-sha256 = lowercase_hex(SHA-256(EXACT_PAYLOAD_FIELD_BYTES))`

The signed JSON manifest has this logical shape:

```json
{
  "questionnaire": {
    "last_name": "Synthetic",
    "first_name": "Person",
    "email": "synthetic@example.test",
    "education_type_code": "example_code",
    "...": "..."
  },
  "documents": [
    {
      "field": "document_0",
      "type": "diploma",
      "original_name": "diploma.pdf",
      "size": 12345,
      "sha256": "<lowercase hex SHA-256 of file bytes>"
    }
  ]
}
```

Rules:

- all questionnaire scalar data lives inside `payload.questionnaire`; no duplicate unsigned questionnaire form fields;
- every uploaded file must have exactly one signed descriptor;
- every descriptor must point to exactly one multipart file field;
- reject undeclared file parts;
- reject missing declared files;
- reject duplicate descriptor field names;
- recompute SHA-256 and byte size from the uploaded temporary file and compare to the signed descriptor;
- validate the document type from the signed descriptor;
- use the signed `original_name` only after existing safe-name sanitization; do not trust multipart filename metadata as authoritative;
- MIME is still detected server-side from actual file content and is not trusted from the manifest;
- tampered file/type/name/size/hash causes authentication/integrity rejection before business mutation;
- raw serialized multipart boundaries/headers are intentionally NOT part of the signature.

This is the production contract to document for the public-site developer.

### Semantic idempotency

The idempotency fingerprint remains semantic, not transport-specific.

For JSON:
- normalized validated application fields + endpoint.

For multipart:
- normalized validated questionnaire fields;
- each signed document descriptor using type, safe original name, size and verified SHA-256;
- endpoint.

Therefore a retry may use a new multipart boundary and still replay the original response when the logical request is identical.

## Architecture Decisions

### API endpoints

Use:

- `POST /api/v1/psychologists`
- `POST /api/v1/group-applications`

Full production URLs:

- `https://gruppa.info/cabinet/api/v1/psychologists`
- `https://gruppa.info/cabinet/api/v1/group-applications`

The psychologist route is the stable Stage 11 contract because SPEC requires a versioned psychologist endpoint but does not name the path.

### Stateless route boundary

Add a dedicated `routes/api.php` and register it through Laravel routing.

Requirements:

- stateless API middleware;
- no session authentication;
- no CSRF;
- no web login redirect;
- no HTML error responses;
- no prototype/local behavior mixed into the API.

Use the normal Laravel `api` prefix plus a route-level `v1` prefix so the application path resolves as `/cabinet/api/v1/...`.

### Integration configuration

Add `config/integration.php` with environment-backed values:

- `INTEGRATION_SECRET`;
- timestamp tolerance, default 300 seconds;
- rate limit per minute, choose/document a conservative technical default (e.g. 60) and keep it configurable;
- optional source IP allowlist.

Add placeholders/defaults only to `.env.example`; never commit a real secret.

The application must fail closed for signed integration routes when no secret is configured outside explicit testing overrides.

### HMAC implementation boundary

Implement the canonical signing contract defined in the authoritative “Architecture Correction — PHP 8.2/FPM Multipart Signing” section above.

Do not reintroduce raw serialized multipart-body verification.

Implementation requirements:

- JSON endpoint computes SHA-256 from exact raw request bytes;
- multipart endpoint computes SHA-256 from the exact parsed `payload` form-field string;
- canonical signing string binds version, method, endpoint path, timestamp, request ID and payload digest;
- expected/provided HMAC values are compared using `hash_equals`;
- malformed/missing signature is rejected;
- full signatures are never logged;
- file descriptors in signed multipart payload are verified against actual uploaded file bytes before business mutation.

### Required request ID

`X-Request-Id`:

- required;
- opaque string;
- max 128 chars;
- allow a practical safe character set such as UUID/ULID/token characters;
- never derive business meaning from it.

Missing/invalid request id returns a unified protocol error.

### Durable idempotency

Use MySQL, not cache, for authoritative idempotency.

Add a migration/model for an integration request journal, recommended table:

`gp_integration_requests`

Minimum fields:

- id;
- request_id — unique;
- endpoint/action code;
- request_fingerprint — SHA-256;
- response_status;
- response_body JSON/text suitable for exact replay;
- completed_at;
- timestamps.

Do not store the request body, questionnaire fields, file contents, HMAC secret or signature.

Behavior:

1. Authentication/timestamp protocol validation happens before business execution.
2. Build a semantic request fingerprint after parsing:
   - endpoint code;
   - normalized scalar request values;
   - for every uploaded file: document type + SHA-256 file-content hash;
   - no file bytes are persisted in the journal.
3. First authenticated request claims/processes the request ID.
4. Business mutation and final idempotency response must be coordinated transactionally.
5. A later request with the same request ID + same endpoint + same semantic fingerprint returns the original HTTP status/body without executing business effects again.
6. Same request ID with a different endpoint or fingerprint returns HTTP 409 `idempotency_conflict`.
7. Concurrent duplicate requests must create exactly one business effect.
8. A failed transaction must not permanently poison the request ID as completed.
9. Do not use process-local locks as the authoritative guarantee.

For multipart retries, semantic fingerprinting must not depend on the random MIME boundary; identical logical fields/files with a newly serialized boundary must still match.

### Unified JSON response/error envelope

Use JSON for all API responses.

Success should use a stable shape, for example:

`{"data": {...}, "request_id": "..."}`

Errors:

`{"code": "...", "message": "...", "errors": {...optional...}, "request_id": "...optional..."}`

Do not expose stack traces, SQL, filesystem paths or internal exception messages.

Stable error codes must exist for at least:

- missing_request_id;
- invalid_request_id;
- missing_timestamp;
- invalid_timestamp;
- expired_timestamp;
- missing_signature;
- invalid_signature;
- rate_limited;
- validation_failed;
- idempotency_conflict;
- psychologist_conflict;
- group_not_found;
- group_not_accepting_applications;
- internal_error.

Use correct HTTP semantics:

- 400 for malformed protocol headers/request ID;
- 401 for invalid/missing/stale signed authentication;
- 404 unknown group UUID;
- 409 idempotency/business conflict;
- 422 validated business/input rejection;
- 429 rate limit;
- 500 only for genuine unexpected failures.

### Rate limiting

Define a dedicated limiter for Stage 11 integration endpoints.

Requirements:

- configured limit;
- keyed at minimum by source IP and endpoint;
- response uses the same JSON error envelope;
- log rejection using safe technical context only;
- do not use request payload content as a rate-limit key.

### Safe logging

Follow SPEC §4.10.

For rejected integration requests log only:

- endpoint/action;
- request ID if syntactically available;
- reason/error code;
- source IP;
- timestamp/technical context needed for diagnosis.

Never log:

- full request body;
- questionnaire values;
- participant names/phone;
- uploaded document metadata beyond non-PII technical count if necessary;
- file content;
- integration secret;
- full signature;
- password/reset tokens.

Approved/disabled/deleted email conflicts may be logged with a safe internal user ID and technical code; do not log the email itself unless existing production logging policy explicitly requires it (default: do not).

### Optional IP allowlist

Support a configurable allowlist if `INTEGRATION_ALLOWED_IPS` is set.

- empty config = do not enforce IP allowlist;
- non-empty = reject outside addresses;
- use trusted Laravel request IP handling;
- document proxy/trusted-proxy prerequisite;
- do not hardcode current public-site IPs.

## Scope

### 1. API routing and middleware

Add:

- `routes/api.php`;
- API v1 route group;
- dedicated integration HMAC/timestamp/request-id middleware or equivalent cohesive layer;
- dedicated rate limiter;
- optional IP allowlist middleware/check;
- unified exception/error rendering for these API routes.

Do not alter web auth behavior.

### 2. Integration request journal migration/model

Add the durable idempotency table/model described above.

Indexes:

- unique request_id;
- endpoint/action if useful operationally;
- created_at/completed_at as useful for maintenance.

No PII payload column.

A cleanup policy for this small journal is not required in Stage 11 unless trivially safe; document that retention can be added later. Do not silently delete active idempotency history on a short schedule.

### 3. Psychologist questionnaire endpoint

Implement:

`POST /api/v1/psychologists`

Content type:

- `multipart/form-data`, using the signed `payload` JSON manifest defined above because documents may be included.

The multipart body itself contains no unsigned questionnaire fields other than the single signed `payload` string and its declared file parts.

Accepted `payload.questionnaire` fields:

- last_name;
- first_name;
- middle_name;
- phone;
- email;
- education_type_code;
- other_education;
- modality_program;
- training_center;
- graduation_year;
- training_hours;
- license_number;
- license_expires_at;
- group_leading_experience;
- groups_conducted_count;
- documents_confirmed;
- education_confirmed;
- live_session_ready;
- personal_data_consent_at;
- personal_data_consent_version;
- documents.

Do NOT accept writable internal fields:

- id;
- status;
- accept;
- disabled;
- free;
- admin;
- password;
- remember_token;
- deleted_at;
- education_type_id.

Unexpected protected/internal fields should be rejected, not silently applied.

### 4. Questionnaire validation

Do not invent stricter product requirements than SPEC.

Required for incoming public questionnaire:

- email;
- personal_data_consent_at;
- personal_data_consent_version.

Other questionnaire fields remain nullable unless technical type/range validation applies.

Normalize:

- email = lower-case + trim;
- nullable text = trimmed;
- integer/date/boolean fields through explicit validation;
- consent timestamp interpreted according to the documented public contract and stored UTC.

Use the same technical bounds as existing admin questionnaire validation.

#### Education type

External contract uses `education_type_code`, not an internal DB ID.

Resolve only against dictionary `education_type`.

For a new user:
- supplied code must be active.

For repeat pending/rejected submission:
- active codes accepted;
- the currently selected inactive item may remain valid if the same code is re-submitted, matching existing cabinet behavior.

Unknown/wrong-dictionary code -> 422.

Store the resolved internal `education_type_id`; never expose that requirement to the public site.

### 5. Questionnaire full-submission semantics

Treat the POST as one full questionnaire submission, not a PATCH.

Build an explicit whitelist map of all questionnaire fields.

- fields omitted from the nullable questionnaire contract may become null;
- booleans use explicit validated values;
- internal lifecycle/access/tariff fields are never copied from request input.

Document these semantics so the public site sends the complete current questionnaire on retry/resubmission.

### 6. New psychologist behavior

If no active or soft-deleted conflict exists for email:

create one `gp_users` row:

- status=pending;
- admin=false;
- disabled=false;
- free=false (DB/business default; tariff remains an admin decision);
- password=null;
- questionnaire + consent from request.

Return HTTP 201.

No email is sent.

No password token is created.

The user must immediately appear in the existing admin psychologist list/filter as pending.

### 7. Repeat email matrix

Implement SPEC §5 exactly.

Resolve conflicts under transaction/row lock, including withTrashed lookup.

#### Existing pending

- update questionnaire fields;
- keep status pending;
- keep tariff/access/internal fields unchanged;
- append newly uploaded documents;
- HTTP 200.

#### Existing rejected

- update questionnaire;
- transition rejected -> pending through `UserStatusTransitionService` or existing domain transition boundary;
- keep tariff/access/internal fields unchanged;
- append new documents;
- HTTP 200.

#### Existing approved

- no mutation;
- no new documents;
- HTTP 409 `psychologist_conflict`.

#### Existing disabled=true

- no mutation regardless of status;
- HTTP 409.

#### Soft-deleted matching email

- do not restore;
- do not create a new row automatically;
- no document mutation;
- HTTP 409.

Concurrent same-email submissions with different request IDs must still preserve the single-active-email invariant and return deterministic success/conflict rather than 500 duplicate-key leakage.

Do not allow repeat questionnaire to change `free`, `disabled`, `admin`, password, or approved status.

### 8. Multipart documents

Use the signed-manifest multipart contract from the authoritative architecture correction.

Multipart transport:

- scalar field: `payload` (JSON string);
- file fields: flat names such as `document_0`, `document_1`, each declared in `payload.documents`.

Each signed descriptor contains:

- field;
- type;
- original_name;
- size;
- sha256.

Allowed type values are the existing stable config keys:

- diploma;
- certificate;
- license;
- registration.

Validation must reuse the existing size/MIME policy:

- PDF;
- JPEG;
- PNG;
- max KB from config.

Integrity/security:

- signed descriptor hash and size must match actual uploaded bytes;
- content MIME is detected server-side, not trusted from extension/manifest/multipart headers;
- private local storage only;
- random path;
- original name comes from the signed descriptor and then passes existing safe-name sanitization;
- never expose a public URL;
- no binary/file content in logs/idempotency journal;
- reject undeclared file parts and missing/duplicate descriptors.

Behavior:

- zero documents is allowed unless SPEC/public form requires otherwise;
- repeat pending/rejected submissions append new documents;
- existing documents are never removed/replaced by this endpoint.

Atomicity/file cleanup:

- if any DB/business/idempotency operation fails, no orphan private file from that request may remain;
- if storing one of multiple files fails, clean up all files created by the failed API request and roll back DB changes;
- duplicate idempotent replay must not write the same document twice.

Reuse/extend `PsychologistDocuments` rather than creating a second incompatible storage policy.

### 9. Participant application endpoint

Implement exactly:

`POST /api/v1/group-applications`

Prefer JSON request body for this endpoint.

Accepted fields only:

- group_uuid;
- last_name;
- first_name;
- phone.

Explicitly reject/ignore as validation error attempts to provide:

- psychologist_id;
- owner_id;
- group_id;
- processed_at;
- any internal application id/status field.

### 10. Application validation / group lookup

Validation:

- group_uuid required UUID;
- last_name required string with reasonable existing DB max;
- first_name required string;
- phone required and accepted by existing `PhoneNormalizer`.

Lookup:

- query by `gp_groups.public_uuid = group_uuid`;
- do not query by internal group id;
- soft-deleted group behaves as unknown -> 404;
- unknown UUID -> 404 `group_not_found`;
- found but status != active -> 422 `group_not_accepting_applications`;
- found but disabled=true -> 422 same stable business code.

Do not accept psychologist ID from the caller.

The owner is always derived internally via `group.owner_id`.

### 11. Application creation

On a valid active/enabled group:

- create `gp_group_applications.group_id` from the matched internal group;
- store last_name/first_name;
- store original display phone as appropriate;
- store `phone_normalized` using existing `PhoneNormalizer`;
- processed_at=null.

Return HTTP 201.

No email/job/payment/group transition.

Immediately prove:

- psychologist owner sees the new application in Stage 10 list/detail;
- owner group counters increase;
- admin application list sees it;
- a different psychologist cannot access it.

### 12. Idempotent application behavior

Repeated same `X-Request-Id` + same semantic request returns the original 201 JSON and creates exactly one application.

Concurrent duplicates create exactly one application.

Different `X-Request-Id` values are distinct submissions even if participant fields happen to match; Stage 11 does not invent participant de-duplication business rules beyond request idempotency.

### 13. Unified API validation

Use dedicated Form Requests / DTO-like normalized request classes.

Do not reuse admin HTML FormRequest responses directly.

API validation must produce the unified JSON envelope and stable field errors.

Do not expose internal dictionary IDs or database implementation in validation messages.

### 14. API exception safety

For `/api/v1/*`:

- no HTML 404/419/500;
- no login redirect;
- unexpected exceptions -> safe JSON internal_error;
- in testing/logs the actual exception remains diagnosable server-side without exposing it to caller.

Web error handling must remain unchanged.

### 15. Security headers / transport assumptions

Document:

- production calls must use HTTPS;
- secret is server-only;
- never put secret/signature generation in browser JS;
- same-host does not mean browser-to-cabinet API;
- source is the backend of the public site.

Do not attempt to implement TLS in Laravel.

### 16. Integration documentation

Create `docs/integration.md`.

It must be usable by the developer of the existing public site without reading cabinet source code.

Include:

- architecture/server-to-server flow;
- production base URL;
- local development base URL;
- unknown staging URL marked configurable/not invented;
- required headers;
- timestamp window;
- exact HMAC algorithm;
- exact request-id behavior;
- JSON error envelope;
- endpoint 1 psychologist multipart contract;
- questionnaire field names/types;
- `education_type_code` mapping;
- document multipart naming and allowed types/MIME/size;
- repeat-email matrix;
- endpoint 2 group application JSON contract;
- group_uuid and public-site `cabinet_group_uuid` responsibility;
- explicit statement: never send psychologist_id/owner_id;
- response/status examples;
- error code table;
- idempotent retry instructions;
- multipart signing instructions for the signed `payload` manifest and per-file SHA-256 descriptors; explicitly state that the MIME boundary/raw multipart body is not signed;
- example signing code/pseudocode;
- curl/test examples that do not contain real secrets or personal data;
- operational notes for rate limit/IP allowlist.

### 17. Environment/development documentation

Update `docs/development.md` and `.env.example` for integration config.

Use placeholders only.

Document a local synthetic secret for manual testing as a user-supplied shell env example, not a committed credential.

### 18. Project/UI documentation

Update:

- `docs/project-status.md` — Stage 11 implemented, Stage 12+ pending;
- `docs/ui-pages.md` only if needed to state that no new UI page was introduced and incoming data feeds existing Stage 5/10 screens;
- `docs/architecture.md` — integration middleware/idempotency/service boundaries and safe logging.

Preserve the current redesigned UI. Do not perform visual refactoring in this backend milestone.

## Out Of Scope

Do NOT implement:

- changes to the separate public-site repository/code;
- browser-to-cabinet API calls;
- CORS-based public browser integration;
- password setup emails;
- password reset/setup tokens;
- admin resend setup email;
- expiry warning email/jobs;
- SMTP;
- Stage 12;
- WEBPAY;
- placement payment;
- paid extension;
- refund behavior;
- new application UI;
- new psychologist UI;
- UI redesign;
- production deployment.

Do not send an email when an admin later approves an API-created psychologist; Stage 12 owns that behavior.

## Constraints

- Follow WORKFLOW.md and AGENTS.md.
- Work from current HEAD `ccd546b0a484e085345aa8ddd30bc00123d05288`.
- Keep current Stage 10 design/UI baseline.
- HMAC verification follows the canonical envelope above: raw-body SHA-256 for JSON, signed-manifest SHA-256 + verified file hashes for multipart, and `hash_equals` for the final HMAC.
- Keep production `enable_post_data_reading=1`; no custom multipart parser or special FPM/Nginx body handling.
- Timestamp tolerance defaults to 300 seconds.
- Durable idempotency is MySQL-backed.
- Do not store raw integration payloads in the idempotency journal.
- Do not log PII/files/secrets/signatures.
- Integration secret exists only in env/config.
- Internal IDs are not part of the public group-application contract.
- Public group association is only through immutable `public_uuid`.
- Questionnaire education dictionary is addressed by stable code, not ID.
- Files remain private.
- Money/payment code is untouched.
- Tests use MySQL only.
- No npm/Vite/frontend framework.
- No real participant/psychologist data or real secret in fixtures/docs.
- Do not alter `.ai/task.md`.

## Tests

All integration tests run on the project MySQL testing database.

Cover at minimum:

### Protocol/authentication

- valid canonical signature accepted for JSON;
- valid canonical signature accepted for multipart manifest;
- missing signature;
- invalid signature;
- signature for a different endpoint/timestamp/request-id/payload digest rejected;
- changing multipart file bytes while keeping signed manifest rejected;
- changing signed document type/name/size/hash rejected;
- missing timestamp;
- malformed timestamp;
- timestamp exactly -300/+300 seconds accepted;
- ±301 rejected;
- missing request ID;
- invalid/too-long request ID;
- secret missing fails closed;
- optional allowlist behavior;
- rate limit returns unified 429;
- all rejections JSON, not HTML/redirect;
- safe log context and explicit absence of body/PII/secret/full signature.

### Idempotency

- first request stores completed response;
- exact duplicate returns same status/body;
- duplicate does not rerun business logic;
- same ID different endpoint -> 409;
- same ID different semantic body -> 409;
- multipart duplicate with a different MIME boundary but semantically identical signed payload/files replays successfully;
- concurrent duplicate application creates one row;
- concurrent duplicate psychologist creates/updates once;
- failed business transaction does not leave a completed poisoned idempotency result;
- journal contains no raw payload or file bytes.

### Psychologist intake

- valid new signed multipart -> 201 pending;
- visible in admin pending list;
- internal fields cannot be supplied;
- email trim/lower normalization;
- consent required;
- education_type_code maps correct dictionary;
- wrong/unknown/inactive code rejected;
- same existing inactive education code accepted on repeat;
- valid document types/MIME/size;
- content MIME spoof rejected;
- files private with random paths;
- no public URL;
- zero-doc request works;
- multiple docs work;
- failure cleans newly stored files.

Repeat matrix:
- pending -> update + append docs + 200;
- rejected -> update + domain transition to pending + append docs + 200;
- approved -> 409/no mutation;
- disabled -> 409/no mutation;
- soft-deleted -> 409/no restore/new row;
- repeat never changes free/admin/disabled/password except rejected->pending status rule;
- concurrent same-email submissions do not expose duplicate-key 500.

### Group application intake

- valid active/enabled public_uuid -> 201;
- exact gp_group_applications.group_id is matched group internal id;
- owner derives only from group relationship;
- psychologist_id/owner_id/group_id input rejected;
- phone normalized using existing service;
- unknown UUID -> 404 JSON;
- soft-deleted group -> 404;
- draft/moderation/revision/rejected/approved/expired -> 422;
- disabled active -> 422;
- duplicate request ID creates one application;
- different request IDs can create separate applications;
- Stage 10 owner/admin UI and counters see the created application;
- foreign owner still gets 404/denied through existing cabinet routes.

### Side effects/regression

- no email sent/queued;
- no password tokens created;
- no payment rows;
- no group status transition;
- Stage 4–10 full regression green;
- scheduler definitions unchanged;
- current UI/prototype suite remains green;
- API routes exist in production;
- prototype routes remain production-isolated.

## Runtime / Manual Verification

Using Docker and only synthetic data:

1. Set a temporary local `INTEGRATION_SECRET` through runtime env/config; do not commit it.
2. Create/ensure an active dictionary education_type item.
3. Send a correctly signed manifest-based multipart `POST /cabinet/api/v1/psychologists` using standard PHP/FPM multipart parsing.
4. Verify pending psychologist appears in real admin UI and private documents open through existing protected admin route.
5. Retry same logical multipart with the same request ID and confirm no duplicate user/document.
6. Exercise pending/rejected repeat behavior.
7. Verify approved/disabled/deleted conflict responses.
8. Identify/create a synthetic active group and copy its real public_uuid.
9. Send signed JSON group application using that UUID.
10. Verify it appears in real owner group application list/counter and admin application list.
11. Retry same request ID -> no duplicate.
12. Send unknown/inactive/disabled group cases.
13. Send bad signature/stale timestamp/missing request-id/rate-limit cases.
14. Inspect application logs and journal rows for absence of PII/raw payload/secrets.
15. Confirm no mail/jobs/payments/unrelated lifecycle effects.

Do not modify the separate public site as part of this repository task.

## Required Checks

Run and report exact results:

1. `docker compose ps`
2. non-destructive migrate/seed
3. focused Stage 11 integration tests
4. concurrency/idempotency tests on MySQL
5. route inspection for API/web/prototype boundaries
6. manual signed test-client/curl flow
7. storage/private document inspection
8. log redaction inspection
9. `docker compose exec -T php php artisan test`
10. `docker compose exec -T php ./vendor/bin/pint --test`
11. `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
12. `docker compose exec -T php composer check-platform-reqs`
13. `docker compose exec -T php php artisan view:cache`
14. `git diff --check`
15. inspect final diff/staged files
16. confirm no real secret, PII, uploaded files, logs, screenshots or browser artifacts are staged

## Required .ai/report.md

Include:

- Status;
- exact API routes;
- HMAC/timestamp contract;
- limiter/IP allowlist behavior;
- idempotency table/schema and replay/conflict behavior;
- unified response/error format;
- psychologist create/repeat matrix;
- document storage/rollback behavior;
- group application lookup/owner derivation;
- safe logging evidence;
- no-email/no-WEBPAY evidence;
- migrations/config/docs added;
- exact test/check results;
- manual signed request evidence;
- Facts / Assumptions / Unknowns;
- remaining external work for the public-site developer.

Do not include the integration secret or real payloads in the report.

## Acceptance Criteria

1. `POST /cabinet/api/v1/psychologists` exists in production.
2. `POST /cabinet/api/v1/group-applications` exists in production.
3. API routes are stateless and do not use web session/CSRF auth.
3a. Standard PHP 8.2 FPM multipart parsing remains enabled (`enable_post_data_reading=1`); no custom raw multipart parser/proxy module is required in production.
4. Valid canonical HMAC-SHA256 signature is required: raw JSON body digest for group applications; signed JSON manifest plus verified file hashes for psychologist multipart.
5. Signature comparison uses `hash_equals`.
6. Timestamp ±300s boundary behavior is correct.
7. Required `X-Request-Id` is validated.
8. Dedicated configurable rate limiter works.
9. Optional configured IP allowlist works without hardcoded production IPs.
10. All API failures use the unified JSON envelope.
11. API unexpected exceptions never expose internals.
12. Technical integration logs contain no questionnaire/application payload, PII, files, secrets or full signatures.
13. Durable MySQL idempotency journal exists and stores no raw payload.
14. Duplicate same request ID/request returns original response.
15. Duplicate ID with different semantic request returns 409.
16. Concurrent duplicate requests produce exactly one business effect.
17. New questionnaire creates exactly one pending non-admin enabled paid-default user and sends no email.
18. Public request cannot set lifecycle/access/tariff/password/internal fields.
19. education_type is addressed by stable external code and correctly resolves internally.
20. Valid multipart documents are stored privately using existing policy.
21. Failed multipart transaction leaves no orphan newly uploaded files.
22. Pending repeat updates questionnaire and appends documents.
23. Rejected repeat returns user to pending through the domain transition boundary.
24. Approved repeat is 409 without mutation.
25. Disabled repeat is 409 without mutation.
26. Soft-deleted email is 409 without restore/new user.
27. Repeat submission never changes free/admin/access/password fields.
28. Valid group application uses only group_uuid/public_uuid lookup.
29. Caller cannot choose psychologist/owner/internal group id.
30. Unknown or soft-deleted group UUID returns 404.
31. Non-active or disabled group returns 422.
32. Phone normalization reuses Stage 10 PhoneNormalizer.
33. Valid application appears immediately in existing Stage 10 owner/admin UI and counters.
34. Idempotent application replay creates no duplicate.
35. No Stage 11 flow sends email/queues onboarding mail.
36. No Stage 11 flow creates payment or changes group lifecycle.
37. `docs/integration.md` is sufficient for the public-site developer and includes full production URLs, the canonical HMAC envelope, JSON raw-body digest, manifest-based multipart signing/file hashes, request-id retry, `cabinet_group_uuid`, and no psychologist_id rule.
38. Current redesigned Stage 10 UI is preserved.
39. Stage 4–10 regression remains green.
40. Full MySQL suite passes.
41. Pint passes.
42. Larastan passes.
43. Composer platform check passes.
44. Blade compilation passes.
45. Prototype production isolation remains correct.
46. Final diff is limited to Stage 11 API/security/idempotency/intake/storage/config/tests/docs/report.
47. No secret, real PII or unrelated artifact is committed.

## Hard Workflow Gate

Before changing files:

- read WORKFLOW.md;
- read AGENTS.md;
- read this `.ai/task.md`;
- read SPEC only around §4.10, §5–6, §17–18 and Stage 11;
- read current `docs/project-status.md`;
- inspect current `bootstrap/app.php`, models, `PhoneNormalizer`, `PsychologistDocuments`, dictionary conventions and current Stage 10 application flow;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm base `ccd546b0a484e085345aa8ddd30bc00123d05288`;
- do not overwrite unknown local changes.

During implementation:

- stay strictly in Stage 11;
- do not implement Stage 12 email/password setup;
- do not implement WEBPAY;
- do not redesign UI;
- keep incoming API stateless;
- keep public contract independent from internal database IDs;
- do not log sensitive request data;
- do not edit `.ai/task.md`;
- do not edit SPEC/WORKFLOW/AGENTS.

Before commit:

- run all required automated checks;
- execute real signed synthetic requests;
- inspect idempotency rows/logs/private storage;
- inspect complete diff and staged files;
- remove temporary secrets, uploaded smoke files and artifacts;
- update `.ai/report.md`;
- stage only Stage 11 files + report.

Completion:

- use Status: done only if every acceptance criterion is satisfied;
- otherwise partial / blocked / failed.

If complete, commit with:

`codex: TASK-2026-09-21-10 implement signed incoming integrations`

Do not create an accept commit.
