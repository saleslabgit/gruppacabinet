# Report: TASK-2026-09-21-14

Status: done

## Summary

Отсутствие PCNTL переведено из hard failure в явный WARN capability. Только FAIL
влияет на exit status preflight. Остальные обязательные проверки не ослаблены.
Документация описывает безопасный no-PCNTL cron fallback и operator acceptance gate.
Все обязательные локальные проверки завершены успешно.

## Changed Files

- `application/app/Support/DeploymentPreflight.php`: status pass/warn/fail;
  PCNTL available/unavailable; protected capability detector для детерминированной
  подстановки в тестах, без изменения окружения или отключения расширения.
- `application/app/Console/Commands/DeploymentPreflight.php`: вывод уровня
  проверки; exit 1 только при fail или неожиданной ошибке.
- `application/tests/Feature/DeploymentPreflightTest.php`: native/available/
  unavailable capabilities; WARN + hard FAIL; redaction и отсутствие побочных
  эффектов проверяются также без PCNTL.
- `docs/deployment.md`: preferred PCNTL path, no-PCNTL finite worker, транспортные
  и DB limits, host process bound, retry_after с запасом и gate без предположений
  о лимитах HostER.
- `docs/development.md`, `docs/email.md`: согласованы описание optional PCNTL
  и актуальное наличие расширения в local image. В email.md устранено устаревшее
  утверждение, что local image не содержит PCNTL и production обязательно требует его.
- `.ai/report.md`: этот отчёт.

## Checks

Команды выполнены через `docker.exe compose exec -T` на существующем локальном
стенде PHP 8.2.32 / MySQL 8.4.11. Миграции, Docker image и runtime .env не менялись.

- `-e CACHE_STORE=database php php artisan test --filter='DeploymentPreflightTest|SharedHostingRuntimeTest'`:
  **8 passed, 85 assertions, 44.05 s**.
- В этом прогоне реальный finite Artisan worker с --stop-when-empty --tries=3
  --timeout=45 --max-time=50 обработал password setup, expiry warning и bound
  WEBPAY recovery с fake SMTP/HTTP. Jobs 3→0, failed_jobs=0, unique locks=0,
  повторный dispatch не дублирует эффекты; cross-process database lock проверен.
- `php php artisan deployment:preflight`: exit 0, реальная строка
  `PASS CLI worker hard timeout (pcntl): available`; cache/locks PASS.
- `php ./vendor/bin/pint --test`: **172 files PASS**.
- `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: **No errors**.
- `php composer check-platform-reqs`: все требования satisfied.
- `php php artisan view:cache`: успешно.
- `-e CACHE_STORE=database php php artisan test --compact --filter='Webpay|Integration|PasswordSetup|ExpiryWarning'`:
  **74 passed, 2724 assertions, 143.09 s**; WEBPAY focused/concurrency, Stage 11/12.
- `-e CACHE_STORE=database php php artisan test --compact`:
  **409 passed, 6700 assertions, 575.29 s** — полный MySQL-набор, включая
  WEBPAY concurrency, Stage 11/12, prototypes и production isolation.
- `git diff --check`: без ошибок.
- Полный diff и staged-файлы проверены: только 7 файлов задачи; secrets,
  runtime .env, logs, caches, vendor и посторонние artifacts не добавлены.

## Facts

Стартовый working tree чистый. HEAD c83717b — planner этой задачи. Точно
подтверждён base 4e425eb932e4bcbea6aebbc4fe9b9eca11b952be; между base и planner
изменён только task.md. Task/SPEC/WORKFLOW/AGENTS не редактировались.

Изучен установленный Laravel 12 Worker.php: supportsAsyncSignals проверяет
extension_loaded('pcntl'); daemon условно регистрирует timeout handler, но
выполняет runJob независимо от PCNTL. stopIfNecessary проверяет maxTime и
stopWhenEmpty между jobs. Документация очередей получена через Context7
(/laravel/docs; выдача 13.x сверена с установленным кодом 12.x).

SMTP timeout — MAIL_TIMEOUT, default 15 s; WEBPAY connect 5 s, request default
15 s с ограничением 1–30; DB_QUEUE_RETRY_AFTER default 90 s. Значения и queue
semantics сохранены. Docker PCNTL, scheduler, mail/payment/group logic не менялись.
В project-status.md PCNTL не назван обязательным, поэтому файл не изменялся.

## Assumptions

Доступность host process bounds не предполагается. Protected detector заменяется
только тестовым подклассом; production читает реальную capability расширения.

## Unknowns

Фактические HostER cron/process/DB limits и нужный retry_after остаются внешними
staging facts. Без PCNTL preflight WARN не означает принятие хостинга. Нативный
runtime без установленного расширения не запускался; missing capability проверена
детерминированно через support layer. Реальные SMTP/WEBPAY запросы не выполнялись.

## Risks / Next Step

Без PCNTL необходимо подтвердить принудительную верхнюю границу времени процесса,
совместимую с jobs и retry_after с запасом; transport timeouts не ограничивают весь
job. Если ни PCNTL, ни безопасной process bound нет, хостинг не принимается до
изменения возможностей. --max-time не прерывает выполняющийся job. Все условия
зафиксированы в deployment.md; sync/HTTP-runner fallback не добавлен.
