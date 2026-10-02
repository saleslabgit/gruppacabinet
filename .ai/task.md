# Task: TASK-2026-10-02-01

Status: planned
Created from: e93689ba2e81aad6c990c38cb1d2ae67eba0e248 (main)

## Title

Correct password-recovery timing privacy

## Goal

Correct the implementation of TASK-2026-10-02-01 after review.

The self-service password recovery feature is functionally complete, but the
public POST `/password/forgot` currently has an observable application-level
timing difference:

- an unknown or ineligible email performs only lookup/eligibility work and returns;
- an eligible email additionally performs Laravel broker token creation, including
  the deliberately expensive token hash, transaction/write work and queue insert.

The public response body/status/headers are generic, but this obvious cost
difference can be used for statistical account enumeration.

Remove that timing side channel while preserving every accepted behavior from
commit `e93689ba2e81aad6c990c38cb1d2ae67eba0e248`.

Also correct the two stale prototype-count summaries found during review.

## Review Result / Starting Point

The implementation commit under review is:

`e93689ba2e81aad6c990c38cb1d2ae67eba0e248`
`codex: TASK-2026-10-02-01 add password recovery`

Original planner:

`a4b718059a54a0430dc0eacebb6ec45261a085b2`
`planner: TASK-2026-10-02-01 add password recovery`

Accepted implementation behavior already present and to preserve:

- login has “Забыли пароль?”;
- GET/POST `/password/forgot`;
- same Laravel `PasswordBroker` / `DatabaseTokenRepository` /
  `password_reset_tokens`;
- configured password-link TTL;
- recovery works with an existing password or null password;
- unknown/ineligible accounts receive no token/job;
- generic public result;
- public IP + SHA-256-normalized-email throttles;
- newest link invalidates the previous link;
- password completion rechecks eligibility/token;
- password replacement revokes only the target user’s sessions and remember state;
- no auto-login;
- admin can send a new password link to an eligible psychologist regardless of
  current password;
- initial post-approval onboarding remains password-null-only;
- mail remains database-queued and SMTP/sendmail only;
- no sensitive token/link/recipient/password diagnostic logging;
- 32 prototype page groups / 263 variants are implemented and tested.

## Required Correction

### 1. Equalize the expensive public recovery path

For every syntactically valid POST `/password/forgot`, perform an equivalent
expensive token-hash operation whether or not the submitted email resolves to an
eligible account.

The implementation must remove the current obvious branch where only an eligible
account incurs the password/token hasher cost.

Use the same hashing service/algorithm/cost that Laravel's existing
`DatabaseTokenRepository` uses for password-reset token hashing. Do not invent a
cheaper hash, fixed hash, plain SHA-256 substitute, or a separate crypto system.

Preferred design:

- keep the existing real broker path unchanged for eligible users;
- for unknown/ineligible users, execute a dummy token-like hash using the same
  framework hasher and a fresh random token-shaped value;
- discard the dummy result immediately;
- do not create a fake user;
- do not create a real `password_reset_tokens` row for an unknown/ineligible email;
- do not enqueue a job for an unknown/ineligible email;
- do not log the submitted email or dummy token/hash.

If a small helper/service method makes the security property explicit and
testable, that is acceptable. Avoid a broad password subsystem refactor.

The correction is about eliminating the obvious cryptographic-work differential.
Do not introduce sleeps, busy loops, fixed response delays or random artificial
latency as the primary defense. They amplify request cost and are brittle under
load.

### 2. Preserve transaction/token semantics for eligible accounts

Eligible requests must still use the existing real broker flow and existing
transactional guarantees:

- current user row lock;
- broker token replacement;
- queue insert;
- rollback preserves the previous token if queue insertion fails;
- stale queued tokens remain no-op;
- configured TTL unchanged.

Do not replace the real broker operation with custom token generation.

### 3. Keep public response privacy unchanged

For syntactically valid requests, preserve the current same public outcome for:

- eligible account;
- unknown email;
- pending/rejected/disabled/deleted/admin account;
- eligible-account queue/infrastructure failure.

Do not introduce account-specific:

- status codes;
- redirects;
- body copy;
- response headers;
- validation messages;
- logs.

Malformed-email validation remains allowed.

### 4. Rate-limit behavior stays unchanged

Preserve:

- 5 requests/minute/IP;
- 1 request/minute per SHA-256 key of trim+lowercase email;
- existing generic recovery-specific 429 UI;
- no plaintext email in limiter keys/logging.

Do not weaken login, password-link GET/POST or admin resend throttles.

### 5. Security-focused tests

Add tests that verify the timing-privacy mechanism by behavior/instrumentation,
not by fragile wall-clock thresholds.

At minimum prove:

- one syntactically valid eligible request performs one framework token-hash cost
  through the real broker;
- one syntactically valid unknown request performs one equivalent dummy hash cost;
- each ineligible state (pending/rejected/disabled/deleted/admin) follows the
  equivalent dummy hash path;
- malformed email does not need to perform the dummy hash because syntax has
  already been rejected;
- unknown/ineligible requests still create no `password_reset_tokens` row and
  no queue job;
- eligible request still creates the real token/job;
- queue failure keeps the generic result and previous-token rollback semantics;
- no plaintext email, token, dummy token or hash appears in application logs.

Do not write a flaky test that asserts response times are within N milliseconds.
Mock/spy the framework hasher or isolate an explicit helper boundary so the
expensive operation count/type is deterministic.

The test must also ensure the dummy hash uses the same configured framework
hasher as the token repository rather than a hard-coded alternative.

### 6. Preserve all original recovery regressions

Re-run the full password-recovery/setup/auth/admin/prototype/session tests from
the original task. The correction must not regress:

- first-password onboarding;
- existing-password replacement;
- old/new password authentication;
- one-time tokens;
- session invalidation;
- admin action;
- base-path-safe production URLs;
- safe queued mail;
- unsupported transport rejection;
- account-existence-safe body/status/headers;
- CSRF/policy boundaries.

### 7. Correct stale prototype-count documentation

The actual `PrototypeCatalog` and `PrototypeTest` now prove:

- **32 page groups**;
- **263 variants**.

Update stale summary statements that still say **31 / 249**, specifically the
current top-level summaries in:

- `docs/ui-pages.md`;
- `docs/project-status.md`.

Do not mechanically rewrite historical statements where the text is explicitly
describing what an older stage/task contained at that historical point. Correct
only statements that purport to describe the current/final catalogue.

If review finds another current-state 31/249 statement, correct it only when its
meaning is clearly current state.

## Out Of Scope

Do NOT:

- redesign password recovery UI;
- change password/link email copy unless required by the correction;
- add a second token table/broker;
- add fake password-reset rows for unknown users;
- add sleeps/fixed delays as the main timing defense;
- change password complexity;
- change eligibility rules;
- change admin eligibility;
- change TTL;
- change session invalidation semantics;
- change rate-limit values;
- change MODX, WEBPAY, intake, group lifecycle or unrelated auth behavior;
- add packages;
- read/change/commit production private files including `.env_save`;
- send real external email;
- run `migrate:fresh`;
- create an `accept:` commit.

## Acceptance Criteria

1. A valid unknown/ineligible recovery request performs an equivalent framework
   token-hashing cost to an eligible request's real token generation.
2. The implementation uses the same configured framework hasher/cost as
   `DatabaseTokenRepository`; no cheap substitute or custom token crypto exists.
3. Unknown/ineligible requests still create no reset-token row and no queue job.
4. Eligible requests still use the real Laravel broker/token repository and keep
   token replacement/rollback/queue semantics.
5. Public body/status/headers remain generic and account-existence-safe.
6. Existing IP/email throttles and all auth/CSRF/policy boundaries remain.
7. No sensitive submitted email/token/dummy value/hash is added to diagnostics.
8. All functionality accepted from `e93689b` remains intact.
9. Current prototype documentation consistently reports 32 page groups / 263
   variants where it describes the current catalogue.
10. Full regression/static checks pass.

## Checks

Run and report exact results for:

1. new focused timing-privacy tests;
2. full `PasswordRecoveryTest`;
3. full `PasswordSetupTest`;
4. full `AuthenticationTest`;
5. affected admin psychologist/policy/session tests;
6. full `PrototypeTest`;
7. affected mail/deployment-preflight tests;
8. full MySQL suite;
9. Pint;
10. PHPStan;
11. composer check-platform-reqs;
12. composer validate --no-check-publish;
13. artisan view:cache;
14. artisan route:list --path=password -v;
15. artisan schedule:list;
16. git diff --check;
17. final status/diff/staged secret/artifact review.

Do not make real SMTP/sendmail/MODX/WEBPAY calls.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this corrective planner commit and its parent is
  `e93689ba2e81aad6c990c38cb1d2ae67eba0e248`;
- read `WORKFLOW.md`, `AGENTS.md`, this task and current `.ai/report.md`;
- inspect the full implementation diff
  `a4b718059a54a0430dc0eacebb6ec45261a085b2..e93689ba2e81aad6c990c38cb1d2ae67eba0e248`;
- inspect `PasswordRecoveryController`, `PasswordSetupService`,
  framework hasher usage in the existing broker/repository boundary, and
  `PasswordRecoveryTest`;
- inspect current prototype counts and the two stale docs;
- verify clean/known local tree;
- do not touch unknown local changes.

During implementation:

- work only on this correction;
- do not edit `.ai/task.md`;
- keep real eligible flow on the existing Laravel broker;
- use the same framework hash cost for dummy work;
- no artificial sleep defense;
- no fake reset rows/jobs;
- never log submitted email/token/hash;
- preserve all accepted recovery behavior;
- avoid unrelated refactors/docs cleanup.

Before commit:

- run all required checks;
- inspect complete diff/staged files;
- verify no env/private files, production data, tokens, queue payload dumps,
  mail captures, logs/cache/vendor or temporary artifacts are staged;
- update `.ai/report.md` factually;
- explicitly state that no real external mail was sent.

If complete, commit with:

`codex: TASK-2026-10-02-01 correct recovery timing privacy`

Do not create an accept commit.
