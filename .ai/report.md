# Report: TASK-2026-09-24-02

Status: done

## Summary

Реализованы упорядоченные обучения психолога, связь сертификатов с обучениями,
повторная анкета с заменой текущего списка, стабильное админское редактирование
и свободный строковый телефон участника. Фокусированные, полный MySQL-набор и заключительная проверка admin old input
прошли. Все обязательные проверки выполнены.

WEBPAY signing/trust и Stage 12 mail/password behavior не изменялись.

## Changed Files

- `application/database/migrations/2026_09_24_000001_create_user_trainings.php` —
  добавочная схема и перенос legacy-данных.
- `application/app/Models/UserTraining.php`, `User.php`, `UserDocument.php` —
  модель обучения, ordered has-many и nullable certificate relation.
- `application/app/Support/TrainingData.php` — общие scalar rules, meaningful
  validation и нормализация обучения.
- `application/app/Integration/IntakeData.php`, `IntakeService.php` — новый
  контракт, semantic fingerprint, транзакционная замена списка и связь файлов.
- `application/app/Support/PhoneNormalizer.php`,
  `application/database/factories/GroupApplicationFactory.php` — best-effort
  digitsForSearch; strict normalizeForStorage удалён после проверки callers.
- `application/app/Http/Requests/Admin/PsychologistRequest.php`,
  `application/app/Http/Controllers/Admin/PsychologistController.php`,
  `application/app/Services/PsychologistTrainings.php` — nested validation,
  ownership check и транзакционное stable-row сохранение/перестановка.
- `application/app/Http/Controllers/Psychologist/ProfileController.php`,
  `application/app/Support/PsychologistPages.php`, `PrototypeFixtures.php` —
  загрузка/представление всего списка, synthetic variants.
- `application/resources/views/admin/users/form.blade.php`,
  `application/resources/views/shared/training-fields.blade.php`,
  `profile-data.blade.php`, `application/public/ui.js` — существующая форма
  с add/remove/up/down, ошибки соответствующего блока, read-only список.
- `application/tests/Feature/UserTrainingMigrationTest.php`,
  `IntegrationIntakeTest.php`, `IntegrationConcurrencyTest.php`,
  `PsychologistAdminTest.php`, `PsychologistProfileTest.php`,
  `ApplicationWorkflowTest.php` — контрактные и регрессионные проверки.
- `SPEC.md`, `docs/integration.md`, `docs/architecture.md`,
  `docs/project-status.md`, `docs/development.md`, `docs/ui-pages.md` — текущая
  схема/контракт/интерфейс и deployment/rollback notes.
- `.ai/report.md` — этот отчёт. `.ai/task.md` не изменялся.

## Schema / Backfill / Rollback

Новая `gp_user_trainings`: bigint id/user_id, user FK с RESTRICT, unsigned integer
position, nullable modality_program/training_center varchar(255), nullable
unsigned smallint graduation_year, unsigned integer training_hours, timestamps.
Уникальный `(user_id, position)` одновременно пригоден для поиска по user_id;
FK также обеспечивается индексом MySQL. Soft delete не добавлен.
`gp_user_documents.user_training_id` — nullable bigint FK с ON DELETE SET NULL.

Backfill использует query builder без application events, chunkById(500), общую
транзакцию для данных: для каждого пользователя, включая soft-deleted, с хотя бы
одним non-null legacy-полем создаётся position 0 с точными исходными значениями.
Все его существующие certificate rows связываются с этим обучением; diploma и
сертификаты пользователя без legacy-обучения остаются unlinked. Старые gp_users
колонки остаются физически, но runtime не читает/не пишет/не синхронизирует их.
Legacy empty strings сохраняются буквально; новые intake/admin/model writes
требуют хотя бы одно содержательное поле (число 0 допустимо).

Disposable MySQL test проверил все четыре значения legacy-записи, несколько
сертификатов, отсутствие обучения при all-null, значение 0, soft-deleted user,
неизменность gp_users, SET NULL, сохранность четырёх document rows и private
файлов, down/up, уникальность позиции и отказ текущей модели от пустой строки.

Down удаляет только новый FK/колонку связи и таблицу обучений. Пользователи,
старые колонки, документы и файлы остаются. Новые множественные обучения не
переносятся в старые колонки: перед rollback нужен backup/export новых данных.
Production не требует migrate:fresh; **добавочная миграция обязательна перед
обслуживанием кода, ожидающего gp_user_trainings**. На время deployment нужно
остановить записи intake/admin и согласовать новый контракт внешнего сайта.

## Implemented Contracts

`payload.trainings` — optional JSON list; omitted/[] означает пустой текущий список.
Каждый item — JSON object только с modality_program/training_center (nullable
string <=255), graduation_year (nullable integer 0..65535), training_hours
(nullable integer 0..4294967295). Text trim, empty -> null; все поля пустыми
быть не могут. Порядок задаёт position 0..N-1; произвольного count cap нет
(тест принимает 35 записей). Старые top-level поля отвергаются с 422.

`certificate_N` требует существующий trainings[N], decimal index без ведущих
нулей. Пропуски сертификатов допустимы, обучение без файла допустимо. Остальные
типы документов user-level с null link. Metadata/MIME/size/SHA-256 определяются
сервером; private paths, защита просмотра и скачивания сохранены.

Pending/rejected non-idempotent resubmission заменяет текущие обучения, обнуляет
старые certificate links через FK, сохраняет все старые документы/файлы и связывает
новые файлы с новым списком. Всё внутри существующей intake/journal transaction,
с прежним rollback cleanup. Same-ID replay не пересоздаёт строки/файлы. Fingerprint
учитывает нормализованные значения, позиции и certificate association; изменённые
поля/список/порядок/содержимое файлов дают 409; multipart order/boundary не влияют.
В journal нет submitted payload/PII/names/file bytes.

Admin save transactional: lock user, recheck training ownership under lock,
update submitted IDs, create new rows, remove omitted rows, временные позиции
для безопасного reorder при unique index. Чужие/повторные ID отклоняются.
Профиль, карточка и прототипы используют общие утверждённые Blade components.

Participant phone — required scalar string, trim, nonempty, <=255. Любой текст,
локальный номер, короткий номер допустимы. Сохраняется trimmed raw display value;
phone_normalized содержит best-effort digitsForSearch или ''. Код страны не
добавляется. Fingerprint использует непустой цифровой ключ, иначе raw trim-текст.
Admin raw/digit search и показ телефона проверены.

## Checks

Локальный Docker: PHP 8.2.32, MySQL 8.4, dedicated `gruppa_cabinet_test`.
DB-наборы запускались последовательно. Реальных внешних mail/WEBPAY запросов нет.
Runtime .env не читался/не изменялся. Development/production БД не мигрировались.

Команды с префиксом `docker.exe compose exec -T`:

- `-e CACHE_STORE=database php php artisan test --compact --filter='UserTrainingMigrationTest|IntegrationIntakeTest|PsychologistAdminTest|PsychologistProfileTest|ApplicationWorkflowTest'`:
  **87 passed, 1406 assertions, 153.00 s**.
- `-e CACHE_STORE=database php php artisan test --compact --filter='IntegrationConcurrencyTest|PsychologistDocumentTest|PrototypeTest|PasswordSetupTest|ExpiryWarningTest|SharedHostingRuntimeTest|DeploymentPreflightTest|Webpay'`:
  **113 passed, 4014 assertions, 283.98 s**. Включает все 249 prototype variants,
  Stage 11 concurrency, Stage 12/shared runtime и WEBPAY/signature/concurrency.
- `-e CACHE_STORE=database php php artisan test --compact`: **449 passed,
  7501 assertions, 648.45 s**.
- `-e CACHE_STORE=database php php artisan test --compact --filter=PsychologistAdminTest`:
  **15 passed, 263 assertions, 74.98 s** после финальной защиты old input
  с некорректным ID и ссылок ошибок на соответствующий блок.
- `php ./vendor/bin/pint --test`: **PASS, 177 files**. Перед этим Pint исправил
  только import/operator formatting в трёх файлах задачи. Попытка `--dirty`
  недоступна внутри контейнера без .git; использован обычный Pint.
- `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`:
  **No errors**, в том числе после финальной правки перестановки позиций.
- `php composer check-platform-reqs`: **все требования success**.
- `php php artisan view:cache`: **Blade templates cached successfully**.
- `git diff --check` и `git diff --cached --check`: успешно.
- Финальный staged review: 32 файла только текущей задачи; проверены полный
  diff и новые файлы, credential patterns/artifacts. .env, credentials, logs,
  реальные uploads/PII, task/workflow и посторонние файлы отсутствуют.
- `node --check application/public/ui.js`: успешно; Node применялся только
  как существующий инструмент проверки, build/dependencies проекта не добавлялись.

Browser smoke: существующий Playwright + установленный Chromium 1243, временные
скрипты/screenshots только в /tmp. Add, заполнение, reorder, remove, renumber,
ссылка summary на ошибку поля проверены через реальные prototype HTTP pages.
1440/768/390 px: корректные ширины полей, уникальные DOM IDs, нет horizontal
overflow и JS errors. Дополнительно long-варианты admin form/detail и profile
на всех трёх ширинах: HTTP 200, без overflow/JS errors. Mobile screenshots формы
и профиля просмотрены. Business create/edit/detail, private document access, rejected
resubmission/replay/search/rollback проверены через Laravel HTTP feature tests.
Production/public-site handler и реальный пользовательский deployment не проверялись.

## Facts

Стартовый working tree чистый. HEAD fee2b5f — planner текущей задачи; parent
cb31df7196c512478da00acf5d8394c04eb0ab0e соответствует hard gate. Прочитаны workflow,
AGENTS/task/report, релевантные SPEC/UI/docs/runtime/tests до редактирования.
Новых Composer/npm зависимостей, frontend build, public-site изменений нет.
WEBPAY provider signing/trust, mail/password jobs/transports, authentication,
rate limit/allowlist, access/tariff/group lifecycle не изменялись.

Context7 использован для Laravel rules/foreign keys. Он вернул docs 13.x;
validateList и nullOnDelete дополнительно сверены с установленным Laravel 12,
поведение проверено MySQL-набором. Sandbox launcher не работает из-за mount
/mnt/wslg/distro; shell-команды выполнены через разрешённый escalation.
MCP браузеры указывали на отсутствующие revisions 1217/1228; использован уже
установленный Chromium 1243 без установки пакетов и правки настроек.

## Assumptions

Внешний handler согласованно переходит на trainings/certificate_N, сохраняет
логическое содержимое и request ID при retry. Его rollout вне этого репозитория.

## Unknowns / Risks / Next Step

Реализация готова к codex commit.
Production rollout и реальные retained данные не проверялись. Технические
HTTP/PHP limits остаются. Новая схема fingerprint не переписывает старый журнал:
повторы запросов, записанных до rollout, могут дать 409; до смены handler нужно
согласовать незавершённые отправки, не генерируя новый ID вслепую после таймаута.
