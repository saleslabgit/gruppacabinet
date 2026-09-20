<?php

namespace App\Support;

final class UiStatus
{
    /** @return array{string, string} */
    public static function get(string $domain, string $value): array
    {
        $statuses = ['group' => ['awaiting_payment' => ['warning', 'Ожидает оплаты'], 'draft' => ['neutral', 'Черновик'], 'moderation' => ['info', 'На модерации'], 'revision' => ['warning', 'На доработке'], 'rejected' => ['danger', 'Отклонена'], 'approved' => ['info', 'Одобрена, ожидает публикации'], 'active' => ['success', 'Активная'], 'expired' => ['neutral', 'Закончена']], 'user' => ['pending' => ['warning', 'Анкета ожидает проверки'], 'approved' => ['success', 'Анкета принята'], 'rejected' => ['danger', 'Анкета отклонена']], 'payment' => ['created' => ['neutral', 'Создан'], 'pending' => ['warning', 'Ожидает подтверждения'], 'succeeded' => ['success', 'Оплата подтверждена'], 'failed' => ['danger', 'Оплата не прошла'], 'cancelled' => ['neutral', 'Отмена подтверждена'], 'refunded' => ['info', 'Возврат учтён']], 'access' => ['enabled' => ['success', 'Доступ включён'], 'disabled' => ['danger', 'Доступ отключён']], 'application' => ['new' => ['info', 'Новая'], 'processed' => ['success', 'Обработана']]];

        return $statuses[$domain][$value] ?? throw new \InvalidArgumentException('Unknown visual status');
    }
}
