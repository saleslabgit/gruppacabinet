# Gruppa Cabinet

Gruppa Cabinet is the Laravel application foundation for the psychologist group cabinet described in `SPEC.md`. Stage 1 provides the runtime and development tooling only; product models and workflows are intentionally not implemented yet.

## Repository boundary

- `application/` is the standalone deployable Laravel 12 project.
- `docker/` and `compose.yaml` are local-development infrastructure only.
- `docs/` contains the current architecture, development, and project-status notes.

Only `application/` is intended for a later production deployment. Docker is not a production dependency, and production deployment has not yet been performed or verified.

## Local start

Prerequisite: Docker Engine with the Docker Compose plugin. Host PHP, Composer, Node.js, and npm are not required.

From the repository root, run the single startup command:

```bash
docker compose up --build -d
```

The PHP container installs the locked Composer dependencies, creates the ignored local `.env` when needed, generates a local application key, and waits for MySQL. Open:

```text
http://localhost:8080/cabinet/
```

The technical smoke page proves Blade rendering, local Bootstrap 5.3.8 assets, project CSS/JS, generated base-path URLs, and a live MySQL query.

## Development commands

Run commands from the repository root after startup:

```bash
docker compose exec php php artisan about
docker compose exec php composer install
docker compose exec php php artisan test
docker compose exec php ./vendor/bin/pint --test
docker compose exec php ./vendor/bin/phpstan analyse
docker compose exec php composer check-platform-reqs
```

The test command connects to the dedicated `gruppa_cabinet_test` database on the Compose MySQL service. Compose creates it automatically and keeps it separate from the `gruppa_cabinet` development database.

Stop the stack without removing MySQL data:

```bash
docker compose down
```

See [development instructions](docs/development.md) for the clean-checkout flow and troubleshooting.
