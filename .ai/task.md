# Task: TASK-2026-09-24-02

Status: planned
Created from: cb31df7196c512478da00acf5d8394c04eb0ab0e (main)

## Title

Support multiple psychologist trainings and permissive participant phone intake

## Goal

Align Gruppa Cabinet with the real public-site forms without lossy client-side workarounds.

Two product decisions are explicit:

1. A psychologist may have any number of additional training entries. The public form already sends a repeated `training[]` structure and a certificate for each training. Cabinet must model and accept this as a real one-to-many relationship rather than collapsing it into the current single `modality_program` / `training_center` / `graduation_year` / `training_hours` columns on `gp_users`.
2. A participant group application must not be rejected because a phone number is not in explicit international `+...` / `00...` format. Cabinet must accept any non-empty phone string within the existing length bound and preserve the submitted display value. A normalized/digit search key may remain best-effort only; it must never be an acceptance gate.

Keep the Stage 11 public, unsigned intake architecture from TASK-2026-09-24-01. Do not reintroduce a shared secret, HMAC, timestamp/signature headers or client-side file hashes.

Do not modify WEBPAY trust/signing, login/session authentication, mail/password setup, group lifecycle or public-site code in this repository.

## Facts

- Current HEAD is `cb31df7196c512478da00acf5d8394c04eb0ab0e`.
- Stage 11 currently exposes:
  - `POST /api/v1/psychologists` as multipart/form-data with one scalar `payload` JSON object plus flat files;
  - `POST /api/v1/group-applications` as JSON.
- `X-Request-Id` is the required non-secret idempotency key.
- Current `IntakeData::psychologist` stores only one top-level training set:
  - `modality_program`;
  - `training_center`;
  - `graduation_year`;
  - `training_hours`.
- Those four fields currently live directly on `gp_users` and are also used by the admin psychologist form, the shared profile presenter and profile/detail views.
- The real public psychologist form supports repeated `training[]` entries and one uploaded certificate per training.
- Current intake already accepts flat `certificate_0`, `certificate_1`, ... file fields, but they are only typed as generic `certificate` documents; there is no persistent training association.
- `gp_user_documents` currently belongs only to the user and has no training foreign key.
- Current `IntakeData::application` rejects participant phones unless `PhoneNormalizer::normalizeForStorage()` can turn them into explicit international format.
- Production testing confirmed a local Belarusian-style phone without `+` is rejected with HTTP 422, while `+375...` succeeds.
- `gp_group_applications.phone` stores the submitted display value and `phone_normalized` is a required string used as a technical search key.
- Existing admin application search already searches both raw `phone` and `phone_normalized`.
- Existing Stage 11 repeat-email conflict rules, private file storage, integration journal, rate limiting, optional IP allowlist and business validation are already implemented.
- The public-site handler is outside this repository and will be updated separately after Cabinet accepts this contract.

## Assumptions

- The public-site handler can send `trainings` as a JSON list inside the existing psychologist `payload`.
- The list order is meaningful and becomes the training display/order position.
- The public-site handler can keep using `certificate_N` where N is the zero-based training index.
- A training may exist without a certificate at Cabinet API level; the public site's own form may still require one.
- Existing certificate documents must never be deleted merely because the current training list is replaced or an admin removes a training.
- Existing users may contain legacy values in the four current `gp_users` training columns and may already have one or more certificate documents.

## Unknowns

- The production public-site handler rollout timing is outside this repository.
- Existing production certificate files cannot prove which historical training they represented beyond the current single-training data model. Where a user has one backfilled legacy training, associating existing certificate documents with that sole training is the only unambiguous mapping available.
- No new product limit on the number of trainings has been chosen. Therefore this task must not introduce an arbitrary application-level count limit; normal HTTP/PHP request-size and scalar field limits remain the technical boundary.

## Scope

### 1. Add a real psychologist training entity

Add an additive MySQL migration creating `gp_user_trainings` with at least:

- `id`;
- `user_id` foreign key to `gp_users`;
- `position` unsigned integer;
- `modality_program` nullable string, max-compatible with the existing 255-character contract;
- `training_center` nullable string, same bound;
- `graduation_year` nullable unsigned small integer;
- `training_hours` nullable unsigned integer;
- timestamps.

Requirements:

- unique `(user_id, position)`;
- useful index on `user_id` if not already provided by the foreign key/index implementation;
- no soft delete requirement;
- a training row must contain at least one meaningful non-null training field;
- list order is authoritative through `position`.

Add `App\Models\UserTraining` and normal Eloquent relationships:

- `User::trainings()`;
- `UserTraining::user()`;
- document relation described below.

The new table becomes the only runtime source of truth for psychologist training data.

### 2. Add certificate-to-training association

Add a nullable `user_training_id` foreign key to `gp_user_documents`.

Requirements:

- it is nullable because diploma/license/registration are not training documents and because old certificate records may become unlinked;
- deleting/removing a training must set the document training link to null, not delete the document;
- a training can have zero or more certificate documents;
- `UserDocument` exposes an optional training relation;
- `UserTraining` exposes its certificate documents.

Do not change private storage paths, MIME/size validation, authorization or protected document download/view behavior.

### 3. Backfill legacy training data safely

The migration must preserve existing data.

For each existing user where at least one of the legacy `gp_users` fields is non-null:

- `modality_program`;
- `training_center`;
- `graduation_year`;
- `training_hours`;

create one `gp_user_trainings` row at `position = 0` containing those values.

If that user has existing `gp_user_documents.type = certificate` rows, link those certificate rows to the sole backfilled training because the old schema represented only one training. If there is no backfilled training for a user, existing certificate documents remain unlinked.

Do not delete document rows or private files.

For deployment/rollback safety, do **not** drop the four legacy columns from `gp_users` in this task. After backfill:

- application code must no longer read them;
- application code must no longer write them;
- they are deprecated compatibility columns only, not a second source of truth;
- docs/SPEC must state that current training data lives in `gp_user_trainings`.

Do not keep the two representations synchronized.

### 4. Psychologist intake contract: repeated trainings

Change the direct psychologist `payload` contract so the canonical training data is:

```json
{
  "trainings": [
    {
      "modality_program": "Example program",
      "training_center": "Example center",
      "graduation_year": 2024,
      "training_hours": 120
    }
  ]
}
```

Requirements:

- `trainings` is optional; omitted or `[]` means no current trainings;
- it must be a JSON list when present;
- there is no arbitrary application-level maximum count;
- each item is an object containing only the four training keys above;
- preserve the current scalar bounds/types for those fields;
- nullable/omitted values may be supported to preserve the current nullable business model, but an all-empty training object must be rejected;
- list order defines `position`;
- the legacy top-level `modality_program`, `training_center`, `graduation_year` and `training_hours` fields are no longer part of the Stage 11 psychologist payload and must be rejected as unexpected fields so there is one unambiguous contract.

All other psychologist questionnaire fields and validation remain unchanged.

### 5. Map `certificate_N` to `trainings[N]`

Keep the flat multipart file names:

- `diploma`;
- `certificate_0`, `certificate_1`, ...;
- `license`;
- `registration`.

For certificates:

- `certificate_N` links to `trainings[N]` from the same request;
- N remains a nonnegative decimal integer without leading zeros;
- a certificate whose N has no corresponding training item must return safe 422 `validation_failed`;
- a training without a certificate is allowed at Cabinet API level;
- certificate field order in the multipart body itself is irrelevant;
- MIME, size, safe original name and SHA-256 remain server-observed exactly as in TASK-2026-09-24-01;
- no client manifest/hash/size descriptor is reintroduced.

`diploma`, `license` and `registration` remain user-level documents with null `user_training_id`.

### 6. Full-submission and resubmission semantics

The psychologist questionnaire remains a full submission, not PATCH.

For a new user:

- create the user;
- create the ordered training collection;
- store/link uploaded documents;
- complete the integration journal in the existing transaction boundary.

For an accepted non-idempotent resubmission of a pending/rejected user:

- replace the **current** training collection with the submitted `trainings` list;
- do not delete historical document rows/files;
- old certificate links belonging to removed/replaced training rows become null via the nullable FK behavior;
- newly uploaded `certificate_N` documents link to the newly created current `trainings[N]`;
- existing repeat-email status/access/tariff/password behavior remains unchanged.

An idempotent replay with the same `X-Request-Id` and same semantic request must not recreate trainings or append documents.

Approved/disabled/deleted conflict behavior remains unchanged.

Keep the replacement and file/document writes inside the same existing intake transaction/rollback cleanup boundary.

### 7. Semantic idempotency for trainings and certificates

Update the Stage 11 fingerprint so it reflects the new semantics.

It must include:

- normalized questionnaire fields;
- ordered training values and their positions;
- each certificate's association to its training index/position;
- server-observed document type, safe name, size and SHA-256.

Consequences to test:

- multipart boundary/order changes do not cause conflict;
- same trainings/files under the same request ID replay safely;
- changing any training value causes 409 `idempotency_conflict`;
- adding/removing/reordering trainings causes conflict;
- swapping certificate contents between two training indices causes conflict;
- changing a diploma/license/registration file causes conflict.

Do not store submitted PII, training payloads, names or file bytes in the integration journal.

### 8. Permissive participant phone intake

Change `POST /api/v1/group-applications` phone handling.

Required public contract:

- `phone` remains required;
- it must be a scalar string;
- trim surrounding whitespace;
- empty-after-trim is invalid;
- maximum length remains 255;
- **do not require `+`, `00`, a country code, 7–15 digits or any other international-number syntax**;
- preserve the trimmed submitted value in `gp_group_applications.phone`.

`phone_normalized` becomes a best-effort technical search key only:

- derive digits with the existing/search-oriented normalizer where possible;
- local/bare formats such as `80291234567` and `29 123-45-67` must be accepted;
- if no useful digit key can be derived, store an empty string rather than rejecting the application;
- do not infer or prepend a country code.

Idempotency must not reject arbitrary phone text. Preserve the existing useful punctuation-insensitive behavior when a non-empty digit search key can be derived; otherwise fingerprint the trimmed raw phone value.

Admin/raw phone search must continue to work. Numeric/digit search should continue using `phone_normalized` where useful.

Update factories/tests so they no longer depend on strict `normalizeForStorage()` acceptance semantics. Remove or narrow that strict method only if no remaining caller legitimately needs it; do not change unrelated phone behavior elsewhere without evidence.

### 9. Admin create/edit and profile presentation

Update the existing approved psychologist UI without redesigning the page.

Admin psychologist create/edit must support zero or more ordered training blocks:

- add training;
- edit existing training values;
- remove a training;
- preserve submitted order;
- validation errors address the correct training item;
- existing training IDs used by the edit form must be ownership-checked; a crafted ID from another psychologist must not be accepted.

Saving a psychologist profile plus its training collection is a multi-entity operation and must be transactional.

Prefer stable-row synchronization for admin editing:

- update submitted existing training rows belonging to that psychologist;
- create new rows;
- remove rows omitted from the submitted collection;
- reassign positions to submitted order;
- removing a row must only null its document links, never delete documents/files.

Update:

- admin psychologist detail;
- psychologist `Мои данные` read-only profile;
- shared profile presentation;
- prototype data/variants that use these same Blade views;

so all current trainings are rendered in order, not only one legacy set.

Keep the existing approved layout/components and responsive behavior. This task authorizes the minimum repeated-training UI needed by the new data model; it is not a general redesign.

Where a certificate has a current `user_training_id`, document presentation may identify the linked training in a concise way. Unlinked legacy certificates must remain visible and usable.

### 10. Existing business/security boundaries

Preserve:

- user pending/rejected/approved/disabled/deleted conflict matrix;
- education dictionary mapping;
- personal-data consent requirements;
- private document storage and authorization;
- random storage paths;
- MIME/size enforcement;
- `X-Request-Id` syntax and integration journal;
- rate limiting;
- optional IP allowlist;
- safe API error envelopes/log redaction;
- group UUID lookup and active/not-disabled checks;
- owner derivation from the matched group;
- no public `psychologist_id` / `owner_id` input;
- no mail/password/payment/group lifecycle side effects from intake.

Do not reintroduce intake authentication/shared secrets.

### 11. Documentation and specification

Update all directly affected current-state documentation, at minimum:

- `SPEC.md`:
  - psychologist data/document model;
  - participant phone acceptance;
  - Stage 11 intake contract/acceptance;
- `docs/integration.md`:
  - `trainings` payload array;
  - `certificate_N ↔ trainings[N]` mapping;
  - no arbitrary training-count limit beyond request constraints;
  - permissive phone contract;
  - idempotency semantics;
- `docs/architecture.md`;
- `docs/project-status.md`;
- `docs/development.md`;
- `docs/ui-pages.md` only if needed to keep the shared prototype/page catalogue accurate.

Do not document the deprecated `gp_users` training columns as current source-of-truth fields.

### 12. Report

Update `.ai/report.md` with:

- exact migration/schema changes;
- exact backfill behavior and verified results;
- exact new psychologist `trainings` contract;
- exact certificate association rules;
- exact participant phone contract;
- changed files;
- focused and full test results;
- migration/rollback notes;
- explicit statement that WEBPAY signing/trust and Stage 12 mail behavior were not changed;
- manual deployment note: production requires the additive migration before serving code that expects `gp_user_trainings`.

## Out Of Scope

Do NOT:

- modify the public-site `form.php` / `form_cabinet.php` in this repository; it is external and will be updated separately;
- change WEBPAY provider code, signatures, credentials, callbacks or payment state transitions;
- change mail transport, password setup, queues or scheduler behavior;
- change login/session authentication;
- change psychologist approval/access/tariff lifecycle;
- change group lifecycle/moderation/payment rules;
- add CAPTCHA/WAF/spam services;
- add a new Composer/package dependency;
- add Node/npm/Vite or a frontend build step;
- expose private document URLs;
- delete existing certificate documents as part of training synchronization;
- add an arbitrary `trainings` count limit such as the public form's previous 30-item validation;
- normalize local phones into an invented country code;
- commit runtime `.env`, credentials, logs, real personal data or upload artifacts.

## Constraints

- Laravel 12 / PHP ^8.2 remains unchanged.
- MySQL remains the production database.
- The migration must be additive and safe for retained production data.
- The four old training columns may remain physically present but are deprecated and must not be used by runtime code after this task.
- Existing private document storage remains authoritative.
- Existing integration request journal remains authoritative for idempotency.
- Existing approved Blade pages/components are reused.
- No new external dependency.
- No real external network/mail/WEBPAY calls in automated tests.
- Keep the diff focused on multiple trainings, certificate association, permissive participant phones, directly affected UI/tests/docs, and migration/backfill.

## Acceptance Criteria

1. `gp_user_trainings` exists and is the runtime source of truth for psychologist trainings.
2. Existing single-training data is backfilled without deleting user/document data.
3. Existing certificate documents are linked to the sole backfilled training when that mapping is unambiguous; otherwise they remain valid unlinked documents.
4. Runtime code no longer reads/writes the four legacy `gp_users` training columns.
5. A psychologist can have zero, one or many ordered trainings.
6. Stage 11 accepts a `trainings` JSON list with no arbitrary application-level count cap.
7. `certificate_N` is associated with `trainings[N]` and a certificate without a matching training index is rejected with 422.
8. Training certificates remain private and use existing MIME/size/server-metadata validation.
9. Pending/rejected resubmission replaces the current training collection without deleting old documents/files.
10. Idempotent replay does not duplicate trainings/documents.
11. Same request ID with changed/reordered trainings or changed certificate association/content returns 409.
12. Group applications accept local/bare/non-international phone formats as long as the trimmed string is non-empty and <=255 characters.
13. `phone_normalized` is best-effort only and can be empty without rejecting the application.
14. Raw phone display and admin search continue to work.
15. Admin create/edit supports multiple trainings transactionally and rejects foreign training IDs.
16. Admin detail and psychologist `Мои данные` show all trainings in order.
17. Existing psychologist conflict matrix, group application business rules, rate limit, allowlist and idempotency journal remain intact.
18. No WEBPAY trust/signing, mail/password, group lifecycle or login/auth behavior changes.
19. Migration/backfill tests or deterministic disposable-MySQL verification pass.
20. Focused integration/admin/profile/application tests pass.
21. Full MySQL suite passes.
22. Pint and Larastan pass.
23. `composer check-platform-reqs` and `php artisan view:cache` pass.
24. Documentation/SPEC describe the implemented contract and deprecated legacy columns accurately.
25. No secrets, runtime env files, logs, real uploads/PII or unrelated artifacts are committed.

## Checks

Run and report exact results for:

1. migration/backfill verification on disposable MySQL data, including:
   - legacy single-training user → `gp_user_trainings position 0`;
   - existing certificate association;
   - user with certificate but no legacy training remains unlinked;
2. focused `IntegrationIntakeTest`;
3. focused `IntegrationConcurrencyTest`;
4. psychologist admin tests covering create/edit/detail/document behavior;
5. psychologist profile tests;
6. participant application/search tests;
7. Stage 11/12/shared-runtime regression relevant to intake/document/mail boundaries;
8. focused WEBPAY regression sufficient to prove provider signing/trust code is untouched;
9. full MySQL test suite;
10. Pint;
11. Larastan with the repository's normal memory limit override if needed;
12. `composer check-platform-reqs`;
13. `php artisan view:cache`;
14. `git diff --check`;
15. final git status/staged-file review;
16. secrets/artifact/real-PII review.

Database suites sharing `gruppa_cabinet_test` must run sequentially.

Do not make real external mail or WEBPAY requests.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and its parent is `cb31df7196c512478da00acf5d8394c04eb0ab0e`;
- read `WORKFLOW.md`;
- read `AGENTS.md`;
- read relevant `SPEC.md` sections, especially psychologist data/documents, participant applications, Stage 11 and security;
- read this `.ai/task.md`;
- read current `.ai/report.md`;
- inspect the current domain migration and models:
  - `User`;
  - `UserDocument`;
  - `GroupApplication`;
  - `PhoneNormalizer`;
- inspect:
  - `IntakeData`;
  - `IntakeService`;
  - `PsychologistDocuments`;
  - integration tests/concurrency worker;
- inspect admin psychologist request/controller/views and `PsychologistPages`;
- inspect psychologist profile controller/shared profile view;
- inspect participant application factory/search/controller tests;
- inspect `docs/integration.md` and `docs/project-status.md`;
- do not overwrite unknown local changes.

During implementation:

- work only within this task;
- do not edit `.ai/task.md`;
- use migrations for schema changes;
- keep migration/backfill safe for retained data;
- preserve private files and document records;
- keep multi-entity writes transactional;
- do not broaden into public-site implementation;
- do not weaken unrelated WEBPAY/auth/mail security;
- do not add dependencies;
- do not make real external network calls;
- keep tests deterministic and synthetic.

Before commit:

- run all applicable checks above;
- inspect the full diff;
- inspect staged files;
- verify the migration is additive and no destructive `migrate:fresh` assumption is required for production;
- verify runtime code has no remaining read/write dependency on the four legacy training columns;
- verify no `.env`, credentials, tokens, logs, submitted PII, real file fixtures or unrelated artifacts are staged;
- update `.ai/report.md` with factual results only.

If complete, commit with:

`codex: TASK-2026-09-24-02 support multiple trainings and permissive participant phones`

If blocked/partial/failed, record the real status and reason in `.ai/report.md`; do not present incomplete work as done.

Do not create an accept commit.
