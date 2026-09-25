# Report: TASK-2026-09-25-01

Status: done

## Summary

В публичном `.htaccess` добавлен постоянный HTTP → HTTPS redirect для
`gruppa.info` до обслуживания файлов и запуска Laravel. Целевой host фиксирован;
путь и query string сохраняются. Правило проверяет Apache `%{HTTPS}` и не
доверяет `X-Forwarded-Proto` либо `SERVER_PORT`. Login/session-код не менялся.

## Changed Files

- `application/public/.htaccess` — раннее HTTPS-правило для production host.
- `application/tests/Unit/ProductionHttpsRedirectTest.php` — проверка контракта
  `.htaccess`: условия, фиксированный host, порядок и отсутствие proxy/port trust.
- `application/tests/Feature/AuthenticationTest.php` — production HTTPS
  login/home redirects для обеих ролей, session regeneration и failed login;
  существующие localhost/base-path tests сохранены.
- `docs/deployment.md` — HostER probe, правило и точные production smoke-команды.
- `.ai/report.md` — результаты этой задачи.

## Checks

Проверки выполнялись в PHP 8.2.32 / MySQL 8.4. Исходники и зависимости
скопированы во временный `/tmp/task25-check` контейнера без рабочего `.env`;
использован `.env.example`. SHA-256 всех трёх изменённых application-файлов
совпали с файлами проверенной копии.

- Focused `AuthenticationTest|SharedHostingRuntimeTest|DeploymentPreflightTest|ProductionHttpsRedirectTest`:
  **49 passed, 398 assertions**, exit 0.
- Full MySQL suite: **469 passed, 5730 assertions**, exit 0.
- `php ./vendor/bin/pint --test`: **PASS, 180 files**.
- `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`:
  **No errors**.
- `composer check-platform-reqs`: все требования **success**.
- `php artisan view:cache`: **Blade templates cached successfully**.
- Локальный Apache 2.4.68 с проектным `.htaccess`: HTTP запросы к
  `/cabinet/login`, `/cabinet/`, `/cabinet/login?probe=1`, вложенному пути с
  query string и статическому файлу вернули 301 с тем же HTTPS-путём/query.
  `Host: localhost` и `Host: evil.example` не направлялись на production.
  Подставленный клиентом `X-Forwarded-Proto: https` не обходил redirect.
  Закодированный путь `/cabinet/a%20b?x=%2520` сохранился в Location.
  Локальный TLS с одноразовым сертификатом: HTTPS asset вернул 200 без
  redirect, HTTPS login без приложения вернул 404 без redirect/downgrade.
- `git diff --check` и `git diff --cached --check`: PASS. Финальный
  diff/staged review выполнен перед commit: только файлы задачи, без секретов,
  `.env`, логов, данных или временных артефактов.
- Первый focused-прогон временной копии: 14 failed / 35 passed из-за отсутствия
  пустого `storage/framework/views`; после создания runtime-каталогов повторный
  прогон прошёл полностью, как указано выше.
- Production curl и mobile-Chrome login: **не запускались**. Они остаются
  операторской проверкой после deployment.

## Facts

- HEAD перед работой — `2c07740` planner, parent — принятый `5ad0f13`.
  До этой итерации из локальных изменений был только `blocked` отчёт прошлого
  обращения по этой же задаче.
- Пользователь предоставил результат web-PHP probe HostER: HTTP имеет
  `HTTPS=NULL`, HTTPS имеет `HTTPS=on`; оба запроса имеют `SERVER_PORT=80`.
- Apache по умолчанию переносит исходный query string, если substitution не
  задаёт новый. Локальный Apache подтвердил фактический результат.

## Assumptions

- Production public directory получает актуальный `application/public/.htaccess`
  через принятую symlink/copy схему.

## Unknowns / Risks / Next Step

Фактическая production-раздача зависит от parent rewrite rules и обновления
публичного файла при deployment. Оператору нужно выполнить smoke-команды из
`docs/deployment.md` и вход психолога в mobile Chrome. Отдельный 405 /
`route:cache` вопрос не исследовался и не изменялся.
