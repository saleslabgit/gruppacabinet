# Report: TASK-2026-09-23-01

Status: done

## Summary

Реализована поддержка SMTP и явно настроенного sendmail для обоих почтовых jobs.
Добавлены безопасные started/accepted_by_transport/failed события; queued для
password setup пишется только после успешного database insert и commit внешней
транзакции. Все обязательные локальные проверки прошли, включая полный MySQL-набор.

## Changed Files

- `application/app/Jobs/SendPasswordSetup.php`: allowlist smtp/sendmail,
  техническая диагностика; прежние token/eligibility/no-op/retry проверки сохранены.
- `application/app/Jobs/SendExpiryWarning.php`: тот же allowlist и диагностика;
  ShouldBeUnique, ключ, retries и locked marker recheck сохранены.
- `application/app/Services/PasswordSetupService.php`: queued после получения
  database job ID и afterCommit; beforeCommit dispatch и атомарность токена/job сохранены.
- `application/app/Support/DeploymentPreflight.php`: общий mail check,
  проверка sendmail command syntax и protected executable capability detector.
- `application/app/Console/Commands/DeploymentPreflight.php`: уточнение общей
  mail delivery формулировки вместо SMTP-only reachability.
- `application/tests/Feature/PasswordSetupTest.php`: транспорты, безопасные
  события/исключения, sendmail resend, отсутствие queued при failure/rollback.
- `application/tests/Feature/ExpiryWarningTest.php`: транспорты, события,
  redaction, marker и повторный no-op.
- `application/tests/Feature/DeploymentPreflightTest.php`: capability injection,
  invalid/missing/non-executable command, filesystem fixture без исполнения,
  redaction, отсутствие реальной отправки; прежние PASS/WARN/FAIL проверки сохранены.
- `application/.env.example`: опциональный MAIL_SENDMAIL_PATH и SMTP-only
  значение MAIL_TIMEOUT; default остаётся smtp/Mailpit.
- `docs/email.md`: mail transports, диагностика, queue/acceptance semantics.
- `docs/deployment.md`: sendmail configuration, preflight grammar, PCNTL/process
  safety gate и manual HostER verification.
- `docs/development.md`: сохранён local SMTP/Mailpit, описаны sendmail fakes.
- `docs/project-status.md`: состояние mail portability и внешние неизвестные.
- `SPEC.md`: узкие mail transport формулировки SMTP/sendmail и acceptance boundary.
- `.ai/report.md`: этот отчёт.

## Checks

Команды выполнены на существующем локальном Docker-стенде через
`docker.exe compose exec -T`; PHP 8.2.32 / MySQL 8.4.11. Docker Desktop был остановлен,
запущен вместе с существующими сервисами. Runtime .env, Docker image и schema не менялись.
Sandbox launcher недоступен из-за host mount /mnt/wslg/distro; команды выполнялись
через разрешённый escalation.

- `-e CACHE_STORE=database php php artisan test --compact --filter='PasswordSetupTest|ExpiryWarningTest|DeploymentPreflightTest'`:
  **58 passed, 641 assertions, 28.89 s** (до дополнительного outer-transaction теста).
- `-e CACHE_STORE=database php php artisan test --compact --filter='SharedHostingRuntimeTest|Webpay|Integration|PasswordSetup|ExpiryWarning'`:
  **93 passed, 2984 assertions, 183.57 s**. Проверены Stage 11/12, WEBPAY/concurrency,
  cross-process database lock и реальный finite worker с --stop-when-empty --tries=3
  --timeout=45 --max-time=50 (fake SMTP/HTTP): jobs 3→0, failed_jobs=0, locks=0,
  повторный dispatch не дублирует эффекты.
- `-e CACHE_STORE=database php php artisan test --compact`:
  **441 passed, 7216 assertions, 527.64 s**. Включён дополнительный тест:
  успешный insert внутри outer transaction не пишет queued до commit, rollback
  удаляет job/token и не оставляет queued событие.
- `php ./vendor/bin/pint --test`: **172 files PASS**.
- `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: **No errors**.
- `php composer check-platform-reqs`: все требования satisfied.
- `php php artisan view:cache`: успешно.
- `php php artisan deployment:preflight`: exit 0; `PASS Mail delivery configured: smtp`,
  PCNTL available, MySQL 8.4.11, database cache/locks PASS. Это local environment:
  production secrets/HTTPS gates проверены тестами, а не приняты по local exit 0.
- Первоначальный `php ./vendor/bin/pint --dirty` не выполнился: контейнер не содержит
  .git; заменён успешным `pint --test`, никаких правок этим запуском не сделано.
- `git diff --check`: без ошибок; Git сообщает лишь о нормализации CRLF в SPEC.md.
- Полный diff проверен: только 15 файлов задачи. Runtime .env, logs, queue payloads,
  credentials, vendor/cache и посторонние artifacts не включены; перед commit
  выполнены git status, staged-file review и git diff --cached --check.

## Facts

Стартовый working tree чистый; HEAD ae99fa0 — planner задачи, parent равен
1d6b9b21e2ee6e2ed756f4e505f0d18cfd45b62c; между ними изменён только task.md.
.ai/task.md и WORKFLOW/AGENTS не изменялись. Нет новых зависимостей, миграций,
UI/build, WEBPAY или lifecycle изменений.

Framework queue документация получена через Context7 (/laravel/docs, выдача 13.x),
семантика сверена с установленным Laravel 12 Queue/DatabaseQueue и Symfony
SendmailTransport. Dispatch beforeCommit возвращает ID успешной вставки;
afterCommit callback диагностики не переносит и не заменяет сам dispatch.

Контекст логов содержит только user_id/group_id, transport (smtp/sendmail/unsupported),
attempt и exception_class при ошибке; queued — только user_id. Не пишутся mailer
credentials, recipient, token, setup URL, command/path/output, body/MIME или payload.
Замещающие RuntimeException не содержат raw message и previous exception.
Accepted событие предупреждения предшествует существующему locked marker recheck:
оно не означает изменение маркера для продлённого периода.

Sendmail preflight принимает простой абсолютный путь и простые флаги, включая
-bs либо -t; shell syntax, relative/quoted/space-containing paths отвергаются.
Метод capability использует только is_file/is_executable. Приватные пути не выводятся.
Автотесты используют Laravel Mail fakes и capability injection/временный fixture;
никаких реальных внешних email или WEBPAY запросов не отправлено. SMTP/Mailpit
configuration сохранена, реальный Mailpit delivery smoke в этой итерации не запускался.
В текущем PHP-контейнере /usr/sbin/sendmail уже исполним по filesystem check;
он не запускался и не устанавливался для задачи. Тесты от его наличия не зависят.

## Assumptions

Настроенный hosting sendmail-compatible binary предполагается разрешённым оператором.
Проверка файла не доказывает доступ к downstream relay или возможность inbox delivery.
MAIL_TIMEOUT ограничивает только SMTP socket, не локальный sendmail process.
Без PCNTL требуется подтверждённая host process-runtime/retry_after safety gate.

## Unknowns

HostER downstream delivery, MTA queue/log access, SPF/DKIM/DMARC/reputation и
последующий defer/reject/relay остаются внешними неизвестными. Указанные задачей
HostER PCNTL available и MTA 250 OK не перепроверялись удалённо и не доказывают inbox delivery.

## Risks / Next Step

После deployment оператор выполняет manual staging verification:

1. В private runtime .env задать MAIL_MAILER=sendmail, проверенный MAIL_SENDMAIL_PATH,
   реальные MAIL_FROM_ADDRESS/NAME, QUEUE_CONNECTION=database, LOG_CHANNEL=single,
   LOG_LEVEL=info; никаких секретов в Git.
2. `php artisan optimize:clear`, затем `php artisan deployment:preflight`;
   проверить `PASS Mail delivery configured: sendmail` и остальные hard gates.
3. Наблюдать private storage/logs/laravel.log; выполнить один разрешённый resend
   одобренному психологу без пароля и увидеть mail.password_setup.queued.
4. Запустить `php artisan queue:work database --once --tries=3 --timeout=45`
   проверенным CLI PHP; без PCNTL сначала выполнить process-runtime/retry_after gate.
5. Проверить mail.password_setup.started и accepted_by_transport либо failed;
   при необходимости `php artisan queue:failed`, не публикуя payload.
6. При accepted и пустом inbox продолжить через HostER MTA/Exim facilities/support
   по hosting-side message ID. Не менять Laravel business state для имитации доставки.

HostER настройки/отправки не выполнялись. Accepted означает лишь application transport
acceptance; окно повторного письма при crash перед marker commit остаётся прежним.
