# Task: TASK-2026-09-20-05

Status: planned
Created from: 1b1b22ea61051c013be443cbe0385a0084d6a75d (main)

## Title

Stage 3 — Build and approve the complete Blade frontend prototype for all MVP pages and UI states

## Goal

Create the complete static frontend version of the future application described by §§23–25 and Stage 3 of `SPEC.md`, using the final production view structure and the final frontend stack:

- Blade;
- Bootstrap 5.3.8 already committed locally;
- project-owned CSS;
- minimal Vanilla JS;
- locally stored Montserrat.

This stage must finish the page structure, visual language, reusable Blade components, responsive behavior, and all required product states **before** real authentication and CRUD/backend flows are connected.

The deliverable is not a disposable mockup or a separate UI-kit. It is the real set of Blade views that later stages will reuse by replacing fixture data with real data and wiring real actions.

Do not implement real authentication, CRUD, email, public API, scheduler behavior, or WEBPAY requests.

## Facts

- Stage 1 and Stage 2 are accepted through commit `1b1b22ea61051c013be443cbe0385a0084d6a75d`.
- The application already runs under the real local base path `/cabinet`.
- Bootstrap 5.3.8 is already stored locally under `application/public/`.
- Current frontend files are only the minimal Stage 1 foundation layout/page and small `app.css`/`app.js`.
- Stage 2 provides the domain enums/models/schema, but Stage 3 prototype rendering must not depend on real database records or unfinished business flows.
- Prototype routes must render the same Blade view files that later production controllers will render.
- Prototype routes must be registered only in `local`/`testing`; they must not exist in production.
- Separate `DESIGN_SYSTEM.md` and `uikit/` deliverables are not used. The approved interface itself is the source of truth after this stage.
- Visible user-facing copy is Russian unless a technical identifier/provider name requires otherwise.
- No Node/npm/Vite/frontend build pipeline is allowed.

## Assumptions

- The existing technical foundation page may remain available for Stage 1 diagnostics; do not repurpose the real application root into the Stage 4 authenticated flow yet.
- Prototype URLs live under `/_prototype`, which locally resolves under the application base path as `/cabinet/_prototype/...`.
- The simplest implementation is preferred: Blade layouts/components + a small fixture provider/arrays + GET-only development prototype routes.
- Prototype forms may be non-submitting/no-op at this stage, but their field names, grouping, help/error areas, and action hierarchy should be production-ready.
- The product has no approved graphic logo asset in the repository. Use a restrained typographic product name/wordmark rather than inventing a new logo.
- No external icon library is required. Prefer text labels and minimal inline SVG only where an icon materially improves clarity.
- Exact design tokens that were intentionally left to Stage 3 are fixed by this task below and should remain consistent across pages.

## Unknowns

- Approved user-facing dictionary item values and actual placement/extension prices are still unavailable. Prototype fixtures may use clearly fictional display examples for visual layout only, but must not change database seed values or documentation as if those examples were product-approved configuration.
- Real authentication/error behavior and real validation messages are connected in later stages. Stage 3 represents those states visually only.
- Real WEBPAY provider responses are unavailable and must not be contacted; payment pages use fixture states only.

## Scope

### 1. Final frontend structure

Create the real reusable view structure in `application/resources/views/`.

Use a clear hierarchy such as:

```text
resources/views/
  layouts/
  components/
  auth/
  errors/
  psychologist/
    groups/
    applications/
    profile/
    payments/
  admin/
    users/
    groups/
    applications/
    payments/
    dictionaries/
    settings/
  prototype/
```

The exact names may vary if an equally clear existing Laravel convention is used, but:

- production views and prototype views must not be duplicated;
- `prototype/` should contain only prototype index/catalog or prototype-specific wrappers, not copies of the product pages;
- later controllers must be able to render the actual `auth/*`, `psychologist/*`, `admin/*`, and `errors/*` views directly.

Do not build a parallel static HTML tree.

### 2. Shared layouts

Implement reusable final layouts for the required surfaces:

- authentication/public system surface;
- psychologist cabinet;
- admin area;
- system/error pages where appropriate.

Layouts must:

- retain the application `/cabinet` base path through Laravel helpers;
- load Bootstrap locally;
- load project CSS/JS locally;
- load local Montserrat;
- expose page title/header/action areas cleanly;
- provide responsive navigation;
- avoid page-specific duplicated chrome.

#### Psychologist navigation

At minimum:

- «Мои группы»;
- «Мои данные»;
- «Выход» visual action.

#### Admin navigation

At minimum:

- рабочая сводка;
- психологи;
- группы;
- заявки;
- платежи;
- справочники;
- настройки;
- выход visual action.

The logout controls are visual/no-op at this stage; real POST/CSRF behavior belongs to Stage 4.

### 3. Local Montserrat assets

Add locally served Montserrat with:

- weight 500 for normal body text;
- weight 600 for headings, labels, buttons, important values, and emphasis;
- Cyrillic support;
- `font-display: swap`;
- no Google Fonts or other runtime CDN dependency.

Prefer WOFF2 static files for only the two used weights. Include the applicable font license/source attribution in the repository.

Do not add extra font weights that the UI does not use.

If authentic licensed Montserrat assets cannot be obtained/verified, mark the task blocked rather than substituting a different font.

### 4. Fixed Stage 3 visual tokens

Implement one shared CSS token layer in project CSS. Do not hardcode arbitrary per-page alternatives.

#### Required colors

Use exactly:

- accent: `#FF714A`;
- accent strong / primary button: `#CC4B2A`;
- main text: `#3C3834`;
- surface 1: `#F5F4F0`;
- surface 2: `#EBE9E2`;
- white: `#FFFFFF`;
- success: `#2F7D5A`;
- success surface: `#EAF4EE`;
- warning: `#9A6817`;
- warning surface: `#FFF2D9`;
- danger: `#C84E42`;
- danger surface: `#FBE9E6`;
- info: `#4F6F8F`;
- info surface: `#EAF0F5`.

Use shared neutral derived tokens:

- muted text: `#716B65`;
- border: `#D8D5CC`;
- subtle border/background separator: `#E5E2DA`.

#### Typography

Use a consistent scale:

- body: 16px / 1.55, weight 500;
- small/supporting text: 14px / 1.5;
- compact meta text: 13px / 1.45 where necessary;
- page title: 32px / 1.2 desktop, 28px mobile, weight 600;
- section title: 24px / 1.3, weight 600;
- card/subsection title: 20px / 1.35, weight 600;
- control labels/buttons: 14–16px, weight 600.

Do not create unrelated type scales on individual pages.

#### Spacing and sizing

Use a shared spacing scale based on:

`4, 8, 12, 16, 24, 32, 48, 64px`.

Set shared minimum control/button heights suitable for touch, approximately 44–48px.

Use Bootstrap breakpoints rather than inventing a parallel responsive system.

#### Radius

Use shared tokens:

- large cards/panels/modals: 32px;
- normal cards/table wrappers: 24px;
- compact containers: 20px;
- textarea/multiline surfaces: 24px;
- buttons, badges/chips, and suitable one-line inputs/selects: pill / 9999px.

#### Shadow

Use one restrained shared card/popover shadow derived from the main text, e.g. a low-opacity soft shadow; do not add multiple dramatic shadow systems.

### 5. Shared Blade components

Build reusable Blade components for repeated patterns. At minimum cover:

- primary/secondary/ghost/danger buttons;
- label + required/optional indication;
- input;
- textarea;
- select;
- checkbox;
- radio;
- validation error;
- validation summary;
- alert/notice;
- card/panel;
- status badge;
- table/list wrapper;
- responsive row/list item where needed;
- pagination;
- modal/confirmation;
- dropdown/action menu;
- empty state;
- page header;
- navbar/header;
- admin sidebar/navigation;
- date/time display;
- money display.

Components must support the required normal/hover/focus/disabled/error states.

Use the existing date and money formatting primitives where appropriate rather than inventing separate formatting rules.

Do not create an abstract component library larger than the actual pages require.

### 6. Status presentation

Create a centralized visual mapping for statuses used in prototypes.

#### User statuses

- pending;
- approved;
- rejected;
- separately show enabled/disabled access state and free/paid tariff.

#### Group statuses

- awaiting_payment;
- draft;
- moderation;
- revision;
- rejected;
- approved;
- active;
- expired;
- active + expiry warning;
- expired inside extension window;
- expired outside extension window;
- disabled where relevant.

#### Payment statuses

- created;
- pending;
- pending requiring manual review;
- succeeded;
- failed;
- cancelled;
- refunded.

Status must never be communicated by color alone. Every badge/alert includes explicit text and, where useful, an icon/label.

### 7. Development-only prototype routes and fixtures

Add a development prototype catalog at:

`/_prototype`

and dedicated child routes for all pages/variants.

Requirements:

- routes are registered only when environment is `local` or `testing`;
- in `production`, route registration must be absent, not merely hidden by UI;
- all routes use the real final Blade view files;
- fixture/mock data comes from simple arrays or a small explicit fixture provider/ViewModel;
- prototype page rendering must not depend on seeded business data or database queries;
- prototype GET routes do not perform writes;
- real production controllers/actions are not created yet.

Every route should have a stable descriptive name and be documented in `docs/ui-pages.md`.

Variant handling may use separate paths or an explicit `variant` parameter/query string, but the index must link directly to each required state.

### 8. Prototype catalog — implement all 31 page groups

Implement every page group and all required variants from §24.3.

#### Common/system

1. Login.
2. Password setup.
3. System errors: 403, 404, 419, 429, 500.
4. Common confirmation/alert states.

#### Psychologist cabinet

5. My groups — empty state.
6. My groups — populated list with all group visual variants.
7. Group create/edit — draft, revision, validation/error states.
8. Psychologist group detail/read-only with status/history/action variants.
9. Placement payment — awaiting/start state.
10. WEBPAY return — confirmation pending.
11. WEBPAY return — confirmed success.
12. WEBPAY return/payment — unsuccessful/cancel/undetermined state with safe wording.
13. Extension flow with all six required variants.
14. Group applications list — normal/empty/filter/processed states.
15. Application detail.
16. My profile/data.

#### Admin

17. Admin home/work queue summary.
18. Psychologists list.
19. Psychologist detail.
20. Psychologist create/edit.
21. Psychologist documents.
22. Groups admin list.
23. Group admin detail/moderation.
24. Group admin create/edit.
25. Applications admin list.
26. Application admin detail.
27. Payments admin list.
28. Payment admin detail/refund-accounting form.
29. Dictionaries list.
30. Dictionary items.
31. Settings.

No catalog item may be represented only by a placeholder link or “coming later” page.

### 9. Required content and variants

Follow §24.3 field-by-field. In particular:

#### Login

Show:

- product name;
- email;
- password;
- submit button;
- generic safe authentication error;
- field validation state;
- rate-limit/error alert;
- mobile and desktop behavior.

No real login POST route yet.

#### Password setup

Show:

- user/email identification in a Laravel-compatible flow;
- password;
- confirmation;
- requirements;
- validation errors;
- expired/invalid token;
- success/return-to-login state.

#### Error pages

Create real Blade views appropriate for later Laravel error rendering:

- 403;
- 404;
- 419;
- 429;
- 500.

They must include a clear safe action back to the cabinet/login surface.

#### My groups list

Every group row/card must communicate:

- title;
- format;
- status;
- creation date;
- publication date;
- expiry date;
- expiry warning;
- new/processed/all application counters;
- primary next action;
- secondary actions.

Show all required status variants from §24.3, including revision comment and rejection reason.

Desktop may use table/structured list; mobile must have a purpose-built readable card/stack representation rather than an unusable squeezed table.

#### Group form

Include all §11 fields with final field names where possible:

- title;
- description;
- schedule;
- format;
- meeting duration;
- participant capacity;
- gender;
- meeting price.

Include help text, required/optional markers, validation error placement, save/send/cancel hierarchy, draft/revision variants, visible moderator comment/history for revision.

Do not add new business fields absent from the specification.

#### Psychologist group detail

Show:

- all group data read-only;
- current status + explanation;
- moderator/rejection messages;
- status/comment history;
- publication/expiry dates;
- application counters/list preview;
- status-appropriate actions;
- disabled representation.

#### Payment/WEBPAY visual pages

These are visual fixtures only. No request may leave the application.

The pending confirmation page must use the exact concept:

«Оплата подтверждается WEBPAY»

Browser cancel must not be presented as trusted financial cancellation when server confirmation is unknown.

#### Extension

Show separately:

- free active;
- free expired;
- paid active;
- paid expired;
- expired after extension window;
- paid confirmation pending.

The page content must make clear whether admin republication will later be required for an expired group.

#### Applications

Show new/processed/all counters, filters, participant name, phone, date, processed state, toggling visual action, empty state, mobile representation.

#### My data

Read-only only. Show:

- questionnaire/profile data;
- education/license data;
- consent information;
- available document list;
- no edit controls.

#### Admin dashboard

It is a work queue, not analytics. Show actionable counts/links for:

- pending psychologists;
- moderation groups;
- approved waiting for publication;
- expired waiting for manual unpublish;
- pending payments needing attention.

Do not invent charts/KPIs/product analytics.

#### Psychologists admin

List/detail/form/document pages must include the fields and states from §§6 and 24.3, including tariff/access/status, document actions, groups summary, confirmation dialogs, pending/approved/rejected/disabled variants.

#### Groups admin

List/detail/form must include required search/filter/sort surfaces and moderation state variants.

Admin group detail must prominently include:

**«Интеграция с gruppa.info»**

and:

**«ID группы для gruppa.info»**

with fixture `public_uuid`, a functional client-side «Скопировать ID» action, and explanatory text.

Moderation UI must visibly support:

- approve;
- request revision with required comment;
- reject with required reason;
- activate after manual publication;
- edit;
- delete;
- paid-rejected manual-refund warning.

Actions remain visual/no-op in this stage.

#### Payments admin

List includes:

- pre-WEBPAY informational empty state;
- normal records;
- pending/manual-review state;
- filters by status/type/psychologist/period.

Detail includes:

- internal payment ID;
- order number;
- transaction id;
- amount/currency;
- type/status;
- linked group/user;
- paid/refunded timestamps;
- status-check metadata;
- notification summary without secrets;
- refund accounting form;
- explicit warning that «Отметить возврат выполненным в WEBPAY» does not send money or call a refund API.

#### Dictionaries/settings

Build the final management surfaces with validation/confirmation states, but no real CRUD.

Settings must cover:

- placement price;
- extension price;
- placement days;
- warning days;
- expired extension window;
- application retention;
- password setup TTL.

Use clear units in labels/help text.

### 10. Cross-page states

For every page where applicable, create direct prototype variants for:

- normal;
- empty;
- validation error;
- success notice;
- access/permission error;
- disabled action;
- destructive confirmation;
- long text/long name stress case;
- pagination;
- business statuses affecting the page.

Do not create meaningless variants where the state cannot apply; document the applicable coverage in `docs/ui-pages.md`.

### 11. Responsive requirements

All product pages must be usable at:

- desktop — validate around 1440px width;
- tablet — validate around 1024px width;
- smartphone — validate around 390px width.

Requirements:

- navigation adapts without hover dependence;
- forms use available width;
- buttons remain within viewport;
- tables either scroll safely or transform to a mobile list/card representation;
- main actions remain obvious on touch;
- modals fit the viewport and remain scrollable;
- long IDs, emails, UUIDs, names, comments, and URLs wrap safely;
- no horizontal page overflow at smartphone width.

Use Bootstrap responsive utilities/grid plus shared project CSS.

### 12. Minimal Vanilla JS

Use JavaScript only where the static prototype benefits materially, such as:

- admin group UUID copy button;
- Bootstrap modal/dropdown behavior if needed;
- simple prototype-only state demonstration controls only when necessary.

Do not build client-side state management, SPA behavior, AJAX business actions, or framework-like utilities.

Project JavaScript remains directly served from `application/public/` with no build step.

### 13. Accessibility and interaction baseline

For all views/components:

- semantic headings and landmarks;
- explicit form labels;
- visible keyboard focus;
- sufficient contrast using the approved palette;
- buttons vs links chosen semantically;
- disabled controls have both visual and HTML disabled/aria state where applicable;
- modal markup follows Bootstrap accessibility expectations;
- form errors are associated with their fields;
- status is not color-only;
- touch targets remain usable.

Do not claim a formal WCAG audit unless one is actually performed.

### 14. Prototype fixtures must be obviously synthetic

Use stable fictional data for layout demonstration.

Do not use real user/production information.

For sensitive-looking fields:

- use fake phone numbers/names;
- use fake document filenames;
- use fake payment/order/transaction identifiers;
- use fixture UUIDs;
- never use actual credentials/secrets.

Payment examples must not be mistaken for real WEBPAY responses.

### 15. `docs/ui-pages.md`

Create a complete catalog containing for every page group:

- final Blade view path;
- prototype URL(s);
- variants;
- key reusable components used;
- responsive behavior notes;
- any intentionally non-functional actions in Stage 3.

This document becomes the page/state index for later implementation/review.

Do not create a separate design-system document.

### 16. Automated verification

Add focused tests sufficient to protect the Stage 3 contract.

At minimum verify:

- prototype route index is reachable in `testing`;
- every documented prototype URL returns HTTP 200;
- prototype routes are not registered under production environment;
- prototype pages render from the final product Blade views, not duplicate HTML copies;
- prototype rendering does not require product database fixtures/records;
- all required page groups are linked from the prototype catalog;
- key group status variants are present;
- admin group integration block contains the `public_uuid` label/copy control;
- local asset URLs preserve `/cabinet`;
- local Montserrat CSS/assets are referenced and no Google Fonts/CDN is used by the application layouts;
- existing MySQL Stage 2 tests remain green.

Do not add browser-test Node dependencies.

### 17. Manual visual/runtime verification

Start the real Docker runtime and review the prototype catalog through:

`http://localhost:8080/cabinet/_prototype/`

Manually inspect every page group and applicable variant at desktop, tablet, and smartphone widths.

At minimum explicitly check:

- navigation;
- tables/mobile transformations;
- forms/errors/help;
- modals;
- long text/UUID wrapping;
- status colors + text;
- primary/secondary/destructive action hierarchy;
- payment wording;
- admin group integration/copy control;
- no external font/CDN requests;
- no horizontal overflow on smartphone.

Do not commit screenshots or temporary visual-test artifacts unless they are explicitly needed by the repository (they are not required for this task).

If actual viewport/runtime visual verification cannot be performed, do not mark the task `done`; report `partial` with the limitation.

### 18. Documentation updates

Update:

- `docs/ui-pages.md` — required new catalog;
- `docs/architecture.md` — final Stage 3 frontend/view structure and prototype-route boundary;
- `docs/development.md` — how to browse prototypes locally;
- `docs/project-status.md` — actual Stage 3 state.

Update README only if the developer entry point materially changes.

Documentation must describe implemented state only.

## Out Of Scope

Do not implement:

- real login/logout/session authorization;
- password broker/token behavior;
- role/access middleware;
- real psychologist/admin CRUD;
- real Form Request handling;
- real policies/actions;
- document upload/download;
- real group creation/moderation/activation;
- actual payment creation or WEBPAY redirects;
- WEBPAY notify/return/get_transaction;
- real extension effects;
- public-site API;
- participant application ingestion;
- SMTP/email;
- scheduler/queue business jobs;
- real dictionary/settings mutations;
- production deployment.

Do not modify Stage 2 domain rules merely to make fixtures easier.

Do not add Node/npm/Vite, Tailwind, Vue, React, Livewire, Inertia, external font CDN, external Bootstrap CDN, or an icon framework.

Do not change `SPEC.md`, `WORKFLOW.md`, or `AGENTS.md` unless a genuine blocking contradiction is discovered; stop and report instead.

## Constraints

- Follow `WORKFLOW.md` and `AGENTS.md`.
- Work only on Stage 3.
- Build real final Blade views, not disposable HTML.
- Prototype routes are development/testing-only and must be absent in production.
- Prototype rendering must use fixture/mock data, not unfinished backend flows.
- Use the approved visual tokens from this task and `SPEC.md`.
- Use Montserrat 500/600 locally with Cyrillic.
- Use local Bootstrap 5.3.8 already in the project.
- No frontend build pipeline.
- Preserve `/cabinet` base-path compatibility.
- Keep business actions no-op and clearly prototype-only.
- Do not invent real prices, credentials, approved dictionary values, or provider responses.
- Do not alter `.ai/task.md`.

## Acceptance Criteria

1. The final Blade view structure contains all 31 page groups from §24.3.
2. `/_prototype` provides direct navigation to every required page and applicable variant.
3. Prototype routes register only in `local`/`testing` and are absent in production.
4. Prototype routes render the same final Blade files intended for later production controllers; there is no duplicate static HTML version.
5. Prototype pages render without depending on real business database records.
6. Montserrat 500/600 with Cyrillic is stored locally, loaded with `font-display: swap`, and no external font CDN is used.
7. All pages use the fixed shared colors, type scale, spacing, radii, status colors, and components from this task.
8. Required shared Blade components exist and are reused rather than page-specific duplicated primitives.
9. Login, password setup, system errors, and common alert/confirmation states are visually complete.
10. Psychologist pages implement all required group/payment/extension/application/profile states.
11. Admin pages implement the complete user/group/application/payment/dictionary/settings surfaces.
12. Every business status that affects UI has an explicit labeled visual representation; statuses are not color-only.
13. Admin group detail includes the required gruppa.info integration block, fixture `public_uuid`, and functional copy action.
14. WEBPAY visual pages make no provider requests and use safe wording that does not trust browser return/cancel as financial confirmation.
15. Forms contain production-oriented field names, labels, help/error areas, and validation variants without real submission behavior.
16. Pages are usable at desktop/tablet/smartphone widths; smartphone has no page-level horizontal overflow.
17. Long names/comments/UUIDs/IDs wrap safely.
18. Navigation and primary actions do not require hover.
19. `docs/ui-pages.md` accurately catalogs views, URLs, variants, and responsive notes.
20. Existing Stage 1/2 runtime/domain behavior remains intact.
21. Automated tests for prototype routing/rendering/assets/environment boundary pass.
22. Full MySQL test suite passes.
23. Pint, Larastan, and `composer check-platform-reqs` pass.
24. Actual Docker/browser-equivalent visual verification is performed across all page groups at desktop/tablet/smartphone sizes and recorded in `.ai/report.md`.
25. No Stage 4+ real business/auth/integration functionality is introduced.
26. Final diff contains only Stage 3 frontend prototype work, required local assets/docs/tests, and `.ai/report.md`.

## Checks

Run and report exact results. At minimum:

1. Start/verify Docker and open:
   - `http://localhost:8080/cabinet/_prototype/`
2. Confirm every catalog link/variant returns successfully.
3. Confirm production route registration excludes `/_prototype`.
4. Verify prototype requests do not require business database records/seed state.
5. Verify local assets under `/cabinet` return HTTP 200:
   - Bootstrap;
   - app CSS/JS;
   - Montserrat 500/600 font files.
6. Search rendered HTML/CSS for prohibited runtime dependencies:
   - Google Fonts;
   - Bootstrap CDN;
   - Node/Vite-generated assets;
   - external icon libraries.
7. Run automated tests covering prototype route/view/state contract.
8. Run the full MySQL suite:
   - `docker compose exec -T php php artisan test`
9. Run:
   - `docker compose exec -T php ./vendor/bin/pint --test`
   - `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
   - `docker compose exec -T php composer check-platform-reqs`
10. Perform visual inspection of every page group/applicable variant at approximately:
   - 1440px desktop;
   - 1024px tablet;
   - 390px smartphone.
11. Explicitly verify no smartphone horizontal overflow, unreadable squeezed tables, inaccessible modal content, or action controls outside the viewport.
12. Test the admin group «Скопировать ID» control in the browser.
13. Inspect `git diff`, `git status --short`, and staged files before commit.
14. Confirm no real credentials, user data, WEBPAY requests, generated screenshots, temporary assets, Node artifacts, or unrelated files are staged.

## Hard Workflow Gate

Before changing files:

- read `WORKFLOW.md`, `AGENTS.md`, `SPEC.md`, `docs/project-status.md`, and this `.ai/task.md`;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm this task corresponds to the latest relevant `planner:` commit;
- do not touch unknown local changes.

During implementation:

- stay strictly inside Stage 3 frontend prototype scope;
- do not implement Stage 4 auth/backend actions;
- do not alter `.ai/task.md`;
- do not change governance/spec files;
- do not invent product configuration or provider behavior;
- do not add a frontend build tool;
- keep prototype routes absent from production;
- use the actual final Blade views throughout.

Before commit:

- run all automated checks;
- perform and record the required visual viewport review;
- update `.ai/report.md` with:
  - pages/components/assets created;
  - prototype route/catalog coverage;
  - visual viewport checks;
  - automated checks;
  - any unresolved visual/product gaps;
- inspect complete diff;
- inspect staged files;
- stage only Stage 3 files plus `.ai/report.md`;
- confirm no secrets, temporary screenshots, build artifacts, or unrelated files are staged.

Completion:

- use `Status: done` only if all required prototype pages/states exist and actual desktop/tablet/smartphone visual verification was performed;
- otherwise use `partial`, `blocked`, or `failed`;
- if the gate passes, commit with:

```text
codex: TASK-2026-09-20-05 build complete Blade prototypes
```

- do not create an `accept:` commit.
