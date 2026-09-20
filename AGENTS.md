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

## UI / Approved Blade Pages

This project does not use a separate abstract `DESIGN_SYSTEM.md` or `uikit/` as mandatory UI sources. The approved interface is created as the complete set of real Blade pages described by the specification.

### During Stage 3

For the full frontend/prototype stage:

- use the UI requirements and page catalogue in `SPEC.md`, especially sections 24 and 25;
- build pages directly in the final `application/resources/views/` structure;
- use real Blade layouts, partials, and reusable components;
- create the shared CSS variables/tokens and reusable visual primitives as part of the actual pages, not as a separate abstract UI-kit deliverable;
- use fixture/mock data and development-only prototype routes to show all required states without depending on unfinished backend flows;
- cover the required status variants, validation/error/empty/success states, destructive confirmations, long-content cases, pagination where applicable, and desktop/tablet/mobile layouts;
- keep prototype routes available only in `local`/`testing`;
- do not create disposable HTML copies that would later need to be ported into Blade.

The purpose of Stage 3 is to finish and approve the real page structure and visual behavior before the main backend CRUD work begins.

### After Stage 3 Is Approved

Before every task that affects UI, layout, frontend components, or responsive behavior:

- inspect the relevant approved Blade views, layouts, partials, and components;
- inspect the shared CSS/tokens actually used by those pages;
- inspect `docs/ui-pages.md` for the approved page and state catalogue;
- reuse the existing page structure and shared components instead of creating parallel markup or alternate visual implementations.

Backend tasks should primarily replace fixture data with real data, connect routes/actions, validation, authorization, and state-dependent behavior to the already approved views.

Small changes required by real backend integration are allowed when they preserve the approved structure and visual intent. A material change to page structure, interaction model, visual hierarchy, or responsive behavior must not be introduced silently. If such a change is required, stop and report it for an explicit user decision or a separately approved task.

If a required page or materially different UI state was not covered by the approved Stage 3 pages, do not invent a new product/UX pattern silently. Report the gap and obtain a decision when it materially affects behavior or scope.

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
