# Project status

## Stage 1 foundation

Stage 1 provides:

- a standalone Laravel 12 application in `application/` with locked Composer dependencies;
- local Docker services for Nginx, PHP 8.2/Composer, and MySQL;
- real `/cabinet/` base-path handling in local Nginx and Laravel-generated URLs;
- a minimal Blade/MySQL smoke page with locally committed Bootstrap 5.3.8, project CSS, and project JavaScript;
- UTC application time plus shared Minsk date/time and integer-minor-unit money formatters;
- PHPUnit, Pint, and Larastan level 5 configuration;
- a PHP `8.2.32` Composer platform target and explicit runtime extension requirements.

The exact check results for this implementation iteration are recorded in `.ai/report.md`.

## Intentionally not implemented

No Stage 2+ domain tables, models, enums, authentication, role access, CRUD, complete UI prototypes, registration, mail, queue jobs, public-site integration, group/payment flows, or WEBPAY behavior and credentials are present.

## External prerequisites and unknowns

- Local execution requires a working Docker Engine and Compose plugin.
- Production hosting paths, rewrite behavior under `gruppa.info`, and the queue-worker mechanism have not been validated.
- Production deployment has not been performed or verified.
- SMTP, public-site, and WEBPAY credentials are neither required nor included.
