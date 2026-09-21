<div class="actions mb-3">
<x-status :value="$group['status']" />
@if($group['disabled'])
<x-status domain="access" value="disabled" />
@endif
<span class="meta">{{ $group['free'] ? 'Бесплатное размещение' : 'Платное размещение' }}</span>
</div>
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
@if($group['warning'])
<x-alert tone="warning">До окончания размещения 3 дня</x-alert>
@endif
@if($group['outside_window'])
<x-alert tone="warning">Срок продления закончился. Создайте новую группу.</x-alert>
@endif
<dl class="detail-grid">
<div>
<dt>Создана</dt>
<dd>
<x-date :value="$group['created_at']" />
</dd>
</div>
<div>
<dt>Формат</dt>
<dd>{{ $group['format'] }}</dd>
</div>
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

<p class="meta mt-3">{{ match($group['status']) {
    'awaiting_payment' => ($realGroups ?? false) ? 'Историческая запись. Действия пока недоступны.' : 'Заполнение анкеты станет доступно после подтверждения оплаты.',
    'draft' => 'Заполните анкету и отправьте группу на модерацию.',
    'moderation' => 'Администратор проверяет группу. Дождитесь решения.',
    'revision' => 'Внесите исправления по замечаниям и отправьте группу повторно.',
    'rejected' => 'Группа не будет опубликована. Причина указана выше.',
    'approved' => 'Администратор опубликует группу вручную; с этого момента начнётся срок размещения.',
    'active' => ($realGroups ?? false) ? 'Группа опубликована.' : 'Группа опубликована и принимает заявки.',
    'expired' => ($realGroups ?? false) ? 'Размещение завершено.' : 'Размещение завершено. После продления потребуется ручная повторная публикация.',
} }}</p>
