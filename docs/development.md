# Development

## Clean checkout

Install Docker Engine with the Docker Compose plugin, then run from the repository root:

```bash
docker compose up --build -d
```

No host PHP, Composer, Node.js, or npm installation is needed. On first start, the PHP entrypoint performs `composer install` from `application/composer.lock`, creates the ignored `application/.env` from `.env.example`, generates a local key, checks MySQL availability, and then starts PHP-FPM. A missing application or unavailable database causes the container to exit with an explicit error.

Check service state and logs:

```bash
docker compose ps
docker compose logs php web mysql
```

Visit `http://localhost:8080/cabinet/`. A successful page shows `OK` for the MySQL connection. The local root URL redirects to this base path.

The normal local configuration uses the database drivers for sessions and
queues. The first startup copies these values from `.env.example`:

```dotenv
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

For an existing local `.env` created before Stage 2, update those two values
manually.

Stop services while keeping database data:

```bash
docker compose down
```

## Application and quality commands

```bash
docker compose exec php php artisan about
docker compose exec php composer install
docker compose exec php php artisan test
docker compose exec php ./vendor/bin/pint --test
docker compose exec php ./vendor/bin/phpstan analyse
docker compose exec php composer check-platform-reqs
```

## Database schema and seed data

Run application migrations and the idempotent local seed:

```bash
docker compose exec php php artisan migrate
docker compose exec php php artisan db:seed
```

To rebuild only the disposable development database:

```bash
docker compose exec php php artisan migrate:fresh --seed
```

Never run `migrate:fresh` against a database containing data that must be kept.
Automated tests use `gruppa_cabinet_test` and perform their own migrations.

The seed creates empty dictionary containers for education type, group format,
and gender; it does not invent display values. It also creates typed business
settings. Placement and extension prices are intentionally unconfigured
(`NULL`). In `local` and `testing` only, the seed creates the development
administrator `admin@gruppa.test` with password `password`; production seeding
does not create this known-password account.

`php artisan test` uses MySQL in Docker with the dedicated `gruppa_cabinet_test` database. The one-shot `mysql-provision` Compose service creates that database and grants the local application user access on every stack start, so both fresh and existing MySQL volumes are supported without touching the `gruppa_cabinet` development database.

Composer resolves dependencies for the production target configured as PHP `8.2.32`. Larastan uses the committed `application/phpstan.neon` at level 5. Pint uses `application/pint.json`.

## Troubleshooting

- If `docker` inside WSL reports that the command cannot be found and asks for WSL integration, enable Docker Desktop integration for that distribution and reopen the shell.
- If startup fails while waiting for MySQL, inspect `docker compose logs mysql php`; the PHP entrypoint deliberately exits after 30 failed connection attempts.
- If port 8080 is already occupied, stop the conflicting local service before starting this stack. The documented URL and `APP_URL` intentionally use port 8080.
