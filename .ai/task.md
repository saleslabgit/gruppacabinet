# Task: TASK-2026-10-02-01

Status: planned
Created from: 80d0db339e8a0d0c2305cb3cdee3fb64ebcbbb9e (main)

## Title

Close recovery timing race after locked eligibility recheck

## Goal

Finish the password-recovery timing-privacy correction after review of:

`80d0db339e8a0d0c2305cb3cdee3fb64ebcbbb9e`
`codex: TASK-2026-10-02-01 correct recovery timing privacy`

The current implementation correctly performs the same configured framework hash
for an already-known ineligible/unknown account, but one race remains.

Current public flow:

1. controller reads user;
2. controller sees the user as eligible;
3. controller calls `PasswordSetupService::invite()`;
4. `invite()` locks/reloads the row;
5. concurrent admin/account change may make the user ineligible;
6. actorless `invite()` returns without creating a real broker token;
7. controller has already skipped the dummy branch.

Therefore a syntactically valid recovery request can still perform **zero**
expensive token-hash operations.

Close this race so every syntactically valid, non-throttled public recovery
request performs exactly one configured framework token-hash cost:

- real broker token hash if a token is actually issued;
- otherwise one dummy hash using the same framework hasher/cost.

Preserve every accepted recovery/admin/session/mail behavior.

## Facts

- Current main HEAD is
  `80d0db339e8a0d0c2305cb3cdee3fb64ebcbbb9e`.
- Previous timing correction already:
  - uses `app('hash')->make(Str::random(64))` for the normal unknown/ineligible branch;
  - proves same framework hasher for bcrypt/Argon2id;
  - creates no fake user/token row/job;
  - adds no sleeps;
  - preserves generic public responses;
  - passes the full suite.
- The remaining issue exists only when eligibility changes between the controller's
  first lookup and the locked recheck inside `PasswordSetupService::invite()`.
- `invite()` currently returns `void`.
- For actorless/public use, an ineligible locked row returns silently.
- For admin use, ineligibility still raises the existing validation/authorization
  behavior and must remain unchanged.
- No UI, migration, route, mail-copy or documentation-count change is needed
  unless implementation facts require a narrowly related correction.

## Required Design

### 1. Make actual issuance observable to the public caller

Adjust the smallest appropriate service boundary so the public recovery caller
can know whether a real token was actually created after the row-lock eligibility
recheck.

Preferred approach:

- change `PasswordSetupService::invite(...)` to return a boolean such as:
  - `true` = a real broker token/job was created;
  - `false` = actorless/public call found the locked user no longer eligible and
    no real token/hash was created.

Admin behavior must stay the same:

- actor present + missing/ineligible target still follows existing 404 /
  authorization / validation semantics;
- successful admin sending still audits and returns success;
- do not turn admin failures into `false`.

Automatic post-approval onboarding must keep working. Its caller may ignore the
return value.

If another equally small explicit service boundary is cleaner, it is acceptable,
but do not introduce a broad refactor.

### 2. Public controller fallback

For every syntactically valid, non-throttled POST `/password/forgot`:

- if no eligible user was found before calling the service: perform one dummy hash;
- if an eligible user was found but the locked recheck returns “not issued”:
  perform one dummy hash;
- if the real token was issued: do not perform an additional dummy hash.

The normal successful real path must still perform exactly one hash through the
broker.

The race-lost path must perform exactly one dummy hash.

Do not create two expensive hashes for any ordinary request.

### 3. Preserve queue failure semantics

If a real eligible issuance reaches broker token creation but queue insertion
throws and the transaction rolls back:

- keep the existing generic public success response;
- preserve the previous broker token if one existed;
- do not add a second dummy hash after the exception.

That request already paid the real broker hash cost before the queue failure.

Do not accidentally convert the catch path into “always dummy hash”, which would
double the cryptographic cost for infrastructure failures after token creation.

### 4. Preserve the existing timing-privacy properties

Keep unchanged:

- same framework hasher / configured cost;
- fresh random dummy input;
- no per-call cheaper options;
- no dummy storage;
- no fake reset row;
- no queue job for ineligible users;
- no sleeps/fixed delays;
- generic body/status/headers;
- no plaintext email/token/hash in logs;
- 5/min/IP and 1/min/normalized-email throttles;
- malformed email does not need dummy hash;
- throttled request does not need dummy hash.

## Concurrency / Race Tests

Add deterministic coverage for the exact remaining race.

Do not use wall-clock timing assertions.

At minimum prove:

1. controller initially resolves an eligible account;
2. before/during the locked `invite()` recheck, the account becomes ineligible;
3. the service does not create a real token/job;
4. the public request still performs exactly one framework hash via the dummy path;
5. public response remains the same generic success response;
6. no reset-token row/job is created;
7. no sensitive values are logged.

Cover at least one concurrent/in-between state change, preferably `disabled=true`.

Also test the service return contract directly:

- actorless eligible → `true`;
- actorless locked-ineligible → `false`;
- admin eligible → success;
- admin ineligible → existing exception/validation semantics, not `false`.

Retain and re-run the existing `PasswordRecoveryTimingTest` cases proving:

- eligible real path = one hash;
- unknown/ineligible dummy path = one hash;
- bcrypt + Argon2id same configured hasher/cost;
- queue failure rollback;
- malformed/throttled no hash;
- dummy freshness and safe logs.

## Preserve Original Feature

Do not regress:

- self-service “Забыли пароль?”;
- recovery with existing or null password;
- one-time/newest-token semantics;
- admin password-link action;
- post-approval first-password invitation;
- target-only session invalidation;
- old/new password authentication;
- no auto-login;
- base-path production URLs;
- SMTP/sendmail/database queue restrictions;
- CSRF/policy/account access boundaries;
- 32 page groups / 263 variants.

## Out Of Scope

Do NOT:

- redesign UI;
- change routes;
- change mail copy;
- change rate-limit values;
- change password TTL or complexity;
- add new token storage/broker;
- add sleeps or response delays;
- add packages/migrations;
- touch MODX, WEBPAY, intake, group lifecycle;
- read/change/commit `.env_save` or other production private files;
- send real external mail;
- run `migrate:fresh`;
- create an `accept:` commit.

## Acceptance Criteria

1. Every syntactically valid, non-throttled public recovery request performs
   exactly one configured framework token-hash cost.
2. Real issuance uses the existing Laravel broker hash.
3. Initial/locked-ineligible requests use exactly one dummy hash.
4. A race from initially eligible to locked-ineligible cannot produce a zero-hash
   request.
5. Queue failure after real token creation does not add a second dummy hash and
   preserves rollback/generic-response behavior.
6. Admin and onboarding behavior remains unchanged.
7. No fake reset rows/jobs or sensitive diagnostics are introduced.
8. All previous recovery/timing/security tests and full regression checks pass.

## Checks

Run and report exact results for:

1. new focused locked-recheck race tests;
2. full `PasswordRecoveryTimingTest`;
3. full `PasswordRecoveryTest`;
4. full `PasswordSetupTest`;
5. full `AuthenticationTest`;
6. affected admin psychologist/policy/session tests;
7. full `PrototypeTest`;
8. affected mail/deployment-preflight tests;
9. full MySQL suite;
10. Pint;
11. PHPStan;
12. composer check-platform-reqs;
13. composer validate --no-check-publish;
14. artisan view:cache;
15. artisan route:list --path=password -v;
16. artisan schedule:list;
17. git diff --check;
18. final status/diff/staged secret/artifact review.

No real SMTP/sendmail/MODX/WEBPAY calls.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and parent is
  `80d0db339e8a0d0c2305cb3cdee3fb64ebcbbb9e`;
- read WORKFLOW.md, AGENTS.md, this task and current report;
- inspect `PasswordRecoveryController`, `PasswordSetupService`,
  `PasswordRecoveryTimingTest` and the previous correction diff;
- verify clean/known local tree;
- do not touch unknown local changes.

During implementation:

- work only on this race correction;
- do not edit `.ai/task.md`;
- keep eligible real broker flow intact;
- ensure exactly one hash in real/dummy/race paths;
- no artificial delays;
- no fake token rows/jobs;
- never log submitted email/token/hash;
- avoid unrelated refactors/docs changes.

Before commit:

- run all required checks;
- inspect full diff/staged files;
- verify no env/private files, production data, token/queue dumps, mail captures,
  logs/cache/vendor/temp artifacts are staged;
- update `.ai/report.md` factually;
- explicitly state that no real external mail was sent.

If complete, commit with:

`codex: TASK-2026-10-02-01 close recovery timing race`

Do not create an accept commit.
