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

## Intentionally not implemented

No Stage 3+ UI prototypes, authentication flow, CRUD controllers, uploads,
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
