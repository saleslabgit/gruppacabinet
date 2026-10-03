@if($realGroups ?? false)
<div class="actions">
@if(($realGroups ?? false) && !$group['disabled'] && $group['status'] === 'awaiting_payment')
<x-button :href="($placementPayment ?? null) ? route('psychologist.payments.show', $placementPayment) : route('psychologist.groups.show', $group['id'])">Оплатить размещение</x-button>
@endif
@if(!$group['disabled'] && in_array($group['status'], ['draft', 'revision']))
@if($detail ?? false)<form method="POST" action="{{ route('psychologist.groups.submit-stored', $group['id']) }}">@csrf<input type="hidden" name="confirmed" value="1"><x-button type="submit">Отправить на модерацию</x-button></form>@endif
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
@if(($detail ?? false) && ($canPause ?? false))
<x-button kind="secondary" data-bs-toggle="modal" data-bs-target="#pause-group">Поставить на паузу</x-button>
@endif
@if(($detail ?? false) && ($canResume ?? false) && ($group['modx_publication_status'] ?? null) !== 'conflict')
@if(($group['modx_publication_desired'] ?? null) === 'published' && in_array($group['modx_publication_status'] ?? null, ['pending', 'syncing'], true))
<x-button :disabled="true">Возобновление выполняется</x-button>
@else
<x-button data-bs-toggle="modal" data-bs-target="#resume-group">Возобновить публикацию</x-button>
@endif
@endif
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
@if($detail ?? false)<x-button data-noop>Отправить на модерацию</x-button>@endif
<x-button icon="pencil" :href="route('prototype.group-form', ['variant' => $group['status']])">{{ $group['status'] === 'revision' ? 'Исправить и отправить' : 'Заполнить группу' }}</x-button>
@elseif(in_array($group['status'], ['active','expired']) && !$group['outside_window'])
<x-button icon="calendar-plus" :href="route('prototype.extension', ['variant' => ($user['free'] ? 'free-' : 'paid-').$group['status']])">Продлить размещение</x-button>
@elseif($group['outside_window'])
<x-button icon="plus-lg" :href="route('prototype.group-form', ['variant' => 'create'])">Создать новую группу</x-button>
@endif
@unless($detail ?? false)
<x-button icon="arrow-up-right" kind="ghost" :href="route('prototype.group', ['variant' => $group['status']])">Подробнее</x-button>
@endunless
@if(($detail ?? false) && !$group['disabled'] && $group['status'] === 'active')
<x-button kind="secondary" data-bs-toggle="modal" data-bs-target="#pause-group">Поставить на паузу</x-button>
@elseif(($detail ?? false) && !$group['disabled'] && $group['status'] === 'paused' && ($group['modx_publication_status'] ?? null) !== 'conflict')
@if(($group['modx_publication_desired'] ?? null) === 'published' && in_array($group['modx_publication_status'] ?? null, ['pending', 'syncing'], true))
<x-button :disabled="true">Возобновление выполняется</x-button>
@else
<x-button data-bs-toggle="modal" data-bs-target="#resume-group">Возобновить публикацию</x-button>
@endif
@endif
<x-button icon="trash" kind="danger" data-bs-toggle="modal" data-bs-target="#delete-group">Удалить</x-button>
</div>
@endif
