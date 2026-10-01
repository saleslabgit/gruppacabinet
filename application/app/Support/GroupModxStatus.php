<?php

namespace App\Support;

class GroupModxStatus
{
    public static function error(string $code): string
    {
        return match ($code) {
            'resume_unavailable' => 'resume_unavailable: группа больше не допускает возобновление. Проверьте её состояние.',
            'configuration' => 'configuration: администратору необходимо проверить настройки подключения.',
            'authorization' => 'authorization: MODX отклонил доступ. Проверьте настройки доступа.',
            'not_sync_ready' => 'not_sync_ready: проверьте заполнение группы, справочники и обложку.',
            'idempotency_conflict' => 'idempotency_conflict: параметры запроса не совпадают с ранее отправленными. Требуется сверка с MODX; конфликтующий ключ не заменяется автоматически.',
            'resource_id_conflict' => 'resource_id_conflict: MODX вернул другой Resource ID. Требуется сверка с MODX.',
            'resource_not_found' => 'resource_not_found: ресурс не найден на основном сайте.',
            'queue_unavailable' => 'queue_unavailable: очередь недоступна. Повторите после восстановления очереди.',
            'connection', 'rate_limited', 'remote_unavailable', 'in_progress' => $code.': временная ошибка MODX. Повторите синхронизацию.',
            'redirect', 'remote_validation', 'invalid_response' => $code.': ответ MODX не соответствует контракту. Требуется проверка интеграции.',
            'local_failure', 'worker_failed' => $code.': проверьте работу очереди и повторите синхронизацию.',
            default => 'Не удалось выполнить синхронизацию. Проверьте работу очереди и повторите.',
        };
    }
}
