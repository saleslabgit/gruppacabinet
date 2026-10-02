# Report: TASK-2026-10-02-01

Status: done

## Summary

Добавлено самостоятельное восстановление пароля через GET/POST `/password/forgot`
и «Забыли пароль?» на login. Публичный ответ одинаков для существующего,
неизвестного, недоступного аккаунта и сбоя очереди; проверяется только синтаксис
email. Нормализация соответствует login. Ограничения: 5 запросов/мин/IP и
1 запрос/мин по SHA-256 нормализованного email, независимо от наличия аккаунта.

Существующие PasswordSetupService, Laravel PasswordBroker/DatabaseTokenRepository,
password_reset_tokens, TTL и database queue используются для первой установки
и замены пароля. Новая ссылка заменяет старую. Успешное завершение под блокировкой
пользователя меняет пароль через hashed cast, вызывает SessionInvalidator и удаляет
токен. Завершаются только сессии целевого пользователя, меняется remember_token;
его текущая браузерная сессия также выходит. Автовхода нет.

Администратор может отправить ссылку доступному психологу независимо от наличия
пароля. Новый audit action — user.password_link_sent; исторический
user.password_setup_resent продолжает отображаться. Автоматическое приглашение
после approval остаётся только для аккаунтов без пароля. Нейтральные HTML/text
письма подходят для onboarding и recovery; ограничения SMTP/sendmail сохранены.

## Changed Files

- PasswordRecoveryController (новый), web routes, AppServiceProvider и
  bootstrap/app.php: форма/валидация/общий ответ, лимиты и безопасное отображение 429.
- PasswordSetupService, PasswordSetupController, PsychologistActions:
  общая eligibility без password-null, транзакционная смена пароля и sessions,
  сохранение after-commit onboarding.
- Admin PsychologistController, admin/users/show: новое название действия и audit.
- auth/password-forgot (новый), login/password, mail/password-setup HTML/text:
  существующие layout/components, восстановление и нейтральный текст письма.
- PrototypeCatalog/Fixtures: четыре новых состояния; 32 группы / 263 варианта.
- PasswordRecoveryTest (новый), PasswordSetupTest, PrototypeTest: публичная
  приватность, лимиты, токены, отказ очереди, сессии, авторизация, mail и UI.
- SPEC.md; docs/email, architecture, development, deployment, project-status,
  ui-pages: актуальный password recovery flow и эксплуатационные границы.
- .ai/report.md: этот отчёт. .ai/task.md, WORKFLOW и AGENTS не изменены.

## Checks

Проверки выполнены в изолированной копии `/tmp/password-check` локального
PHP 8.2.32 контейнера; MySQL 8.4, выделенная БД `gruppa_cabinet_test`.
Исходный код смонтирован read-only. Env/private/storage/cache файлы проекта не
копировались; тестовая копия использует пустой `.env` и синтетические настройки.
Для subprocess-тестов DB_CONNECTION/HOST/PORT/DATABASE/USERNAME/PASSWORD из
phpunit.xml явно переданы через docker exec -e; APP_ENV=testing, синтетический
APP_KEY, MAIL_MAILER=array. Mail fake исключает реальные SMTP/sendmail вызовы; HTTP preventStrayRequests
сохранён. Production env, включая `.env_save`, не читались.

- `php artisan test --compact --filter="PasswordRecoveryTest|PasswordSetupTest|AuthenticationTest|PsychologistAdminTest|PrototypeTest|DeploymentPreflightTest|PolicyTest" --log-junit=/tmp/password-regression.xml`
  — **115 passed, 2354 assertions**, 22.13 s; failures/errors/skips: 0/0/0.
- Первый полный suite: 712 passed, 4 failed, 7751 assertions, 624.40 s.
  Три IntegrationConcurrencyTest и один SharedHostingRuntimeTest не получили
  DB-настройки родительского PHPUnit: subprocess подключался к 127.0.0.1.
  После передачи локальных настроек через environment:
  `php artisan test --compact --filter="IntegrationConcurrencyTest|SharedHostingRuntimeTest" --log-junit=/tmp/password-environment.xml`
  — **6 passed, 93 assertions**, 62.11 s. Код этих подсистем не менялся.
- `php artisan test --compact --log-junit=/tmp/password-full-final.xml`
  — **716 passed, 7864 assertions**, 599.32 s; failures/errors/skips: **0/0/0**.
  Включены все классы без exclusions; исправлены только настройки тестового окружения.
- `php vendor/bin/pint --test` — **PASS, 213 files**.
- `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — **No errors**.
- `composer check-platform-reqs` — все требования **success**.
- `composer validate --no-check-publish` — **composer.json is valid**.
- `php artisan view:cache` — **Blade templates cached successfully**.
- `php artisan route:list --path=password -v` — семь маршрутов, включая
  recovery GET/POST, setup GET/POST, admin POST и два prototype routes.
  Middleware recovery/setup/admin проверены; существующие guards сохранены.
- `CACHE_STORE=array php artisan schedule:list` — **PASS**, пять прежних команд.
  Запуск списка не выполнял scheduled commands. Первая попытка без изолированного
  cache store использовала отсутствующую default SQLite; после настройки прошла.
- `git diff --check` / `git diff --cached --check` — exit 0.
  Просмотрены diff и 26 staged implementation/docs файлов; нет env/private,
  production data, mail captures, logs/cache/vendor или временных артефактов.
  Каталоги app/routes/resources/views/tests и bootstrap/app.php тестовой копии
  побайтово совпали с исходными файлами (diff -qr/cmp).

| Полный класс из финального JUnit | Tests | Assertions | Failures/errors/skips |
|---|---:|---:|---|
| PasswordRecoveryTest | 23 | 254 | 0/0/0 |
| PasswordSetupTest | 22 | 270 | 0/0/0 |
| AuthenticationTest | 25 | 208 | 0/0/0 |
| PsychologistAdminTest | 15 | 263 | 0/0/0 |
| PrototypeTest | 10 | 1207 | 0/0/0 |
| DeploymentPreflightTest | 20 | 152 | 0/0/0 |
| ExpiryWarningTest | 18 | 239 | 0/0/0 |
| SharedHostingRuntimeTest | 3 | 26 | 0/0/0 |
| IntegrationConcurrencyTest | 3 | 67 | 0/0/0 |

HTTP feature tests проходят через реальные routes, middleware, CSRF/policy и Blade.
Проверены database queue insertion, token rollback при сбое очереди, stale jobs,
production base-path URL, безопасная диагностика, прежние transports, оба варианта
password presence, login новым/отказ старым паролем, отзыв только целевых sessions,
выход текущего пользователя и сохранение чужой авторизации. Все 263 прототипных
варианта рендерятся без БД; новый prototype не отправляет формы.

Начальные итерации выявили ошибки новых fixtures/base-path тестовых URL и
статического типа logoutCurrentDevice; исправлены. Отсутствующий `.env` в тестовой
копии давал предупреждения раннера; пустой синтетический файл устранил их.

## Facts

- Исходный HEAD `a4b7180` — planner текущей задачи, parent
  `a632e01cc5776c57d568d9592196782a18203d2b` подтверждён.
- Исходное дерево чистое; WORKFLOW/AGENTS/task/предыдущий report и необходимые
  код/тесты/Blade/CSS/docs изучены до реализации.
- Context7 consulted для named multi-limit API; совместимость проверена по
  установленному Laravel framework. Новых зависимостей и миграций нет.
- **Реальных внешних писем не отправлялось.** MODX/WEBPAY/intake и production
  артефакты не изменялись; внешний live smoke не выполнялся.

## Assumptions

Новых продуктовых предположений нет; реализованы явные решения задачи.

## Unknowns

Browser smoke не выполнен: Playwright MCP не смог запустить отсутствующий
Chromium executable. Новая страница наследует существующие responsive CSS и
компоненты; browser desktop/tablet/mobile проверка визуального результата остаётся
непроверенной. Реальная доставка во входящие не проверялась и задачей не разрешена.

## Risks / Next Step

Обязательные automated/runtime проверки пройдены; результат готов к task commit.
Отдельная визуальная проверка desktop/tablet/mobile в браузере с установленным
Chromium остаётся непроверенной. Доставка ссылки зависит от database worker и
SMTP/sendmail; при инфраструктурном сбое публичный ответ намеренно остаётся общим.
