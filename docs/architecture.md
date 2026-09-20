# Architecture

## Stage 1 topology

The repository has a strict runtime boundary:

```text
browser -> localhost:8080/cabinet/ -> Nginx -> PHP-FPM -> Laravel -> MySQL
```

`application/` is a standalone Laravel 12 application and is the only deployable source tree. `compose.yaml` and `docker/` create a local Nginx, PHP 8.2, Composer, and MySQL environment; none of that container configuration belongs to the production artifact.

MySQL data is stored in the Compose named volume `mysql-data`, outside `application/`. The Laravel directory is bind-mounted into PHP and read-only into Nginx during local development.

## Public base path

The application public URL includes `/cabinet`. Local Nginx maps only `/cabinet/` to `application/public/`, forwards front-controller requests to PHP-FPM with `/cabinet/index.php` as the script name, and redirects the local domain root to `/cabinet/`. Laravel receives the correct base URL and uses its route and asset helpers rather than hardcoded root-relative application paths.

Local `APP_URL` is `http://localhost:8080/cabinet`. The future production value can be `https://gruppa.info/cabinet`; the hosting rewrite and real production deployment remain unverified.

## Time and money

Laravel and the PHP container use UTC for application/runtime time. `App\Support\DateTimeFormatter` converts display values to `Europe/Minsk`.

Money remains integer minor units. `App\Support\MoneyFormatter` splits and groups the integer's decimal representation and never converts an amount through floating-point arithmetic.

## Frontend delivery

There is no frontend build pipeline. Bootstrap 5.3.8 CSS and bundle JS are committed under `application/public/vendor/bootstrap/5.3.8/`; project-owned `app.css` and `app.js` are also served directly from `public/`. Node.js, npm, Vite, and runtime asset compilation are not used.
