# Report: TASK-2026-09-21-10

Status: done

## Summary

Реализован Stage 11: stateless server-to-server приём анкет психологов и заявок
участников с общей HMAC-защитой, MySQL-идемпотентностью, приватными документами,
едиными JSON-ошибками и безопасными техническими логами. UI Stage 10 сохранён.
Полный регрессионный прогон пройден: 342 tests / 4349 assertions.
Окончательный focused Stage 11 прогон: 20 tests / 331 assertions, все пройдены.

## API / security contract

Production:

- `POST https://gruppa.info/cabinet/api/v1/psychologists`
- `POST https://gruppa.info/cabinet/api/v1/group-applications`

Маршруты зарегистрированы через `routes/api.php`, middleware `api`,
`throttle:integration`, `AuthenticateIntegration`; без web/session/CSRF/login.
Production route inspection: 68 маршрутов, оба POST API присутствуют,
`_prototype`, `_foundation`, `redirect-check` отсутствуют. Web login и страницы
заявок сохраняют `web`/account/role middleware.

Подпись: lowercase hex HMAC-SHA256, `hash_equals`, шесть строк с LF без завершающего
LF: `v1`, `POST`, `/api/v1/<endpoint>`, timestamp, request ID, SHA-256 payload.
Префикс `/cabinet` исключён из канонического пути. JSON подписывает точные raw
bytes; multipart — точную строку `payload` и descriptor каждого файла с hash/size.
PHP/FPM parsing остаётся штатным, `enable_post_data_reading=1`. Boundary не
подписывается. Global trim/null middleware пропускает API; нормализация явная в DTO.

Timestamp — Unix UTC seconds; ±300 включительно, ±301 отклоняется. Request ID
обязателен: 1–128 символов безопасного ASCII-набора, регистр значим. Секрет только
env/config, отсутствие секрета закрывает доступ (503). Rate limit по IP + endpoint,
по умолчанию 60/min, configurable, считает также неуспешную аутентификацию.
Allowlist — необязательный список точных IP; пустой отключает проверку.

Ответы: `data/status + request_id`; ошибки `code/message`, опциональные `errors`,
безопасный request ID или null. HTTP 400/401/403/404/405/409/413/415/422/429/500/503
описаны в integration guide. Неожиданные исключения не раскрывают SQL/пути/trace.
Для API подавлен стандартный exception report, способный включать SQL bindings;
логируются только endpoint, безопасный ID, code, IP, UTC time, exception class и
server-side source file/line. Тела, email/имена/телефоны, файлы и подписи не пишутся.
Web error handling не изменён.

## Idempotency / business behavior

Новая таблица/модель `gp_integration_requests`:

- unique `request_id` (128, `utf8mb4_bin`);
- endpoint, SHA-256 semantic fingerprint;
- response_status, точный сериализованный response_body;
- completed_at с индексом, timestamps.

Нет колонок raw payload, персональных данных, файлов или секрета. Atomic no-op
upsert и row lock координируют конкурентные запросы. Бизнес-изменения и завершение
журнала находятся в одной транзакции. Replay возвращает исходные status/body без
повторного эффекта; другое содержимое/endpoint с тем же ID — 409. Откат не оставляет
завершённого/отравленного ID. Bounded retries обрабатывают deadlock/insert race.
Fingerprint нормализует поля и телефон заявки, сортирует descriptors и не зависит
от JSON formatting, multipart boundary, порядка/транспортных имён файлов.
Автоматическая очистка журнала не добавлена.

Анкеты:

- новый email → 201 pending, non-admin, enabled, free=false, password=null;
- pending → 200, полная замена nullable questionnaire fields, append documents;
- rejected → то же + domain transition в pending;
- approved/disabled/soft-deleted match → 409, без изменения/восстановления;
- education_type_code разрешается только в нужном словаре; новый выбор active,
  текущий inactive code допустим при повторе;
- consent обязателен, UTC `YYYY-MM-DDTHH:mm:ssZ`; внутренние поля запрещены;
- доступ/тариф/пароль/admin при повторе не перезаписываются.

Документы: PDF/JPEG/PNG по фактическому MIME, configurable 10240 KiB/file,
подписанные type/name/size/hash; safe name, случайный путь, private local storage.
Расширен существующий `PsychologistDocuments`: signed original name и очистка
известного случайного пути даже после частичной записи. При сбое бизнес-операции,
следующей загрузки или завершения журнала удаляются новые файлы запроса.

Заявка ищет только `public_uuid`; soft-deleted/unknown → 404, не active/disabled
→ 422. Создаётся через связь найденной группы; владелец определяется group.owner_id.
Телефон нормализует существующий `PhoneNormalizer`, processed_at=null. Внутренние
ID/owner/status-поля не принимаются. Разные request IDs — отдельные заявки.

## Changed Files

- `application/routes/api.php`, `bootstrap/app.php`, `AppServiceProvider`:
  маршруты, limiter, API error boundary и сохранение signed bytes.
- `app/Http/Middleware/AuthenticateIntegration.php`, API `IntakeController`;
  `app/Integration/{ApiErrors,IntegrationException,IntakeData,IntakeService}`.
- `app/Models/IntegrationRequest.php`, migration
  `2026_09_21_000001_create_integration_requests_table.php`.
- `config/integration.php`, `.env.example` — только placeholders/defaults.
- `app/Services/PsychologistDocuments.php` — общая политика хранения/cleanup.
- `IntegrationIntakeTest`, `IntegrationConcurrencyTest`, отдельный test worker;
  Stage 10 route assertion уточнён до web, поскольку API теперь существует.
- `docs/integration.md`, `development.md`, `architecture.md`, `project-status.md`.
- `.ai/report.md`.

Blade/CSS/JS, scheduler, payment-код, SPEC/WORKFLOW/AGENTS и `.ai/task.md` не менялись.

## Checks

- `docker compose ps`: MySQL/PHP healthy, Nginx running.
- `docker compose exec -T php php artisan migrate --seed --no-interaction`: PASS,
  применена только новая миграция, выполнен идемпотентный seed, без reset/fresh.
- Первый завершённый focused `IntegrationIntakeTest`: 14 passed / 266 assertions;
  затем добавлены проверки partial file write, replay после изменения состояния,
  malformed inputs и реального QueryException с sensitive bindings.
- MySQL concurrency: отдельные 4 PHP процесса; same-ID application → одна строка,
  same-ID questionnaire → одна строка/исходный 201 у всех; разные IDs одного нового
  email → один 201, остальные 200, без duplicate-key 500.
- Production `route:list --json`: PASS, результаты указаны выше.
- Larastan: PASS (No errors).
- Pint: PASS, 133 files; дополнительный изменённый тест отдельно форматирован.
- Composer platform requirements: PASS, PHP 8.2.32 и необходимые расширения.
- `docker compose exec -T php php artisan test`: **342 passed / 4349 assertions**,
  391.45 s; Stage 4–10, Stage 11, все 249 prototype variants и production isolation.
- `docker compose exec -T php php artisan view:cache`: PASS.
- `git diff --check`: PASS.
- `docker compose exec -T php php artisan test --filter='Integration(Intake|Concurrency)Test'`:
  **20 passed / 331 assertions**, 33.84 s, после последнего уточнения сохранения
  admin-флага и проверки настоящего QueryException с SQL bindings.
- Финальные Pint (133 files), Larastan и `git diff --check`: PASS.
- Полный diff и staged inspection: PASS, ровно 23 файла задачи;
  `git diff --cached --check`: PASS. Нет секретов, real PII, uploaded files,
  логов, screenshots, runtime config cache или посторонних артефактов.

Промежуточные исправленные неуспехи: limiter callback выбрасывает Laravel
HttpResponseException, для него сохранён готовый JSON response; UI fixture требовал
настройки placement lifecycle; сравнение raw Eloquent attributes до refresh
различало bool/int. `pint --dirty` неприменим без Git внутри контейнера — использован
явный список файлов. Одноразовый runtime-клиент исправлен для обхода системного HTTP
proxy и корректного импорта urllib; production-код для этих клиентских ошибок не
менялся. Все временные smoke-данные очищались в finally.

## Runtime / manual signed evidence

Одноразовый Python test-client из `/tmp` отправил реальные HTTP-запросы через
локальный Nginx → PHP 8.2 FPM под `/cabinet`. Временный случайный секрет установлен
через локальный config cache; первоначальная конфигурация восстановлена после
проверки. Секрет не выводился и не коммитился.

Проверено:

- signed multipart → 201; новая boundary + тот же ID → идентичный ответ;
- анкета присутствует в real admin pending list;
- реальный защищённый admin document view возвращает исходный PDF и правильный MIME;
- private exists/public missing, случайный путь и имя из signed descriptor;
- pending/rejected repeats → 200; approved/disabled/deleted → 409;
- completed replay после удаления анкеты возвращает исходный 201;
- signed JSON active group → 201, повтор не создаёт дубль;
- owner list/detail, admin list, group detail показывают новую заявку;
- counters all/new/processed = 1/1/0; другой психолог получает 404;
- unknown UUID 404, inactive/disabled 422, bad signature/stale 401,
  missing ID 400, rate limit 429, всё в JSON;
- 4 completed journal entries, 3 append-only private documents после повторов;
- inspection новых логов и journal rows: нет synthetic email/имён/телефона,
  document name, questionnaire body, секретов и подписей;
- payment/history/job counts до и после intake совпадают;
- удалены собственные smoke rows/documents/sessions/dictionary item/journal,
  восстановлен исходный config cache, временный секрет удалён.

Это HTTP-проверка реальных страниц и действий; визуальная переработка или новая
браузерная responsive-сертификация не выполнялась, UI не менялся.

## Facts

Начальная рабочая директория чистая. HEAD `0a779ce` — актуальная planner-коррекция
multipart; после implementation base `ccd546b` были только два planner-коммита,
менявшие `.ai/task.md`. Задача ранее не реализована. Все данные проверок синтетические.
Тесты используют MySQL `gruppa_cabinet_test`; runtime smoke — отдельные временные
записи локальной development DB. Для Laravel routing/error hooks запрошен Context7,
а доступность API сверена с установленными Laravel 12 исходниками.

## Assumptions / Unknowns

Продуктовые предположения не добавлены. UTC consent format и conservative rate
limit выбраны в разрешённых задачей рамках и явно описаны в контракте.
Production/staging секрет, source IPs, staging URL и реальные education codes
неизвестны и не выдуманы. Деплой и интеграция внешнего публичного сайта не выполнялись.

## Risks / Next Step

Разработчику публичного сайта: реализовать server-side signing/retries и хранение
request IDs, связать `cabinet_group_uuid`, согласовать dictionary codes, provision
secret/HTTPS/proxy/allowlist и проверить staging по `docs/integration.md`.
Stage 12 email/password setup и WEBPAY остаются отдельными этапами.
