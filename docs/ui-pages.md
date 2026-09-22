# Каталог страниц Stage 3

Все URL ниже начинаются с `/cabinet/_prototype/` в локальном Docker. Данные вымышлены; бизнес-действия, формы, загрузка документов, выход и платежи — no-op. Проверяемые действия: навигация, модальные окна и копирование UUID.

Шаблоны используют layouts/public, psychologist или admin; общие компоненты panel, button, alert, status, поля формы, validation-error/summary, table/cell, pagination, confirmation, dropdown, date/money. Таблицы превращаются в подписанные карточки на телефоне; формы и детали переходят в одну колонку; навигация переносится без hover. Длинные значения переносятся.

Визуальная ревизия TASK-2026-09-21-09 обновляет общий интерфейс всех 31 групп и сохраняет 249 вариантов. Используются локальные Montserrat 500/600 и Bootstrap Icons 1.13.1 (CSS/WOFF/WOFF2 в `public/vendor/bootstrap-icons/1.13.1`, MIT). Иконки декоративные, с `aria-hidden`; текст действий сохраняется. CDN и frontend build не нужны.

Общая система: шаги отступов 4/8/12/16/20/24/32/48 px, заголовки 30/21/18 px (25/19/17 на телефоне), основной текст 15 px, метаданные 13 px. Радиусы поверхностей 10–12 px, контролов 8 px; спокойная нейтральная палитра с оранжевым основным действием. Навигация, ссылки, кнопки и пагинация имеют различимые hover/focus/current состояния. Уведомления компактны; история использует вторичную секцию, согласия — общую белую карточку. Анкета разделена на контакты, образование и опыт. Формы ограничены по ширине, обязательность отмечается только у обязательных полей.

Вложенные страницы используют общий `breadcrumbs`: предки — ссылки, текущая страница — текст. Иерархия групп включает список, группу, редактирование/продление/заявки; карточка заявки психолога сохраняет путь через группу и список заявок. Аналогично связаны психологи, документы, платежи и справочники администратора. Отмена редактирования возвращает к объекту, создание — к списку. Таблицы превращаются в подписанные блоки на телефоне, короткие факты используют две колонки, длинные поля переносятся. Под 1200 px административное меню раскрывается кнопкой «Меню» с иконкой бургера; Escape закрывает его и возвращает фокус. Без JavaScript ссылки остаются видимыми. В мобильной шапке психолога выход расположен рядом с логотипом, разделы — следующей строкой.

Правки по визуальному архиву от 21.09.2026: кнопки, теги, крошки, чекбоксы и заголовки модальных окон выровнены по центру; тарифы оформлены тегами. Фильтры используют белую поверхность, быстрые фильтры разделены отступами. Поля имеют текст 14 px и смену цвета границы при фокусе без внешнего кольца. Select прогрессивно заменяется оформленным списком: стрелки/Home/End, выбор Enter/Space, поиск по начальным буквам, Escape/Tab, клик снаружи; исходный select сохраняет значения формы, required-валидацию и работу без JavaScript. Карточка психолога показывает его группы со статусами и ссылками, по 10 на страницу, и историю в общей временной шкале. В форме редактирования сведения о статусе, тарифе и доступе находятся внутри анкеты со ссылкой на карточку; отдельной панели нет.

Повторная визуальная правка (архив 20:29): теги имеют единый размер и вес шрифта, доступ выделен голубым, тариф — нейтральным цветом. У законченной группы иконка календаря с отметкой; в списке групп психолога статус расположен под названием. Поле файла имеет кнопку на полную высоту, действия справочников — одинаковую ширину. URL `ui.css` и `ui.js` включают версию по времени изменения файла, чтобы браузер обновлял стили и поведение меню/select после правок.

Действия группы расположены рядом с её названием и статусом, до длинного описания. На карточке нет ссылки «Подробнее» на саму себя. В пустом списке остаётся одна кнопка создания. Фильтрованные пустые заявки отличаются от первого использования. Реальная главная администратора содержит ссылки на доступные разделы без вымышленных счётчиков. Сам редизайн не подключал платежи и установку пароля; теперь они подключены к реальным маршрутам последующими этапами, как описано ниже.

Состояние permission показывает реальную errors/403 Blade-страницу. Системные ошибки имеют собственные views и не требуют fixtures при штатном рендеринге. Варианты pagination показывают вторую демонстрационную страницу. Validation используется только на формах, empty — в списках; для read-only страниц сохранение/валидация не применяются.

## 1. Вход

View: `application/resources/views/auth/login.blade.php`.

Компоненты: panel, input/textarea/select, label, validation-error/summary, button; настройки также используют confirmation. Форма занимает всю доступную ширину; колонки складываются на телефоне. Сохранение и отправка не выполняются.

- `/cabinet/_prototype/login/normal`
- `/cabinet/_prototype/login/validation`
- `/cabinet/_prototype/login/error`
- `/cabinet/_prototype/login/rate-limit`
- `/cabinet/_prototype/login/disabled`

## 2. Установка пароля

View: `application/resources/views/auth/password.blade.php`.

Компоненты: panel, input/textarea/select, label, validation-error/summary, button; настройки также используют confirmation. Форма занимает всю доступную ширину; колонки складываются на телефоне. Сохранение и отправка не выполняются.

- `/cabinet/_prototype/password/normal`
- `/cabinet/_prototype/password/validation`
- `/cabinet/_prototype/password/expired`
- `/cabinet/_prototype/password/invalid`
- `/cabinet/_prototype/password/success`
- `/cabinet/_prototype/password/long`

## 3. Системные ошибки

View: `application/resources/views/errors/403.blade.php` (коды 403/404/419/429/500 имеют отдельные файлы).

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/errors/403`
- `/cabinet/_prototype/errors/404`
- `/cabinet/_prototype/errors/419`
- `/cabinet/_prototype/errors/429`
- `/cabinet/_prototype/errors/500`

## 4. Уведомления и подтверждения

View: `application/resources/views/shared/notices.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/notices/success`
- `/cabinet/_prototype/notices/validation`
- `/cabinet/_prototype/notices/warning`
- `/cabinet/_prototype/notices/danger`
- `/cabinet/_prototype/notices/confirmation`
- `/cabinet/_prototype/notices/long`

## 5. Мои группы — пустой список

View: `application/resources/views/psychologist/groups/index.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/groups-empty/empty`

## 6. Мои группы

View: `application/resources/views/psychologist/groups/index.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/groups/normal`
- `/cabinet/_prototype/groups/awaiting_payment`
- `/cabinet/_prototype/groups/draft`
- `/cabinet/_prototype/groups/moderation`
- `/cabinet/_prototype/groups/revision`
- `/cabinet/_prototype/groups/rejected`
- `/cabinet/_prototype/groups/approved`
- `/cabinet/_prototype/groups/active`
- `/cabinet/_prototype/groups/warning`
- `/cabinet/_prototype/groups/expired`
- `/cabinet/_prototype/groups/outside-window`
- `/cabinet/_prototype/groups/disabled`
- `/cabinet/_prototype/groups/long`
- `/cabinet/_prototype/groups/success`
- `/cabinet/_prototype/groups/permission`
- `/cabinet/_prototype/groups/confirmation`
- `/cabinet/_prototype/groups/pagination`

## 7. Создание и редактирование группы

View: `application/resources/views/psychologist/groups/form.blade.php`.

Компоненты: panel, input/textarea/select, label, validation-error/summary, button; настройки также используют confirmation. Форма занимает всю доступную ширину; колонки складываются на телефоне. Сохранение и отправка не выполняются.

- `/cabinet/_prototype/group-form/create`
- `/cabinet/_prototype/group-form/draft`
- `/cabinet/_prototype/group-form/revision`
- `/cabinet/_prototype/group-form/validation`
- `/cabinet/_prototype/group-form/success`
- `/cabinet/_prototype/group-form/long`
- `/cabinet/_prototype/group-form/disabled`
- `/cabinet/_prototype/group-form/permission`

## 8. Просмотр группы

View: `application/resources/views/psychologist/groups/show.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/group/awaiting_payment`
- `/cabinet/_prototype/group/draft`
- `/cabinet/_prototype/group/moderation`
- `/cabinet/_prototype/group/revision`
- `/cabinet/_prototype/group/rejected`
- `/cabinet/_prototype/group/approved`
- `/cabinet/_prototype/group/active`
- `/cabinet/_prototype/group/warning`
- `/cabinet/_prototype/group/expired`
- `/cabinet/_prototype/group/outside-window`
- `/cabinet/_prototype/group/disabled`
- `/cabinet/_prototype/group/long`
- `/cabinet/_prototype/group/permission`
- `/cabinet/_prototype/group/confirmation`
- `/cabinet/_prototype/group/paid-delete-blocked`

## 9. Оплата размещения

View: `application/resources/views/psychologist/payments/placement.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/placement/normal`
- `/cabinet/_prototype/placement/retry`
- `/cabinet/_prototype/placement/disabled`
- `/cabinet/_prototype/placement/long`

## 10. Подтверждение оплаты

View: `application/resources/views/psychologist/payments/return.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/payment-pending/pending`
- `/cabinet/_prototype/payment-pending/long`

## 11. Оплата подтверждена

View: `application/resources/views/psychologist/payments/return.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/payment-success/succeeded`
- `/cabinet/_prototype/payment-success/extension-active`
- `/cabinet/_prototype/payment-success/extension-expired`

## 12. Результат попытки оплаты

View: `application/resources/views/psychologist/payments/return.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/payment-result/failed`
- `/cabinet/_prototype/payment-result/cancelled`
- `/cabinet/_prototype/payment-result/browser-cancel`
- `/cabinet/_prototype/payment-result/undetermined`

## 13. Продление размещения

View: `application/resources/views/psychologist/groups/extension.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/extension/free-active`
- `/cabinet/_prototype/extension/free-expired`
- `/cabinet/_prototype/extension/paid-active`
- `/cabinet/_prototype/extension/paid-expired`
- `/cabinet/_prototype/extension/outside-window`
- `/cabinet/_prototype/extension/pending`

## 14. Заявки группы

View: `application/resources/views/psychologist/applications/index.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/applications/normal`
- `/cabinet/_prototype/applications/empty`
- `/cabinet/_prototype/applications/new`
- `/cabinet/_prototype/applications/processed`
- `/cabinet/_prototype/applications/long`
- `/cabinet/_prototype/applications/success`
- `/cabinet/_prototype/applications/pagination`
- `/cabinet/_prototype/applications/permission`

## 15. Просмотр заявки

View: `application/resources/views/psychologist/applications/show.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/application/new`
- `/cabinet/_prototype/application/processed`
- `/cabinet/_prototype/application/long`
- `/cabinet/_prototype/application/permission`

## 16. Мои данные

View: `application/resources/views/psychologist/profile/show.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/profile/normal`
- `/cabinet/_prototype/profile/long`
- `/cabinet/_prototype/profile/no-documents`
- `/cabinet/_prototype/profile/permission`

## 17. Рабочая сводка

View: `application/resources/views/admin/home.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/admin-home/normal`
- `/cabinet/_prototype/admin-home/empty`
- `/cabinet/_prototype/admin-home/long`
- `/cabinet/_prototype/admin-home/permission`

## 18. Психологи

View: `application/resources/views/admin/users/index.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/admin-users/normal`
- `/cabinet/_prototype/admin-users/pending`
- `/cabinet/_prototype/admin-users/approved`
- `/cabinet/_prototype/admin-users/rejected`
- `/cabinet/_prototype/admin-users/disabled`
- `/cabinet/_prototype/admin-users/free`
- `/cabinet/_prototype/admin-users/paid`
- `/cabinet/_prototype/admin-users/empty`
- `/cabinet/_prototype/admin-users/no-results`
- `/cabinet/_prototype/admin-users/long`
- `/cabinet/_prototype/admin-users/pagination`
- `/cabinet/_prototype/admin-users/permission`
- `/cabinet/_prototype/admin-users/success`

## 19. Просмотр психолога

View: `application/resources/views/admin/users/show.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/admin-user/pending`
- `/cabinet/_prototype/admin-user/approved`
- `/cabinet/_prototype/admin-user/rejected`
- `/cabinet/_prototype/admin-user/disabled`
- `/cabinet/_prototype/admin-user/free`
- `/cabinet/_prototype/admin-user/paid`
- `/cabinet/_prototype/admin-user/confirmation`
- `/cabinet/_prototype/admin-user/long`
- `/cabinet/_prototype/admin-user/permission`
- `/cabinet/_prototype/admin-user/success`

## 20. Создание и редактирование психолога

View: `application/resources/views/admin/users/form.blade.php`.

Компоненты: panel, input/textarea/select, label, validation-error/summary, button; настройки также используют confirmation. Форма занимает всю доступную ширину; колонки складываются на телефоне. Сохранение и отправка не выполняются.

- `/cabinet/_prototype/admin-user-form/create`
- `/cabinet/_prototype/admin-user-form/edit`
- `/cabinet/_prototype/admin-user-form/validation`
- `/cabinet/_prototype/admin-user-form/success`
- `/cabinet/_prototype/admin-user-form/long`
- `/cabinet/_prototype/admin-user-form/disabled`
- `/cabinet/_prototype/admin-user-form/permission`

## 21. Документы психолога

View: `application/resources/views/admin/users/documents.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/admin-documents/normal`
- `/cabinet/_prototype/admin-documents/empty`
- `/cabinet/_prototype/admin-documents/validation`
- `/cabinet/_prototype/admin-documents/error`
- `/cabinet/_prototype/admin-documents/confirmation`
- `/cabinet/_prototype/admin-documents/long`
- `/cabinet/_prototype/admin-documents/success`
- `/cabinet/_prototype/admin-documents/permission`

## 22. Группы

View: `application/resources/views/admin/groups/index.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/admin-groups/normal`
- `/cabinet/_prototype/admin-groups/awaiting_payment`
- `/cabinet/_prototype/admin-groups/draft`
- `/cabinet/_prototype/admin-groups/moderation`
- `/cabinet/_prototype/admin-groups/revision`
- `/cabinet/_prototype/admin-groups/rejected`
- `/cabinet/_prototype/admin-groups/approved`
- `/cabinet/_prototype/admin-groups/active`
- `/cabinet/_prototype/admin-groups/warning`
- `/cabinet/_prototype/admin-groups/expired`
- `/cabinet/_prototype/admin-groups/outside-window`
- `/cabinet/_prototype/admin-groups/disabled`
- `/cabinet/_prototype/admin-groups/abandoned`
- `/cabinet/_prototype/admin-groups/free`
- `/cabinet/_prototype/admin-groups/paid`
- `/cabinet/_prototype/admin-groups/successful-payment`
- `/cabinet/_prototype/admin-groups/empty`
- `/cabinet/_prototype/admin-groups/no-results`
- `/cabinet/_prototype/admin-groups/long`
- `/cabinet/_prototype/admin-groups/pagination`
- `/cabinet/_prototype/admin-groups/permission`
- `/cabinet/_prototype/admin-groups/success`

## 23. Модерация группы

View: `application/resources/views/admin/groups/show.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/admin-group/awaiting_payment`
- `/cabinet/_prototype/admin-group/draft`
- `/cabinet/_prototype/admin-group/moderation`
- `/cabinet/_prototype/admin-group/revision`
- `/cabinet/_prototype/admin-group/rejected`
- `/cabinet/_prototype/admin-group/approved`
- `/cabinet/_prototype/admin-group/active`
- `/cabinet/_prototype/admin-group/warning`
- `/cabinet/_prototype/admin-group/expired`
- `/cabinet/_prototype/admin-group/outside-window`
- `/cabinet/_prototype/admin-group/disabled`
- `/cabinet/_prototype/admin-group/validation`
- `/cabinet/_prototype/admin-group/confirmation`
- `/cabinet/_prototype/admin-group/paid-rejected`
- `/cabinet/_prototype/admin-group/paid-delete-blocked`
- `/cabinet/_prototype/admin-group/long`
- `/cabinet/_prototype/admin-group/permission`
- `/cabinet/_prototype/admin-group/success`

## 24. Создание и редактирование группы

View: `application/resources/views/admin/groups/form.blade.php`.

Компоненты: panel, input/textarea/select, label, validation-error/summary, button; настройки также используют confirmation. Форма занимает всю доступную ширину; колонки складываются на телефоне. Сохранение и отправка не выполняются.

- `/cabinet/_prototype/admin-group-form/create`
- `/cabinet/_prototype/admin-group-form/edit`
- `/cabinet/_prototype/admin-group-form/validation`
- `/cabinet/_prototype/admin-group-form/success`
- `/cabinet/_prototype/admin-group-form/long`
- `/cabinet/_prototype/admin-group-form/disabled`
- `/cabinet/_prototype/admin-group-form/permission`

## 25. Все заявки

View: `application/resources/views/admin/applications/index.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/admin-applications/normal`
- `/cabinet/_prototype/admin-applications/empty`
- `/cabinet/_prototype/admin-applications/no-results`
- `/cabinet/_prototype/admin-applications/new`
- `/cabinet/_prototype/admin-applications/processed`
- `/cabinet/_prototype/admin-applications/long`
- `/cabinet/_prototype/admin-applications/pagination`
- `/cabinet/_prototype/admin-applications/permission`

## 26. Данные заявки

View: `application/resources/views/admin/applications/show.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/admin-application/new`
- `/cabinet/_prototype/admin-application/processed`
- `/cabinet/_prototype/admin-application/long`
- `/cabinet/_prototype/admin-application/permission`

## 27. Платежи

View: `application/resources/views/admin/payments/index.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/admin-payments/normal`
- `/cabinet/_prototype/admin-payments/pre-webpay`
- `/cabinet/_prototype/admin-payments/empty`
- `/cabinet/_prototype/admin-payments/no-results`
- `/cabinet/_prototype/admin-payments/created`
- `/cabinet/_prototype/admin-payments/pending`
- `/cabinet/_prototype/admin-payments/manual-review`
- `/cabinet/_prototype/admin-payments/succeeded`
- `/cabinet/_prototype/admin-payments/failed`
- `/cabinet/_prototype/admin-payments/cancelled`
- `/cabinet/_prototype/admin-payments/refunded`
- `/cabinet/_prototype/admin-payments/long`
- `/cabinet/_prototype/admin-payments/pagination`
- `/cabinet/_prototype/admin-payments/permission`

## 28. Данные платежа

View: `application/resources/views/admin/payments/show.blade.php`.

Компоненты: panel, alert/status, date/money, button; опасные действия используют confirmation. Блоки деталей переходят в одну колонку, длинные значения переносятся. Бизнес-действия не выполняются.

- `/cabinet/_prototype/admin-payment/created`
- `/cabinet/_prototype/admin-payment/pending`
- `/cabinet/_prototype/admin-payment/manual-review`
- `/cabinet/_prototype/admin-payment/succeeded`
- `/cabinet/_prototype/admin-payment/failed`
- `/cabinet/_prototype/admin-payment/cancelled`
- `/cabinet/_prototype/admin-payment/refunded`
- `/cabinet/_prototype/admin-payment/validation`
- `/cabinet/_prototype/admin-payment/confirmation`
- `/cabinet/_prototype/admin-payment/long`
- `/cabinet/_prototype/admin-payment/permission`
- `/cabinet/_prototype/admin-payment/success`

## 29. Справочники

View: `application/resources/views/admin/dictionaries/index.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/admin-dictionaries/normal`
- `/cabinet/_prototype/admin-dictionaries/empty`
- `/cabinet/_prototype/admin-dictionaries/edit`
- `/cabinet/_prototype/admin-dictionaries/validation`
- `/cabinet/_prototype/admin-dictionaries/success`
- `/cabinet/_prototype/admin-dictionaries/long`
- `/cabinet/_prototype/admin-dictionaries/permission`
- `/cabinet/_prototype/admin-dictionaries/pagination`

## 30. Элементы справочника

View: `application/resources/views/admin/dictionaries/items.blade.php`.

Компоненты: panel, table/cell или карточки групп, empty, pagination, button; формы используют общие поля. На телефоне строки превращаются в подписанные карточки, действия переносятся. Фильтрация/CRUD показаны визуально; варианты открываются по ссылкам каталога.

- `/cabinet/_prototype/admin-dictionary/normal`
- `/cabinet/_prototype/admin-dictionary/empty`
- `/cabinet/_prototype/admin-dictionary/edit`
- `/cabinet/_prototype/admin-dictionary/used`
- `/cabinet/_prototype/admin-dictionary/deactivated`
- `/cabinet/_prototype/admin-dictionary/validation`
- `/cabinet/_prototype/admin-dictionary/confirmation`
- `/cabinet/_prototype/admin-dictionary/success`
- `/cabinet/_prototype/admin-dictionary/long`
- `/cabinet/_prototype/admin-dictionary/permission`
- `/cabinet/_prototype/admin-dictionary/pagination`

## 31. Настройки

View: `application/resources/views/admin/settings/index.blade.php`.

Компоненты: panel, input/textarea/select, label, validation-error/summary, button; настройки также используют confirmation. Форма занимает всю доступную ширину; колонки складываются на телефоне. Сохранение и отправка не выполняются.

- `/cabinet/_prototype/admin-settings/normal`
- `/cabinet/_prototype/admin-settings/validation`
- `/cabinet/_prototype/admin-settings/success`
- `/cabinet/_prototype/admin-settings/confirmation`
- `/cabinet/_prototype/admin-settings/permission`


## Реальные маршруты Stage 4

`/cabinet/login` использует тот же `auth/login` с POST, CSRF и серверными
ошибками. `/cabinet/` использует `psychologist/groups/index`; в Stage 7 подключены реальные
группы и создание черновика. `/cabinet/admin` использует `admin/home` без
демонстрационных счётчиков: рабочая очередь пока недоступна. Общая навигация
получает реальные ссылки и форму POST `/cabinet/logout`. Структура, CSS и
все 249 вариантов каталога сохранены; прототипы остаются no-op.


## Подключение Stage 5 к реальным данным

Администратору доступны `/cabinet/admin/psychologists`, `/create`, `/{id}`,
`/{id}/edit` и `/{id}/documents`. Они используют те же `admin/users/*` и общие
Blade-компоненты, что и прототипы разделов 18–21. Реальные формы сохраняют
данные, показывают ошибки и старый ввод; модальные подтверждения отправляют
CSRF-защищённые действия. Поиск и фильтры сохраняются при пагинации.

Тариф выбирается в форме создания; у существующего психолога статус, тариф,
доступ и удаление меняются отдельными действиями карточки. Документы имеют
реальные защищённые ссылки просмотра/скачивания и отдельное подтверждение
удаления каждого файла. Карточка показывает число документов/групп и аудит.
В Stage 7 добавлены реальные маршруты групп; повторная отправка пароля отсутствует.
Навигация администратора: Главная, Психологи, Группы, Выход. Все 249 прототипных
вариантов сохраняют синтетические данные и no-op бизнес-действия.

## Подключение Stage 6 к реальным данным

`/cabinet/profile` использует утверждённый `psychologist/profile/show` и общие
`shared/profile-data` / `shared/documents`. Анкета и документы принадлежат
текущему пользователю; форма редактирования и действия загрузки/удаления
отсутствуют. Просмотр и скачивание ведут на защищённые маршруты
`/cabinet/profile/documents/{id}/view` и `/download`.

Реальные страницы «Мои группы» и «Мои данные» используют общую навигацию с
активным пунктом и POST-выходом. С Stage 7 главная показывает собственные группы из БД. Общая таблица документов принимает ссылки действий
явно: удаление доступно только в административном режиме. CSS, структура
страницы, мобильные карточки и 31 группа / 249 вариантов прототипов сохранены.

## Подключение Stage 7 к реальным данным

Те же psychologist/groups/index, form, show и admin/groups/index, form, show
используют реальные группы, общие group-form/data/summary/history/delete и
подтверждения. CSS и структура страниц сохранены; каталог 31/249 не меняется.

- `/cabinet/`: собственные группы, 20 на страницу; «Добавить группу» отправляет
  POST `/cabinet/groups` и открывает `/cabinet/groups/{id}/edit`.
- `/cabinet/groups/{id}`: данные, текущий комментарий/причина, история,
  разрешённые действия; с этапа 10 — реальные счётчики и ссылки на заявки.
- `/cabinet/groups/{id}/edit`: старый ввод/ошибки, реальные справочники,
  отдельные PUT-сохранение и POST `/{id}/submit`. Нет редактируемого UUID.
- `/cabinet/admin/groups`: поиск, тариф/статус, сортировка, быстрые фильтры
  approved/abandoned (с этапа 9 также expired); нет фильтра успешного платежа.
- `/cabinet/admin/groups/create` и `/{id}/edit`: общий group-form; психолог
  выбирается только при создании. Опубликованные изменения синхронизируются
  с каталогом вручную.
- `/cabinet/admin/groups/{id}`: настоящий психолог и история, UUID с рабочим
  копированием, подтверждения approve/revision/reject/activate по статусу.
  Комментарии связаны с реальными формами подтверждения; ошибки сохраняют ввод.

Удаление подтверждается на карточке; из списка психолог открывает «Подробнее».
Admin удаляет только брошенный draft по техническому порогу 30 дней.
Формы показывают предупреждение, если нет доступных значений справочников.
Нет вымышленных платежей/заявок и активных ссылок на будущие функции.
Все соответствующие prototype-варианты сохраняют прежние synthetic/no-op
сценарии, включая оплату, заявки и продление.

## Подключение Stage 8 к реальным данным

Существующие `admin/dictionaries/index`, `items`, `admin/settings/index` и
`admin/payments/index` подключены к реальным маршрутам. CSS, общий layout,
таблицы/мобильные карточки и каталог 31 группы / 249 вариантов сохранены.

- `/cabinet/admin/dictionaries`: реальные контейнеры, общее и активное число
  элементов, 20 на страницу, форма создания; `/{dictionary}/edit` использует ту же
  страницу с редактированием названия и неизменяемым кодом.
- `/{dictionary}/items`: элементы в порядке sort_order/id, состояние и использование,
  создание, редактирование на `/{item}/edit`, подтверждённые деактивация/удаление,
  повторная активация. Используемые значения и системные контейнеры защищены.
- `/cabinet/admin/settings`: реальные семь значений, nullable цены в BYN,
  серверные ошибки/старый ввод и подтверждённое PUT-сохранение исходной формы.
- `/cabinet/admin/payments`: только информационное состояние до WEBPAY без
  демонстрационных строк, поиска, фильтров, detail/refund и платёжных действий.

Навигация администратора: Главная, Психологи, Группы, Платежи, Справочники,
Настройки, Выход. С этапа 10 также подключены Заявки. Существующие prototype payment
list/detail и остальные варианты остаются synthetic/no-op в local/testing.

## Stage 9 — реальные сроки и продление

GET `/cabinet/groups/{group}/extension` подключает утверждённую страницу продления.
POST по тому же адресу требует CSRF и подтверждения в существующем модальном окне.
Доступны только собственные, не удалённые и не отключённые active/expired группы.
Текущий тариф владельца определяет бесплатность; исторический тариф группы не
выбирает сценарий продления. Платное продление пока показывает информационное
состояние без формы оплаты и эффективного действия.

Реальные списки/карточки показывают оставшиеся дни, предупреждение по текущей
настройке и понятное состояние просроченного active до обработки расписанием.
Expired показывает старую дату и текущий срок доступного продления; после окна
предлагается создание новой группы. Бесплатное active-продление добавляет
сохранённую длительность, expired-продление возвращает в ожидание публикации.
Admin detail определяет повторную публикацию по истории; активация начинает
новый период по текущей настройке. `admin/groups?quick=expired` — реальный
пагинируемый фильтр «Снять с публикации» с напоминанием о ручном действии.
Все 31/249 прототипов и синтетические warning/extension варианты сохранены.

## Stage 10 — реальные заявки участников

Те же четыре application views и общие application-list/detail/counters теперь
работают с реальными данными; каталог 31/249 и no-op прототипы сохранены.

- GET `/cabinet/groups/{group}/applications`: заявки только своей группы,
  счётчики, фильтр all/new/processed, 20 строк на страницу.
- GET `/{application}` внутри этого маршрута: участник, телефон, группа,
  даты получения/обновления/обработки и возврат в список.
- POST `/{application}/processed` и `/unprocessed`: реальные CSRF-защищённые
  действия владельца с идемпотентным результатом.
- GET `/cabinet/admin/applications`: общий список, поиск участника/телефона/
  группы/психолога, фильтр обработки и пагинация с сохранением запроса.
- GET `/cabinet/admin/applications/{application}`: чтение и реальные ссылки
  на группу/анкету психолога; действий обработки у администратора нет.

Список/карточка групп психолога показывают реальные агрегированные счётчики,
активные ссылки на заявки; карточка — последнюю заявку или честное пустое
состояние. Пункт «Заявки» добавлен между «Группы» и «Платежи» в admin navigation.
Навигация психолога остаётся «Мои группы», «Мои данные», «Выход».
CSS/tokens, таблицы и мобильные представления не меняются. Отдельный глобальный
UI/UX-аудит рекомендуется следующей продуктовой задачей до Stage 11 по запросу
владельца продукта.

## Stage 12 — установка первого пароля

Утверждённый `auth/password.blade.php` подключён к GET
`/cabinet/password/setup/{token}?email=...` и POST `/cabinet/password/setup`.
Реальная форма имеет CSRF, серверную валидацию и существующие состояния
недействительной/истёкшей ссылки и успешной установки. Автовхода нет.
На реальной карточке психолога в меню действий доступна отправка ссылки только
для approved/enabled/non-admin/password-null аккаунта. История показывает
повторную отправку. CSS, иерархия действий и 31/249 прототипов сохранены.


## Real WEBPAY wiring

Existing psychologist payments/placement and payments/return now show real
owner-scoped attempts at `/payments/{payment}`, `/return`, `/cancel`; start and
retry are CSRF POST actions. The same placement panel renders the signed
provider form after start. Existing groups/extension shows current tariff,
configured price, unfinished attempt, free extension or paid action. Success
wording distinguishes active extension and expired/republication via the saved
effect. Pending/manual-review never claims a completed payment.

Admin payments/index now has working local filters/search/pagination;
payments/show displays real details, safe notification journal, provider summary
and existing refund confirmation form/modal. Refund wording remains “Отметить
возврат выполненным в WEBPAY”. Admin group/moderation shows placement context
and rejected paid-group manual-refund guidance. Settings no longer claim payment
integration is unavailable. No CSS/layout redesign or psychologist history
section was added; prototype routes retain all demonstration states in local/testing.
