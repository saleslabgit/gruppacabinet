# Report: TASK-2026-09-25-01

Status: done

## Summary

Исправлена предыдущая HTTPS-переадресация. После развёртывания коммита
`b9878954884de354a9f815ced64abe1d4b80a4ec` production HTTPS попал в
`ERR_TOO_MANY_REDIRECTS`; оператор временно вернул production `.htaccess` к
версии до задачи, и цикл исчез. Новый HostER probe подтвердил, что публичный
прокси перезаписывает `X-Forwarded-Proto`. Теперь правило перенаправляет только
при `HTTPS != on` **и** `X-Forwarded-Proto != https`. Целевой host остаётся
фиксированным `gruppa.info`; путь и query string сохраняются. Laravel
login/session-код не менялся.

## Changed Files

- `application/public/.htaccess` — учитывает проверенный HostER proxy signal
  наряду с Apache HTTPS; `SERVER_PORT` не используется.
- `application/tests/Unit/ProductionHttpsRedirectTest.php` — проверяет оба
  условия, фиксированный host, порядок правила и отсутствие зависимости от port.
- `docs/deployment.md` — описывает новый probe и production smoke с подменой
  клиентского `X-Forwarded-Proto` в обоих направлениях.
- `.ai/report.md` — результаты исправляющей итерации.

## Checks

Проверки выполнены в PHP 8.2.32 / MySQL 8.4 на временной копии
`/tmp/task25-corrective-check` в PHP-контейнере. Рабочие `.env*` не копировались;
для стенда использована `.env.example`. SHA-256 изменённого `.htaccess`,
регрессионного теста и неизменённого `AuthenticationTest` совпали с рабочим
репозиторием.

- Focused `AuthenticationTest|SharedHostingRuntimeTest|DeploymentPreflightTest|ProductionHttpsRedirectTest`:
  **49 passed, 397 assertions**, exit 0.
- Full MySQL suite: **469 passed, 5712 assertions**, exit 0.
- `php ./vendor/bin/pint --test`: **PASS, 180 files**.
- `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`:
  **No errors**.
- `composer check-platform-reqs`: все требования **success**.
- `php artisan view:cache`: **Blade templates cached successfully**.
- Локальный Apache 2.4.68 с текущим `.htaccess`: синтаксис OK; HTTP без XFP
  и с `X-Forwarded-Proto: http` вернул 301 с фиксированным HTTPS Location,
  сохранив путь/query. HTTP с `X-Forwarded-Proto: https` прошёл без редиректа
  (модель сигнала после HostER proxy). Прямой TLS с
  `X-Forwarded-Proto: http` вернул 200 без цикла. `Host: localhost` и
  `Host: evil.example` не перенаправлялись на production.
- `git diff --check` и `git diff --cached --check`: PASS. Итоговый diff и staged
  файлы проверены перед коммитом; посторонние файлы и секреты не добавлены.
- Исправленное правило **не развёртывалось и не проверялось на production** в
  этой итерации. Production curl и mobile-Chrome login из документации — не
  запускались после исправления.

## Facts

- HEAD до исправления — `b987895`, предыдущий `codex:` коммит этой задачи.
  Рабочий Git tree был чистым. Отдельное явное указание пользователя разрешило
  исправляющую итерацию без нового planner-коммита.
- Сообщённый оператором production smoke после `b987895`: HTTPS входил в
  `ERR_TOO_MANY_REDIRECTS`; восстановление прежнего `.htaccess` убрало цикл.
- Сообщённый оператором HostER probe: HTTP даёт `HTTPS=NULL`, `XFP=http`;
  клиентский `X-Forwarded-Proto: https` на HTTP всё равно приходит как `http`;
  HTTPS с клиентским `X-Forwarded-Proto: http` приходит как `HTTPS=on`,
  `XFP=https`. `REMOTE_ADDR=2a0a:7d80:1:7::154`.
- Apache 2.4 документация подтверждает проверку `X-Forwarded-Proto` за TLS
  terminator, если upstream proxy контролируется и заголовок нельзя подделать.
  Здесь это условие подтверждено production probe для публичного endpoint.

## Assumptions

- Production public directory при следующем deployment получит новый
  `application/public/.htaccess` через принятую symlink/copy схему.

## Unknowns / Risks / Next Step

Результат исправленного правила на реальном HostER ещё не проверен. Оператору
нужно обновить production `.htaccess`, выполнить все команды из
`docs/deployment.md`, проверить отсутствие цикла и пройти вход психолога в
mobile Chrome. Отдельный 405 / `route:cache` вопрос не исследовался.
`application/.env_save` и другие локальные конфигурации не менялись.
