@unless($admin)
<x-panel :title="$group['title']">
@include('shared.application-counters')
</x-panel>
@endunless
<x-panel title="Поиск и фильтры" :compact="true" class="panel-compact filter-panel">
<form @if($realApplications ?? false) method="GET" action="{{ $links[$admin ? 'admin-applications' : 'applications'] }}" @else data-prototype-form @endif>
@if($admin && !empty($filters['group_id']))<input type="hidden" name="group_id" value="{{ $filters['group_id'] }}"><p>Заявки группы № {{ $filters['group_id'] }}</p>@endif
<div class="row">
@if($admin)
<div class="col-md-6">
<x-input name="search" label="Участник, телефон, группа или психолог" :value="($realApplications ?? false) ? ($filters['search'] ?? '') : ($variant === 'no-results' ? 'Нет совпадений' : '')" />
</div>
@endif
<div class="col-md-6">
<x-select name="processed" label="Состояние заявки" :options="['all'=>'Все','new'=>'Новые','processed'=>'Обработанные']" :value="($realApplications ?? false) ? ($filters['processed'] ?? 'all') : (in_array($variant,['new','processed']) ? $variant : 'all')" />
</div>
</div>
<div class="actions">
@if($realApplications ?? false)
<x-button icon="search" kind="secondary" type="submit">Применить</x-button>
@else
<x-button icon="search" kind="secondary" data-noop>Применить</x-button>
@endif
<x-button icon="arrow-counterclockwise" kind="ghost" :href="$links[$admin ? 'admin-applications' : 'applications']">Сбросить</x-button>
</div>
</form>
</x-panel>
@if($empty)
<x-empty :title="(!empty($filters['search']) || !in_array($filters['processed'] ?? 'all', ['', 'all']) || $variant === 'no-results') ? 'Заявки не найдены' : 'Заявок пока нет'" :text="(!empty($filters['search']) || !in_array($filters['processed'] ?? 'all', ['', 'all']) || $variant === 'no-results') ? 'Измените условия поиска или сбросьте фильтры.' : 'Здесь появятся контакты участников, когда поступят заявки.'" />
@else
<x-table :headers="$admin ? ['Участник','Группа и психолог','Дата и состояние','Действия'] : ['Участник','Дата','Состояние','Действия']">
@foreach(($realApplications ?? false) ? $applications : [$application] as $item)
<tr>
<x-cell label="Участник">
<strong>{{ $item['name'] }}</strong>
@if($admin && !empty($item['psychologist_deleted_at']))<p class="meta">Психолог удалил заявку</p>@endif
<p>{{ $item['phone'] }}</p>
</x-cell>
<x-cell :label="$admin ? 'Группа и психолог' : 'Дата'">
@if($admin)
<a href="{{ $item['group_url'] ?? $links['admin-group'] }}">{{ $item['group_title'] ?? $group['title'] }}</a>
<p>
<a href="{{ $item['owner_url'] ?? $links['admin-user'] }}">{{ $item['owner_name'] ?? $user['name'] }}</a>
</p>
@else
<x-date :value="$item['created_at']" />
@endif
</x-cell>
<x-cell label="Состояние">
@if($admin)
<p>
<x-date :value="$item['created_at']" />
</p>
@endif
<x-status domain="application" :value="$item['processed_at'] ? 'processed' : 'new'" />
</x-cell>
<x-cell label="Действия">
<div class="actions">
<x-button icon="arrow-up-right" kind="ghost" :href="$item['show_url'] ?? route('prototype.'.($admin ? 'admin-application' : 'application'), ['variant' => $variant === 'processed' ? 'processed' : 'new'])">Открыть</x-button>
@unless($admin)
@if($item['delete_url'] ?? null)<x-button kind="danger" data-bs-toggle="modal" :data-bs-target="'#delete-application-'.$item['id']">Удалить</x-button>@endif
@if($item['action_url'] ?? null)
<form method="POST" action="{{ $item['action_url'] }}">@csrf
<x-button icon="check-lg" kind="secondary" type="submit">{{ $item['processed_at'] ? 'Вернуть в необработанные' : 'Отметить обработанной' }}</x-button>
</form>
@else
<x-button icon="check-lg" kind="secondary" data-noop>{{ $item['processed_at'] ? 'Вернуть в необработанные' : 'Отметить обработанной' }}</x-button>
@endif
@endunless
</div>
</x-cell>
</tr>
@endforeach
</x-table>
@if(!$admin && ($realApplications ?? false))
@foreach($applications as $item)
<x-confirmation :id="'delete-application-'.$item['id']" title="Удалить заявку?" action="Удалить" :url="$item['delete_url']" method="DELETE"><p>{{ $item['name'] }}. Заявка исчезнет из вашего кабинета. История сохранится у администратора.</p></x-confirmation>
@endforeach
@endif
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
