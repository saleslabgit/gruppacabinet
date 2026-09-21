# Report: TASK-2026-09-21-08

Status: done

## Summary

Реализован Stage 10: внутренние заявки участников, счётчики групп, доступ
владельца, обработка/возврат, административный поиск и ежедневная очистка.
Использованы утверждённые Blade views и общие компоненты без изменения CSS.
Stage 11 API/intake, email и WEBPAY не реализовывались.

## Changed Files

- `app/Models/GroupApplication.php`, `database/factories/GroupApplicationFactory.php`:
  типизированная связь, фабрика с обязательной существующей группой, явно
  синтетическими именами/вымышленными телефонами и processed/unprocessed states.
- `app/Support/PhoneNormalizer.php`: международный формат +digits, поддержка 00,
  отдельный ключ поиска; неоднозначные местные номера явно отклоняются.
- `app/Http/Controllers/{Psychologist,Admin}/ApplicationController.php`,
  `app/Http/Requests/ApplicationIndexRequest.php`, `routes/web.php`:
  четыре owner endpoints и два read-only admin endpoints, фильтры/поиск,
  сортировка created_at DESC/id DESC, пагинация 20 и сохранение query string.
- `app/Policies/GroupApplicationPolicy.php`, `app/Services/ApplicationWorkflow.php`:
  проверка аккаунта/владельца, scoped lookup, транзакция/блокировки, идемпотентность.
- `app/Models/Group.php`, `app/Http/Controllers/Psychologist/GroupController.php`,
  `app/Support/GroupPages.php`: три агрегированных счётчика в запросе группы,
  реальная последняя заявка на карточке.
- `app/Support/ApplicationPages.php`, `app/Support/PsychologistPages.php`,
  `resources/views/shared/application-{list,detail}.blade.php`,
  `resources/views/psychologist/groups/{index,show}.blade.php`: реальные
  URL/POST actions, admin navigation, сохранение синтетического режима прототипов.
- `app/Services/ApplicationRetentionService.php`,
  `app/Console/Commands/CleanupApplications.php`, `routes/console.php`:
  физическое удаление порциями по 500 ID и daily withoutOverlapping.
- `tests/Feature/Application{Workflow,Retention}Test.php`: новые MySQL-проверки;
  `GroupWorkflowTest.php` / `GroupLifecycleTest.php`: прежние ожидания отсутствия
  заявок заменены актуальными, остальные регрессионные ограничения сохранены.
- `docs/{architecture,development,project-status,ui-pages}.md`, `.ai/report.md`.

Пути приложения выше указаны относительно `application/`.

## Checks

- Исходный статус чистый; актуальный planner `3b570d8`, его родитель совпадает
  с требуемым `96b64c9323ba78ff434e35f2bdeadf15c623e344`.
- `docker compose ps`: PHP и MySQL healthy, web Up.
- `docker compose exec -T php php artisan migrate --seed`: Nothing to migrate;
  сидирование выполнено, destructive reset не использовался.
- `docker compose exec -T php php artisan test --filter=Application`:
  33 passed, 280 assertions (включая один существующий тест с Application в имени).
- `docker compose exec -T php php artisan test --filter=PrototypeTest`:
  8 passed, 1014 assertions; все 31 группы / 249 вариантов, no-op и isolation.
- `docker compose exec -T php php artisan test`: **321 passed, 3947 assertions**,
  273.02 s; Stage 4–9, MySQL-only, прототипы и новые сценарии проходят.
- После финального переноса счётчиков карточки в основной SELECT повторён
  `php artisan test --filter=test_owner_counters_details_and_idempotent_processing_without_side_effects`:
  1 passed, 34 assertions. Повторный Pint изменённого контроллера проходит.
- `docker compose exec -T php ./vendor/bin/pint --test`: PASS, 120 files.
- `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`:
  OK, No errors. Первоначальные замечания к PHPDoc счётчиков/лишнему null-check
  исправлены до успешного запуска.
- `docker compose exec -T php composer check-platform-reqs`: все success,
  PHP 8.2.32 и требуемые расширения доступны.
- `docker compose exec -T php php artisan view:cache`: cached successfully.
- `php artisan route:list --path=applications`: шесть реальных маршрутов;
  create/API/admin mutation отсутствуют.
- `docker compose exec -T -e APP_ENV=production php php artisan route:list --json`:
  отдельная проверка подтвердила шесть application routes и отсутствие
  prototype/foundation/API routes.
- `php artisan schedule:list`: groups:expire `* * * * *` без изменения;
  applications:cleanup `0 0 * * *`. Автотест проверяет withoutOverlapping обоих.
- Query-count tests: число запросов owner group list, owner application list и
  admin application list одинаково при 1 и 24 строках/росте числа владельцев.
  Счётчики групп находятся в одном SELECT с подзапросами, payment queries нет.
- `git diff --check`: успешно. Итоговый diff и staged-файлы проверены;
  task/spec/governance, секреты и runtime/browser artifacts не включены.

### Runtime/browser

Реальный Chromium + Docker, URL `http://localhost:8080/cabinet`, только временные
синтетические данные: два одобренных психолога с отдельными группами, 25 заявок.

- Owner: счётчики 23 новые / 1 обработанная / 24 всего; список, карточка,
  process/unprocess через реальные CSRF-формы, фильтры new/processed,
  страницы 20 + 3 новых заявки. Чужая группа, чужая заявка и подмена заявки
  внутри своей группы возвращают HTTP 404.
- Admin: пункт «Заявки», записи обоих владельцев, поиск по имени участника,
  форматированному/нормализованному телефону, группе, ФИО/email психолога;
  processed filter, query-preserving pagination, empty search result,
  реальные переходы к группе и анкете. Кнопок изменения обработки нет.
- Проверены owner group list/detail/application list/detail и admin application
  list/detail при 1440/1024/390 px: HTTP 200, горизонтального переполнения нет;
  просмотрены снимки интерфейса. CSS и структура прототипов сохранены.
- Временный PHP smoke в local заморозил UTC-время и создал пять записей около
  cutoff, включая processed и soft-deleted parent. Artisan-команда выдала строго
  `Deleted applications: 3`, затем `Deleted applications: 0`.
  Exact/newer сохранились. Сравнены хеши групп, пользователей, платежей,
  истории, аудита, jobs/failed_jobs: изменений нет. Удаление посторонних
  eligible records заранее исключено проверкой.
- Автотесты дополнительно подтверждают отсутствие sent/queued mail и queue work,
  неизменность lifecycle/payment/audit, изменение retention settings на следующем
  запуске, удаление 1003 записей без пропуска порций и конец календарного месяца.
- Smoke-only заявки, группы и пользователи удалены по проверенным ID;
  браузер закрыт. Скрипты/снимки/логи вынесены в `/tmp`, не staged.
  Для браузера использована временная ссылка на уже установленный Chromium
  взамен устаревшего пути инструмента; после проверки ссылка удалена.

## Facts

- processed_at — единственный источник состояния; повтор одинакового действия
  сохраняет исходные processed_at и updated_at.
- Owner lookup всегда через group.owner_id и затем application.group_id;
  lifecycle/disabled группы не скрывает существующие заявки. Account/role
  middleware и policy блокируют отозванный доступ и противоположную роль.
- Admin eager-load включает исторические soft-deleted parents; мутаций нет.
- Retention читает текущую типизированную настройку один раз за запуск,
  удаляет только created_at < UTC cutoff и выводит только общий счётчик.
- Зависимости, миграции, Node/npm/Vite, внешние API и production deployment
  не добавлялись. Реальные персональные данные не использовались.

## Assumptions

- Для хранения требуется явный международный префикс + или 00; без достоверного
  правила определения страны bare/local номера не преобразуются.
- Месячный cutoff использует календарные месяцы без overflow на конце месяца.
  Оба решения описаны в документации и покрыты тестами.

## Unknowns

- Интеграционные требования публичного сайта и финальная валидация входящих
  номеров относятся к Stage 11; production scheduler/deployment не проверялись.
- Блокирующих неизвестных для Stage 10 нет.

## Risks / Next Step

Stage 10 завершён. По запросу владельца продукта рекомендуемая отдельная
следующая задача — глобальный UI/UX-аудит перед Stage 11 incoming integration.
Нормализация номера синтаксическая и не подтверждает существование абонента.
