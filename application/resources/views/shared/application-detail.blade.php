<x-panel :title="$application['name']">
@if($admin && !empty($application['psychologist_deleted_at']))<p class="meta">Психолог удалил заявку</p>@endif
<div class="mb-4">
<x-status domain="application" :value="$application['processed_at'] ? 'processed' : 'new'" />
</div>
<dl class="detail-grid">
<div>
<dt>Телефон</dt>
<dd>{{ $application['phone'] }}</dd>
</div>
<div>
<dt>Группа</dt>
<dd>
<a href="{{ $application['group_url'] ?? $links[$admin ? 'admin-group' : 'group'] }}">{{ $application['group_title'] ?? $group['title'] }}</a>
</dd>
</div>
@if($admin)
<div>
<dt>Психолог</dt>
<dd>
<a href="{{ $application['owner_url'] ?? $links['admin-user'] }}">{{ $application['owner_name'] ?? $user['name'] }}</a>
</dd>
</div>
@endif
<div>
<dt>Получена</dt>
<dd>
<x-date :value="$application['created_at']" />
</dd>
</div>
<div>
<dt>Обновлена</dt>
<dd>
<x-date :value="$application['updated_at']" />
</dd>
</div>
<div>
<dt>Обработана</dt>
<dd>
<x-date :value="$application['processed_at']" />
</dd>
</div>
</dl>
<div class="actions mt-4">
@unless($admin)
@if($application['delete_url'] ?? null)<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#delete-application">Удалить</x-button>@endif
@if($application['action_url'] ?? null)
<form method="POST" action="{{ $application['action_url'] }}">@csrf
<x-button icon="check-lg" type="submit">{{ $application['processed_at'] ? 'Вернуть в необработанные' : 'Отметить обработанной' }}</x-button>
</form>
@else
<x-button icon="check-lg" data-noop>{{ $application['processed_at'] ? 'Вернуть в необработанные' : 'Отметить обработанной' }}</x-button>
@endif
@endunless
<x-button icon="arrow-left" kind="ghost" :href="$links[$admin ? 'admin-applications' : 'applications']">Назад к заявкам</x-button>
</div>
</x-panel>
@if(!$admin && ($application['delete_url'] ?? null))
<x-confirmation id="delete-application" title="Удалить заявку?" action="Удалить" :url="$application['delete_url']" method="DELETE"><p>Заявка исчезнет из вашего кабинета. История сохранится у администратора.</p></x-confirmation>
@endif
