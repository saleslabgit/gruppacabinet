# AGENTS.md

Coding agent guidelines for this repository.

This file defines how Codex should write and change code.

For collaboration protocol, task planning, Codex handoff, reports, commit lifecycle, and acceptance flow, see:

```text
WORKFLOW.md
```

`WORKFLOW.md` is the process document.

`AGENTS.md` defines implementation behavior inside an approved task.

If these documents appear to conflict, stop and report the conflict instead of choosing an interpretation silently.

---

# Core Principles

## 1. Think First

Before changing code:

- read `.ai/task.md`;
- understand the actual goal;
- inspect the relevant files;
- identify facts;
- identify assumptions;
- identify unknowns;
- avoid guessing.

Do not write code before understanding the problem.

If a requirement is materially unclear and cannot be resolved from the task or repository, stop and report it.

---

## 2. Keep It Simple

Prefer the simplest solution that satisfies the requirement and acceptance criteria.

Do not:

- over-engineer;
- introduce abstractions without need;
- add configuration without need;
- generalize for hypothetical future cases;
- create frameworks around small changes;
- introduce dependencies without a clear task need.

Simple code is preferred over clever code.

---

## 3. Make Surgical Changes

Change only what is necessary to complete the current task.

Do not:

- refactor unrelated code;
- rename unrelated symbols;
- reformat unrelated files;
- move code without need;
- change public behavior outside the task;
- add features not requested;
- perform unrelated cleanup.

Minimize the diff while fully satisfying the task.

Task size and diff size are different concerns.

A task may represent a large product or technical milestone, while the implementation should still contain only the changes necessary to complete that milestone.

---

## 4. Preserve Existing Style

Follow the current project style.

Before adding new patterns:

- look for existing conventions;
- match naming style;
- match file organization;
- match error handling style;
- match testing style;
- reuse existing components and utilities when appropriate.

Prefer consistency over personal preference.

Do not rewrite working code only to make it match a different style.

---

## 5. Do Not Invent Facts

Do not assume APIs, schemas, environment variables, commands, dependencies, file locations, or project structure.

Verify from:

- `.ai/task.md`;
- repository files;
- tests;
- documentation;
- existing code;
- project configuration.

If something is not verified, treat it as unknown.

Do not silently fill missing information with assumptions that materially affect behavior.

---

## 6. Work Backwards From Acceptance

Every change should have a clear success condition.

Before implementation, understand the acceptance criteria.

Before finishing, verify:

- the requested behavior is implemented;
- unrelated behavior is preserved;
- relevant checks were run;
- applicable user or runtime flows were verified;
- remaining risks are documented.

If a required check cannot be run, explain why in `.ai/report.md`.

Never claim something was tested unless it was actually tested.

---

# Code Quality Rules

## UI / Design System

For every interface or frontend task, before implementation:

- read `DESIGN_SYSTEM.md`;
- inspect the relevant approved visual reference in `uikit/index.html`;
- identify the shared design-system components and tokens that the task must reuse.

Implement UI by composing shared reusable Blade components and approved tokens, not page-specific visual copies. Use only the approved component variants, spacing, typography, colors, radii, states, responsive behavior, and page patterns. Do not restyle or "improve" approved components based on personal preference, and do not introduce a new visual variant or one-off design primitive without an explicit approved design-system change.

For visual questions, explicit numeric values and implementation rules in `DESIGN_SYSTEM.md` take priority over `uikit/index.html`; the visual reference governs approved visual intent not already resolved by that document. If a required pattern or value is absent or explicitly unresolved, stop and report the gap for a user decision rather than inventing it.

Treat `uikit/` as reference material only, not production runtime code or a dependency. Continue to obey all project-level stack, architecture, security, runtime, and asset-delivery constraints; a design reference cannot silently override them.

## Prefer Explicit Code

Use clear, direct code.

Avoid:

- clever one-liners;
- hidden side effects;
- unnecessary indirection;
- magic behavior;
- premature optimization.

Readable code is better than compact code.

---

## Preserve Boundaries

Respect the existing architecture.

Do not cross module or layer boundaries casually.

Do not move responsibilities between layers unless the task requires it.

If the correct fix appears to require an architectural change beyond the current task, stop and report the tradeoff.

Do not introduce a new architectural pattern when an existing project pattern solves the problem adequately.

---

## Handle Errors Deliberately

Do not swallow errors silently.

Follow existing project conventions for:

- validation;
- exceptions;
- logging;
- user-facing errors;
- retries;
- fallback behavior.

Do not add noisy logging unless needed.

Do not hide failures behind fallback behavior unless that behavior is explicitly intended.

---

## Reuse Before Creating

Before adding a new helper, service, component, abstraction, or utility:

- check whether an equivalent already exists;
- reuse existing project primitives when appropriate;
- avoid duplicate implementations.

Do not force reuse when it makes the solution harder to understand or violates current boundaries.

---

# Tests and Verification

Use the smallest verification set that is sufficient to validate the task.

Prefer existing test commands and project scripts.

When changing behavior:

- add or update tests when appropriate;
- run relevant tests;
- run required checks from `.ai/task.md`;
- verify applicable runtime or user flows;
- report what was run;
- report what was not run.

Do not substitute a broad but irrelevant test suite for a check that directly validates the changed behavior.

Do not claim successful verification if the actual user-facing or runtime result was not checked when the task requires it.

---

# Repository and Data Safety

Do not commit:

- secrets;
- credentials;
- access keys;
- sensitive local configuration;
- user or production data;
- temporary files;
- logs;
- caches;
- unrelated generated artifacts.

Treat external systems, production data, destructive operations, and irreversible changes cautiously.

If the task could modify sensitive data, an external system, or production state, follow the explicit constraints in `.ai/task.md`.

If those constraints are missing or ambiguous, stop rather than guessing.

---

# Git Discipline

Before changing files:

```bash
git status --short
```

Do not overwrite, revert, clean, stage, or otherwise modify unknown local changes.

Do not use:

```bash
git add .
```

unless explicitly allowed.

Stage only files related to the current task.

Before commit:

- inspect the diff;
- inspect staged files;
- make sure unrelated changes are not staged;
- make sure secrets or sensitive data are not staged;
- run applicable checks;
- update `.ai/report.md`.

Follow the commit lifecycle defined in `WORKFLOW.md`.

---

# Documentation

Update documentation when the task changes behavior, setup, architecture, interfaces, operational flow, or other documented project facts.

Do not make unrelated documentation changes.

Documentation must describe the implemented state, not planned or assumed behavior.

---

# What Not To Do

Do not:

- add unrelated cleanup;
- modernize code without request;
- introduce new dependencies without need;
- change formatting globally;
- rewrite working code for style reasons;
- expand scope;
- hide uncertainty;
- claim verification that was not performed;
- silently resolve product ambiguity;
- bypass acceptance criteria;
- alter `.ai/task.md` unless explicitly requested.

---

# Default Behavior

When working on a task:

1. Read `WORKFLOW.md`.
2. Read `AGENTS.md`.
3. Read `.ai/task.md`.
4. Inspect repository status and relevant files.
5. Identify facts, assumptions, and unknowns.
6. Understand acceptance criteria.
7. Make the smallest correct set of changes that completes the task.
8. Run the required and relevant checks.
9. Verify applicable runtime or user flow.
10. Update `.ai/report.md`.
11. Inspect diff and staged files.
12. Complete the task according to the commit rules in `WORKFLOW.md`.

Bias toward caution over speed for non-trivial work.

For trivial fixes, keep the implementation lightweight, but do not bypass explicit workflow gates or task constraints.
