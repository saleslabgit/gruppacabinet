# Report: TASK-2026-09-21-13

Status: done

## Summary

Исправлены WEBPAY admin group flows и подготовлен runtime baseline для shared
PHP hosting. Обязательные локальные проверки завершены успешно.

## Changed Files

- GroupPolicy, admin GroupController, GroupIndexRequest: старые awaiting_payment
  и draft входят в abandoned; фильтр successful_payment=yes/no использует EXISTS
  succeeded + refunded_at IS NULL, включая soft-deleted историю платежей.
- Admin group index, shared group-summary: реальный фильтр и актуальный текст
  ожидания оплаты. Уже существующая ссылка на последний placement payment сохранена.
- Additive cache/cache_locks migration, .env.example: database cache baseline.
- DeploymentPreflight command/support: безопасная CLI-диагностика, ненулевой код
  при blockers, только временные технические cache/lock probes; без SMTP/WEBPAY
  запросов и изменения бизнес-данных. Проверяются также production ext-* из lock.
- docker/php/Dockerfile: pcntl для исполнения worker timeout.
- PaymentEraGroupsTest, DeploymentPreflightTest, SharedHostingRuntimeTest,
  GroupWorkflowTest: границы возраста/прав/оплат, фильтры и query counts, UI,
  redaction, независимый PHP process для locks, конечный worker и /cabinet URLs.
- docs/deployment.md, development.md, webpay.md, ui-pages.md, project-status.md:
  shared-hosting layout, artifact/vendor, cron, discovery, WEBPAY notify prerequisite.

## Checks

Команды запускались через Windows `docker.exe compose`: Linux docker в WSL не
имел рабочего daemon. Docker Desktop запущен, volumes сохранены; PHP и worker
образы пересобраны с pcntl.

- `docker.exe compose ps`: PHP/MySQL/Mailpit healthy, web/queue-worker running.
- Additive migrate/seed выполнены без сброса retained DB; `migrate:status`:
  все 5 migrations Ran, включая cache tables (batch 5).
- Начальный focused correction/runtime прогон: 9 passed, 1 failed — отсутствовал
  pcntl. Добавлен в image; конечный worker и cross-process locks проходили.
- `CACHE_STORE=database php artisan test --filter='Integration|PasswordSetup|ExpiryWarning|Webpay|PaymentEra|DeploymentPreflight|SharedHosting|GroupWorkflow'`:
  118 passed, 2 failed (3421 assertions). Оба сбоя в новом тесте preflight:
  повторное чтение Artisan output очищало буфер; cache driver был создан до
  смены конфигурации missing-table fixture. Исправлены capture output и forgetDriver.
- Повтор `CACHE_STORE=database ... --filter=DeploymentPreflightTest`:
  **3 passed, 29 assertions**, включая synthetic production config, отсутствие
  secret в stdout, отсутствие business writes/mail/HTTP и cleanup probes.
- `php artisan deployment:preflight` на локальном стенде: exit 0, PHP 8.2.32,
  MySQL 8.4.11, cache read/write и lock exclusion PASS. Отсутствующие локальные
  integration/WEBPAY credentials показаны только как missing; production требует их.
- Larastan `./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`:
  **No errors** после уточнения типов проверок.
- `composer check-platform-reqs`: все требования satisfied.
- `php artisan view:cache`: успешно.
- Route inspection: POST webpay/notify зарегистрирован;
  `APP_ENV=production ... route:list --path=_prototype`: matching routes отсутствуют.
- `./vendor/bin/pint --test`: **172 files PASS**.
- `git diff --check`: без ошибок; task/SPEC/WORKFLOW/AGENTS не изменены.
- Payment routes проверены: owner show/start/retry/return/cancel, admin index/show/refund.
- Повторный `migrate --seed --no-interaction`: Nothing to migrate, Seeding database, exit 0.
- `docker.exe compose exec -T -e CACHE_STORE=database php php artisan test --compact`:
  **407 passed, 6896 assertions, 522.19 s**. Включает Stage 11/12, WEBPAY
  focused/concurrency, group workflows, новые correction/runtime/preflight tests,
  весь catalogue **31 pages / 249 variants** и production isolation.
- Полный diff просмотрен; staged review выполнен перед commit. В изменениях
  только 20 файлов задачи, без credentials, runtime .env, logs, vendor или artifacts.

## Facts

Начальный working tree чистый. HEAD planner 7731549, предыдущий e11fc81;
проверен исходный implementation base 094e94e1734707cdf9c607c989eaa03461da2893.
Задача/SPEC/WORKFLOW/AGENTS не изменены. Owner deletion rules, browser callback
trust и signed merchant binding не изменены. SQL фильтра не добавляет per-row
payment queries; normal list regression сохранена.

Конечный реальный Artisan worker с --stop-when-empty --tries=3 --timeout=45
--max-time=50 обработал password setup, expiry warning и trusted-bound recovery
с fake mail/HTTP; 3 jobs → 0, failed_jobs=0, locks=0, по одному эффекту.
Повторные scheduler commands не создают новых jobs. Независимый PHP process
не получает удерживаемый database lock и получает его после release.

Официальная документация WEBPAY повторно проверена:
https://docs.webpay.by/paymentIntegration/cardIntegration/paymentNotification/
По умолчанию notify только для успеха; unsuccessful notifications требуют
запроса поддержки. Документация сохраняет pending/manual-review fallback,
не разрешает browser cancel или standalone API result менять статус без binding.
Laravel cache/queue/scheduling docs получены через Context7; schema взята из
установленного Laravel migration stub.

## Assumptions

Shared hosting допускает MySQL и cron; реальные возможности должны быть
подтверждены discovery checklist. Абсолютные пути в deployment docs — placeholders.
Файловая структура и UI baseline сохранены; новая визуальная модель не вводилась.

## Unknowns

Не проверены реальный HostER account, web/CLI PHP и extensions, SSH/Composer,
cron interval/runtime, symlink/rewrite permissions, SMTP/HTTPS/WAF и реальные
WEBPAY credentials/notify/API/refund. Preflight не проверяет внешнюю доставку.
Локальные HTTP feature tests проверяют rendered Blade/action/authorization;
ручная браузерная responsive-проверка в этой задаче не выполнялась.

## Risks / Next Step

Перед внешним запуском выполнить docs/deployment.md и WEBPAY staging checklist.
Worker max-time проверяется между jobs: hosting limit должен позволять завершить
in-flight job; pcntl и timeout < retry_after обязательны. Сборка production artifact
описана с vendor из composer.lock, target platform check обязателен перед activation.
Никаких реальных платежей, production deployment или изменения финансового
статуса вручную не выполнялось.
