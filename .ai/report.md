# Report: TASK-2026-10-03-01

Status: done

## Summary

Коррекция обхода ручной активации продлённой группы из planner `9df089d`.

- `GroupPolicy::activate()` запрещает любое текущее `expired -> approved`,
  независимо от наличия MODX Resource ID. Остальные проверки сохранены.
- `GroupWorkflow::activate()` уже повторно вызывает Gate после `lockForUpdate()`
  и загрузки текущей строки. Исправленная policy проверяет актуальную последнюю
  историю именно этой модели до изменения дат/публикационных полей/статуса.
  Это существующая защита внутри сервиса; добавлен поясняющий комментарий,
  отдельная дублирующая проверка не нужна. Прямой вызов получает существующий
  `AuthorizationException`, маршрут — 403.
- Кнопка и модальное подтверждение ручной активации исключены также из
  prototype/shared rendering для renewal. Сообщение о восстановлении
  синхронизации при отсутствии Resource ID сохранено.
- Первичное `moderation -> approved` сохраняет ручную активацию. Автопубликация,
  retry и платёжные правила не менялись.

## Changed Files

- `application/app/Policies/GroupPolicy.php` — исправлено условие активации.
- `application/app/Services/GroupWorkflow.php` — пояснение повторной авторизации
  текущей строки под блокировкой.
- `application/resources/views/admin/groups/show.blade.php` — renewal guard
  для кнопки и подтверждения, включая prototype rendering.
- `application/tests/Feature/GroupPublicationTest.php` — policy/POST/direct-service,
  неизменность всех полей и истории, восстановление Resource ID + retry,
  первичная активация, детерминированный stale-model сценарий; каждый с ID/без ID.
- `application/tests/Feature/CabinetImprovementsUiTest.php` — renewal prototype UI.
- `application/tests/Feature/GroupLifecycleTest.php` — прежний regression новой
  длительности переведён с запрещённой ручной активации на восстановление ID,
  retry и подтверждение публикации; actor перехода теперь system.
- `docs/architecture.md` — уточнение запрета и восстановительного пути.
- `.ai/report.md` — текущий отчёт.

## Checks

Проверки выполняются в отдельных временных `cabinet-correction-php` и
`cabinet-correction-mysql`: PHP 8.2.32 / MySQL 8.4, новая синтетическая
`gruppa_cabinet_test`, Docker internal network без внешнего доступа.
Исходники скопированы из read-only mount в `/tmp/check` по явному перечню,
private env/storage/cache исключены; тестовый `.env` пуст. Побайтовое сравнение
316 source files с проверяемой копией: расхождений нет.

- Новые focused regressions: **7 passed, 76 assertions**, 10.47 s.
- Affected suite: **163 passed, 3480 assertions**, 99.31 s.
- Полный MySQL suite: **782 passed, 8933 assertions**, 757.10 s;
  failures/errors/skips: **0/0/0** (JUnit).
- Pint: **PASS, 224 files**, включая окончательную версию тестов.
- PHPStan: **No errors**.
- `composer check-platform-reqs`: все требования success.
- `composer validate --no-check-publish`: composer.json valid.
- `php artisan view:cache`: Blade templates cached successfully.
- `php artisan route:list`: успешно, 130 маршрутов.
- `CACHE_STORE=array php artisan schedule:list`: успешно, прежние 5 команд;
  сами scheduled-команды не запускались.
- `node --check application/public/ui.js`: exit 0.
- `git diff --check` и `git diff --cached --check`: успешно.
- Финальный staged review: ровно 8 файлов текущей задачи, полный diff просмотрен;
  секретов/private env/production data/logs/cache/vendor/temp artifacts нет.
  Посторонних unstaged/untracked файлов нет.

Результаты затронутых классов из JUnit:

| Класс | Tests | Assertions | Failures/errors/skips |
|---|---:|---:|---|
| CabinetImprovementsUiTest | 3 | 50 | 0/0/0 |
| GroupLifecycleTest | 20 | 325 | 0/0/0 |
| GroupPublicationTest | 41 | 410 | 0/0/0 |
| GroupWorkflowTest | 53 | 697 | 0/0/0 |
| PaymentEraGroupsTest | 4 | 54 | 0/0/0 |
| PrototypeTest | 10 | 1507 | 0/0/0 |
| WebpayConcurrencyTest | 6 | 165 | 0/0/0 |
| WebpayTest | 26 | 272 | 0/0/0 |

Промежуточные сбои новых fixtures: первый focused — 4 failed / 3 passed,
29 assertions (неизменяемый Resource ID и неверное имя метода в тесте);
второй — 1 failed / 6 passed, 70 assertions (повторный уникальный Resource ID).
Исправлены только fixtures: группа без ID создаётся отдельно, восстановлению
назначается отдельный синтетический ID, initial approval создаётся штатным
сервисом переходов. Финальные 7 regressions прошли.
Первый affected suite: 1 failed / 162 passed, 3455 assertions, 100.70 s —
старый lifecycle-тест ожидал запрещённую ручную активацию без Resource ID.
Ожидание исправлено на восстановление ID и подтверждённую публикацию,
с сохранением проверки длительности из настроек.

## Facts

- Стартовый HEAD `9df089d` — корректирующий planner; parent точно
  `5250b3d24114b438381774e86c85c5b087b73713`. Рабочее дерево было чистым.
- Прочитаны WORKFLOW, AGENTS, task, предыдущий report, policy/workflow/model,
  admin Blade/layout, CSS tokens, каталог UI и соответствующие тесты.
- `.ai/task.md` не изменялся; private env, включая `.env_save`, не читались
  и не изменялись. Миграций, пакетов, API/payment изменений нет.
- **Реальных Telegram, mail, MODX и WEBPAY запросов не выполнялось.**
  Использованы HTTP/queue fakes и изолированная сеть.
- Stale safety проверена без wall-clock timing: после исходного разрешения
  выполняются переходы active/expired/approved, затем workflow получает старую
  модель с загруженной старой историей и отклоняет активацию без изменений.

## Assumptions

Новых продуктовых предположений нет. Идентификатор текущего продления —
последняя запись истории `expired -> approved`, как требует задача.

## Unknowns

Реальная внешняя доставка/публикация не проверялась и не заявляется.

## Risks / Next Step

Готово к приёмке. Новые миграции и настройки для исправления не нужны.
Реальная внешняя публикация в рамках этой задачи не запускалась.
