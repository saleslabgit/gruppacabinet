# Report: TASK-2026-10-08-02

Status: done

## Summary

Добавлен owner-only GET `/payments/{payment}/status` (`psychologist.payments.status`).
Используются существующие account/psychologist middleware, owner lookup и policy.
Ответ — только `{"status":"..."}` из сохранённого enum, JSON с
`Cache-Control: private, no-store`. Endpoint не вызывает recovery, WEBPAY,
переходы статусов или jobs и не изменяет бизнес-данные.

В real pending result view добавлен отдельный polling marker с URL status и
canonical show. Локальный ui.js начинает через 5 секунд и повторяет проверку
через 5 секунд после предыдущего pending-ответа, не более 120 секунд от
инициализации. Один запрос одновременно, request timeout 5 секунд. Скрытая
вкладка пропускает проверки без продления бюджета; pagehide останавливает
таймеры и abort текущего запроса. При terminal status выполняется одна навигация
на canonical show, где сервер отображает фактический результат. `/return` и
`/cancel` автоматически не вызываются. Нет optimistic UI/auto-retry/auto-start.

При offline, timeout, HTTP/auth ошибке, redirect, неверном JSON/статусе polling
тихо прекращается. Manual refresh и явное «Продолжить эту оплату» сохраняются.
Без JS всё работает вручную. Checkout auto-submit из предыдущей задачи не менялся.

На owner result страницах статус/сумма/действия остаются видимыми, merchant order
находится только в нативном изначально закрытом `<details>` «Детали платежа».
Обычное Blade escaping сохранено; номер можно раскрыть клавиатурой и скопировать.
Перенос длинных значений использует существующее `dd { overflow-wrap:anywhere }`
из общего selector; CSS не менялся. Prototype показывает тот же disclosure без
polling; admin, provider fields, история и binding не менялись.

## Changed Files

- `application/app/Http/Controllers/Psychologist/PaymentController.php` — status JSON.
- `application/routes/web.php` — один authenticated owner GET.
- `application/resources/views/psychologist/payments/return.blade.php` — real pending
  marker, native disclosure; остальные статусы/действия сохранены.
- `application/public/ui.js` — ограниченный same-origin polling.
- `application/tests/Feature/PaymentStatusTest.php` — HTTP/authorization/read-only,
  presentation/escaping, HTTPS URLs, signed fake notify→local result.
- `application/tests/Feature/PrototypeTest.php` — все prototypes без polling,
  owner result variants имеют закрытый disclosure с полным номером.
- `application/tests/JavaScript/payment-status.test.cjs` — deterministic Node VM
  fake DOM/fetch/timers, без зависимостей и реальной сети.
- `docs/webpay.md`, `docs/ui-pages.md` — реализованное поведение и пределы.
- `docs/deployment.md` — совместный release route/view/public ui.js и smoke.
- `.ai/report.md` — этот отчёт.

## Checks

Проверки выполняются в одноразовых `cabinet-status-php` / `cabinet-status-mysql`,
PHP 8.2.32 / MySQL 8.4, internal Docker network без выхода наружу. Только
синтетическая `gruppa_cabinet_test`. Код/vendor копировались по allowlist из
read-only source; private .env/.env_save, исходные storage/cache/logs не читались
и не копировались. В тестовой копии пустой .env и явные synthetic env settings,
также для subprocess tests. Миграциями тестовой БД управляет suite; отдельная
команда migrate:fresh на retained/production DB не запускалась.

- Первый `php artisan test --compact --filter="PaymentStatusTest|PrototypeTest" --log-junit=/tmp/status-new.xml`:
  **31 passed, 2352 assertions**, 10.28 s (до дополнительного escaping case).
- `php artisan test --compact --filter="PaymentStatusTest|WebpayTest|WebpayConcurrencyTest|PaymentEraGroupsTest|GroupWorkflowTest|GroupLifecycleTest|PrototypeTest|ProductionUrlGenerationTest|AuthenticationTest" --log-junit=/tmp/status-focused.xml`:
  **175 passed, 4316 assertions**, 77.60 s.
- `php artisan test --compact --log-junit=/tmp/status-full.xml`: **821 passed,
  10135 assertions**, 373.59 s; JUnit failures/errors/skips: **0/0/0**.
- `node --check application/public/ui.js`: **PASS**.
- `node application/tests/JavaScript/payment-status.test.cjs`: **18 passed**, no
  failures/cancellations/skips. `node --test application/tests/JavaScript/payment-status.test.cjs`
  тоже завершился успешно; прямой запуск показывает все 18 named cases.
- JS cases: первый check на 5 s, 23 checks на 5..115 s и остановка на 120 s;
  четыре terminal statuses→один canonical show; не более одного in-flight;
  abort timeout; offline/non-JSON/bad JSON/unknown/401/403/404/500; hidden tab,
  задержанные timers и поздний response; pagehide cleanup; отсутствие marker,
  cross-origin guard и неизменный checkout form submit.
- `vendor/bin/pint --test`: **PASS, 226 files**.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: **No errors**.
- `composer check-platform-reqs`: все требования **success**.
- `composer validate --no-check-publish`: **composer.json valid**.
- `VIEW_COMPILED_PATH=/tmp/status-cli-views php artisan view:cache`: **success**,
  отдельный CLI cache не мешает тестам.
- `php artisan route:list` (**131 routes**) и `php artisan route:list --path=payments`: **success**;
  новый GET|HEAD `payments/{payment}/status` → psychologist.payments.status.
- `git diff --check`: **PASS**.
- `git diff --cached --check`, staged/private/secret review: **PASS**;
  ровно 11 файлов задачи, diff просмотрен, credential-pattern scan пройден.
  Private env, production data, logs/cache/vendor/generated artifacts и
  посторонних изменений нет.
- `diff -qr` app/routes/resources/public/tests: финальные исходники совпадают
  с тестовой копией, расхождений нет.

Обязательные классы в полном suite:

| Класс | Tests | Assertions | Failures/errors/skips |
|---|---:|---:|---|
| PaymentStatusTest | 22 | 275 | 0/0/0 |
| WebpayTest | 32 | 507 | 0/0/0 |
| WebpayConcurrencyTest | 6 | 142 | 0/0/0 |
| PaymentEraGroupsTest | 4 | 55 | 0/0/0 |
| GroupWorkflowTest | 53 | 697 | 0/0/0 |
| GroupLifecycleTest | 20 | 325 | 0/0/0 |
| PrototypeTest | 10 | 2080 | 0/0/0 |
| ProductionUrlGenerationTest | 3 | 33 | 0/0/0 |
| AuthenticationTest | 25 | 208 | 0/0/0 |

Playwright попытался запустить browser, но отсутствует Chromium executable
(`/home/admin1/.cache/ms-playwright/chromium_headless_shell-1217/...`). Поэтому
реальный browser polling/navigation, native disclosure/mobile и no-JS flow
**не проверены браузером**. Node VM не выдаётся за browser/live acceptance.

## Facts

- Начальный HEAD `e434f4b` — planner этой задачи; parent точно
  `825905cdd8ac3cb9792dfae93603ab474ca69acd`. Начальное дерево чистое.
- Прочитаны WORKFLOW, AGENTS, task, предыдущий report, актуальные payment
  views/layout/CSS/catalog, controller/routes/policy/enum/PaymentPages/recovery
  и provider tests/fixtures. `.ai/task.md` не менялась.
- Laravel JSON/header/test API сверены через Context7; итоговая совместимость
  проверяется на зависимостях репозитория.
- Polling читает сохранённый статус. Подписи, notify, ConfirmPayment,
  PaymentRecovery, PaymentAttempts, тарифы, refund/delete, worker/scheduler и
  product effects не менялись. Миграций/пакетов/очередей не добавлено.
- User-reported LIVE payment и последующий Draft — наблюдение владельца из
  task, не проведённая Codex внешняя проверка. Реальных финансовых/provider,
  mail/Telegram/MODX запросов в этой задаче не было.
- Production env/данные/keys не читались и не менялись. Старые sandbox попытки
  и процедуры cutover не менялись и не являются условием этого UI release.
- Одноразовые тестовые контейнеры, синтетические данные и internal-сеть удалены.

## Assumptions

Route/controller/view и публичный ui.js будут развернуты совместно обычной
процедурой. Same-origin generated URLs и действующая owner session необходимы;
при их отсутствии сохраняется ручной сценарий. Пять секунд отсчитываются от
завершения предыдущего запроса, поэтому медленный ответ не создаёт overlap.

## Unknowns

Фактическая версия файлов/caches на production, доступность JS в браузере,
реальная browser навигация и responsive/no-JS приёмка после deployment.
Состояние банковских операций не устанавливалось этой задачей.

## Risks / Next Step

После deployment выполнить вручную (Codex не выполнял):

1. Развернуть private controller/routes/Blade и обновить отдельно скопированный
   `application/public/ui.js` в public `/cabinet`; rebuild route/view cache.
   Проверить загрузку нового filemtime URL скрипта и новый authenticated GET.
2. Для отдельно разрешённой REAL оплаты, попавшей на pending, дождаться trusted
   notify: GET status меняется, браузер за несколько polling intervals открывает
   canonical show с правильным действием для placement/active/expired extension.
   Проверить локальный group effect. Не проводить новое списание только ради
   smoke без отдельного разрешения оператора.
3. При длительном pending проверить прекращение polling после 120 секунд и
   рабочую «Обновить страницу». Отдельно проверить no-JS, offline/expired auth;
   «Продолжить эту оплату» должно оставаться только явным действием.
4. Проверить другой owner ID (отказ), admin без owner polling и неизменённые
   admin order details. Номер на owner страницах виден только после раскрытия
   «Детали платежа»; проверить клавиатуру, копирование и узкий мобильный экран.

JS-навигация не доказывает оплату: результат определяется исключительно
сохранённым состоянием после trusted WEBPAY notify/recovery.
