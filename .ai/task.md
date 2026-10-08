# Task: TASK-2026-10-08-01

Status: planned
Created from: 52454eeb5208b31a7246205e46c29cec49a3f54e (main)

## Title

One-click WEBPAY checkout and production-readiness audit

## Goal

Prepare Gruppa Cabinet's existing WEBPAY card integration for a later, separately
authorized switch from sandbox credentials to real production credentials.

Deliver two verifiable results in one payment-focused milestone:

1. Remove the unnecessary second "Оплатить картой" click. Once a psychologist
   explicitly clicks to pay on the Cabinet payment page, the prepared, signed
   HTML POST form should automatically take the browser to WEBPAY.
2. Audit the existing WEBPAY/payment code and operational runbook for live
   acceptance. Produce a precise, honest GO / NO-GO checklist. Do not claim
   that real payments work unless actual provider acceptance was performed.

Do not deploy production credentials or make real provider/financial requests.

## Current verified facts

- HEAD is the accepted 20 MiB upload change
  52454eeb5208b31a7246205e46c29cec49a3f54e.
- The psychologist creates a paid group and lands on
  GET /payments/{payment}. It initially displays the owner-only CSRF POST
  /payments/{payment}/start.
- PaymentController::start calls PaymentAttempts::start, marks the existing
  attempt pending under locks, generates the WEBPAY v2 signed form, then renders
  the **same** psychologist/payments/placement.blade.php with providerForm.
- That Blade form currently has a second manual submit button labeled
  "Оплатить картой" to POST the signed fields to WEBPAY; it does not auto-submit.
  This exactly explains the owner's observed double-click behavior. There is no
  evidence of a second local payment record being created by this step.
- The same start route also handles "Продолжить эту оплату" from pending and
  applies to paid extensions.
- WEBPAY provider config maps sandbox -> securesandbox.webpay.by, wsb_test=1;
  production -> payment.webpay.by, wsb_test=0; API uses sandbox.webpay.by or
  billing.webpay.by.
- Payment form signs random seed, store ID, merchant order, test flag, BYN and
  exact decimal amount using v2 SHA1 plus SecretKey. Production callbacks use
  APP_URL=https://gruppa.info/cabinet.
- The signed standard notify supplies trusted merchant-order binding. Browser
  return/cancel never proves a financial outcome. There is bounded recovery
  only for verified-bound pending attempts.
- Existing docs/webpay.md says real Sandbox provider acceptance is NOT VERIFIED:
  no real payment, signed notify delivery, get_transaction or refund test.
- Existing deployment:preflight checks WEBPAY mode for being sandbox|production
  and checks presence of four credentials. It does NOT prove keys are correct,
  real API permission, routing or signed callback delivery. It can report
  'WEBPAY environment' PASS even when APP_ENV=production and WEBPAY_ENV=sandbox.
- In the current gp_payments schema, individual attempts do not store their
  sandbox/production environment. Switching global keys/endpoints while older
  sandbox created/pending attempts exist can make future continuation or
  late notifications ambiguous. This needs an explicit safe cutover gate.
- Official WEBPAY documentation requires real checkout from the contracted
  domain, production Store ID and wsb_test=0, and gives support-only opt-in for
  unsuccessful-operation notifications. WEBPAY get_transaction documents account
  login and MD5(password); our PHP adapter hashes the ordinary configured
  password itself.
- Project code/tests/docs can be audited locally; private production .env,
  real WEBPAY billing settings, hosting WAF, live queue, certificate/callback
  delivery and bank processing cannot be inferred from repository source.
- Current live /cabinet/login and /cabinet/webpay/notify could not be independently
  inspected using the available web viewer. Do not treat this as proof of a
  broken endpoint or of a working one.

## A. Fix one-click checkout

Make a small, progressively enhanced change to the existing approved payment
Blade and existing local UI JavaScript. Keep the approved component language.

1. GET /payments/{id} for a newly created attempt still displays the owner's
   deliberate "Оплатить картой" action, a CSRF POST to the existing start route.
   Do NOT auto-start payments on page load / GET and do NOT POST to WEBPAY before
   the user clicks the Cabinet payment action.
2. When the authorized start POST succeeds and the response contains
   providerForm, send **that exact** signed form to the provider automatically
   from the browser (native HTML form POST, not AJAX or an invented redirect
   URL). Use a narrowly scoped data attribute + local JS in application/public;
   no inline JS/CDN/dependencies needed.
3. The intermediate response should have clear short copy such as
   "Переходим к оплате…" and an explicit manual "Перейти к оплате" fallback
   button for disabled JS, JS errors or blocked navigation. Do not show the
   original placement explanation and an apparently second "Оплатить картой"
   action again. Avoid redesigning the approved payment page.
4. Do not auto-submit provider forms on ordinary GET pages, prototype pages,
   errors, terminal payments, or the pending status page without an explicit
   start/continue POST. Manual fallback should remain an actual working POST
   to the exact pre-approved WEBPAY form action.
5. Preserve existing response Cache-Control no-store/private and
   Referrer-Policy strict-origin-when-cross-origin. Never put SecretKey,
   API password or other credentials in HTML/JS; derived signature and
   merchant fields remain permitted. Never log full signed forms.
6. Double-click/back/reload must not create another payment row, change the
   order_number, apply product effects, or bypass the existing start/policy
   checks. The same signed form/amount/order should be used for one response
   regardless of whether auto-submit or fallback button is used.
7. Cover paid new placements, paid active/expired extensions, pending "continue
   this payment", retry after signed failure/cancellation, and owner authorization.
   No new payment routes, business states, migrations or provider API contracts.
8. Do not hide or redefine payment errors; preserve safe result copy.

## B. Payment production-readiness audit

Inspect actual current code, configuration and tests for the full payment journey.
At minimum:

- payment attempt creation for new paid group and paid extension;
- tariff validation and amount snapshots (BYN minor units);
- one-click provider form, signing and sandbox/production endpoints;
- trusted standard notification signature, payment_method/currency/amount/
  order/transaction consistency, duplicate/out-of-order callbacks;
- browser return/cancel and unbound pending/manual review;
- bounded get_transaction recovery, queue and scheduler;
- succeeded/failed/cancelled/refunded status mapping;
- group Draft transition for first successful paid placement;
- active extension and expired renewal publication interaction;
- administrative payment history, safe logs, external refund first plus local
  accounting, and deletion safeguards;
- owner role, CSRF and callback no-auth/no-CSRF split;
- APP_URL/proxy/subdirectory HTTPS and Webpay production domain/Referer;
- credential presence and preflight shortcomings;
- the ability of a previously created sandbox attempt to survive into a
  production configuration if not reconciled before switching.

If a concrete security or financial correctness defect is found, identify
the exact file/test/scenario. Fix focused low-risk defects necessary for this
go-live milestone. If an architectural change, new schema, financial trust-rule
change, migration, or provider protocol assumption is necessary, STOP and report
it as blocked with alternatives rather than silently inventing a fix.
Do not expand into unrelated UI, MODX, mail or Telegram code.

### B1. Read-only production readiness / preflight

Ensure the documented acceptance gate does not imply live readiness merely
from having nonempty secrets.

Inspect existing deployment:preflight:
- it may be reasonable to add a clear production-mode check:
  for APP_ENV=production, WEBPAY_ENV must equal production for *live*
  readiness, while staging still supports sandbox;
- any added check must be read-only, reveal at most environment and credential
  PRESENCE, not values, and be covered by regression tests;
- do not contact WEBPAY or modify business data from preflight.
- independent external acceptance remains required even if all checks PASS.

### B2. Cutover risk: outstanding sandbox attempts

Before inserting production keys, the operator must inspect and reconcile
existing 'created'/'pending' sandbox payment attempts in the retained DB.

Do not delete, expire, mark paid, refund, or close such attempts automatically.
Do not invent manual mark-paid controls.

Document how operators can inspect **counts and status/order IDs only** without
printing personal data, secrets or payment payloads; coordinate a quiet cutover
and preserve payment history. Existing attempts do not encode environment:
describe the risk and a clear decision gate. If this cannot be handled safely
with operational reconciliation, report a BLOCKER for explicit product/architecture
decision rather than adding an environment-migration silently.

### B3. External WEBPAY prerequisites (not verifiable in repository)

Give the operator a safe checklist including:

- contracted exact site origin/domain 'gruppa.info' and allowed callback
  HTTPS host/path under /cabinet; referer not stripped;
- separate REAL Store ID, REAL SecretKey (same in real billing and private env),
  account login/password for get_transaction (unhashed in private env), and
  confirmation that necessary API access is enabled; do not request actual values;
- merchant configured for expected ordinary card/one-stage flow; no
  card-inclusive notify signature mode, SOAP-only notify, unsupported method
  or two-stage capture assumptions;
- enable unsuccessful-operation signed notifications via WEBPAY support if
  automatic failure/cancel recognition is required; otherwise document the
  manual-review fallback as a business limitation;
- queue/scheduler, database cache/locks, mail/operational logging, backups and
  rollback runbook;
- production setup: APP_URL=https://gruppa.info/cabinet,
  WEBPAY_ENV=production, WEBPAY_STORE_ID, WEBPAY_SECRET_KEY,
  WEBPAY_API_USERNAME, WEBPAY_API_PASSWORD, positive approved real tariffs;
  separate environment values from actual secrets in output;
- check generated action https://payment.webpay.by/ with wsb_test=0 and
  return/cancel/notify HTTPS /cabinet URLs, never expose the full signed form;
- realistic Sandbox end-to-end success, signed failure/cancel, duplicate notify,
  return-before/after-notify, bounded recovery and refund acceptance before
  authorizing the real merchant switch;
- separately authorized low-amount LIVE acceptance (only after all gates);
  verify exact bank debit, signed notify, local payment state, group effect,
  and accounting. Refund via provider first, then local admin accounting.
- public notify route must answer correct stateless HTTP 200 to valid signed
  callbacks, reject invalid ones, allow inbound HTTPS 443 POST without
  auth/CSRF/WAF challenge/redirect, and must remain up if browser never returns.

Be precise about where "verified in code", "verified by fake tests",
"requires operator live check", and "BLOCKED until acceptance" differ.

## C. Automated checks

Add/update relevant tests without real WEBPAY requests. At least:

1. GET created payment has only the intentional local start form, NO
   provider-auto-submit marker and no direct external provider form.
2. Authenticated owner POST /payments/{id}/start produces the signed,
   method=POST provider form with the one-click auto-submit hook and fallback.
3. The provider fields/action differ correctly between sandbox and production:
   sandbox URL + wsb_test=1, production URL + wsb_test=0, and exact HTTPS
   callback URLs under /cabinet; same signed order/amount semantics.
4. Pending "continue" POST gets the same transition hook, does not create a
   new attempt or reset started_at.
5. Signed failed/cancelled retry, new placement and paid extension remain
   correct and single-effect.
6. Untrusted browser-return/cancel never confirms payment.
7. Owner/admin/other-user role and CSRF behavior unchanged; stateless signed
   notify unaffected.
8. If preflight changes, cover production live-mode gate vs sandbox staging.
9. UI JS is syntax-valid and confined to expected form; if browser-level check
   is unavailable, explicitly report that the real auto-navigation is still
   awaiting manual user-browser acceptance. Never claim external redirect or
   real provider traffic was exercised by feature tests.

Run and report the exact results for:
- focused payment/checkout tests and API/security regressions;
- WebpayTest, WebpayConcurrencyTest, PaymentEraGroupsTest, GroupWorkflowTest,
  GroupLifecycleTest, PrototypeTest, ProductionUrlGenerationTest,
  DeploymentPreflightTest (if touched);
- full MySQL suite;
- Pint, PHPStan, composer check-platform-reqs, composer validate;
- artisan view:cache, route:list, schedule:list;
- node --check on changed JS if Node available;
- git diff --check and staged/secret review.

No real provider, financial, mail, Telegram or MODX requests in automated checks.

## D. Documentation and report

Update:
- docs/webpay.md: exact one-click flow, safeguards, non-verified external
  acceptance, production readiness gates, staged->live cutover risk;
- docs/deployment.md: exact safe go-live/checklist and rollback;
- docs/ui-pages.md only for actual changed checkout interaction.

In .ai/report.md include:
- observed root cause and implementation summary;
- precise code-audit findings, any blockers/risks, evidence/tests;
- concise GO/NO-GO table distinguishing static readiness vs external checks;
- operator-ready deployment/smoke checklist;
- an explicit statement if provider acceptance remains unverified.

Do not mark Sandbox/production tests verified without running them.

## Hard constraints

- Do NOT access/print/commit real merchant/API credentials, secret-bearing
  forms, production private .env or .env_save, personal/payment data or logs.
- Do NOT call actual provider endpoints, trigger a real payment, refund or
  signed notify, or alter production configuration.
- Do NOT weaken signature, order binding, replay, amount, or trusted-notify
  requirements just to ease a test or unbound recovery.
- Do NOT redirect with GET carrying payment parameters; use a signed POST form.
- Do NOT add packages, routes or schema migrations unless a blocker is
  escalated for a separate user decision.
- Do NOT change unrelated MODX workflow, mail/Telegram, historical 405 or
  HTTPS redirect internals; no payment deletion or automatic refunds.
- Do NOT touch untracked production .env_save or run migrate:fresh.
- Do NOT create an accept: commit.

## Acceptance criteria

1. One click from the existing owner payment screen initiates WEBPAY navigation
   through an automatically submitted signed POST; non-JS fallback works.
2. No duplicate local attempt/order or speculative paid status.
3. Existing WEBPAY callbacks, amount/signature verification, retry,
   idempotency, security and group lifecycle remain intact.
4. Tests for checkout, production/sandbox form flags and relevant payment
   security flows pass, with limitations stated.
5. Readiness audit and docs enumerate **real** outside-provider, hosting and
   cutover gates. No claim of live readiness without live acceptance.
6. No production keys or external financial calls were used.

## Codex workflow gate

Before editing:
- git log --oneline -5; git status --short;
- confirm HEAD is this planner commit, parent is
  52454eeb5208b31a7246205e46c29cec49a3f54e;
- read WORKFLOW.md, AGENTS.md, .ai/task.md, current .ai/report.md;
- inspect existing approved placement/return Blade, surface layout,
  existing ui.js and docs/ui-pages.md;
- inspect payment controllers/attempts/provider/notify/confirmation/recovery,
  policy, tests, deployment preflight and official WEBPAY docs linked
  in docs/webpay.md;
- verify known/clean local tree.

During work:
- use the smallest faithful change; no unrelated refactors;
- do not edit .ai/task.md;
- if real financial safety ambiguity appears, stop and report blocker.

Before commit:
- run checks, inspect diff and staged files;
- ensure no secrets, credentials, production data, vendor/cache/log artifacts;
- update .ai/report.md factually, including all unverified gates.

If complete commit:

codex: TASK-2026-10-08-01 one-click WEBPAY checkout and launch audit

Do not create an accept commit.
