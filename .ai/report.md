# Report: TASK-2026-09-21-05

Status: done

## Summary

Реализован Stage 7: реальные группы психолога и администратора, анкета,
модерация, полная история, ручная активация и безопасное soft delete.
Оба тарифа начинают с draft и проходят весь цикл без платежей.
Утверждённые Blade-страницы подключены к данным; CSS и prototype-каталог не менялись.

База задачи подтверждена: `2b9ff579d91c69cc2d7a6ecae6438415f93f06e1`;
исходный HEAD — `980a65e` (актуальный planner), рабочая директория была чистой.
`.ai/task.md`, SPEC.md, WORKFLOW.md и AGENTS.md не изменены.

## Changed Files

- `application/routes/web.php`: корневая страница групп и реальные owner/admin маршруты.
- `app/Http/Controllers/Psychologist/GroupController.php`,
  `app/Http/Controllers/Admin/GroupController.php`: списки, карточки, формы и действия.
  В HomeController удалена заменённая заглушка групп.
- `app/Http/Requests/GroupRequest.php`, `GroupActionRequest.php`,
  `GroupIndexRequest.php`: анкета/деньги/справочники, подтверждения/комментарии, фильтры.
- `app/Policies/GroupPolicy.php`: owner/admin, состояния, удаление и исторический платёж.
- `app/Services/GroupWorkflow.php`: атомарные операции с блокировкой строки,
  создание начальной истории, композиция существующего transition service.
- `app/Support/GroupPages.php`, PsychologistPages и PsychologistCabinetPages:
  реальные данные и общая навигация. Group получает только аннотации типов связей.
- `config/groups.php`: технический порог abandoned draft = 30 дней.
- Существующие admin/groups, psychologist/groups и shared/group-* views:
  реальные формы/CSRF/old input, отдельное сохранение/отправка, история,
  подтверждения и доступные действия. `components/confirmation` получает ID формы
  для связи textarea в модальном окне через стандартный HTML-атрибут `form`.
- `tests/Feature/GroupWorkflowTest.php`: 36 сценариев с data providers.
  AuthenticationTest и PsychologistProfileTest обновлены только для новой главной
  с реальными группами/пагинацией; остальные проверки сохранены. В PrototypeTest
  добавлена проверка единственного автоматически открытого validation-окна.
- docs/architecture.md, development.md, project-status.md, ui-pages.md и этот отчёт.

Пути app/resources/tests/config выше относятся к `application/`.
Новых migrations, зависимостей, frontend build, env-переменных нет.

## Routes and rules

- `GET /` — собственные группы, 20 на страницу, created_at/id DESC.
- `POST /groups` — собственный draft → форма; owner/status/UUID из запроса игнорируются.
- `GET /groups/{group}`, `GET /groups/{group}/edit`, `PUT /groups/{group}`,
  `POST /groups/{group}/submit`, `DELETE /groups/{group}`.
- `/admin/groups`: index/create/store/show/edit/update; отдельные POST
  approve/revision/reject/activate и DELETE abandoned draft.
- Все маршруты защищены account + соответствующей ролью; чужие owner IDs дают 404.
- Редактирование психологом только draft/revision и enabled; rejected — просмотр/удаление.
- Admin создаёт для approved/enabled/non-admin владельца и редактирует только анкету.
- Начальная история null → draft записывается с текущим actor атомарно с созданием.
  Остальные статусы меняет только GroupStatusTransitionService.
- Revision/rejection требуют 10–16000 символов после trimming. История сохраняет
  каждый комментарий независимо; актеры/справочники загружаются без N+1.
- Activation: approved → active, текущая длительность SettingService, UTC now,
  expires_at = published_at + duration, сброс expiry_warning_sent_at. Повтор запрещён.
- UUID создаётся моделью один раз; edit/submit/moderation не меняют UUID, owner/free.
  Copy control использует точный UUID. Внешних запросов при активации нет.
- Money: до 16 цифр целой части, 0–2 дробных, точка/запятая; только integer/string
  преобразование в копейки. Формат/пол — активные значения плюс текущий inactive item.
- Admin search: ID/title/owner name/email; status/free, безопасный date sort,
  approved/abandoned quick filters и сохранение query при пагинации.
- Owner delete: enabled draft/rejected без succeeded/unrefunded payment.
  Admin delete: draft с created_at <= now - 30 дней и та же платёжная защита.
  Исторические soft-deleted payments тоже учитываются. Удаление только soft delete.
- В обычных списках нет запросов payments/applications; payment query используется
  только для авторизации удаления. Формы редактирования такую проверку не запрашивают.
- Заявки явно недоступны; реальных payment/application/extension ссылок/маршрутов нет.
  Payment rows не создаются/изменяются/удаляются, awaiting_payment недостижим.

## Checks

Фактически выполнено:

1. `docker compose ps`: mysql и php healthy, web Up, локальный HTTP :8080.
2. `docker compose exec -T php php artisan migrate --seed --force`:
   Nothing to migrate; idempotent seed выполнен, без destructive reset.
3. `docker compose exec -T php php artisan test --filter=GroupWorkflowTest`:
   начальный focused прогон — 29 passed, 403 assertions (32.05 s).
   Затем добавлены ещё 7 сценариев отката, сортировки, исторического актора,
   отзыва доступа и disabled/status deletion; они вошли в финальный полный прогон.
4. `docker compose exec -T php php artisan test`:
   финально **246 passed, 2455 assertions, 148.46 s**, MySQL test database.
   Включены Stage 4–6, документы, domain foundation и все 31/249 prototype variants.
   Первый полный прогон выявил старое ожидание одного aria-current на всей странице:
   теперь атрибут есть и у пагинации. Проверка ограничена навигацией; финальный прогон зелёный.
5. `docker compose exec -T php ./vendor/bin/pint --test`: PASS, 90 files.
   Форматирование запускалось только для файлов задачи. Попытка `pint --dirty`
   не поддерживается контейнером без .git; использован явный список файлов.
6. `docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress`:
   [OK] No errors. Первые замечания к типам Eloquent-связей исправлены.
7. `docker compose exec -T php composer check-platform-reqs`:
   все требования success, PHP 8.2.32.
8. `docker compose exec -T php php artisan view:cache`:
   Blade templates cached successfully, включая финальные шаблоны.
9. `docker compose exec -T php php artisan route:list --except-vendor --json`:
   проверены реальные group routes, нет реальных payment/application/extension routes.
10. `docker compose exec -T -e APP_ENV=production php php artisan route:list --except-vendor --json`:
    42 маршрута; prototype, foundation и redirect-check отсутствуют.
11. После финального diff-review исправлено автоматическое открытие rejection-modal
    в prototype validation: оно включается только в real mode, чтобы сохранить
    исходное единственное окно revision. Выполнен
    `docker compose exec -T php php artisan test --filter=test_prototype_login_and_navigation_remain_no_op`:
    **1 passed, 12 assertions, 7.10 s**. Также повторены Blade compilation (success)
    и Pint для последних двух PHP-файлов (PASS).
12. `git diff --check` и `git diff --cached --check`: успешно.
    Просмотрены diff и staged-файлы; только Stage 7, тесты, документация и отчёт.

### Real Docker HTTP/browser

`node /tmp/stage7-smoke.cjs` — PASS через локальный Chromium/Playwright и Nginx
`http://localhost:8080/cabinet`. Скрипт и снимки находятся только в `/tmp`.
MCP browser не запускался из-за отсутствующих путей исполняемых файлов;
использована уже установленная Chromium 1243, без установки зависимостей проекта.

Проверены отдельными browser sessions для free=true и free=false:

- login → POST создания → заполнение → сохранение draft → отправка;
- запрет редактирования moderation;
- реальный admin list/detail → revision с комментарием;
- видимость комментария психологу → исправление → resubmit → approve;
- точное совпадение clipboard с public_uuid → activate;
- статус active, 30 дней из локальной настройки, разность expires/published ровно 30 дней;
- история draft/moderation/revision/moderation/approved/active;
- отдельное отклонение с причиной → owner soft delete;
- чужой ID — 404;
- abandoned filter/delete по разные стороны 30-дневного порога;
- по каждой activated группе 0 payments; общий payment count не изменился;
- нет активных payment/application/extension/prototype ссылок.

Для обеих активаций SQL-проверка gp_group_status_history подтвердила требуемую
цепочку; корректные actor_id/actor_type отдельно проверены MySQL-тестами.
SQL-проверки dates/UUID/payments выполнялись после реальных browser actions.

Геометрия списка психолога, admin list, формы, карточки и admin moderation
проверена на **1440/1024/390**, горизонтального переполнения нет. Скриншоты
репрезентативных desktop/tablet/mobile состояний просмотрены.
`node /tmp/stage7-mobile.cjs` дополнительно подтвердил видимость/границы кнопок
сохранения/отправки и работоспособность мобильного confirmation удаления.

Создавались только синтетические пользователи/значения справочников/группы.
Продуктовые удаления проверены как soft delete с сохранением истории.
После проверки удалены только созданные этими smoke-скриптами fixtures;
существующие записи не изменялись. Скриншоты, временные скрипты и данные не включены в Git.

## Facts

- Все 42 acceptance criteria реализованы/проверены в рамках Stage 7.
- Существующий transition service и его принятые правила не менялись.
- Production UI использует исходные Blade-страницы и существующие CSS tokens.
- Повторная activation/moderation не меняет историю/даты; ошибка transition
  откатывает сохраняемый контент, что проверено отдельным тестом.
- Owner/admin list query counts постоянны при увеличении количества строк;
  SQL не содержит payments/applications.
- Полный MySQL suite и обязательные проверки проходят; внешние credentials не нужны.

## Assumptions

Использованы продуктовые решения самой задачи: draft для обоих тарифов,
неизменяемый владелец, 10 символов комментария/причины, 30 дней abandoned cutoff.
Технические пределы денег/текста/положительных integer описаны в документации.

## Unknowns

Утверждённые реальные display values справочников group_format/gender всё ещё
не предоставлены. Они не выдуманы и не добавлены в seed; проверка выполнена на
изолированных синтетических значениях. Без настроенных справочников создание
пустого draft доступно, а заполнение/отправка требует реальных значений.
Форма объясняет отсутствие вариантов. Dictionary CRUD относится к Stage 8.

## Risks / Next Step

Для ручной продуктовой работы требуется наполнить справочники утверждёнными
значениями. Этапы 8+ остаются pending. WEBPAY, приложения участников, scheduler,
продление, email и публичная интеграция сознательно не подключены.
Ручная проверка: два входа психолог/admin → группы → draft/save/submit →
revision/resubmit/approve → UUID copy → activation; подробности в docs/development.md.
