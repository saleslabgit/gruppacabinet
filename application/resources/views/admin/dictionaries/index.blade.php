@extends('layouts.admin')
@section('content')
@if($empty)
<x-empty title="Справочников пока нет" text="Создайте справочник для повторно используемых значений." />
@else
<x-table :headers="['Код','Название','Элементы','Действия']">
@foreach(['education_type'=>'Тип образования','group_format'=>'Формат группы','gender'=>'Пол'] as $code=>$name)
<tr>
<x-cell label="Код">{{ $code }}</x-cell>
<x-cell label="Название">{{ $long ? str_repeat($name.' ', 15) : $name }}</x-cell>
<x-cell label="Элементы">1 · демонстрационный</x-cell>
<x-cell label="Действия">
<div class="actions">
<a href="{{ $links['admin-dictionary'] }}">Открыть</a>
<a href="{{ route('prototype.admin-dictionaries',['variant'=>'edit']) }}">Редактировать</a>
</div>
</x-cell>
</tr>
@endforeach
</x-table>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
<x-panel :title="$variant === 'edit' ? 'Редактировать справочник' : 'Создать справочник'">
<form data-prototype-form>
<x-input name="code" label="Стабильный код" :required="true" :value="$variant === 'edit' ? 'group_format' : ''" :error="$errors['code'] ?? null" />
<x-input name="name" label="Название" :required="true" :value="$variant === 'edit' ? 'Формат группы' : ''" :error="$errors['name'] ?? null" />
<x-button data-noop>Сохранить</x-button>
</form>
</x-panel>
@endsection
