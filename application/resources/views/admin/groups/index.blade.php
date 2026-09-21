@extends('layouts.admin')
@section('actions')
<x-button icon="plus-lg" :href="$links['admin-group-form']">Создать группу</x-button>
@endsection
@section('content')
<x-panel title="Поиск и фильтры" :compact="true" class="panel-compact filter-panel">
<form @if($realGroups ?? false) method="GET" action="{{ route('admin.groups.index') }}" @else data-prototype-form @endif>
<x-input name="search" label="ID, название или психолог" :value="$filters['search'] ?? ($variant === 'no-results' ? 'Нет совпадений' : '')" />
<div class="row">
<div class="col-md-4">
<x-select name="status" label="Статус" :options="[''=>'Все','awaiting_payment'=>'Ожидает оплаты','draft'=>'Черновик','moderation'=>'На модерации','revision'=>'На доработке','rejected'=>'Отклонена','approved'=>'Ожидает публикации','active'=>'Активная','expired'=>'Закончена']" :value="$filters['status'] ?? (in_array($variant,['normal','empty','long','pagination']) ? '' : $group['status'])" />
</div>
<div class="col-md-4">
<x-select name="free" label="Тариф группы" :options="[''=>'Все','free'=>'Бесплатная','paid'=>'Платная']" :value="$filters['free'] ?? $variant" />
</div>
@unless($realGroups ?? false)
<div class="col-md-4">
<x-select name="successful_payment" label="Успешный платёж" :options="[''=>'Все','yes'=>'Есть','no'=>'Нет']" :value="$variant === 'successful-payment' ? 'yes' : ''" />
</div>
@endunless
<div class="col-md-4">
<x-select name="sort" label="Сортировка" :value="$filters['sort'] ?? 'created_at'" :options="['created_at'=>'Дата создания','published_at'=>'Дата публикации','expires_at'=>'Дата окончания']" />
</div>
@unless($realGroups ?? false)
<div class="col-md-4">
<x-input name="created_before" label="Созданы до, Минск" type="date" :value="$variant === 'abandoned' ? '2026-08-20' : ''" />
</div>
@endunless
</div>
@if($realGroups ?? false)
@if(!empty($filters['quick']))<input type="hidden" name="quick" value="{{ $filters['quick'] }}">@endif
<x-button icon="search" kind="secondary" type="submit">Применить</x-button>
<x-button icon="arrow-counterclockwise" kind="ghost" :href="route('admin.groups.index')">Сбросить</x-button>
@else
<x-button icon="search" kind="secondary" data-noop>Применить</x-button>
@endif
</form>
<div class="actions quick-filters mt-3">
@foreach(['approved'=>'Ожидают публикации','expired'=>'Снять с публикации','abandoned'=>'Брошенные черновики'] as $state=>$label)
<a href="{{ ($realGroups ?? false) ? route('admin.groups.index',['quick'=>$state]) : route('prototype.admin-groups',['variant'=>$state]) }}">{{ $label }}</a>
@endforeach
</div>
</x-panel>
@if($empty)
<x-empty title="Группы не найдены" text="Попробуйте изменить фильтры." />
@else
<div class="group-list group-list-admin">
@foreach($groups as $group)
<article class="group-row">
<div>
<h2>{{ $group['title'] }}</h2>
<p>
<a href="{{ ($realGroups ?? false) ? route('admin.psychologists.show', $group['owner_id']) : $links['admin-user'] }}">{{ ($realGroups ?? false) ? $group['owner']['name'] : $user['name'] }}</a>
</p>
@include('shared.group-summary')
</div>
<div class="actions" aria-label="Действия с группой">
<x-button icon="arrow-up-right" kind="secondary" :href="($realGroups ?? false) ? route('admin.groups.show', $group['id']) : route('prototype.admin-group',['variant'=>$group['status']])">Открыть группу</x-button>
</div>
</article>
@endforeach
</div>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
@endsection
