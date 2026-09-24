# Report: TASK-2026-09-24-03

Status: done

## Summary

Исправлен только путь замены обучений в Stage 11 intake. Новый психолог больше
не вызывает DELETE. Pending/rejected resubmission использует прямой DELETE без
ORDER BY и без server-side prepare, с явно приведённым к int ID пользователя.
Транзакция, создание новых обучений, связь сертификатов и cleanup сохранены.
Все обязательные проверки завершены успешно; hotfix готов к развёртыванию.

## Root Cause / Code Change

Production evidence из `.ai/task.md`: MySQL 8.0.46 вернул errno 1615
«Prepared statement needs to be re-prepared» на `$user->trainings()->delete()`.
Связь `User::trainings()` включает `orderBy('position')`, поэтому DELETE
наследовал ORDER BY и шёл через prepared execution. Он выполнялся даже сразу
после создания нового пользователя, когда удалять нечего. Форма, trainings
contract и файлы не были причиной зафиксированного сбоя.

В `IntakeService::psychologist()` DELETE выполняется только при `! $new`:

```php
DB::unprepared('DELETE FROM `gp_user_trainings` WHERE `user_id` = '.(int) $user->id);
```

Laravel 12 Connection::unprepared использует PDO::exec на том же соединении,
а не prepare/execute. Это DML внутри существующей intake/journal transaction;
ON DELETE SET NULL и rollback продолжают работать. В SQL интерполируется только
строго приведённый integer ID сохранённой модели; строки запроса не используются.
Глобальные PDO options, connection defaults и retry policy не менялись.
Внутренняя серверная причина invalidation prepared statement на production не
исследовалась: исправляется конкретный воспроизведённый там execution path.

## Changed Files

- `application/app/Integration/IntakeService.php` — условный прямой DELETE.
- `application/tests/Feature/IntegrationIntakeTest.php` — regression test
  выполнения SQL для new/resubmission/replay с реальным DB-соединением.
- `.ai/report.md` — этот отчёт.

API/SPEC/integration docs, миграции, схема, admin UX, frontend, .htaccess,
.env/config, WEBPAY, mail/password, login/session/auth, телефоны, group lifecycle,
rate limit/allowlist, API error envelope и fingerprint не изменялись.

## Checks

Локально PHP 8.2.32, обычный MySQL 8.4 и disposable MySQL 8.0.46.
DB suites выполнялись последовательно, только synthetic data, без реальных
mail/WEBPAY вызовов. Production и development данные не изменялись.

Команды с префиксом `docker.exe compose exec -T`:

- Red check на старом коде: `-e CACHE_STORE=database php php artisan test --compact --filter=test_training_delete_is_skipped`:
  1 failed, 4 assertions, 11.81 s. Тест обнаружил прежний ordered prepared DELETE
  при создании пользователя. Ранее настройка test double была исправлена,
  чтобы делегировать не перехваченные вызовы реальному DB manager.
- `-e CACHE_STORE=database php php artisan test --compact --filter='IntegrationIntakeTest|IntegrationConcurrencyTest|UserTrainingMigrationTest|PsychologistAdminTest|PsychologistProfileTest|PsychologistDocumentTest'`:
  71 passed, 1 failed, 1350 assertions, 150.03 s. Единственный сбой — строгое
  сравнение PDO integer 0 с false в новом тесте; assertion исправлен на integer 0.
  SQL execution, transaction, resubmission/replay проверки уже прошли.
- Повтор `-e CACHE_STORE=database php php artisan test --compact --filter=IntegrationIntakeTest`:
  **24 passed, 600 assertions, 24.84 s**.
- Полный MySQL 8.4 suite, `-e CACHE_STORE=database php php artisan test --compact`:
  **450 passed, 7430 assertions, 540.09 s**, exit 0.
- `php ./vendor/bin/pint --test`: PASS, 177 files. Финальные два изменённых PHP-файла
  дополнительно проверены `pint --test app/Integration/IntakeService.php tests/Feature/IntegrationIntakeTest.php`: PASS, 2 files.
- `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: No errors.
- `php composer check-platform-reqs`: все требования success, PHP 8.2.32.
- `php php artisan view:cache`: Blade templates cached successfully.
- `git diff --check` и `git diff --cached --check`: успешно.
- Финальный diff/staged review: ровно три файла из Changed Files; нет миграций,
  env/config, реальных PII/uploads, логов, временных или посторонних артефактов.
  Проверка добавленных строк на признаки секретов пройдена; unstaged/untracked
  файлов нет.

Новый regression test проверяет отсутствие training DELETE при создании,
реальные два вызова unprepared при pending/rejected resubmission, отсутствие
ORDER BY/placeholder/bindings, целочисленный ID, активную intake transaction,
замену ID обучений, сохранение ID на replay и отключённые emulated prepares.
Существующие intake tests проверяют SET NULL, сохранность документов/private
файлов, создание новых связей, rollback после journal/storage failure и conflict
matrix. Полный набор также покрывает остальные границы приложения.

## MySQL 8.0.46 Verification

Получен официальный `mysql:8.0.46`, digest
`sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b`.
Одноразовый контейнер без опубликованных портов, данные в tmpfs;
`SELECT VERSION()` подтвердил `8.0.46`. Временная копия phpunit.xml только в
контейнерном /tmp направляет IntegrationIntakeTest в отдельную БД этого сервера.
Compose и зависимости проекта не менялись.

Прямой запуск (единственная конфигурация):
`docker.exe compose exec -T -e CACHE_STORE=database php php vendor/bin/phpunit --configuration=/tmp/task03-phpunit.xml --filter=IntegrationIntakeTest`:
**24 tests, 600 assertions, 20.631 s, 58.50 MB, OK** на MySQL 8.0.46.
Предыдущая попытка через artisan wrapper дала предупреждение дублированного
--configuration и exit 1, несмотря на 24 passed / 600 assertions (21.61 s);
она не используется как подтверждение MySQL 8.0.46.

После этого отдельный transactional probe на том же isolated сервере вызвал
оригинальный `$user->trainings()->delete()` с двумя синтетическими обучениями:
`version=8.0.46`, `emulated_prepares=0`, `deleted_rows=2`, errno 1615 не возник.
Все probe-данные откачены. Таким образом, hotfix и сохранение intake semantics
проверены на точной версии, но **сам production errno 1615 локально не воспроизведён**.
Доказательство исходной ошибки — production evidence, записанное в `.ai/task.md`.
Чистый локальный сервер не воспроизводит состояние/нагрузку shared hosting.

Одноразовый контейнер с tmpfs и временные config/probe-файлы удалены после проверки.

## Facts

Working tree был чистым. HEAD af94bdf — актуальный planner, parent
7e3ed67fd8c700549dc998bb4f1886aa930fc600 соответствует gate. Task/workflow/AGENTS,
предыдущий report, модель/связь/миграция и текущие intake tests прочитаны.
Context7 использован для DB::unprepared/transactions; он возвращает docs 13.x,
поэтому путь PDO::exec дополнительно проверен в установленном Laravel 12.
Sandbox launcher недоступен из-за host mount /mnt/wslg/distro; использован
разрешённый escalation. Runtime .env не читался и не изменялся.

## Assumptions

На production развёрнут описанный в задаче код и уже применена миграция TASK-02.

## Unknowns / Risks / Next Step

Следующий шаг — развёртывание и проверка intake на shared hosting.
Production acceptance не выполнялась из этого workspace.

Deployment: обычный `git pull`, затем из каталога `application`
`php artisan optimize:clear`. Для этого hotfix **новая миграция не требуется**.
После обновления повторить сохранённую неуспешную отправку с тем же request ID:
откатившийся 500 не создаёт завершённой записи журнала. Успешный same-ID replay
сохраняет прежний результат без повторных записей/файлов.
