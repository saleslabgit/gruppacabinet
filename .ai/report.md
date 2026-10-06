# Report: TASK-2026-10-06-01

Status: done

## Summary

- Cabinet: default и `.env.example` переведены на 20480 KiB (20 MiB) на файл.
  MIME whitelist сохранён: `application/pdf`, `image/jpeg`, `image/png`.
  WEBP не добавлен; лимит обложек групп не изменён.
- Добавлены intake boundary tests с реальными временными файлами: 10 MiB,
  ровно 20 MiB, 20 MiB + 1 байт; WEBP с именем `.pdf` отклоняется по содержимому.
  Fixture увеличивается через `ftruncate`, большие бинарники в Git не добавлены.
  При отклонении второго файла первый тоже не сохраняется; нет документов,
  пользователя, idempotency journal и Telegram jobs.
- Локальные PHP/nginx лимиты приведены к контракту: 20M на файл, 128M на POST/body.
  Это устраняет прежний локальный инфраструктурный потолок 10M/12M.
  Production-конфигурация автоматически не меняется.
- Обновлены deployment/development/integration docs и текущий project status.
  Preflight не проверяет upload limits, поэтому не расширялся.
- Внешние `/form` и handler **не изменены и требуют отдельного deployment**.
  Точный handoff оператору приведён ниже и в `docs/integration.md`.

## Changed Files

- `application/config/psychologist_documents.php` — default 20480.
- `application/.env.example` — `PSYCHOLOGIST_DOCUMENT_MAX_KB=20480`.
- `application/tests/Feature/IntegrationIntakeTest.php` — 4 новых boundary/MIME cases.
- `docker/php/uploads.ini`, `docker/nginx/default.conf` — локальная инфраструктура.
- `docs/deployment.md` — production upload limits и ручная проверка обеих сторон.
- `docs/development.md` — актуальные local limits и перезапуск сервисов.
- `docs/integration.md` — контракт intake и внешний operator handoff.
- `docs/project-status.md` — актуальный потолок документов.
- `.ai/report.md` — результаты и handoff.

## Checks

Проверки выполняются в одноразовых `cabinet-upload-php` / `cabinet-upload-mysql`,
PHP 8.2.32 / MySQL 8.4, Docker internal network без доступа наружу.
Исходники и vendor скопированы по явному списку из read-only mount в `/tmp/check`;
private env, storage и cache не копировались. Тестовый `.env` пуст, DB-переменные
явно переданы также subprocess-тестам. MySQL содержит только синтетическую
`gruppa_cabinet_test`; оптимизация fsync применяется только к этой одноразовой БД.

- `php artisan test --filter='document_upload|document_content_size_types_and_validation' --log-junit=/tmp/upload-focused.xml`:
  **5 passed, 97 assertions**, 7.25 s. Включает JPEG/PNG/PDF во всех document parts.
- `php artisan test --filter='IntegrationIntakeTest|IntegrationConcurrencyTest|PsychologistDocumentTest|PsychologistProfileTest|PsychologistAdminTest' --log-junit=/tmp/upload-related.xml`:
  **77 passed, 1418 assertions**, 39.09 s.
- Первый `php artisan test --log-junit=/tmp/upload-full.xml`: **789 passed,
  1 failed, 9027 assertions**, 383.70 s. `PasswordRecoveryTest` /
  `test_public_result_is_identical_for_ineligible_or_unknown_email`, dataset #1
  (pending): ожидался 200 после `travel(61)`, получен 429. Этот код не менялся.
- Диагностический повтор `php artisan test --filter='PasswordRecoveryTest|PasswordRecoveryTimingTest' --log-junit=/tmp/upload-recovery.xml`:
  **44 passed, 607 assertions**, 8.32 s. Сбой не воспроизвёлся, причина не
  установлена; код/тесты восстановления пароля не менялись.
- Финальный `php artisan test --log-junit=/tmp/upload-full-final.xml`: **790 passed,
  9031 assertions**, 373.37 s; JUnit failures/errors/skips: **0/0/0**.
  Повтор выполнен без изменения кода или ослабления тестов.
- `vendor/bin/pint --test`: **PASS, 225 files**.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: **No errors**.
- `composer check-platform-reqs`: все требования success.
- `composer validate --no-check-publish`: composer.json valid.
- `nginx -t` с изменённым local config: syntax ok, test successful.
- PHP с изменённым `uploads.ini`: `upload_max_filesize=20M`, `post_max_size=128M`.
- `DeploymentPreflightTest` отдельно не запускался: preflight не менялся;
  входит в полный suite.
- `php artisan view:cache`: Blade templates cached successfully; использован
  отдельный `VIEW_COMPILED_PATH=/tmp/upload-cli-views`, не мешающий suite.
- `php artisan route:list`: успешно, **130 маршрутов**.
- `git diff --check`, финальный staged review и `git diff --cached --check`: успешно.
  Только 10 файлов задачи; полный diff просмотрен, секретов/private env,
  uploads/logs/cache/vendor/temp artifacts и посторонних файлов нет.
- Сравнение `diff -qr` app/config/database/public/resources/routes/tests в
  тестовой копии с read-only source: расхождений нет.

Обязательные классы в финальном полном suite:

| Класс | Tests | Assertions | Failures/errors/skips |
|---|---:|---:|---|
| IntegrationIntakeTest | 31 | 674 | 0/0/0 |
| IntegrationConcurrencyTest | 3 | 67 | 0/0/0 |
| PsychologistDocumentTest | 8 | 111 | 0/0/0 |
| PsychologistProfileTest | 20 | 303 | 0/0/0 |
| PsychologistAdminTest | 15 | 263 | 0/0/0 |
| DeploymentPreflightTest | 20 | 152 | 0/0/0 |

## Facts

- Стартовый HEAD `93ccb5c` — актуальный planner; parent точно
  `60464736e1a6b7ea03ca02be5211cd4e79f4bcfe`; рабочее дерево было чистым.
- Прочитаны WORKFLOW, AGENTS, task, предыдущий report, intake validator/service,
  документные тесты, настройки и deployment/integration docs.
- Intake уже проверял фактический размер через `getSize()` и MIME через
  `getMimeType()`. Архитектура валидации, хранение, транзакции, идемпотентность,
  анкета, mail и Telegram business behavior не менялись.
- PHP upload/body semantics сверены через Context7 с PHP Manual; ссылки есть
  в `docs/integration.md`.
- `.ai/task.md` прочитана как контракт и не изменена. Private env, включая
  `.env_save`, не читались, не менялись и не копировались.
- Реальных HTTP/Telegram/mail/MODX/payment запросов не выполнялось.
- Runtime-проверка выполнена на Laravel HTTP intake boundary с фактическими
  файлами и MySQL; реальные production PHP/proxy и внешний frontend не проверялись.

## Assumptions

Локальные Docker upload/body ceilings должны позволять проверить новый контракт;
поэтому они повышены вместе с application default. Это не меняет отдельный
application limit обложек групп и не является изменением production settings.

## Unknowns

Текущие effective web PHP/proxy limits и deployment внешнего сайта неизвестны.
Исходный внешний handler не хранится в репозитории; точные номера строк/имена
его методов не заявляются. Его изменения ниже — инструкция оператору.

## Exact External Operator Handoff

1. Внешний handler: заменить
   `const MAX_FILE_SIZE = 10 * 1024 * 1024;`
   на `const MAX_FILE_SIZE = 20 * 1024 * 1024;`.
   Ровно 20971520 байт допустимо; отклонять `> MAX_FILE_SIZE`.
   Оставить только `image/jpeg`, `image/png`, `application/pdf`, с определением
   MIME по содержимому. WEBP не добавлять.
2. Все подсказки diploma/certificate/license/registration на `/form`, включая
   динамические блоки сертификатов, заменить на точный текст:
   `Допустимые форматы: JPG, PNG, PDF; размер — до 20 МБ.`
   Удалить WEBP и 50 МБ. Если есть HTML `accept`, выставить
   `.jpg,.jpeg,.png,.pdf`; JS size validation и hidden `MAX_FILE_SIZE`, если они
   есть, согласовать с 20971520. Серверная проверка остаётся обязательной.
3. Заменить общий catch локального `ValidationException` на mapping по
   структурированным причинам и утверждённому label, без парсинга английского
   текста исключений. Сохранить текущие response envelope/status и field rules.
   Frontend должен показать безопасный message из ответа.

| Причина | Точное публичное сообщение |
|---|---|
| Размер файла | `Файл «<label>» слишком большой. Максимальный размер — 20 МБ.` |
| Неподдерживаемый формат | `Формат файла «<label>» не поддерживается. Используйте JPG, PNG или PDF.` |
| Нет обязательного файла | `Загрузите файл «<label>».` |
| Обычные поля | `Проверьте заполнение формы и попробуйте ещё раз.` |
| Прочий upload failure | `Не удалось загрузить файл «<label>». Попробуйте ещё раз.` |
| Превышен общий body | `Общий размер файлов слишком большой. Уменьшите размер или количество файлов и попробуйте ещё раз. Максимальный размер одного файла — 20 МБ.` |

`<label>` брать из известных полей: Диплом, Сертификат, Лицензия / членство,
Свидетельство о государственной регистрации; не из имени файла/ввода клиента.
Экранировать при HTML-выводе или использовать `textContent`.

4. До чтения temp file/MIME проверять PHP upload error:
   `UPLOAD_ERR_INI_SIZE` и `UPLOAD_ERR_FORM_SIZE` → сообщение размера 20 МБ;
   `UPLOAD_ERR_NO_FILE` / отсутствие part → missing-file только для уже обязательного
   поля (не делать optional files обязательными); другие non-OK → upload failure.
   После `UPLOAD_ERR_OK` проверять upload, фактический размер, затем MIME.
5. До required-field checks обнаруживать oversized multipart body, когда возможно:
   numeric `CONTENT_LENGTH` больше effective positive `post_max_size` (перевести
   K/M/G в байты) → сообщение общего размера, даже при пустых `$_POST`/`$_FILES`.
   Пустой POST сам по себе не доказательство превышения. При отсутствующем или
   ненадёжном Content-Length причина может быть неразличима. Для proxy HTTP 413
   обеспечить такое же безопасное сообщение; handler до PHP тогда не вызывается.
   Не раскрывать номера PHP errors, temp paths, MIME internals, Cabinet/internal
   exception messages или stack traces. Диагностические категории допустимы
   только во внутренних логах.
6. На production Cabinet установить `PSYCHOLOGIST_DOCUMENT_MAX_KB=20480`
   и пересобрать config cache обычной процедурой deployment. На **обеих** сторонах
   проверить effective web PHP `upload_max_filesize >= 20M`, `post_max_size`
   с запасом на всю анкету и все файлы: рекомендуется минимум **128M**, если
   hosting не имеет более строгого согласованного лимита. Apache/nginx/proxy body
   limit должен быть не ниже выбранного общего размера; обеспечить writable
   upload temp storage, свободное место и достаточный `max_file_uploads`.
   CLI-настройки не доказывают web-настройки. Не менять лимиты через runtime ini_set.
7. Cabinet — основной получатель. Сбой вторичного Telegram после принятия Cabinet
   не должен возвращать ошибку уже принятой анкеты. Telegram file limits
   не повышать, Telegram не использовать как document storage.
8. **Ротировать раскрытый Telegram bot token**, новый вынести в приватную
   конфигурацию внешнего handler. Значение токена нигде не копировалось.
   Ротация и внешнее развёртывание из этого репозитория не выполнялись.

После отдельного deployment оператору проверить в staging все форматы, границы,
отсутствующий required file/обычное поле, PHP file/body errors, proxy 413,
multi-file request и вторичный Telegram failure после успеха Cabinet (через stubs).

## Risks / Next Step

Готово к приёмке. Первый одиночный 429 в PasswordRecoveryTest не воспроизвёлся
ни в диагностическом, ни в повторном полном прогоне; его причина не установлена.
Одноразовые тестовые контейнеры, данные и сеть удалены после проверок.
Внешние `/form`/handler и production upload settings требуют отдельного deployment
оператором; без него исходная публичная ошибка может сохраняться.
