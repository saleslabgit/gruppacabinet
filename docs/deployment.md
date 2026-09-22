# Deployment: shared PHP hosting and /cabinet

The portable baseline is HostER.by / ispmanager-style shared hosting with MySQL,
SMTP and cron. Docker and permanent daemons are local conveniences. No actual
hosting account or public staging has been verified; the checklist below is a
discovery and acceptance gate, not a statement of a hosting plan's capabilities.

## Discover before activation

Record the actual account paths and owner, web PHP and CLI PHP versions/binaries,
CLI access (SSH or panel task), required extensions including pdo_mysql, optional
pcntl capability, memory and execution limits, MySQL vendor/version (MariaDB is not the verified MySQL 8
contract), database permissions and quotas. Record Composer availability, allowed
cron interval and maximum process lifetime, overlapping-task policy, symlink or
alias support, rewrite support and public/private directory boundaries.

Confirm outbound SMTP/TLS and HTTPS to WEBPAY, certificate and DNS, callback/WAF
rules, writable directory permissions, log rotation, backups and tested restore.
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
workers, the same database/cache connections and credentials, SMTP sender and
transport, integration secret and WEBPAY configuration. Use WEBPAY_ENV=sandbox
on staging. Never use array/file cache for staging/production distributed locks.
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
sessions/queue/cache, HTTPS /cabinet, debug, SMTP and secret presence. It performs
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
timeouts remain mandatory: SMTP `MAIL_TIMEOUT` defaults to 15 seconds; WEBPAY
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

Verify prototype routes are absent in production, SMTP invitation/password URLs,
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
