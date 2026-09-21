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

Visit `http://localhost:8080/cabinet/login` after migrations and seeding. The local root URL redirects to `/cabinet/`, which requires psychologist authentication. The local/testing database diagnostic is at `http://localhost:8080/cabinet/_foundation`.

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
administrator `admin@gruppa.test` and approved psychologist
`psychologist@gruppa.test`, both with password `password`. Production seeding
creates neither known-password account. Repeated seeding preserves existing
accounts and does not duplicate them.

`php artisan test` uses MySQL in Docker with the dedicated `gruppa_cabinet_test` database. The one-shot `mysql-provision` Compose service creates that database and grants the local application user access on every stack start, so both fresh and existing MySQL volumes are supported without touching the `gruppa_cabinet` development database.

Composer resolves dependencies for the production target configured as PHP `8.2.32`. Larastan uses the committed `application/phpstan.neon` at level 5. Pint uses `application/pint.json`.

## Troubleshooting

- If `docker` inside WSL reports that the command cannot be found and asks for WSL integration, enable Docker Desktop integration for that distribution and reopen the shell.
- If startup fails while waiting for MySQL, inspect `docker compose logs mysql php`; the PHP entrypoint deliberately exits after 30 failed connection attempts.
- If port 8080 is already occupied, stop the conflicting local service before starting this stack. The documented URL and `APP_URL` intentionally use port 8080.


## Browse Stage 3 prototypes

With the existing Docker stack running, open:

`http://localhost:8080/cabinet/_prototype/`

The catalog links to all 31 page groups and their direct state variants.
`docs/ui-pages.md` lists the view files and URLs. These pages work without
business records or seed data. Examples of prices, dictionaries, names,
phones, UUIDs, documents and payment identifiers are synthetic and do not
configure the database. The technical foundation is at `/cabinet/_foundation`.

Forms, upload/download, logout, moderation and payment buttons do not perform
business operations. Modal confirmations, navigation and copying the group UUID
can be exercised. There are no outgoing WEBPAY requests. Prototype routes do
not register in production.

Run the route/view/asset contract test with:

```bash
docker compose exec -T php php artisan test tests/Feature/PrototypeTest.php
```

Review the catalog at 1440, 1024 and 390 px, including long content and open
confirmations. Browser tools are external verification tools, not application
dependencies; screenshots and temporary browser artifacts are not committed.


## Real Stage 4 login

Open `http://localhost:8080/cabinet/login`. Use `psychologist@gruppa.test` /
`password` to open `/cabinet/` (Мои группы), or `admin@gruppa.test` / `password`
to open `/cabinet/admin`. The opposite role's home returns 403. Click «Выход»
to submit the real CSRF-protected POST logout form and return to login.
Group creation and management are available in Stage 7. Administrators can open
«Психологи» and «Группы»; the home work queue remains unavailable.

Five failed login attempts per normalized email and client IP are allowed
within 60 seconds. After that, wait 60 seconds from the first failed attempt.
A successful login clears the failure counter. Unknown, pending, rejected,
disabled and deleted accounts all receive the same generic failure message.


## Stage 5 psychologist CRUD and private documents

Log in as the development administrator, open «Психологи», create a synthetic
psychologist, then open the profile to edit it or confirm moderation, tariff,
access and deletion actions. New accounts are pending/enabled/non-admin and
have no password. No mail is sent. Use a separate pending record for rejection;
approved accounts are disabled rather than rejected. Education choices remain
empty until approved items exist in the database.

Open the profile's documents link to upload PDF, JPEG or PNG, view/download
through protected endpoints, and confirm deletion. Files are stored under
`application/storage/app/private/psychologists/{id}/` with random names and
must never be copied to `public` or `storage/app/public`. Do not expose the
private directory with a web-server alias or storage link.

`application/.env.example` defines `PSYCHOLOGIST_DOCUMENT_MAX_KB=10240`.
This is a configurable technical upload ceiling (10 MiB), not a product price
or business setting. `config/psychologist_documents.php` defines the disk,
document codes and allowed MIME types. PHP/web-server ceilings must also allow
the chosen size plus multipart overhead. Local Docker mounts
`docker/php/uploads.ini` (`upload_max_filesize=10M`, `post_max_size=12M`);
nginx allows 12 MiB requests. After changing these files run
`docker compose up -d --no-deps php web` and, for an nginx-only change,
`docker compose exec -T web nginx -s reload`. Production PHP/web-server limits
must be configured independently by hosting operations.

Verification commands:

```bash
docker compose exec -T php php artisan test tests/Feature/PsychologistAdminTest.php tests/Feature/PsychologistDocumentTest.php
docker compose exec -T php php artisan test
docker compose exec -T php ./vendor/bin/pint --test
docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M
docker compose exec -T php composer check-platform-reqs
docker compose exec -T php php artisan view:cache
```

The explicit analysis memory limit avoids exhausting the default PHP CLI
128 MiB; it does not change production PHP configuration. Tests use only the
disposable MySQL test database. Browser smoke data must be synthetic, and test
uploads/browser artifacts must not be committed.

## Stage 6 real psychologist profile

Log in at `http://localhost:8080/cabinet/login` with the local seeded
`psychologist@gruppa.test` / `password` account. The root shows the owner’s real groups and draft creation action. Open «Мои данные» to visit
`http://localhost:8080/cabinet/profile`: only the current questionnaire and
documents are shown, with no edit/upload/delete controls. Missing questionnaire
values and an empty document list use the approved empty states.

In a separate browser session, log in as the local administrator, find this
psychologist and upload a synthetic PDF/JPEG/PNG through its Documents page.
Refresh the psychologist profile, open «Просмотр» and «Скачать», and verify the
original filename and file content. Another approved psychologist must receive
404 for the same `/profile/documents/{id}/view` and `/download` URLs. Admins
receive 403 on these owner routes and `/profile`; psychologists still receive
403 on admin routes. Admin document management stays on its existing URLs.

Check the profile with long email/document names at 1440, 1024 and 390 px:
navigation wraps, details collapse to one column on mobile, document rows become
cards, actions remain available and the page has no horizontal overflow. Use
«Выход» to verify POST logout. Delete synthetic uploads through admin management
after verification. Existing data should not be removed or overwritten.

Focused MySQL regression:

```bash
docker compose exec -T php php artisan test tests/Feature/PsychologistProfileTest.php
```

## Stage 7 real groups

Use two separate browser sessions for the seeded psychologist and administrator.
All URLs below are relative to `http://localhost:8080/cabinet`.

1. Psychologist: open `/`, click «Добавить группу» (POST `/groups`). A draft is
   created and redirects to `/groups/{id}/edit`.
2. Fill the questionnaire, then «Сохранить изменения» to keep the draft, or
   «Отправить на модерацию» to save and submit. These are separate requests.
3. Administrator: open «Группы» (`/admin/groups`), find the moderation group,
   open it, choose «На доработку» and enter a comment of at least 10 characters.
4. Psychologist: read the comment/history, edit and resubmit. Administrator:
   confirm approval, copy «ID группы для gruppa.info», and manually publish the
   catalogue entry with that ID. Confirm «Отметить активной» in the cabinet.
   Dates start at activation and display in Europe/Minsk.
5. Verify the rejected path on a separate group, including required reason and
   owner soft deletion. Rejected groups cannot be edited or resubmitted.
6. Repeat with `free=false`: the historical tariff label differs, but no payment
   screen, row or payment prerequisite exists in Stage 7.

Group dictionaries are not seeded with invented product values. Before filling
forms, approved active items must exist in `group_format` and `gender`.
Empty dictionaries display an explanatory warning. Automated and browser
verification use isolated synthetic items; these are not product seed values.
Dictionary administration is Stage 8, so real values remain a prerequisite.

Admin creation selects an enabled approved psychologist. Existing ownership,
UUID, tariff and lifecycle dates are not editable. The published-content warning
reminds the admin to synchronize changes manually with the public catalogue.
List search/filter/sort preserve query parameters across 20-row pages.

`config/groups.php` sets `abandoned_draft_days = 30`; this is an administrative
cleanup threshold, not placement duration and not a `gp_settings` value.
The abandoned filter and admin delete use the same inclusive cutoff. Drafts on
the newer side of the cutoff cannot be deleted by admin. Historical succeeded
unrefunded payments block deletion, even when a payment was soft-deleted.
No automatic cleanup is scheduled. Confirmed deletion retains group history.

Focused MySQL checks:

```bash
docker compose exec -T php php artisan test --filter=GroupWorkflowTest
```

Also run the full test suite, Pint, Larastan, platform requirements and Blade
compilation listed above. Browser checks cover both tariffs, rejection, IDOR,
UUID copying, abandoned cutoff, and list/form/detail/moderation at 1440/1024/390.
Use only synthetic verification data; keep screenshots/scripts outside Git.

## Stage 8: manage local dictionaries and settings

Log in as the seeded local administrator, then open **Справочники**. Open each
of `education_type`, `group_format` and `gender` and add synthetic local values
with stable lowercase codes (letters, digits, underscore). They immediately
appear in psychologist/group creation forms; no seed rerun or source edit is
needed. These local examples are not approved production dictionary content.

Edit changes names/order/active state; codes cannot change. Deactivation requires
confirmation and hides a value from new forms, while records already using it
retain their selection. Reactivation restores it. Delete is offered only for
unused values and empty custom containers; core containers remain protected.
Soft-deleted users/groups still count as usage.

Open **Настройки** to edit both prices in BYN (`50`, `50.0`, `50,00`) and all five
integer timing settings. Blank price means unconfigured, not zero. Confirm the
save in the modal. Validation retains entered values and shows field errors.
Warning days must be smaller than placement days. Technical integer/date bounds
are documented in architecture.md. Settings and their old/new audit values commit
together; no payment is created. Changing placement duration affects later
activations and leaves existing group dates intact.

**Платежи** displays only “Платежи ещё не подключены”. There is no provider setup,
payment detail, refund or payment mutation at this stage. Applications and
WEBPAY remain later milestones; Stage 9 provides lifecycle automation below.

## Stage 9 expiration and free extension

After normal non-destructive migration/seeding, inspect and run the lifecycle:

```bash
docker compose exec -T php php artisan schedule:list
docker compose exec -T php php artisan groups:expire
docker compose exec -T php php artisan schedule:run
```

The command reports only the aggregate expired count. It runs every minute with
an overlap guard; row locks and state re-checks protect direct/concurrent runs.
The local stack does not start a persistent scheduler automatically. Production
needs the normal once-per-minute `schedule:run` cron from the private application
directory, using the hosting PHP executable. Configuring that cron/deployment
is outside this stage.

Use synthetic local accounts/groups to check the full flow:

1. Set an approved psychologist's current tariff to free, create/moderate and
   manually activate a group. Check the exact expiry and remaining days.
2. Prepare a future expiry inside the current warning threshold. Rendering a
   warning must leave the warning marker unchanged and create no mail/job.
3. Run `groups:expire` before expiry (unchanged), then at/after expiry (expired).
   Repeat: the expiration history must remain one system transition.
4. Open admin Groups → «Снять с публикации» and check the manual-unpublish reminder.
5. Extend another active group through its confirmation. It adds its stored
   placement duration, even when the current global setting differs.
6. Extend an expired group within its window. It becomes approved with old dates;
   admin detail shows «Продление» and manual re-publication instructions. Activate
   again: a new period uses the current placement-duration setting.
7. Change the current owner tariff after group creation. A historically paid
   group can extend free with a currently free owner; a historically free group
   cannot extend with a currently paid owner. Historical group tariff is preserved.
8. Outside the extension window, check create-new behavior and direct POST denial.
   An overdue active row also rejects extension until the scheduler expires it.
9. Verify payment/job counts unchanged and check list/detail/extension/confirmation
   pages at 1440, 1024 and 390 px.

Run focused MySQL tests with `php artisan test --filter=GroupLifecycle` inside
Docker. The concurrency tests use committed disposable test fixtures and two
independent PHP processes against MySQL, including a candidate whose expiry
changes while workers wait for its row lock. Browser verification uses external
tools; keep scripts/screenshots outside the repository.

## Stage 10 applications and retention

Create only clearly synthetic fixtures locally. Stage 11 now provides signed
participant intake (see below). A local PHP script bootstrapped through the console kernel can use:

```php
$group = App\Models\Group::query()->where('owner_id', $syntheticOwner->id)->findOrFail($syntheticGroupId);
App\Models\GroupApplication::factory()->for($group)->count(23)->create();
App\Models\GroupApplication::factory()->for($group)->processed()->create();
```

The factory requires an existing synthetic group and uses fictional +1 202
555-01xx phone numbers without Faker or any new dependency. Never use real
participant data or add fixture seeding to production. Keep temporary scripts
and screenshots outside Git; remove only the smoke fixture IDs afterward.

Manual verification with two approved synthetic psychologists and separate groups:

1. Login as the first owner: verify new/processed/all counters on group list and
   detail, then open the group's applications. Check filters, 20-row pagination,
   detail and process/unprocess; repeat each action to verify idempotency.
2. Substitute the other owner's group/application IDs and mismatched group IDs:
   all owner reads/actions must return 404. Admin accounts cannot use owner routes.
3. Login as admin, use «Заявки»: search name, formatted/normalized phone, group,
   psychologist name/email; combine search with processed filters and pagination.
   Open detail and its real group/profile links. There are no admin mutations.
4. In an isolated local fixture run, freeze Carbon's UTC clock, calculate the
   current retention cutoff with subMonthsNoOverflow, and create rows at cutoff
   minus one second, exact cutoff, and plus one second. Include processed/new
   rows and a soft-deleted synthetic parent. Run the command twice under the same
   clock: only strictly older rows disappear, then the aggregate count is zero.
   Do not run this fixture cleanup against unrelated data eligible for deletion.
5. Compare groups/users/payments/history/jobs before and after; verify no mail or
   queued work. Inspect representative owner groups/applications/detail and admin
   list/detail at 1440, 1024 and 390 px, including long text and empty results.

```bash
docker compose exec -T php php artisan test --filter=Application
docker compose exec -T php php artisan applications:cleanup
docker compose exec -T php php artisan schedule:list
```

Cleanup is permanent and reports `Deleted applications: N` only. Its daily
schedule has overlap protection; the existing every-minute group expiry schedule
is unchanged. Stage 11 intake is documented below.

## Stage 11 signed intake

The full public-site contract, signing helper and synthetic curl examples are in
[the integration guide](integration.md). Local endpoints use
`http://localhost:8080/cabinet/api/v1/{psychologists,group-applications}`.
Use `php artisan migrate --seed` (non-destructive) for the request journal.

Configure `INTEGRATION_SECRET` outside version control. For manual testing,
choose a temporary synthetic value interactively in your local shell:

```bash
read -rsp 'Local synthetic integration secret: ' INTEGRATION_SECRET; echo
export INTEGRATION_SECRET
```

Provision that same value through the receiving PHP/FPM environment or ignored
local `.env`; an exported host variable alone does not change running FPM.
Reload cached configuration as appropriate, and remove the temporary value after
testing. Do not print it in command output, reports or logs. Defaults are
`INTEGRATION_TIMESTAMP_TOLERANCE=300`, `INTEGRATION_RATE_PER_MINUTE=60`, and empty
`INTEGRATION_ALLOWED_IPS` (comma-separated exact IPs when enabled). See the guide
for trusted-proxy/shared-cache prerequisites and fail-closed behavior.

Keep standard `enable_post_data_reading=1`. Existing PHP limits are 10 MiB/file
and 12 MiB total POST; Nginx also limits total requests to 12 MiB. Multiple small
files work; keep the entire multipart request below the total limit. Do not
change global upload parsing or build a raw multipart parser. Sign the payload
manifest and include actual byte size/SHA-256 for each file.

```bash
docker compose exec -T php php artisan test --filter=IntegrationIntakeTest
docker compose exec -T php php artisan test --filter=IntegrationConcurrencyTest
docker compose exec -T php php artisan route:list --path=api -vv
```

Concurrency tests use separate PHP processes and the dedicated MySQL testing DB;
run database test suites sequentially. For runtime smoke checks, use disposable
synthetic accounts/groups/documents, inspect actual owner/admin pages, replay
IDs and compare journal/file counts. Inspect only newly produced log records for
redaction. Clean only your smoke IDs/files and restore temporary configuration.
No public-site repository changes, emails or payment operations are part of this
verification.
