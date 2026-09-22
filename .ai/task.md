# Task: TASK-2026-09-21-13

Status: planned
Created from: 094e94e1734707cdf9c607c989eaa03461da2893 (main)

## Title

Stage 13 local WEBPAY correction — complete real payment-era admin/group integration and staging prerequisites

## Goal

Correct the remaining integration gaps found during acceptance review of TASK-2026-09-21-12.

The core local WEBPAY implementation is structurally sound and must be preserved:

- protocol-v2 form/signature;
- signed notify as the trusted merchant-order binding;
- standalone get_transaction cannot bind a local payment;
- lost-notify/no-binding stays pending/manual review;
- central idempotent confirmation;
- paid placement and paid extension;
- bounded trusted-bound recovery;
- manual refund accounting;
- current UI design baseline.

This correction is intentionally narrow. Do not rewrite the payment architecture.

## Base

Use exactly:

`094e94e1734707cdf9c607c989eaa03461da2893`

Do not revert the accepted Stage 1–12 behavior or the local WEBPAY implementation.

## Acceptance Review Findings

### 1. Real abandoned-group workflow does not include awaiting_payment

Paid placement now creates real `awaiting_payment` groups.

However current admin behavior still reflects the pre-payment era:

- quick filter `abandoned` selects only `draft`;
- admin delete policy allows only old `draft`;
- therefore an abandoned unpaid `awaiting_payment` group cannot be found through the intended quick filter and cannot be manually soft-deleted by admin.

SPEC requires abandoned records to include both:

- `awaiting_payment`;
- `draft`.

Correction:

- admin quick filter `abandoned` must include both statuses, using the existing abandoned age threshold;
- admin delete eligibility must include old `awaiting_payment` and old `draft`;
- existing payment safety remains authoritative:
  - any succeeded unrefunded payment blocks deletion;
  - refund accounting does not otherwise bypass status/age rules;
- deletion remains soft delete;
- historical payments/notifications remain intact.

Do not add automatic cleanup.

### 2. Successful-payment filter is still prototype-only

Now that `gp_payments` is real, the real admin Groups list must expose the SPEC filter for presence of a successful unrefunded payment.

Current view hides `successful_payment` in real mode and GroupIndexRequest/controller do not implement it.

Correction:

Add real filter:

- `successful_payment=yes`
- `successful_payment=no`

Definition for this list:

- “yes” = group has at least one payment with status `succeeded` and `refunded_at IS NULL`;
- “no” = no such payment.

Use `whereHas` / `whereDoesntHave` or equivalent SQL.

Requirements:

- no per-row payment queries;
- normal group list without this filter should preserve existing constant-query behavior;
- filter composes correctly with status/free/search/quick/sort/pagination;
- query string persists through pagination;
- do not treat `refunded` as successful-unrefunded.

### 3. Real awaiting_payment copy is stale and contradictory

Current real group summary renders:

`Историческая запись. Действия пока недоступны.`

for `awaiting_payment`, while the same screen exposes real payment actions.

That text belonged to the pre-WEBPAY state and is now false.

Correction:

Use truthful current-state wording, for example:

`Ожидается оплата размещения. Заполнение анкеты группы станет доступно после доверенного подтверждения WEBPAY.`

Requirements:

- owner real group show/list must not call awaiting_payment “historical”;
- payment CTA remains available;
- do not redesign the page;
- prototype variants may retain their intended demonstration semantics only if still accurate.

Where practical, from the real group detail link directly to the current/latest placement payment.

Do not introduce a psychologist payment-history section.

### 4. Staging prerequisites omit unsuccessful WEBPAY notifications

Official WEBPAY documentation currently states:

- standard notify is sent after the provider has a result;
- by default notifications are sent only for successful operations;
- receiving notifications for unsuccessful payments requires contacting WEBPAY technical support.

The local code safely supports trusted signed provider types:

- 2 -> failed;
- 8 -> failed;
- 7 -> cancelled/voided while still pending.

But because browser cancel is untrusted and standalone get_transaction cannot establish merchant-order binding, a merchant configured for success-only notify cannot safely transition a failed/cancelled unbound attempt to a terminal state. Such an attempt intentionally remains pending/manual review and retry stays blocked.

This is not a reason to weaken the trust boundary.

Correction to docs/staging checklist:

- explicitly state that if the product is expected to automatically obtain trusted `failed/cancelled` states and enable normal retry after unsuccessful card attempts, WEBPAY Sandbox/production account must be configured to deliver signed notifications for unsuccessful operations;
- instruct operator to request this from WEBPAY support and verify it during Sandbox acceptance;
- if unsuccessful notifications are not enabled/delivered, document the safe fallback:
  - browser cancel/failed page is not trusted;
  - standalone get_transaction cannot bind the attempt;
  - payment remains pending/manual review;
  - no new retry is allowed merely from browser outcome;
- do not invent a manual “mark failed/cancelled/succeeded” financial action;
- do not adopt a different provider API in this correction.

Official source to re-check before implementation:

`https://docs.webpay.by/paymentIntegration/cardIntegration/paymentNotification/`

At review time the documentation says unsuccessful payment notifications can be enabled by contacting `support@webpay.by`.

## Scope

### Admin group index

Update:

- request validation;
- controller query;
- real Blade filter;
- pagination/query persistence.

Implement successful-payment yes/no filter.

Update abandoned quick filter to include both awaiting_payment and draft older than the existing configured threshold.

### Admin group deletion

Update GroupPolicy/admin behavior so old abandoned:

- awaiting_payment;
- draft;

may be deleted by an authorized admin if no succeeded unrefunded payment blocks deletion.

Preserve psychologist deletion rules unchanged.

### Real owner group UI

Correct awaiting_payment wording.

Verify:

- real list;
- real detail;
- payment CTA/navigation;
- disabled state behavior.

No visual redesign.

### Documentation

Update at minimum:

- `docs/webpay.md`;
- `docs/deployment.md`;
- `docs/project-status.md` only if wording needs correction;
- `.ai/report.md`.

Explicitly add unsuccessful-notify provider prerequisite/fallback.

## Out Of Scope

Do NOT change:

- trusted-binding architecture;
- standalone get_transaction restriction;
- notify signature algorithm;
- payment form signing;
- payment state machine except where needed for the described admin filters/deletion;
- recovery schedule;
- extension semantics;
- refund API behavior;
- WEBPAY credentials;
- real Sandbox network calls;
- deployment;
- UI design;
- Stage 11/12 flows.

Do NOT implement:

- manual “mark paid”;
- manual “mark failed”;
- manual “mark cancelled”;
- automatic refund;
- alternative WEBPAY APIs;
- payment-history page for psychologists.

## Tests

Use MySQL.

Add/adjust focused coverage:

### Abandoned workflow

- admin quick abandoned includes old awaiting_payment;
- admin quick abandoned includes old draft;
- recent awaiting_payment excluded;
- recent draft excluded;
- unrelated statuses excluded;
- pagination works;
- admin can soft-delete eligible old awaiting_payment;
- admin can soft-delete eligible old draft;
- succeeded unrefunded payment blocks deletion;
- refunded payment does not by itself block deletion;
- psychologist deletion rules unchanged.

### Successful-payment filter

- yes includes group with succeeded + refunded_at null;
- yes excludes refunded;
- yes excludes pending/failed/cancelled;
- no is inverse for the relevant groups;
- composes with status/free/search;
- pagination keeps filter query;
- no N+1;
- normal list query count remains constant and does not add per-row payment queries.

### awaiting_payment real UI

- real owner list/show does not contain `Историческая запись`;
- truthful awaiting-payment text appears;
- payment CTA exists;
- group detail leads to the current placement payment;
- cross-owner access unchanged.

### WEBPAY staging docs

Automated textual/document check if project conventions support it, otherwise inspect manually:

- default success-only notify behavior documented;
- support request for unsuccessful notifications documented;
- safe pending/manual-review fallback documented;
- no weakening of signed binding/get_transaction rules.

### Regression

Run:

- WebpayTest;
- WebpayConcurrencyTest;
- GroupWorkflowTest;
- group admin/index/policy tests;
- full MySQL suite;
- Stage 11/12 focused regression;
- prototype 31/249;
- production isolation.

## Required Checks

Report exact results:

1. `docker compose ps`
2. non-destructive migrate/seed if needed (no new migration expected)
3. focused correction tests
4. existing WEBPAY focused/concurrency tests
5. full MySQL test suite
6. Pint
7. Larastan
8. composer check-platform-reqs
9. view:cache
10. route inspection
11. `git diff --check`
12. final staged/secrets/artifact review

## Acceptance Criteria

1. Abandoned quick filter includes both old awaiting_payment and old draft.
2. Recent awaiting_payment/draft are not classified abandoned.
3. Authorized admin can soft-delete eligible abandoned awaiting_payment.
4. Existing succeeded-unrefunded payment protection remains.
5. Psychologist deletion rules are unchanged.
6. Real admin group list exposes successful-payment yes/no filter.
7. Successful-payment filter treats only succeeded + unrefunded as yes.
8. Refunded/failed/cancelled/pending are not counted as successful-unrefunded.
9. Filters compose and paginate correctly.
10. No N+1/per-row payment lookup is introduced.
11. Real awaiting_payment UI no longer calls the record historical.
12. Real owner can navigate from awaiting_payment group to its current placement payment.
13. Current design baseline is preserved.
14. docs/webpay.md explicitly states WEBPAY default success-only notify behavior.
15. Staging checklist explicitly says to request/verify unsuccessful signed notifications when automatic failed/cancelled retry behavior is required.
16. Docs preserve fail-closed behavior when those notifications are unavailable.
17. Browser cancel remains untrusted.
18. Standalone get_transaction remains unable to bind local payment.
19. No manual financial-success/failure override is added.
20. Existing local WEBPAY focused/concurrency tests remain green.
21. Stage 11 API remains green.
22. Stage 12 mail/queue remains green.
23. Full MySQL suite passes.
24. Pint/Larastan/composer/view checks pass.
25. 31/249 prototypes and production isolation remain green.
26. No credentials/secrets/real financial data/unrelated artifacts are committed.

## Hard Workflow Gate

Before changing files:

- read WORKFLOW.md;
- read AGENTS.md;
- read this task;
- inspect GroupPolicy, admin GroupController/GroupIndexRequest, real group list/show/_actions, current WEBPAY docs;
- re-check official WEBPAY notification documentation;
- run git log/status;
- confirm base `094e94e1734707cdf9c607c989eaa03461da2893`;
- do not overwrite unknown changes.

During implementation:

- keep correction narrow;
- preserve payment trust architecture;
- no external provider calls required;
- no UI redesign;
- do not edit `.ai/task.md`, SPEC, WORKFLOW or AGENTS.

Before commit:

- run required checks;
- update `.ai/report.md`;
- inspect full diff/staged files;
- remove temporary artifacts.

If complete, commit with:

`codex: TASK-2026-09-21-13 complete webpay admin integration`

Do not create an accept commit.
