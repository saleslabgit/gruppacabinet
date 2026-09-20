# Task: TASK-2026-09-20-04

Status: planned
Created from: d2b5b9097d1b3350fa8c5976bbb1d1ae61aab858 (main)

## Title

Stage 2 correction — enforce group tariff snapshot and application-owned public UUID creation

## Goal

Correct the two remaining Stage 2 creation invariants identified during review:

1. every new group must snapshot the owner’s current tariff from `gp_users.free` into `gp_groups.free`;
2. every new group must receive an application-generated random UUID v4 `public_uuid` that callers cannot choose.

This is a narrow corrective iteration of Stage 2. Do not begin Stage 3.

## Facts

- Stage 2 was implemented in commit `d2b5b9097d1b3350fa8c5976bbb1d1ae61aab858`.
- Review found two remaining blockers in `App\Models\Group`.
- The current model generates `public_uuid` only when the field is null, so a caller can currently supply its own value on create.
- The current model does not copy the owner’s current `free` flag when a group is created; the database default can therefore incorrectly record `false` for a free psychologist.
- `SPEC.md` defines `gp_users.free` as the current psychologist tariff.
- `SPEC.md` defines `gp_groups.free` as the tariff snapshot taken when that group is created.
- Later changes to `gp_users.free` must not retroactively change already-created groups.
- `public_uuid` is the cabinet-owned integration identifier and must be generated automatically as a random UUID v4 when the group is created.
- `public_uuid` must remain immutable after creation.
- Existing Stage 2 tests use MySQL `gruppa_cabinet_test`.

## Assumptions

- A group always has an `owner_id` before it can be persisted; the schema already requires it.
- The snapshot must be taken from the persisted owner referenced by `owner_id`, not from a caller-provided `free` attribute.
- If a caller supplies `free` during group creation, the application-owned snapshot value should win.
- If a caller supplies `public_uuid` during group creation, the application must ignore/replace it with a fresh internally generated UUID v4 rather than accepting caller control.
- Existing post-create immutability behavior for `public_uuid` must remain intact.

## Unknowns

- None that block this correction.

## Scope

### 1. Enforce tariff snapshot at group creation

Update the group creation behavior in one central place so that every newly persisted `Group`:

- resolves its owner from `owner_id`;
- copies the owner’s current `gp_users.free` value into `gp_groups.free`;
- does this regardless of whether the caller supplied a `free` attribute;
- fails clearly if a valid owner cannot be resolved rather than silently using a default tariff.

Do not add this logic to controllers, seeders, factories, or multiple call sites. It belongs in the domain/model creation boundary.

The snapshot is taken once at creation. Later changes to `gp_users.free` must not automatically update existing `gp_groups.free`.

Add MySQL-backed tests for at least:

- owner `free=true` → new group `free=true`;
- owner `free=false` → new group `free=false`;
- a caller-provided contradictory `free` value cannot override the owner snapshot;
- changing the user tariff after group creation does not alter the existing group snapshot;
- a new group created after the tariff change receives the new current tariff.

### 2. Make public UUID fully application-owned

Update group creation so that every newly persisted group receives a fresh application-generated UUID v4.

Requirements:

- generate a new UUID v4 for every create;
- caller-provided `public_uuid` must not become the persisted identifier;
- keep the database unique constraint;
- keep existing post-create mutation protection;
- do not derive the UUID from internal IDs or caller data.

Prefer to make the application ownership explicit through model guarding/creation behavior rather than relying only on later controllers to omit the field.

Add tests for at least:

- automatic UUID generation;
- UUID v4 format;
- two created groups receive different UUIDs;
- supplying a valid custom UUID on create does not persist that supplied value;
- supplying an arbitrary invalid string on create cannot become the persisted value;
- modifying `public_uuid` after creation still throws and leaves the persisted UUID unchanged.

### 3. Preserve existing Stage 2 behavior

Do not change:

- status matrices;
- `accept` compatibility mapping;
- payment constraints;
- settings defaults;
- migrations unless a genuinely necessary schema correction is discovered;
- seed product values;
- Docker/test architecture;
- Stage 1 base-path/runtime behavior.

No schema migration should be necessary for the identified defects because the required columns/constraints already exist. If Codex concludes a migration is necessary, explain why in `.ai/report.md` and keep it strictly limited to these invariants.

### 4. Documentation

Update documentation only if actual documented Stage 2 behavior needs clarification.

At minimum `docs/project-status.md` should remain truthful after the correction. Avoid unnecessary documentation churn if the existing high-level statement already matches the corrected implementation.

### 5. Report

Update `.ai/report.md` for this task and include:

- exact implementation used for the tariff snapshot;
- exact implementation used to prevent caller-controlled UUIDs;
- tests added/changed;
- full verification commands and results;
- confirmation that Stage 3 was not started.

## Out Of Scope

Do not implement:

- Stage 3 Blade prototypes or any UI work;
- authentication or CRUD controllers;
- group creation HTTP flow;
- payment behavior;
- group lifecycle effects;
- API/email/scheduler/WEBPAY functionality;
- new product settings or dictionary values;
- unrelated Stage 2 refactoring.

Do not change `SPEC.md`, `WORKFLOW.md`, or `AGENTS.md`.

## Constraints

- Follow `WORKFLOW.md` and `AGENTS.md`.
- Work only on these two rejected Stage 2 invariants.
- Keep the diff surgical.
- Tests remain on MySQL in Docker.
- Do not duplicate snapshot/UUID logic across callers.
- `gp_groups.free` must come from the persisted owner’s current tariff at create time.
- `public_uuid` must be generated by the application and not caller-controlled.
- Existing `public_uuid` immutability after creation must remain enforced.
- No Node/npm/Vite, WEBPAY code, secrets, or later-stage functionality.
- Do not alter `.ai/task.md`.

## Acceptance Criteria

1. Creating a group for an owner with `free=true` persists `gp_groups.free=true`.
2. Creating a group for an owner with `free=false` persists `gp_groups.free=false`.
3. Caller-provided `free` cannot override the owner’s current tariff snapshot during create.
4. Changing `gp_users.free` after a group is created does not change that existing group’s `free` value.
5. A group created after the owner tariff changes receives the new tariff snapshot.
6. Every new group receives an application-generated UUID v4.
7. Caller-provided `public_uuid` cannot control the persisted UUID, including when the supplied value is itself a valid UUID.
8. Invalid caller-provided UUID text also cannot become the persisted identifier.
9. Two groups receive distinct UUIDs and the database unique constraint remains present.
10. Attempting to change `public_uuid` after creation still fails and the persisted UUID remains unchanged.
11. Existing Stage 2 domain tests continue to pass.
12. Full MySQL test suite passes.
13. Pint, Larastan, and `composer check-platform-reqs` pass.
14. No Stage 3+ or unrelated changes are introduced.
15. `.ai/report.md` accurately describes the correction and actual checks.

## Checks

Run and report at minimum:

1. `docker compose exec -T php php artisan test`
2. Focused MySQL-backed tests proving all tariff-snapshot cases above.
3. Focused tests proving caller-supplied UUIDs cannot control creation and post-create mutation remains blocked.
4. Verify through a persisted/fresh model read that the stored values, not only in-memory event values, satisfy the invariants.
5. Run:
   - `docker compose exec -T php ./vendor/bin/pint --test`
   - `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
   - `docker compose exec -T php composer check-platform-reqs`
6. Confirm PHPUnit still uses MySQL `gruppa_cabinet_test`.
7. Inspect the diff for unrelated migrations, Stage 3 files, secrets, WEBPAY code, or product-scope expansion.
8. Inspect `git diff`, `git status --short`, and staged files before commit.

## Hard Workflow Gate

Before changing files:

- read `WORKFLOW.md`, `AGENTS.md`, `SPEC.md`, `docs/project-status.md`, and this `.ai/task.md`;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm this task corresponds to the latest relevant `planner:` commit;
- do not touch unknown local changes.

During implementation:

- change only what is necessary for the two creation invariants;
- do not begin Stage 3;
- do not alter `.ai/task.md`;
- do not change governance/specification files;
- do not introduce unrelated abstractions or dependencies.

Before commit:

- run all required checks;
- update `.ai/report.md` with actual results;
- inspect full diff and staged files;
- stage only files belonging to this correction plus `.ai/report.md`;
- confirm no secrets, runtime artifacts, or unrelated files are staged.

Completion:

- use `Status: done` only if both rejected invariants are fully fixed and verified;
- otherwise use `partial`, `blocked`, or `failed`;
- if the gate passes, commit with:

```text
codex: TASK-2026-09-20-04 fix group creation invariants
```

- do not create an `accept:` commit.
