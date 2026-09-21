# Report: TASK-2026-09-21-11

Status: done

## Summary

Реализован Stage 12: одноразовая установка первого пароля, приглашение после
commit одобрения, admin resend, письма через database queue, часовые уведомления
об окончании размещения. Реальный HTTP/SMTP smoke и полный MySQL regression пройдены: 365 tests /
4545 assertions. Окончательный focused прогон: 40 tests / 477 assertions.
WEBPAY и Stage 11 API implementation не изменялись.

## Architecture / behavior

- Новая миграция `2026_09_21_120000_create_password_reset_tokens_table` создаёт
  стандартную таблицу `password_reset_tokens` с email, хешем token, created_at.
- `PasswordSetupService` на каждой операции создаёт Laravel PasswordBroker и
  DatabaseTokenRepository, использует штатный Eloquent provider и APP_KEY.
  Генерация, хеширование, проверка и удаление токенов — framework code.
- Текущий SettingService::passwordSetupLinkTtlHours() переводится в секунды;
  broker не кешируется в worker. Изменение TTL влияет на ранее выданные токены.
  Проверенная граница Laravel isPast: точный момент expiry ещё допустим,
  момент после него — нет; хранение с точностью до секунды. Увеличение TTL
  может вернуть валидность ещё не использованной ссылки, что документировано.
- GET `/password/setup/{token}?email=...`, POST `/password/setup`; общий лимит
  10/min/IP. Существующий login limiter сохранён. Успех: hashed cast пароля,
  ротация remember_token, удаление broker token под user lock, без автовхода.
- Допускаются только approved/enabled/non-deleted/non-admin/password-null.
  POST требует email/token/password/confirmation, пароль 8–255 символов.
  Открытого forgot-password/reset-request endpoint нет.
- Approval регистрирует afterCommit callback: rollback не создаёт приглашение.
  После commit отдельная транзакция заменяет токен и вставляет database job.
  Ошибка инфраструктуры оставляет approval/audit сохранёнными и пишет только
  безопасное сообщение/user_id; восстановление — admin resend.
- Admin POST `/admin/psychologists/{id}/password-setup`: account/admin, policy,
  Form Request, CSRF, 1/min/admin/target. Токен и queue insert атомарны;
  прежняя ссылка сразу теряет валидность после успешного commit. Audit action
  `user.password_setup_resent`, actor/entity, metadata=null, без email/URL/token.
- SendPasswordSetup перед отправкой проверяет текущее состояние и тот же токен.
  Старое queued job после resend завершается без письма. Retry не меняет токен.
- GET setup не сохраняется в session previous URL; validation не flash-ит ввод.
  no-store/no-referrer, безопасные ответы при исключениях даже в APP_DEBUG.
  Письмо и hidden input действительной формы содержат токен по назначению;
  закрытые database queue payloads также могут содержать его.
- `groups:queue-expiry-warnings`: hourly + withoutOverlapping; текущий threshold
  читается один раз, выборка chunkById(200), eligibility owner через EXISTS,
  вывод только фактического queued count. Disabled группы исключены без маркера.
- SendExpiryWarning implements ShouldBeUnique. Ключ: group ID + exact expires_at
  UTC `Y-m-d H:i:s`, совпадающая с БД точность. Команда берёт Laravel UniqueLock
  явно перед Bus dispatch, worker освобождает тот же lock после завершения;
  при dispatch failure команда освобождает lock. TTL блокировки не ограничен,
  поэтому ожидание/выполнение/retry не открывает окно повторной постановки.
- Job повторно читает группу/владельца/current threshold. После успешного SMTP
  отдельная транзакция блокирует группу и проверяет active, exact expiry и null
  marker, затем пишет expiry_warning_sent_at. SMTP exception/cancel не ставит
  marker. Старое письмо не отмечает новый период при конкурентном продлении.
- Письма содержат только необходимые данные, HTML/text по-русски без внешних
  ресурсов. Warning указывает Europe/Minsk и защищённую ссылку группы.
- Оба job: database queue, 3 tries, backoff 60/300, timeout 45; SMTP timeout 15.
  Разрешён SMTP transport, чтобы log/failover-to-log не раскрывал ссылку.
  Transport exceptions заменяются техническими без previous exception.
  Bootstrap включает zend.exception_ignore_args=1: worker exception traces
  не содержат аргументы с serialized payload и токеном.
- Lifecycle expiration не зависит от писем/очереди. Warning не меняет
  status/expires_at/placement_days, не создаёт платёжных эффектов.

## Changed Files

Добавлены broker/setup service, два jobs, два mailables и четыре mail templates,
password controller/Form Request/session middleware, warning command, миграция,
два feature suites и тестовый SMTP receipt fake. Подключены approval, admin
controller/policy/action/history, rate limits, routes/schedule, existing password
Blade. Добавлены Mailpit/worker в корневой Compose и SMTP defaults. Обновлены
email/development/architecture/project-status/ui-pages docs и этот отчёт.
Task/SPEC/WORKFLOW/AGENTS, Stage 11 API, CSS и lifecycle implementation не менялись.
В существующих тестах login ограничен срок действия Auth mock и добавлен
assertOk; Stage 11 теперь проверяет отсутствие token rows после API вместо
устаревшего ожидания отсутствия самой таблицы.

## Checks

- `docker compose ps`: MySQL/PHP/Mailpit healthy, web и queue-worker запущены.
- Non-destructive `php artisan migrate --force`: новая миграция выполнена;
  `php artisan db:seed --force`: успешно, существующие данные не очищались.
- `schedule:list`: warnings hourly, groups:expire every minute,
  applications:cleanup daily, overlap protection сохранена.
- `queue:failed`: No failed jobs found; после smoke очередь пуста.
- Profile suites проверяют broker/hash/TTL, rollback и queue failure, роли,
  CSRF/лимиты, invalid/expired/reused links, stale mail, marker ordering,
  очередь/исполнение/retry uniqueness, extension/republication и lifecycle.
  Финальный запуск `docker compose exec -T -e APP_DEBUG=false php php artisan
  test --filter='PasswordSetupTest|ExpiryWarningTest|IntegrationIntakeTest'`:
  40 passed / 477 assertions, 29.57 s. Включает 22 новых password/warning tests
  и 18 тестов Stage 11; выполнен после последней настройки exception traces.
- Полный MySQL прогон `docker compose exec -T -e APP_DEBUG=false php php artisan
  test`: 365 passed / 4545 assertions, 303.54 s. Все 31/249 прототипов и
  production isolation прошли, включая Stage 11 concurrency/signatures/replay.
  Команда без APP_DEBUG override также запускалась, но тот ранний прогон был
  остановлен до завершения. Промежуточный полный прогон выявил два описанных
  выше устаревших тестовых ожидания; они исправлены до успешного финального.
- `./vendor/bin/pint --test`: PASS, 146 PHP files.
- `./vendor/bin/phpstan analyse --no-progress`: PASS, no errors. Первый холодный
  прогон превысил 128 MiB; повтор с --memory-limit=512M прошёл, затем точная
  команда без override тоже прошла.
- `composer check-platform-reqs`: все требования проходят (PHP 8.2.32).
- `php artisan view:cache`: успешно.
- Local/production route lists: setup/resend присутствуют, production не имеет
  prototype/foundation routes; публичного forgot-password endpoint нет.
- `git diff --check` и `git diff --cached --check`: успешно. Финальный diff и
  staged review выполнены: 37 файлов текущей задачи, нет credentials/raw tokens,
  captured messages, runtime artifacts или посторонних изменений. Task/SPEC/
  WORKFLOW/AGENTS не staged.

## Runtime smoke

Выполнен через реальные HTTP-формы/CSRF и Docker, синтетические @example.test
адреса. Временный сценарий/вывод хранились вне репозитория. Графический браузер
для этого прогона не использовался; CSS/layout не изменены.

1. Реальный admin login, detail и approval POST двух pending password-null
   пользователей; по одной job на approval до запуска worker.
2. `queue:work database --once --tries=3 --timeout=45` отправил реальное письмо
   в Mailpit. Открыт именно полученный URL, установлен пароль, выполнен login,
   повторное использование URL отвергнуто.
3. Resend через admin POST немедленно сделал старую ссылку недействительной;
   старое ожидающее job не отправило письмо, новое отправило рабочую ссылку,
   по ней успешно установлен пароль.
4. Outside threshold — 0; inside — 1; повтор до worker — 0; реальное письмо
   warning захвачено, marker установлен, последующий scheduler — 0.
5. Mailpit остановлен: реальное SMTP-падение оставило marker=null, job осталась
   для retry (attempts=1). Группа стала due; groups:expire успешно перевёл её
   в expired при недоступном SMTP. После восстановления stale retry завершился
   без нового письма.
6. Проверены application logs, audit и decoded session payloads: известных
   smoke tokens и выбранного пароля нет. Failed jobs=0.
7. Синтетические пользователи/группа/history/audit и captured messages удалены,
   jobs завершены; Mailpit и persistent queue-worker восстановлены.
8. Дополнительный реальный smoke приглашения: SMTP остановлен, worker сохранил
   job для retry; raw token отсутствует в worker exception log, bootstrap flag=1.
   После восстановления SMTP та же job доставила письмо с тем же токеном,
   очередь опустела. Дополнительные пользователь/token/message удалены.

## Facts / Assumptions / Unknowns

- Base `7bb1c7410e9e6db2d3225c5b8637681a7459ee8f`, planner `208c452`;
  исходное рабочее дерево было чистым. Новая задача однозначно разрешена.
- Осмотрены установленные Laravel 12 PasswordBroker, DatabaseTokenRepository,
  PasswordBrokerManager и unique queue implementation. Context7 вернул docs
  текущей ветки 13, поэтому детали сверены с installed 12 source и официальной
  документацией https://laravel.com/docs/12.x/queues.
- Mailpit pinned v1.27.8, SMTP mailpit:1025, UI 127.0.0.1:8025, без credentials.
- Локальный file cache доступен контейнерам через общий mount. В production
  нужен shared cache/lock store для всех экземпляров и актуальных settings.
- В production нужны настоящий SMTP, APP_URL=https://gruppa.info/cabinet,
  supervisor/systemd либо cron worker, scheduler cron и ограниченный доступ
  к queue/failed jobs/storage. Локальный образ не имеет PCNTL; SMTP timeout
  ограничивает ожидание транспорта. Production worker должен иметь PCNTL
  для жёсткого job timeout, retry_after должен превышать timeout.
- SMTP acceptance не гарантирует доставку в inbox. При аварии процесса между
  SMTP success и DB marker возможна повторная доставка; это документированное
  окно между двумя системами, которое unique dispatch не устраняет.
- Production SMTP/deployment и внешняя доставка не проверялись и не входят
  в задачу. HTTP access/error logging в production должно редактировать setup
  path/query. Настоящие SMTP credentials не использовались.

## Risks / Next Step

Stage 12 завершён и проверен локально. Production deployment не выполнялся.
Stage 13 WEBPAY
Sandbox остаётся pending: нужны цены, merchant/Sandbox configuration, актуальный
provider contract и доступные callback URLs. Stage 12 не выполняет платежи.
