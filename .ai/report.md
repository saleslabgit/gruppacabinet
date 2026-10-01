# Report: TASK-2026-10-01-04

Status: done

## Summary

Реализованы упрощение редактора, необязательная валютная цена, пауза/возобновление
с замороженным сроком и асинхронное управление публикацией MODX при
паузе, удалении и истечении размещения. Первичная публикация и публикация
после продления expired остаются ручными. Все обязательные проверки пройдены; результат готов к task commit.

## Hard Workflow Gate

- Исходный HEAD `39fc5c466886c70b884f6c69f9e420ed1c1459f1` — актуальный planner;
  parent `fb108792cdfb8902342b6f0ab79afeff27bdaad8` совпал с task.
- Accepted base `344d6a6ed5d372d6852c54ea7e8a1676861f40bb` подтверждён в ancestry.
- Выполнены `git log --oneline -5`, `git status --short`; исходная директория чистая.
- Прочитаны WORKFLOW/AGENTS/task/report, релевантные SPEC/docs, существующие
  Blade/CSS/JS, lifecycle/payment/intake/expiry-warning и MODX code/tests.
- Context7 использован для Laravel queue API; возвращённая документация
  дополнительно сверена с установленным Laravel: `WithoutOverlapping::shared()`
  действительно объединяет блокировку разных классов jobs.
- Task/WORKFLOW/AGENTS не изменены. Реальный MODX endpoint не вызывался.

## Changed Files

- Миграция `2026_10_01_000004_add_group_publication_lifecycle.php`: nullable
  `meeting_price_currency`, `paused_at`, revision/desired/status/timestamps/safe code.
  Старые миграции не менялись; upgrade/rollback проверяются на синтетической записи.
- GroupStatus/Group/GroupPolicy/GroupWorkflow/GroupLifecycleService, requests,
  psychologist controller/routes: авторизация владельца, row locks, история,
  pause active→paused и resume через очередь; expiration/delete запрашивают
  unpublished после commit. Удаление психологом всегда скрывает через
  psychologist_deleted_at; admin soft-delete/audit сохранены. Оплата не блокирует
  удаление, платежи и история не меняются, автоматического возврата нет.
- `GroupModxPublicationScheduler`, `SetGroupModxPublication`, `Modx/GroupClient`:
  отдельный publication endpoint, exact JSON/key, четыре попытки и backoff
  60/300/900, safe retry/permanent/conflict, withTrashed, revision/Resource ID guards.
  Content и publication jobs используют общий `modx-group:<id>` shared lock.
- Resume сохраняет paused до подтверждения MODX. Затем system transition,
  expires_at + точная длительность паузы в секундах, очистка paused_at/warning marker
  и отметка успеха выполняются атомарно. Повтор завершённой revision пропускается;
  rollback сохраняет paused_at, поэтому повтор не добавляет время дважды.
- GroupRequest/GroupWorkflow/GroupPages/form/detail/fixtures/payload builder:
  nullable trim/max255 currency, old input, escaped detail, непустое → price_usd,
  пустое → omission с сохранением удалённого значения; основной BYN не меняется.
- ui.js и существующие Blade components: удалены Paragraph/H2/H3/Quote/Link и две
  helper-строки. Bold/Italic/UL/OL/Remove и native fallback сохранены. Серверная
  и клиентская безопасная грамматика legacy HTML не сужалась.
- Существующие group actions/summary/delete/admin MODX panel/status/history:
  pause/resume confirmations, pending/failure/conflict, truthful publication state,
  удаление без ручного unpublish/refund blocker. Каталог: 31 группа / 259 вариантов.
- IntakeService дополнительно исключает psychologist-hidden active группы:
  после расширения удаления на active они больше не могут принимать новые заявки.
  Paused уже исключается active-only условием. Expiry-warning и extension
  active-only/active-expired ограничения сохранены.
- GroupPublicationTest и расширения content/migration/client/sync/workflow/lifecycle/
  expiry-warning/intake/prototype/payment/matrix tests; прежние ожидания запрета
  удаления после оплаты обновлены под новую задачу.
- SPEC и docs: modx-api, modx-group-sync-plan, project-status, architecture,
  development, deployment, ui-pages. Описаны внешний контракт и ручная live-приёмка.

## Checks

PHP 8.2.32 / MySQL 8.4, изолированная копия `/tmp/modx-group-check` в локальном
PHP-контейнере, выделенная `gruppa_cabinet_test`. Рабочие .env/storage/cache/uploads
в копию не переносились. HTTP — Laravel fakes с глобальным запретом stray requests.
SHA-256 всех 48 изменённых application-файлов совпал с финальной тестовой копией.

- Первый focused прогон: **97 passed, 4 failed**. Исправлены отсутствующие настройки
  в новой fixture, stale test model и два устаревших prototype expectations.
- Расширенный focused:
  `php artisan test --compact --filter="GroupPublicationTest|GroupContentTest|GroupWorkflowTest|GroupLifecycleTest|PaymentEraGroupsTest|PrototypeTest|ModxGroup"`
  — **202 passed, 3049 assertions**, 26.64 s.
- Первый полный:
  `php artisan test --compact --log-junit=/tmp/group-publication-full.xml`
  — **681 passed, 1 failed, 7260 assertions**, 453.49 s. Единственный сбой:
  WebpayTest ожидал прежний запрет удаления после успешной оплаты; expectation
  исправлен, реализация платежей не менялась.
- Финальный полный: `php artisan test --compact --log-junit=/tmp/group-publication-final.xml`
  — **683 passed, 7374 assertions**, 519.37 s. Нет failures/errors/skips.
- `php ./vendor/bin/pint --test` — **PASS, 211 files**. Первичные пять замечаний
  исправлены только в затронутых файлах.
- `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — **No errors**.
  Первичное замечание типа paused_at устранено model PHPDoc.
- `php /tmp/group-composer.phar check-platform-reqs` — все требования **success**.
- `php /tmp/group-composer.phar validate --no-check-publish` — **composer.json is valid**.
- `php artisan view:cache` — **Blade templates cached successfully**.
- `php artisan schedule:list` — успешно; пять существующих commands, включая
  groups:expire и groups:queue-expiry-warnings. Scheduled commands не запускались.
- `git diff --check` — exit 0.

HTTP feature tests проверяют реальные Blade страницы и POST actions, авторизацию,
подтверждение, paused/pending/error состояния. Database worker проверен с HTTP fake:
shared lock, retry/exhaustion, идемпотентное remote success, rollback local transition,
удаление во время remote resume, stale revisions/results и сохранность срока.
Отдельный визуальный browser smoke и настоящий MODX acceptance не выполнялись.

Полные классы из финального JUnit (без exclusions):

| Класс | Tests | Assertions | Failures/errors |
|---|---:|---:|---:|
| ApplicationWorkflowTest | 26 | 239 | 0 |
| ExpiryWarningTest | 16 | 222 | 0 |
| GroupContentMigrationTest | 2 | 127 | 0 |
| GroupContentTest | 17 | 217 | 0 |
| GroupCoverTest | 7 | 84 | 0 |
| GroupHtmlSanitizerTest | 17 | 21 | 0 |
| GroupLifecycleConcurrencyTest | 2 | 44 | 0 |
| GroupLifecycleTest | 19 | 300 | 0 |
| GroupPublicationTest | 21 | 166 | 0 |
| GroupWorkflowTest | 50 | 658 | 0 |
| IntegrationConcurrencyTest | 3 | 67 | 0 |
| IntegrationIntakeTest | 25 | 625 | 0 |
| ModxDictionarySyncTest | 44 | 359 | 0 |
| ModxGroupClientTest | 57 | 298 | 0 |
| ModxGroupSyncTest | 28 | 236 | 0 |
| PaymentEraGroupsTest | 4 | 54 | 0 |
| PrototypeTest | 9 | 1164 | 0 |
| WebpayConcurrencyTest | 6 | 173 | 0 |
| WebpayTest | 26 | 272 | 0 |

## Facts

- Initial approved→active остаётся ручным подтверждением администратора; оно
  записывает published state без HTTP. Автоматический publish возможен для resume.
- Ключ `group-publication:<public_uuid>:<resource_id>:<revision>` меняется для новой
  логической операции, но не для retry одной revision. Resume после conflict
  не выдаёт новый ключ: требуется сверка оператора.
- Успех публикации и продление срока фиксируются одной транзакцией. Ни один DB lock
  не держится во время HTTP. Удаление создаёт более новый unpublished intent,
  поэтому устаревшее resume не реактивирует скрытую/удалённую группу.
- Пауза, удаление и expired сохраняются при queue/MODX failures. Неуспешное resume
  оставляет paused и замороженное время. Remote Resource никогда не удаляется.
- Admin content edit/resync paused обновляет тот же Resource без publication flags.
- Длительность паузы вычисляется с точностью хранения существующих timestamp — секунды.

## Assumptions

- MODX operator реализует внешний publication endpoint по фиксированному контракту.
- Production workers используют общие database queue/cache и настройки блокировок.

## Unknowns

- Реальная доступность publication endpoint, его idempotency/cache/event поведение
  и production конфигурация не проверены; автоматических real HTTP вызовов не было.

## Risks / Next Step

После deployment нужны миграция и согласованный restart content/publication workers:
старые workers ещё используют прежний lock namespace. Нельзя удалять pause/revision
metadata rollback-миграцией при существующих paused группах. Очередь и MODX проверяет
оператор; recovery существующей revision и ручная external acceptance описаны в
`docs/deployment.md`. Платёжные возвраты остаются отдельным ручным процессом.

Финальный полный и staged diff проверены: 57 файлов текущей задачи.
В staged нет секретов, private config, production fixtures, uploads/base64-файлов,
логов, кэшей, vendor или внешнего plugin source. `git diff --check` и
`git diff --cached --check` проходят. Реальный publication endpoint не вызывался.
