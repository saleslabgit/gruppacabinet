# Project status

## Stage 2 database and domain foundation

Stage 2 provides:

- the complete MySQL schema for users/documents, groups/status history,
  business audit, payments/notification journal, participant applications,
  dictionaries, settings, sessions, and database queues;
- MySQL generated-column enforcement of one active user per email;
- Eloquent models, casts, and the required domain relationships;
- explicit user, group, and payment status enums and transition services;
- atomic group status/history transitions, centralized compatibility `accept`
  derivation, and immutable generated group UUIDs;
- typed cached settings access with explicit cache invalidation and nullable
  unconfigured prices;
- an explicit audit-write service;
- idempotent seed data for settings, dictionary containers, and a local/testing
  administrator;
- MySQL integration coverage for schema constraints, indexes, transitions,
  relationships, settings, audit records, UUID behavior, and seed idempotency.

The exact verification results for this implementation iteration are recorded
in `.ai/report.md`.

## Stage 3 frontend prototypes

The final Blade view tree covers all 31 page groups with 249 direct catalog
variants, shared layouts/components, local Montserrat 500/600 with Cyrillic,
shared CSS tokens, responsive lists/forms, and confirmation dialogs.
The Stage 3 visual revision separates page/section/card typography, reduces
notice and surface scale, applies restrained form-control rounding, compacts
group lists and adapts admin navigation for tablet/mobile.
Development-only GET routes render synthetic data without business database
records. UUID copying is functional; business actions remain no-op. Payment
wording distinguishes an unknown browser-return outcome from trusted success.

The page and state index is `docs/ui-pages.md`. The visual revision is accepted as the baseline for backend integration.

## Stage 4 authentication and access

Session login/logout now use the approved Blade UI and database sessions.
Login has generic failures, email+IP throttling and session ID regeneration.
Protected psychologist/admin homes check current account eligibility on each
request and enforce opposite role boundaries. Disabled, non-approved and
soft-deleted accounts lose access; SessionInvalidator supports later bulk
session revocation and rotates remember tokens.

The psychologist home now renders real owned groups and creation (Stage 7). The admin home renders an unavailable-work-queue state without
fixture counts. Real navigation has CSRF-protected POST logout. The 31 groups /
249 prototype variants remain local/testing only. The old DB diagnostic moved
to local/testing-only `/_foundation`.

Idempotent local/testing seeding includes approved admin and psychologist
accounts. No new migrations or dependencies are required.

## Stage 5 administrator psychologist management

Active administrators manage psychologists at `/admin/psychologists` using the
accepted list, detail, form and document Blade views. Search, lifecycle/tariff
filters, 20-row pagination, nullable questionnaire fields and active education
options use real data. Existing inactive education selections are preserved.
Profile updates cannot write lifecycle/access/tariff/credential fields.

Confirmed approve/reject, enable/disable, tariff and soft-delete actions use
policies, row locks, the existing transition/audit/session services and database
transactions. Historical group tariff snapshots remain unchanged. Documents
are stored privately with random paths, content MIME validation and authorized
nested-owner view/download/delete endpoints. Uploads have a configurable
10 MiB technical ceiling. Stage 12 now adds queued first-password invitations after approval.

The same Blade files retain all 31 prototype groups / 249 variants. Real admin
navigation now also exposes Payments, Dictionaries and Settings (Stage 8). New MySQL tests cover CRUD,
protected fields, audit, session revocation, IDOR, file failures and constant
list query counts. No migrations or dependencies were added.

## Stage 6 psychologist cabinet profile

Approved enabled psychologists can open their real read-only questionnaire and
own document list at `/profile`. The accepted Blade pages now share real
Groups/Profile navigation and POST logout. The profile mapping is reused from
admin pages, and document actions are explicit capabilities of the shared table.

Owner-scoped lookup plus policy and existing account/role middleware protect
private view/download endpoints. Cross-owner document IDs return 404; admin
accounts cannot use owner routes. Both controller paths use the same secure
private streaming service. MySQL tests cover real and nullable questionnaire
data, IDOR, MIME/security headers, missing files, navigation, role boundaries
and session revocation. Stage 7 connects the root to owned groups and draft creation.

## Stage 7 groups, moderation and activation

Owner and admin group CRUD now use the accepted Blade list/form/detail pages.
Free groups start as drafts; the local WEBPAY stage now creates paid groups
as awaiting_payment with a payment attempt. UUID, owner and tariff snapshot
plus initial actor history remain protected. Owner-scoped lookups and policy
checks protect all routes. Owners save/submit draft or revision only; admins
edit content at any status and moderate with confirmed actions and required
revision/rejection comments. History retains every comment and actor.

Manual activation snapshots current configured duration, calculates UTC dates
from activation, resets the warning marker and records admin history. UUID
copy/reminder is available before activation. Owner draft/rejected deletion and
admin abandoned-draft deletion are soft deletes with historical payment safety.
Lists use eager loading, filters/search, deterministic sorting and pagination;
payment queries are excluded from normal listing. Stage 10 adds aggregate
application counters and real application links; payment operations have no real routes or links. Stage 9 connects free extension.
Stage 8 adds only an informational admin payment-list route.

MySQL coverage includes both tariffs, history/atomicity, IDOR, immutability,
validation/dictionaries/integer money, activation, deletion and query counts.
Stage 8 adds dictionary/settings administration and an informational payment page.
Stage 9 adds lifecycle automation/free extension. Stage 10 connects internal applications below; Stage 11 adds incoming integration. Stage 12 adds mail below;
payments remain pending.

## Stage 8 dictionaries, settings and payment information

Real admin routes reuse the approved dictionary/item/settings/payment-list views.
Containers and items have stable create-only codes, validated CRUD, pagination,
scoped ownership and confirmed deletion/deactivation. Core containers and all
historically used values are protected; soft-deleted references count. New and
reactivated items appear immediately in existing Stage 5/7 forms, and inactive
current selections remain visible.

The seven typed settings now have a transactional write boundary with minimal
actor/old/new audit and cache invalidation after commit. BYN prices use integer
minor units; nullable values round-trip. Placement duration affects later
activations only. The real Payments route is informational and performs no
payment table query, provider call or mutation. All 249 prototype variants remain.

## Stage 9 placement lifecycle and free extension

The every-minute `groups:expire` scheduler expires active placements under row
locks with an idempotent system history transition, including disabled groups.
Real owner/admin views show remaining days, configured warnings and extension
windows; the admin expired filter reminds about manual public-site unpublication.

Confirmed owner-only extension uses the current owner tariff. Free active
extension adds the stored duration and clears the warning marker; free expired
extension returns to approved within the current window without moderation.
Manual re-publication starts a new period using the current duration setting.
Paid extension is now connected through the local WEBPAY attempt flow below.
Free extension creates no payment; pending paid extension applies no dates.
Approved Blade/prototype structure remains.

## Stage 10 internal participant applications

Owners can list/filter/page/open applications of their own groups and mark them
processed/unprocessed. Scoped lookups and policy protect IDs; transactional row
locks preserve idempotency. Group list/detail show aggregate counters and real
links. Admin Applications navigation opens the global searchable read-only list
and details with group/psychologist links. Lists remain constant-query and use
the accepted Blade pages without changing prototype variants.

Reusable phone normalization requires explicit international input and never
invents a country code. Synthetic factories use reserved fictional phones.
Daily overlap-protected applications:cleanup permanently removes only records
strictly older than the current retention setting, including deleted parents;
output is aggregate-only. Stage 11 adds public intake below, with no email, payment or lifecycle
side effect. Verification evidence is in `.ai/report.md`.

## Stage 11 incoming integration

Two stateless public submission routes accept questionnaires and participant
applications: `/api/v1/psychologists` (direct questionnaire JSON in multipart
`payload`, optional `diploma`, `certificate_N`, `license`, `registration` files)
and `/api/v1/group-applications` (JSON), under the `/cabinet` deployment prefix.
No shared secret/HMAC, timestamp/signature headers or client file manifest is
required. `X-Request-Id` is a non-secret idempotency token, not authentication.
Cabinet derives file metadata/content hashes and enforces business validation.
Per-IP/endpoint rate limiting, optional IP allowlisting and safe errors/logging
remain; direct abuse/spam is not prevented by authenticated origin identity.
Do not introduce public-site secrets for these forms. Standard PHP parsing
cannot expose raw duplicate flat parts; see the integration guide's limitation.

The MySQL request journal coordinates replay and business mutation in one
transaction, including concurrent duplicates. Questionnaire repeats follow the
pending/rejected/approved/disabled/deleted matrix; existing document policy and
domain transitions are reused, with rollback cleanup for new files. Applications
resolve immutable group UUIDs and derive ownership internally. Existing Stage 5
and 10 pages/counters receive real incoming data without UI changes.

The public-site contract is `docs/integration.md`. Stage 11 itself adds no mail
or payment effects. Public-site implementation/deployment is external; Stage 12
adds the email flows described below.

## Stage 12 email and onboarding

Stage 12 is complete and verified locally; verification details are in `.ai/report.md`.

Queued first-password invitations follow committed admin approval. Admin resend
replaces the broker token and invalidates older queued invitations. The real
password Blade page validates current eligibility and typed TTL, consumes tokens,
hashes passwords and supports normal login afterward. No public reset request or
password replacement is provided.

Hourly expiry warnings use database jobs and a shared unique lock for each exact
placement period. Jobs recheck current state, then mark only after successful
SMTP or sendmail transport acceptance. Lifecycle expiration stays independent.
Local Mailpit and a dedicated worker provide reproducible SMTP/queue verification. Production prerequisites,
TTL semantics and delivery limits are in `docs/email.md`.

Local WEBPAY implementation follows below. Real Sandbox acceptance remains a
separate external stage; no payment behavior was introduced by Stage 12 itself.

## Intentionally not implemented

Psychologist profile editing/document mutations and automatic WEBPAY refunds
remain out of scope. Real Sandbox/production acceptance and deployment have not
been performed.

## External prerequisites and unknowns

- Local execution requires a working Docker Engine and Compose plugin.
- Placement and extension prices remain intentionally unconfigured until the
  product values are supplied.
- Seeds still contain no dictionary item display values. Administrators can add
  local synthetic examples or approved values through the Stage 8 UI.
- Production hosting, queue-worker operation, SMTP, public-site integration,
  and WEBPAY remain unverified and belong to later stages.


## Local WEBPAY implementation (Stages 13–14 and staging preparation)

Implemented paid placement, current-tariff paid extension, v2 forms, stateless
signed notify, strict XML API adapter, central idempotent confirmation, finite
trusted-bound recovery, real admin payment list/detail and manual refund
accounting. The existing Blade pages and lifecycle transitions are reused.
Tests cover synthetic provider contracts and real MySQL confirmation races;
see `.ai/report.md` for actual verification results.

The corrected task accepts that completely lost notify cannot be automatically
confirmed from standalone get_transaction: no signed merchant order is present
there. Unbound attempts remain pending/manual review. No manual “mark succeeded”
action or alternative undocumented provider protocol has been introduced.

Staging prerequisites and Sandbox/production checklists are in webpay.md and
deployment.md. Actual prices/credentials/public HTTPS delivery, real payment,
real get_transaction and manual Sandbox refund are NOT VERIFIED locally.

## WEBPAY admin corrections and shared-hosting baseline

Admin abandoned filtering/deletion includes old awaiting_payment and draft groups,
with successful-unrefunded payment protection preserved. The real successful
payment filter composes with other group filters. Awaiting-payment copy describes
the current payment flow and retains the placement-payment detail link.

Database cache/locks, a read-only deployment preflight (temporary technical cache
probes only), finite cron-worker verification and /cabinet deployment instructions
prepare the shared-hosting baseline. Production artifacts include locally built
vendor dependencies; server Composer and a permanent worker are optional. The
local PHP image includes pcntl for worker timeouts. Actual HostER account limits,
web PHP, cron, SMTP, HTTPS and Sandbox acceptance remain unverified external gates.
See deployment.md and webpay.md, especially success-only notify defaults and the
support request for unsuccessful signed notifications. No trust rule is relaxed.

## Shared-hosting mail portability

Both business mail jobs support SMTP and explicitly configured local sendmail,
with safe started/accepted_by_transport/failed diagnostics and post-commit
password-invitation queued diagnostics. Unsupported transports remain blocked.
Preflight checks sendmail executable capability without running it; local
SMTP/Mailpit and database queue/retry/uniqueness semantics remain unchanged.
HostER PCNTL and local MTA acceptance are reported by the task; downstream relay,
inbox delivery, DNS/reputation and available MTA logs remain external unknowns.
Transport acceptance does not establish inbox delivery. See email.md and
deployment.md for timeout limits and manual staging verification.
