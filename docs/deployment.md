# Staging deployment checklist

Deployment is a separate task. This repository has been prepared for local
verification; public staging and real WEBPAY acceptance must be recorded there.

1. Provision PHP matching composer platform requirements, MySQL, web server,
   persistent shared cache/locks, database queue and SMTP. Serve only
   `application/public`; keep private storage/env inaccessible from the web.
2. Obtain the actual staging hostname, HTTPS certificate, database connection,
   SMTP credentials, Sandbox store/SecretKey/API credentials and WEBPAY API
   access. These values come from the operator/provider; there are no invented
   deployment defaults. Set APP_ENV=production, APP_DEBUG=false, APP_TIMEZONE=UTC,
   APP_URL to the public HTTPS cabinet base path. Set secure session cookies.
3. Preserve an existing APP_KEY. For a new installation generate one once and
   back it up securely. Configure database session/queue and a persistent cache
   shared by all web/worker/scheduler instances. Never use array cache there.
4. Install production dependencies from composer.lock; check platform
   requirements. Back up database/private files and secure configuration before
   migration. Verify restoration procedures and capture current release ID.
5. Run `php artisan migrate --force` and idempotent `php artisan db:seed --force`.
   No migrate:fresh on retained data. Production seeds do not create known-password
   users; initial administrator provisioning needs a separate approved procedure.
6. Configure placement/extension prices via admin settings. Follow docs/webpay.md
   with WEBPAY_ENV=sandbox on staging; do not use production credentials there.
7. Run `php artisan optimize:clear`, `php artisan config:cache`,
   `php artisan route:cache`, `php artisan view:cache`. Inspect
   `php artisan route:list --path=webpay -vv` and payment routes. Prototype routes
   must be absent in production. Check generated return/cancel/notify URLs with
   the actual APP_URL; do not let main-site rewrites intercept `/cabinet/*`.
8. Run supervised `php artisan queue:work database --sleep=2 --tries=3 --timeout=45`.
   Restart workers after deploying changed code/config (`php artisan queue:restart`).
   Add one scheduler (`* * * * * php /path/to/application/artisan schedule:run`)
   under the correct runtime user. Check `php artisan schedule:list`, failed jobs,
   expiry warnings and five-minute payment recovery. Monitor worker exits and
   persistent cache locks; investigate crashes before clearing locks.
9. Verify SMTP delivery and password setup URLs. Restrict log access and rotation;
   verify no credentials, tokens, signatures, raw provider XML or card information
   appear in app/server logs. Avoid logging request bodies/query strings in the
   reverse proxy. Notify must be reachable publicly over HTTPS port 443 without
   login, session, CSRF, basic-auth gates or main-site redirects.
10. An unsigned synthetic notify should be rejected, with a sanitized local
    journal entry. This only checks reachability; real signed delivery is a
    separate WEBPAY Sandbox acceptance step. Never send a real payment as a
    deployment health check.
11. Run the WEBPAY staging checklist, complete API/queue/SMTP/UI checks and record
    actual results. A lost notify without verified binding remains pending/manual
    review by design; operations must not bypass this through manual DB success.
12. Rollback: stop incoming writes/worker/scheduler as needed, restore the previous
    compatible code/config and rebuild caches. The payment context migration is
    additive; prefer leaving columns in place during code rollback. Do not drop
    binding/history columns on an active system. Coordinate database restoration
    with provider reconciliation to avoid losing acknowledged financial events.

The production switch is separately authorized only after staging acceptance;
see docs/webpay.md. Backups, deployment paths, process manager and DNS/TLS values
must be supplied by the actual hosting environment.
