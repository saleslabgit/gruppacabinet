# Task: TASK-2026-09-21-01

Status: planned
Created from: 1070957220c003d825d2bf6a026171b6ee7bc8ec (main)

## Title

Stage 3 visual revision — full design audit, typography reset, density cleanup, and form-control refinement

## Execution Assignment

The user explicitly assigned this task to **Claude**.

For this task only, that explicit user instruction overrides the executor label in `WORKFLOW.md`. All other repository workflow, safety, scope, testing, reporting, and review rules remain in force.

Claude must treat `.ai/task.md` as the implementation contract, update `.ai/report.md`, and keep the implementation confined to this visual revision.

## Goal

Perform a complete visual/design audit of the accepted Stage 3 Blade frontend and revise the shared visual system across the entire prototype catalog.

The current Stage 3 information architecture, page coverage, product states, and business wording are broadly correct, but the visual execution is **not accepted**.

Primary user feedback:

- typography hierarchy is poor;
- the main page heading is often visually too close to subordinate headings/subtitles;
- typography feels oversized and inconsistent across the application;
- alerts/notices are excessively large and visually dominate pages;
- input controls are too rounded;
- the visual system must be reviewed globally rather than patched page by page.

This task must improve the entire interface systematically: typography, visual hierarchy, component density, spacing, alerts, forms, cards, navigation, tables, modals, and responsive composition.

The redesign must remain on the existing Blade + Bootstrap + project CSS + minimal Vanilla JS stack and must preserve the product structure and all Stage 3 page/state coverage.

## Facts

- Stage 3 implementation is commit `1070957220c003d825d2bf6a026171b6ee7bc8ec`.
- The prototype catalog contains 31 page groups and 249 direct variants.
- The actual final product Blade views already exist and must remain the production view source.
- Prototype routes use synthetic fixtures and are local/testing only.
- Montserrat 500 and 600 are already stored locally with Cyrillic support.
- Bootstrap 5.3.8 is local.
- There is no frontend build pipeline and none may be added.
- Current `ui.css` uses:
  - page title 32px;
  - section title 24px;
  - subsection 20px;
  - body 16px;
  - 32px large panel radius;
  - 24px normal radius;
  - pill one-line inputs/selects;
  - 24px alert padding;
  - many generously padded panels and large vertical gaps.
- Current visual hierarchy has been rejected by the product owner.
- The user explicitly requires **input rounding no greater than 10px**.
- The current product flows, page catalog, business statuses, safe WEBPAY wording, gruppa.info UUID block, and Stage 3 non-functional prototype boundary must be preserved unless a visual correction requires minor markup restructuring.

## Design Direction

The revised interface should feel:

- professional;
- calm;
- dense enough for a working cabinet/admin tool;
- clearly hierarchical;
- readable rather than oversized;
- modern without looking like a marketing landing page;
- visually restrained;
- consistent between psychologist and admin surfaces.

Avoid:

- oversized headings everywhere;
- multiple competing large headings on one screen;
- oversized alert blocks;
- excessive empty vertical space;
- over-rounded “bubble” UI;
- pill-shaped text inputs;
- cards inside cards without clear hierarchy;
- every section looking equally important;
- strong colors used for large surfaces without need;
- large bold text for routine metadata;
- decorative styling that hurts information density.

The existing orange brand palette may remain. This task is not a brand-color redesign unless a contrast/accessibility issue requires a small adjustment.

## Mandatory Typography Reset

Create a clear, centralized type hierarchy in shared CSS and apply it consistently to all pages.

### Font family and weights

Keep local Montserrat.

Use:

- 500 for normal body/supporting text;
- 600 for titles, labels, buttons, important values, and intentional emphasis.

Do not add extra font weights unless there is a proven design need and the font asset/license is handled correctly.

### Required hierarchy

Use the following target scale unless a very small technical adjustment is required for rendering:

#### Desktop

- page title / H1: **38px**, line-height about **1.15**, weight 600;
- section title / H2: **24px**, line-height about **1.30**, weight 600;
- card/subsection title / H3: **18px**, line-height about **1.35**, weight 600;
- body: **15px**, line-height about **1.55**, weight 500;
- form labels/buttons: **13–14px**, weight 600;
- supporting/small text: **13px**, line-height about 1.45;
- compact metadata: **12px**, line-height about 1.4.

#### Smartphone

- page title / H1: **30px**, line-height about 1.18;
- section title / H2: **21px**;
- card/subsection title / H3: **17px**;
- body remains approximately **15px**;
- supporting/meta text must remain readable and should not collapse below 12px.

### Hierarchy rules

- There must be an obvious visual difference between page title, section title, card title, body, and metadata.
- Do not use H2-sized typography inside routine alerts.
- Do not use large headings merely to label small cards.
- Avoid multiple H1-like elements on the same screen.
- Page supporting copy/subtitles should be visually subordinate to H1 through size, color, spacing, and weight.
- Long page titles must wrap cleanly without overwhelming the viewport.
- Dense admin/list pages should use compact but readable typography.
- Review every page for semantic heading order as well as visual size.

## Mandatory Form-Control Geometry

The user explicitly requires input rounding no greater than 10px.

Apply consistently:

- text inputs: border radius **8–10px**, never pill;
- email/password/number/date/file inputs: **8–10px**;
- selects: **8–10px**;
- textarea: preferably **10px**, maximum **12px** only if visually necessary;
- input groups or comparable one-line controls: no radius above 10px;
- validation/error controls use the same geometry rather than a different rounded style.

Target control height:

- ordinary one-line controls: approximately **42–44px**;
- avoid unnecessarily tall 48px+ fields unless an accessibility issue requires it.

Labels/help/errors should become more compact and clearly associated with controls.

Checkboxes/radios may keep native/Bootstrap geometry where appropriate.

## Alerts / Notices Redesign

The current notices are too large.

Redesign the shared alert/notice component and all alert usage.

Default notice target:

- padding approximately **10–12px vertical / 14–16px horizontal**;
- radius approximately **10–12px**;
- body text approximately **13–14px**;
- compact line-height;
- routine alerts should not use H2/H3 typography;
- margin below notice approximately 12–16px, not large card spacing;
- semantic border/background should remain visible but restrained.

Rules:

- a notice is not a full content panel;
- warning/success/info/danger states must not dominate the entire page unless the state is genuinely the primary page content;
- replace large headings inside alerts with a compact `notice-title`/strong label where appropriate;
- keep long warning text readable without creating huge colored blocks;
- validation summary should be concise;
- login/auth errors should not visually exceed the form itself;
- payment confirmation states may be more prominent, but still use intentional hierarchy rather than oversized generic alerts.

Audit every `<x-alert>` usage.

## Cards / Panels / Surfaces Audit

Reduce the “bubble UI” feeling.

Target geometry:

- major panel/card radius: approximately **16–18px**;
- table/list wrapper radius: approximately **12–16px**;
- compact nested surface radius: approximately **10–12px**;
- modals: approximately **16–18px**;
- badges/status chips may remain pill-shaped.

Do not use 24–32px radius as the default for normal work surfaces.

Panel padding targets:

- desktop: usually **20–24px**;
- tablet/mobile: usually **16–20px**;
- compact list/filter panels may use less.

Rules:

- avoid nested white cards where spacing/dividers can communicate hierarchy more clearly;
- avoid putting every small information group into a large panel;
- related metadata should be visually grouped without excessive containers;
- keep important actions easy to scan;
- auth screens may remain centered but should not look oversized.

## Buttons and Action Hierarchy Audit

Review all button styles and action clusters.

Requirements:

- primary action must be visually clear but not oversized;
- secondary/ghost/destructive actions must have predictable hierarchy;
- routine buttons should be approximately 40–44px high;
- avoid visually huge pill buttons in dense admin pages;
- reserve pill geometry primarily for status badges/chips; buttons may use a consistent moderate radius;
- destructive actions must remain clearly distinct;
- button text should use the compact control typography scale;
- mobile actions must wrap/stack cleanly;
- action groups must not create large vertical blocks.

If button radius is changed, choose one coherent shared value; do not create per-page variants.

## Spacing / Density Audit

Rework spacing globally instead of only changing font sizes.

Use a restrained shared scale centered around:

- 4;
- 8;
- 12;
- 16;
- 20;
- 24;
- 32;
- 40/48 when a major section break actually needs it.

Review:

- page header → first content section;
- panel padding;
- gaps between related fields;
- gaps between list rows;
- action groups;
- table cell padding;
- modal spacing;
- form section spacing;
- auth layout spacing;
- empty-state spacing.

Target behavior:

- information-dense admin screens should show materially more useful content above the fold;
- psychologist screens should remain approachable but not spacious to the point of looking unfinished;
- whitespace should indicate hierarchy, not be applied uniformly everywhere.

## Page Header Audit

The page header component is a priority.

Requirements:

- H1 must be unmistakably the primary page title;
- eyebrow/context text must be much smaller and quieter;
- supporting subtitle/description must not compete with H1;
- page actions align cleanly and do not visually outweigh the title;
- long titles wrap correctly;
- mobile page header stacks naturally;
- avoid giant vertical gaps below the page header.

Audit every page using the shared page header.

## Navigation Audit

Review top navigation and admin sidebar.

Requirements:

- navigation typography should be compact and work-oriented;
- active state is clear without oversized pills;
- wordmark/product title should not compete with page H1;
- logout remains visible but subordinate;
- tablet/mobile wrapping should feel intentional, not like desktop navigation accidentally wrapping;
- admin sidebar density should support quick scanning;
- preserve all existing navigation destinations and prototype behavior.

Do not redesign information architecture in this task.

## Tables / Lists Audit

Review every list/table surface.

Requirements:

- table text should generally be 13–14px;
- row/cell padding should be compact but usable;
- headers must be clearly distinct without becoming visually heavy;
- status/action columns should scan quickly;
- long names/UUIDs/emails wrap safely;
- desktop tables should use available width efficiently;
- mobile card transformation must preserve label/value hierarchy;
- application/group/payment/user lists should not feel like a stack of oversized marketing cards;
- pagination should be compact.

Where existing “one giant panel per row” layouts are visually inefficient, minor markup restructuring is allowed as long as product information and actions remain unchanged.

## Form Layout Audit

Review all forms:

- login;
- password setup;
- group form;
- psychologist admin form;
- documents;
- moderation comments;
- refund form;
- dictionaries;
- settings.

Requirements:

- clear section hierarchy;
- compact labels/help/errors;
- field groups should be visually related;
- validation should be noticeable but not visually overwhelming;
- required/optional markers should be subtle;
- field width should reflect content type where practical;
- long forms should be easier to scan;
- avoid excessive 32px panel padding around every form section;
- no input/select radius above 10px.

## Modal / Confirmation Audit

Review every confirmation dialog.

Requirements:

- compact title and body;
- modal should not look like a giant card;
- clear primary/destructive action;
- cancel remains obvious;
- content fits comfortably on 390px width;
- validation within moderation/refund modals remains readable;
- no giant alert blocks inside modal unless truly necessary.

## Status / Badge Audit

Keep status badges explicitly labeled.

Requirements:

- status badges may remain pill-shaped;
- compact height/padding;
- 12–13px text;
- color remains semantic and accessible;
- multiple statuses on one row should not create visual clutter;
- tariff/access metadata should not look as visually strong as lifecycle status unless intentionally needed.

## Empty States Audit

Current empty states must be reviewed for scale.

Requirements:

- do not use oversized whitespace or headings;
- empty-state title should generally be H3/subsection scale, not page-title scale;
- explanation compact;
- one clear action where applicable;
- empty states inside panels/lists should not consume most of the viewport without reason.

## Payment / WEBPAY Page Audit

Preserve all safe business wording and existing trusted/untrusted confirmation distinctions.

Visually refine:

- pending confirmation;
- success;
- failure/cancel;
- placement;
- extension.

Requirements:

- state is immediately understandable;
- amount/order metadata is secondary;
- “Оплата подтверждается WEBPAY” remains exact in meaning;
- browser cancel remains untrusted;
- do not reintroduce misleading financial language;
- no oversized generic alert dominating the whole page.

## Full 31-Page Audit

Claude must audit **every one of the 31 page groups**, not only shared CSS.

For each group, visually check at least one primary variant and every materially different visual state.

Audit categories:

1. login;
2. password setup;
3. system errors;
4. common notices;
5. psychologist groups empty;
6. psychologist groups list;
7. group create/edit;
8. psychologist group detail;
9. placement payment;
10. payment confirmation pending;
11. payment success;
12. payment unsuccessful/cancel/unknown;
13. extension;
14. psychologist applications list;
15. psychologist application detail;
16. psychologist profile/documents;
17. admin work queue;
18. psychologists list;
19. psychologist detail;
20. psychologist create/edit;
21. psychologist documents;
22. admin groups list;
23. admin group moderation/detail;
24. admin group create/edit;
25. admin applications list;
26. admin application detail;
27. admin payments list;
28. admin payment detail;
29. dictionaries;
30. dictionary items;
31. settings.

Do not leave a page on an old visual pattern simply because shared CSS did not automatically fix it.

## Full 249-Variant Regression

All existing 249 prototype variants must remain reachable.

Requirements:

- no variant may be deleted merely to simplify the redesign;
- existing product-state coverage remains;
- prototype routes remain local/testing only;
- final product Blade views remain the source;
- synthetic fixtures remain synthetic;
- no real backend actions are introduced;
- no Stage 4 functionality is added.

## Allowed Markup Changes

This task explicitly authorizes material **visual hierarchy and presentation** changes to the accepted Stage 3 UI.

Claude may:

- restructure headings;
- reduce/merge decorative panels;
- change shared component markup;
- change grid arrangements;
- adjust action placement;
- change card/list/table presentation;
- improve semantic heading structure;
- add small visual helper wrappers/classes where necessary.

Claude must not:

- change the product information architecture;
- add/remove business functionality;
- remove required fields/states/actions;
- change business rules;
- change navigation destinations;
- invent a new feature;
- silently change safe WEBPAY semantics;
- begin Stage 4 auth/backend integration.

## Preserve Product-Critical UI Content

The following must remain functionally/semantically present:

- all group lifecycle states;
- revision comment and rejection reason;
- applications counters/states;
- psychologist “Мои группы” and “Мои данные” navigation;
- admin work queue;
- admin moderation actions;
- gruppa.info integration block;
- “ID группы для gruppa.info” label;
- functional prototype UUID copy action;
- payment/manual refund warnings;
- safe WEBPAY return wording;
- tariff/access/status presentation;
- dictionary/settings forms;
- all required validation/empty/permission/confirmation states.

## CSS Architecture

Prefer a coherent revision of the existing `application/public/ui.css`.

Requirements:

- centralize typography/radius/spacing/control tokens;
- remove obsolete oversized tokens after migration;
- avoid conflicting duplicate CSS declarations;
- do not add page-specific arbitrary font sizes/radii when a token solves it;
- keep Bootstrap as foundation and project CSS after it;
- do not introduce Tailwind or another CSS framework;
- no frontend build system.

If `app.css` remains a Stage 1 diagnostic-only file, do not move the Stage 3 system back into it without need.

## JavaScript

Keep JavaScript minimal.

Existing prototype behavior must continue:

- no-op form/action behavior;
- modal/dropdown behavior;
- UUID clipboard copy feedback.

Do not implement UI state frameworks, AJAX, or frontend business logic.

## Accessibility Baseline

Maintain or improve:

- semantic heading order;
- keyboard focus visibility;
- form labels;
- error associations;
- color contrast;
- non-color status labels;
- usable touch targets;
- modal accessibility;
- mobile readability.

Do not claim a formal WCAG certification unless actually audited.

## Responsive Review

Review at approximately:

- 1440px desktop;
- 1024px tablet;
- 390px smartphone.

Typography and density must be responsive intentionally, not merely shrink through Bootstrap.

Explicitly inspect:

- page headers;
- navigation;
- long forms;
- tables/mobile cards;
- action groups;
- alerts;
- modals;
- long names/emails/comments;
- gruppa.info UUID;
- payment identifiers.

No page-level horizontal overflow is allowed at 390px.

## Design Audit Process

Before changing the UI, Claude must visually inspect the current implementation and record the major observed problems in `.ai/report.md` under a **Design Audit — Before** section.

At minimum assess:

- type hierarchy;
- component scale;
- spacing/density;
- form geometry;
- alerts;
- card/panel overuse;
- navigation;
- tables/lists;
- modal scale;
- mobile density;
- visual consistency between psychologist/admin areas.

Then implement systemic fixes.

After implementation, repeat the audit under **Design Audit — After** and explain how each major issue was addressed.

Do not create a permanent standalone `DESIGN_SYSTEM.md` or abstract UI-kit document.

## Documentation

Update only documentation affected by the redesign:

- `docs/ui-pages.md` if responsive/layout/component notes changed materially;
- `docs/project-status.md` to state that Stage 3 visual revision is awaiting/reached acceptance;
- `docs/architecture.md` only if shared frontend structure actually changes;
- `docs/development.md` only if prototype browsing/verification instructions change.

Do not churn documentation just to restate CSS values already visible in code.

## Automated Tests

Preserve all existing prototype/domain tests.

Update/add tests where useful to protect key design constraints, at minimum:

- 31 page groups still exist;
- 249 variants still render;
- production still has no prototype routes;
- no external font/CDN is introduced;
- local Montserrat still loads;
- gruppa.info copy control remains;
- safe WEBPAY wording remains;
- form controls use the revised shared CSS;
- CSS no longer applies pill radius to normal `.form-control` / `.form-select`;
- form-control radius token/value is <=10px;
- typography tokens expose clearly separated page/section/subsection sizes;
- alert component no longer relies on oversized generic heading styles.

Do not create brittle snapshot tests of entire HTML pages unless necessary.

## Visual Verification

A full design task cannot be marked done based only on PHPUnit.

Perform actual browser rendering against the Docker runtime.

### Required automated browser regression

Using browser tooling external to the repository if necessary:

- render all 249 variants at 1440px, 1024px, and 390px;
- verify HTTP success;
- verify no JS exceptions;
- verify no external runtime asset requests;
- verify no page-level horizontal overflow;
- verify visible interactive controls remain inside the viewport;
- verify local fonts load.

Do not add Node/npm dependencies to the repository.

### Required manual visual review

Create temporary screenshots/contact sheets outside the repository and visually review all 31 page groups.

Manually inspect especially:

- login;
- psychologist groups normal;
- group revision form;
- group detail;
- payment pending;
- paid-expired extension;
- applications list;
- psychologist profile;
- admin home;
- psychologists list/detail/form;
- admin groups list;
- admin moderation;
- admin payment detail;
- dictionaries/items;
- settings;
- representative errors/modals.

Inspect all three target widths.

Do not commit screenshots, browser profiles, logs, or generated audit artifacts.

If actual browser visual verification cannot be performed, task status must be `partial`, not `done`.

## Required Checks

Run and report exact results:

1. Docker runtime healthy.
2. `/_prototype` catalog reachable.
3. All 249 variants render.
4. Production environment contains no prototype routes.
5. Local Bootstrap/CSS/JS/Montserrat assets return HTTP 200.
6. Full MySQL test suite:
   - `docker compose exec -T php php artisan test`
7. Pint:
   - `docker compose exec -T php ./vendor/bin/pint --test`
8. Larastan:
   - `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
9. Composer platform:
   - `docker compose exec -T php composer check-platform-reqs`
10. Blade compilation:
   - `docker compose exec -T php php artisan view:cache`
11. Browser regression at 1440/1024/390.
12. Manual design review of all 31 page groups.
13. UUID copy interaction.
14. Modal interaction at desktop/mobile.
15. Git diff/status/staged inspection.
16. Confirm no Node artifacts, screenshots, secrets, provider requests, or unrelated files are staged.

## Acceptance Criteria

1. A complete before/after visual audit is recorded in `.ai/report.md`.
2. The entire interface uses a clear, visibly differentiated typography hierarchy.
3. H1/page titles are clearly distinct from H2/H3/supporting copy on every page.
4. Routine alerts/notices are materially smaller and no longer dominate pages.
5. No normal text input/select has border radius greater than 10px.
6. Textareas use restrained rounding and no form control is pill-shaped.
7. Cards/panels/modals use materially less exaggerated radii.
8. Overall vertical density is improved across admin and psychologist screens.
9. Forms are easier to scan and validation remains clear without being oversized.
10. Tables/lists are more compact and information-dense while remaining readable.
11. Navigation and action hierarchy are clearer and less visually heavy.
12. Status badges remain explicit and compact.
13. Empty states are visually proportionate.
14. Payment/WEBPAY pages preserve safe business semantics while improving hierarchy.
15. All 31 page groups are visually reviewed and consistent.
16. All 249 variants remain reachable and render successfully.
17. Smartphone pages have no page-level horizontal overflow.
18. Desktop/tablet/mobile layouts remain usable.
19. Local Montserrat and local Bootstrap remain; no external font/CDN dependency is introduced.
20. No Stage 4/backend/business behavior is added.
21. All existing domain/prototype tests remain green after necessary test updates.
22. PHPUnit, Pint, Larastan, Composer platform checks, and Blade compilation pass.
23. Browser regression passes at 1440/1024/390.
24. `.ai/report.md` contains exact verification results and remaining design risks.
25. Final diff is limited to Stage 3 visual/frontend revision, relevant tests/docs, and `.ai/report.md`.

## Out Of Scope

Do not implement:

- real authentication/login/logout;
- real password setup;
- real CRUD;
- Form Request backend handling;
- policies/access middleware;
- document transfer;
- payment creation/confirmation;
- WEBPAY network requests;
- public API;
- email;
- scheduler/queue business jobs;
- database/domain redesign;
- new product pages;
- new navigation sections;
- tariff-product redesign;
- unrelated content rewriting.

Do not change `SPEC.md`, `WORKFLOW.md`, or `AGENTS.md` for this task.

## Hard Workflow Gate

Before changing files:

- read `WORKFLOW.md`, `AGENTS.md`, `SPEC.md`, `docs/ui-pages.md`, `docs/project-status.md`, and this `.ai/task.md`;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm base commit `1070957220c003d825d2bf6a026171b6ee7bc8ec`;
- confirm no unknown local changes will be overwritten;
- inspect current rendered prototypes before editing.

During implementation:

- make systemic shared-component/CSS changes first;
- then inspect and fix page-specific visual issues;
- keep all 31 page groups and 249 variants;
- do not alter `.ai/task.md`;
- do not begin Stage 4;
- do not introduce a frontend build system;
- do not change product/business rules.

Before commit:

- run every required automated check;
- perform actual visual review at all three widths;
- update `.ai/report.md` with Design Audit — Before / After;
- inspect full diff;
- inspect staged files;
- ensure screenshots/browser artifacts remain outside repository;
- ensure no secrets or unrelated files are staged.

Completion:

- use `Status: done` only if the design revision and required browser review are complete;
- otherwise use `partial`, `blocked`, or `failed`;
- if complete, commit with:

```text
claude: TASK-2026-09-21-01 audit and refine Stage 3 design
```

- do not create an `accept:` commit.
