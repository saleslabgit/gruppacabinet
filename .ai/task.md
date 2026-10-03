# Task: TASK-2026-10-03-01

Status: planned
Created from: 5a981b77b13c7b3d5d718b4af05d697f34c1da40 (main)

## Title

Post-manual-testing cabinet UX, notifications, application retention and publication lifecycle improvements

## Goal

Implement the accepted improvement batch found during manual production testing.

This is one product acceptance milestone covering:

- psychologist feedback to the administrator through Telegram;
- important administrator Telegram notifications;
- important psychologist email notifications for group moderation outcomes;
- removal of provider-specific WEBPAY wording from all user-facing UI;
- targeted psychologist/admin UI corrections;
- psychologist-side application hiding while retaining admin history;
- group/application visibility consistency;
- admin group-detail application visibility;
- direct submit-to-moderation from the group detail;
- production HTTPS URL generation audit/hardening;
- mobile admin menu moved into the header;
- admin withdrawal/restoration of a published active group;
- automatic publication after renewal of an expired group.

Do not implement deletion of unconfirmed payments in this task.

## Authoritative Product Decisions

The following decisions were confirmed by the product owner.

### Feedback

Psychologist feedback is **text only**.

No screenshots or attachments.

The message must arrive in the configured administrator Telegram chat and clearly
identify which psychologist sent it.

### Psychologist moderation email

Send a queued email for **all three** administrator moderation outcomes:

- approved;
- revision requested;
- rejected.

### Admin withdrawal of an active group

Accepted behavior:

- administrator can remove an active published group from placement;
- local lifecycle status remains active;
- group becomes disabled locally;
- MODX Resource is asynchronously unpublished;
- placement end date continues unchanged;
- psychologist cannot undo the administrator withdrawal;
- administrator can restore placement before expiry;
- restoration republishes the same MODX Resource;
- the group must not accept applications while admin-withdrawn.

### Expired renewal publication

Accepted behavior:

- when an expired group is successfully renewed, Cabinet automatically republishes
  the same MODX Resource;
- a new placement period begins only after MODX confirms publication;
- until remote publication succeeds the group must not become active and the new
  placement clock must not run.

Initial publication after first moderation remains manual.

### Payment deletion

**Out of scope.**

Do not add deletion of created/pending/failed/cancelled payments in this task.

## Facts / Current State

- Current main HEAD is
  `5a981b77b13c7b3d5d718b4af05d697f34c1da40`.
- Current group publication lifecycle uses:
  - `modx_publication_revision`;
  - desired/status fields;
  - `GroupModxPublicationScheduler`;
  - `SetGroupModxPublication`;
  - shared `modx-group:<id>` overlap lock;
  - stale revision protection.
- Initial approved publication is manual.
- Paused resume is automatic and already protected against expiry races.
- Expired renewal currently performs:
  `expired -> approved -> manual MODX publication -> admin activate -> active`.
- Psychologist group deletion already sets `psychologist_deleted_at`, does not
  soft-delete, preserves status/history/payments and remains visible to admin.
- Participant applications currently have no psychologist-hide timestamp.
- Application cleanup permanently deletes old applications after retention.
- Group application intake only accepts active, non-disabled, non-hidden groups.
- Payment status `failed/cancelled` is trusted only after provider-side confirmation;
  browser return/cancel alone must not be treated as a financial result.
- Psychologist group form currently submits edited data and moderation in one POST.
- Current group detail has no standalone submit-stored-data moderation action.
- Current admin group detail does not show participant applications.
- Current psychologist group list shows:
  - `Группа № <id>`;
  - `Формат` through shared group summary.
- Psychologist profile currently renders the shared
  `Подтверждения и согласие` panel.
- Admin and psychologist group views share `group-summary` and `group-data`.
- Current admin mobile navigation toggle is rendered in the application shell
  outside the top header.
- Psychologist navigation is already header-based.
- Production canonical URL is:
  `https://gruppa.info/cabinet`.
- Do not read, modify, delete or commit production `.env_save`.

## Scope

# A. Telegram infrastructure

## A1. Configuration

Add repository-safe configuration only.

Use environment variables such as:

- `TELEGRAM_BOT_TOKEN`;
- `TELEGRAM_ADMIN_CHAT_ID`.

Add empty/documented placeholders to `.env.example`.

Do not commit a real token/chat ID.

Use Telegram Bot API over HTTPS.

Do not add a package: use existing Laravel HTTP facilities.

Validate configuration before sending.

The bot token must never appear in:

- logs;
- exception messages;
- audit metadata;
- queue diagnostics;
- rendered UI.

Do not log the full Telegram request URL because the bot token is part of that URL.

Transport failures must be converted to safe application exceptions/diagnostics
without chaining raw HTTP exceptions containing the URL/token.

## A2. Queue delivery

Telegram delivery must use the existing database queue.

Business actions must commit independently of Telegram availability:

- public psychologist intake still succeeds if Telegram is unavailable;
- group submission to moderation still succeeds if Telegram is unavailable.

A queue insertion failure after the business commit may be safely logged using
only entity IDs/event codes.

Use bounded HTTP connect/request timeouts and queued retry/backoff consistent with
the existing mail/integration style.

Tests must use HTTP fakes and prevent stray requests.

# B. Psychologist feedback to Telegram

Add an authenticated psychologist-only Cabinet feedback flow.

Recommended route shape:

- GET `/feedback`;
- POST `/feedback`.

Add a navigation entry such as:

`Сообщить об ошибке`.

Use the existing psychologist layout/components.

Feedback is text only.

Validation:

- required;
- trimmed string;
- sensible Telegram-safe maximum, no more than approximately 3000 characters.

No uploads.

The Telegram message must clearly contain:

- event label indicating psychologist feedback;
- psychologist internal ID;
- psychologist name;
- psychologist email;
- feedback text;
- safe HTTPS link to that psychologist in admin Cabinet.

Do not include questionnaire/document contents.

Do not use Telegram parse mode for user text unless escaping is complete; plain
text is preferred.

Add a per-user authenticated rate limit to prevent accidental spam.

The success UI should truthfully say the message was accepted/queued, not promise
Telegram/inbox delivery.

If queue insertion itself fails, show a safe retryable UI error.

Do not persist a new feedback table unless technically necessary; Telegram
delivery is the requested destination.

Add prototype/catalogue coverage for normal, validation, success and delivery/
queue error state if the existing prototype architecture requires a new page.

# C. Telegram notifications requiring admin action

Send administrator Telegram notifications for these two events only in this task.

## C1. Psychologist awaiting approval

When public psychologist intake creates a new pending psychologist, or a rejected
psychologist successfully resubmits and returns to pending, queue one Telegram
notification after the successful business transaction commits.

Do not notify on idempotent replay of the same integration request.

Message should contain only necessary operational information:

- event: psychologist requires approval;
- psychologist ID;
- name;
- email;
- HTTPS admin psychologist link.

Do not send full questionnaire fields or document metadata.

Admin-created psychologists do not need this Telegram notification.

## C2. Group awaiting moderation

Whenever a psychologist successfully sends a group to moderation:

- `draft -> moderation`;
- `revision -> moderation`;

queue one Telegram notification after commit.

This applies both to:

- the existing edit+submit path;
- the new submit-stored-data action from the group card/detail.

Message:

- event: group requires moderation;
- group internal ID;
- group title;
- psychologist name/email;
- HTTPS admin group link.

Do not notify for admin-created/moderated changes that do not represent an owner
submission.

# D. Psychologist email after group moderation decision

Add queued psychologist email for administrator moderation outcomes:

- `moderation -> approved`;
- `moderation -> revision`;
- `moderation -> rejected`.

Use database queue and the existing supported production mail transports
SMTP/sendmail.

Do not make moderation transaction success depend on SMTP availability.

Mail must be dispatched only after the moderation transaction commits.

Content:

### approved

- group title;
- clear statement that moderation passed;
- initial group still waits for publication;
- Cabinet group link.

### revision

- group title;
- clear statement that revision is required;
- moderator comment;
- Cabinet group/edit link.

### rejected

- group title;
- clear statement that group was rejected;
- rejection reason;
- Cabinet group link.

No password/token/payment secrets.

Safe mail diagnostics only: entity ID/event/transport/attempt; no message body.

Retries must not re-run the moderation transition.

Existing first-password/access email behavior remains unchanged.

# E. Remove WEBPAY wording from all user-facing UI

The payment provider implementation remains WEBPAY internally.

Do not rename classes/config/env/log codes merely for presentation.

But **no rendered psychologist/admin/prototype interface should display the word
`WEBPAY`**.

Replace user-facing wording with neutral/card wording, for example:

- `Оплата картой`;
- `Оплатить картой`;
- `Повторить оплату`;
- `платёжный сервис`;
- `платёж подтверждается`;
- `возврат в платёжном сервисе`.

This includes, where rendered:

- psychologist payment start/return pages;
- group/payment notices;
- admin payment details;
- refund confirmations;
- prototype copy;
- settings/admin group warnings.

Internal docs may still name WEBPAY when describing integration architecture.

Add rendered-view regression proving provider brand is absent from relevant UI.

# F. Failed/cancelled payment result UX

Preserve payment trust boundaries.

Do **not** treat browser return/cancel as proof of failure.

Only when the stored trusted payment status is terminal:

- `failed`;
- `cancelled`;

show terminal result UI.

For `failed`:

- clear `Оплата не прошла`;
- no “ожидается подтверждение” language;
- no refresh/continue-current-attempt actions;
- primary available payment action is `Повторить оплату`.

For `cancelled`:

- clear cancelled wording;
- no pending wording;
- allow `Повторить оплату`.

Pending/manual-review semantics remain unchanged.

Do not weaken signed notify/API confirmation rules.

# G. Psychologist group UI corrections

## G1. Group list preview

On psychologist group list only:

- remove visible `Группа № <id>`;
- do not show the `Формат` row in the preview summary.

Do not remove format from:

- group detail;
- edit form;
- admin UI;
- MODX payload.

Do not remove internal IDs from URLs/database.

## G2. Profile consent block

Remove the complete `Подтверждения и согласие` visual panel from the
psychologist's own profile page.

Keep the underlying data.

Keep the panel visible in admin psychologist detail.

Do not remove validation/intake fields.

## G3. Moderation waiting copy

Remove:

`Администратор проверяет группу. Дождитесь решения.`

from admin group interface.

Psychologist may retain a truthful moderation status explanation.

Because the summary is shared, make rendering context-aware rather than removing
useful owner copy globally.

## G4. Applications above description

On psychologist group detail, display the participant applications block above
the short/full description content.

Do not hide the group status/actions to achieve this.

Preserve counters/latest application/link behavior.

## G5. Direct submit-to-moderation action

Psychologist must be able to submit already-saved group data to moderation from
the group detail without entering edit mode.

Keep the existing edit-form `save + submit` path.

Add a separate direct action for stored content rather than reusing a POST that
would ignore unsaved form input.

Direct action is available for:

- draft;
- revision;

when not disabled/hidden.

It must:

1. row-lock current group;
2. authorize owner submit;
3. validate the **stored** group as complete using the same effective readiness
   requirements as normal moderation submission;
4. transition:
   - draft -> moderation;
   - revision -> moderation;
5. record normal status history with user actor;
6. queue the admin Telegram moderation notification after commit.

If stored content is incomplete, do not transition. Return useful validation
errors with an edit action.

Do not duplicate/skip existing MODX readiness rules.

## G6. Status history position

`История статусов и замечаний` must be the **last visible content panel** on
group pages where it is rendered.

Apply consistently to psychologist/admin group details and revision/edit page if
history is shown there.

Confirmation modals may exist after it in DOM; the requirement concerns visible
content panels.

# H. Participant application hide/delete semantics

Add psychologist-side deletion/hiding of participant applications.

## H1. Schema

Add an additive nullable timestamp to `gp_group_applications`, e.g.:

`psychologist_deleted_at`.

Add only indexes justified by real queries.

No destructive migration.

## H2. Psychologist behavior

Psychologist can delete/hide only an application belonging to their visible own
group.

Delete action:

- row-lock group and application;
- authorize owner;
- set `psychologist_deleted_at`;
- do not physically delete;
- do not soft-delete;
- do not change participant data;
- no restore UI in this task.

After hide:

- psychologist list/detail routes return 404 for that application;
- psychologist counters exclude it;
- psychologist latest-application preview excludes it;
- processing actions cannot mutate it.

Add delete actions on psychologist application list/detail with confirmation.

## H3. Admin behavior

Admin continues to see psychologist-hidden applications in:

- global application list;
- application detail;
- group-detail application panel.

Display a clear marker:

`Психолог удалил заявку`

or equivalent.

Admin filters/counts should not silently hide these records.

Retention cleanup still permanently deletes records when the configured
retention period expires, including psychologist-hidden applications.

## H4. Group-delete regression

Do not redesign existing group delete semantics.

Add/retain regression proving:

- psychologist delete sets group `psychologist_deleted_at`;
- status remains unchanged;
- a rejected group remains `rejected`;
- owner routes hide it;
- admin still sees it;
- history/payment records remain;
- synced resource unpublish continues;
- admin can see that psychologist hid/deleted the group.

Expose a clear admin marker such as:

`Психолог удалил группу`

for psychologist-hidden groups.

# I. Applications inside admin group detail

Admin group detail must show participant applications, not only link through the
global application section.

Add a dedicated `Заявки участников` panel before status history.

At minimum show:

- counters;
- latest/recent applications (reasonable bounded set, e.g. 5);
- name;
- phone;
- created date;
- processed/new state;
- psychologist-deleted marker;
- link to application detail;
- link to all applications for this group.

Add an explicit `group_id` filter to admin application index or an equivalent
safe group-scoped link, rather than relying on title search.

Admin panel includes hidden applications.

Avoid N+1 queries and keep detail query count bounded.

# J. Production HTTPS URL hardening

Audit generated application links so production Cabinet outputs HTTPS URLs.

Do not reopen or rewrite the already accepted web-server redirect work unless
code inspection proves it is necessary.

Application-level requirements:

- production `APP_URL` remains `https://gruppa.info/cabinet`;
- in production, Laravel route/asset URL generation must not emit `http://gruppa.info`;
- queue-generated links in email/Telegram must be HTTPS;
- payment return/cancel/notify URLs must be HTTPS;
- MODX/API URLs remain HTTPS where required.

Use a production-safe URL generation mechanism, e.g. forcing HTTPS scheme in
production, while keeping local/testing HTTP development usable.

Deployment/preflight must detect an invalid production APP_URL scheme.

Do not hardcode production origin into every Blade file.

Add tests that render representative psychologist/admin pages and queued
notification/payment URLs under production configuration and assert no insecure
Cabinet links.

# K. Mobile navigation in the header

Psychologist navigation is already header-based.

Correct the admin responsive behavior so the mobile/tablet menu control is in the
top header rather than appearing as a separate application-shell block below the
header.

Requirements:

- desktop keeps the accepted sidebar;
- responsive menu uses the same admin navigation items, no duplicate business
  navigation model;
- at mobile width the menu toggle is visually in the header;
- menu remains keyboard accessible;
- Escape/focus behavior from existing progressive JS must be preserved or
  equivalently implemented;
- logout remains available;
- without JS, navigation remains usable;
- no broad redesign.

Update approved prototype documentation/tests and perform browser/mobile smoke if
a browser is available.

# L. Admin withdraw / restore active group placement

Add administrator actions on an active synchronized group.

Suggested UI labels:

- `Снять с размещения`;
- `Вернуть в размещение`.

## L1. Withdraw

Eligibility:

- admin;
- group is visible to admin;
- status = active;
- not psychologist-hidden/soft-deleted;
- valid MODX Resource ID.

On confirmed withdraw:

1. row-lock group;
2. set `disabled=true` immediately;
3. keep status = active;
4. do not change `published_at`, `expires_at`, `placement_days`;
5. schedule a newer desired `unpublished` publication revision after commit.

Local disabled state is authoritative for intake/owner actions even if MODX
unpublish fails.

Placement time continues.

Expiry scheduler must still be able to expire the disabled active group at the
original deadline.

Psychologist sees that the group is disabled by administrator and cannot
pause/extend/accept applications.

## L2. Restore

Eligibility:

- admin;
- status = active;
- `disabled=true`;
- valid Resource ID;
- `expires_at > now`;
- group not hidden/deleted.

Restore must publish the same Resource.

For safe UX, keep the group locally disabled until the current remote publish
revision succeeds.

The publication job must support an admin-restore mode:

- active + disabled + future expiry;
- before HTTP recheck current revision/identity/deadline;
- after successful HTTP recheck under row lock;
- clear `disabled` only on current successful publish;
- do not change status/dates.

If expiry is reached before/during publish:

- do not re-enable;
- expire normally;
- ensure newer desired unpublished wins;
- same stale revision protections as paused resume.

Publication failure leaves the group disabled.

Provide safe retry through the same admin restore action/current revision rules.

Do not let psychologist perform this admin restore.

# M. Automatic publication after expired renewal

Replace the manual expired-renewal publication step for synchronized groups.

This applies to both:

- free expired renewal;
- successfully confirmed paid expired renewal.

## M1. Renewal transition

Preserve the existing domain/payment trust rules up to successful renewal.

Expired renewal still transitions:

`expired -> approved`

to represent “renewal accepted, publication pending”.

Do not immediately set new placement dates.

If the group has a valid MODX Resource ID, schedule desired `published` after
commit.

No remote HTTP inside the free-extension transaction or payment-confirmation
transaction.

If no Resource ID exists, do not fake publication. Leave approved and expose a
truthful admin/manual recovery state.

## M2. Publication job completion mode

Extend the existing revision-protected publication job to distinguish a legitimate
expired-renewal publication from initial approval.

A published request for status `approved` is allowed to auto-activate only when
it is clearly an expired-renewal flow, e.g. the current status history proves:

`expired -> approved`.

Initial moderation:

`moderation -> approved`

must **never** auto-publish.

Before remote publish, recheck:

- current revision/desired state/resource identity;
- approved renewal identity;
- not hidden/deleted/disabled;
- renewal is still current.

After successful publish, under row lock:

1. recheck current revision/state;
2. set a new placement start timestamp;
3. set `placement_days` using the same duration semantics that the previous
   manual reactivation would have used;
4. set `expires_at = new published_at + placement_days`;
5. clear `expiry_warning_sent_at`;
6. transition `approved -> active` as system;
7. atomically mark publication state published.

The new placement clock starts only here.

If the publication fails, group remains approved and no new placement dates run.

Provide an admin-visible safe retry for failed publication.

## M3. Paid renewal

In `ConfirmPayment` expired extension success:

- preserve signed/provider trust and idempotency;
- preserve successful payment/product_effect accounting;
- transition expired -> approved as today;
- schedule publication only after the DB commit;
- a MODX failure must not roll back or rewrite the successful payment.

Do not call MODX from the payment notify transaction.

## M4. Free renewal

After a valid free expired extension transitions to approved, schedule the same
publication flow after commit.

## M5. Race/stale requirements

Publication revision rules remain authoritative.

Cover:

- expired renewal publish success;
- duplicate job/retry;
- newer unpublish intent wins;
- psychologist group delete during publish;
- admin delete during publish;
- expiry/date/state mutation before completion where applicable;
- no stale publish result can activate an invalid/hidden/deleted group.

# N. Documentation

Update implemented-state docs, at least:

- `SPEC.md`;
- `docs/project-status.md`;
- `docs/ui-pages.md`;
- `docs/architecture.md`;
- `docs/development.md`;
- `docs/deployment.md`;
- `docs/email.md` where mail behavior changes;
- add a concise Telegram operations section/file if clearer.

Document:

- Telegram env/config and safe operational diagnostics;
- feedback flow;
- admin Telegram events;
- group moderation emails;
- UI provider-neutral payment terminology;
- psychologist application hide/admin retention;
- admin group applications;
- production HTTPS generation;
- mobile header navigation;
- admin withdraw/restore semantics;
- automatic expired-renewal publication;
- initial publication remains manual.

Do not rewrite historical stage text unless it is presented as current behavior.

## Migrations

Expected additive migration:

- nullable `psychologist_deleted_at` on `gp_group_applications`.

Do not add unrelated schema.

Do not run `migrate:fresh`.

Migration must preserve existing applications.

Rollback must remove only fields/indexes introduced by this task.

## Tests

Add focused tests for each product boundary.

### Telegram

- feedback validation/auth/rate limit;
- psychologist identity + text in outgoing Telegram request;
- no attachments;
- public intake pending event after commit;
- rejected resubmission pending event;
- integration replay does not duplicate Telegram notification;
- draft/revision group submit queues moderation Telegram event;
- queue/Telegram failure does not roll back intake/group transition;
- exact HTTPS Telegram endpoint;
- no bot token in logs/exceptions;
- no real HTTP.

### Moderation mail

- approved/revision/rejected each queue exactly one mail after commit;
- correct owner/group/result/comment/reason;
- rollback queues nothing;
- mail failure does not revert moderation;
- safe diagnostics;
- no real mail.

### Payment UI

- no rendered user/admin/prototype UI contains `WEBPAY`;
- failed/cancelled have terminal wording and retry;
- failed/cancelled do not render pending refresh/continue language;
- pending trust semantics remain.

### Psychologist/admin UI

- group list hides internal group number and format preview only;
- group detail still shows format;
- psychologist profile omits consent panel;
- admin psychologist detail still shows it;
- admin moderation view omits the specified waiting sentence;
- psychologist moderation view remains truthful;
- applications precede descriptions;
- status history is last visible group panel;
- direct stored-data submit works/incomplete fails without transition.

### Application deletion

- migration upgrade/rollback preserves rows;
- psychologist hide only own application;
- hidden application 404 for psychologist;
- process/unprocess blocked after hide;
- psychologist counters/latest/list exclude hidden;
- admin global list/detail include hidden + marker;
- admin group detail includes hidden + marker;
- retention cleanup still deletes old hidden rows.

### Group deletion regression

- psychologist delete of rejected group keeps status rejected;
- admin still sees row/history/payments;
- owner does not;
- admin marker visible;
- remote unpublish behavior preserved.

### Admin group applications

- counters/recent records and links;
- `group_id` filter;
- hidden applications included;
- bounded query count.

### HTTPS

Under production configuration:

- representative route()/asset() links are HTTPS;
- password/group moderation mail URLs HTTPS;
- Telegram admin links HTTPS;
- payment return/cancel/notify HTTPS;
- no `http://gruppa.info/cabinet` in rendered representative pages;
- local/testing HTTP remains supported;
- deployment preflight catches invalid production APP_URL.

### Mobile navigation

- admin menu toggle/nav rendered in header responsive structure;
- desktop sidebar retained;
- no duplicate/incorrect links;
- progressive no-JS navigation preserved;
- browser smoke at representative mobile width if browser tooling is available.

### Admin withdraw/restore

- authorization and confirmed actions;
- withdraw active -> active + disabled, dates unchanged;
- unpublish after commit;
- intake/owner actions blocked;
- expiry still processes disabled group;
- restore before expiry publishes same ID;
- stays disabled until remote success;
- success clears disabled, status/dates unchanged;
- failure stays disabled;
- due-before/during publish expires + newer unpublish;
- stale revisions cannot re-enable.

### Expired renewal auto-publication

For both free and paid:

- expired -> approved;
- publication queued after commit only;
- no new published_at/expires_at before remote success;
- same Resource ID published;
- remote success sets fresh dates and approved -> active;
- initial moderation approval never auto-publishes;
- missing Resource ID remains truthful approved/manual recovery;
- queue/MODX failure does not undo free renewal or successful payment;
- paid product_effect/idempotency preserved;
- stale/delete races cannot activate invalid group.

## Regression Checks

Run and report exact results for:

1. focused Telegram/feedback tests;
2. focused moderation mail tests;
3. GroupWorkflowTest;
4. GroupLifecycleTest + concurrency;
5. GroupPublicationTest;
6. ExpiryWarningTest;
7. IntegrationIntakeTest + integration concurrency;
8. application feature/policy/retention tests;
9. payment WEBPAY/PaymentEra/WebpayConcurrency regression suites;
10. PrototypeTest;
11. authentication/password recovery/setup regressions;
12. DeploymentPreflight/SharedHostingRuntime;
13. full MySQL test suite;
14. Pint;
15. PHPStan;
16. composer check-platform-reqs;
17. composer validate --no-check-publish;
18. artisan view:cache;
19. artisan route:list;
20. artisan schedule:list;
21. `node --check application/public/ui.js` if Node is available;
22. `git diff --check`;
23. final status/diff/staged secret/artifact review.

No real Telegram, SMTP/sendmail, MODX or WEBPAY requests in automated verification.

## Out Of Scope

Do NOT:

- implement payment deletion;
- change trusted WEBPAY financial confirmation rules;
- treat browser return/cancel as trusted failure;
- rename WEBPAY integration classes/env/config/log codes just to change UI wording;
- add feedback screenshots/files;
- send additional noisy Telegram event categories beyond:
  - psychologist pending approval;
  - group pending moderation;
  - explicit psychologist feedback;
- auto-publish initial moderation approval;
- delete remote MODX Resources;
- auto-refund payments;
- change group placement duration business values;
- restore psychologist-hidden applications/groups;
- add packages/Node/Vite;
- read/change/commit `.env_save`;
- run `migrate:fresh`;
- create an `accept:` commit.

## Acceptance Criteria

1. Psychologist can send text feedback from Cabinet and admin receives a queued
   Telegram message identifying the psychologist.
2. Admin Telegram receives actionable notification for new/resubmitted pending
   psychologist and owner-submitted moderation group, without replay duplicates.
3. Psychologist receives queued email for approved/revision/rejected group
   moderation decisions.
4. User-facing UI no longer displays `WEBPAY`; card-payment terminology is used.
5. Trusted failed/cancelled payment page is terminal and offers retry without
   pending wording.
6. Psychologist group preview hides internal number and format only; detail/data
   remain intact.
7. Psychologist profile omits consent panel while admin retains it.
8. Group-detail applications are above description; history is last visible panel.
9. Psychologist can submit saved draft/revision directly to moderation.
10. Psychologist can hide an application; admin retains it with deletion marker,
    and owner counters/routes exclude it.
11. Existing psychologist group deletion remains history-preserving; rejected
    remains rejected and admin sees a deletion marker.
12. Admin group detail includes group applications and a real group filter/link.
13. Production Cabinet-generated links are HTTPS; local development remains usable.
14. Admin responsive navigation menu is in the header; desktop sidebar remains.
15. Admin can withdraw/restore an active published group with revision-safe MODX
    publication and unchanged placement deadline.
16. Expired renewal automatically republishes the same Resource; the new placement
    clock starts only after remote success.
17. Initial approved publication remains manual.
18. Payment deletion is not introduced.
19. Full regression/static checks pass with no real external calls or secrets.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and parent is
  `5a981b77b13c7b3d5d718b4af05d697f34c1da40`;
- read WORKFLOW.md, AGENTS.md, this task and current report;
- inspect all affected approved Blade/CSS/JS and `docs/ui-pages.md`;
- inspect current Telegram absence/config, mail jobs, intake transaction,
  group workflow/publication jobs, applications, payments and deployment URL logic;
- verify clean/known local tree;
- do not touch unknown local changes.

During implementation:

- work only within this task;
- do not edit `.ai/task.md`;
- preserve external trust/idempotency boundaries;
- all external notification/publication work must be async/after-commit where
  required;
- never log secrets/raw sensitive payloads;
- keep initial publication manual;
- do not add payment deletion;
- preserve revision/stale publication protections;
- avoid unrelated refactors/redesign.

Before commit:

- run all required checks;
- inspect complete diff/staged files;
- verify no bot token/chat ID, production credentials, private env, user production
  data, document uploads, queue payload dumps, mail captures, logs/cache/vendor or
  temporary artifacts are staged;
- update `.ai/report.md` factually;
- explicitly state no real Telegram/mail/MODX/WEBPAY requests were made.

If complete, commit with:

`codex: TASK-2026-10-03-01 post-manual-testing improvements`

Do not create an accept commit.
