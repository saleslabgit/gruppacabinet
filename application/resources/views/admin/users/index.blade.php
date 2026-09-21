@extends('layouts.admin')
@section('actions')
<x-button :href="$links['admin-user-form']">Создать психолога</x-button>
@endsection
@section('content')
<x-panel title="Поиск психологов" :compact="true" class="panel-compact">
<form data-prototype-form>
<div class="row">
<div class="col-lg-6">
<x-input name="search" label="ФИО, email или телефон" :value="$variant === 'no-results' ? 'Нет совпадений' : ''" />
</div>
<div class="col-lg-3">
<x-select name="status" label="Статус" :options="[''=>'Все','pending'=>'Ожидает проверки','approved'=>'Принята','rejected'=>'Отклонена']" :value="in_array($variant,['pending','approved','rejected']) ? $variant : ''" />
</div>
<div class="col-lg-3">
<x-select name="free" label="Тариф" :options="[''=>'Все','free'=>'Бесплатный','paid'=>'Платный']" :value="in_array($variant,['free','paid']) ? $variant : ''" />
</div>
</div>
<x-button kind="secondary" data-noop>Применить</x-button>
</form>
</x-panel>
@if($empty)
<x-empty title="Психологи не найдены" text="Измените условия поиска или создайте психолога." />
@else
<x-table :headers="['Психолог','Статус и тариф','Регистрация','Действия']">
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
<a href="{{ route('prototype.admin-user', ['variant'=>$user['status']]) }}">Открыть</a>
</x-cell>
</tr>
</x-table>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
@endsection
