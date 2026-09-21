# Task: TASK-2026-09-21-09

Status: planned
Created from: e96ce0a8c41282827513f9b3fb6fea3f140dacce (main)

## Title

Global application design audit and full UI/UX refactor of every page

## Executor

Codex.

## Goal

Perform a complete design audit of the entire cabinet and immediately fix every material UI/UX and visual-design problem found.

This is not an audit-only task and not a small cosmetic pass.

The current interface has accumulated systemic problems across navigation, wayfinding, spacing, hierarchy, components, forms, lists, states and responsive behavior. Treat this milestone as the point where the cabinet receives a coherent application-wide design system and every existing page is reviewed against the same standards.

Audit and refactor:

- public/auth surfaces;
- psychologist cabinet;
- administrator cabinet;
- all shared UI components;
- every currently real page from Stages 4–10;
- the existing prototype/future pages sufficiently to ensure the shared design system works when Stages 11–14 connect them;
- desktop, tablet and mobile behavior.

The product owner explicitly authorizes substantial Blade/CSS restructuring where it improves usability, hierarchy, navigation, consistency or responsiveness.

The result must feel like one intentionally designed application, not a collection of prototype screens connected over time.

## Base / Accepted Product State

Stages 1–10 business behavior is accepted through:

e96ce0a8c41282827513f9b3fb6fea3f140dacce

Do not change accepted business semantics.

This task explicitly supersedes the old Stage 3 visual baseline. The previous “do not redesign accepted views” rule does not apply here because this is the dedicated product-approved redesign milestone.

## Hard Boundary

### Allowed

You may change:

- Blade layouts;
- Blade page structure;
- shared Blade partials;
- Blade components;
- navigation markup/presentation;
- breadcrumbs;
- page headers;
- content hierarchy;
- visual grouping;
- forms/layout of existing fields;
- lists/tables/cards;
- status presentation;
- alerts/notices;
- empty/no-result/error states;
- confirmation modals;
- pagination presentation;
- responsive behavior;
- typography;
- spacing system;
- CSS design tokens;
- restrained product colors;
- borders/radii/shadows;
- hover/focus/active/disabled states;
- application/public/ui.css;
- application/public/ui.js only when minimal UI behavior is necessary;
- a local icon-library integration;
- minor presenter/ViewModel changes only to expose already-existing data in a cleaner UI;
- UI-focused tests or text expectations affected by justified design changes;
- docs/ui-pages.md if materially outdated;
- .ai/report.md.

### Forbidden

Do NOT change:

- database schema or migrations;
- business rules;
- authentication/session behavior;
- authorization semantics;
- user/group/payment/application status semantics;
- group status-transition rules;
- group lifecycle behavior;
- application processed/unprocessed semantics;
- retention behavior;
- document security/storage rules;
- scheduler behavior;
- payment behavior;
- WEBPAY;
- email;
- external/public API;
- Stage 11+ functionality;
- package/framework architecture;
- route semantics unless a harmless presentation-only alias is absolutely unavoidable; default is no route changes;
- SPEC.md;
- WORKFLOW.md;
- AGENTS.md;
- .ai/task.md.

Do not use this task for backend cleanup.

If a backend/product problem is discovered but is not necessary to complete the UI refactor, record it in .ai/report.md and leave it unchanged.

## Required Icon Library

Integrate **Bootstrap Icons** as the single application icon library.

Reason: the project already uses Bootstrap 5 and does not use a frontend build tool.

Requirements:

- use the official Bootstrap Icons distribution;
- vendor production assets locally under application/public/vendor or another clear local public path;
- no CDN;
- no runtime dependency on npm/Vite;
- document the exact Bootstrap Icons version in .ai/report.md;
- load icon CSS through a base-path-safe Laravel asset URL;
- use one icon family only;
- do not mix Bootstrap Icons with emoji, Font Awesome, inline random SVG sets or Unicode symbols for UI actions.

Use icons purposefully for:

- primary navigation;
- breadcrumbs where useful;
- back/parent navigation;
- search/filter;
- add/create;
- edit;
- view/open;
- save;
- submit/send;
- approve;
- revision;
- reject;
- activate/publish;
- copy;
- upload/download;
- documents;
- groups;
- applications;
- payments;
- dictionaries;
- settings;
- profile/user;
- logout;
- delete/destructive actions;
- pagination chevrons where appropriate;
- success/warning/error/info states when useful.

Accessibility rules:

- icons do not replace required text where the action is not universally obvious;
- decorative icons use aria-hidden="true";
- icon-only controls require an accessible name via aria-label and should be reserved for compact, universally understood actions;
- status meaning must never rely on icon alone;
- icon color must not be the only carrier of meaning;
- keep icon sizing/alignment consistent;
- do not sprinkle icons into every label/heading just for decoration.

Establish a small consistent icon mapping and reuse it.

## Design Audit Scorecard

Every page family must be evaluated against the dimensions below.

Use a 0–3 score internally while auditing:

- 0 = broken / missing / materially confusing;
- 1 = weak / inconsistent / needs redesign;
- 2 = acceptable production baseline;
- 3 = strong, clear and consistent.

By completion:

- no reviewed dimension may remain at 0 or 1 on a real page;
- no critical flow may have a navigation dead end;
- shared-pattern problems must be fixed at component/system level, not patched page by page.

Do not commit the full score spreadsheet unless useful; summarize systemic findings/fixes in .ai/report.md.

### D1 — Information architecture

Every page must answer quickly:

- Where am I?
- What object/workflow am I viewing?
- What is its current state?
- What requires my attention?
- What is the primary next action?
- How do I return to the parent context?

Avoid:

- repeated blocks explaining the same state;
- sections with unclear purpose;
- critical actions buried below low-priority information;
- duplicate metadata.

### D2 — Navigation and wayfinding

No dead-end pages.

Add a reusable breadcrumb system.

Expected hierarchy examples:

Psychologist:
- Мои группы > Название группы
- Мои группы > Название группы > Редактирование
- Мои группы > Название группы > Продление
- Мои группы > Название группы > Заявки
- Мои группы > Название группы > Заявки > Имя участника
- Мои данные

Admin:
- Психологи > Имя психолога
- Психологи > Имя психолога > Редактирование
- Психологи > Имя психолога > Документы
- Группы > Название группы
- Группы > Название группы > Редактирование
- Заявки > Имя участника
- Справочники > Название справочника
- Настройки
- Платежи

Rules:

- top-level section pages should not show redundant breadcrumbs;
- nested/detail/edit pages should;
- breadcrumb ancestors are real links;
- current breadcrumb item is non-clickable;
- do not use browser-history JavaScript as navigation;
- users must not depend on the browser Back button;
- forms and deep detail pages also need an obvious contextual Cancel/Back action where useful;
- back/cancel destinations must be deterministic, not arbitrary referrer behavior.

Create a reusable breadcrumb component rather than one-off markup.

### D3 — Global shell and navigation

Audit:

- product header/shell;
- psychologist navigation;
- admin navigation;
- current state;
- logo/product identity;
- logout placement;
- desktop/tablet/mobile behavior;
- density.

Admin has many sections; make it highly scannable without hiding important destinations.

Psychologist navigation should remain lightweight.

Use icons consistently in navigation.

### D4 — Layout, grid and spacing

Establish a coherent application layout system.

Audit:

- page max width;
- content widths;
- form widths;
- table/list widths;
- gutters;
- page padding;
- section spacing;
- internal component spacing;
- alignment of left/right edges;
- vertical rhythm.

Avoid arbitrary spacing values.

Use a small spacing family, for example:

4 / 8 / 12 / 16 / 24 / 32 / 48 px

Exact tokens may differ, but they must form a system.

Fix:

- elements touching viewport edges;
- giant gaps;
- cramped controls;
- inconsistent section spacing;
- nested panel padding multiplication;
- random margins added page-by-page.

### D5 — Typography

Create a clear hierarchy for:

- page title;
- object title;
- section title;
- card/list title;
- body;
- form label;
- metadata;
- helper text;
- status/eyebrow;
- table headers.

Rules:

- page titles must not dominate the viewport;
- body must be comfortable for Russian text;
- line-height must be deliberate;
- helper/meta text must remain readable;
- heading levels must have visible distinction;
- semantic h1/h2/h3 order must be sensible;
- avoid “everything bold”;
- avoid very small secondary text.

Keep local Montserrat.

### D6 — Color and contrast

Audit:

- text/background contrast;
- muted text;
- links;
- borders;
- active navigation;
- statuses;
- alerts;
- disabled controls;
- destructive actions.

Use a restrained palette.

Avoid:

- too many unrelated accent colors;
- low-contrast gray-on-gray text;
- color as the only meaning;
- heavy colored surfaces for low-priority information.

### D7 — Component correctness

Use the correct semantic/control pattern.

Examples:

- navigation -> links;
- mutations/submissions -> buttons/forms;
- destructive action -> button + confirmation;
- status -> status/badge;
- tabular data -> table only when columns help comparison;
- unrelated detail data -> definition/section layout, not fake table;
- no clickable div/span;
- do not make plain text look clickable;
- do not use disabled buttons as the only explanation of unavailable functionality.

Audit whether each current panel/card/table/button is actually the right component.

### D8 — Action hierarchy

Every page should have one clear primary action or intentionally none.

Define and consistently style:

- primary;
- secondary;
- tertiary/text;
- destructive.

Audit:

- button prominence;
- action grouping;
- action location;
- duplicated actions;
- mobile wrapping.

Avoid:

- three equal primary buttons;
- delete sitting beside save with equal prominence;
- primary action hidden below unrelated content;
- action clusters with no visual hierarchy.

### D9 — Interaction states

Every interactive element must have intentional states:

- default;
- hover;
- focus-visible;
- active/pressed;
- current/selected;
- disabled.

Apply to:

- global navigation;
- breadcrumb links;
- text links;
- buttons;
- ghost/text buttons;
- table/list row actions;
- pagination;
- selects;
- inputs;
- checkboxes/radios;
- modal controls;
- copy control;
- dropdowns.

Hover should communicate interactivity on pointer devices.

Focus-visible must be clearly visible for keyboard users.

Never remove focus outlines without an equal or better replacement.

### D10 — Forms

Audit every form for:

- field grouping;
- field order;
- label clarity;
- required indication;
- input width;
- textarea size;
- select choice presentation;
- checkbox/radio semantics;
- help-text usefulness;
- error placement;
- error summary;
- old input after validation;
- Save vs Submit distinction;
- Cancel/back behavior;
- destructive action separation;
- mobile keyboard/inputmode where already appropriate.

Rules:

- help text only when useful;
- do not repeat obvious instructions;
- field errors belong near fields;
- error summary should be useful, not noisy duplication;
- long forms need clear sections;
- Save / Submit / Send / Approve actions must be unambiguous.

Do not change validation requirements.

### D11 — Lists, tables and cards

Optimize for scanning.

For each list ask:

- What is the primary identifier?
- Where is status?
- Which metadata matters?
- Which columns are essential?
- Where are actions?
- What can be removed from the row?
- Does row hover help?
- How do long values wrap?
- How does the pattern transform on mobile?

Admin lists may be denser than psychologist lists.

Avoid giant card rows if a compact structured list/table is more effective.

### D12 — Detail pages

Avoid a vertical stack of visually identical panels.

A detail page should establish:

1. identity/title;
2. status;
3. primary action;
4. critical warning;
5. essential facts;
6. related information;
7. history/secondary data.

Use stronger section hierarchy and less repeated chrome.

### D13 — Status and lifecycle communication

Audit all status-heavy flows:

Users:
- pending;
- approved;
- rejected;
- disabled.

Groups:
- draft;
- moderation;
- revision;
- rejected;
- approved;
- active;
- warning;
- expired;
- outside extension window;
- disabled.

Applications:
- new;
- processed.

Requirements:

- status must be easy to find;
- label and color/icon mapping must be consistent;
- current state should not be explained three times;
- warning/action guidance appears only when actionable;
- avoid conflating status with tariff/access/payment.

Do not change status semantics.

### D14 — Alerts, notices and feedback

Audit:

- success;
- info;
- warning;
- danger;
- validation;
- empty;
- no-results;
- unavailable functionality.

Alerts should be compact and proportional to importance.

Do not use full-card warning surfaces for minor notes.

Success feedback should not dominate the next task.

### D15 — Empty and no-result states

Every empty state should explain:

- what is empty;
- whether this is expected;
- what the user can do next, if anything.

Differentiate:

- first-use empty;
- filter/search no-results;
- temporarily unavailable future feature.

Do not show a CTA when the user cannot actually perform it.

### D16 — Pagination and filters

Audit:

- active page;
- previous/next affordance;
- hover/focus;
- query preservation;
- mobile size;
- filter grouping;
- reset action;
- applied filter visibility.

Filters should not visually dominate the page.

### D17 — Confirmation and destructive actions

Audit every dangerous action.

Requirements:

- destructive actions are visually distinct but not constantly dominant;
- confirmations clearly name the object/effect;
- cancel is obvious;
- modal fits 390px viewport;
- keyboard/focus behavior remains usable;
- no nested-form invalid markup;
- copy/approve/reject/revision dialogs use consistent structure.

### D18 — Breadcrumbs and contextual return

This is a hard requirement due to current product issues.

Every deep page must provide:

- breadcrumbs;
- a clear path to parent list/context;
- a sensible Cancel/Back control when editing.

Check every detail/edit/document/application/dictionary page for dead ends.

### D19 — Responsive behavior

Validate at minimum:

- 1440px;
- 1024px;
- 390px.

Do not merely stack the desktop UI.

At 390px:

- no page-level horizontal overflow;
- navigation remains usable;
- breadcrumbs wrap or collapse gracefully;
- actions do not become tiny clusters;
- tables become readable cards/structured blocks where appropriate;
- forms use available width;
- touch targets are sensible;
- modal content fits viewport;
- long values wrap;
- primary action remains easy to find.

### D20 — Accessibility baseline

This is not a formal certification, but fix obvious issues:

- semantic landmarks/headings;
- labels associated with controls;
- aria-current;
- accessible names for icon-only buttons;
- keyboard-visible focus;
- disabled clarity;
- color contrast;
- modal controls;
- text alternatives where needed.

### D21 — Microcopy

Audit wording for:

- button labels;
- statuses;
- alerts;
- empty states;
- help text;
- confirmation dialogs;
- headings.

Prefer concise action-oriented Russian.

Avoid technical/internal language where user-facing wording exists.

Do not alter business meaning.

### D22 — Consistency

The same concept must look and behave the same everywhere.

Audit consistency for:

- page header;
- breadcrumbs;
- section header;
- buttons;
- forms;
- statuses;
- alerts;
- lists;
- pagination;
- filters;
- dates;
- money;
- dangerous actions;
- empty states.

Prefer shared component changes over per-page overrides.

### D23 — Visual polish

Final pass for:

- alignment;
- hover quality;
- icon baseline;
- border consistency;
- radius consistency;
- line wrapping;
- orphaned labels;
- awkward whitespace;
- jitter from inconsistent button heights;
- table/card rhythm;
- mobile edge cases.

## Application-Wide Audit Coverage

You must review every page group, not only the most important flows.

There are 31 existing page groups / 249 prototype variants.

Do NOT manually inspect all 249 variants.

Instead:

- inspect every distinct page group;
- inspect all real routes;
- inspect representative state variants for components/statuses;
- use shared component fixes so the remaining variants inherit the improvements;
- run the full prototype test suite at the end.

## Token / Context Efficiency

Do not read the whole repository.

### Read first

1. WORKFLOW.md
   - only planner/executor/report/commit rules.

2. this .ai/task.md.

3. docs/project-status.md
   - Stages 4–10 and intentionally-not-implemented.

4. docs/ui-pages.md
   - page groups and real wiring sections;
   - do not read every prototype URL description unless needed.

5. SPEC.md
   Search/read only:
   - UI/design section around 24;
   - rule 24.5;
   - #25 Responsive.
   This task explicitly authorizes redesign.

6. application/routes/web.php
   - route map only.

### UI foundation

Read:

- application/public/ui.css
- application/public/ui.js
- application/resources/views/layouts/app.blade.php
- application/resources/views/layouts/surface.blade.php
- application/resources/views/layouts/admin.blade.php
- application/resources/views/layouts/psychologist.blade.php
- application/resources/views/layouts/public.blade.php

### Core components

Prioritize:

- components/navbar.blade.php
- components/sidebar.blade.php
- components/page-header.blade.php
- components/panel.blade.php
- components/button.blade.php
- components/status.blade.php
- components/alert.blade.php
- components/empty.blade.php
- components/table.blade.php
- components/cell.blade.php
- components/pagination.blade.php
- components/input.blade.php
- components/select.blade.php
- components/textarea.blade.php
- components/checkbox.blade.php
- components/confirmation.blade.php
- components/validation-summary.blade.php
- date/money components if needed.

Create a reusable breadcrumb component.

### Shared product partials

Review:

- shared/group-form.blade.php
- shared/group-data.blade.php
- shared/group-summary.blade.php
- shared/group-history.blade.php
- shared/application-list.blade.php
- shared/application-detail.blade.php
- shared/application-counters.blade.php
- shared/profile-data.blade.php
- shared/documents.blade.php
- shared/payment-data.blade.php if present.

### Psychologist pages

Review all:

- psychologist/groups/index.blade.php
- psychologist/groups/form.blade.php
- psychologist/groups/show.blade.php
- psychologist/groups/extension.blade.php
- psychologist/applications/index.blade.php
- psychologist/applications/show.blade.php
- psychologist/profile/show.blade.php
- psychologist/payments/placement.blade.php
- psychologist/payments/return.blade.php

### Admin pages

Review all:

- admin/home.blade.php
- admin/users/index.blade.php
- admin/users/show.blade.php
- admin/users/form.blade.php
- admin/users/documents.blade.php
- admin/groups/index.blade.php
- admin/groups/show.blade.php
- admin/groups/form.blade.php
- admin/applications/index.blade.php
- admin/applications/show.blade.php
- admin/payments/index.blade.php
- admin/payments/show.blade.php
- admin/dictionaries/index.blade.php
- admin/dictionaries/items.blade.php
- admin/settings/index.blade.php

### Public/system pages

Review:

- auth/login.blade.php
- auth/password.blade.php
- errors/403.blade.php
- errors/404.blade.php
- errors/419.blade.php
- errors/429.blade.php
- errors/500.blade.php
- shared/notices.blade.php
- prototype/index.blade.php only enough to keep it usable.

### Presenters only when needed

Prefer not to inspect backend.

Allowed focused support classes:

- App\Support\GroupPages
- App\Support\ApplicationPages
- App\Support\PsychologistPages
- App\Support\PsychologistCabinetPages
- PrototypeFixtures only when a prototype cannot render after UI changes.

Do not wander into domain services/controllers/tests unless a visible bug requires understanding existing data.

## Local Browser Access

Base URL:

http://localhost:8080/cabinet

Accounts:

- admin@gruppa.test / password
- psychologist@gruppa.test / password

Use current local synthetic data.

Do not mutate domain data just to manufacture every rare state.

Use prototype pages for rare states.

## Representative Browser Audit Routes

### Public

- /cabinet/login

### Psychologist real

- /cabinet/
- /cabinet/profile
- one real /cabinet/groups/{id}
- one real /cabinet/groups/{id}/edit
- one real /cabinet/groups/{id}/extension
- one real /cabinet/groups/{id}/applications
- one real application detail

### Admin real

- /cabinet/admin
- /cabinet/admin/psychologists
- one psychologist detail
- one psychologist edit
- documents
- /cabinet/admin/groups
- one group detail
- one group edit
- /cabinet/admin/applications
- one application detail
- /cabinet/admin/dictionaries
- one dictionary items page
- /cabinet/admin/settings
- /cabinet/admin/payments

## Prototype Coverage

Inspect at least one representative state for every distinct prototype-only page group.

Prioritize:

- login validation/error;
- password normal/validation/expired;
- system errors;
- notices/confirmation;
- group revision/rejected/warning/expired/outside-window;
- group form validation/long;
- placement;
- payment pending/success/result;
- extension free/paid/outside-window;
- application long/processed;
- admin group moderation/validation/paid-rejected;
- admin applications long;
- admin payment detail/refund states;
- dictionary used/deactivated;
- settings validation.

Do not inspect every permutation once shared components are stable.

## Implementation Order

### Pass 1 — establish design system

Before page-by-page fixes:

- spacing tokens;
- typography tokens;
- color tokens;
- radii/borders/shadows;
- link states;
- button system;
- icon system;
- status system;
- alert system;
- focus system;
- global content widths.

### Pass 2 — application shell and wayfinding

Implement/fix:

- public shell;
- psychologist shell;
- admin shell;
- responsive nav;
- breadcrumb component;
- page-header pattern;
- back/context pattern.

### Pass 3 — shared patterns

Fix:

- forms;
- tables/lists/cards;
- details;
- filters;
- pagination;
- empty states;
- confirmation modals;
- timeline/history;
- documents;
- counters.

### Pass 4 — psychologist pages

Walk full flows and fix page-specific issues.

### Pass 5 — admin pages

Walk full flows and fix page-specific issues.

### Pass 6 — prototype/future surfaces

Apply the new system to not-yet-real payment/password/error states.

### Pass 7 — responsive and interaction polish

Validate all representative pages at:

- 1440;
- 1024;
- 390.

Check hover/focus/active states, wrapping and modal behavior.

## Do Not Overdesign

The application is a professional working cabinet.

Avoid:

- glassmorphism;
- gradients everywhere;
- huge decorative hero sections;
- excessive animation;
- excessive shadow;
- excessive border-radius;
- oversized icons;
- playful consumer-app patterns;
- icon-only interfaces that hurt clarity;
- decorative dashboard charts without product value.

Prefer calm, precise, work-oriented design.

## UI JS Rules

Use JavaScript only for interaction that cannot reasonably be done with HTML/Bootstrap:

- existing copy feedback;
- minimal progressive enhancement;
- optional responsive navigation behavior if Bootstrap does not already solve it.

No SPA behavior.
No client-side business logic.
No dependency.

## Required Browser/Interaction Checks

For each representative interactive component verify:

- pointer hover;
- keyboard focus;
- active/current state;
- disabled state if applicable;
- click/tap target;
- mobile wrapping.

At minimum manually inspect:

### 1440

- psychologist groups;
- group form;
- profile;
- admin psychologists;
- admin group detail;
- admin applications;
- dictionaries;
- settings.

### 1024

- psychologist group detail;
- psychologist applications;
- admin groups;
- admin psychologist detail;
- admin applications;
- payments info.

### 390

- login;
- psychologist groups;
- group form;
- group detail;
- application list;
- application detail;
- profile/documents;
- admin navigation;
- admin psychologists;
- admin group detail/moderation;
- admin applications;
- dictionary items;
- settings;
- one confirmation modal;
- one validation-error form.

## Tests / Verification Strategy

Do not rerun the entire suite after every CSS change.

During work:

- use browser iteration;
- run targeted prototype/UI tests after shared component changes;
- run view:cache after larger Blade edits.

Before commit run:

1. docker compose exec -T php php artisan test
2. docker compose exec -T php ./vendor/bin/pint --test
3. docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress
4. docker compose exec -T php composer check-platform-reqs
5. docker compose exec -T php php artisan view:cache

Also verify:

- all 31 / 249 prototype routes/tests remain green;
- production still excludes prototype/foundation routes;
- no real route/business semantics changed;
- browser console has no new JS errors;
- no page-level horizontal overflow at 390 on representative pages;
- icon assets load under /cabinet base path;
- no external CDN request is used for the icon library.

## Required .ai/report.md

Keep the report concise but complete.

Include:

### Status
done / partial / blocked / failed.

### Design system
Final:
- spacing;
- typography;
- color;
- surface;
- radius;
- action;
- status;
- icon;
- responsive direction.

### Navigation / wayfinding
Describe:
- new breadcrumb rules;
- back/cancel conventions;
- admin/psychologist nav changes;
- dead-end pages fixed.

### System-level fixes
Summarize:
- components;
- forms;
- lists/tables;
- detail pages;
- alerts;
- states;
- focus/hover;
- mobile.

### Page-family fixes
Psychologist:
- groups;
- group form/detail/extension;
- applications;
- profile/documents;
- future payment views.

Admin:
- home;
- psychologists;
- groups/moderation;
- applications;
- payments;
- dictionaries;
- settings.

Public/system:
- login/password;
- errors;
- notices.

### Icon library
Exact Bootstrap Icons version and local asset paths.

### Remaining issues
Only unresolved UI issues.

### Verification
Exact automated checks and representative browser widths/routes.

### Files changed
Grouped by:
- foundation/assets;
- components/layouts;
- psychologist;
- admin;
- public/prototypes;
- support/tests/docs/report.

Do not write a long chronological diary.

## Acceptance Criteria

1. All real application page families have been visually audited.
2. All 31 distinct prototype page groups inherit the new design system and remain functional.
3. Every nested/detail/edit page has appropriate breadcrumbs/contextual navigation.
4. No user must rely on browser Back to leave a page.
5. No material navigation dead end remains.
6. Global admin navigation is clear, compact and responsive.
7. Psychologist navigation is clear and lightweight.
8. Bootstrap Icons is locally integrated with no CDN/npm runtime.
9. Icon use is consistent and accessible.
10. Spacing follows a coherent token system.
11. Typography has a consistent semantic/visual hierarchy.
12. Links/buttons have clear hover/focus/active states.
13. Current navigation and pagination states are obvious.
14. Primary/secondary/destructive action hierarchy is consistent.
15. Forms have coherent grouping, labels, errors and save/submit hierarchy.
16. Lists/tables are scan-friendly and use appropriate density.
17. Detail pages are not repetitive stacks of identical panels.
18. Status/lifecycle presentation is consistent and easy to understand.
19. Alerts/notices are proportional to importance.
20. Empty/no-result/unavailable states are clear and actionable only when appropriate.
21. Confirmation/destructive flows are consistent and fit mobile.
22. Breadcrumbs, navigation and actions use correct semantic HTML.
23. Focus-visible states are usable with keyboard navigation.
24. Long Russian text, email, UUID and phone values wrap safely.
25. 390px representative pages have no page-level horizontal overflow.
26. Mobile pages are intentionally composed, not merely stacked desktop UI.
27. 1024px tablet layouts remain practical.
28. 1440px layouts use space efficiently without over-wide text.
29. Business form field names/values/CSRF/method spoofing remain correct.
30. Accepted business/domain/auth/lifecycle/application behavior is unchanged.
31. No Stage 11+ functionality is introduced.
32. Prototype routes remain development/testing-only.
33. Full MySQL test suite passes.
34. Pint passes.
35. Larastan passes.
36. Composer platform check passes.
37. Blade compilation passes.
38. Browser console has no new errors.
39. No external icon CDN/request is introduced.
40. .ai/report.md documents the completed design audit/refactor and any remaining issues.
41. Final diff contains only justified UI/design assets, minimal presenter/UI test/docs changes and report.
42. No secrets, real personal data, browser artifacts or screenshots are committed.

## Hard Workflow Gate

Before editing:

- confirm current base is e96ce0a8c41282827513f9b3fb6fea3f140dacce;
- run git log --oneline -5;
- run git status --short;
- review unknown local changes before touching files;
- read only the focused files listed above first.

During work:

- audit and fix immediately;
- fix shared systemic problems before page-specific patches;
- use the scorecard consistently;
- do not change backend/business behavior;
- do not implement Stage 11;
- do not edit .ai/task.md;
- do not modify SPEC/WORKFLOW/AGENTS.

Before commit:

- inspect full diff;
- remove temporary browser/debug artifacts;
- update .ai/report.md;
- run all required checks;
- stage only justified task files.

Completion:

- Status: done only if the full design audit/refactor and checks are complete;
- otherwise partial / blocked / failed.

If complete, commit with:

codex: TASK-2026-09-21-09 audit and refactor application design

Do not create an accept commit.
