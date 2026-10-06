# Task: TASK-2026-10-06-01

Status: planned
Created from: 60464736e1a6b7ea03ca02be5211cd4e79f4bcfe (main)

## Title

Raise psychologist document upload limit to 20 MiB and align public form contract

## Goal

Fix the production issue found on the public psychologist application form where
a user was shown a generic “check the form” error although the actual failure was
a certificate file larger than the server-side 10 MiB limit.

Adopt one clear contract across the public form and Cabinet:

- maximum document size: **20 MiB per file**;
- supported formats: **JPG/JPEG, PNG, PDF**;
- WEBP is **not** supported in this task;
- user-facing errors must distinguish file-size / unsupported-format failures from
  missing-field failures.

The public page currently advertises “JPG, PNG, WEBP, PDF; до 50 МБ”, while both
the supplied public handler and Cabinet actually accept only JPEG/PNG/PDF and
10 MiB. Close this mismatch.

## Facts

Current accepted Cabinet HEAD:
`60464736e1a6b7ea03ca02be5211cd4e79f4bcfe`.

Cabinet currently has:

- `application/config/psychologist_documents.php`
  - default `PSYCHOLOGIST_DOCUMENT_MAX_KB = 10240`;
  - MIME list: `application/pdf`, `image/jpeg`, `image/png`.
- `application/.env.example`
  - `PSYCHOLOGIST_DOCUMENT_MAX_KB=10240`.
- intake rejects files above configured max or outside configured MIME list.
- public form handler supplied by product owner currently has:
  - `MAX_FILE_SIZE = 10 * 1024 * 1024`;
  - allowed MIME: JPEG/PNG/PDF;
  - generic public validation response for all local validation errors.
- live `https://gruppa.info/form` currently advertises:
  `JPG, PNG, WEBP, PDF; размер — до 50 МБ.`

The public form/handler are external to this repository. Do not pretend to modify
or commit them here. Repository changes must make Cabinet ready for the 20 MiB
contract and documentation/report must provide the exact external operator changes.

Do not read/modify/commit production private files including `.env_save`.

## A. Cabinet file limit

Change Cabinet's default psychologist document maximum from 10 MiB to 20 MiB:

- config default: 20480 KiB;
- `.env.example`: `PSYCHOLOGIST_DOCUMENT_MAX_KB=20480`.

Keep current MIME whitelist unchanged:

- `application/pdf`;
- `image/jpeg`;
- `image/png`.

Do not add WEBP.

Do not change group-cover limits.

## B. Intake behavior

Keep the same validation architecture and safe MIME detection.

Add focused boundary tests proving:

- 20 MiB file is accepted at the Cabinet intake boundary when PHP test upload
  facilities permit construction of that fixture;
- file larger than 20 MiB is rejected with `422 validation_failed`;
- JPEG, PNG and PDF remain accepted;
- WEBP remains rejected;
- no document is persisted for rejected requests;
- accepted existing-size documents continue to work.

Use efficient synthetic fixtures; do not store huge binaries in the repository.

No change to questionnaire business fields, idempotency semantics, transactions,
document storage or Telegram/mail behavior.

## C. Deployment / infrastructure contract

Update current deployment/integration documentation to state:

- Cabinet per-file application limit is 20 MiB;
- production `PSYCHOLOGIST_DOCUMENT_MAX_KB=20480`;
- PHP/web server `upload_max_filesize` must be at least 20M;
- `post_max_size` must be comfortably larger than the largest expected complete
  questionnaire because multiple files can be uploaded in one request;
- recommend at least **128M** for `post_max_size` for the current form unless the
  host has a stricter approved limit;
- request/body limits in Apache/nginx/proxy must not be below the intended total
  multipart size;
- temporary upload storage must have enough space.

Do not change production PHP settings automatically from application code.

Add/extend deployment preflight only if the existing preflight already safely
checks upload limits without exposing private config. Otherwise document the
operator check; do not broaden preflight unnecessarily.

## D. External public form/handler operator handoff

The external public site is not in this repository.

The final report must include exact manual changes for the operator.

### D1. Public handler

Change:

`const MAX_FILE_SIZE = 10 * 1024 * 1024;`

to:

`const MAX_FILE_SIZE = 20 * 1024 * 1024;`

Keep allowed MIME exactly:

- `image/jpeg`;
- `image/png`;
- `application/pdf`.

Do not add WEBP.

### D2. Public form copy

Every psychologist document upload hint on `/form` must say:

`Допустимые форматы: JPG, PNG, PDF; размер — до 20 МБ.`

Remove WEBP and 50 МБ from all diploma/certificate/license/registration hints.

### D3. Public error messages

The supplied public handler currently maps all local `ValidationException` cases
to:

`Проверьте заполнение формы и попробуйте ещё раз.`

Improve the external handler so file errors are actionable.

Required public behavior:

- too-large file:
  `Файл «<label>» слишком большой. Максимальный размер — 20 МБ.`
- unsupported MIME:
  `Формат файла «<label>» не поддерживается. Используйте JPG, PNG или PDF.`
- missing required file:
  `Загрузите файл «<label>».`
- ordinary missing/invalid fields may continue to use:
  `Проверьте заполнение формы и попробуйте ещё раз.`

Do not expose:
- PHP upload error numbers;
- temp paths;
- MIME internals;
- Cabinet/internal exception messages;
- stack traces.

Internal logs may keep precise diagnostic categories.

Prefer structured/local exception reason data rather than parsing English exception
strings when modifying the external handler.

### D4. PHP upload failure handling

Explicitly distinguish PHP upload-limit failures such as
`UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE` from other upload failures and
show the same safe “максимальный размер — 20 МБ” public message.

Remember: if PHP rejects the full multipart body because `post_max_size` is
exceeded, `$_POST` / `$_FILES` can be incomplete/empty. Document and handle
that case with a safe size-related form error where detectable.

## E. Telegram behavior

Do not redesign Telegram in this task.

For psychologist applications:

- Cabinet remains the primary destination;
- Telegram remains secondary and must not make an already accepted Cabinet
  submission fail.

Do not increase Telegram file limits or rely on Telegram as document storage.

## F. Security note

The product-owner supplied external handler contains a real Telegram bot token.

Do not copy that token into repository files, task/report examples, tests or logs.

The final report should remind the operator to rotate the exposed token and move
the external handler credential to private configuration, but do not attempt to
change external secrets from this repository.

## Tests / Checks

Run and report exact results for:

1. focused psychologist intake upload-boundary tests;
2. IntegrationIntakeTest;
3. IntegrationConcurrencyTest;
4. document/profile admin tests affected by configured max;
5. DeploymentPreflightTest if touched;
6. full MySQL suite;
7. Pint;
8. PHPStan;
9. composer check-platform-reqs;
10. composer validate --no-check-publish;
11. artisan view:cache;
12. artisan route:list;
13. git diff --check;
14. final staged/secret/artifact review.

No real external HTTP/Telegram/mail/MODX/payment requests.

## Out Of Scope

Do NOT:

- add WEBP support;
- allow 50 MiB files;
- change group-cover size limits;
- redesign public questionnaire fields;
- change intake authentication/idempotency;
- change Telegram architecture;
- change Cabinet business lifecycle;
- add migrations;
- add packages;
- commit the external public handler into this repository merely to claim it was
  deployed;
- read/change/commit `.env_save`;
- run `migrate:fresh`;
- create an `accept:` commit.

## Acceptance Criteria

1. Cabinet accepts psychologist documents up to and including 20 MiB subject to
   supported MIME and infrastructure limits.
2. Cabinet rejects files over 20 MiB.
3. Cabinet supports JPG/JPEG, PNG and PDF only; WEBP remains rejected.
4. Repository config/example/docs consistently state 20 MiB.
5. External handoff precisely changes the public handler to 20 MiB.
6. External handoff changes all public form hints to JPG/PNG/PDF and 20 MiB.
7. External handoff provides clear safe file-size/format/missing-file messages.
8. No business/integration semantics regress.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and parent is
  `60464736e1a6b7ea03ca02be5211cd4e79f4bcfe`;
- read WORKFLOW.md, AGENTS.md, this task and current report;
- inspect current psychologist document config, intake validation, tests and
  deployment/integration docs;
- verify clean/known local tree.

During implementation:

- work only on Cabinet-side 20 MiB readiness and documentation/tests;
- do not edit `.ai/task.md`;
- do not claim external /form or handler was changed by this repository;
- preserve MIME whitelist and security/idempotency behavior.

Before commit:

- run all required checks;
- inspect full diff/staged files;
- verify no real token/private env/production data/uploads/logs/cache/vendor/temp
  artifacts are staged;
- update `.ai/report.md` factually;
- include the exact external operator handoff;
- explicitly state external public form/handler still require separate deployment.

If complete, commit with:

`codex: TASK-2026-10-06-01 raise psychologist upload limit`

Do not create an accept commit.
