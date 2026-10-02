# Report: TASK-2026-10-02-01

Status: done

## Summary

Внесена корректировка timing privacy после review `e93689b`.
Неизвестный или недоступный аккаунт после успешной проверки синтаксиса email
выполняет один `app('hash')->make(Str::random(64))`: тот же контейнерный framework
hasher и настроенная стоимость, которые получает DatabaseTokenRepository.
Случайное значение и результат сразу отбрасываются; fake user, token row, job,
новые логи или искусственные задержки не добавлены.

Ветка доступного аккаунта по-прежнему вызывает существующий `invite()`.
Broker, row lock, token replacement, rollback, очередь, TTL, eligibility,
сессии, rate limits и публичные ответы не изменены.

Текущие сводки каталога исправлены на 32 группы / 263 варианта. Исторические
описания состава старых этапов не переписывались.

## Changed Files

- `application/app/Http/Controllers/PasswordRecoveryController.php` — три строки
  для dummy hash в неизвестной/недоступной ветке.
- `application/tests/Feature/PasswordRecoveryTimingTest.php` — 19 deterministic
  HTTP tests: восемь состояний × bcrypt/Argon2id, malformed/throttle, свежесть
  dummy values, rollback и безопасная диагностика при отказе очереди.
- `docs/ui-pages.md`, `docs/project-status.md` — актуальные сводки 32/263,
  включая текущие формулировки local/testing и повторного использования Blade.
- `docs/email.md` — механизм одинаковой криптографической работы и его границы.
- `.ai/report.md` — отчёт этой корректирующей итерации.

## Checks

Изолированная копия `/tmp/timing-check` локального PHP 8.2.32 контейнера;
MySQL 8.4, выделенная `gruppa_cabinet_test`. Исходники смонтированы read-only.
Env/private/storage/cache файлы проекта исключены из копирования; использован
пустой тестовый `.env`. DB-настройки из phpunit.xml и синтетический APP_KEY
переданы через environment, в том числе дочерним PHP-процессам.

- `php artisan test --compact --filter=PasswordRecoveryTimingTest --log-junit=/tmp/timing-focused.xml`
  — **19 passed, 295 assertions**, 9.12 s.
- `php artisan test --compact --filter="PasswordRecoveryTest|PasswordSetupTest|AuthenticationTest|PsychologistAdminTest|PrototypeTest|DeploymentPreflightTest|ExpiryWarningTest|SharedHostingRuntimeTest" --log-junit=/tmp/timing-regression.xml`
  — **136 passed, 2619 assertions**, 78.50 s.
- `php artisan test --compact --log-junit=/tmp/timing-full.xml`
  — **735 passed, 8143 assertions**, 679.74 s; failures/errors/skips: **0/0/0**.
  Выполнены все классы, без exclusions.
- `php vendor/bin/pint --test` — **PASS, 214 files**.
- `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — **No errors**.
- `composer check-platform-reqs` — все требования **success**.
- `composer validate --no-check-publish` — **composer.json is valid**.
- `php artisan view:cache` — **Blade templates cached successfully**.
- `php artisan route:list --path=password -v` — **PASS**, семь прежних routes,
  включая recovery/setup/admin и два prototype routes; middleware сохранены.
- `CACHE_STORE=array php artisan schedule:list` — **PASS**, пять прежних команд;
  scheduled commands не запускались.
- `git diff --check` / `git diff --cached --check` — exit 0. Полный diff и
  состав staged-файлов проверены: только текущая корректировка и отчёт, без
  env/private/production data, tokens, queue dumps, captures, logs/cache/vendor
  и временных артефактов. App/tests/routes/Blade/bootstrap тестовой копии
  побайтово совпали с исходниками (diff -qr/cmp).

Полные классы из финального JUnit (failures/errors/skips везде 0/0/0):

| Класс | Tests | Assertions |
|---|---:|---:|
| PasswordRecoveryTimingTest | 19 | 295 |
| PasswordRecoveryTest | 23 | 254 |
| PasswordSetupTest | 22 | 270 |
| AuthenticationTest | 25 | 208 |
| PsychologistAdminTest | 15 | 263 |
| PrototypeTest | 10 | 1207 |
| DeploymentPreflightTest | 20 | 152 |
| ExpiryWarningTest | 18 | 239 |
| SharedHostingRuntimeTest | 3 | 26 |

Новые тесты наблюдают вызовы реального framework hash manager через proxy,
делегируя actual make настроенным Laravel bcrypt/Argon2id drivers. Проверены
один вызов make, отсутствие per-call overrides и metadata фактических хешей
(bcrypt cost 6 либо Argon2id memory/time/threads 1024/2/1 в тестах). Broker получает
тот же объект hasher. Для доступного аккаунта наблюдаемый hash сохранён в реальном
password_reset_tokens и работает через broker; для остальных states записей/jobs нет.
Проверены свежесть dummy input, отсутствие dummy input/hash в сессии и логах,
отказ очереди с сохранением старого токена и прежним публичным ответом.
Wall-clock thresholds и sleep-based assertions не применялись.

Полные regression-классы проверяют первые приглашения, замену существующего
пароля, login старым/новым паролем, одноразовость, отзыв целевых сессий,
CSRF/policy, base-path URLs, mail transports и generic body/status/headers.
Все 263 prototype states проверены серверным рендерингом.

## Facts

- Исходный HEAD `b42ecbc` — corrective planner; parent
  `e93689ba2e81aad6c990c38cb1d2ae67eba0e248` подтверждён; дерево было чистым.
- Прочитаны WORKFLOW/AGENTS/task/report, изучены исходный implementation diff,
  controller/service, установленный DatabaseTokenRepository/HashManager,
  recovery tests, каталог и связанные документы.
- `.ai/task.md`, AGENTS, WORKFLOW, Blade/CSS, broker, policy, limiter и session
  implementation не изменены; пакетов и миграций не добавлено.
- **Реальных внешних писем не отправлялось.** Тесты используют mail/HTTP fakes;
  реальных SMTP/sendmail/MODX/WEBPAY вызовов не выполнялось.
- Production/private env, включая `.env_save`, не читались и не изменялись.

## Assumptions

Дополнительных продуктовых предположений нет; применён preferred design задачи.

## Unknowns

Live email/integration delivery не проверялась: внешние вызовы запрещены задачей.
Изменений интерфейса нет; отдельный browser smoke для этой коррекции не проводился.

## Risks / Next Step

Обязательные проверки пройдены; корректировка готова к task commit.
Коррекция устраняет различие дорогой криптографической работы; она не обещает абсолютного равенства времени DB/queue/network операций
или инфраструктурных отказов.
