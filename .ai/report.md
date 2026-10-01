# Report: TASK-2026-10-01-02

Status: done

## Summary

Реализована Cabinet-часть формы и содержимого группы: аддитивная схема,
санитизированное полное описание, приватная обложка, структурированное расписание,
город/периодичность, локальные тип группы и множественные подходы/теги.
Использованы существующие workflow, shared Blade views и CSS/JS без зависимостей.
Отправка психологом на модерацию и admin create требуют полного нового содержимого;
обычные legacy-изменения допускают отсутствие новых полей. Старый `schedule`
не разбирается и не перезаписывается, остаётся read-only fallback.

Outbound group sync, MODX upload, mapper, queue job при approval и неразрешённые
MODX mappings не добавлены. Реальных MODX-запросов не выполнялось.

## Hard Workflow Gate

До изменений проверены `git log --oneline -5`, `git status --short`, HEAD и parent:

- HEAD: `00131e1cb8b72d65ec2635f2eb5ff9cf11f0f569`, planner текущей задачи.
- Parent: `af89db87412d31cf7787163bff67c919b7bccc20`, совпадает с task parent.
- Начальная рабочая директория чистая; неизвестных локальных изменений нет.
- Прочитаны WORKFLOW.md, AGENTS.md, текущие task/report, релевантные Stage 17 и
  group-field разделы SPEC.md, modx-group-sync-plan/modx-api/project-status/ui-pages.
- Проверены все перечисленные gate implementation-файлы: Group и миграции,
  GroupRequest/Workflow/оба Controller/Policy/Pages, shared form/data/summary,
  компоненты и CSS/JS, filesystem/PsychologistDocuments, dictionary models/usage/
  managed behavior, workflow/prototype/dictionary tests, Composer и Docker PHP.
- `.ai/task.md` не изменён. Изменения ограничены этой задачей.

## Changed Files

- Новая `2026_10_01_000002_add_group_content.php`: nullable поля и два pivot,
  restrict FK dictionary items, уникальные пары, cascade physical group deletion.
- Group model, DictionaryUsage: связи/casts и учёт исторических ссылок.
- GroupContent, GroupHtmlSanitizer, GroupCovers: общие правила новых полей,
  DOM-реконструкция разрешённой HTML-грамматики, приватные случайные имена,
  проверка MIME/содержимого/размера и явная компенсация ошибок хранения.
- GroupRequest/GroupWorkflow: сохранение скаляров, pivot и submit/history в одной
  транзакции; отсутствующие новые поля не стирают существующие. Lifecycle,
  защищённые поля, payments и approval переход сохранены.
- GroupPages, два GroupController и web routes: local options, eager loading,
  owner/admin inline preview с MIME/nosniff/private no-store и 404 отсутствующего файла.
- Shared group-form/group-data, rich-text/multi-select components, ui.js/ui.css,
  PrototypeFixtures: новая общая форма/карточка, editor и поиск, native fallback.
- groups.php, `.env.example`: HTML ceiling 100000 символов, JPEG/PNG/WebP,
  приватный local disk, `GROUP_COVER_MAX_KB=5120`.
- composer.json/lock: только explicit ext-dom и актуальный content-hash;
  версии библиотек не менялись.
- Четыре новых feature-test класса, синтетический GroupContentFixture,
  адаптация complete-content fixtures существующего GroupWorkflowTest.
- docs/modx-group-sync-plan, project-status, architecture, development,
  deployment, ui-pages и этот отчёт.

## Checks

PHP 8.2.32 / MySQL 8.4. DB-тесты выполнены последовательно в изолированной копии
`/tmp/group-content-check` PHP-контейнера, с тестовой БД `gruppa_cabinet_test`.
Рабочие `.env*`, storage и caches в неё не копировались; runtime создан из
`.env.example`. Laravel HTTP fakes и глобальный preventStrayRequests запрещают
реальный MODX transport. Обложки — синтетические изображения и Storage::fake.
SHA-256 всех **29 изменённых/новых application-файлов** совпали с тестовой копией.

Финальные результаты:

1. `php artisan test --compact --filter="GroupContent|GroupCover|GroupHtmlSanitizer"`:
   **40 passed, 326 assertions**, 15.98 s. Upgrade/nullability/FK/unique/casts,
   dictionary validation, completeness/legacy, HTML security, private upload/
   preview/replacement/failures, scalar+pivot+cover rollback и real HTTP/Blade flow.
2. `php artisan test --compact --filter="GroupWorkflowTest|PrototypeTest"`:
   **57 passed, 1756 assertions**, 14.87 s. Весь GroupWorkflowTest и весь
   PrototypeTest, включая все **249** документированных вариантов прототипов,
   lifecycle/payment/deletion/date regressions и постоянное число list queries.
3. `php artisan test --compact --filter="DictionaryAdminTest|ModxDictionary|AuthenticationTest|PsychologistDocumentTest"`:
   **84 passed, 867 assertions**, 31.10 s. Local/managed dictionary regressions,
   HTTP-fake MODX sync, authorization и существующие приватные документы.
4. `php artisan test --compact`: **555 passed, 6442 assertions**, 293.88 s.
   После полного прогона добавлена только защитная default-ветка MIME match для
   PHPStan и CSS `clear:both` для нового weekday fieldset. Финальный focused
   content/upload набор и workflow/prototype набор повторно прошли (пункты 1–2).
5. `php ./vendor/bin/pint --test`: **PASS, 197 files**.
6. `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`:
   **No errors**.
7. `composer check-platform-reqs`: **все success**, в том числе ext-dom.
8. `composer validate --no-check-publish`: **composer.json is valid**.
9. `php artisan view:cache`: **Blade templates cached successfully**
   в изолированной копии, без production runtime/cache изменений.
10. `node --check application/public/ui.js`: **exit 0**; Node использован только
    для синтаксической проверки, build pipeline/пакеты не добавлены.
11. `git diff --check`: **exit 0**. Полный diff и новые файлы просмотрены.

Промежуточные ошибки исправлены: отсутствующий code у synthetic dictionary item,
nullable admin flag у свежего Eloquent User, цена из HTTP fixture в прямом domain
вызове, замечания PHPStan/Pint. Приведены результаты последних успешных прогонов.

## Browser / Runtime Verification

Playwright MCP и Chrome DevTools MCP не запустились из-за отсутствующих Linux
Chromium executables. Вместо них использован установленный Windows Chrome
headless с временным отдельным профилем и автономной страницей, отрендеренной
из настоящего shared Blade form с synthetic PrototypeFixtures и текущими assets.
MODX/production URL не открывались. Временные HTML/profile удалены из репозитория.

Автоматический Chrome smoke: **17 проверок прошли** на desktop (innerWidth 1424)
и на **390 px** в локальном iframe. Проверены 121 вариант selector, поиск/выбор
и запись native select, безопасное old input, bold/italic/H2/H3/оба списка/quote,
ссылка, remove-format, синхронизация чистой textarea при submit, отсутствие
schedule/leader input и горизонтального переполнения. На narrow-screen найдено
и устранено наложение weekday fieldset из-за float legend Bootstrap.
Отдельно без application JS проверены видимость textarea и обоих native multiple
select, отсутствие enhanced controls и schedule/leader; все четыре проверки прошли.

Это автоматические browser/feature проверки, не ручная пользовательская приёмка.
Живой authenticated browser POST с серверной сессией отдельно не выполнялся;
полный save/submit/redirect/detail/preview flow проверен Laravel feature-тестами.

## Risks / Remaining Work

- Применить миграцию и проверить ext-dom в CLI/web PHP целевого хостинга перед
  rollout; production deployment этой задачей не выполнялся.
- Приватные файлы и DB не образуют распределённую транзакцию: обработанные ошибки
  компенсируются, а аварийное завершение процесса может оставить orphan-файл.
  Неуспешная очистка после commit явно сообщает, что данные уже сохранены.
- MODX cover transport, outbound mapper/job, serialization и неразрешённые
  price/public_uuid/SEO mappings остаются отдельными будущими этапами.

## Git Review

Полный implementation/documentation diff и все новые файлы просмотрены.
`git diff --cached --check` — exit 0. Staged manifest: **36 файлов**, ровно
29 application-файлов, шесть документов и `.ai/report.md`; каждый staged blob
совпадает с просмотренным рабочим файлом. Незастейдженных изменений нет.
`.ai/task.md` совпадает с HEAD. Проверены отсутствие outbound HTTP/job/schema
добавлений, секретов, uploads, storage/cache/log/vendor и временного Chrome
профиля; из env-файлов staged только разрешённый `.env.example`.
Hard Workflow Gate пройден; разрешён task commit:
`codex: TASK-2026-10-01-02 expand group content form`.
Accept commit не создаётся.
