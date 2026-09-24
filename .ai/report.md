# Report: TASK-2026-09-24-04

Status: done

## Summary

Реализованы четыре изменения задачи: admin soft delete во всех статусах с
сохранением платёжной защиты; скрытие rejected только для психолога; ручная
коррекция дат размещения администратором; новая публичная анкета с email
soft-deleted психолога создаёт нового пользователя. Матрица статусов прежняя,
произвольного выбора статуса нет. Все обязательные проверки пройдены.

## Changed Files

- `application/app/Models/Group.php`, `Policies/GroupPolicy.php`,
  `Policies/GroupApplicationPolicy.php` — timestamp/cast/scope видимости и доступ.
- `application/app/Http/Controllers/Psychologist/{GroupController,ApplicationController}.php`,
  `Http/Requests/{GroupRequest,GroupActionRequest}.php`,
  `Services/ApplicationWorkflow.php` — общий scope на owner-маршрутах,
  включая прямые ссылки, изменение заявок, submit/delete/extension.
- `application/app/Services/GroupWorkflow.php` — раздельные эффекты admin delete
  и psychologist hide, транзакционное сохранение дат и бизнес-аудита.
- `application/app/Support/GroupPages.php`,
  `resources/views/shared/{group-form,group-delete}.blade.php` — Minsk-prefill,
  поля дат только в admin edit, подтверждения и ручные публикационные предупреждения.
- `application/app/Integration/IntakeService.php` — конфликт только с активной
  записью; прежние блокировки, active_email uniqueness и retries сохранены.
- `application/database/migrations/2026_09_24_000002_add_psychologist_deleted_at_to_groups.php`
  — nullable timestamp, owner/visibility index, backfill, безопасный down.
- `application/tests/Feature/{GroupWorkflowTest,PaymentEraGroupsTest,GroupVisibilityMigrationTest,IntegrationIntakeTest,IntegrationConcurrencyTest}.php`
  — актуальные правила, regression, migration, atomicity и concurrency.
- `SPEC.md`, `docs/{ui-pages,integration,deployment}.md` — реализованное поведение
  и ограничения отката; `.ai/report.md` — этот отчёт.

## Checks

PHP 8.2.32, MySQL 8.4. Базы тестовых suites использовались последовательно.
Внешние mail/WEBPAY вызовы не выполнялись, данные синтетические.

Из-за очень медленного чтения Windows/WSL bind mount проверки выполнены в
`/tmp/task04-check` PHP-контейнера. Копия включает существующие зависимости,
исходники и tests; `.env` создан из `.env.example`, runtime `.env` не копировался.
SHA-256 всех 19 изменённых application-файлов совпадает с рабочим репозиторием.

Префикс команд для этой копии:
`docker.exe compose exec -T -w /tmp/task04-check`.

- `-e CACHE_STORE=database php php artisan test --compact --filter='GroupVisibilityMigrationTest|GroupWorkflowTest|GroupLifecycleTest|PaymentEraGroupsTest|IntegrationIntakeTest|IntegrationConcurrencyTest|PsychologistAdminTest'`:
  **115 passed, 1961 assertions, 43.71 s**, exit 0.
  Включает rollback/backfill с retained relations на disposable MySQL,
  все статусы и исторические платежи, прямые owner-маршруты после скрытия,
  UTC/Minsk, nullable/invalid/partial date edits, warning reset, scheduler,
  минимальные audit metadata и откат при отказе аудита, active-email matrix,
  несколько исторических пользователей, replay и конкурентный reuse.
- `-e CACHE_STORE=database php php artisan test --compact`: полный MySQL suite —
  **465 passed, 5683 assertions, 183.60 s**, exit 0.
- `php ./vendor/bin/pint --test`: **PASS, 179 files**.
- Larastan в рабочем mount:
  `docker.exe compose exec -T php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`:
  **No errors**.
- `php composer check-platform-reqs`: **все требования success**, PHP 8.2.32.
- `php php artisan view:cache`: **Blade templates cached successfully**.
- Chromium/Playwright, **1440×900 и 390×900**: login, Minsk-prefill,
  ошибка при одинаковых датах, исправление/сохранение; active/approved delete
  warning и отмена; rejected-delete с подтверждением, исчезновение из owner-list,
  прямой show = 404. Admin затем открывает обе скрытые группы как rejected.
  Проверено отсутствие горизонтального overflow формы, визуально осмотрены
  screenshots формы и подтверждения на mobile. PASS.
- `git diff --check` и `git diff --cached --check`: PASS. Финальный diff/staged
  review: только файлы задачи, без секретов, runtime env, логов, uploads или
  посторонних артефактов.

Промежуточные сбои не скрываются: первый медленный прогон остановлен; первый
прогон копии дал 4 failed / 19 warnings (303 assertions), исходная ошибка —
неполный новый fixture заявки без `phone_normalized`, последующие — каскад
после незавершённого миграционного теста. Добавлены обязательное поле fixture и
`.env` из example для копии. Следующий прогон: 113 passed / 2 failed,
1952 assertions, 41.44 s; исправлены assertions порядка JSON-ключей MySQL и
сравнение модели до/после загрузки из БД. Финальный focused результат выше.
Первый Larastan нашёл недостаточный PHPDoc-тип `published_at`; тип уточнён.
Pint `--dirty` недоступен без Git внутри контейнера; форматирование выполнено
по явному списку файлов, затем полный `--test` прошёл.

Browser MCP не запустился из-за отсутствия настроенных Chromium-версий;
использованы уже установленные Playwright и Chromium 1243 без новых зависимостей.
Первый artisan serve без `--no-reload` отбрасывал DB env overrides и давал 404
после local login до любых действий с группами. Повторный стенд с `--no-reload`
использовал только отдельную `gruppa_task04_smoke`. Его контейнер и база удалены.
Screenshots/временные scripts находятся вне репозитория и не коммитятся.

## Facts

HEAD перед работой: `437dfa9`, актуальный planner; parent
`aff00f66d2dd75ac00025586bb03288d95abae28` соответствует gate. Working tree был
чистым. Task/workflow/AGENTS, предыдущий report, relevant SPEC/UI, перечисленные
доменные файлы и тесты изучены. Context7 использован; доступные docs относятся
к 13.x, поэтому scope и date validation сверены с установленным Laravel 12.
Sandbox launcher не работает из-за host mount `/mnt/wslg/distro`; использован
разрешённый escalation. `.ai/task.md`, `.env`, `.htaccess`, enum/transition
service, WEBPAY и публичный payload contract не изменены.

## Assumptions

Deployment выполняется штатным оператором с резервной копией и остановкой
пишущих процессов на время миграции. Production-доступ не использовался.

## Unknowns / Risks / Next Step

Новая миграция обязательна: из `application` выполнить
`php artisan migrate --force`, затем штатную очистку/пересборку кешей.
Проверена как additive миграция на данных и при чистом развёртывании smoke-БД;
`migrate:fresh` для production не требуется.

Backfill поднимает для admin только soft-deleted rejected, копируя старую дату
в `psychologist_deleted_at`. Down снова soft-delete скрытые записи, не затирает
более поздний admin deleted_at и сохраняет связи. При удалении нового поля
теряется различие источников удаления. Повторный up после rollback снова
поднимет все deleted rejected, в том числе удалённые admin после первого
развёртывания. Для точного восстановления нужна резервная копия. MySQL DDL
не транзакционен, поэтому deployment требует остановки writers.

Снятие публикации на gruppa.info остаётся ручным. Production acceptance
не выполнялась в рамках локальной разработки.
