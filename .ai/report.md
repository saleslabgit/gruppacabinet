# Report: TASK-2026-10-03-01

Status: done

## Summary

Реализован согласованный пакет после ручного тестирования:

- текстовая обратная связь психолога, Telegram-события pending-анкеты и отправленной
  на модерацию группы; database queue, after-commit для бизнес-событий,
  ограничение частоты, безопасные ошибки без token-bearing HTTP exception;
- queued SMTP/sendmail письма для approved/revision/rejected по конкретной записи
  истории, с результатом/комментарием и ссылкой;
- нейтральные тексты оплаты, терминальные failed/cancelled с повтором, сохранение
  недоверия к browser return/cancel;
- исправления списка/профиля/порядка панелей и отдельный submit-stored с row lock,
  owner authorization и общей GroupModxReadiness;
- скрытие заявки психологом с сохранением данных/админ-истории; owner 404,
  исключение из counters/latest/list; group_id filter и пять последних заявок
  в карточке администратора, включая скрытые; additive migration;
- явная пометка удаления группы психологом, без изменения статуса/истории/платежей;
- HTTPS URL generation в production и админ-кнопка меню в шапке с прежним
  desktop sidebar, keyboard/Escape/focus и no-JS навигацией;
- admin withdraw/restore: active и исходные даты сохраняются, disabled включается
  сразу и снимается только успешной текущей publish revision;
- free/paid expired renewal: approved до remote success, затем active и новый срок
  по текущей настройке длительности. Первичное одобрение не публикуется автоматически.

Задания публикации проверяют revision/resource, режим и снимок состояния/дат до HTTP
и под row lock после ответа. Просрочка и недопустимое изменение во время HTTP
создают более новое unpublish intent. Старые сериализованные pause/resume jobs
сохраняют defaults. Успешная оплата/product_effect не зависит от публикации.

## Changed Files

- Notifications: `ActionNotifications`, `SendAdminTelegram`, `SendGroupModeration`,
  `GroupModerationMail`, mail views, `FeedbackController`, feedback view,
  `services.php`, `.env.example`, маршруты/меню/prototype fixtures/catalogue.
- Lifecycle: `GroupWorkflow`, `GroupLifecycleService`, `GroupModxPublicationScheduler`,
  `SetGroupModxPublication`, `GroupPolicy`, `GroupActionRequest`, `Group`,
  `ConfirmPayment`, psychologist/admin group controllers.
- Applications: additive migration `2026_10_03_000001`, `GroupApplication`, policy,
  `ApplicationWorkflow`, owner/admin controllers, index request, page presenters,
  shared list/detail и admin group applications panel.
- UI: затронутые shared/psychologist/admin Blade pages, navbar/sidebar/surface,
  `ui.css`/`ui.js`, payment validation/success copy, production AppServiceProvider.
- Tests: новые ActionNotifications/ApplicationHideMigration/CabinetImprovementsUi/
  ProductionUrlGeneration; расширены workflow/publication/intake/application/
  retention regressions; обновлены прежние проверки изменённых UI/queue-контрактов.
- Docs: SPEC, project-status, architecture, ui-pages, development, deployment,
  email и новый `docs/telegram.md`.

## Checks

Финальный полный MySQL suite: **775 passed, 8831 assertions**, 683.69 s;
failures/errors/skips: **0/0/0**. После него уточнён только текст успешного
продления на payment return; окончательная версия проверена targeted suite
`CabinetImprovementsUiTest|PrototypeTest|WebpayTest`: **38 passed, 1823 assertions**,
20.55 s. Все Blade views затем успешно скомпилированы.

Проверки выполняются в отдельных временных контейнерах `cabinet-task-php` и
`cabinet-task-mysql`: PHP 8.2.32, MySQL 8.4, новая синтетическая
`gruppa_cabinet_test`. Сеть Docker internal без внешнего доступа. Исходники
смонтированы read-only и скопированы в `/tmp/check`, private env/storage/cache
исключены; тестовый `.env` пуст. Проверено побайтовое совпадение 316 source files.

Дополнительно выполнено:

- Focused affected suite: **165 passed, 3578 assertions**, 49.35 s.
- Дополнительный worker regression после correction: **1 passed, 16 assertions**, 8.39 s.
- `php vendor/bin/pint --test`: **PASS, 224 files**.
- `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: **No errors**.
- `composer check-platform-reqs`: все требования success.
- `composer validate --no-check-publish`: composer.json valid.
- `node --check application/public/ui.js`: exit 0.
- `php artisan view:cache`: Blade templates cached successfully.
- `php artisan route:list`: успешно, 130 routes.
- `CACHE_STORE=array php artisan schedule:list`: успешно, прежние 5 команд;
  scheduled-команды не запускались.
- `git diff --check`: успешно; проверены полный diff и перечень файлов,
  private env, секреты и временные артефакты не включены.
- Staged review: 85 файлов соответствуют проверенному scope;
  `git diff --cached --check` успешен, посторонних unstaged/untracked файлов нет.
- Browser smoke: локальный Chromium/Playwright, синтетические Blade-rendered страницы.
  Admin menu на 390/1024/1440 px: header toggle, open/close, Escape/focus, desktop
  sidebar; owner revision/feedback normal/error на 390 px; no-JS navigation (7 links).
  Горизонтального overflow и JS errors нет. Повторено после окончательной правки меню.

Результаты обязательных regression-классов из финального JUnit:

| Класс | Tests | Assertions | Failures/errors/skips |
|---|---:|---:|---|
| ActionNotificationsTest | 6 | 42 | 0/0/0 |
| ApplicationHideMigrationTest | 1 | 3 | 0/0/0 |
| ApplicationRetentionTest | 5 | 36 | 0/0/0 |
| ApplicationWorkflowTest | 28 | 274 | 0/0/0 |
| AuthenticationTest | 25 | 208 | 0/0/0 |
| CabinetImprovementsUiTest | 2 | 44 | 0/0/0 |
| DeploymentPreflightTest | 20 | 152 | 0/0/0 |
| ExpiryWarningTest | 18 | 239 | 0/0/0 |
| GroupLifecycleConcurrencyTest | 6 | 123 | 0/0/0 |
| GroupLifecycleTest | 20 | 318 | 0/0/0 |
| GroupPublicationTest | 35 | 340 | 0/0/0 |
| GroupWorkflowTest | 53 | 697 | 0/0/0 |
| IntegrationConcurrencyTest | 3 | 67 | 0/0/0 |
| IntegrationIntakeTest | 27 | 639 | 0/0/0 |
| ModxGroupSyncTest | 28 | 238 | 0/0/0 |
| PasswordRecoveryTest | 23 | 254 | 0/0/0 |
| PasswordRecoveryTimingTest | 21 | 353 | 0/0/0 |
| PasswordSetupTest | 30 | 325 | 0/0/0 |
| PaymentEraGroupsTest | 4 | 54 | 0/0/0 |
| ProductionUrlGenerationTest | 2 | 16 | 0/0/0 |
| PrototypeTest | 10 | 1507 | 0/0/0 |
| SharedHostingRuntimeTest | 3 | 26 | 0/0/0 |
| WebpayConcurrencyTest | 6 | 146 | 0/0/0 |
| WebpayTest | 26 | 272 | 0/0/0 |

Промежуточные сбои не скрыты:

- Первый regression: 4 failed / 106 passed, 1546 assertions — Blade directive
  boundary в feedback и устаревшие ожидания количества routes/payment copy. Исправлено.
- Следующий focused: 3 failed / 158 passed, 3217 assertions — ошибки новых fixtures
  (устаревший Eloquent instance между итерациями, повторный уникальный Resource ID).
  Исправлены fixtures; последующий focused прошёл.
- Первый полный MySQL: 6 failed / 765 passed, 8726 assertions, 626.00 s.
  Все шесть относились к прежним ожиданиям ручного продления, согласий/маршрутов
  профиля и дополнительных notification jobs в MODX tests.
- Correction suite: 1 failed / 114 passed, 2740 assertions, 34.62 s.
  Selective Queue fake не обслуживал настоящий worker; заменён на selective Bus fake
  только для нового mail job. Реальная database queue/HTTP fake/overlap/retry
  проверка после этого прошла. Финальный полный suite также прошёл.
- Первый запуск migrations без DB_CONNECTION выбрал SQLite по framework default
  и остановился на MySQL collation; это произошло только в disposable `/tmp/check`.
  Затем явно выбран MySQL, additive migrations успешно применены к новой test DB.
- Первые Pint/PHPStan выявили formatting и избыточный nullsafe-access; исправлено.
- Playwright MCP не нашёл ожидаемую версию Chromium; smoke выполнен установленным
  Chromium через локальный Playwright. Sandbox launch потребовал разрешённого запуска
  браузера вне sandbox. Пакеты не устанавливались.

## Facts

- Стартовый HEAD `d18f279` — актуальный planner; parent
  `5a981b77b13c7b3d5d718b4af05d697f34c1da40` подтверждён. Дерево было чистым.
- Прочитаны WORKFLOW/AGENTS/task/current report и соответствующий код/Blade/CSS/JS.
- `.ai/task.md` не изменялся. Production/private env, включая `.env_save`,
  не читались и не изменялись.
- **Реальных Telegram, SMTP/sendmail, MODX и WEBPAY запросов не выполнялось.**
  Автоматическая проверка использует HTTP/mail fakes, внешняя сеть изолирована.
- Не добавлены payment deletion/refund, пакеты, Node/Vite, новые бизнес-таблицы,
  restore скрытых сущностей или автоматическая первичная публикация.
- Production APP_URL и web-server redirects не переписывались; existing preflight
  уже проверяет HTTPS, добавлено application-level forceScheme только в production.
- Каталог: 33 группы страниц / 267 вариантов; четыре новых feedback states.

## Assumptions

Дополнительных продуктовых предположений нет. Из допустимых вариантов задачи
выбраны 2800 символов текста, 2 запроса в минуту, 5 последних admin applications.
Expired renewal использует placementDurationDays при успешной публикации,
как прежняя ручная активация.

## Unknowns

Реальная Telegram/inbox/MODX/payment delivery не проверялась и не заявляется.
Для Telegram нет exactly-once transport guarantee: потерянный ответ и retry могут
доставить повтор; intake replay при этом не ставит новое событие.

## Risks / Next Step

Готово к приёмке. Для развёртывания необходимы обычная additive migration,
приватная Telegram-конфигурация и обновление config/worker. Реальная доставка
проверяется отдельно в разрешённом окружении; в этой задаче внешних вызовов нет.
