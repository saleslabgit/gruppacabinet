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
10 MiB technical ceiling. No email or password invitation is sent.

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
Both tariffs create draft groups without payments, with immutable UUID, owner
and tariff snapshot plus initial actor history. Owner-scoped lookups and policy
checks protect all routes. Owners save/submit draft or revision only; admins
edit content at any status and moderate with confirmed actions and required
revision/rejection comments. History retains every comment and actor.

Manual activation snapshots current configured duration, calculates UTC dates
from activation, resets the warning marker and records admin history. UUID
copy/reminder is available before activation. Owner draft/rejected deletion and
admin abandoned-draft deletion are soft deletes with historical payment safety.
Lists use eager loading, filters/search, deterministic sorting and pagination;
payment/application queries are excluded from normal listing. Applications are
unavailable; payment operations and extensions have no real routes or links.
Stage 8 adds only an informational admin payment-list route.

MySQL coverage includes both tariffs, history/atomicity, IDOR, immutability,
validation/dictionaries/integer money, activation, deletion and query counts.
Stage 8 adds dictionary/settings administration and an informational payment page.
Stages 9+ (lifecycle automation, applications, external integration, mail and
payments) remain pending.

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

## Intentionally not implemented

No psychologist profile editing or document mutations, payment CRUD,
public API, mail/jobs/scheduler behavior, group lifecycle automation, or WEBPAY
requests, signatures, credentials, callbacks, and payment effects are present.

## External prerequisites and unknowns

- Local execution requires a working Docker Engine and Compose plugin.
- Placement and extension prices remain intentionally unconfigured until the
  product values are supplied.
- Seeds still contain no dictionary item display values. Administrators can add
  local synthetic examples or approved values through the Stage 8 UI.
- Production hosting, queue-worker operation, SMTP, public-site integration,
  and WEBPAY remain unverified and belong to later stages.
