@extends('layouts.admin')
@section('breadcrumbs')
@if(isset($editing) || $variant === 'edit')
<x-breadcrumbs :items="[['label'=>'Справочники', 'url'=>($realDictionaries ?? false) ? route('admin.dictionaries.index') : $links['admin-dictionaries']], ['label'=>'Редактирование']]" />
@endif
@endsection
@section('content')
@php
$real = $realDictionaries ?? false;
$editing = $real ? $editing : ($variant === 'edit' ? (object) ['code'=>'group_format', 'name'=>'Формат группы'] : null);
$rows = $real ? $dictionaries : ($empty ? [] : collect(['education_type'=>'Тип образования','group_format'=>'Формат группы','gender'=>'Пол'])->map(fn($name, $code) => (object) ['code'=>$code, 'name'=>$long ? str_repeat($name.' ', 15) : $name]));
@endphp
@if($real)<x-validation-summary :errors="$errors" />@endif
@unless($editing)
@if(count($rows) === 0)
<x-empty title="Справочников пока нет" text="Создайте справочник для повторно используемых значений." />
@else
<x-table :headers="['Код','Название','Элементы','Действия']">
@foreach($rows as $row)
<tr>
<x-cell label="Код">{{ $row->code }}</x-cell>
<x-cell label="Название">{{ $row->name }}</x-cell>
<x-cell label="Элементы">{{ $real ? $row->items_count.' · активных: '.$row->active_items_count : '1 · демонстрационный' }}</x-cell>
<x-cell label="Действия"><div class="actions dictionary-actions">
<x-button kind="secondary" icon="arrow-up-right" :href="$real ? route('admin.dictionaries.items.index', $row) : $links['admin-dictionary']">Открыть</x-button>
<x-button kind="secondary" icon="pencil" :href="$real ? route('admin.dictionaries.edit', $row) : route('prototype.admin-dictionaries',['variant'=>'edit'])">Редактировать</x-button>
@if($real && !in_array($row->code, $coreCodes, true) && $row->items_count === 0)
<x-button icon="trash" kind="danger" data-bs-toggle="modal" data-bs-target="#delete-dictionary-{{ $row->id }}">Удалить</x-button>
@endif
</div></x-cell>
</tr>
@endforeach
</x-table>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
@if($real)
@foreach($rows as $row)
@if(!in_array($row->code, $coreCodes, true) && $row->items_count === 0)
<x-confirmation :id="'delete-dictionary-'.$row->id" title="Удалить справочник?" action="Удалить" :url="route('admin.dictionaries.destroy', $row)" method="DELETE"><p>{{ $row->name }} будет удалён окончательно.</p></x-confirmation>
@endif
@endforeach
@endif
@endunless
<x-panel :title="$editing ? 'Редактировать справочник' : 'Создать справочник'">
<form @if($real) method="POST" action="{{ $editing ? route('admin.dictionaries.update', $editing) : route('admin.dictionaries.store') }}" @else data-prototype-form @endif>
@if($real) @csrf @if($editing) @method('PUT') @endif @endif
@if($real && $editing)
<p>Стабильный код: <strong>{{ $editing->code }}</strong></p>
@else
<x-input name="code" label="Стабильный код" :required="true" :value="$real ? old('code') : ($editing->code ?? '')" :error="$errors['code'] ?? null" />
@endif
<x-input name="name" label="Название" :required="true" :value="$real ? old('name', $editing->name ?? '') : ($editing->name ?? '')" :error="$errors['name'] ?? null" />
@if($real)
<x-button icon="check-lg" type="submit">Сохранить</x-button>
@if($editing) <x-button icon="arrow-left" kind="ghost" :href="route('admin.dictionaries.index')">Отмена</x-button> @endif
@else
<x-button icon="check-lg" data-noop>Сохранить</x-button>
@endif
</form>
</x-panel>
@endsection
