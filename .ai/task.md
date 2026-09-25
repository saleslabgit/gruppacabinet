# Task: TASK-2026-09-25-01

Status: planned
Created from: 5ad0f13baabe79f02fca047bdbd515134e88ae5a (main)

## Title

Enforce HTTPS for the production cabinet and prevent HTTP login flows

## Goal

Make the production cabinet at `gruppa.info/cabinet` HTTPS-only so a browser can never continue the login/session flow over plain HTTP.

Fix the deployment/runtime boundary, not the authentication business logic.

## Facts

- Accepted base commit is `5ad0f13baabe79f02fca047bdbd515134e88ae5a`.
- Remote `main` is identical to that commit; there are no newer repository commits or changed files on remote `main`.
- The owner reproduced the issue in mobile Chrome: after psychologist login the browser can land on an `http://` cabinet URL; changing it manually to `https://` shows the user is already authenticated.
- HTTPS request behavior itself is currently correct:
  - unauthenticated `https://gruppa.info/cabinet/profile` returns a 302 whose `Location` is `https://gruppa.info/cabinet/login`;
  - an invalid POST to `https://gruppa.info/cabinet/login` also redirects to `https://gruppa.info/cabinet/login`.
- Plain HTTP is currently accepted:
  - `http://gruppa.info/cabinet/login` returns `HTTP/1.1 200 OK` instead of redirecting to HTTPS.
- Current `application/public/.htaccess` contains the standard Laravel rewrite/front-controller rules but no HTTP-to-HTTPS redirect.
- `docs/deployment.md` already requires production `APP_URL=https://.../cabinet` and `SESSION_SECURE_COOKIE=true`, and explicitly notes that `APP_URL` alone cannot fix server rewrite errors.
- `SPEC.md` defines the production cabinet URL as `https://gruppa.info/cabinet/` and requires production HTTPS.
- Current login controller regenerates the session and redirects by named route; no hardcoded `http://` URL exists in the login form or controller.
- Existing relevant tests include `AuthenticationTest`, `DeploymentPreflightTest`, and `SharedHostingRuntimeTest`.
- Last accepted verification reported the full MySQL suite as **465 passed / 5683 assertions**, Pint PASS, Larastan no errors, platform requirements success, and `view:cache` success.

## Assumptions

- The production canonical host remains exactly `gruppa.info`.
- The application continues to be published under `/cabinet`.
- HTTPS termination/rewrite behavior must be handled safely for the actual shared-hosting topology; do not blindly trust client-supplied forwarded headers.

## Unknowns

- The exact mobile-Chrome navigation step that first enters plain HTTP has not been captured.
- Whether HostER terminates TLS directly in the Apache context that reads this `.htaccess`, or through a trusted front proxy, is not proven from the repository.
- The separate intermittent 405 issue and the suspected `route:cache` trigger are not yet isolated by the required production cache-by-cache test.

## Scope

### 1. Enforce canonical HTTPS for production cabinet traffic

Implement the smallest safe production-layer fix so requests to the cabinet over plain HTTP redirect permanently to the same cabinet URL on HTTPS.

Required behavior:

- `http://gruppa.info/cabinet/login` redirects to `https://gruppa.info/cabinet/login`;
- arbitrary `/cabinet/*` paths preserve their path and query string when redirected;
- already-HTTPS requests do not redirect again;
- the redirect happens before Laravel authentication/session handling;
- do not reflect an untrusted arbitrary `Host` value into the redirect target; the production canonical host is fixed as `gruppa.info`;
- do not break local/test development on localhost or the existing `/cabinet` base-path behavior;
- do not create a redirect loop if the real hosting topology uses a front proxy/TLS terminator;
- if forwarded-proto handling is needed, trust it only in a way justified by the verified hosting topology; do not globally trust arbitrary client proxy headers.

Prefer a web-server/public-entry solution because HTTPS-only behavior must also protect requests that may be served without entering Laravel. Keep the change surgical.

### 2. Preserve auth/session behavior

Do not redesign login/session logic.

Verify that:

- successful psychologist login still regenerates the session and redirects to the psychologist home;
- successful admin login still redirects to the admin home;
- failed login behavior remains unchanged;
- secure-cookie production requirements remain intact;
- named routes and generated production URLs stay under `https://gruppa.info/cabinet`.

Do not add `URL::forceScheme('https')`, trusted-proxy configuration, or application middleware merely as a workaround unless implementation evidence shows it is actually required in addition to the server-layer redirect.

### 3. Tests and documentation

Add focused regression coverage appropriate to the chosen implementation.

At minimum:

- cover the HTTPS-only deployment contract deterministically without requiring a live production Apache instance;
- keep/extend auth/base-path tests so generated production redirects remain HTTPS;
- verify local/test URLs are not unintentionally forced to the production host;
- update `docs/deployment.md` with the canonical HTTP -> HTTPS requirement and exact production smoke checks;
- update other documentation only if the implementation changes an already documented fact.

The production smoke procedure must include checks equivalent to:

```bash
curl -sS -D - -o /dev/null http://gruppa.info/cabinet/login
curl -sS -D - -o /dev/null http://gruppa.info/cabinet/
curl -sS -D - -o /dev/null 'http://gruppa.info/cabinet/login?probe=1'
curl -sS -D - -o /dev/null https://gruppa.info/cabinet/login
```

Expected production result:

- every HTTP cabinet request redirects to the same `https://gruppa.info/cabinet/...` URL;
- HTTPS does not loop or downgrade;
- mobile Chrome psychologist login completes without exposing or requiring an `http://` cabinet URL.

## Out Of Scope

Do NOT:

- investigate or modify the intermittent 405 / `route:cache` issue in this task;
- remove `route:cache` from deployment instructions without the separate production isolation result;
- change `.htaccess` front-controller behavior unrelated to HTTPS;
- change login credentials, throttling, role checks, session invalidation, CSRF, password setup, or authorization semantics;
- weaken `SESSION_SECURE_COOKIE`;
- add HSTS for the whole `gruppa.info` host as a side effect; HSTS affects the entire hostname and requires a separate host-level decision;
- change the main public site's code or redirects;
- change WEBPAY behavior or callback trust;
- add dependencies;
- add secrets, hosting credentials, logs, or production data.

## Constraints

- Laravel 12 / PHP ^8.2.
- Production cabinet path remains `/cabinet`.
- Canonical production origin remains `https://gruppa.info`.
- Preserve the existing symlink/public-path deployment model.
- Keep local development usable without redirecting localhost to production.
- Use a fixed/safe redirect target rather than arbitrary Host-header reflection.
- Avoid proxy-header trust expansion unless justified by verified infrastructure.
- No production data changes or migrations are expected.
- Keep the diff focused on HTTPS enforcement, relevant regression tests, deployment documentation, and `.ai/report.md`.
- Do not edit `.ai/task.md`.

## Acceptance Criteria

1. Plain HTTP access to `gruppa.info/cabinet/*` is redirected to the equivalent HTTPS URL before auth/session handling.
2. Redirect preserves the cabinet path and query string.
3. Redirect target cannot be changed to an arbitrary host through the request Host header.
4. HTTPS requests are not downgraded and do not enter a redirect loop.
5. Local/test development is not forced to `gruppa.info`.
6. Successful psychologist/admin login behavior and session regeneration remain unchanged.
7. Failed login semantics remain unchanged.
8. Production-generated login/home URLs remain under `https://gruppa.info/cabinet`.
9. Relevant focused tests pass.
10. Full MySQL suite passes.
11. Pint and Larastan pass.
12. `composer check-platform-reqs` and `php artisan view:cache` pass.
13. Deployment documentation contains the HTTP -> HTTPS smoke checks and expected results.
14. No unrelated `route:cache`/405 change is included.
15. No secrets, logs, production data, or unrelated artifacts are committed.

## Checks

Run and report exact results for:

1. focused `AuthenticationTest`;
2. focused `SharedHostingRuntimeTest`;
3. focused deployment/HTTPS regression test(s) added or updated for this task;
4. `DeploymentPreflightTest` if touched or affected;
5. full MySQL test suite;
6. `php ./vendor/bin/pint --test`;
7. `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`;
8. `composer check-platform-reqs`;
9. `php artisan view:cache`;
10. any local web-server smoke available for HTTP/HTTPS rewrite behavior, clearly distinguishing it from real production acceptance;
11. `git diff --check`;
12. final `git status --short`, full diff, staged-file, secrets/artifact review.

After deployment, production acceptance is performed by the operator/user using the documented `curl` checks and a real mobile-Chrome psychologist login. If Codex has no production access, report those checks as **not run**, not as passed.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and its parent is `5ad0f13baabe79f02fca047bdbd515134e88ae5a`;
- read `WORKFLOW.md`;
- read `AGENTS.md`;
- read this `.ai/task.md`;
- read current `.ai/report.md`;
- read the relevant HTTPS/base-path/production deployment sections of `SPEC.md`;
- inspect:
  - `application/public/.htaccess`;
  - `application/public/index.php`;
  - `application/bootstrap/app.php`;
  - `application/app/Http/Controllers/SessionController.php`;
  - `application/config/app.php`;
  - `application/config/session.php`;
  - `application/resources/views/auth/login.blade.php`;
  - `docs/deployment.md`;
  - `AuthenticationTest`;
  - `SharedHostingRuntimeTest`;
  - `DeploymentPreflightTest`;
- verify there are no unknown local changes before touching files.

During implementation:

- work only within this task;
- do not edit `.ai/task.md`;
- keep HTTPS enforcement before auth/session processing;
- preserve `/cabinet` path and query strings;
- do not introduce Host-header open redirects;
- do not expand trusted-proxy scope speculatively;
- do not alter auth/session semantics;
- do not touch the separate 405/`route:cache` issue;
- keep tests deterministic and free of real external requests.

Before commit:

- run all applicable checks above;
- inspect the complete diff and staged files;
- verify no unrelated rewrite/auth/session/deployment changes are present;
- verify no `.env`, credentials, tokens, logs, production data, uploads, caches, or temporary artifacts are staged;
- update `.ai/report.md` with factual results only, explicitly separating local verification from production checks not actually run.

If complete, commit with:

`codex: TASK-2026-09-25-01 enforce HTTPS for production cabinet`

If blocked/partial/failed, record the real status and reason in `.ai/report.md`; do not present incomplete work as done.

Do not create an accept commit.
