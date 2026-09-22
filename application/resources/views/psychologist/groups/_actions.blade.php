@if($realGroups ?? false)
<div class="actions">
@if(($realGroups ?? false) && !$group['disabled'] && $group['status'] === 'awaiting_payment')
<x-button :href="($placementPayment ?? null) ? route('psychologist.payments.show', $placementPayment) : route('psychologist.groups.show', $group['id'])">Оплатить размещение</x-button>
@endif
@if(!$group['disabled'] && in_array($group['status'], ['draft', 'revision']))
<x-button icon="pencil" :href="route('psychologist.groups.edit', $group['id'])">{{ $group['status'] === 'revision' ? 'Исправить и отправить' : 'Заполнить группу' }}</x-button>
@endif
@if(!$group['disabled'] && in_array($group['status'], ['active', 'expired']))
@if($group['outside_window'])
<form method="POST" action="{{ route('psychologist.groups.store') }}">@csrf<x-button icon="plus-lg" type="submit">Создать новую группу</x-button></form>
@else
<x-button icon="calendar-plus" :href="route('psychologist.groups.extension', $group['id'])">Продлить размещение</x-button>
@endif
@endif
@unless($detail ?? false)
<x-button icon="arrow-up-right" kind="ghost" :href="route('psychologist.groups.show', $group['id'])">Подробнее</x-button>
@endunless
@if($canDelete ?? false)
<x-button icon="trash" kind="danger" data-bs-toggle="modal" data-bs-target="#delete-group">Удалить</x-button>
@endif
</div>
@else
<div class="actions">
@if($group['disabled'])
<x-button :disabled="true">Действия недоступны</x-button>
@elseif($group['status'] === 'awaiting_payment')
<x-button :href="$links['placement']">Оплатить размещение</x-button>
@elseif(in_array($group['status'], ['draft','revision']))
<x-button icon="pencil" :href="route('prototype.group-form', ['variant' => $group['status']])">{{ $group['status'] === 'revision' ? 'Исправить и отправить' : 'Заполнить группу' }}</x-button>
@elseif(in_array($group['status'], ['active','expired']) && !$group['outside_window'])
<x-button icon="calendar-plus" :href="route('prototype.extension', ['variant' => ($user['free'] ? 'free-' : 'paid-').$group['status']])">Продлить размещение</x-button>
@elseif($group['outside_window'])
<x-button icon="plus-lg" :href="route('prototype.group-form', ['variant' => 'create'])">Создать новую группу</x-button>
@endif
@unless($detail ?? false)
<x-button icon="arrow-up-right" kind="ghost" :href="route('prototype.group', ['variant' => $group['status']])">Подробнее</x-button>
@endunless
@if(in_array($group['status'], ['draft','rejected']) && !$group['disabled'])
<x-button icon="trash" kind="danger" :disabled="$group['has_unrefunded_payment']" data-bs-toggle="modal" data-bs-target="#delete-group">Удалить</x-button>
@endif
</div>
@endif
