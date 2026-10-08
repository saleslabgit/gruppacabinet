# Report: TASK-2026-10-08-01

Status: done

## Summary

Причина второго клика подтверждена: owner start POST уже подготавливал подписанную
форму, но placement Blade показывал ещё одну ручную «Оплатить картой».
В ответе на start теперь есть «Переходим к оплате…», native POST auto-submit
через узкий `data-webpay-auto-submit` hook в локальном ui.js и рабочий submit
fallback «Перейти к оплате». Начальный GET по-прежнему требует явного действия.
Существующие подписи, обработка notify, бизнес-переходы и схемы не менялись.

Добавлен read-only gate `WEBPAY production live mode`: APP_ENV=production требует
WEBPAY_ENV=production; staging допускает sandbox. PASS не доказывает работоспособность
ключей, API, hosting или банка. Написаны runbook безопасного cutover и rollback,
GO/NO-GO gates и read-only SQL только для counts/status/order IDs.

## Changed Files

- `application/resources/views/psychologist/payments/placement.blade.php` — состояние
  перехода и fallback в той же подписанной форме.
- `application/public/ui.js` — native submit только формы с payment hook.
- `application/app/Support/DeploymentPreflight.php` — production live-mode gate.
- `application/tests/Feature/WebpayTest.php` — sandbox/production rendered forms,
  HTTPS callbacks, подпись/поля, pending continuation, ошибки, retries размещения
  и active/expired продлений, terminal callbacks, owner/admin boundaries.
- `application/tests/Feature/DeploymentPreflightTest.php` — production/sandbox gate,
  безопасный вывод и отсутствие внешних запросов.
- `application/tests/Feature/PrototypeTest.php` — ни один prototype не auto-submits.
- `docs/webpay.md` — точный checkout, ограничения, audit/cutover gates и исправление
  устаревшего описания удаления групп.
- `docs/deployment.md` — последовательность внешней приёмки, provision и rollback.
- `docs/ui-pages.md` — только фактически изменённое взаимодействие checkout.
- `.ai/report.md` — этот отчёт.

## Code Audit Findings

| Область | Проверенные файлы / сценарий | Вывод |
|---|---|---|
| Создание и тариф | GroupWorkflow::create, GroupCovers::persist, PaymentAttempts, SettingService | Платная группа/attempt создаются транзакционно; цены положительные, BYN integer minor units; существующая попытка сохраняет сумму; retry получает новый order/актуальную цену |
| Checkout | PaymentController::start, placement Blade, ui.js, Webpay::form | Marker только после owner CSRF POST; native signed POST; GET/pending/result/prototype/error не auto-start; no-store/private и Referrer-Policy сохранены |
| Повторный start | PaymentAttempts::start | Блокировки payment/group; созданная запись становится pending; repeated start не создаёт строку, не меняет order и started_at, не применяет продуктовый эффект; новый response может иметь новый seed |
| Подпись / endpoint | Webpay::configuration/form/notify | Фиксированные sandbox/production URL; test 1/0; SHA1 v2; стандартный MD5 notify и hash_equals; card-inclusive mode отклоняется |
| Финансовое доверие | ConfirmPayment::apply, Webpay::verify | Проверки merchant order, cc, BYN, точной суммы, transaction/provider order, cross-payment reuse; unique transaction DB constraint и locks; browser параметры не связывают платёж |
| Повторы / порядок | ConfirmPayment, WebpayConcurrencyTest, WebpayTest | Duplicate success не повторяет эффект; failure/void не понижают succeeded/refunded; failed/cancelled не превращаются в success от позднего conflicting callback |
| API / recovery | Webpay::transaction, PaymentRecovery, CheckPayment, QueuePaymentChecks | TLS, redirects off, bounded timeout/XML, safe fields; API применим только к trusted binding; 20 минут до начала, до 4 вызовов, окно 1 час; unbound — ручная проверка, без произвольного mark-paid |
| Статусы / продукт | ProviderResult, ConfirmPayment, GroupLifecycleService | 1/4→succeeded, 2/8→failed, 7→cancelled для pending; прочие типы не применяются. Placement→draft; active extension добавляет snapshot days; expired→approved и ожидает publication confirmation |
| Admin / refund | Admin PaymentController, PaymentPolicy, PaymentRefundRequest | Просмотр local history, safe journal; refund сначала у провайдера, затем locked local accounting, без provider call; повтор запрещён |
| Удаление | GroupPolicy::delete, GroupWorkflow::delete, PaymentEraGroupsTest, актуальный SPEC | Старое docs/webpay.md ошибочно обещало запрет удаления при succeeded. Сейчас owner скрывает, admin soft-deletes независимо от платежа; история сохраняется, автоматического refund нет. Документация приведена к текущему коду/ТЗ |
| Удаление до notify | ConfirmPayment::apply | Success для admin-deleted группы сохраняет trusted binding/pending для ручной проверки, не восстанавливает группу; нужен operator разбор |
| Роли / CSRF | routes/web.php, bootstrap/app.php, RequireRole, PaymentPolicy | Owner lookup и account/psychologist middleware; другой owner 404, admin start 403; start требует CSRF; notify вне auth/session/CSRF, signed bytes не trim/normalize |
| HTTPS / base path | AppServiceProvider, surface layout, ProductionUrlGenerationTest | Production forceScheme HTTPS; /cabinet callback generation; фактический proxy/host/Referer/WAF проверяется оператором |
| Preflight | DeploymentPreflight / DeploymentPreflightTest | Исправлен ложный live PASS при production+sandbox. Ключи проверяются только на наличие. Новый gate не пишет данные; существующие disposable cache/lock probes сохранены |
| Cutover | gp_payments schema, PaymentAttempts, Webpay, PaymentRecovery | Среда не сохраняется. Старые created/pending могут получить новые endpoints/keys; late notify — неверный secret. Это обязательный внешний NO-GO gate, не повод для скрытой миграции |

Новых подтверждённых дефектов финансового trust-flow, требующих изменения протокола
или схемы, при инспекции не выявлено. Это не доказательство внешней готовности.
Если retained sandbox created/pending/session невозможно сверить текущими trusted
путями, переключение BLOCKED: сохранить sandbox и отдельно спроектировать привязку
среды либо audited retirement никогда не начатых attempts. SQL-закрытие/удаление,
подмена статуса и очистка истории не разрешены.

## Checks

Проверки выполняются в одноразовых `cabinet-webpay-php` / `cabinet-webpay-mysql`,
PHP 8.2.32 / MySQL 8.4, Docker internal network без выхода наружу. Исходники и
vendor скопированы по allowlist; `.env`, `.env_save`, storage/cache/logs источника
не копировались. В тестовой копии создан пустой `.env`; DB/APP/MAIL settings
синтетические, переданы окружением, включая subprocess tests. Только dedicated
`gruppa_cabinet_test`; тестовые migrations управляются suite. Команда
migrate:fresh на retained/production DB не выполнялась.

- `php artisan test --compact --filter="WebpayTest|WebpayConcurrencyTest|PaymentEraGroupsTest|GroupWorkflowTest|GroupLifecycleTest|PrototypeTest|ProductionUrlGenerationTest|DeploymentPreflightTest|AuthenticationTest|IntegrationIntakeTest|IntegrationConcurrencyTest" --log-junit=/tmp/webpay-focused-final.xml`:
  **209 passed, 4645 assertions**, 106.53 s.
- `php artisan test --compact --filter=test_late_callbacks --log-junit=/tmp/webpay-terminal.xml`:
  **1 passed, 23 assertions**, 6.94 s (добавлен после целевого прогона).
- `php artisan test --compact --log-junit=/tmp/webpay-full.xml`: **799 passed,
  9579 assertions**, 411.14 s; JUnit failures/errors/skips: **0/0/0**.
- `vendor/bin/pint --test`: **PASS, 225 files**, включая финальную версию tests.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: **No errors**.
- `composer check-platform-reqs`: все требования **success**.
- `composer validate --no-check-publish`: **composer.json valid**.
- `VIEW_COMPILED_PATH=/tmp/webpay-cli-views php artisan view:cache`: **success**;
  отдельный каталог не мешает компиляции views в тестах.
- `php artisan route:list`: **success, 130 routes**; `php artisan schedule:list`: **success**,
  в том числе recovery каждые 5 минут. Задания не исполнялись этими проверками.
- `node --check application/public/ui.js`: **PASS**.
- Node VM smoke с полным ui.js: **PASS**, только marker вызывает submit, та же
  форма и поля, никаких browser/network запросов.
- `git diff --check`: **PASS**.
- `git diff --cached --check` и staged review: **PASS**; ровно 10 файлов задачи,
  diff просмотрен, credential-pattern scan пройден; секретов, private env,
  production data, vendor/storage/cache/log/temp artifacts и посторонних файлов нет.
- `diff -qr` для app/config/database/public/resources/routes/tests: тестовая
  копия соответствует финальным исходникам, расхождений нет.

Обязательные классы в полном suite:

| Класс | Tests | Assertions | Failures/errors/skips |
|---|---:|---:|---|
| WebpayTest | 32 | 507 | 0/0/0 |
| WebpayConcurrencyTest | 6 | 155 | 0/0/0 |
| PaymentEraGroupsTest | 4 | 55 | 0/0/0 |
| GroupWorkflowTest | 53 | 697 | 0/0/0 |
| GroupLifecycleTest | 20 | 325 | 0/0/0 |
| PrototypeTest | 10 | 1774 | 0/0/0 |
| ProductionUrlGenerationTest | 3 | 33 | 0/0/0 |
| DeploymentPreflightTest | 23 | 170 | 0/0/0 |

Диагностика первого прогона: два новых checkout cases получили 404 из-за
forceRootUrl с /cabinet в Laravel test request; исправлено использованием explicit
http://localhost request, как в ProductionUrlGenerationTest. 146 предупреждений
объяснены отсутствующим /app/.env в одноразовой копии. Создан пустой файл;
приватная конфигурация не использовалась. Затем новые checkout и extension retry
сценарии подтвердили 146 assertions; предупреждения до исправления окружения
не скрывались и код приложения для них не менялся.

Браузерная проверка попыталась запустить Playwright, но Chromium executable
отсутствует. Реальная auto-navigation, no-JS fallback и responsive/browser
acceptance **не проверены браузером**. Node VM smoke исполнил весь ui.js с DOM
stub: без marker нет submit, с marker ровно один вызов на той же форме, поля
сохранены. Это не browser test и не запрос к провайдеру.

## GO / NO-GO

| Gate | Решение / доказательство |
|---|---|
| Статическая реализация и синтетические регрессии | GO к review: целевые и полный MySQL suite, Pint/PHPStan/Composer/Blade/JS checks прошли |
| Реальная browser auto-navigation / no-JS | NOT VERIFIED; ручная приёмка обязательна |
| WEBPAY Sandbox success/notify/API/refund | NOT VERIFIED; внешняя приёмка обязательна |
| Retained sandbox inventory / late sessions | NOT VERIFIED; NO-GO до сверки, BLOCKED при неразрешимых попытках |
| Production secrets/merchant mode/API permissions | NOT VERIFIED; только private operator/provider check |
| Hosting HTTPS/Referer/notify/queue/cron/locks/mail/backups | NOT VERIFIED на целевом сервере |
| LIVE launch | NO-GO до всех gates и отдельно разрешённой low-amount приёмки |

## Operator Deployment / Smoke Checklist

Подробная последовательность: `docs/deployment.md`, раздел «WEBPAY live cutover
and acceptance»; SQL inventory и blocker alternatives: `docs/webpay.md`,
«Retained database: sandbox-to-production cutover».

1. Пройти Sandbox: размещение/продления/retry, auto-POST и fallback, повторные
   действия, success/signed decline/cancel, duplicate/return ordering, bounded
   recovery/manual-review, provider refund + local accounting.
2. С WEBPAY подтвердить contracted origin gruppa.info, обычный one-stage cc flow,
   standard signed notify, unsuccessful-notify support opt-in и API доступ.
3. Закрыть новые payment entry points, оставить notify доступным, сверить только
   counts/status/order IDs старых попыток и outstanding sessions/late delivery.
   Не менять keys при неразрешённых attempts; никакого manual mark-paid/close.
4. Backups и rollback, queue/scheduler/shared locks/mail/logging; drain/pause
   старых jobs и повторная сверка перед cutover.
5. Только затем privately provision REAL store/secret/login/unhashed password;
   APP_ENV=production, WEBPAY_ENV=production, APP_URL=https://gruppa.info/cabinet,
   positive approved prices; rebuild caches/restart workers.
6. Preflight/route/schedule checks и отдельная hosting проверка. Сверить только
   action https://payment.webpay.by/, test=0 и HTTPS /cabinet callbacks, без dump
   подписанной формы. Notify: inbound 443 POST, без redirect/auth/CSRF/WAF,
   valid signed→stateless 200; работает без browser return.
7. Отдельно разрешить low-amount LIVE test: exact bank debit, signed notify,
   local state, один group effect, accounting/refund. Только после этого launch.
8. Rollback после LIVE попытки не возвращает sandbox keys/старую БД автоматически:
   сохранить live notify/history и сверить финансовые события; предпочтителен
   совместимый code/UI rollback.

## Facts

- Стартовый HEAD `09b11eb` — актуальный planner; parent
  `52454eeb5208b31a7246205e46c29cec49a3f54e`; начальное дерево чистое.
- WORKFLOW/AGENTS/task/предыдущий report, approved Blade/layout/CSS/catalog,
  payment services/controllers/policies/schema/tests и runbook прочитаны.
- Официальные WEBPAY docs сверены через Context7 и docs.webpay.by: environment,
  form fields/signature, standard notify, get_transaction, API prerequisites.
  Transaction-types page недоступна web viewer; текущий mapping описан по коду,
  success 1/4 также подтверждён страницами notify/verification.
- Production private env/ключи/платёжные данные/логи не читались и не менялись.
  Реальных WEBPAY/payment/refund/notify/mail/Telegram/MODX запросов не выполнялось.
- `.ai/task.md` не изменена. Новых routes/packages/migrations нет.

## Assumptions

Production cutover будет отдельно разрешён и выполнен оператором. Фактический
состав retained DB и merchant configuration неизвестен; отсутствие outstanding
attempts не предполагается. Наличие credentials не равно acceptance.

## Unknowns

Реальные billing permissions, provider mode, deployed config, hosting WAF/proxy,
callback delivery, bank processing и retained sandbox reconciliation. Реальная
браузерная навигация и no-JS fallback ещё требуют ручной проверки.

## Risks / Next Step

Provider acceptance остаётся **NOT VERIFIED**: ни реальный Sandbox payment,
notify/get_transaction/refund, ни LIVE debit/refund не выполнялись.
Локальная проверка не разрешает смену ключей. Cutover с неразрешимыми старыми
попытками требует отдельного продуктового/архитектурного решения.
Одноразовые тестовые контейнеры, их данные и internal-сеть удалены после проверок.
