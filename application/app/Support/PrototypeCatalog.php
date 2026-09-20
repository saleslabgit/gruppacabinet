<?php

namespace App\Support;

final class PrototypeCatalog
{
    /** @return array<string, array{title: string, view: string, variants: list<string>}> */
    public static function pages(): array
    {
        return [
            'login' => ['title' => 'Вход', 'view' => 'auth.login', 'variants' => ['normal', 'validation', 'error', 'rate-limit', 'disabled']],
            'password' => ['title' => 'Установка пароля', 'view' => 'auth.password', 'variants' => ['normal', 'validation', 'expired', 'invalid', 'success', 'long']],
            'errors' => ['title' => 'Системные ошибки', 'view' => 'errors.403', 'variants' => ['403', '404', '419', '429', '500']],
            'notices' => ['title' => 'Уведомления и подтверждения', 'view' => 'shared.notices', 'variants' => ['success', 'validation', 'warning', 'danger', 'confirmation', 'long']],
            'groups-empty' => ['title' => 'Мои группы — пустой список', 'view' => 'psychologist.groups.index', 'variants' => ['empty']],
            'groups' => ['title' => 'Мои группы', 'view' => 'psychologist.groups.index', 'variants' => ['normal', 'awaiting_payment', 'draft', 'moderation', 'revision', 'rejected', 'approved', 'active', 'warning', 'expired', 'outside-window', 'disabled', 'long', 'success', 'permission', 'confirmation', 'pagination']],
            'group-form' => ['title' => 'Создание и редактирование группы', 'view' => 'psychologist.groups.form', 'variants' => ['create', 'draft', 'revision', 'validation', 'success', 'long', 'disabled', 'permission']],
            'group' => ['title' => 'Просмотр группы', 'view' => 'psychologist.groups.show', 'variants' => ['awaiting_payment', 'draft', 'moderation', 'revision', 'rejected', 'approved', 'active', 'warning', 'expired', 'outside-window', 'disabled', 'long', 'permission', 'confirmation', 'paid-delete-blocked']],
            'placement' => ['title' => 'Оплата размещения', 'view' => 'psychologist.payments.placement', 'variants' => ['normal', 'retry', 'disabled', 'long']],
            'payment-pending' => ['title' => 'Подтверждение оплаты', 'view' => 'psychologist.payments.return', 'variants' => ['pending', 'long']],
            'payment-success' => ['title' => 'Оплата подтверждена', 'view' => 'psychologist.payments.return', 'variants' => ['succeeded', 'extension-active', 'extension-expired']],
            'payment-result' => ['title' => 'Результат попытки оплаты', 'view' => 'psychologist.payments.return', 'variants' => ['failed', 'cancelled', 'browser-cancel', 'undetermined']],
            'extension' => ['title' => 'Продление размещения', 'view' => 'psychologist.groups.extension', 'variants' => ['free-active', 'free-expired', 'paid-active', 'paid-expired', 'outside-window', 'pending']],
            'applications' => ['title' => 'Заявки группы', 'view' => 'psychologist.applications.index', 'variants' => ['normal', 'empty', 'new', 'processed', 'long', 'success', 'pagination', 'permission']],
            'application' => ['title' => 'Просмотр заявки', 'view' => 'psychologist.applications.show', 'variants' => ['new', 'processed', 'long', 'permission']],
            'profile' => ['title' => 'Мои данные', 'view' => 'psychologist.profile.show', 'variants' => ['normal', 'long', 'no-documents', 'permission']],
            'admin-home' => ['title' => 'Рабочая сводка', 'view' => 'admin.home', 'variants' => ['normal', 'empty', 'long', 'permission']],
            'admin-users' => ['title' => 'Психологи', 'view' => 'admin.users.index', 'variants' => ['normal', 'pending', 'approved', 'rejected', 'disabled', 'free', 'paid', 'empty', 'no-results', 'long', 'pagination', 'permission', 'success']],
            'admin-user' => ['title' => 'Просмотр психолога', 'view' => 'admin.users.show', 'variants' => ['pending', 'approved', 'rejected', 'disabled', 'free', 'paid', 'confirmation', 'long', 'permission', 'success']],
            'admin-user-form' => ['title' => 'Создание и редактирование психолога', 'view' => 'admin.users.form', 'variants' => ['create', 'edit', 'validation', 'success', 'long', 'disabled', 'permission']],
            'admin-documents' => ['title' => 'Документы психолога', 'view' => 'admin.users.documents', 'variants' => ['normal', 'empty', 'validation', 'error', 'confirmation', 'long', 'success', 'permission']],
            'admin-groups' => ['title' => 'Группы', 'view' => 'admin.groups.index', 'variants' => ['normal', 'awaiting_payment', 'draft', 'moderation', 'revision', 'rejected', 'approved', 'active', 'warning', 'expired', 'outside-window', 'disabled', 'abandoned', 'free', 'paid', 'successful-payment', 'empty', 'no-results', 'long', 'pagination', 'permission', 'success']],
            'admin-group' => ['title' => 'Модерация группы', 'view' => 'admin.groups.show', 'variants' => ['awaiting_payment', 'draft', 'moderation', 'revision', 'rejected', 'approved', 'active', 'warning', 'expired', 'outside-window', 'disabled', 'validation', 'confirmation', 'paid-rejected', 'paid-delete-blocked', 'long', 'permission', 'success']],
            'admin-group-form' => ['title' => 'Создание и редактирование группы', 'view' => 'admin.groups.form', 'variants' => ['create', 'edit', 'validation', 'success', 'long', 'disabled', 'permission']],
            'admin-applications' => ['title' => 'Все заявки', 'view' => 'admin.applications.index', 'variants' => ['normal', 'empty', 'no-results', 'new', 'processed', 'long', 'pagination', 'permission']],
            'admin-application' => ['title' => 'Данные заявки', 'view' => 'admin.applications.show', 'variants' => ['new', 'processed', 'long', 'permission']],
            'admin-payments' => ['title' => 'Платежи', 'view' => 'admin.payments.index', 'variants' => ['normal', 'pre-webpay', 'empty', 'no-results', 'created', 'pending', 'manual-review', 'succeeded', 'failed', 'cancelled', 'refunded', 'long', 'pagination', 'permission']],
            'admin-payment' => ['title' => 'Данные платежа', 'view' => 'admin.payments.show', 'variants' => ['created', 'pending', 'manual-review', 'succeeded', 'failed', 'cancelled', 'refunded', 'validation', 'confirmation', 'long', 'permission', 'success']],
            'admin-dictionaries' => ['title' => 'Справочники', 'view' => 'admin.dictionaries.index', 'variants' => ['normal', 'empty', 'edit', 'validation', 'success', 'long', 'permission', 'pagination']],
            'admin-dictionary' => ['title' => 'Элементы справочника', 'view' => 'admin.dictionaries.items', 'variants' => ['normal', 'empty', 'edit', 'used', 'deactivated', 'validation', 'confirmation', 'success', 'long', 'permission', 'pagination']],
            'admin-settings' => ['title' => 'Настройки', 'view' => 'admin.settings.index', 'variants' => ['normal', 'validation', 'success', 'confirmation', 'permission']],
        ];
    }
}
