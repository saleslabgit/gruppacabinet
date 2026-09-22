# Report: TASK-2026-09-21-12

Status: done

## Summary

Реализованы локальные WEBPAY-платежи за размещение и продление, подписанная форма
v2, stateless notify, XML get_transaction, единое идемпотентное подтверждение,
ограниченные проверки статуса, реальные страницы платежей администратора и
ручной учёт возврата. Существующие Blade и правила жизненного цикла сохранены.
Реальная приёмка WEBPAY Sandbox и deployment НЕ ВЫПОЛНЯЛИСЬ.

## Changed Files

- `application/app/Payments/`: адаптер протокола, безопасные результаты/ошибки,
  создание/повтор/старт попыток, подтверждение и recovery.
- Payment controllers, requests, policy, `PaymentPages`, команда и job проверки;
  `config/webpay.php`, пустые env placeholders, additive migration контекста
  попытки, casts Payment/PaymentNotification.
- GroupWorkflow/GroupLifecycleService, GroupController/GroupPages, routes и
  bootstrap подключены к платежам; существующие payment/group/settings views
  используют реальные данные и действия. CSS и общий layout не изменены.
- `WebpayTest`, `WebpayConcurrencyTest`, синтетический WebpayFixture; пять старых
  suites обновлены только в местах прежних предположений об отсутствии платежей.
- `docs/webpay.md`, `docs/deployment.md`, development/architecture/project-status/
  ui-pages и этот отчёт. Task/SPEC/WORKFLOW/AGENTS не изменены.

## Implemented behavior

- Платный владелец создаёт awaiting_payment + created payment атомарно; бесплатный
  — draft без платежа. Положительная цена и store/secret обязательны только для
  платной операции. Сумма целочисленная, без float; случайный уникальный order.
- Start — owner-only CSRF POST, переводит created в pending, сохраняет started_at.
  Повторный start продолжает ту же попытку. Форма v2 содержит SHA1 и точные суммы,
  не содержит secret/API password/карточные данные. Sandbox/production URL фиксированы.
- Notify исключён из session/auth/CSRF и преобразования подписанных строк.
  MD5 проверяется constant-time; card-inclusive режим отвергается. Журнал хранит
  только ограниченные поля с проверкой формата; подпись, RRN, raw body и секреты
  не сохраняются. Ошибки имеют фиксированные безопасные коды.
- ConfirmPayment блокирует payment, затем group; проверяет merchant order,
  transaction/provider order, сумму/BYN/cc/type. Эффект применяется один раз:
  placement -> draft; active extension добавляет snapshot дней и очищает warning;
  expired extension -> approved без новых дат публикации. Повтор возвращает успех
  без дублирования history/срока. Succeeded/refunded не понижаются notify.
- Новое продление использует текущий owner.free; исторический group.free не
  определяет тариф. Незавершённая попытка сохраняет цену/тариф. Eligibility
  проверяется при создании и первом старте; переход времени после старта не
  отнимает успешное продление. Retry после доверенного failed/cancelled создаёт
  новый order по текущей цене/тарифу, сохраняя историю.
- XML API использует существующий HTTP client, HTTPS verify=true, без redirects,
  connect timeout 5 s, total 1–30 s. DOM/libxml уже входят в production dependency
  requirements. Пустой/повреждённый XML, DTD/entities, неоднозначные поля и неверные
  подписи отвергаются; raw XML и транспортные exception payloads не логируются.
- Standalone get_transaction НЕ устанавливает связь с локальным заказом:
  документированный ответ не содержит подписанного site_order_id. API mutation
  разрешена только после binding из проверенного notify. Browser wsb_tid/order
  не используются для binding/API selection. Lost notify без binding оставляет
  pending/manual review; обхода через «отметить оплаченным» нет.
- Recovery запускается не ранее 20 min, только для trusted-bound pending.
  Unique database job и shared-cache execution lock предотвращают параллельные
  проверки. Максимум четыре вызова на отметках 0/10/30/55 min от первой проверки;
  после 60 min вызовов нет. Ошибка расходует слот, но не меняет финансовый статус.
  Unbound manual review выводится через 20 min без HTTP/job/counter increments.
- Admin list/detail читают только БД: фильтры, Минские даты, eager loading,
  pagination, безопасный журнал. Refund требует admin policy, CSRF, комментарий,
  confirmation; ставит refunded/refunded_at и audit без вызова refund API.
  Прежний запрет удаления при succeeded без возврата сохранён.

## Checks

- Docker/MySQL/PHP/Mailpit здоровы, web/worker работают. Additive migrate --force
  и идемпотентный db:seed --force выполнены без очистки сохраняемой dev DB.
- Финальный полный MySQL regression (`docker compose exec -T php php artisan test`):
  397 passed / 6615 assertions, 462.35 s. Предыдущий полный прогон также прошёл:
  394 passed / 5996 assertions (503.46 s).
- Финальный focused `php artisan test tests/Feature/WebpayTest.php` после
  проверки пустого XML: 26 passed / 272 assertions, 42.00 s.
- Предыдущий focused прогон: 123 passed / 2675 assertions (241.67 s).
  Промежуточные падения выявили устаревшие no-payment ожидания и конфликтующие
  Http fake callbacks; исправлены. Точное время в тестах приведено к секундам БД.
- Pint --test: PASS, 166 PHP files. Larastan с --memory-limit=512M: no errors.
  Начальный анализ с лимитом 128 MiB завершился нехваткой памяти; лимит поднят
  только для команды проверки, конфигурация проекта не менялась.
- composer check-platform-reqs: PASS (PHP 8.2.32); view:cache: PASS, затем view:clear.
- schedule:list: recovery каждые 5 min; прежние expiration/cleanup/warnings
  расписания сохранены. Recovery command на очищенной dev DB: queued 0.
  queue:failed: no failed jobs.
- Local routes: 31 страницы прототипов + index; production: 0 prototype routes.
  9 payment/notify routes присутствуют в обоих режимах; notify middleware = [].
  Полный regression включает каталог 31/249, Stage 11 integration и Stage 12 mail.
- Новые tests проверяют известные SHA1/MD5 vectors, integer bounds, trust boundary,
  mismatch/signature/XML/transport errors, CSRF/ownership, idempotency, eligibility,
  tariff/retry, bounded recovery, refund и отсутствие HTTP на admin страницах.
  Шесть гонок реальными MySQL child processes: placement/active/expired extension,
  notify-vs-notify и notify-vs-bound-API; ровно один эффект каждого платежа.
- Полный diff и staged list проверены: 53 файла текущей задачи, без секретов,
  чувствительных данных и посторонних artifacts. `git diff --check` и
  `git diff --cached --check` проходят; защищённые task/SPEC/WORKFLOW/AGENTS
  не изменены и не включены в commit.

## Runtime smoke

Выполнен реальный локальный HTTP/Chromium smoke через временный Docker process
на localhost:8099, с исключительно синтетическими credentials и @example.test
аккаунтами. WEBPAY browser requests блокировались; external requests = 0.

1. Login психолога, создание платной группы, payment/start, проверка полей формы
   и SHA1 независимым Node crypto расчётом; return/cancel не подтверждают платёж.
2. Независимо подписанный notify отправлен по HTTP дважды: одна смена статуса,
   один product effect. Проверены подтверждённые active и expired extensions.
3. Trusted failure, новый order при retry и trusted cancellation.
4. Admin login, list/detail, required comment и modal confirmation ручного учёта
   возврата: status refunded, timestamp/audit, без provider call.
5. Проверены widths 1440/1024/390: нет горизонтального overflow и JS errors.
   Снимки placement/refund просмотрены; существующая структура страниц сохранена.
6. Отдельная MySQL проверка подтвердила history, даты, marker, refund и отсутствие
   synthetic secrets в журнале/логе; standalone API result без binding отвергнут.
7. Синтетические users/groups/payments/notifications/audit/sessions удалены,
   исходные цены восстановлены. Временный контейнер остановлен/удалён; .env не
   менялся. Smoke scripts/screenshots/logs не добавлены в репозиторий.

## Facts

- Accepted base: 7b7e79c687f329f78ad94887c90e7b590b9b04fe; текущий planner:
  56129209a02cdbb196f1fd8250a71c9b18b75b24. Исходное дерево чистое.
- Ранее найденное отсутствие merchant order в get_transaction разрешено
  уточнением задачи: автоматическое восстановление без binding запрещено.
- Официальная WEBPAY документация перепроверена 2026-09-22; ссылки и точные
  порядки signature fields, endpoints, types и API prerequisites в docs/webpay.md.
  Laravel queue/HTTP details сверены с документацией и установленным кодом.
- Новых production dependencies нет. Реальные credentials и данные платежей
  не использовались и не включены в изменения.

## Assumptions

- Store настроен для обычного card notify без card-inclusive подписи; merchant
  configuration, API permissions и callback URLs подтверждает оператор WEBPAY.
- Shared persistent cache/locks, database worker и scheduler доступны всем
  экземплярам при staging/production; эти prerequisites описаны в документации.

## Unknowns

Реальные цены, merchant/API credentials, public HTTPS/base path, доставка notify,
фактический XML Sandbox, physical refund, staging/production hosting и acceptance
не проверены. Никакие локальные fixtures не заменяют эти внешние проверки.

## Risks / Next Step

Локальная реализация готова к отдельной внешней Sandbox-приёмке по
`docs/webpay.md` и `docs/deployment.md`. Deployment не выполнялся.

- Local payment implementation: done.
- Real WEBPAY Sandbox payment: NOT VERIFIED.
- Real notify delivery from WEBPAY: NOT VERIFIED locally.
- Real get_transaction: NOT VERIFIED locally.
- Lost-notify automatic confirmation via get_transaction: intentionally
  unsupported without previously trusted merchant-order binding.
- Manual Sandbox refund: NOT VERIFIED locally.

При полностью потерянном notify без binding платёж намеренно остаётся pending
и требует расследования WEBPAY/support и доставки доверенного уведомления.
