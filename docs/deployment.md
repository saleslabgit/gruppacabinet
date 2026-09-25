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

## Build a production artifact

In a clean local/CI release directory containing the committed application and
composer.lock, using PHP/extensions compatible with the target, run:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
```

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
secret presence. It performs only disposable technical cache/lock probes (removed afterward); no mail, provider
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
