# Report: TASK-2026-10-01-03

Status: done

## Summary

Реализована Cabinet-часть полного outbound Cabinet → MODX flow: проверка готовности,
единый mapping, HTTPS JSON client, аддитивная схема состояния, database queue job,
after-commit scheduling, сохранение Resource ID, cover transport/tracking,
идемпотентность и overlap protection, admin status/manual resync.
Все обязательные проверки завершены успешно. Hard Workflow Gate пройден;
результат подготовлен к предусмотренному task commit.

Внешний MODX plugin не изменялся и не добавлялся в репозиторий.
Реальная MODX acceptance не запускалась Codex/тестами: HTTP только через Laravel
fakes, изображения синтетические, приватное хранилище — Storage::fake.

## Hard Workflow Gate

- Исходный HEAD: `75ca664`, planner TASK-2026-10-01-03.
- Parent: `a1009c8736064347a3e6df5b9f1048ff8f1a3061`, совпал с task.
- Перед изменениями выполнены `git log --oneline -5`, `git status --short`;
  рабочая директория была чистой, неизвестных локальных изменений не было.
- Прочитаны WORKFLOW/AGENTS/task/report, релевантные group/Stage 17/MODX разделы
  SPEC и требуемая документация. Изучены Group/schema, оба group controller,
  requests/policy, workflow/content/covers/transitions, GroupPages/Blade/CSS,
  dictionary models/client/service, queue/cache/schedule и существующие тесты.
- Laravel queue/after-commit API сверены через Context7 и установленные зависимости.
- `.ai/task.md`, inbound dictionary client, внешний plugin и payment/lifecycle
  реализации не изменены. Изменения ограничены заданием.

## Changed Files

- `2026_10_01_000003_add_group_modx_sync.php`: nullable unique remote Resource ID,
  revision/status/timestamps, safe error, cover path/source, payload SHA-256,
  дополнительный attempted request key и boolean cleanup warning. Старые миграции
  не менялись. Group получил casts и защиту существующего remote ID от замены.
- `GroupModxReadiness`: полное содержимое, целые BYN, непустые имя/фамилия,
  активные значения правильных MODX-managed справочников и фактическая обложка.
  Обычные legacy saves сохраняют прежние возможности.
- `GroupModxPayloadBuilder`: точные Resource/TV/MIGX значения, UUID→groupid,
  canonical days, HH:MM, ordered || values, minor→whole BYN, create-only alias.
  price_usd/SEO/showOnMainPage/publication flags отсутствуют в payload.
- `GroupCovers`: безопасное чтение существующего private image с повторной
  проверкой размера/MIME. Mapper отправляет base64 только для новой обложки;
  неизменённую reasserts через image TV, tracking меняется только после успеха.
- `Modx/GroupClient`, `ModxGroupSyncException`, services config/`.env.example`:
  HTTPS-only/no redirects, exact raw JSON/key, bounded 60-second timeout,
  проверка ответа, retryable/permanent/conflict codes без исходных исключений.
- `GroupModxSyncScheduler`, `SyncGroupToModx`, GroupWorkflow:
  approval/content edit/manual resync scheduling после commit, четыре попытки,
  backoff 60/300/900, timeout 75, per-group cache lock, safe state transitions.
  DB locks не удерживаются во время HTTP. Устаревший успешный create сохраняет ID
  и cover identity, оставляя новую редакцию pending; старые ошибки её не меняют.
  Hash/key guard запрещает отправлять изменённое тело под уже использованным ключом.
- Admin controller/policy/route, `_modx-sync` panel, status explanations и две
  существующие UI-подсказки: status/ID/timestamps/safe errors/cleanup warning,
  POST manual resync без синхронного HTTP, честная ошибка при queue outage.
  Психологам panel/action недоступны; новая визуальная система не создавалась.
- `ModxGroupClientTest`, `ModxGroupSyncTest`: mapping, readiness, transport,
  idempotency/stale work/identity/cover/admin, настоящий database worker с HTTP
  fake, retry exhaustion и shared database cache lock.
  Existing content/workflow fixtures приведены к новому sync-ready контракту;
  fractional legacy save покрыт отдельно, устаревшая UI-подсказка обновлена.
- SPEC и docs: modx-api, modx-group-sync-plan, project-status, architecture,
  development, deployment, ui-pages. Mapping/outbound отмечены implemented;
  external live acceptance и лимиты hosting явно остаются unverified.

## Checks

Проверки выполняются на PHP 8.2.32 / MySQL 8.4 в `/tmp/modx-group-check`
локального PHP-контейнера, БД `gruppa_cabinet_test`. Рабочие `.env`, storage/logs,
uploads и caches не копировались. Зависимости для финальных прогонов скопированы
в изолированный vendor; runtime создан из `.env.example`. Production MODX не вызывался. SHA-256 всех 24 изменённых/новых application-файлов
совпали с изолированной тестовой копией.

Финальные результаты:

- Финальный `php artisan test --compact --filter=ModxGroup`: **57 passed, 423 assertions**, 17.34 s.

- Расширенный regression command:
  `php artisan test --compact --filter="ModxGroup|ModxDictionarySyncTest|GroupContent|GroupCover|GroupHtmlSanitizer|GroupWorkflowTest|GroupLifecycle|DictionaryAdminTest|PrototypeTest"`
  — **224 passed, 3386 assertions**, 60.73 s.
- Первый полный `php artisan test --compact`: **610 passed, 1 failed**,
  6843 assertions, 435.96 s. Единственный сбой — assertion старой подсказки
  «вручную перенести в каталог»; expectation исправлен на очередь.
- Финальный полный `php artisan test --compact --log-junit=/tmp/modx-group-final-junit.xml`: **612 passed, 6858 assertions**, 644.14 s (CLI; JUnit 643.89 s).
- Финальный `php ./vendor/bin/pint --test`: **PASS, 207 files**.
- Финальный `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`:
  **No errors**.
- `php /tmp/group-composer.phar check-platform-reqs`: все требования **success**.
- `php /tmp/group-composer.phar validate --no-check-publish`: **composer.json is valid**.
- `php artisan view:cache`: **Blade templates cached successfully**.
- `php artisan schedule:list`: успешно; пять существующих scheduled commands,
  включая hourly dictionary sync; команды не выполнялись.
- `git diff --check`: exit 0.

Результаты обязательных классов из финального полного JUnit (все cases/classes
выполнены целиком, без exclusions):

| Набор | Tests | Assertions | Failures/errors |
|---|---:|---:|---:|
| ModxGroupClientTest | 30 | 191 | 0 |
| ModxGroupSyncTest | 27 | 232 | 0 |
| ModxDictionarySyncTest | 44 | 359 | 0 |
| GroupContentMigrationTest | 1 | 61 | 0 |
| GroupContentTest | 15 | 173 | 0 |
| GroupCoverTest | 7 | 84 | 0 |
| GroupHtmlSanitizerTest | 17 | 21 | 0 |
| GroupWorkflowTest | 48 | 631 | 0 |
| GroupLifecycleTest | 19 | 300 | 0 |
| GroupLifecycleConcurrencyTest | 2 | 41 | 0 |
| DictionaryAdminTest | 6 | 177 | 0 |
| PrototypeTest | 9 | 1128 | 0 |

Изначальные новые тесты имели ошибки reset HTTP fake/settings fixture; исправлены.
Замечания Pint/PHPStan исправлены. Ни одна проваленная проверка не указана как успешная.

## Facts

- Create key всегда `group-create:<public_uuid>`, update key —
  `group-update:<public_uuid>:<revision>`; автоматической ротации create key нет.
- JSON формируется один раз на попытку; сохраняются только key/hash и metadata.
  При изменении rebuilt тела для прежнего ключа — conflict без новой HTTP отправки.
- Approval не зависит от MODX availability и не откатывается при транспортной
  ошибке. При backoff статус pending; при исчерпании попыток failed.
- Publication/unpublication/deletion на MODX не вызываются; activation/payment/
  expiration правила сохранены.
- Настоящий database worker проверен с HTTP fakes: успешный job, отложенный retry,
  исчерпание четырёх попыток, сохранение ID, отсутствие payload/секретов в failed_jobs,
  удержание per-group database cache lock во время вызова.
- HTTP feature tests рендерят реальные Blade views и проверяют admin actions,
  статусы, безопасность/роль и сохранность прежних query-count/list regressions.
  Отдельный визуальный browser smoke в этой задаче не выполнялся.

## Assumptions

- Внешний plugin реализует контракт из task, согласно сообщению владельца.
- Production web/cron/worker используют общие database queue/cache и prefix;
  timeout worker/job и retry_after согласованы согласно deployment checklist.

## Unknowns

- Production API credential, body/upload/PHP limits, Media Source permissions,
  реальная очередь/cron и live MODX create/update/upload не проверены.
- mxHeadless idempotency TTL по контракту по умолчанию 86400 секунд; реальное
  production значение неизвестно. Постоянный UUID lookup endpoint не гарантирован.

## Risks / Next Step

Полный diff и staged-файлы проверены: только 33 файла задачи. Нет private .env,
секретов, реальных production fixtures, image/base64 uploads, storage/cache/log/
vendor и временных артефактов; внешний plugin не добавлен. `git diff --check` и
`git diff --cached --check` проходят. Task/WORKFLOW/AGENTS не изменялись.
Migration rollback удаляет remote identity: после live sync нужно сохранять и
восстанавливать эти связи из backup до повторного включения outbound.

После deployment владельцу выполнить external end-to-end acceptance по docs.
Неоднозначный первоначальный create после истечения remote idempotency TTL требует
сверки с MODX перед повтором: постоянный ключ сам по себе не даёт бессрочной
дедупликации после удаления remote cache. Автоматического восстановления через
произвольное редактирование Resource ID в этой задаче нет.
