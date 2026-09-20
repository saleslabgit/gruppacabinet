# Task: TASK-2026-09-20-01

Status: planned
Created from: 3d3c963456d8e52ece8de8fda4ddd7f557f1c826 (main)

## Title

Stage 1 — Bootstrap the Laravel application, local Docker environment, base-path runtime, tooling, and foundation documentation

## Goal

Create the complete technical foundation defined by Stage 1 of `SPEC.md`.

The repository must become a runnable Laravel 12 project with a strict separation between the deployable application and local Docker infrastructure. A fresh local checkout must be able to start the application through Docker from the repository root, serve it under the `/cabinet` base path, connect to MySQL, load local Bootstrap/CSS/JS assets without a frontend build tool, and run the required PHP test/static-analysis/tooling checks.

This task is infrastructure and application foundation only. Do not implement Stage 2 domain models/database schema or any later product flow.

## Facts

- The current repository contains the project governance/specification files and no Laravel application yet.
- The current baseline is commit `3d3c963456d8e52ece8de8fda4ddd7f557f1c826` on `main`.
- `WORKFLOW.md`, `AGENTS.md`, and `SPEC.md` are the governing documents.
- The required backend stack is Laravel 12.x, PHP 8.2+, MySQL, and Composer.
- Production PHP is specified as PHP 8.2.32 or compatible; Composer platform compatibility must be pinned to the production target.
- Frontend foundation is Blade + Bootstrap 5 + project CSS + minimal Vanilla JS.
- Bootstrap must be stored locally at a fixed version and must not be loaded from a CDN.
- Node.js, npm, Vite, and any frontend build step are prohibited.
- The deployable Laravel application belongs in `application/`.
- Docker is local-development infrastructure only and belongs outside `application/`.
- Production deployment must not depend on Docker.
- The application must work from the public base path `/cabinet`, not only from the domain root.
- Production `APP_URL` will be `https://gruppa.info/cabinet`; production deployment itself is not part of this task.
- Application storage/time logic uses UTC; user-facing date/time formatting uses `Europe/Minsk`.
- Monetary values are represented in integer minor units; Stage 1 must provide a shared display helper without introducing float-based money calculations.
- Stage 3, not this task, is responsible for the complete approved Blade page set and visual design foundation.
- No WEBPAY requests or credentials are allowed before Stage 13.

## Assumptions

- Docker Engine with the Docker Compose plugin is the supported local prerequisite.
- Network access may be used during initial dependency/image acquisition.
- A minimal conventional local web-server configuration may be chosen by Codex as long as it satisfies the required `/cabinet` behavior and does not leak Docker assumptions into production application code.
- The Composer platform target should be set to PHP `8.2.32`, matching the concrete production version stated in the specification.
- The temporary Stage 1 Blade page may use the application root route and can be replaced by the real product home flow in a later stage.
- Local-only development credentials/configuration may be used for Docker services, but no production secrets or credentials may be committed.

## Unknowns

- The exact production hosting panel and final filesystem paths are not yet validated. Stage 1 should document the intended deployment boundary from `SPEC.md`, but must not claim that a real production deployment has been verified.
- Final production rewrite behavior under the existing `gruppa.info` site is not yet verified. That verification belongs to staging/production stages.
- SMTP, public-site integration credentials, WEBPAY Sandbox credentials, and WEBPAY production credentials are intentionally unavailable/not required for this task.
- The exact production queue-worker mechanism available on the shared host is not yet verified and must not be invented as completed behavior.

## Scope

### 1. Repository structure

Create and use the required top-level boundary:

```text
application/     # standalone deployable Laravel project
docker/          # local infrastructure only
compose.yaml
docs/
README.md
```

Keep the existing root governance files in place.

The `application/` directory must be a normal standalone Laravel project containing its own `composer.json`, `composer.lock`, `artisan`, source directories, public directory, tests, storage, and configuration.

Docker-specific files, Dockerfiles, local web-server config, and Docker volumes must remain outside `application/`.

Do not create a second deployable Laravel copy such as `deploy/`.

### 2. Laravel 12 application bootstrap

Bootstrap Laravel 12.x inside `application/`.

Remove or avoid Laravel starter artifacts that violate this project's constraints, including frontend build-tool dependencies/configuration such as Vite/npm/package manifests when they are not needed.

Do not implement Stage 2 business tables/models/status enums in this task.

If the Laravel skeleton includes starter database migrations that conflict with the project-specific Stage 2 schema, keep the Stage 1 migration surface clean rather than creating an accidental parallel user/domain schema.

### 3. Local Docker environment

Create a reproducible Docker Compose environment containing at minimum:

- PHP 8.2 runtime suitable for Laravel;
- MySQL;
- a web server;
- Composer available inside the development environment.

Requirements:

- mount `./application` into the PHP/runtime container as a bind mount;
- keep MySQL data in a Docker named volume or another location outside `application/`;
- do not encode Docker-only hostnames or filesystem paths into production application configuration;
- provide a root-level one-command startup path, documented in `README.md`;
- from a clean checkout with Docker available, the documented startup command must be sufficient to build/start the local stack without requiring host PHP, Composer, Node, or npm;
- startup must fail clearly rather than silently masking missing dependencies or invalid configuration.

Prefer the simplest setup that meets the acceptance criteria. Do not add orchestration or helper tooling that is not needed.

### 4. Base path `/cabinet`

Configure the local web server and Laravel environment so the application is actually exercised under a `/cabinet` prefix.

The local URL may use an explicit development port, but the path must be `/cabinet/`.

Verify that:

- the Stage 1 page opens at the local `/cabinet/` URL;
- named-route URLs generated by Laravel retain the `/cabinet` prefix where applicable;
- asset URLs generated through Laravel helpers point to the correct `/cabinet` location;
- redirects used by the Stage 1 smoke flow do not escape to the domain root;
- the implementation does not depend on hardcoded root-domain paths.

Do not fake the requirement by serving only at `/` and documenting `/cabinet` as future work.

### 5. Basic Blade/runtime page

Create a minimal Stage 1 Blade layout/page sufficient to verify the runtime foundation.

It must demonstrate:

- Blade rendering;
- local Bootstrap CSS;
- project `app.css`;
- project `app.js`;
- Laravel-generated route/asset URLs;
- a successful database connectivity check or another explicit runtime check that proves Laravel can reach MySQL.

This is a technical smoke page, not Stage 3 UI prototyping. Keep it intentionally minimal.

### 6. Local frontend assets without a build step

Add a fixed Bootstrap 5 distribution under `application/public/` and load it locally.

Add project-owned:

- `application/public/app.css`;
- `application/public/app.js`.

No CDN references.

No Node.js.

No npm/yarn/pnpm.

No Vite.

No runtime asset compilation.

Document the exact Bootstrap version actually committed.

### 7. PHP/Composer platform compatibility

Configure `application/composer.json` so that:

- `config.platform.php` is `8.2.32`;
- PHP extensions required by the current Laravel application/tooling are represented explicitly in Composer requirements where appropriate;
- MySQL support required by this project is included in the runtime image and platform requirements;
- dependencies are locked in `composer.lock`.

Do not add speculative application/framework packages that are not required by Stage 1.

### 8. Time and money display foundation

Configure:

- application/server timezone as UTC;
- a single reusable date/time display helper or equivalent shared primitive converting values for display in `Europe/Minsk`;
- a single reusable money display helper or equivalent shared primitive accepting integer minor units and formatting them for display without float-based arithmetic.

Add focused automated tests for these helpers.

Do not build later business date/payment logic in this task.

### 9. Code quality tooling

Configure and make runnable:

- Laravel Pint with committed configuration;
- Larastan with a committed configuration and an explicit level;
- Laravel test suite.

Use the smallest sensible baseline configuration that passes on the Stage 1 codebase and can be tightened/evolved later without introducing avoidable technical debt.

### 10. Environment example and repository hygiene

Create/update `application/.env.example` for the actual Stage 1 application configuration.

It must:

- contain no real secrets;
- include variables needed by the application and local documented setup;
- use UTC application timezone;
- remain compatible with later `APP_URL=https://gruppa.info/cabinet`;
- not contain WEBPAY production credentials, public-site integration secrets, or SMTP secrets.

Ensure normal local/generated files are ignored appropriately, including runtime caches, local environment files, vendor artifacts when appropriate, IDE/system noise, and local Docker data if any bind-mounted data directory is used.

Do not commit generated runtime caches/logs.

### 11. Documentation

Create/update:

- root `README.md`;
- `docs/architecture.md`;
- `docs/development.md`;
- `docs/project-status.md`.

The documentation must describe the state that actually exists after this task.

At minimum document:

#### README
- project purpose at a concise level;
- repository boundary: `application/` vs `docker/`;
- local prerequisites;
- the exact one-command local startup command;
- the local `/cabinet/` URL;
- how to run Artisan/Composer/tests/Pint/Larastan in the Docker environment;
- production deployment boundary at a high level: only `application/` is deployable and Docker is not part of production;
- clearly state that production deployment is not yet performed/verified.

#### docs/architecture.md
- Stage 1 runtime topology;
- application/Docker boundary;
- request path through the local web server to Laravel;
- base-path constraint;
- UTC storage/application timezone and Minsk display timezone;
- no frontend build pipeline.

#### docs/development.md
- clean local setup/start/stop;
- dependency/tool commands;
- troubleshooting only for facts verified in this stage.

#### docs/project-status.md
- Stage 1 status and implemented foundation;
- checks actually available/passing;
- intentionally not implemented later-stage areas;
- known external prerequisites/unknowns.

Do not document planned later-stage features as if they already exist.

## Out Of Scope

Do not implement any Stage 2+ product functionality, including:

- `gp_*` domain tables or the Stage 2 domain model;
- user/group/payment status enums or transition services;
- audit/history domain services;
- authentication or role-based access;
- psychologist/admin CRUD;
- complete UI prototypes or Stage 3 page catalogue;
- registration/onboarding flows;
- email or SMTP integration;
- queues/scheduler business jobs beyond framework/tooling needs;
- public-site `/api/v1` integration;
- HMAC/idempotency integration logic;
- group lifecycle or extensions;
- participant applications;
- WEBPAY adapter, requests, credentials, callbacks, or payment behavior;
- production deployment;
- production rewrite changes on `gruppa.info`;
- production credentials of any kind.

Do not modify `SPEC.md`, `WORKFLOW.md`, or `AGENTS.md` unless an actual blocking contradiction is discovered. If one is found, stop and report it instead of silently rewriting governance/specification files.

## Constraints

- Follow `WORKFLOW.md` and `AGENTS.md`.
- Work only on this task.
- Keep the implementation simple and explicit.
- Do not introduce Node/npm/Vite or a frontend build system.
- Do not add Vue, React, Inertia, Livewire, Tailwind, or SPA infrastructure.
- Do not introduce unnecessary Laravel/framework packages.
- Bootstrap must be local, not CDN-hosted.
- Production application code must remain in `application/`; Docker infrastructure must remain outside it.
- Production must not depend on Docker-specific hostnames, paths, or commands.
- Use Laravel URL/route/asset helpers rather than hardcoded domain-root paths.
- Do not add secrets, real credentials, production data, or sensitive local configuration to Git.
- Do not add WEBPAY requests or credentials.
- Do not claim real production/shared-hosting compatibility beyond what is actually verified locally.
- Do not alter `.ai/task.md`.
- Preserve existing repository governance files and unrelated content.

## Acceptance Criteria

1. The repository contains separate `application/` and `docker/` areas, with local Docker infrastructure outside the Laravel project.
2. `application/` is a valid standalone Laravel 12.x project with committed `composer.json` and `composer.lock`.
3. The documented single root command starts/builds the local environment from a clean checkout using Docker, without requiring host PHP/Composer/Node/npm.
4. Laravel connects successfully to the MySQL container.
5. The application is reachable through the documented local URL under `/cabinet/`; it is not validated only at `/`.
6. Laravel-generated route/asset URLs used by the Stage 1 page preserve the `/cabinet` base path.
7. The Stage 1 Blade page renders successfully and loads the committed local Bootstrap asset, `app.css`, and `app.js`.
8. Bootstrap is a pinned Bootstrap 5 version stored locally and no CDN is required for the page.
9. No Node/npm/Vite/package-manager/build-tool dependency is required to install, run, test, or serve the application.
10. `application/composer.json` pins `config.platform.php` to `8.2.32` and declares the PHP/extensions required by the current application/tooling.
11. `composer check-platform-reqs` passes in the intended Docker PHP environment.
12. Application timezone is UTC and a tested shared helper correctly renders dates/times for `Europe/Minsk`.
13. A tested shared money helper formats integer minor units without float-based money arithmetic.
14. `php artisan test` passes in the Docker/application environment.
15. Laravel Pint runs and passes.
16. Larastan runs at the committed configured level and passes.
17. No Stage 2 business schema/domain workflow has been implemented prematurely.
18. No WEBPAY/public-site/SMTP/production credentials or secrets are committed.
19. `README.md`, `docs/architecture.md`, `docs/development.md`, and `docs/project-status.md` exist and match the actual Stage 1 implementation.
20. `application/` contains no Dockerfile/Compose/local-container configuration and remains the sole source tree intended for later production deployment.
21. The final diff contains only Stage 1 foundation work, required documentation, and `.ai/report.md`; no unrelated cleanup or feature work is included.

## Checks

Run and report the exact results of all applicable checks.

At minimum:

1. From a clean/stopped local Docker state, run the documented one-command startup/build procedure.
2. Verify the local `/cabinet/` HTTP page with an actual request/browser-equivalent check.
3. Verify the page returns successful HTML and the referenced local Bootstrap/CSS/JS asset URLs are reachable under the correct base path.
4. Verify Laravel can query/connect to MySQL from inside the application environment.
5. Run:
   - `php artisan test`
   - Laravel Pint in check mode
   - Larastan using the committed configuration
   - `composer check-platform-reqs`
6. Run a focused automated test proving UTC → `Europe/Minsk` display conversion.
7. Run a focused automated test for money formatting from integer minor units, including at least zero and a non-whole amount.
8. Search/inspect the repository to confirm there is no required Node/npm/Vite runtime/build path and no accidental committed secrets.
9. Inspect generated route/asset URLs or equivalent runtime output to confirm the `/cabinet` prefix is correct.
10. Inspect `git diff`, `git status --short`, and staged files before committing.

If a required check cannot be run, record the reason accurately in `.ai/report.md`. Do not mark the task `done` unless the acceptance criteria that depend on that check are actually verified.

## Hard Workflow Gate

Before making changes:

- read `WORKFLOW.md`, `AGENTS.md`, and this `.ai/task.md`;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm this task belongs to the latest relevant `planner:` commit;
- do not touch unknown local changes.

During implementation:

- work only within Stage 1 scope;
- do not expand into Stage 2 or later product work;
- do not modify `.ai/task.md`;
- do not change governance/specification files unless blocked by a real contradiction, in which case stop and report;
- do not add secrets, credentials, production data, runtime logs/caches, or unrelated generated artifacts;
- do not introduce Node/npm/Vite or unrequested framework/application packages.

Before commit:

- run the required and relevant checks above;
- verify the actual `/cabinet` runtime flow, not only unit tests;
- update `.ai/report.md` with the real status, changed files, checks, facts, assumptions, unknowns, and risks/next step;
- inspect the complete diff;
- inspect staged files;
- stage only files belonging to this task plus `.ai/report.md`;
- confirm no secrets or unrelated files are staged.

Completion:

- only use `Status: done` if the Stage 1 acceptance criteria are actually satisfied;
- otherwise use `partial`, `blocked`, or `failed` honestly in `.ai/report.md`;
- if the gate passes, commit with:

```text
codex: TASK-2026-09-20-01 bootstrap project foundation
```

- do not create an `accept:` commit.
