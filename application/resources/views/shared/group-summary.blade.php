@if(($admin ?? false) && !empty($group['psychologist_deleted_at']))<x-alert tone="warning">Психолог удалил группу</x-alert>@endif
<div class="actions mb-3">
<x-status :value="$group['status']" />
@if($group['disabled'])
<x-status domain="access" value="disabled" />
@endif
<x-tariff :free="$group['free']" suffix="размещение" />
</div>
@yield('group-actions')
@if($group['status'] === 'revision')
<x-alert tone="warning" title="Комментарий администратора">
<p>{{ $group['moderator_comment'] }}</p>
</x-alert>
@endif
@if($group['status'] === 'rejected')
<x-alert tone="danger" title="Причина отклонения">
<p>{{ $group['rejection_reason'] }}</p>
</x-alert>
@endif
@if($realGroups ?? false)
@if($group['expiry_due'])
<x-alert tone="warning">Срок размещения истёк. Ожидается обновление статуса.</x-alert>
@elseif($group['remaining_days'] !== null)
@if($group['warning'])
<x-alert tone="warning">До окончания размещения: {{ $group['remaining_days'] }} дн.</x-alert>
@else
<p class="meta">До окончания размещения: {{ $group['remaining_days'] }} дн.</p>
@endif
@endif
@if($group['status'] === 'expired' && !$group['outside_window'] && $group['extension_deadline'])
<p>Продление доступно до: <x-date :value="$group['extension_deadline']" /></p>
@endif
@elseif($group['warning'])
<x-alert tone="warning">До окончания размещения 3 дня</x-alert>
@endif
@if($group['outside_window'])
<x-alert tone="warning">Срок продления закончился. Создайте новую группу.</x-alert>
@endif
@if(($group['modx_publication_desired'] ?? null) && in_array($group['status'], ['paused', 'expired']))
<p role="status">{{ match($group['modx_publication_status'] ?? null) {
    'pending', 'syncing' => $group['modx_publication_desired'] === 'published' ? 'Возобновление публикации выполняется. До подтверждения группа остаётся на паузе.' : 'Снятие с публикации выполняется.',
    'published' => 'Публикация подтверждена.',
    'unpublished' => 'Группа снята с публикации.',
    'failed' => 'Не удалось изменить публикацию. '.($group['modx_publication_desired'] === 'published' ? 'Можно повторить возобновление.' : 'Обратитесь к администратору.'),
    'conflict' => 'Требуется проверка публикации администратором.',
    default => 'Состояние публикации ещё не подтверждено.',
} }}</p>
@endif
<dl class="detail-grid">
<div>
<dt>Создана</dt>
<dd>
<x-date :value="$group['created_at']" />
</dd>
</div>
@unless($preview ?? false)
<div>
<dt>Формат</dt>
<dd>{{ $group['format'] }}</dd>
</div>
@endunless
<div>
<dt>Опубликована</dt>
<dd>
<x-date :value="$group['published_at']" />
</dd>
</div>
<div>
<dt>Размещение до</dt>
<dd>
<x-date :value="$group['expires_at']" />
</dd>
</div>
</dl>

<p class="group-state-summary mt-3">{{ match($group['status']) {
    'awaiting_payment' => ($realGroups ?? false) ? 'Ожидается оплата размещения. Заполнение анкеты группы станет доступно после доверенного подтверждения оплаты.' : 'Заполнение анкеты станет доступно после подтверждения оплаты.',
    'draft' => 'Заполните анкету и отправьте группу на модерацию.',
    'moderation' => ($admin ?? false) ? 'Группа ожидает вашего решения.' : 'Администратор проверяет группу. Дождитесь решения.',
    'revision' => 'Внесите исправления по замечаниям и отправьте группу повторно.',
    'rejected' => 'Группа не будет опубликована. Причина указана выше.',
    'approved' => ($republication ?? false) ? 'Продление принято. Новый срок начнётся только после подтверждения повторной публикации.' : 'Группа одобрена и ожидает публикации. Срок размещения начнётся после публикации.',
    'active' => $group['disabled'] ? 'Группа снята с размещения администратором. Заявки не принимаются. Срок размещения продолжает идти.' : (($realGroups ?? false) ? 'Группа опубликована.' : 'Группа опубликована и принимает заявки.'),
    'paused' => 'Группа на паузе. Заявки не принимаются. Срок размещения продолжает идти до указанной даты окончания.',
    'expired' => ($realGroups ?? false) ? 'Размещение завершено.' : 'Размещение завершено. После продления начнётся повторная публикация.',
} }}</p>
