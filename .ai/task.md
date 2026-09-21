# Task: TASK-2026-09-21-09

Status: planned
Created from: e96ce0a8c41282827513f9b3fb6fea3f140dacce (main)

## Title

Global UI/UX audit and refactor after Stage 10

## Executor

Claude Code.

The product owner explicitly authorizes a substantial UI/UX refactor in this task.

This is NOT audit-only: audit the interface and immediately fix the problems you find.

## Goal

Bring the real cabinet UI to a coherent, production-quality state now that Stages 1–10 are working.

Audit and improve both roles:

- psychologist cabinet;
- administrator cabinet.

The current Stage 3 visual baseline is no longer a constraint. It may be significantly reworked where necessary.

The task should result in a cabinet that feels like one deliberate product rather than a collection of prototype pages wired to backend flows.

## Hard Boundary

You may change the UI layer substantially.

### Allowed

- Blade layouts;
- Blade pages;
- shared Blade partials/components;
- UI component structure;
- navigation structure/presentation;
- information hierarchy;
- page composition;
- tables/cards/lists;
- forms;
- filters;
- statuses;
- alerts;
- confirmations;
- empty states;
- responsive behavior;
- typography;
- spacing;
- borders/radii/shadows;
- colors/tokens within the existing brand direction;
- `application/public/ui.css`;
- `application/public/ui.js` when needed for UI behavior;
- minor view-presenter changes only when needed to expose already-existing data in a cleaner way;
- UI-focused tests that must change because markup/labels/navigation changed;
- `.ai/report.md`;
- UI documentation only if it is now materially inaccurate.

### Forbidden

Do NOT change:

- database schema/migrations;
- business rules;
- status transitions;
- authorization semantics;
- policies except a purely UI-unblocking bug discovered during the work, and only if explicitly documented;
- payment behavior;
- scheduler behavior;
- retention behavior;
- group lifecycle behavior;
- application processing semantics;
- document security;
- authentication/session behavior;
- external/public API;
- WEBPAY;
- email;
- Stage 11+ functionality;
- dependencies/framework;
- routes unless a UI restructuring absolutely requires a harmless navigation alias; default is no route changes;
- `SPEC.md`, `WORKFLOW.md`, `AGENTS.md`;
- `.ai/task.md`.

Do not use this task as an excuse for backend cleanup.

If a backend issue is discovered but is not required to complete the UI refactor, report it in `.ai/report.md` and leave it untouched.

## Product Intent

The product owner expects that many current interface decisions are weak and is open to a near-complete visual/compositional rethink.

Prioritize:

1. clarity;
2. visual hierarchy;
3. fast scanning;
4. obvious next action;
5. lower cognitive load;
6. predictable patterns;
7. responsive behavior;
8. consistency between psychologist/admin;
9. restrained, professional aesthetics;
10. usability over preserving old markup.

Avoid:

- excessive cards/panels;
- repeated status explanations;
- duplicated information;
- oversized headings;
- unnecessary borders;
- excessive rounded containers;
- giant empty spaces;
- weak action hierarchy;
- multiple equally-prominent buttons;
- verbose helper text everywhere;
- desktop-first layouts that merely stack badly on mobile;
- “prototype/demo” visual language in real UI;
- accidental visual differences between similar CRUD pages.

## Token / Context Efficiency

Do NOT read the whole repository.

Do NOT inspect all backend services/tests.

Do NOT browse all 249 prototype variants.

Do NOT read the full SPEC.

Use the focused path below.

### First read only

1. `WORKFLOW.md`
   - only the planner/executor/report/commit rules.

2. this `.ai/task.md`.

3. `docs/project-status.md`
   - Stage 5–10 sections;
   - Intentionally not implemented.

4. `docs/ui-pages.md`
   - headings + real wiring sections;
   - do not read every prototype URL line.

5. `SPEC.md`
   Search only:
   - `24.2`;
   - `24.5`;
   - `# 25. Responsive`.
   The current task supersedes the old Stage 3 “do not redesign” rule because this is an explicit UI redesign task.

6. `application/routes/web.php`
   - only to map current real pages.

### Visual foundation files

Read these first:

- `application/public/ui.css`
- `application/public/ui.js`
- `application/resources/views/layouts/surface.blade.php`
- `application/resources/views/layouts/app.blade.php`
- `application/resources/views/layouts/admin.blade.php`
- `application/resources/views/layouts/psychologist.blade.php`

### Core components

Prioritize:

- `components/navbar.blade.php`
- `components/sidebar.blade.php`
- `components/page-header.blade.php`
- `components/panel.blade.php`
- `components/button.blade.php`
- `components/status.blade.php`
- `components/alert.blade.php`
- `components/empty.blade.php`
- `components/table.blade.php`
- `components/cell.blade.php`
- `components/pagination.blade.php`
- `components/input.blade.php`
- `components/select.blade.php`
- `components/textarea.blade.php`
- `components/checkbox.blade.php`
- `components/confirmation.blade.php`
- `components/validation-summary.blade.php`

Do not inspect every component unless a visible issue requires it.

### Shared product partials

- `resources/views/shared/group-form.blade.php`
- `resources/views/shared/group-data.blade.php`
- `resources/views/shared/group-summary.blade.php`
- `resources/views/shared/group-history.blade.php`
- `resources/views/shared/application-list.blade.php`
- `resources/views/shared/application-detail.blade.php`
- `resources/views/shared/application-counters.blade.php`
- `resources/views/shared/profile-data.blade.php`
- `resources/views/shared/documents.blade.php`

### Psychologist pages

- `psychologist/groups/index.blade.php`
- `psychologist/groups/form.blade.php`
- `psychologist/groups/show.blade.php`
- `psychologist/groups/extension.blade.php`
- `psychologist/applications/index.blade.php`
- `psychologist/applications/show.blade.php`
- `psychologist/profile/show.blade.php`

### Admin pages

- `admin/home.blade.php`
- `admin/users/index.blade.php`
- `admin/users/show.blade.php`
- `admin/users/form.blade.php`
- `admin/users/documents.blade.php`
- `admin/groups/index.blade.php`
- `admin/groups/show.blade.php`
- `admin/groups/form.blade.php`
- `admin/applications/index.blade.php`
- `admin/applications/show.blade.php`
- `admin/dictionaries/index.blade.php`
- `admin/dictionaries/items.blade.php`
- `admin/settings/index.blade.php`
- `admin/payments/index.blade.php`

### Only inspect presenters if needed

Use only these unless absolutely necessary:

- `App\Support\GroupPages`
- `App\Support\ApplicationPages`
- `App\Support\PsychologistPages`
- `App\Support\PsychologistCabinetPages`

Do not wander into domain services/controllers/tests unless required to understand a visible state.

## Local Access

Base URL:

`http://localhost:8080/cabinet`

Accounts:

- admin: `admin@gruppa.test` / `password`
- psychologist: `psychologist@gruppa.test` / `password`

Use existing local synthetic data.

Do not mutate business data just to create every rare state.

Use prototypes only for hard-to-reproduce states.

## Audit + Fix Workflow

Work in system-level passes, not random page patches.

### Pass 1 — global shell

Audit and immediately fix:

- page width;
- navigation;
- desktop/tablet/mobile layout;
- page header;
- global spacing scale;
- typography hierarchy;
- backgrounds/surfaces;
- primary/secondary/destructive action styling;
- status badges;
- alert density;
- focus states;
- modal/confirmation behavior.

Do this before page-specific polish.

### Pass 2 — reusable patterns

Audit and fix shared patterns:

- list/table/card pattern;
- form pattern;
- detail/read-only pattern;
- search/filter bar;
- counters/metrics;
- empty state;
- timeline/history;
- document list;
- pagination;
- validation state;
- dangerous action confirmation.

Prefer one coherent pattern over page-specific exceptions.

### Pass 3 — psychologist flows

Fix the full user journey:

1. groups list;
2. create/edit group;
3. group detail/status/history;
4. extension;
5. applications list/detail/process action;
6. profile/documents.

Focus on:
- next action clarity;
- status comprehension;
- action priority;
- reducing redundant blocks;
- mobile usability.

### Pass 4 — admin flows

Fix:

1. admin home;
2. psychologists list/detail/form/documents;
3. groups list/detail/form/moderation;
4. applications list/detail;
5. dictionaries/items;
6. settings;
7. payments informational state.

Admin must remain dense enough for work, but not visually noisy.

### Pass 5 — responsive polish

Validate and fix at:

- 1440;
- 1024;
- 390.

Do not just stack desktop UI.
Make deliberate mobile decisions.

## Real Browser Pages to Use

### Psychologist

- `/cabinet/`
- `/cabinet/profile`
- one `/cabinet/groups/{id}`
- one `/cabinet/groups/{id}/edit`
- one `/cabinet/groups/{id}/extension`
- one `/cabinet/groups/{id}/applications`
- one `/cabinet/groups/{id}/applications/{application}`

### Admin

- `/cabinet/admin`
- `/cabinet/admin/psychologists`
- one psychologist detail/edit/documents
- `/cabinet/admin/groups`
- one group detail/edit
- `/cabinet/admin/applications`
- one application detail
- `/cabinet/admin/dictionaries`
- one dictionary items page
- `/cabinet/admin/settings`
- `/cabinet/admin/payments`

## Prototype Pages — only representative rare states

Do not browse the full catalogue.

Use only if real data does not already show the state:

### Psychologist

- `/_prototype/groups/revision`
- `/_prototype/groups/rejected`
- `/_prototype/groups/warning`
- `/_prototype/groups/expired`
- `/_prototype/groups/outside-window`
- `/_prototype/group-form/validation`
- `/_prototype/group-form/long`
- `/_prototype/applications/long`

### Admin

- `/_prototype/admin-group/moderation`
- `/_prototype/admin-group/validation`
- `/_prototype/admin-group/paid-delete-blocked`
- `/_prototype/admin-groups/long`
- `/_prototype/admin-applications/long`
- `/_prototype/admin-user-form/validation`
- `/_prototype/admin-documents/long`
- `/_prototype/admin-settings/validation`

If a shared issue is already obvious, skip redundant prototype browsing.

## Viewport Checklist

### 1440

Must inspect:
- psychologist groups;
- group form;
- admin psychologists;
- admin group detail;
- admin applications;
- settings.

### 1024

Must inspect:
- psychologist group detail;
- psychologist applications;
- admin groups;
- admin psychologist detail;
- dictionaries.

### 390

Must inspect:
- psychologist groups;
- group form;
- application list;
- application detail;
- profile;
- admin psychologists;
- admin group detail;
- admin applications;
- settings;
- at least one confirmation modal.

## What to Improve

### Information hierarchy

Make it obvious:

- where the user is;
- what the current status is;
- what requires attention;
- what the primary action is;
- what is historical/secondary.

Avoid showing the same status explanation in multiple places.

### Navigation

Psychologist navigation should feel simple and lightweight.

Admin navigation has many sections; improve grouping/scannability/responsiveness without changing route semantics.

### Lists

Lists should support fast scanning.

Pay attention to:

- column/card density;
- primary identifier;
- status placement;
- metadata hierarchy;
- action placement;
- empty/no-result behavior;
- long content;
- mobile conversion.

### Forms

Improve:

- grouping;
- labels;
- help text density;
- field widths;
- required indicators;
- error location;
- save vs submit hierarchy;
- cancel/back behavior;
- sticky/action area only if actually useful.

Do not alter validation/business requirements.

### Detail pages

Avoid “stack of identical panels”.

Create stronger sections and reduce repetitive chrome.

Emphasize:
- identity/title;
- status;
- primary next action;
- important dates;
- critical warnings;
- contextual history.

### Admin moderation

This is a high-priority workflow.

Make moderation state and actions extremely clear:
- approve;
- revision;
- reject;
- activation;
- public_uuid copy/manual publication reminder.

Do not alter any moderation rules.

### Applications

Make phone/name/state/action scannable.

Owner processing action should be clear but not dominate every row.

### Settings/dictionaries

Make internal administrative tools compact and predictable.

Dangerous actions must remain clearly destructive.

### Mobile

At 390px:
- no page-level horizontal overflow;
- no tiny cramped action clusters;
- no unreadable tables;
- no modal overflow;
- controls have sensible touch targets;
- primary actions remain obvious;
- content order must make sense after stacking.

## Accessibility / Interaction

Fix obvious UI accessibility problems encountered during the refactor:

- semantic heading hierarchy;
- form labels;
- focus visibility;
- button/link misuse;
- disabled state clarity;
- modal focus/close behavior;
- aria-current;
- color contrast where clearly weak.

Do not turn this into a formal WCAG certification project.

## Technical Rules

- Blade + Bootstrap 5 + project CSS + minimal Vanilla JS only.
- No npm/Vite.
- No frontend framework.
- No new dependency.
- Keep Montserrat local.
- Keep base path-safe Laravel helpers.
- Keep production/prototype isolation.
- Do not hardcode `/cabinet` paths in Blade.
- Preserve CSRF/method spoofing/forms.
- Preserve all business form field names and submitted values.
- Preserve route names and controller contracts by default.

## Prototype Strategy

The prototype catalogue remains useful for rare visual states, but this refactor may change their appearance.

Do not preserve old screenshots/markup for its own sake.

Required:
- all prototype routes still render;
- no prototype route becomes a real business action;
- no prototype leaks into production;
- representative variants remain usable after shared component changes.

You do not need to visually inspect all 249 variants.

## Tests / Verification

Do not rerun expensive checks after every small change.

During iteration:
- use browser;
- use targeted UI/feature tests for changed shared views/components;
- use `php artisan view:cache` when appropriate.

Before commit run:

1. `docker compose exec -T php php artisan test`
2. `docker compose exec -T php ./vendor/bin/pint --test`
3. `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
4. `docker compose exec -T php composer check-platform-reqs`
5. `docker compose exec -T php php artisan view:cache`

Also verify:

- no real route/business behavior changed unintentionally;
- 31/249 prototype catalogue still renders/tests green;
- production has no prototype routes;
- no horizontal overflow on representative 390px pages;
- browser console has no new JS errors.

## Required Report

Update `.ai/report.md` with:

### 1. Status

done / partial / blocked / failed

### 2. UI direction

Concise description of the final visual/system direction.

### 3. System-level changes

What changed in:

- shell/navigation;
- typography;
- spacing;
- panels/surfaces;
- buttons/actions;
- status/alerts;
- forms;
- lists/tables/cards;
- responsive behavior.

### 4. Page-level changes

Psychologist:
- groups;
- group form/detail/extension;
- applications;
- profile/documents.

Admin:
- home;
- psychologists;
- groups/moderation;
- applications;
- dictionaries;
- settings;
- payments info.

### 5. Important UX decisions

List deliberate tradeoffs and why.

### 6. Remaining issues

Only things not addressed in this task.

### 7. Verification

Exact test/check/browser results.

### 8. Files changed

Group them by UI foundation/components/pages/support/tests/docs.

Do not write a huge narrative diary.

## Acceptance Criteria

1. Cabinet has one coherent UI system across psychologist/admin.
2. Global navigation is clear and responsive.
3. Page titles/actions/statuses have consistent hierarchy.
4. Excessive nested panels/cards are reduced.
5. Primary vs secondary vs destructive actions are visually obvious.
6. Lists are faster to scan.
7. Forms have coherent grouping and action hierarchy.
8. Group lifecycle/status UX is easier to understand.
9. Moderation actions are unambiguous.
10. Applications are easy to scan/process.
11. Profile/documents are readable without visual clutter.
12. Dictionaries/settings are compact and work-oriented.
13. Empty/error/validation states remain usable.
14. Long values wrap safely.
15. 390px representative pages have no page-level horizontal overflow.
16. Confirmation modals fit mobile viewport.
17. Touch targets and focus states remain usable.
18. Real business actions/forms/routes continue working.
19. No domain/business/auth/payment/lifecycle behavior changed.
20. No Stage 11+ functionality introduced.
21. Prototype catalogue remains functional.
22. Production prototype isolation remains.
23. Full MySQL suite passes.
24. Pint passes.
25. Larastan passes.
26. Composer platform check passes.
27. Blade compilation passes.
28. `.ai/report.md` clearly documents the refactor and remaining issues.
29. Final diff contains only justified UI-layer changes, minimal presenter/test/doc updates, and report.
30. No secrets, real personal data, browser artifacts or screenshots are committed.

## Hard Workflow Gate

Before editing:

- confirm HEAD/base `e96ce0a8c41282827513f9b3fb6fea3f140dacce`;
- `git status --short` must be reviewed;
- inspect only the focused files listed above;
- do not overwrite unknown local changes.

During work:

- audit and fix immediately;
- prefer system-level fixes over per-page hacks;
- do not change backend rules;
- do not implement Stage 11;
- do not edit this task.

Before commit:

- inspect complete diff;
- remove temporary debug/browser artifacts;
- update `.ai/report.md`;
- run required checks;
- stage only justified files.

If complete, commit with:

`claude: TASK-2026-09-21-09 refactor cabinet UI UX`

Do not create an `accept:` commit.
