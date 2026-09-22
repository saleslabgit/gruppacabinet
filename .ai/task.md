# Task: TASK-2026-09-21-12

Status: planned
Created from: 7b7e79c687f329f78ad94887c90e7b590b9b04fe (main)

## Title

Local WEBPAY payment implementation — placement, paid extension, recovery, admin refunds, and staging readiness

## Goal

Implement locally everything that can be completed before real WEBPAY Sandbox credentials and a public HTTPS staging callback are available.

This task intentionally combines the implementation portions of the former Stage 13 and Stage 14 and the local/staging-preparation portion of Stage 15.

After this task, the repository must contain a complete Sandbox-ready WEBPAY payment subsystem that can be deployed to a public HTTPS staging server and verified against the real WEBPAY Sandbox without redesigning or rewriting the payment domain.

Implement:

1. paid initial group placement;
2. paid group extension;
3. WEBPAY protocol-v2 payment form generation;
4. trusted server-to-server payment confirmation from signed notify, with get_transaction limited to verification of an already trusted-bound transaction;
5. browser return/cancel handling without trusting the browser as payment proof;
6. idempotent payment effects under races/repeated confirmations;
7. bounded recovery checks for pending payments;
8. real admin payment list/detail;
9. manual refund accounting only after the administrator has physically refunded in WEBPAY;
10. staging-ready WEBPAY configuration and deployment documentation.

Do NOT require real WEBPAY credentials to mark this local implementation task done.

All real WEBPAY Sandbox network acceptance is explicitly deferred to the next staging task.

## Current Base

Use current main HEAD exactly:

`7b7e79c687f329f78ad94887c90e7b590b9b04fe`

This includes accepted Stages 1–12:

- full cabinet/domain;
- current redesigned UI;
- Stage 11 public-site incoming integration;
- Stage 12 SMTP/password setup/warning jobs;
- database queue and local Mailpit/worker;
- existing payment schema/models/enums/prototype pages;
- payment prices already exist as typed nullable settings.

Do not revert Stage 9–12 lifecycle/mail/UI behavior.

## Authoritative Sources

Business behavior is defined by current SPEC.md, especially:

- §16;
- §19;
- former Stage 13;
- former Stage 14.

Provider-specific protocol must follow current official WEBPAY documentation.

At task planning time the current official documentation confirms:

- Sandbox billing/API cabinet: `https://sandbox.webpay.by`;
- Sandbox payment page: `https://securesandbox.webpay.by/`;
- Production billing/API: `https://billing.webpay.by`;
- Production payment page: `https://payment.webpay.by/`;
- HTML payment form protocol `wsb_version=2`;
- `wsb_test=1` Sandbox / `0` production;
- v2 order signature is SHA1 over the documented ordered concatenation:
  `wsb_seed + wsb_storeid + wsb_order_num + wsb_test + wsb_currency_id + wsb_total + SecretKey`;
- standard POST payment notification uses the documented MD5 signature over:
  `batch_timestamp + currency_id + amount + payment_method + order_id + site_order_id + transaction_id + payment_type + rrn [+ card only when applicable] + SecretKey`;
- standard MVP must not request/store the card field;
- successful payment_type values are `1` and `4`;
- `get_transaction` uses Sandbox/production billing endpoint, WEBPAY username, MD5 representation of API password, and transaction_id;
- response signature uses the documented MD5 ordered concatenation plus SecretKey;
- API operation access may need to be enabled by WEBPAY support.

Before implementation, Codex must re-open/check the current official WEBPAY docs and record the exact provider contract used in `docs/webpay.md`.

Do not switch to a different provider API style merely because a newer JSON API exists; SPEC for this MVP explicitly requires protocol-v2 form + notify + get_transaction behavior.

## Architecture Correction — Trusted WEBPAY transaction binding

This section is authoritative and supersedes any earlier statement in this task that `get_transaction` by itself may confirm a local payment or recover a lost notify.

The implementation was correctly stopped before code changes because current documented WEBPAY `get_transaction` returns a signed transaction response containing provider transaction data such as:

- transaction_id;
- batch_timestamp;
- currency_id;
- amount;
- payment_method;
- payment_type;
- WEBPAY internal order_id;
- rrn;

but it does **not** return the merchant's `site_order_id` / our `wsb_order_num`.

The standard signed WEBPAY notify does contain both:

- `site_order_id` — our merchant order number;
- `transaction_id`;

and both are covered by the notify signature.

Therefore the security boundary is:

### Trusted binding

A WEBPAY `transaction_id` becomes trusted-bound to a local `gp_payments` row only when a valid provider-signed message/response cryptographically binds that transaction to our merchant order number.

For the currently documented MVP protocol, the standard signed notify is that binding source because its signature covers both `site_order_id` and `transaction_id`.

Do not establish the binding from:

- browser return parameters;
- cancel-return parameters;
- amount/currency equality;
- payment method/type equality;
- WEBPAY internal `order_id`;
- an unsigned transaction-id hint;
- a standalone `get_transaction` response that lacks our merchant order number.

### Browser return

WEBPAY browser return may contain our order number and `wsb_tid`, but browser parameters are untrusted.

They may be used only to locate/render the owner's local payment and as a diagnostic hint.

They must not:

- populate the authoritative `gp_payments.transaction_id` field;
- cause `succeeded`, `failed` or `cancelled`;
- establish a trusted transaction/payment binding;
- cause a standalone `get_transaction` result to mutate payment/group state.

A malicious user must not be able to pair one local payment with another WEBPAY transaction merely because amount/currency happen to match.

### Role of get_transaction

Implement the documented `get_transaction` adapter and signature verification because it is still useful provider functionality and required for staging diagnostics.

However:

- a standalone `get_transaction` response is **not sufficient** to map a transaction to a local payment;
- it may mutate payment/group state only when the transaction ID is already trusted-bound to that same local payment by a signed provider message that includes the merchant order;
- amount/currency/method/type checks remain mandatory but are additional consistency checks, not identity proof;
- never search/select a local payment by amount or other non-unique financial attributes.

If a future officially documented WEBPAY endpoint returns a signed merchant order identifier together with transaction_id, adopting it as another binding source requires an explicit later architecture decision and tests.

### Lost-notify recovery

With the currently documented `get_transaction` contract, a completely lost standard notify cannot be safely reconstructed into a trusted local success automatically.

Therefore:

- if no trusted transaction/payment binding exists, the payment remains `pending`;
- after the bounded waiting/recovery window, surface it as requiring manual review;
- do not guess succeeded/failed/cancelled;
- do not keep polling indefinitely;
- administrator resolves the provider status using the WEBPAY cabinet/support during staging/operations;
- manual review does not itself mark payment succeeded;
- payment product effects remain blocked until a trusted signed provider binding is obtained.

This is an intentional fail-closed product/security decision and is the accepted limitation of the current WEBPAY protocol.

### Recovery scheduler behavior

Retain a bounded recovery mechanism only where it is meaningful:

1. select old pending attempts according to the documented timing window;
2. if the payment has a transaction ID already trusted-bound by a signed provider message but still needs a provider status refresh, `get_transaction` may be called and strictly validated;
3. if no trusted binding exists, do not call `get_transaction` using only browser-supplied hints; leave pending and surface manual review;
4. after the bounded window/attempt limit, stop automatic checks.

No general polling.

### Central confirmation service

The central confirmation service accepts only a normalized provider result that includes evidence of trusted local-payment binding.

For signed notify this evidence includes the verified `site_order_id + transaction_id` pair.

A normalized standalone get_transaction result without prior trusted binding must be rejected for product-state mutation.

## External Inputs Explicitly NOT Required For Local Completion

Do not block local implementation on missing:

- real Sandbox `wsb_storeid`;
- real SecretKey;
- real WEBPAY login/password;
- actual API access enablement;
- public HTTPS callback;
- staging domain;
- production credentials;
- final business placement/extension prices.

Use synthetic runtime/test values only.

No secret or real provider credential may be committed.

## Configuration

Add `config/webpay.php` with env-backed configuration.

Required env variables:

- `WEBPAY_ENV=sandbox|production`
- `WEBPAY_STORE_ID`
- `WEBPAY_SECRET_KEY`
- `WEBPAY_API_USERNAME`
- `WEBPAY_API_PASSWORD`

Optional operational config, with safe defaults:

- `WEBPAY_HTTP_TIMEOUT`
- recovery timing/attempt constants if not kept as named application constants.

Environment behavior:

### sandbox

- payment URL: `https://securesandbox.webpay.by/`
- API URL: `https://sandbox.webpay.by`
- `wsb_test=1`

### production

- payment URL: `https://payment.webpay.by/`
- API URL: `https://billing.webpay.by`
- `wsb_test=0`

Provider URLs must normally be selected from `WEBPAY_ENV`, not arbitrary user input.

Allow URL overrides only in automated tests if useful.

Add placeholders to `.env.example`; never add actual values.

Fail closed for paid provider operations when required config is absent.

Free group/extension behavior must remain usable without WEBPAY credentials.

## Price Semantics

Use existing typed settings only:

- `placement_price_minor_units`;
- `extension_price_minor_units`.

Do not hardcode production prices.

If a paid operation requires a price and the relevant setting is null:

- show/return a truthful “price not configured” state;
- do not create a payment with zero/null amount;
- do not change group lifecycle;
- no provider call/form.

Tests/local smoke may temporarily set synthetic values and must restore/clean them.

Money remains integer minor units internally.

Provider decimal formatting must not use float.

## Provider Adapter Boundary

Create a focused WEBPAY adapter/service, not provider code scattered across controllers/models.

Recommended responsibilities:

- config/environment resolution;
- payment-form fields;
- exact order signature;
- notify signature verification;
- get_transaction request generation;
- XML response parsing;
- get_transaction response signature verification;
- explicit trusted-binding evidence in normalized provider result DTO/value object;
- strict decimal -> minor-unit conversion;
- safe provider error normalization.

Controllers must not implement crypto/protocol string concatenation.

TLS certificate verification must stay enabled for outbound HTTPS.

Do not copy legacy examples that disable CURLOPT_SSL_VERIFYPEER/VERIFYHOST.

## Payment Form Protocol

Use standard HTML form protocol v2.

For each attempt produce at least:

- `*scart`;
- `wsb_version=2`;
- `wsb_storeid`;
- `wsb_order_num`;
- `wsb_test`;
- `wsb_currency_id=BYN`;
- `wsb_seed`;
- invoice line(s);
- `wsb_total`;
- `wsb_return_url`;
- `wsb_cancel_return_url`;
- `wsb_notify_url`;
- `wsb_signature`.

Use one clear invoice line:

- placement: group placement;
- extension: group placement extension;
- quantity=1;
- price = exact configured amount.

`wsb_total` must exactly match invoice arithmetic.

Do not send questionnaire/participant data.

Customer email/phone are optional provider fields and should be omitted unless required by verified Sandbox contract.

## Order Numbers

Generate unique application order numbers:

- max 64 characters;
- not based solely on auto-increment DB id;
- opaque/non-sensitive;
- safe for WEBPAY;
- unique DB constraint remains authoritative.

Each new payment attempt gets a new payment row and new order_number.

Never reuse a failed/cancelled prior order for a new attempt.

## Initial Placement Flow

Current Stage 7 behavior must change only where payment is now real.

### Free group snapshot

If owner tariff at group creation is `free=true`:

- no payment;
- create group as `draft`;
- existing flow continues.

### Paid group snapshot

If owner tariff at creation is `free=false`:

- require configured placement price;
- create group in `awaiting_payment`;
- create one `placement` payment tied to group+owner;
- payment amount = current placement price at attempt creation;
- status initially `created`;
- redirect to real placement-payment page.

The immutable `gp_groups.free` snapshot determines initial placement tariff.

Later owner tariff changes do not make this existing paid-snapshot group free.

Admin group creation for a paid owner must preserve the same domain state/snapshot and must not silently bypass payment.

### Payment page/start

Connect existing psychologist placement view to real payment.

Use an explicit CSRF-protected owner action to begin/continue the attempt.

When a created payment is actually started:

- transition `created -> pending`;
- render/submit the signed form to WEBPAY.

Do not create a second payment merely because the payment page was refreshed.

A new attempt is created only after a trusted terminal unsuccessful state or another explicit retry rule.

## Browser Return / Cancel

Implement real browser routes based on the existing payment return templates.

Browser return/cancel are NOT payment confirmation.

Requirements:

- only the owner can see their payment result page;
- cross-owner payment access returns 404/denied without leakage;
- WEBPAY-provided order/transaction identifiers are treated as untrusted hints only;
- browser return must not populate authoritative payment.transaction_id;
- cancel return alone never sets `cancelled`;
- return alone never sets `succeeded`;
- a standalone get_transaction response obtained from browser-supplied wsb_tid cannot confirm this local payment because the documented response lacks our site_order_id;
- provider/API unavailable or no trusted binding -> stay `pending` and show “Оплата подтверждается WEBPAY” / manual-review state as appropriate;
- get_transaction may flow through the central confirmation service only for a transaction already trusted-bound to the same local payment.

Do not let a malicious hint belonging to another payment create cross-payment effects, even when amount/currency/method/type match.

## WEBPAY Notify Endpoint

Add a public server-to-server POST endpoint, recommended:

`POST /webpay/notify`

Full deployed URL will therefore be:

`<APP_URL>/webpay/notify`

Requirements:

- no user auth;
- no CSRF;
- no session dependency;
- standard POST form handling;
- provider-specific signature verified first;
- use `hash_equals`;
- unknown/replayed requests cannot mutate arbitrary payment/group records.

Persist a `gp_payment_notifications` technical journal row for every request that reaches the endpoint when technically possible.

Persist only a minimal whitelist of provider fields needed for troubleshooting.

Never persist/log:

- SecretKey;
- received signature;
- full card number/masked card unless absolutely required by official signature mode;
- credentials;
- arbitrary full provider body.

For this MVP do not request WEBPAY `card` notification field.

## Notify Validation

For a candidate successful standard notify:

- verify exact provider signature;
- payment method must be `cc`;
- payment_type must be trusted successful type `1` or `4`;
- `site_order_id` must match local payment.order_number;
- transaction_id must be valid and consistent;
- amount must equal local payment.amount after exact minor-unit conversion;
- currency must equal local payment.currency/BYN;
- provider identifiers must not conflict with another local payment.

Invalid signature/order/amount/currency/method/type:

- no payment/group mutation;
- safe technical result in notification journal;
- safe operational log without secret/signature/card.

Already-succeeded exact repeat:

- respond successfully to WEBPAY;
- do not reapply product effect.

## get_transaction

Implement via Laravel HTTP client or another existing Laravel-supported HTTP layer.

Do not add a WEBPAY SDK dependency unless strictly necessary; direct provider adapter is preferred for this small fixed protocol.

Request:

- environment-selected billing endpoint;
- command `get_transaction`;
- configured API username;
- configured API password converted to the provider-required MD5 representation inside the adapter;
- transaction_id.

Response:

- safely parse XML;
- reject malformed XML;
- verify provider signature with `hash_equals`;
- validate transaction_id;
- validate amount/currency/payment method/payment type;
- recognize that documented `order_id` is WEBPAY's internal order identifier, not our `site_order_id` / `wsb_order_num`;
- never use amount/currency/internal order_id to establish the transaction-to-local-payment identity;
- only permit state mutation when this transaction ID is already trusted-bound to the same local payment by signed provider evidence containing our merchant order;
- normalize only safe/necessary response fields into `provider_response`.

Never log raw provider response if it may contain unnecessary financial/card data.

Network timeout/5xx/malformed/untrusted response is not success. A validly signed get_transaction response without trusted merchant-order binding is also not sufficient for local payment confirmation.

## Trusted Confirmation Service

Create one central idempotent service used by:

- valid signed notify;
- get_transaction only when the transaction is already trusted-bound to this payment;
- bounded recovery only when such trusted binding exists.

Inside one DB transaction with consistent lock ordering:

1. lock payment;
2. lock related group;
3. re-check payment/group state;
4. validate normalized trusted provider result and explicit trusted-binding evidence;
5. reject state mutation if merchant-order binding is absent;
6. apply payment transition/product effect exactly once.

### Placement success

First trusted success:

- payment `pending -> succeeded`;
- set paid_at UTC;
- set trusted transaction_id;
- store safe normalized provider_response;
- group `awaiting_payment -> draft`;
- create normal group status history through existing transition service.

Repeat trusted success:

- no duplicate history/effect.

### Extension success

First trusted success:

- payment `pending -> succeeded`;
- paid_at/transaction_id/provider_response;
- apply extension exactly once.

If group is still active:

- add stored `placement_days` to existing expires_at;
- status remains active;
- clear expiry_warning_sent_at.

If group is expired:

- transition `expired -> approved`;
- do not set new published/expires dates at payment confirmation;
- manual republish/activation remains Stage 9 behavior.

If a payment attempt began while extension was eligible, the user must not lose a successfully paid extension merely because the clock crossed the expiry/window boundary during the short payment session/recovery period.

Record/validate attempt eligibility at payment creation using existing group state/timestamps; do not allow starting a new paid attempt after the extension window.

Do not let a newly created out-of-window attempt bypass Stage 9 rules.

## Extension Attempt Flow

When owner opens extension:

### Current owner free=true

Keep existing Stage 9 free extension exactly.

No payment row.

### Current owner free=false

- use current owner tariff at attempt start, not `gp_groups.free`;
- require configured extension price;
- group must satisfy existing extension eligibility/window rules;
- create `extension` payment with current configured extension price;
- redirect to real payment page/start flow;
- no group date/status mutation before trusted payment success.

A tariff change after payment creation does not rewrite that attempt.

A later new attempt uses the then-current owner tariff.

Ensure:

- group originally free but owner now paid -> paid extension;
- group originally paid but owner now free -> free extension.

## Trusted Failed / Cancelled Provider Results

Browser cancel itself is not trusted.

Set `failed` or `cancelled` only if a verified WEBPAY API/provider result unambiguously supports that status.

If provider result is ambiguous/unmapped:

- keep pending;
- surface manual review/recovery;
- never guess a terminal status.

Document exact provider mapping in `docs/webpay.md` based on current official docs.

## Retry Flow

For trusted failed/cancelled placement:

- group remains awaiting_payment;
- psychologist may create a new placement payment attempt;
- new order_number;
- current configured placement price for that new attempt;
- historical failed/cancelled payment remains.

For trusted failed/cancelled extension:

- group unchanged;
- new extension attempt allowed only if current business eligibility still allows it;
- tariff decision for new attempt uses current owner.free.

Do not soft-delete payment history merely to retry.

## Recovery of Pending Payments

Implement bounded fail-closed recovery/manual-review classification.

Recommended architecture:

- command `payments:queue-recovery-checks` or an equivalent bounded scheduler;
- scheduled every 5 or 10 minutes with `withoutOverlapping`;
- unique queued check job per payment only when an actual provider check is safe;
- database queue.

No global permanent polling.

Rules:

- first recovery evaluation no earlier than about 20 minutes after attempt starts;
- only pending payments;
- a browser-return `wsb_tid` is not a trusted binding and must not authorize get_transaction mutation;
- call get_transaction only when transaction_id is already trusted-bound to this local payment by signed provider evidence;
- if no trusted binding exists, do not call get_transaction solely from browser hints; keep payment pending and surface manual review;
- use `last_status_check_at` / `status_check_attempts` only for actual trusted-bound provider checks, or document a separate derived manual-review rule if no call is made;
- automatic provider checks are finite and stop no later than one hour after the first allowed check;
- provider/network failure never converts payment to failed/succeeded by assumption;
- a lost notify with no other signed merchant-order binding remains pending/manual review.

The local task must explicitly test that a valid signed get_transaction response for an arbitrary transaction with matching amount/currency cannot be applied to a payment without trusted binding.

## Payment Notification / Recovery Races

Tests must cover real MySQL concurrency for at least:

- notify vs same notify;
- duplicate signed notify vs signed notify;
- signed notify racing with a trusted-bound get_transaction refresh where such state exists.

Exactly one succeeds in applying payment effect.

No duplicate group transition/extension/history.

## Admin Payment UI

Connect existing real payment pages.

### List

`GET /admin/payments`

Support:

- search by order_number / transaction_id;
- filter status;
- filter type;
- filter psychologist;
- date range;
- pagination;
- deterministic sort newest first;
- eager load owner/group;
- no N+1;
- safe manual-review indication.

No provider network request on normal admin list.

### Detail

`GET /admin/payments/{payment}`

Show:

- internal payment ID;
- order number;
- trusted transaction ID if known;
- owner;
- group;
- type;
- amount/currency;
- status;
- created;
- paid_at;
- refunded_at/comment;
- last check;
- attempts/manual review;
- sanitized notification journal;
- sanitized provider_response.

Do not show signatures/secrets/card data.

## Manual Refund Accounting

No automatic WEBPAY refund API in this MVP.

Administrator physically performs refund in WEBPAY Sandbox/production cabinet outside this application.

Application action:

`POST /admin/payments/{payment}/refund`

or equivalent.

Requirements:

- admin only;
- CSRF;
- policy;
- payment must be succeeded;
- required non-empty refund_comment, max 16000;
- confirmation modal;
- transaction/row lock;
- transition `succeeded -> refunded`;
- set refunded_at UTC;
- store comment;
- audit `payment.refunded` with only safe minimal metadata;
- no provider HTTP request;
- repeated refund action rejected/idempotently unavailable;
- wording everywhere: “Отметить возврат выполненным в WEBPAY”.

Existing group delete protection must continue to block succeeded unrefunded payments and allow deletion only after the existing domain rules + refund accounting allow it.

## Admin Group / Moderation Payment Context

Connect real payment information where current approved UI expects it.

For paid groups/moderation:

- show relevant placement payment;
- status/amount/order/transaction link to admin payment detail;
- rejected paid group warns that refund must first be done manually in WEBPAY and then recorded in cabinet.

Do not change moderation rules.

## Psychologist UI

Use existing payment prototype views and current design system.

Required real surfaces:

- placement payment;
- pending return;
- success;
- failed/cancelled trusted result;
- retry;
- paid extension;
- pending extension;
- extension success active;
- extension success expired/republication.

No separate psychologist payment-history section.

Do not redesign.

## Security / Logging

Never log or expose:

- WEBPAY SecretKey;
- API password or MD5 representation;
- full provider signatures;
- unnecessary provider payload;
- card number;
- real credentials.

Use `hash_equals` for provider signature comparisons.

Do not trust:

- browser return;
- cancel URL;
- request IP alone.

Provider signature + field validation is authoritative.

Payment form must never expose SecretKey or API credentials; only the derived wsb_signature is browser-visible.

Application logs use only payment internal ID/order_number and technical error code where needed.

## Local Provider Verification

No real Sandbox account is required in this task.

Automated/focused tests must use deterministic provider fixtures and Laravel HTTP fakes.

Also run a local synthetic smoke:

1. configure temporary synthetic WEBPAY env/config at runtime only;
2. set synthetic placement/extension prices;
3. create paid psychologist/group;
4. inspect generated v2 form fields/signature;
5. transition attempt to pending;
6. send a locally generated valid signed notify to the real local notify endpoint;
7. verify placement becomes succeeded and group becomes draft;
8. repeat notify -> no duplicate effect;
9. verify a standalone signed get_transaction fixture without prior signed merchant-order binding cannot mutate payment/group state;
10. verify extension success through a valid signed notify; optionally verify get_transaction refresh only after trusted binding;
11. simulate failed/cancelled/unavailable responses;
12. verify refund accounting through real admin POST;
13. inspect admin payment list/detail;
14. inspect logs/journal for no secrets/signatures/card;
15. clean temporary data and restore settings/config.

Do not make a real outbound WEBPAY request in the required local smoke.

## Staging Readiness

Create/update `docs/webpay.md` with:

- exact official Sandbox/production endpoints;
- exact env variables;
- payment form field mapping;
- v2 SHA1 signature;
- standard notify MD5 signature;
- get_transaction request/response verification;
- payment state mapping;
- placement/extension effects;
- return/cancel trust boundary;
- recovery strategy;
- manual refund procedure;
- known external prerequisites;
- Sandbox acceptance checklist;
- switch-to-production checklist.

Create/update a staging/deployment checklist in `docs/deployment.md` or equivalent:

- public HTTPS APP_URL;
- notify URL;
- DB migration;
- queue worker;
- scheduler;
- SMTP;
- shared cache/locks;
- WEBPAY Sandbox env;
- APP_DEBUG=false;
- logs;
- backup/rollback;
- route/cache config commands;
- callback reachability.

Do not deploy in this task.

## Required External-Verification Marker

The final report must explicitly say:

- local payment implementation: done/partial;
- real WEBPAY Sandbox payment: NOT VERIFIED unless credentials happen to be supplied by the user;
- real notify delivery from WEBPAY: NOT VERIFIED locally;
- real get_transaction: NOT VERIFIED locally;
- lost-notify automatic confirmation via get_transaction: intentionally unsupported with current documented contract unless a trusted merchant-order binding already exists;
- manual Sandbox refund: NOT VERIFIED locally.

These are expected external staging checks, not local-task blockers.

## Out Of Scope

Do NOT perform:

- actual server deployment;
- DNS;
- TLS certificate provisioning;
- real WEBPAY Sandbox login;
- real payment;
- real provider callback;
- real get_transaction;
- real Sandbox refund;
- production WEBPAY;
- real production credentials;
- public-site changes;
- automatic WEBPAY refund API;
- ERIP;
- recurring payments;
- Apple Pay;
- 3DS customization;
- UI redesign.

## Tests

All database/domain tests use MySQL.

Cover at minimum:

### Configuration / form

- sandbox URLs/test flag;
- production URLs/test flag;
- missing provider config fails closed;
- free flows do not require WEBPAY config;
- v2 fields;
- exact SHA1 signature known-vector;
- random seed included;
- integer-minor -> decimal formatter, including whole and fractional values;
- total/invoice equality;
- no secret/API password in form.

### Placement

- free owner -> draft, no payment;
- paid owner + null price -> rejected/no group/payment;
- paid owner -> awaiting_payment + created placement payment;
- immutable group tariff snapshot retained;
- attempt start -> pending;
- retry only creates a new payment when allowed;
- trusted success -> succeeded + draft exactly once;
- duplicate success no duplicate history;
- failed/cancelled leaves group awaiting_payment.

### Notify

- known valid signature;
- bad signature;
- unknown order;
- wrong amount;
- wrong currency;
- wrong method;
- wrong payment type;
- conflicting transaction ID;
- repeated notify;
- notification journal safe fields only;
- no card/signature/secret persistence.

### get_transaction

- exact request XML/form and MD5 password handling;
- TLS verification remains enabled;
- valid response signature;
- signed response alone does not establish local payment identity because site_order_id is absent;
- invalid signature;
- malformed XML;
- mismatched transaction;
- amount/currency/method/type mismatch;
- network timeout/5xx;
- sanitized provider_response;
- no raw XML/credentials logs.

### Return/cancel

- return cannot succeed without trusted provider response;
- cancel alone does not cancel;
- API unavailable -> pending;
- owner only / IDOR;
- browser-return transaction hint plus even a valid get_transaction response cannot mutate state without prior trusted merchant-order binding.

### Extension

- current owner free -> existing free flow/no payment;
- historical group free does not control extension tariff;
- current owner paid -> created extension payment;
- null extension price -> no payment;
- active success extends exactly once and clears warning marker;
- expired success -> approved exactly once;
- no dates applied until republish for expired;
- out-of-window new attempt blocked;
- pending paid attempt does not mutate group;
- tariff changes affect only new attempts.

### Recovery

- first before 20m blocked;
- finite attempt schedule;
- unique concurrent recovery job;
- only pending selected;
- no transaction hint -> no unsafe provider query;
- network failure leaves pending;
- recovery can confirm only when transaction is already trusted-bound to the local payment;
- lost-notify/no-binding recovery remains pending/manual review;
- matching amount/currency/method/type without merchant-order binding never confirms;
- exhausted recovery remains pending/manual review;
- no permanent polling.

### Race / idempotency

Use real MySQL concurrency:

- notify vs duplicate notify;
- duplicate signed notify;
- signed notify vs already-trusted-bound get_transaction refresh;
- product effect once.

### Refund

- only admin;
- only succeeded;
- required comment;
- sets refunded/refunded_at;
- audit;
- no HTTP provider call;
- repeated action unavailable;
- deletion protection before refund and existing domain behavior after refund.

### Admin UI

- list/search/filters/date/pagination;
- detail;
- links to owner/group;
- manual review;
- no N+1;
- no provider call from list/detail.

### Regression

- Stage 4–12 green;
- Stage 11 API unchanged;
- Stage 12 queue/mail unchanged;
- lifecycle warning marker/reset still correct;
- all 31/249 prototype variants green;
- production prototype isolation green.

## Required Checks

Run and report exact results:

1. `docker compose ps`
2. non-destructive migrations/seeds
3. focused payment/provider tests
4. MySQL race tests
5. local signed-notify smoke
6. local payment UI smoke
7. scheduler list/recovery behavior
8. logs/journal redaction review
9. `docker compose exec -T php php artisan test`
10. `docker compose exec -T php ./vendor/bin/pint --test`
11. `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`
12. `docker compose exec -T php composer check-platform-reqs`
13. `docker compose exec -T php php artisan view:cache`
14. route inspection local+production
15. `git diff --check`
16. inspect full diff/staged files
17. confirm no provider credentials, secrets, real financial/card data, screenshots or runtime artifacts are staged

## Documentation

Update:

- `docs/webpay.md`;
- `docs/development.md`;
- `docs/architecture.md`;
- `docs/project-status.md`;
- `docs/ui-pages.md` only where real payment wiring changes;
- deployment/staging checklist;
- `.env.example`;
- `.ai/report.md`.

Do not edit SPEC.md, WORKFLOW.md or AGENTS.md.

## Acceptance Criteria

1. Provider code is isolated behind a WEBPAY adapter/service.
2. Sandbox/production URLs and wsb_test are config-selected.
3. Protocol-v2 form uses current official required fields.
4. Exact v2 SHA1 signature is implemented/tested.
5. Secret/API password never enters browser form/logs.
6. Money formatting uses integer minor units, no float.
7. Free initial placement remains payment-free.
8. Paid initial placement creates awaiting_payment + placement payment.
9. Null placement price cannot create invalid payment.
10. Each retry gets a new order number/payment.
11. Browser return/cancel never independently confirms payment.
12. Notify endpoint is stateless/CSRF-free/user-auth-free.
13. Notify signature uses official algorithm + hash_equals.
14. Invalid notify cannot mutate product state.
15. Notification journal excludes secrets/signature/card.
16. get_transaction request/response protocol is implemented, but standalone get_transaction cannot establish local payment identity.
17. TLS verification is never disabled.
18. Trusted mutation validates amount/currency/method/type/transaction and cryptographic merchant-order binding.
19. Signed notify establishes site_order_id + transaction_id binding; browser return does not.
20. Lost-notify/no-binding recovery remains pending/manual review rather than guessing success.
21. One central confirmation service enforces trusted-binding evidence for every mutation.
22. Placement product effect applies exactly once.
23. Race/repeated confirmation cannot duplicate status history/effect.
24. Current-owner tariff controls each new extension attempt.
25. Paid active extension mutates expiry only after trusted success.
26. Paid expired extension returns to approved only after trusted success.
27. Old historical gp_groups.free does not override extension tariff.
28. Null extension price cannot create invalid payment.
29. Out-of-window new extension attempt remains blocked.
30. Recovery evaluation starts no earlier than about 20 minutes.
31. Recovery is bounded and not permanent polling.
32. Exhausted uncertain or unbound payment stays pending/manual review.
31. Admin payment list/detail are real.
32. Normal admin payment pages do not call provider.
33. Manual refund accounting never calls WEBPAY refund API.
34. Refund sets refunded/refunded_at/comment and audit.
35. Unrefunded succeeded payment continues to block protected deletion.
36. Psychologist still has no payment-history section.
37. Existing payment UI/prototypes become real without redesign.
38. Stage 11 integration remains green.
39. Stage 12 mail/queue remains green.
40. Full MySQL suite passes.
41. Pint passes.
42. Larastan passes.
43. Composer platform check passes.
44. Blade compilation passes.
45. 31/249 prototype suite remains green.
46. Production prototype isolation remains.
47. docs/webpay.md documents exact current provider protocol and external Sandbox checklist.
48. deployment/staging checklist is sufficient to deploy without inventing missing values.
49. Final report clearly distinguishes local done from external Sandbox unverified.
50. No credentials/secrets/real financial data/unrelated artifacts committed.

## Hard Workflow Gate

Before editing:

- read WORKFLOW.md;
- read AGENTS.md;
- read this `.ai/task.md`;
- read SPEC only around §16, §19 and former Stages 13–15;
- inspect current payment schema/model/enum, GroupWorkflow, GroupLifecycleService, policies, payment prototypes, settings, Stage 12 queue;
- re-check current official WEBPAY documentation for:
  - environment URLs;
  - payment form v2;
  - order signature;
  - standard notify;
  - get_transaction;
  - API-access prerequisites;
- run `git log --oneline -5`;
- run `git status --short`;
- confirm base `7b7e79c687f329f78ad94887c90e7b590b9b04fe`;
- do not overwrite unknown local changes.

During implementation:

- local implementation only;
- no real WEBPAY credentials/network acceptance required;
- no real refund;
- no staging deploy;
- no production deploy;
- do not weaken payment trust boundaries to make local smoke easier;
- no UI redesign;
- preserve Stage 11/12 behavior;
- do not edit `.ai/task.md`;
- do not edit SPEC/WORKFLOW/AGENTS.

Before commit:

- run all required checks;
- inspect full diff;
- inspect logs/journal fixtures for sensitive data;
- remove temporary synthetic payment data/config;
- update `.ai/report.md`;
- stage only justified local WEBPAY implementation/tests/docs/report.

Completion:

Use Status: done when the complete local/Sandbox-ready implementation and all local checks pass, even though real Sandbox acceptance remains explicitly unverified.

Use partial/blocked only for a genuine local implementation blocker.

If complete, commit with:

`codex: TASK-2026-09-21-12 implement local webpay payment flows`

Do not create an accept commit.
