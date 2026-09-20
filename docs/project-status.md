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
fixed CSS tokens, responsive lists/forms, and confirmation dialogs.
Development-only GET routes render synthetic data without business database
records. UUID copying is functional; business actions remain no-op. Payment
wording distinguishes an unknown browser-return outcome from trusted success.

The page and state index is `docs/ui-pages.md`. The implementation is awaiting
Stage 3 acceptance; exact automated and browser verification results are in
`.ai/report.md`. Stages 4 and later are not started.

## Intentionally not implemented

No authentication flow, CRUD controllers, uploads,
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
