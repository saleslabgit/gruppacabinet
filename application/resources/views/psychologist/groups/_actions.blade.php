@if($realGroups ?? false)
<div class="actions">
@if(!$group['disabled'] && in_array($group['status'], ['draft', 'revision']))
<x-button :href="route('psychologist.groups.edit', $group['id'])">{{ $group['status'] === 'revision' ? 'Исправить и отправить' : 'Заполнить группу' }}</x-button>
@endif
<x-button kind="ghost" :href="route('psychologist.groups.show', $group['id'])">Подробнее</x-button>
@if($canDelete ?? false)
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#delete-group">Удалить</x-button>
@endif
</div>
@else
<div class="actions">
@if($group['disabled'])
<x-button :disabled="true">Действия недоступны</x-button>
@elseif($group['status'] === 'awaiting_payment')
<x-button :href="$links['placement']">Оплатить размещение</x-button>
@elseif(in_array($group['status'], ['draft','revision']))
<x-button :href="route('prototype.group-form', ['variant' => $group['status']])">{{ $group['status'] === 'revision' ? 'Исправить и отправить' : 'Заполнить группу' }}</x-button>
@elseif(in_array($group['status'], ['active','expired']) && !$group['outside_window'])
<x-button :href="route('prototype.extension', ['variant' => ($user['free'] ? 'free-' : 'paid-').$group['status']])">Продлить размещение</x-button>
@elseif($group['outside_window'])
<x-button :href="route('prototype.group-form', ['variant' => 'create'])">Создать новую группу</x-button>
@endif
<x-button kind="ghost" :href="route('prototype.group', ['variant' => $group['status']])">Подробнее</x-button>
@if(in_array($group['status'], ['draft','rejected']) && !$group['disabled'])
<x-button kind="danger" :disabled="$group['has_unrefunded_payment']" data-bs-toggle="modal" data-bs-target="#delete-group">Удалить</x-button>
@endif
</div>
@endif
