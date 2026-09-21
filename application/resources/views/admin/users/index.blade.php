@extends('layouts.admin')
@section('actions')
<x-button :href="$links['admin-user-form']">Создать психолога</x-button>
@endsection
@section('content')
<x-panel title="Поиск психологов" :compact="true" class="panel-compact">
<form @if($prototype) data-prototype-form @else method="GET" action="{{ route('admin.psychologists.index') }}" @endif>
<div class="row">
<div class="col-lg-6">
<x-input name="search" label="ФИО, email или телефон" :value="$prototype ? ($variant === 'no-results' ? 'Нет совпадений' : '') : ($filters['search'] ?? '')" />
</div>
<div class="col-lg-3">
<x-select name="status" label="Статус" :options="[''=>'Все','pending'=>'Ожидает проверки','approved'=>'Принята','rejected'=>'Отклонена']" :value="$prototype ? (in_array($variant,['pending','approved','rejected']) ? $variant : '') : ($filters['status'] ?? '')" />
</div>
<div class="col-lg-3">
<x-select name="free" label="Тариф" :options="[''=>'Все','free'=>'Бесплатный','paid'=>'Платный']" :value="$prototype ? (in_array($variant,['free','paid']) ? $variant : '') : ($filters['free'] ?? '')" />
</div>
</div>
<x-button kind="secondary" :type="$prototype ? 'button' : 'submit'" :data-noop="$prototype">Применить</x-button>
</form>
</x-panel>
@if($empty)
<x-empty title="Психологи не найдены" text="Измените условия поиска или создайте психолога." />
@else
<x-table :headers="['Психолог','Статус и тариф','Регистрация','Действия']">
@foreach($prototype ? [$user] : $users as $user)
<tr>
<x-cell label="Психолог">
<strong>{{ $user['name'] }}</strong>
<p>{{ $user['email'] }}</p>
<p>{{ $user['phone'] }}</p>
</x-cell>
<x-cell label="Статус и тариф">
<x-status domain="user" :value="$user['status']" />
<p>{{ $user['free'] ? 'Бесплатный' : 'Платный' }}</p>
<x-status domain="access" :value="$user['disabled'] ? 'disabled' : 'enabled'" />
</x-cell>
<x-cell label="Регистрация">
<x-date :value="$user['created_at']" />
</x-cell>
<x-cell label="Действия">
<a href="{{ $prototype ? route('prototype.admin-user', ['variant'=>$user['status']]) : route('admin.psychologists.show', $user['id']) }}">Открыть</a>
</x-cell>
</tr>
@endforeach
</x-table>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
@endsection
