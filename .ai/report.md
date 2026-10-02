# Report: TASK-2026-10-02-01

Status: done

## Summary

Закрыта гонка между первой проверкой аккаунта в public recovery controller и
повторной проверкой под row lock в `PasswordSetupService::invite()`.
Сервис возвращает `true` после создания реального broker token и постановки job,
либо `false`, если actorless-вызов при locked reload обнаружил недоступный аккаунт.
Контроллер выполняет один dummy hash при первоначальной недоступности либо таком
`false`; успешная выдача выполняет только реальный broker hash.

Fallback остаётся внутри try: исключение очереди после реального хеширования
не вызывает второй dummy hash. Сохранены rollback, прежний токен, generic response,
admin 404/authorization/validation semantics, audit и post-approval onboarding.
Новых задержек, fake token rows/jobs, логов, пакетов и миграций нет.

## Changed Files

- `application/app/Services/PasswordSetupService.php` — boolean issuance contract
  через результат существующей транзакции, без изменений broker/locking/queue flow.
- `application/app/Http/Controllers/PasswordRecoveryController.php` — dummy fallback
  учитывает фактический результат выдачи после locked recheck.
- `application/tests/Feature/PasswordRecoveryTimingTest.php` — deterministic race
  для bcrypt и Argon2id с реальным сервисом и инструментированным framework hasher.
- `application/tests/Feature/PasswordSetupTest.php` — прямые проверки true/false,
  admin success/audit, authorization/404 и validation exception semantics.
- `docs/email.md` — описание locked-recheck fallback и отсутствия второго hash
  после исключения при выдаче.
- `.ai/report.md` — отчёт текущей итерации.

## Checks

Проверки выполнены в изолированной копии `/tmp/race-check` временного контейнера
`cabinet-race-check`: PHP 8.2.32, MySQL 8.4, выделенная `gruppa_cabinet_test`.
Исходники смонтированы read-only; private env, storage/cache исключены из копирования.
Использован пустой тестовый `.env`, DB-настройки из phpunit.xml и синтетический
APP_KEY переданы через environment, включая дочерние PHP-процессы.

- `php artisan test --compact --filter="locked_recheck|invite_reports|invite_rechecks|invite_keeps_validation" --log-junit=/tmp/race-focused.xml`
  — **10 passed, 113 assertions**, 8.12 s.
- `php artisan test --compact --filter="PasswordRecoveryTimingTest|PasswordRecoveryTest|PasswordSetupTest|AuthenticationTest|PsychologistAdminTest|PrototypeTest|DeploymentPreflightTest|ExpiryWarningTest|SharedHostingRuntimeTest" --log-junit=/tmp/race-regression.xml`
  — **165 passed, 3027 assertions**, 50.96 s.
- `php artisan test --compact --log-junit=/tmp/race-full.xml`
  — **745 passed, 8215 assertions**, 587.78 s; failures/errors/skips **0/0/0**,
  выполнены все классы без exclusions.
- `php vendor/bin/pint --test` — **PASS, 214 files**.
- `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — **No errors**.
- `composer check-platform-reqs` — все требования **success**.
- `composer validate --no-check-publish` — **composer.json is valid**.
- `php artisan view:cache` — **Blade templates cached successfully**.
- `php artisan route:list --path=password -v` — **PASS**, семь прежних routes
  с прежними middleware, включая два prototype routes.
- `CACHE_STORE=array php artisan schedule:list` — **PASS**, пять прежних команд;
  scheduled commands не запускались.
- `git diff --check` / `git diff --cached --check` — exit 0. Тестовая копия app/tests/routes/Blade/bootstrap
  побайтово совпала с исходниками (`diff -qr` / `cmp`).
- Полный diff и staged-файлы проверены: только шесть файлов текущей задачи; проверены отсутствие
  private/env/production data, token/queue dumps, mail captures, logs/cache/vendor
  и временных артефактов, а также отсутствие credential patterns в добавленных строках.

Результаты полных обязательных классов из успешного regression JUnit
(failures/errors/skips везде 0/0/0):

| Класс | Tests | Assertions |
|---|---:|---:|
| PasswordRecoveryTimingTest | 21 | 353 |
| PasswordRecoveryTest | 23 | 254 |
| PasswordSetupTest | 30 | 325 |
| AuthenticationTest | 25 | 208 |
| PsychologistAdminTest | 15 | 263 |
| PrototypeTest | 10 | 1207 |
| DeploymentPreflightTest | 20 | 152 |
| ExpiryWarningTest | 18 | 239 |
| SharedHostingRuntimeTest | 3 | 26 |

Промежуточные результаты не скрыты:

- Первый focused-прогон: 6 failed / 4 passed (107 assertions) из-за неверного
  имени audit-таблицы в новых assertions. Исправлено на существующую модель
  `AuditLog`; повторный focused-прогон успешен. Production-код из-за этого не менялся.
- Первый regression-прогон: 1 failed / 164 passed (3024 assertions), 51.88 s.
  Существующий `AuthenticationTest::test_throttle_expires_after_sixty_seconds`
  получил redirect на login после travel(61). Без изменения кода отдельный полный
  `AuthenticationTest` прошёл: **25 passed, 208 assertions**, 12.41 s
  (`php artisan test --compact --filter=AuthenticationTest --log-junit=/tmp/race-auth-recheck.xml`).
  Повтор всего regression-набора и полный MySQL suite также успешны.
  Причина единичного сбоя не установлена.

Race-тест детерминированно перехватывает вызов `invite()` после первоначального
eligible lookup, меняет `disabled` в БД, затем вызывает исходный реальный сервис
с locked reload. Его результат не подменяется: проверяются `false` и отсутствие
hash до возврата в controller, затем ровно один framework hash. Проверены configured
bcrypt/Argon2id cost, отсутствие per-call overrides, одинаковые body/status/headers,
отсутствие token rows/jobs, sensitive values в логах и сессии. Wall-clock assertions
и sleep-based timing tests не применяются. Это воспроизведение порядка событий
между проверками, без недетерминированного планирования двух процессов.

Сохранённые regression-тесты проверяют один hash для real/dummy paths,
queue-failure rollback без второго hash, malformed/throttled без hash, свежесть
и отбрасывание dummy values, password reset/login/session boundaries, onboarding,
admin actions/audit, transport restrictions, base-path URLs и все 263 prototype states.

## Facts

- Исходный HEAD `e00eec1` — актуальный planner; parent
  `80d0db339e8a0d0c2305cb3cdee3fb64ebcbbb9e` подтверждён; дерево было чистым.
- Прочитаны WORKFLOW/AGENTS/task/current report, предыдущий correction diff,
  controller/service/tests и admin/onboarding callers.
- `.ai/task.md`, UI/CSS, routes, rate limits, broker/TTL, session implementation
  и catalogue 32/263 не изменены.
- **Реальных внешних писем не отправлялось.** Использовались mail/HTTP fakes;
  реальных SMTP/sendmail/MODX/WEBPAY вызовов не выполнялось.
- Production/private env, включая `.env_save`, не читались и не изменялись.

## Assumptions

Дополнительных продуктовых предположений нет; реализован preferred design задачи.

## Unknowns

Live delivery не проверялась: внешние вызовы запрещены задачей. Browser smoke
не проводился — UI не менялся. Причина промежуточного throttle-test сбоя неизвестна.

## Risks / Next Step

Все обязательные проверки пройдены; корректировка готова к обязательному task commit.
Исправление выравнивает криптографическую работу при изменении eligibility между
проверками; абсолютное равенство времени DB/queue/network и инфраструктурных отказов
не заявляется.
