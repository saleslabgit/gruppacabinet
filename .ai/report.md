# Report: TASK-2026-09-24-01

Status: done

## Summary

Реализовано упрощение Stage 11 intake: shared secret/HMAC полностью удалены из
runtime-протокола и deployment preflight. Оба endpoint публичные; `X-Request-Id`
остаётся обязательным несекретным ключом идемпотентности. Файлы принимаются без
клиентского manifest, их метаданные и SHA-256 вычисляются сервером.

Все обязательные проверки прошли. Пользователь явно утвердил стандартный
PHP/Laravel multipart parsing и наблюдаемую Laravel-границу проверки. Требование
выполнено в этой границе; raw multipart parser и изменения инфраструктуры не нужны.
Известное ограничение схлопнутых PHP дублей зафиксировано ниже и в документации.

## Changed Files

- `application/app/Http/Middleware/AuthenticateIntegration.php` — удалён;
  `application/app/Http/Middleware/ValidateIntegrationRequest.php` — замена:
  request ID, Content-Type, optional IP allowlist, запрет query parameters.
- `application/routes/api.php` — подключена переименованная middleware.
- `application/app/Integration/IntakeData.php` — direct questionnaire payload,
  flat file allowlist, серверные metadata/hash, безопасные 422 upload errors.
- `application/config/integration.php` — только rate limit и optional IPs.
- `application/.env.example` — удалены secret/timestamp tolerance переменные.
- `application/app/Support/DeploymentPreflight.php` — убран secret gate Stage 11;
  WEBPAY и mail checks сохранены.
- `application/bootstrap/app.php` — обновлён только комментарий API normalization.
- `application/tests/Feature/IntegrationIntakeTest.php` — новый протокол, документы,
  идемпотентность, валидация, лимиты, business matrix и безопасные логи.
- `application/tests/Feature/IntegrationConcurrencyTest.php` и
  `application/tests/Support/integration-worker.php` — unsigned concurrent requests,
  прямой questionnaire JSON без manifest.
- `application/tests/Feature/DeploymentPreflightTest.php` — preflight без intake secret.
- `docs/integration.md` — новый контракт, curl, trust model, parser limitation.
- `docs/project-status.md` — актуальное состояние Stage 11.
- `docs/architecture.md` — новая middleware и публичная граница API.
- `docs/development.md` — локальная проверка без секретов/подписей.
- `docs/deployment.md` — удалено требование integration secret.
- `SPEC.md` — узкие изменения intake/security/acceptance формулировок.
- `.ai/report.md` — этот отчёт.

## Public API Contract

- `POST /api/v1/psychologists`: multipart/form-data, ровно одно скалярное поле
  `payload` с JSON-объектом анкеты напрямую. Требуются email,
  personal_data_consent_at и personal_data_consent_version; прежние nullable
  поля, нормализация, диапазоны, protected-field rejection сохранены.
- Необязательные flat files: `diploma`, `certificate_0`, `certificate_1`, …,
  `license`, `registration`. Certificate indices — decimal без ведущих нулей,
  допускаются пропуски. Ноль документов допустим. Нет `questionnaire` wrapper,
  `documents` manifest, client descriptors/size/SHA-256.
- Тип определяется по имени поля, original name берётся из загрузки и очищается,
  размер/MIME/content SHA-256 определяются сервером. MIME/размер/private storage
  policy прежние. Массивы, неверные/неизвестные поля и failed uploads дают 422.
- `POST /api/v1/group-applications`: прежний JSON только с group_uuid, last_name,
  first_name, phone; group UUID/status/disabled checks и phone normalization прежние.
- На обоих endpoint обязателен `X-Request-Id` с прежним case-sensitive синтаксисом
  1–128 ASCII символов. Same ID + same semantics возвращает исходный ответ;
  изменённые данные/файлы — 409 idempotency_conflict.
- Удалены `INTEGRATION_SECRET`, `INTEGRATION_TIMESTAMP_TOLERANCE`, обязательность
  `X-Timestamp`/`X-Signature`, signing text/HMAC и timestamp-window checks.
  Legacy signature/timestamp headers игнорируются. Authentication-only errors
  больше не генерируются Stage 11. WEBPAY invalid_signature сохранён.
- `INTEGRATION_RATE_PER_MINUTE` остаётся, `INTEGRATION_ALLOWED_IPS=` по умолчанию
  отключает allowlist. Ни одного нового секрета не введено.

## Checks

Локальный Docker, PHP 8.2.32 / dedicated MySQL `gruppa_cabinet_test`.
Существующие mysql/php/mailpit/web services запущены. Runtime .env не читался и
не менялся. Sandbox launcher недоступен из-за host mount /mnt/wslg/distro;
команды выполняются через разрешённый escalation. Первоначальный auto-review
запретил security-boundary change; после прямого «Даю согласие» пользовательское
разрешение получено, изменения выполнены без обхода rejection.

Команды ниже выполнялись с префиксом `docker.exe compose exec -T`:

- `php php artisan test --compact --filter=IntegrationIntakeTest`:
  18 passed, 363 assertions, 20.06 s (первый focused run).
- `-e CACHE_STORE=database php php artisan test --compact --filter='Integration|PasswordSetup|ExpiryWarning|SharedHostingRuntime|DeploymentPreflight|Webpay'`:
  114 passed, 3261 assertions, 186.35 s. Stage 11/12, deployment/shared runtime,
  WEBPAY/signature/concurrency regressions пройдены без реальных внешних запросов.
- `-e CACHE_STORE=database php php artisan test --compact --filter=IntegrationIntakeTest`:
  18 passed, 383 assertions, 23.26 s после дополнительных checks обоих endpoints.
  Финальный повтор после решения пользователя: **18 passed, 383 assertions, 21.97 s**.
  После полного MySQL/Pint/Larastan run менялась только документация и отчёт;
  runtime/test code не менялся, повтор полного набора не требовался.
- `-e CACHE_STORE=database php php artisan test --compact`:
  **441 passed, 7358 assertions, 568.43 s**; полный MySQL-набор.
- `php ./vendor/bin/pint --test`: PASS, 172 files.
- `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: No errors.
- `php composer check-platform-reqs`: все требования success, PHP 8.2.32.
- `php php artisan view:cache`: Blade templates cached successfully.
- `git diff --check`: успешно.
- Временный PHP HTTP parser probe: два `payload` и два `diploma` в исходном
  multipart превращаются в единственный последний payload и diploma в $_POST/$_FILES.
  Probe сервер/файлы удалены; бизнес-БД не затрагивалась.
- Реальный HTTP multipart smoke на временном PHP server с dedicated test DB:
  **201**, pending user, 1 diploma, server-detected application/pdf несмотря на
  client Content-Type text/plain, файл существует в private root. Без signature,
  timestamp или manifest. Внешняя транзакция откачена; сервер и private files удалены.
- Итоговый diff/artifact review: проверены все файлы задачи, .env/credentials,
  logs/cache/vendor/fixtures и сторонних файлов нет. Staged review завершён:
  19 paths только текущей задачи, git diff --cached --check успешен, добавленные
  строки проверены на credential patterns. Решение о parser limitation получено;
  результат подготовлен к codex-коммиту.

## Facts

Стартовый working tree чистый. HEAD a606d8b — planner текущей задачи, parent
6a62a7de22e4f93c42ec8383050e6dcbe9e6b4d4. .ai/task.md не менялся.
Новых зависимостей, миграций, UI/build изменений нет. IntakeService, business
transitions, приватное хранилище, login, mail jobs и WEBPAY runtime не менялись.

Context7 использован для Laravel upload validation; выдача /laravel/docs относится
к 13.x, поэтому фактический parsing дополнительно сверялся с установленным
Laravel 12/Symfony FileBag и локальным PHP HTTP probe.

## Assumptions

Публичный сайт сохраняет request ID и логическое содержимое для retries,
использует уникальные flat field names и переходит на новый контракт согласованно
с deployment кабинета. Его код и deployment находятся вне репозитория.

## Unknowns

Публичный сайт/production deployment не проверялись и не изменялись. Реальная
интенсивность спама и достаточность текущих rate limits неизвестны.

## Risks / Next Step

Intake больше не аутентифицирует источник. Validation/rate limiting/optional IP
allowlist не гарантируют отсутствие прямого спама; CAPTCHA/WAF вне scope.
Shared secret/HMAC для Stage 11 не остаётся, секреты WEBPAY не затронуты.

По явному решению пользователя остаётся стандартный PHP/Laravel multipart parsing.
В наблюдаемой Laravel-границе неизвестные file fields, неправильные certificate_N,
неожиданные массивы/структуры и observable duplicate/ambiguous structures отклоняются
с 422. Все реально видимые Laravel файлы проходят серверную валидацию; поля формы
и файлов проверяются раздельно, поэтому смешанные file/scalar names не обходят
allowlist (payload допустим только как единственное скалярное поле).

Duplicate multipart field names, которые PHP схлопывает до попадания запроса в
Laravel, не могут надёжно детектироваться на application layer. PHP также может
нормализовать исходные имена. Это известное и принятое ограничение, подтверждённое
HTTP probe; 422 для уже схлопнутых raw duplicates не гарантируется. Отдельный raw
multipart parser или изменение инфраструктуры не делаются и не требуются для
приёмки этой задачи.
