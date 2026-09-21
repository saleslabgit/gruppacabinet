@extends('layouts.admin')
@section('content')
@php
$real = $realDictionaries ?? false;
$editing = $real ? $editing : ($variant === 'edit' ? (object) ['code'=>'demo', 'name'=>'Очно · пример', 'sort_order'=>10, 'active'=>true] : null);
$rows = $real ? $items : ($empty ? [] : [(object) ['code'=>'demo', 'name'=>$long ? str_repeat('Демонстрационный формат ', 15) : 'Очно · пример', 'sort_order'=>10, 'active'=>$variant !== 'deactivated', 'usage_count'=>$variant === 'used' ? 1 : 0]]);
@endphp
<p class="mb-4">{{ $real ? $dictionary->name.' · '.$dictionary->code : 'Формат группы · group_format' }}</p>
@if(!$real)<x-alert>Значения ниже — вымышленные примеры для вёрстки, не утверждённый справочник.</x-alert>@endif
@if($real)<x-validation-summary :errors="$errors" />@endif
@if(count($rows) === 0)
<x-empty title="Элементов пока нет" text="Добавьте первое значение ниже." />
@else
<x-table :headers="['Код и название','Порядок','Состояние','Действия']">
@foreach($rows as $row)
<tr>
<x-cell label="Код и название">{{ $row->code }}<p>{{ $row->name }}</p></x-cell>
<x-cell label="Порядок">{{ $row->sort_order }}</x-cell>
<x-cell label="Состояние">{{ $row->active ? 'Активен' : 'Неактивен' }}
@if($row->usage_count)<p>{{ $real ? 'Используется в данных. Можно деактивировать, но нельзя удалить.' : 'Используется в группах. Можно деактивировать, но нельзя удалить.' }}</p>@elseif($real)<p>Не используется.</p>@endif
</x-cell>
<x-cell label="Действия"><div class="actions">
<a href="{{ $real ? route('admin.dictionaries.items.edit', [$dictionary, $row]) : route('prototype.admin-dictionary',['variant'=>'edit']) }}">Редактировать</a>
@if(!$row->active)
@if($real)
<form method="POST" action="{{ route('admin.dictionaries.items.activate', [$dictionary, $row]) }}">@csrf<x-button type="submit" kind="secondary">Активировать</x-button></form>
@else<x-button kind="secondary" data-noop>Активировать</x-button>@endif
@else
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#{{ $real ? 'deactivate-'.$row->id : 'deactivate' }}">Деактивировать</x-button>
@endif
@if($real && !$row->usage_count)<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#delete-item-{{ $row->id }}">Удалить</x-button>@endif
</div></x-cell>
</tr>
@endforeach
</x-table>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
@if($real)
@foreach($rows as $row)
@if($row->active)
<x-confirmation :id="'deactivate-'.$row->id" title="Деактивировать элемент?" action="Деактивировать" :url="route('admin.dictionaries.items.deactivate', [$dictionary, $row])"><p>{{ $row->name }} исчезнет из новых форм, но сохранится в существующих данных.</p></x-confirmation>
@endif
@if(!$row->usage_count)
<x-confirmation :id="'delete-item-'.$row->id" title="Удалить элемент?" action="Удалить" :url="route('admin.dictionaries.items.destroy', [$dictionary, $row])" method="DELETE"><p>{{ $row->name }} будет удалён окончательно.</p></x-confirmation>
@endif
@endforeach
@endif
<x-panel :title="$editing ? 'Редактировать элемент' : 'Добавить элемент'">
<form id="item-form" @if($real) method="POST" action="{{ $editing ? route('admin.dictionaries.items.update', [$dictionary, $editing]) : route('admin.dictionaries.items.store', $dictionary) }}" @else data-prototype-form @endif>
@if($real) @csrf @if($editing) @method('PUT') @endif @endif
@if($real && $editing)
<p>Стабильный код: <strong>{{ $editing->code }}</strong></p>
@else
<x-input name="code" label="Стабильный код" :required="true" :value="$real ? old('code') : ($editing->code ?? '')" :error="$errors['code'] ?? null" />
@endif
<x-input name="name" label="Отображаемое название" :required="true" :value="$real ? old('name', $editing->name ?? '') : ($editing->name ?? '')" :error="$errors['name'] ?? null" />
<x-input name="sort_order" label="Порядок сортировки" :required="$real" type="number" min="0" step="1" :value="$real ? old('sort_order', $editing->sort_order ?? 10) : 10" :error="$errors['sort_order'] ?? null" />
@if($real)<input type="hidden" name="active" value="0">@endif
<x-checkbox name="active" label="Активен" :checked="$real ? (bool) old('active', $editing->active ?? true) : $variant !== 'deactivated'" :error="$errors['active'] ?? null" />
<div class="actions mt-3">
@if($real && $editing)
<x-button data-bs-toggle="modal" data-bs-target="#item-save-confirm">Сохранить</x-button>
@elseif($real)<x-button type="submit">Сохранить</x-button>
@else<x-button data-noop>Сохранить</x-button>@endif
<x-button kind="ghost" :href="$real ? route('admin.dictionaries.index') : $links['admin-dictionaries']">К справочникам</x-button>
@if($real && $editing)<x-button kind="ghost" :href="route('admin.dictionaries.items.index', $dictionary)">Отмена</x-button>@endif
</div>
</form>
</x-panel>
@if($real && $editing)
<x-confirmation id="item-save-confirm" title="Сохранить изменения элемента?" action="Сохранить" kind="primary" form="item-form"><p>Если снять отметку «Активен», элемент исчезнет из новых форм, но сохранится в существующих данных.</p></x-confirmation>
@elseif(!$real)
<x-confirmation id="deactivate" title="Деактивировать элемент?" action="Деактивировать" :open="$variant === 'confirmation'"><p>Элемент исчезнет из новых форм, но сохранится в существующих данных.</p></x-confirmation>
@endif
@endsection
