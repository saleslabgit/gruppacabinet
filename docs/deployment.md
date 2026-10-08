# Deployment: shared PHP hosting and /cabinet

The portable baseline is HostER.by / ispmanager-style shared hosting with MySQL,
SMTP or local sendmail and cron. Docker and permanent daemons are local
conveniences. The task records HostER PCNTL availability and local MTA acceptance.
Full hosting/public staging acceptance has not been completed; the checklist
below remains a discovery and acceptance gate.

## Discover before activation

Record the actual account paths and owner, web PHP and CLI PHP versions/binaries,
CLI access (SSH or panel task), required extensions including pdo_mysql, optional
pcntl capability, memory and execution limits, MySQL vendor/version (MariaDB is not the verified MySQL 8
contract), database permissions and quotas. Record Composer availability, allowed
cron interval and maximum process lifetime, overlapping-task policy, symlink or
alias support, rewrite support and public/private directory boundaries.

Confirm outbound SMTP/TLS or a local sendmail-compatible MTA, HTTPS to WEBPAY,
certificate and DNS, callback/WAF rules, writable directory permissions, log rotation, backups and tested restore.
The PHP binary selected in cron must match the deployed web runtime. If minute
cron or a safe finite worker cannot run, resolve that hosting capability before
activation; do not silently reduce lifecycle/recovery frequency or use sync jobs.

## Psychologist document upload limits

The Cabinet application default is **20480 KiB (20 MiB) per file**, inclusive.
Set production `PSYCHOLOGIST_DOCUMENT_MAX_KB=20480` in private configuration;
rebuild the Laravel config cache through the normal release procedure after changing
it. Existing explicit values of 10240 otherwise continue to override the new default.
Allowed document MIME types remain JPEG, PNG and PDF; WEBP is not supported.
Group-cover application limits remain unchanged at 5120 KiB.

The operator must check effective settings separately on **both** the external
public handler and Cabinet web PHP runtimes (CLI settings alone are insufficient):

- `upload_max_filesize` must be at least `20M`.
- `post_max_size` must comfortably exceed the largest expected complete questionnaire,
  including every attachment, fields and multipart overhead. Recommend at least
  **128M** for the current form unless the host has a stricter approved limit.
  More attachments may require a larger approved total; 128M is not unlimited.
- Apache/nginx/proxy request-body limits must not be below the intended total
  multipart size. Local nginx uses `client_max_body_size 128m`.
- Temporary upload storage must be writable and have sufficient free space for
  concurrent multipart submissions; check hosting quotas and PHP `max_file_uploads`
  against the expected attachment count.

Use hosting configuration/private diagnostics to inspect only these settings;
do not publish phpinfo or private environment files. Configure PHP/server settings
at the hosting layer and reload the affected runtime as needed; application code
must not attempt to raise PHP limits. Local Docker uses `upload_max_filesize=20M`,
`post_max_size=128M`; restart PHP/web after deployment of those settings.
The existing deployment preflight does not check upload limits and is unchanged.

After separate deployment, verify a supported 20 MiB upload, a 20 MiB + 1 byte
rejection and a multi-file submission below the approved total in a controlled
staging environment. Verify safe errors for PHP/proxy total-body rejection too;
requests rejected before PHP cannot be handled by the form handler itself.
The external `/form` and its handler require the separate
[operator handoff](integration.md#external-public-form-operator-handoff).

## Build a production artifact

In a clean local/CI release directory containing the committed application and
composer.lock, using PHP/extensions compatible with the target, run:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
```

The selected CLI and web PHP runtimes must include `ext-dom` (now explicitly
required by Composer for group HTML sanitization), alongside the other locked
platform requirements. Apply the additive group-content migration before serving
new code. Preserve private `storage/app/private/group-covers/` during releases and
back it up with group metadata. `GROUP_COVER_MAX_KB` defaults to 5120; web/PHP
upload and request limits must accommodate this plus form content.

Upload the resulting application **including vendor/**. No Node build is needed;
public assets are committed. Do not copy local .env, cached config/routes/views,
logs, sessions, test artifacts or local uploads. Keep runtime storage and private
configuration persistent and backed up. Composer on the server is optional;
when available it can install the same lock file with the same flags. With CLI
access, run `composer check-platform-reqs --no-dev` against the actual target PHP
(using a temporary Composer PHAR if necessary) before activation. A local check
does not establish compatibility of the hosting web PHP.

## Private application and public path

Keep the full application outside public_html. Prefer an allowed alias/symlink
from `public_html/cabinet` to the private application's `public` directory.
Only public contents may be web-accessible; .env, vendor, storage, bootstrap,
source and database files must not be served. Verify requests for those paths
are denied, including through main-site rewrites.

If aliases/symlinks are unavailable, copy only `application/public/` (including
`.htaccess` and assets) to `public_html/cabinet`. In that deployed copy of
`index.php`, replace all three paths using the verified absolute private path:

```php
// Example placeholder: replace with the actual private application directory.
$appRoot = '/PRIVATE/ACCOUNT/PATH/application';
if (file_exists($maintenance = $appRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}
require $appRoot.'/vendor/autoload.php';
$app = require_once $appRoot.'/bootstrap/app.php';
```

Retain the original imports, LARAVEL_START and handleRequest call. These are
replacements for the existing maintenance/autoload/bootstrap lines, not a second
bootstrap. Do not put account paths in repository production code. Refresh the
copied public assets/controller each release. Configure rewrite fallback to this
controller, preserve `/cabinet` as the request base path, and exclude it from the
main site's redirects. Test assets, login redirects, setup links, return/cancel
and notify URLs over real HTTPS; APP_URL alone cannot fix server rewrite errors.

## Production cabinet HTTPS redirect

Deploy the current `application/public/.htaccess` with the public files (or through
its symlink). For requests whose Host is `gruppa.info`, Apache redirects plain
HTTP to the same path on `https://gruppa.info` before serving files or invoking
Laravel. The original query string is retained. Local hosts are unaffected.
The rule skips the redirect when either Apache's `HTTPS=on` or HostER's
`X-Forwarded-Proto=https` reports HTTPS; it does not use `SERVER_PORT`. HostER's
public proxy was verified to overwrite that header: an HTTP request with a
client-supplied `X-Forwarded-Proto: https` still reached web PHP as `http`, and
an HTTPS request with a client-supplied `X-Forwarded-Proto: http` reached it as
`https`. The latter also had `HTTPS=on`; both protocols reported
`SERVER_PORT=80`. This trust is specific to the verified HostER public endpoint.

After deploying the public files, run these checks against production:

```bash
curl -sS -D - -o /dev/null http://gruppa.info/cabinet/login
curl -sS -D - -o /dev/null http://gruppa.info/cabinet/
curl -sS -D - -o /dev/null 'http://gruppa.info/cabinet/login?probe=1'
curl -sS -D - -o /dev/null https://gruppa.info/cabinet/login
curl -sS -D - -o /dev/null -H 'X-Forwarded-Proto: https' http://gruppa.info/cabinet/login
curl -sS -D - -o /dev/null -H 'X-Forwarded-Proto: http' https://gruppa.info/cabinet/login
```

The first three responses must be permanent redirects with `Location` set to
`https://gruppa.info/cabinet/login`, `https://gruppa.info/cabinet/`, and
`https://gruppa.info/cabinet/login?probe=1`, respectively. The spoofed-header
HTTP request must also redirect to HTTPS. Neither HTTPS request may loop,
downgrade or redirect solely because of the supplied header. Check an arbitrary
`/cabinet/*` path and a public asset as well. Finally, sign in as a psychologist
in mobile Chrome; login and subsequent cabinet pages must stay on HTTPS.
This operator check is required because local tests cannot verify the live
host's parent rewrites or its deployed `.htaccess`.

## Configure and migrate

Use private runtime .env values supplied by the operator:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://YOUR-HOST/cabinet
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
DB_CACHE_TABLE=cache
DB_CACHE_LOCK_TABLE=cache_locks
```

Set a stable application-specific CACHE_PREFIX shared by web, scheduler and
workers, the same database/cache connections and credentials, mail sender and
SMTP/sendmail transport and WEBPAY configuration. Public intake needs no shared secret. Use
WEBPAY_ENV=sandbox on staging. Never use array/file cache for staging/production distributed locks.
Preserve an existing APP_KEY; generate and securely back up a key once for a new
installation. Do not expose private configuration in diagnostic output.

Back up retained DB/private files/config and record release ID before migration:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan deployment:preflight
php artisan schedule:list
php artisan route:list --path=webpay -vv
```

Never use migrate:fresh on retained data. Additive cache/cache_locks migration
supports unique jobs and scheduler locks across processes. Production seeds do
not create known-password users; provision an initial admin through a separately
approved procedure. Configure approved prices in admin settings.

Preflight exits nonzero for blockers. It checks CLI PHP and production dependency
extensions, MySQL identity, required tables, writable directories, configured
sessions/queue/cache, HTTPS /cabinet, debug, mail delivery configuration and
secret presence. `WEBPAY production live mode` additionally fails when
APP_ENV=production and WEBPAY_ENV is not production; staging supports sandbox.
This is a live-readiness gate, not proof of bank/provider acceptance. It performs
only disposable technical cache/lock probes (removed afterward); no mail, provider
requests or business writes. It prints neither credentials nor connection strings.
It cannot prove web PHP compatibility, credentials' validity, actual delivery,
cron operation, routing or external access. Verify these on the target separately.

## Cron queue and scheduler

Configure exactly one scheduler invocation per minute. Separately invoke a finite
queue worker each minute using verified absolute paths to PHP and artisan:

```cron
* * * * * /ABS/PHP /PRIVATE/application/artisan schedule:run >> /PRIVATE/logs/scheduler.log 2>&1
* * * * * /ABS/PHP /PRIVATE/application/artisan queue:work database --stop-when-empty --tries=3 --timeout=45 --max-time=50 >> /PRIVATE/logs/queue.log 2>&1
```

Create private rotated logs first. PCNTL is preferred and remains enabled in the
local image: it enforces the 45-second hard job timeout in the command above.
With PCNTL, keep worker/job timeout below `DB_QUEUE_RETRY_AFTER` (currently 90
seconds by default). The worker exits when empty; `--max-time=50` is checked
between jobs and does not interrupt a running job. Allow time for an in-flight
job after that deadline when agreeing process limits with the host.

Without PCNTL, Laravel still executes database jobs. Keep the finite cron model:

```bash
/ABS/PHP /PRIVATE/application/artisan queue:work database --stop-when-empty --tries=3 --max-time=50
```

Neither a job's timeout property nor a `--timeout` argument provides a hard limit
without PCNTL. `--max-time` is also not a hard process deadline. Explicit transport
timeouts remain mandatory where supported: SMTP `MAIL_TIMEOUT` defaults to 15 seconds
and applies only to SMTP sockets, not the local sendmail process. Sendmail also
requires the verified process-runtime/retry_after gate below without PCNTL. WEBPAY
connect timeout is 5 seconds and request timeout `WEBPAY_HTTP_TIMEOUT` defaults
to 15 seconds (bounded to 1–30 in the adapter). Bound MySQL connection/query/lock
waits through verified hosting/database configuration as well. Transport timeouts
alone do not bound the whole job or hung process.

Before accepting a no-PCNTL deployment, verify the host's enforced maximum cron
process runtime, termination behavior and expected job durations. Set
`DB_QUEUE_RETRY_AFTER` comfortably above the verified maximum reasonable/allowed
in-flight job or process duration with a safety margin. Finalize and record the
actual value during HostER staging discovery; the default 90 seconds is not a
claim about HostER limits. Otherwise an expired reservation can allow another
worker to execute the same still-running job. Rebuild config caches and restart
workers after changing the value; verify retries and overlapping cron runs.

Operator decision gate: no-PCNTL hosting is acceptable only with a verified bounded
cron process runtime compatible with these jobs and retry_after. If neither PCNTL
nor a safe enforced process bound is available, do not accept the hosting until
that capability changes. Preflight reports unavailable PCNTL as WARN and exits
successfully if all hard checks pass; this is not hosting acceptance. All required
Composer extensions and other hard deployment checks remain failures when missing.
Do not switch mail/payment jobs to sync or add an HTTP-triggered queue runner.

Schedules remain: group expiry every minute, application cleanup daily, expiry
warnings hourly, trusted-bound WEBPAY recovery every five minutes. All use the
shared overlap lock. Check `queue:failed`, retry only investigated failed jobs,
monitor queue backlog and scheduler output; never blindly flush jobs or locks.
After deployment restart any old workers (`queue:restart`). Where a process
manager exists, a supervised `queue:work database --sleep=2 --tries=3 --timeout=45`
is an alternative. Local Compose retains its persistent queue-worker service.

## Acceptance and rollback

Verify prototype routes are absent in production, mail invitation/password URLs,
warning delivery, lifecycle expiry, failed-job handling, cron lock behavior and
WEBPAY recovery using synthetic data first. Check browser and CLI generated URLs
under /cabinet. Do not log credentials, tokens, signatures, card data or raw
provider payloads in application/server/proxy logs.

Notify must be reachable on public HTTPS 443 without login, CSRF, basic auth,
WAF challenges or main-site redirects. An unsigned synthetic request must be
rejected with a sanitized journal entry; this is not signed Sandbox acceptance.
Follow [WEBPAY staging prerequisites](webpay.md), including unsuccessful-notify
support configuration. Real Sandbox payment/notify/API/refund and actual hosting
acceptance remain external. Production switch requires separate authorization.

For rollback stop incoming writes/workers/scheduler as needed, restore compatible
code/config and rebuild caches. Prefer retaining additive cache and payment
context tables/columns. Do not drop history or restore DB without reconciliation
of acknowledged financial events. Backups and restore testing are required before
any deployment, not performed by preflight.

## Payment result auto-refresh release

For the payment-result auto-refresh release, deploy the new authenticated
`GET /payments/{payment}/status` route/controller and owner return Blade to the
private application. In the same release copy `application/public/ui.js` to the
separately copied public `/cabinet` directory. Rebuild route/view caches using the normal deployment procedure;
the surface layout uses filemtime cache busting for ui.js. Verify the deployed
script loads and owner status responses are private/no-store. This release
does not require credentials, database cleanup, migrations or another charge.
After deployment, an operator can observe a separately authorized payment's
pending→result transition, test the 120-second/manual/no-JS fallback and expand
“Детали платежа”; verify another owner is denied and admin details are unchanged.
Do not trigger a REAL payment solely for smoke without separate approval.

## WEBPAY live cutover and acceptance

**Current external decision: NO-GO until acceptance evidence exists.** Repository
tests use synthetic configuration and HTTP fakes. They do not verify real keys,
provider delivery, hosting, browser navigation or bank processing. Deployment
and financial acceptance require separate authorization; the launch-audit task
does not perform them.

1. **Before keys:** complete realistic Sandbox acceptance and record results:
   paid placement, active and expired extension; one Cabinet click produces a
   native provider POST, plus no-JS/manual fallback; double-click/back/reload
   reuse the local order and do not duplicate bank processing/product effects;
   signed success/failure/cancel and retry; duplicate/out-of-order notify;
   browser return before/after notify and no browser return; bounded bound-only
   get_transaction and unbound manual review; provider refund first, then local
   admin accounting. Feature tests do not exercise external browser redirects.
2. Confirm exact contracted origin `https://gruppa.info` (not an unapproved
   subdomain), allowed HTTPS callbacks under `/cabinet`, a valid certificate,
   correct web-server/proxy base-path handling, and preserved browser Referer.
   The response policy sends the origin cross-site; verify actual provider
   acceptance and hosting headers. See the official
   [environment requirements](https://docs.webpay.by/generalInfo/devEnvironment/).
3. Confirm ordinary card/one-stage merchant configuration and standard signed
   POST notifications: no card-inclusive signature, SOAP-only mode, unsupported
   payment method or two-stage capture dependency. Obtain support enablement
   for unsuccessful-operation notifications and verify signed decline/cancel
   delivery if automatic retry recognition is required. Without these, browser
   cancel remains pending/unbound and reaches manual review; get_transaction
   cannot establish missing merchant-order binding. Accept that business
   limitation explicitly or keep NO-GO. See
   [notification prerequisites](https://docs.webpay.by/paymentIntegration/cardIntegration/paymentNotification/).
4. Complete the [retained sandbox cutover gate](webpay.md#retained-database-sandbox-to-production-cutover):
   private read-only counts and status/order IDs, quiet entry points, old
   sessions/notifications reconciled, history retained. Do not change keys with
   unresolved created/pending attempts. Unclosable created rows or unbound
   pending rows are a BLOCKER for a separate decision; no manual paid/close SQL.
5. Verify backups/recovery, shared database cache/locks, DB sessions/queue,
   finite worker execution, scheduler, operational mail and safe logging.
   Drain old in-flight jobs, pause workers/cron briefly for the switch, repeat
   the inventory and preserve old private configuration securely. Keep notify
   available throughout; a blanket maintenance/WAF/auth challenge is unsuitable.
6. Provision private real configuration only after these gates:

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://gruppa.info/cabinet
   WEBPAY_ENV=production
   ```

   Supply `WEBPAY_STORE_ID`, `WEBPAY_SECRET_KEY`, `WEBPAY_API_USERNAME` and
   `WEBPAY_API_PASSWORD` privately. Use the REAL Store ID, the same REAL SecretKey
   configured in real billing, and an authorized billing account login and
   ordinary **unhashed** password. The PHP adapter computes MD5 itself for
   get_transaction; storing MD5 in env would double-hash it. Confirm necessary
   API permission with support. Do not request/paste values in chat, reports,
   screenshots or shell history. Nonempty values do not prove correctness.
   Configure approved positive real placement/extension prices in admin settings.
7. Rebuild runtime configuration/views and restart workers using the existing
   deployment procedure. Run `php artisan deployment:preflight`,
   `php artisan schedule:list`, `php artisan route:list --path=webpay -vv`.
   Require production live-mode PASS; do not switch keys just to remove a FAIL
   before cutover approval. Check target PHP/web/proxy separately. Preflight
   neither contacts WEBPAY nor resolves business rows.
8. During separately authorized acceptance, inspect only the generated action
   `https://payment.webpay.by/`, `wsb_test=0`, and the exact HTTPS URLs
   `https://gruppa.info/cabinet/payments/{id}/return`,
   `https://gruppa.info/cabinet/payments/{id}/cancel`,
   `https://gruppa.info/cabinet/webpay/notify`. Never dump the full signed form
   or secrets. Verify CSRF on owner actions and no unauthorized checkout.
9. Confirm inbound HTTPS 443 POST to notify has no redirect, session/login,
   CSRF or WAF challenge. Valid signed provider callbacks must get stateless
   HTTP 200; invalid signatures must be rejected, with safe technical journal
   entries only. Verify actual delivery even when the payer closes the browser.
   A reachable GET/login or route listing is insufficient evidence.
10. After explicit authorization, perform a low-amount LIVE payment at the
    approved tariff. Verify exact bank debit, signed notify, local succeeded
    state and a single group effect (placement → draft; active extension adds
    days; expired renewal → approved until publication confirmation). Verify
    accounting and refund physically through WEBPAY first, then use local
    admin “Отметить возврат выполненным в WEBPAY”. Record acceptance without
    card/personal data or raw forms. Open user payment entry points only after
    the evidence is accepted.

### Payment rollback boundary

If acceptance fails, close new payment entry points and investigate while
keeping the current notify receiver and required workers operational. Before
any LIVE form/attempt exists and after the cutover gate, a compatible code/config
rollback may be coordinated with the same quiet-window checks. Once a LIVE
attempt exists, **do not simply restore sandbox keys or an older DB snapshot**:
late live notifications/recovery still need live credentials and all financial
history must survive. Prefer reverting compatible UI/code while retaining live
payment config/schema/history; reconcile with WEBPAY before any environment
change. No automatic refund, deletion, status reset, migration rollback or
credential fallback is provided. A required cross-environment rollback is a
separate operator/architecture decision.

## Sendmail configuration and staging verification

Local development stays SMTP/Mailpit. For the explicitly selected production
sendmail option, set private runtime values (verify the binary on that host):

```dotenv
MAIL_MAILER=sendmail
MAIL_SENDMAIL_PATH="/usr/sbin/sendmail -bs -i"
MAIL_FROM_ADDRESS=sender@YOUR-DOMAIN
MAIL_FROM_NAME="Your application"
QUEUE_CONNECTION=database
LOG_CHANNEL=single
LOG_LEVEL=info
```

Preflight uses the stable `Mail delivery configured` check. SMTP requires host
and from address. Sendmail requires from address, a literal absolute executable
path and simple flags including `-bs` (preferred) or `-t`. Shell expressions,
relative paths, quoted/space-containing paths and missing/non-executable binaries
fail; use a simple verified absolute binary path. Detection uses filesystem
checks only and never runs the command or sends a probe. Output identifies only
`smtp`, `sendmail` or `unsupported`, never the private path or credentials.
Log/array/failover/roundrobin are hard failures in staging/production.

The task records PCNTL available on the real HostER account, but verify it for
the actual CLI binary on every target; the portable fallback gate above remains.
`MAIL_TIMEOUT` does not protect sendmail. A hosting MTA `250 OK id=...` or Laravel
`accepted_by_transport` is acceptance only, not remote inbox delivery.

After deployment, an authorized operator should:

1. Configure the verified sendmail path, real from address/name, database queue
   and private logging settings above.
2. Run `php artisan optimize:clear`, then `php artisan deployment:preflight`;
   require `PASS Mail delivery configured: sendmail` and resolve all hard failures.
3. Monitor private `storage/logs/laravel.log`, trigger one approved psychologist's
   password-setup resend, and confirm `mail.password_setup.queued`.
4. Run `php artisan queue:work database --once --tries=3 --timeout=45` using the
   verified CLI PHP (apply the no-PCNTL gate if needed).
5. Look for `mail.password_setup.started` followed by `accepted_by_transport` or
   `failed`; inspect `php artisan queue:failed` if necessary, without dumping payloads.
6. If accepted but absent from the inbox, use HostER MTA/Exim facilities/support
   and its message ID to investigate queue/relay/rejection and SPF/DKIM/DMARC or
   reputation. Available log access and actual delivery remain external unknowns.
   Do not alter Laravel business state to claim delivery.

These are manual staging steps; automated tests send no external email and make
no HostER changes.

### TASK-2026-09-24-04: group visibility migration

Apply `php artisan migrate --force` during the deployment maintenance window,
then clear/rebuild the usual application caches. Migration
`2026_09_24_000002_add_psychologist_deleted_at_to_groups` adds a nullable timestamp
and owner/visibility index. Its single backfill UPDATE copies `deleted_at` to
`psychologist_deleted_at` and clears `deleted_at` only for rejected groups.
Other deleted statuses and all related records remain unchanged. No fresh
migration or data reset is required. MySQL schema changes implicitly commit;
keep writers stopped until the schema change and backfill finish.

Rollback converts psychologist-hidden rows to ordinary soft deletes before
removing the field, preserving an existing admin deletion timestamp. Thus those
rows disappear from admin again under the old behavior. The distinction between
psychologist hiding and admin deletion is lost on rollback. A subsequent up()
again restores all soft-deleted rejected groups, including groups deleted by an
administrator after the original deployment; the old schema cannot distinguish
those origins. Retain the normal pre-deployment backup for exact recovery.

## MODX outbound group synchronization (TASK-2026-10-01-03)

Apply additive migration `2026_10_01_000003_add_group_modx_sync` with the normal
backup/maintenance procedure, rebuild caches and restart workers on the new code.
The external MODX plugin is already updated by the product owner; no plugin code
is deployed from this repository. Real MODX end-to-end acceptance is **not verified**.

Rollback of this migration removes remote identities and sync tracking. After live
synchronization, retain/restore those mappings from backup before re-enabling
outbound work; do not treat schema rollback/reapply as a safe way to retry creates.

Before live acceptance, the operator must verify:

- private `MODX_BASE_URL` (HTTPS `/api/v1`) and `MODX_TOKEN` with cabinet.sync;
- `MODX_CONNECT_TIMEOUT=5`, `MODX_SYNC_TIMEOUT=60` (bounded at 60 seconds);
- `mxheadless_max_body_bytes >= 8388608`, MODX `upload_maxsize >= 5242880`;
- PHP/web-server request-body limit >= ~10 MiB and writable Media Source 1;
- database queue worker and scheduler running, shared cache/lock store and prefix;
- queue retry_after > job timeout (default 90 > 75 seconds); CLI PCNTL/timeouts
  supported or existing no-PCNTL deployment gate satisfied. Worker default timeout
  does not override this job's 75-second timeout. Four tries, backoff 60/300/900.

The admin panel shows pending/syncing/synced/failed/conflict and offers async manual
resync. A queue-insertion outage is `queue_unavailable`; after restoring the worker/
queue, use the panel to schedule a new revision. Never rotate a create key or edit
remote IDs arbitrarily to hide conflicts. mxHeadless default idempotency TTL is
86400 seconds: inspect/reconcile an ambiguous initial create before retrying after
TTL expiry. There is no documented permanent UUID lookup on the external endpoint.
See `modx-api.md` for this external limitation and exact mapping/cover contract.

Verify on a synthetic group: approval commits, worker creates parent 3/template 8/
web/unpublished, local Resource ID persists, all owned TVs/MIGX/image match; admin
edit updates the same ID and changed covers upload once. Cabinet never writes
SEO/showOnMainPage or deletion flags. Nonempty `meeting_price_currency` writes price_usd; empty omits it. Content sync preserves publication; initial publication remains manual.
No full JSON, HTML, leader names, paths, base64 or credentials belong in logs.

## Publication control deployment (TASK-2026-10-01-04)

Deploy additive migration 000004 and restart all content/publication workers together
so both classes use the shared overlap lock. Do not roll back the migration while
paused groups exist: it removes the publication/history/revision metadata. Use existing database
queue/cache and timeouts. No new credentials or external plugin source are included.
The operator must first supply `/cabinet/resources/publication` per modx-api.md.

Manual external acceptance on a synthetic remote-backed group: pause/unpublish,
resume/publish same ID before expiry, check unchanged end date and normal MODX events/cache;
repeat and inspect stale work; delete/expire unpublish without Resource deletion or
refund. Initial approved publication and expired renewal remain manual. Verify
nonempty currency writes price_usd and empty preserves remote TV. These live checks
were not executed by automated work.

For queue_unavailable, restore the queue and dispatch the existing
SetGroupModxPublication(group ID, current revision, expected Resource ID) on database
from an operator console after verifying current desired/status. Set a failed current
revision back to pending before replay; never rotate an idempotency-conflict key.
For conflicts, reconcile the same key/body with the MODX operator first. No new
admin recovery UI is introduced; deleted rows are handled with withTrashed().

Pause controls publication only: expires_at stays unchanged and placement time
continues. Visible paused groups receive normal expiry warnings and expire at the
original deadline; psychologist-hidden groups receive no further warning or
expiration processing, including stale jobs. Resume republishes the same Resource
only before expiry and preserves the warning marker. A deadline reached during
publish commits expired plus a newer unpublished revision and queues unpublish
after commit. Initial publication and expired renewal still require manual
publication and admin activation. The publication endpoint contract is unchanged.

## Password recovery

`/password/forgot` reuses the existing broker, token table and configured setup TTL;
no migration or separate reset store is required. Public responses are generic for
unknown/ineligible email and queue failures. IP and hashed-email rate limits need
the shared cache. Admin sending is allowed for eligible psychologists regardless
of an existing password. New links replace old ones; completion invalidates target
database sessions and remember state. Onboarding remains supported. Keep database
queue/SMTP or sendmail operational; never log passwords, tokens, reset URLs or
recipients. Queue/transport acceptance does not guarantee inbox delivery.

## Telegram and publication updates (TASK-2026-10-03-01)

Apply the additive nullable application-hide timestamp migration with the normal `php artisan migrate --force`; existing rows are preserved. Rollback removes only this column.
Set private `TELEGRAM_BOT_TOKEN` and `TELEGRAM_ADMIN_CHAT_ID` (numeric chat ID); repository placeholders remain empty. Rebuild config cache and restart/allow turnover of database workers after deployment. Never print token-bearing Telegram URLs, bot tokens, chat IDs, private env, queue payloads or feedback bodies in diagnostics. See [telegram.md](telegram.md).

Keep `APP_URL=https://gruppa.info/cabinet`. Production route/asset generation forces HTTPS, including queued links and payment return/cancel/notify; `deployment:preflight` rejects an insecure scheme. Existing web-server redirect configuration is unchanged. Local/testing HTTP continues working.

Monitor publication status in admin group detail. Restore keeps active groups disabled until successful current publish, with the old deadline still running. Renewed expired groups remain approved until MODX success starts the new clock. Retry through the admin restore/republication actions; investigate conflict rather than blindly replaying an obsolete job. Missing resource requires manual sync/publication recovery. Initial approved publication remains manual. No payment deletion or automatic refund is introduced.
