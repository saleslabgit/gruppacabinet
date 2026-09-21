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

The psychologist home renders the existing empty groups view with creation
unavailable. The admin home renders an unavailable-work-queue state without
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
navigation offers Home, Psychologists and Logout. New MySQL tests cover CRUD,
protected fields, audit, session revocation, IDOR, file failures and constant
list query counts. No migrations or dependencies were added.

## Intentionally not implemented

No psychologist self-service, group/payment/dictionary/settings CRUD,
public API, mail/jobs/scheduler behavior, group lifecycle automation, or WEBPAY
requests, signatures, credentials, callbacks, and payment effects are present.

## External prerequisites and unknowns

- Local execution requires a working Docker Engine and Compose plugin.
- Placement and extension prices remain intentionally unconfigured until the
  product values are supplied.
- Dictionary item display values remain empty until approved values are
  supplied.
- Production hosting, queue-worker operation, SMTP, public-site integration,
  and WEBPAY remain unverified and belong to later stages.
