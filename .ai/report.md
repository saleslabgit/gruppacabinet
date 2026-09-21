# Report: TASK-2026-09-21-06

Status: done

## Summary

Реализован Stage 8: административное управление справочниками/элементами,
семью бизнес-настройками и информационная страница платежей до WEBPAY.
Использованы существующие утверждённые Blade views и CSS; параллельных страниц,
миграций, зависимостей и frontend build tools не добавлено.

- 15 реальных маршрутов под account + role:admin: контейнеры и вложенные items,
  GET/PUT settings, только GET payments. Навигация содержит Главная, Психологи,
  Группы, Платежи, Справочники, Настройки, Выход; заявок нет.
- Стабильные коды create-only, Form Requests, явные allowlists полей, scoped
  binding с 404 для несовпадающих dictionary/item. Страницы имеют 20 строк,
  детерминированный порядок, aggregate counts/usage без N+1.
- DictionaryManagement блокирует родитель/элемент в транзакции; DictionaryUsage
  учитывает education_type/users и format/gender/groups, включая soft-deleted
  записи. Системные контейнеры и используемые элементы нельзя удалить.
  Удаление допустимых записей и деактивация подтверждаются; реактивация идемпотентна.
- SettingService::update принимает ровно семь известных ключей и int/null,
  блокирует существующие строки, сохраняет настройки и AuditService entries
  одной транзакцией. Минимальный setting.updated содержит actor и key/old/new;
  неизменённые значения не аудируются. DB::afterCommit сбрасывает кеш;
  чтения внутри транзакций не используют/не заполняют общий кеш.
- BynAmount переводит decimal string в minor units без float и отвергает overflow.
  Nullable цены, положительные целые и warning < placement валидируются сервером.
  Технические пределы: PHP_INT_MAX для целых/копеек, unsigned INT для sort_order,
  placement days — число полных дней до предела MySQL TIMESTAMP в 2038 году.
- Общий confirmation получил необязательный ID существующей формы: кнопка
  отправляет её поля, CSRF, method override и confirmed=1. Прежние URL-based
  confirmations и prototype no-op сохранены.
- Платежи отображают только «Платежи ещё не подключены»: нет строк, фильтров,
  detail/refund/mutation routes, запросов платёжных таблиц или provider actions.

## Changed Files

Production PHP:

- `application/routes/web.php` и `app/Support/PsychologistPages.php` — маршруты/навигация.
- `app/Http/Controllers/Admin/{DictionaryController,DictionaryItemController,SettingController}.php`.
- `app/Http/Requests/{DictionaryRequest,DictionaryItemRequest,DictionaryActionRequest,SettingRequest}.php`.
- `app/Policies/{DictionaryPolicy,SettingPolicy}.php`.
- `app/Services/{DictionaryManagement,DictionaryUsage,SettingService}.php`.
- `app/Support/BynAmount.php`, `app/Models/Dictionary.php` (типизация отношения).

Все пути app/ выше относительно `application/`.

Blade:

- `application/resources/views/admin/dictionaries/{index,items}.blade.php`.
- `application/resources/views/admin/settings/index.blade.php`.
- `application/resources/views/admin/payments/index.blade.php`.
- `application/resources/views/components/confirmation.blade.php`.

Tests/docs:

- `application/tests/Feature/{DictionaryAdminTest,SettingsAdminTest}.php`.
- `application/tests/Feature/Domain/SettingsAndSeedTest.php`: существующий тест
  кеширования перенесён без потери проверок в SettingsAdminTest с настоящими
  commit, поскольку RefreshDatabase оборачивает тест в незавершённую транзакцию.
- `docs/{architecture,development,project-status,ui-pages}.md`, `.ai/report.md`.

`.ai/task.md`, SPEC.md, WORKFLOW.md, AGENTS.md не изменены.

## Checks

### Автоматические проверки

- Исходное состояние чистое; HEAD `27b9878` — актуальный planner этой задачи.
  Его родитель подтверждён: `1f5182039fadcfe53bb737c30748104c2535308c`.
- `docker compose ps`: mysql/php healthy, web Up.
- `docker compose exec -T php php artisan migrate --seed --force`: Nothing to
  migrate; idempotent seed успешно. Локальная БД не сбрасывалась.
- Точечные MySQL-проверки словарей прошли; повторный прогон
  `php artisan test --filter='SettingsAdminTest|SettingsAndSeedTest'`:
  19 passed, 158 assertions, 83.14 s.
- Первый прогон обнаружил неправильное ожидание порядка JSON-ключей в тесте
  и отсутствие bail перед проверкой суммы при array-вводе. Оба исправлены.
- `docker compose exec -T php php artisan test`: **268 passed, 2811 assertions,
  250.70 s**. Stage 4–7 regression и все 31 группы / 249 prototype variants прошли.
- `docker compose exec -T php ./vendor/bin/pint --test`: PASS, 104 files.
  `pint --dirty` не поддерживается без .git внутри контейнера; форматирование
  выполнялось явным списком только изменённых PHP-файлов.
- `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`:
  [OK] No errors после уточнения типа Dictionary::items и PHPDoc.
- `docker compose exec -T php composer check-platform-reqs`: все требования
  success, PHP 8.2.32.
- `docker compose exec -T php php artisan view:cache`: успешно.
- `docker compose exec -T php php artisan route:list --json`: 92 routes,
  из них 15 Stage 8. `docker compose exec -T -e APP_ENV=production -e APP_DEBUG=false
  php php artisan route:list --json`: 58 routes, 0 prototype/foundation/redirect-check,
  те же 15 Stage 8. У payments только GET|HEAD index.

- `git diff --check` и `git diff --cached --check`: успешно. Просмотрены diff и
  staged-состав: 29 файлов только Stage 8, тесты, документация и отчёт. Секретов,
  локальных конфигураций, реальных персональных/платёжных данных, screenshots,
  логов и browser artifacts в индексе нет.

### SQL и транзакционные проверки

Реальный HTTP kernel с array-session, без изменения бизнес-данных:

- dictionaries: 3 SQL (account + count + list с counts);
- dictionary items: 4 SQL (account + parent + count + list с usage subquery);
- payments: 1 SQL (account), 0 запросов gp_payments/gp_payment_notifications.

MySQL-тест сравнивает query count при увеличении количества строк и проверяет
пагинацию 20, порядок, scoped uniqueness, все item IDOR actions, core/non-empty
protection, soft-deleted usage. Проверены реальные mutations → новые формы →
неактивные текущие selections → сохранение существующих записей → reactivation.

Настройки проверяются с реальными commit: nullable/decimal/максимальная сумма,
отрицательные/экспонента/лишние знаки/массив/overflow, integer/cross-field validation,
unknown keys/missing rows/types, unchanged audit, actor/minimal metadata,
audit failure rollback, outer rollback, сброс кеша после commit, snapshot
старой группы и новый duration следующей активации, отсутствие payment effects.

### Реальный браузер через Docker/Nginx

`node /tmp/stage8-smoke.cjs`: PASS через уже установленный Chromium 1243 и
Playwright, URL `http://localhost:8080/cabinet`. MCP браузеры не запускались
из-за отсутствующих настроенных executable paths; установки зависимостей
проекта не потребовалось. Скрипты/логи/screenshots только в `/tmp`.

Проверены вход администратора, создание/редактирование/подтверждённое удаление
custom container, добавление значений в три core dictionaries, item edit/sort,
удаление unused item, деактивация used item и реактивация. После каждого
изменения проверены реальные psychologist/group формы, отсутствие значения
в новых и сохранение текущего inactive option в существующих записях.

Все семь настроек сохранены через реальную модальную форму. SQL подтвердил
5000/2550 minor units и семь actor=admin setting.updated entries с минимальной
metadata. В UI обратно показаны 50,00/25,50. Новая группа получила 41 день;
прежняя сохранила 30 дней и исходные published_at/expires_at. До и после —
0 платежей. После сценария исходные локальные настройки восстановлены через UI.
Синтетические локальные элементы/психолог/группы оставлены для ручной проверки;
это не утверждённое production-содержимое справочников.

Для dictionaries/items/settings/payments выполнены геометрическая проверка и
просмотр снимков 1440/1024/390: нет горизонтального выхода за viewport,
сохранены таблицы/мобильные карточки, поля и действия. Психолог в отдельной
browser session получает 403 на всех четырёх разделах.

`node /tmp/stage8-extra.cjs`: PASS — серверная ошибка суммы с сохранением
старого ввода, мобильное подтверждение, деактивация через checkbox в edit-форме
и последующая реактивация. Проверены итоговый required sort_order и desktop
Payments. Первое выполнение этого дополнительного скрипта остановилось на
неверном ожидании строкового значения boolean HTML-атрибута required; исправлен
только временный проверочный скрипт, product-код не менялся.

## Facts

- Новые данные справочников доступны Stage 5/7 без изменения исходников или seed.
- Существующие active placement snapshots не меняются от settings update.
- Новых migrations, packages, CSS/JS, WEBPAY credentials/config/provider code нет.
- Prototype catalogue и production isolation сохранены.
- Все изменения относятся к Stage 8 и его проверкам/документации.

## Assumptions

- Локальные marker-значения предназначены исключительно для проверки интерфейса.
- Для custom dictionaries текущая схема не содержит application references;
  при появлении новых связей DictionaryUsage потребуется расширить явно.

## Unknowns

- Production dictionary content и реальные бизнес-цены не определялись задачей.
- Production deployment, Stage 9+ и WEBPAY не выполнялись и не проверялись.

## Risks / Next Step

Все обязательные проверки пройдены. Результат готов к приёмке Stage 8.
Следующий продуктовый этап — Stage 9; он в эту реализацию не входит.
Локальные синтетические данные не являются production-справочниками; исходные
локальные бизнес-настройки восстановлены после browser smoke.
