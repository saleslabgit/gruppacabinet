<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class PrototypeFixtures
{
    /** @return array<string, mixed> */
    public static function catalog(): array
    {
        return ['title' => 'Каталог прототипов', 'prototype' => true, 'pages' => PrototypeCatalog::pages(), 'links' => self::links()];
    }

    /** @return array<string, string> */
    private static function links(): array
    {
        $links = ['catalog' => route('prototype.index')];
        foreach (PrototypeCatalog::pages() as $slug => $page) {
            $links[$slug] = route('prototype.'.$slug);
        }

        return $links;
    }

    /** @return array<string, mixed> */
    public static function page(string $slug, string $variant): array
    {
        $links = self::links();
        $admin = str_starts_with($slug, 'admin-');
        $long = $variant === 'long';
        $date = CarbonImmutable::parse('2026-09-20 09:00:00', 'UTC');
        $groupStatuses = ['awaiting_payment', 'draft', 'moderation', 'revision', 'rejected', 'approved', 'active', 'expired'];
        $status = in_array($variant, $groupStatuses, true) ? $variant : 'active';
        if (in_array($variant, ['outside-window', 'free-expired', 'paid-expired'], true)) {
            $status = 'expired';
        }
        if (in_array($slug, ['group-form', 'admin-group-form'], true) && ! in_array($variant, ['revision'], true)) {
            $status = 'draft';
        }
        if ($long && in_array($slug, ['groups', 'group', 'admin-groups', 'admin-group'], true)) {
            $status = 'revision';
        }
        if ($variant === 'abandoned') {
            $status = 'draft';
        }
        if ($variant === 'paid-rejected') {
            $status = 'rejected';
        }
        if ($variant === 'validation' && $slug === 'admin-group') {
            $status = 'moderation';
        }
        if (in_array($variant, ['confirmation', 'paid-delete-blocked'], true)) {
            $status = 'draft';
        }
        $group = [
            'id' => 101, 'title' => $long ? str_repeat('Демонстрационная группа поддержки и бережного общения ', 5) : 'Быть собой: группа поддержки',
            'description' => $long ? str_repeat('Вымышленное описание: учимся замечать свои чувства и строить отношения. ', 15) : 'Безопасное пространство, чтобы лучше понимать себя и строить близкие отношения. Встречаемся в небольшой группе, обсуждаем важное и поддерживаем друг друга.',
            'schedule' => 'По средам, 19:00–21:00 (Минск)', 'format' => 'Очно · пример справочника', 'format_id' => 'demo',
            'meeting_duration_minutes' => 120, 'participant_capacity' => 8, 'gender_id' => 'demo', 'gender' => 'Любой · пример справочника', 'meeting_price' => 3500,
            'status' => $status, 'disabled' => $variant === 'disabled', 'free' => ! in_array($variant, ['awaiting_payment', 'paid-rejected', 'paid-delete-blocked', 'paid', 'successful-payment'], true),
            'public_uuid' => '11111111-2222-4333-8444-555555555555',
            'has_unrefunded_payment' => in_array($variant, ['paid-rejected', 'paid-delete-blocked', 'paid', 'successful-payment'], true),
            'created_at' => $date->subDays(80), 'published_at' => $status === 'active' ? $date->subDays($variant === 'warning' ? 27 : 15) : ($status === 'expired' ? $date->subDays($variant === 'outside-window' ? 70 : 35) : null),
            'expires_at' => $status === 'active' ? $date->addDays($variant === 'warning' ? 3 : 15) : ($status === 'expired' ? $date->subDays($variant === 'outside-window' ? 40 : 5) : null),
            'warning' => $variant === 'warning', 'outside_window' => $variant === 'outside-window',
            'moderator_comment' => 'Уточните расписание и опишите, кому подходит группа.'.($long ? str_repeat(' Добавьте подробности о формате встреч.', 12) : ''),
            'rejection_reason' => 'Описание группы не соответствует условиям размещения.',
            'new_count' => in_array($status, ['active', 'expired'], true) ? 2 : 0, 'processed_count' => in_array($status, ['active', 'expired'], true) ? 3 : 0, 'all_count' => in_array($status, ['active', 'expired'], true) ? 5 : 0,
        ];
        if ($variant === 'empty' && in_array($slug, ['applications', 'admin-applications'], true)) {
            $group['new_count'] = $group['processed_count'] = $group['all_count'] = 0;
        }
        $groups = [$group];
        if ($variant === 'normal' && in_array($slug, ['groups', 'admin-groups'], true)) {
            $groups = [];
            foreach ($groupStatuses as $index => $itemStatus) {
                $item = $group;
                $item['status'] = $itemStatus;
                $item['id'] += $index;
                $item['free'] = $itemStatus !== 'awaiting_payment';
                $item['new_count'] = in_array($itemStatus, ['active', 'expired'], true) ? 2 : 0;
                $item['processed_count'] = in_array($itemStatus, ['active', 'expired'], true) ? 3 : 0;
                $item['all_count'] = $item['new_count'] + $item['processed_count'];
                $item['published_at'] = in_array($itemStatus, ['active', 'expired'], true) ? $date->subDays(31) : null;
                $item['expires_at'] = $itemStatus === 'active' ? $date->addDays(3) : ($itemStatus === 'expired' ? $date->subDay() : null);
                $groups[] = $item;
            }
        }
        $user = [
            'last_name' => $long ? str_repeat('Демонстрационная-', 8).'Фамилия' : 'Примерова', 'first_name' => 'Анна', 'middle_name' => 'Ивановна',
            'email' => $long ? str_repeat('demo-', 16).'@example.test' : 'anna@example.test', 'phone' => '+375 (00) 000-00-00',
            'education_type_id' => 'demo', 'education_type' => 'Психологическое · пример справочника', 'other_education' => 'Дополнительная программа · пример',
            'modality_program' => 'Групповая работа · демонстрационная программа', 'training_center' => 'Учебный центр «Пример»',
            'graduation_year' => 2020, 'training_hours' => 600, 'license_number' => 'DEMO-LICENSE-000', 'license_expires_at' => '2027-12-31',
            'group_leading_experience' => 'Ведение групп поддержки · вымышленные данные', 'groups_conducted_count' => 12,
            'documents_confirmed' => true, 'education_confirmed' => true, 'live_session_ready' => false,
            'personal_data_consent_at' => $date->subDays(100), 'personal_data_consent_version' => 'DEMO-v1',
            'status' => in_array($variant, ['pending', 'approved', 'rejected'], true) ? $variant : 'approved',
            'free' => $variant !== 'paid', 'disabled' => $variant === 'disabled', 'created_at' => $date->subDays(100),
        ];
        if ($variant === 'pagination') {
            $group['id'] = 201;
            $group['title'] = 'Вторая страница: демонстрационная группа';
            $groups = [$group];
            $user['last_name'] = 'Образцова';
        }
        $user['name'] = $user['last_name'].' '.$user['first_name'].' '.$user['middle_name'];
        $application = ['name' => $variant === 'pagination' ? 'Примеров Михаил' : ($long ? str_repeat('Демонстрационный участник ', 8) : 'Тестов Алексей'), 'phone' => '+375 (00) 000-00-01', 'created_at' => $date->subDay(), 'updated_at' => $date, 'processed_at' => $variant === 'processed' ? $date : null];
        $paymentStatus = in_array($variant, ['created', 'pending', 'succeeded', 'failed', 'cancelled', 'refunded'], true) ? $variant : 'succeeded';
        if (in_array($variant, ['manual-review', 'browser-cancel', 'undetermined', 'long'], true)) {
            $paymentStatus = 'pending';
        }
        if ($status === 'awaiting_payment' && in_array($slug, ['admin-group', 'group', 'groups', 'admin-groups'], true)) {
            $paymentStatus = 'created';
        }
        $payment = ['id' => $variant === 'pagination' ? 502 : 501, 'order_number' => ($variant === 'pagination' ? 'DEMO-ORDER-20260920-0002' : 'DEMO-ORDER-20260920-0001').($long ? str_repeat('-DEMO', 10) : ''), 'transaction_id' => in_array($paymentStatus, ['created', 'pending'], true) ? null : 'DEMO-TRANSACTION-0001', 'amount' => 5000, 'currency' => 'BYN', 'type' => str_starts_with($variant, 'extension') ? 'extension' : 'placement', 'status' => $paymentStatus, 'created_at' => $date->subHour(), 'paid_at' => in_array($paymentStatus, ['succeeded', 'refunded'], true) ? $date : null, 'refunded_at' => $paymentStatus === 'refunded' ? $date->addHour() : null, 'last_status_check_at' => $paymentStatus === 'created' ? null : $date, 'status_check_attempts' => $variant === 'manual-review' ? 3 : 1, 'refund_comment' => 'Демонстрационная отметка ручного возврата.', 'manual_review' => $variant === 'manual-review'];
        $navigation = [];
        $nav = $admin ? ['admin-home' => 'Рабочая сводка', 'admin-users' => 'Психологи', 'admin-groups' => 'Группы', 'admin-applications' => 'Заявки', 'admin-payments' => 'Платежи', 'admin-dictionaries' => 'Справочники', 'admin-settings' => 'Настройки'] : ['groups' => 'Мои группы', 'profile' => 'Мои данные'];
        $section = match ($slug) {
            'groups-empty', 'group', 'group-form', 'extension', 'placement', 'payment-pending', 'payment-success', 'payment-result', 'applications', 'application' => 'groups',
            'admin-user', 'admin-user-form', 'admin-documents' => 'admin-users',
            'admin-group', 'admin-group-form' => 'admin-groups',
            'admin-application' => 'admin-applications',
            'admin-payment' => 'admin-payments',
            'admin-dictionary' => 'admin-dictionaries',
            default => $slug,
        };
        foreach ($nav as $key => $label) {
            $navigation[] = ['label' => $label, 'url' => $links[$key], 'current' => $section === $key];
        }
        $errors = $variant === 'validation' ? ['title' => 'Укажите название группы.', 'description' => 'Добавьте описание.', 'schedule' => 'Укажите расписание.', 'format_id' => 'Выберите формат.', 'meeting_duration_minutes' => 'Введите положительное целое число минут.', 'participant_capacity' => 'Введите положительное целое число участников.', 'gender_id' => 'Выберите значение.', 'meeting_price' => 'Укажите неотрицательную стоимость.', 'email' => 'Укажите корректный email.', 'password' => 'Пароли должны совпадать и соответствовать требованиям.', 'password_confirmation' => 'Подтверждение не совпадает.', 'file' => 'Выберите PDF, JPEG или PNG допустимого размера.', 'moderator_comment' => 'Комментарий обязателен.', 'rejection_reason' => 'Причина обязательна.', 'refund_comment' => 'Добавьте комментарий к возврату.', 'code' => 'Укажите уникальный код.', 'name' => 'Укажите название.', 'sort_order' => 'Введите целое неотрицательное число.', 'graduation_year' => 'Укажите корректный год окончания.', 'training_hours' => 'Введите целое неотрицательное число часов.', 'license_expires_at' => 'Укажите корректную дату.', 'education_type_id' => 'Выберите тип образования.', 'documents_confirmed' => 'Проверьте подтверждение достоверности документов.', 'placement_price' => 'Введите неотрицательную сумму.', 'warning_days' => 'Срок предупреждения должен быть меньше срока размещения.'] : [];
        if ($variant === 'validation' && $slug === 'login') {
            $errors['password'] = 'Введите пароль.';
        }
        $data = compact('slug', 'variant', 'links', 'admin', 'long', 'date', 'group', 'groups', 'user', 'application', 'payment', 'navigation', 'errors');
        $data += ['title' => PrototypeCatalog::pages()[$slug]['title'], 'prototype' => true, 'empty' => in_array($variant, ['empty', 'no-results', 'pre-webpay'], true), 'pages' => [1 => route('prototype.'.$slug), 2 => route('prototype.'.$slug, ['variant' => in_array('pagination', PrototypeCatalog::pages()[$slug]['variants'], true) ? 'pagination' : PrototypeCatalog::pages()[$slug]['variants'][0]])], 'currentPage' => $variant === 'pagination' ? 2 : 1];
        if ($variant === 'success' && ! in_array($slug, ['password', 'notices'], true)) {
            $data['notice'] = ['tone' => 'success', 'text' => 'Изменения сохранены. Демонстрационное состояние.'];
        }

        return $data;
    }
}
