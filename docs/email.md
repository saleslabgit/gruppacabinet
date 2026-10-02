# Email, password setup and recovery

Approved, enabled, non-deleted, non-admin psychologists may set or replace their
password regardless of whether one is already configured. Login links to the public
GET/POST `/password/forgot` recovery form. Unknown/ineligible accounts and queue
failures receive the same generic success page; only eligible accounts get a job.
Email normalization matches login (trim and lowercase). Malformed input gets syntax
validation only. POST limits are five/minute/IP and one/minute/normalized email
(SHA-256 key), applied equally to existing and unknown accounts. The rate-limit
page returns 429 with retry headers; diagnostics never include plaintext email.
For valid input, eligible accounts incur the real broker token hash; unknown or
ineligible accounts hash a fresh dummy value through the same configured framework
hasher, with no per-call cost override. The dummy hash is discarded without a
token row or queued job. This removes the cryptographic-work differential, not
all variation from database/queue work or infrastructure failures.

## Broker and setup flow

`PasswordSetupService::broker()` constructs a fresh Laravel `PasswordBroker`
and `DatabaseTokenRepository` for every operation, using the existing Eloquent
user provider and `password_reset_tokens`. Framework code generates, hashes,
checks and deletes tokens. The repository takes seconds: current typed
`password_setup_link_ttl_hours * 3600`. No broker/TTL is retained in a worker.
Settings use the existing shared cache and its after-commit invalidation.

Validity uses the current setting, including for previously issued links.
Laravel 12's `isPast()` boundary accepts the exact expiry instant and rejects
any instant after it; timestamps are stored with second precision. Increasing
TTL can make an unconsumed older token valid again. There is no TTL snapshot.

After approval of an eligible password-null account commits, an invitation
transaction locks the user, replaces the broker token and inserts one database
job. Mail delivery never runs in that transaction.
An infrastructure failure logs only a technical message and user ID; approval
stays committed. Self-service recovery and admin sending reuse this transaction.
Token replacement and queue insert are atomic on the application's database connection. Keep
`DB_QUEUE_CONNECTION` unset so the database queue uses that same connection.

- GET `/password/setup/{token}?email=...` validates current eligibility/token.
- POST `/password/setup` validates email, token and confirmed 8–255 character
  password, rechecks under a user row lock, writes through the hashed cast,
  invalidates all target database sessions through SessionInvalidator, rotates
  remember_token and deletes the token in the same transaction. Other users’
  sessions remain unchanged.
- Both routes share 10 requests/minute/IP. No auto-login occurs.
- Admin POST `/admin/psychologists/{id}/password-setup` requires account/admin
  middleware, policy, Form Request and CSRF; limit is one/minute/admin/target.
  It replaces the previous token immediately on successful commit and writes
  `user.password_link_sent` with actor/entity only, no metadata. It is available
  with or without an existing password. Historical `user.password_setup_resent`
  audit rows remain readable. Every new public/admin/approval link replaces the
  previous token.

`SendPasswordSetup` carries user ID and token, then checks current eligibility
and broker validity immediately before delivery. Old jobs after resend and expired
or ineligible accounts finish without mail. Retries use the same token.
Routes generate base-path-safe URLs from APP_URL in workers. Russian HTML and
text templates have no external assets or password content. Neutral copy describes
a one-time new-password link, configured TTL and the option to ignore unexpected
mail; receiving/opening the message does not change the password.

Setup validation renders errors directly without flashing credentials. Setup
URLs are excluded from session previous-URL storage; responses use no-store and
no-referrer. Setup exceptions render a generic response even with APP_DEBUG;
application logs contain only exception type. Raw tokens necessarily appear in
the emailed URL and valid form's hidden input, and in private queue payloads.
Queue and failed-job tables, backups and mail capture are sensitive infrastructure.
Do not dump payloads, messages or SMTP transcripts into logs. Configure production
HTTP/access/error logging to redact setup paths and email query parameters.

## Expiry warnings

`groups:queue-expiry-warnings` runs hourly with scheduler overlap protection.
It reads the warning-days setting once, uses an owner EXISTS filter and chunks
of 200 groups, and reports only the actual queued count. Candidates are active,
non-deleted, enabled groups with null marker and
`now < expires_at <= now + expiry_warning_days`, owned by approved, enabled,
non-deleted non-admin psychologists. Disabled groups retain a null marker and
may warn after re-enabling while still eligible.

`SendExpiryWarning` implements `ShouldBeUnique`, with key
`group-expiry-warning:{group_id}:{expires_at UTC Y-m-d H:i:s}` (the database's
exact second precision). The command explicitly acquires Laravel `UniqueLock`
before Bus dispatch to count actual inserts and releases it on dispatch error.
The framework worker releases this same lock after success or terminal failure;
it remains held during queued/running/retry states. There is no lock TTL that
could expire while the job is still waiting. Do not manually delete queued jobs
without releasing their corresponding lock; investigate orphan locks before
removing one. After terminal failure, prefer scheduler re-dispatch; do not manually
retry an exhausted warning if a replacement for that period is already queued
or running (manual queue retry does not acquire a new unique lock).

The job reloads group/owner and current threshold before sending. Mail contains
only group title, Europe/Minsk expiration, remaining days and protected group
link. After successful application transport acceptance, a separate transaction
locks and rechecks active status, exact expected expiry and null marker before marking.
Mail exceptions and cancelled sends leave the marker null. A late old-period
send cannot mark a newly extended period. Extension and re-publication retain
the existing marker reset and obtain a new unique key when expiry changes.

Transport acceptance is not inbox delivery. A worker crash between transport acceptance and marker commit may cause a repeated email;
there is no atomic transaction between mail transport and MySQL. Queue uniqueness
prevents concurrent duplicate dispatch, not this external-system crash window.

`groups:expire` remains independent of mail/queue, including during mail transport outage.
Neither warning flow mutates placement dates/status/duration nor any payment.

## SMTP/sendmail, queue and deployment

Both jobs use the database queue, three tries, backoff 60/300 seconds and a
45-second job timeout. SMTP has a 15-second timeout; database retry_after defaults
to 90 seconds. With PCNTL, keep retry_after greater than worker/job timeout.
PCNTL is preferred for hard timeouts; without it, use the verified bounded cron
process runtime and retry_after safety gate in deployment.md. Only `smtp` and
`sendmail` transports are accepted for these flows. Log, array, failover and roundrobin are rejected
before Laravel Mail is called, so they cannot expose setup URLs or count as delivery.
Job errors are sanitized without chained transport exceptions. Application
bootstrap enables `zend.exception_ignore_args` so worker traces cannot expose
serialized invitation payload arguments. The local PHP
image enables PCNTL. `MAIL_TIMEOUT` is the SMTP socket timeout only. Sendmail
is a local process transport and is not protected by that setting. Without
PCNTL, sendmail also requires the verified host process-runtime/retry_after
safety gate; SMTP timeouts alone do not bound the entire job/process.

Production needs:

- `APP_URL=https://gruppa.info/cabinet`, HTTPS, APP_DEBUG=false and a stable APP_KEY;
- real SMTP host/port/scheme/username/password, or explicitly configured local
  sendmail (`MAIL_MAILER=sendmail`, `MAIL_SENDMAIL_PATH`), and a real from address
  supplied outside Git;
- a supervised database worker (supervisor/systemd), or scheduled
  `php artisan queue:work database --stop-when-empty --tries=3 --timeout=45`;
- cron every minute for `php artisan schedule:run`;
- a shared cache/lock store for all FPM, scheduler and worker instances, including
  settings invalidation and unique locks; the local file store only works across
  these containers because they mount the same storage;
- non-destructive migrations, worker restart after deploy, aggregate queue/failure
  monitoring and a restricted operator process for retrying exhausted jobs.

Mailpit is local capture only and must not be deployed as production SMTP.
See `development.md` for local commands. No WEBPAY behavior is included.

## Safe operational diagnostics

Both jobs log `mail.password_setup.started` / `mail.expiry_warning.started`,
then `accepted_by_transport` on a non-null Laravel Mail receipt or `failed` on
mail failure. Context contains only user/group ID, the allowed transport name
(or `unsupported`), attempt number, and exception class on failure. An obsolete
or ineligible job has only a started event and remains a no-op. Warning acceptance
is logged before the existing locked marker recheck: it does not assert that a
late old-period send marked the current period.

`mail.password_setup.queued` contains only user ID. It is registered after Bus
returns an inserted database job ID and emitted after the invitation transaction
commits, including any outer transaction. Rollback or failed insertion emits no
queued event. Dispatch still uses beforeCommit so token and queue insert remain
atomic. Warnings retain the scheduler's aggregate queued-count diagnostic.

No event contains recipient, setup URL/token, body/MIME, credentials, serialized
job payload, command/path/output or raw transport exception text. Replacement
job exceptions have no chained transport exception. Keep application and worker
logs private; with `LOG_CHANNEL=single`, Laravel writes `storage/logs/laravel.log`.

`accepted_by_transport` means application handoff only. With sendmail it means
the local transport accepted the message, not that a remote inbox received it.
The hosting MTA may defer, reject or relay it later. Continue delivery diagnosis
in the hosting MTA/Exim facilities or support using its message ID; no specific
MTA log path or account access is assumed. See deployment.md for the operator check.
