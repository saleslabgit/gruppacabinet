# Task: TASK-2026-10-03-02

Status: planned
Created from: 0aa8c06406361c954f01acc3bcf2e9361868329f (main)

## Title

Polish psychologist-facing copy, favicon and feedback icon

## Goal

Apply a small UI polish pass after manual testing.

Three product requirements:

1. review all user-facing messages in the psychologist Cabinet and remove technical implementation language;
2. add a favicon for the Cabinet;
3. replace the generic icon for “Сообщить об ошибке” with an appropriate icon.

This is a presentation/copy task. Do not change accepted business workflows, external integrations or lifecycle semantics.

## Facts

- Current main HEAD: 0aa8c06406361c954f01acc3bcf2e9361868329f.
- Psychologist feedback currently exposes implementation details:
  - success: “Сообщение принято и поставлено в очередь отправки.”;
  - error/prototype error: “Не удалось поставить сообщение в очередь...”.
- PsychologistCabinetPages includes navigation item “Сообщить об ошибке”.
- navigation-link.blade.php maps known labels to Bootstrap Icons and currently falls back to circle for this new item.
- Bootstrap Icons 1.13.1 are already local and approved.
- Shared surface layout currently has no favicon link.
- Admin UI may retain operational/technical terminology where genuinely needed.
- Internal logs, queue/job names, class names, docs and integration code may keep technical terminology.
- Do not touch production/private .env_save.

## A. Psychologist-facing language audit

Audit all real psychologist-facing Cabinet surfaces and directly surfaced server validation/flash messages.

The goal is not to remove useful state information. Explain the state in product language rather than implementation language.

### A1. Remove internal implementation concepts from owner UI

Do not expose terms/concepts such as, where avoidable:

- queue / queued job / “поставлено в очередь”;
- database;
- worker / cron;
- MODX;
- Resource ID;
- API;
- transport;
- SMTP/sendmail;
- synchronization as an implementation mechanism;
- publication revision;
- internal conflict/error codes;
- “trusted confirmation” / “доверенное подтверждение” as an engineering term;
- “browser return does not confirm financial result” or other implementation explanations when simpler payment-state wording is sufficient.

Do not mechanically replace every occurrence in the repository.

Scope is what an authenticated psychologist actually sees:

- navigation;
- psychologist group list/detail/form/extension;
- psychologist applications;
- psychologist payments;
- psychologist profile;
- feedback;
- shared components when rendered in psychologist context;
- validation and flash messages returned by psychologist controllers/services.

Admin-only screens and operational documentation are out of this copy-cleanup scope.

### A2. Keep copy truthful and actionable

Preferred style:

- short;
- plain Russian;
- describes what happened / what user should do;
- no promises of delivery before external delivery is known;
- no technical cause unless the psychologist can act on it.

Feedback success recommended final copy:
“Сообщение принято. Спасибо за обратную связь.”

Feedback submission failure recommended final copy:
“Не удалось отправить сообщение. Попробуйте ещё раз позже.”

Do not mention a queue.

Payment pending:
- say payment is still being confirmed and advise not to start another payment until status updates;
- do not explain browser callbacks or “financial result”.

Group publication/republication:
- say “Публикация выполняется”, “Не удалось опубликовать”, “Обратитесь к администратору”, etc.;
- do not expose MODX/synchronization/revision terminology to psychologist.

Expired renewal:
- avoid “синхронизированная группа”;
- use product wording such as “Если группа уже публиковалась, после продления она будет опубликована автоматически.”

Awaiting payment:
- replace “доверенное подтверждение оплаты” with plain “подтверждение оплаты”.

### A3. Preserve technical truth

Do not hide real errors behind false success.

Do not change:
- HTTP status codes;
- authorization;
- payment trust rules;
- queue semantics;
- MODX/publication state machine;
- retry behavior;
- validation constraints.

Only change text/presentation unless a tiny presenter/context flag is required.

### A4. Context-aware shared views

Some shared views render for both admin and psychologist.

Do not remove admin operational details merely to simplify owner UI.

Use existing admin/realGroups/surface/context flags or a small explicit presentation flag where necessary.

Avoid branching on request paths inside shared components if a clean context value already exists.

## B. Feedback page copy

At minimum update:
- real success flash;
- real queue-insertion failure;
- prototype error state;
- related tests/fixtures.

The successful message must not say that Telegram delivery is guaranteed.

Keep:
- text-only restriction;
- 2800 character limit;
- existing authentication/rate limiting;
- database queue implementation;
- Telegram delivery behavior.

## C. Feedback navigation icon

Update the icon for “Сообщить об ошибке”.

Use the already bundled Bootstrap Icons.

Preferred icon: bug.

If bug is unavailable in the bundled version, use the closest existing semantic icon such as exclamation-triangle or chat-left-text.

Do not add another icon library.

The icon remains decorative/accessible in the same manner as existing navigation icons.

Do not change the label.

## D. Favicon

Add a Cabinet favicon and wire it into the shared head so it appears on:
- psychologist pages;
- admin pages;
- public login/password pages;
- prototype pages.

### D1. Asset

Prefer a lightweight local SVG favicon in application/public/, e.g. favicon.svg.

Design should match the existing Gruppa visual language:
- simple;
- readable at 16×16/32×32;
- use existing orange/neutral brand palette;
- no external fonts/assets;
- no CDN;
- no copied third-party logo.

A simple original “g.” / geometric Gruppa mark is acceptable.

Do not introduce a frontend build step.

### D2. HTML

Add an explicit favicon link in the shared surface/public layout head, e.g.:
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

If there are multiple independent public layouts, ensure all real Cabinet pages ultimately include it without duplicating inconsistent markup.

Favicon URL must obey existing production HTTPS/base-path generation.

## E. Tests

Add/update focused tests.

### Copy

Render representative real psychologist pages/states and assert:
- feedback success contains no “очеред”;
- feedback failure contains no “очеред”;
- owner payment pending copy does not contain the old browser/financial-result engineering explanation;
- owner group awaiting-payment copy does not contain “доверенного”;
- owner renewal/publication copy does not expose MODX, Resource ID, revision, or “синхронизирован”;
- representative psychologist pages do not expose technical diagnostics/codes.

Do not assert ordinary product terms like “оплата”, “публикация”, “администратор” or “статус” disappear.

### Icon

Assert rendered psychologist navigation for feedback uses the selected semantic Bootstrap icon and not the generic circle fallback.

### Favicon

Assert representative psychologist, admin, and login/password/public HTML includes the favicon.

Under production URL generation, favicon href must be HTTPS and include /cabinet base path.

Local/testing HTTP remains supported.

### Regression

Keep current PrototypeTest/current catalogue consistent if copy/fixtures change.

## Documentation

Update only current UI/project documentation that materially describes:
- feedback success/error wording, if documented;
- favicon;
- feedback navigation/icon if catalogued.

Do not perform broad historical documentation rewrites.

## Out Of Scope

Do NOT:
- change Telegram delivery mechanics;
- change feedback rate limits;
- add feedback attachments;
- change mail behavior;
- change payment state machine;
- change group lifecycle/publication logic;
- change MODX integration;
- change routes;
- add migrations;
- add packages;
- redesign navigation;
- modify admin operational wording unless shared wording incorrectly leaks into psychologist UI;
- read/change/commit .env_save;
- make real Telegram/mail/MODX/payment calls;
- run migrate:fresh;
- create an accept: commit.

## Acceptance Criteria

1. Psychologist-facing feedback success/error messages contain no queue/job terminology.
2. Representative psychologist-facing status/alert/validation copy is free of unnecessary implementation terminology and remains truthful/actionable.
3. Admin operational UI is not unintentionally stripped of useful technical information.
4. “Сообщить об ошибке” uses a semantic feedback/error icon, preferably Bootstrap bug, not the generic circle.
5. All Cabinet surfaces include a local favicon.
6. Favicon URL is HTTPS/base-path-safe in production.
7. No business behavior, routes, database schema or external integration semantics change.
8. Focused/full regressions pass.

## Checks

Run and report exact results for:
1. focused copy/favicon/icon tests;
2. CabinetImprovementsUiTest;
3. ActionNotificationsTest;
4. payment owner UI regressions;
5. group owner UI/lifecycle presentation regressions;
6. PrototypeTest;
7. ProductionUrlGenerationTest;
8. authentication/password public-layout tests;
9. full MySQL suite;
10. Pint;
11. PHPStan;
12. composer check-platform-reqs;
13. composer validate --no-check-publish;
14. artisan view:cache;
15. artisan route:list;
16. node --check application/public/ui.js if Node is available;
17. git diff --check;
18. final staged/secret/artifact review.

No real external calls.

## Hard Workflow Gate

Before editing:
- run git log --oneline -5;
- run git status --short;
- confirm HEAD is this planner commit and parent is 0aa8c06406361c954f01acc3bcf2e9361868329f;
- read WORKFLOW.md, AGENTS.md, this task and current report;
- inspect all psychologist-facing Blade views/shared context and owner controller flash/validation messages;
- inspect navbar/navigation icon mapping;
- inspect shared/public layout heads;
- verify whether any favicon asset already exists before creating a new one;
- verify clean/known local tree.

During implementation:
- work only on copy/favicon/icon;
- do not edit .ai/task.md;
- keep technical details in admin/logging where appropriate;
- do not change business behavior to simplify wording;
- avoid unrelated CSS/layout changes.

Before commit:
- run all required checks;
- inspect full diff/staged files;
- verify no production/private files/secrets/logs/cache/vendor/temp artifacts;
- update .ai/report.md factually;
- explicitly state no real external calls were made.

If complete, commit with:

codex: TASK-2026-10-03-02 polish psychologist copy and favicon

Do not create an accept commit.
