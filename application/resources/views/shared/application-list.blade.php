@unless($admin)
<x-panel :title="$group['title']">
@include('shared.application-counters')
</x-panel>
@endunless
<x-panel title="Поиск и фильтры">
<form data-prototype-form>
<div class="row">
@if($admin)
<div class="col-md-6">
<x-input name="search" label="Участник, телефон, группа или психолог" :value="$variant === 'no-results' ? 'Нет совпадений' : ''" />
</div>
@endif
<div class="col-md-6">
<x-select name="processed" label="Состояние заявки" :options="['all'=>'Все','new'=>'Новые','processed'=>'Обработанные']" :value="in_array($variant,['new','processed']) ? $variant : 'all'" />
</div>
</div>
<div class="actions">
<x-button kind="secondary" data-noop>Применить</x-button>
<x-button kind="ghost" :href="$links[$admin ? 'admin-applications' : 'applications']">Сбросить</x-button>
</div>
</form>
</x-panel>
@if($empty)
<x-empty title="Заявок не найдено" text="Когда участники запишутся в группу, здесь появятся их контакты." />
@else
<x-table :headers="$admin ? ['Участник','Группа и психолог','Дата и состояние','Действия'] : ['Участник','Дата','Состояние','Действия']">
@foreach([$application] as $item)
<tr>
<x-cell label="Участник">
<strong>{{ $item['name'] }}</strong>
<p>{{ $item['phone'] }}</p>
</x-cell>
<x-cell :label="$admin ? 'Группа и психолог' : 'Дата'">
@if($admin)
<a href="{{ $links['admin-group'] }}">{{ $group['title'] }}</a>
<p>
<a href="{{ $links['admin-user'] }}">{{ $user['name'] }}</a>
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
<x-button kind="ghost" :href="route('prototype.'.($admin ? 'admin-application' : 'application'), ['variant' => $variant === 'processed' ? 'processed' : 'new'])">Открыть</x-button>
@unless($admin)
<x-button kind="secondary" data-noop>{{ $item['processed_at'] ? 'Вернуть в необработанные' : 'Отметить обработанной' }}</x-button>
@endunless
</div>
</x-cell>
</tr>
@endforeach
</x-table>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
