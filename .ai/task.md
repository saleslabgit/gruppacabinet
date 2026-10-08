# Task: TASK-2026-10-08-02

Status: planned
Created from: 825905cdd8ac3cb9792dfae93603ab474ca69acd (main)

## Title

Auto-refresh psychologist payment status and simplify order details

## Goal

Finish the confirmed live WEBPAY user journey in Gruppa Cabinet with two small,
cohesive owner-facing improvements:

1. After WEBPAY sends the psychologist back to Cabinet, the "Оплата
   подтверждается" screen should automatically notice a changed local payment
   status and show the authoritative result, without asking the user to click
   "Обновить страницу".
2. Reduce technical clutter on psychologist payment result pages: keep the
   current status, amount and useful next action prominent, but move the merchant
   order number into accessible, initially collapsed "Детали платежа". Preserve
   the complete order number for support and all existing admin accounting views.

Do not modify financial confirmation semantics, provider protocol, payment
attempt lifecycle or real merchant credentials.

## Confirmed context and repository facts

- Current accepted main HEAD:
  825905cdd8ac3cb9792dfae93603ab474ca69acd.
- The product owner has performed one live WEBPAY payment. They report that
  payment confirmation arrived, and after clicking "Обновить страницу" the
  Cabinet correctly showed a paid result and the new group became a draft.
  This is a product-owner observation, not an automated external test.
- Current psychologist payment result template:
  application/resources/views/psychologist/payments/return.blade.php.
  It renders pending/succeeded/failed/cancelled/refunded statuses and useful
  actions; pending has a working manual refresh link.
  It currently displays the full "Номер заказа" (merchant order_number,
  typically GP-...) directly alongside the amount.
  It is used by live pages and local/testing prototype variants.
- application/app/Http/Controllers/Psychologist/PaymentController.php:
  - GET /payments/{payment} is owner-scoped and renders local payment state;
  - GET /payments/{payment}/return or /cancel uses PaymentRecovery::check
    before rendering; this can eventually contact WEBPAY for a verified-bound
    due recovery attempt, so do NOT use return/cancel URLs for automatic polling;
  - owner-check helper resolves Payment by owner_id and calls Gate::authorize('view').
- application/routes/web.php has existing payments owner group with
  ['account', 'role:psychologist']; no lightweight local-status endpoint yet.
- application/public/ui.js is the already approved local frontend entrypoint,
  included by application/resources/views/layouts/surface.blade.php with a
  filemtime-based cache-busting query; it already has the narrow WEBPAY form
  auto-submit hook from TASK-2026-10-08-01.
- application/app/Enums/PaymentStatus.php values:
  created, pending, succeeded, failed, cancelled, refunded.
- Admin payment detail uses application/resources/views/admin/payments/show.blade.php
  and shared.payment-data. Admin order number and provider information must remain.
- Prototype catalogue/fixtures use the real return view with $realPayments
  unset/false. Prototype examples must stay inert and DB-independent.
- WEBPAY notify/ConfirmPayment are responsible for trusted state changes.
  PaymentRecovery's bounded provider check must not be triggered by polling.
- Production is already receiving real payments. Never touch actual .env,
  .env_save, live payment data or provider endpoints during implementation.

## A. Lightweight, owner-scoped, read-only status endpoint

Implement a minimal JSON GET within the existing psychologist payment routes,
e.g. GET /payments/{payment}/status, named
psychologist.payments.status.

Required behavior:

1. Use existing account + psychologist-role middleware, owner lookup and
   PaymentPolicy::view authorization. No unrelated user may query another
   owner's payment; an admin account must not access psychologist polling.
   Preserve existing app conventions for absent/unauthorized users.
2. Read ONLY persisted local payment status (e.g. {'status':'pending'}).
   Do not return full Payment DTO, IDs, order number, personal data, signed
   fields, provider_response, credentials, journal, timestamps or API status.
3. Explicit JSON response and Cache-Control: private, no-store; no redirects to
   WEBPAY, no Webpay::transaction, no PaymentRecovery::check, no DB writes,
   no status transitions, no queue dispatch or external calls.
4. Keep all existing start/retry/show/return/cancel/notify routes unchanged in
   meaning and authorization. New GET is not an auth-free public callback.
5. A terminal status must be authoritative from DB. Browser parameters, caller
   hints and JS never mark payments paid or failed.
6. Keep implementation local and simple; no new packages or schema migrations.

## B. Bounded auto-refresh on the real pending payment result page

Change only the current psychologist payment result presentation and existing
local ui.js, reusing the approved Blade/CSS/component style.

1. Render a narrowly scoped status-poll marker/config only when
   ($realPayments ?? false) is true AND $payment['status'] === 'pending'.
   This includes return, cancel and ordinary show URLs if pending; no polling on
   created, succeeded, failed, cancelled, refunded, payment start form,
   prototype, admin, errors, unrelated pages.
2. JS checks the new SAME-ORIGIN, authenticated, local read-only status GET
   **every 5 seconds**, for at most **2 minutes** from page initialization.
   First scheduled check can be after 5 seconds. Stop after 120 seconds even
   if the response stays pending or requests fail.
3. Ensure only one in-flight request; use a safe bounded timer loop (setTimeout
   rather than piling up overlapping intervals). Avoid fetch traffic while the
   tab is hidden if practical; clean up on pagehide/navigation. Respect the
   overall 120-second wall-clock budget regardless of tab visibility.
4. When local status changes to succeeded/failed/cancelled/refunded, stop polling
   and navigate/reload ONCE to the canonical owner-only
   psychologist.payments.show URL. The server renders the authoritative
   result, existing contextual copy and buttons. Do NOT navigate to
   /return or /cancel automatically because those call PaymentRecovery::check.
   Do not claim paid status solely from JS; no optimistic DOM mutation.
5. If status stays pending past 2 minutes, stop background requests and leave
   the existing manual "Обновить страницу" control. Optional short, truthful
   hint such as "Если статус не обновился, попробуйте обновить страницу" is
   acceptable; do not describe it as payment failure or auto-retry payment.
6. On fetch errors, offline, timeout, non-JSON/unexpected response, 401/403/404,
   or expired authentication: do not send payment/retry/start requests; handle
   quietly, stop polling as appropriate, and keep manual refresh/link/navigation
   usable. Never display technical error bodies or secrets.
7. Disabled JavaScript retains existing page, status message and manual refresh.
   The "Продолжить эту оплату" button must retain its existing explicit
   owner-action semantics; JS must never click or auto-submit it.
8. Keep JS confined to a distinct payment-result marker; no interference with
   previous "data-webpay-auto-submit" checkout behavior, forms, prototypes or
   shared select/editor interactions.
9. Prefer native browser capabilities and already bundled libraries. Do not add
   a new polling framework, dependency, background queue or unbounded live stream.
10. The user-facing difference must work for initial paid placement and paid
    active/expired extension; preserve the current success explanations.

## C. Simplify psychologist payment number display

In psychologist/payments/return.blade.php only:

1. Retain visible status/alerts, amount and the appropriate action:
   "Заполнить группу" for succeeded placement, "К группе" for successful
   extension, "Повторить оплату" only where already permitted, pending refresh,
   etc. No misleading promises about WEBPAY completion.
2. Remove "Номер заказа" from the always-visible main detail grid.
3. Keep the existing exact merchant order_number in a small, native,
   keyboard-accessible and initially collapsed disclosure labeled
   **"Детали платежа"**. It must reveal a human-readable "Номер заказа"
   suitable for copying to support. Escape it as regular Blade text.
   Prefer native <details>/<summary> with minimally necessary approved
   styles, rather than a new JS dependency or modal. Long values must wrap on
   narrow mobile screens.
4. Ensure the order number is NOT removed from server-side data, provider
   requests, admin views, history, exports (if any), confirmation binding,
   journal or support/accounting. Payment ID in routes is unaffected.
5. The shared prototype payment-result variants should render the same
   disclosure normally, but remain purely demonstrative with no polling,
   provider form or financial requests.
6. Do not touch unrelated group pages, feedback UI, admin payment screens
   or redefine payment IDs/transaction IDs.

## D. Tests and verification

Add focused deterministic tests, not just static text assertions.

### PHP/HTTP

1. Authenticated owner status GET returns exactly whitelisted status JSON for
   pending, succeeded, failed, cancelled, refunded (created may also be tested).
   Content-Type JSON, Cache-Control private/no-store.
2. Another psychologist cannot see a payment (404/authorized application
   convention), admin cannot query psychologist owner endpoint, guest/revoked
   access follows the existing auth policy. No sensitive fields returned.
3. Repeated status requests produce no changes to payment/group/history,
   PaymentRecovery counters, transaction bindings, notification journal, jobs,
   provider Http calls, mail or other external work.
4. For real pending display, status-poll marker contains correct HTTPS-safe
   /cabinet URLs for poll and canonical show when app.url is production;
   manual refresh and "Продолжить" remain as before.
5. For succeeded/failed/cancelled/refunded, created/checkout pages, all
   prototype payment variants and admin pages: no polling marker. In real and
   prototype owner result views, main amount/status remain, the full merchant
   order number is ONLY inside initially collapsed "Детали платежа".
6. Simulate trusted notification with existing signed fake fixtures: pending
   owner status endpoint reports pending; after notify, endpoint reports
   succeeded and full owner GET renders "Оплата подтверждена" with the correct
   placement or extension action. No provider network access during polls.
7. Confirm existing WebpayTest, WebpayConcurrencyTest and applicable group/payment
   lifecycle/security tests continue to pass.

### JavaScript

8. Run node --check on updated ui.js; add an efficient Node VM fake-DOM/fake-
   fetch/fake-timers scenario (or equivalent available local test harness)
   proving: pending marker triggers bounded 5-second checks; max 120 seconds;
   no overlapping requests; one canonical refresh on terminal; error/unknown
   response fallback; no polling when absent; current WEBPAY auto-submit hook
   unaffected. No real network or payment calls.
9. If browser automation is available, test automatic transition and manual
   fallback in a synthetic/sandbox-only environment. If unavailable, state
   honestly that browser/live flow is not externally verified and requires
   product-owner manual acceptance after deployment.

### Quality gates

Run and report exact results for:
- focused new status/presentation/JS tests;
- WebpayTest, WebpayConcurrencyTest, PaymentEraGroupsTest,
  GroupWorkflowTest, GroupLifecycleTest, PrototypeTest,
  ProductionUrlGenerationTest, AuthenticationTest and other affected tests;
- full MySQL suite;
- Pint and PHPStan;
- composer check-platform-reqs and composer validate --no-check-publish;
- artisan view:cache and route:list (include new owner GET);
- node --check application/public/ui.js;
- git diff --check and staged/private/secret review.

## E. Documentation and deployment handoff

Update only relevant current documentation:
- docs/webpay.md: local pending status polling, 5 s / 120 s, server-only trust,
  manual fallback, no provider API calls from polling;
- docs/ui-pages.md: existing owner payment result interaction and collapsed
  order number;
- docs/deployment.md only if needed to note new read-only owner route and
  release of application/public/ui.js to separately copied public /cabinet.

In .ai/report.md describe exactly what changed, tests actually run, limits and
steps to verify the real browser after deploying the new code and public ui.js.

Manual production smoke (not to be performed by Codex):
- after separately authorized REAL payment that requires confirmation,
  see pending page, allow notification, observe automatic result update
  within a few polling intervals, check draft/group effect;
- verify 2-minute pending fallback, no-JS manual refresh, denied owner IDOR,
  and admin unchanged;
- verify order visible only when expanding details on owner pages.
Do NOT conduct another charge just for a test without operator approval.
A visible JS polling success is NOT proof of financial confirmation; only
trusted WEBPAY notify/recovery determines the persisted status.

## Hard constraints / out of scope

- Do NOT touch production .env, private .env_save, real merchant credentials,
  payments, database records, bank accounts or user data. No real WEBPAY,
  Telegram, mail, MODX requests, charges, refunds or provider probes.
- Do NOT modify Webpay, signed notify verification, ConfirmPayment,
  PaymentRecovery behavior, PaymentAttempts, tariffs, order IDs, product
  effects, refund/delete policy, worker/scheduler logic or payment methods.
- Do NOT change old sandbox/production attempt handling, require cleanup,
  archive test payments, rotate secrets or introduce environment migration.
- Do NOT add migrations, packages, queues, persistent polling or third-party
  browser APIs.
- Do NOT replace the accepted UI design or create parallel payment pages;
  minimal structural changes in the current Blade are allowed.
- Do NOT run migrate:fresh or create an accept: commit.
- Keep production URL generation HTTPS and /cabinet-safe.
- If implementing local polling requires weakening financial-trust rules or
  broad architecture changes, stop and report a blocker instead.

## Acceptance criteria

1. While real owner payment status is pending, JS polls local DB-only status
   every 5 s, at most 120 s, with one in-flight check.
2. When server status is terminal, the browser navigates once to canonical
   owned payment show and displays truthful existing server-rendered result.
3. On 120 s timeout, JS disabled/offline/errors, manual refresh remains usable.
   Nothing auto-pays, retries, contacts WEBPAY or alters money/group status.
4. The full merchant order number is outside the main owner payment details,
   but accessible by expanding "Детали платежа"; admin unchanged.
5. Authorization, HTTPS path, CSRF on actions and external signed notify trust
   remain intact. No extra financial API requests.
6. Relevant synthetic tests and quality gates pass; browser/live limitations
   documented; no migrations or production credentials/actions.

## Codex workflow gate

Before editing:
- git log --oneline -5; git status --short;
- confirm HEAD is this planner commit and parent is
  825905cdd8ac3cb9792dfae93603ab474ca69acd;
- read WORKFLOW.md, AGENTS.md, this .ai/task.md, .ai/report.md;
- inspect the approved psychologist/payments/return and placement Blade,
  layouts, styles, local ui.js and docs/ui-pages.md;
- inspect PaymentController, routes, PaymentPolicy, PaymentStatus, PaymentPages,
  PaymentRecovery and provider-notify tests;
- verify clean/known local tree and preserve unknown changes.

During:
- make the smallest safe owner-status + presentation/JS change;
- no external financial calls;
- do not edit .ai/task.md.

Before commit:
- run applicable checks and record results truthfully in .ai/report.md;
- inspect full diff/staged files for secrets, credentials, production data,
  generated artifacts, logs, caches, vendor or unrelated modifications;
- stage only task files, keep .ai/task.md untouched;
- if blocked, report status rather than pretending completion.

If complete, commit:

codex: TASK-2026-10-08-02 auto-refresh payment result and hide order details

Do not create an accept commit.
