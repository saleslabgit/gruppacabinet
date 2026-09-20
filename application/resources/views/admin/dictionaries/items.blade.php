@extends('layouts.admin')
@section('content')
<p class="mb-4">Формат группы · group_format</p>
<x-alert>Значения ниже — вымышленные примеры для вёрстки, не утверждённый справочник.</x-alert>
@if($empty)
<x-empty title="Элементов пока нет" text="Добавьте первое значение ниже." />
@else
<x-table :headers="['Код и название','Порядок','Состояние','Действия']">
<tr>
<x-cell label="Код и название">demo<p>{{ $long ? str_repeat('Демонстрационный формат ', 15) : 'Очно · пример' }}</p>
</x-cell>
<x-cell label="Порядок">10</x-cell>
<x-cell label="Состояние">{{ $variant === 'deactivated' ? 'Неактивен' : 'Активен' }}@if($variant === 'used')
<p>Используется в группах. Можно деактивировать, но нельзя удалить.</p>
@endif
</x-cell>
<x-cell label="Действия">
<div class="actions">
<a href="{{ route('prototype.admin-dictionary',['variant'=>'edit']) }}">Редактировать</a>
@if($variant === 'deactivated')
<x-button kind="secondary" data-noop>Активировать</x-button>
@else
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#deactivate">Деактивировать</x-button>
@endif
</div>
</x-cell>
</tr>
</x-table>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
<x-panel :title="$variant === 'edit' ? 'Редактировать элемент' : 'Добавить элемент'">
<form data-prototype-form>
<x-input name="code" label="Стабильный код" :required="true" :value="$variant === 'edit' ? 'demo' : ''" :error="$errors['code'] ?? null" />
<x-input name="name" label="Отображаемое название" :required="true" :value="$variant === 'edit' ? 'Очно · пример' : ''" :error="$errors['name'] ?? null" />
<x-input name="sort_order" label="Порядок сортировки" type="number" min="0" step="1" value="10" :error="$errors['sort_order'] ?? null" />
<x-checkbox name="active" label="Активен" :checked="$variant !== 'deactivated'" />
<div class="actions mt-3">
<x-button data-noop>Сохранить</x-button>
<x-button kind="ghost" :href="$links['admin-dictionaries']">К справочникам</x-button>
</div>
</form>
</x-panel>
<x-confirmation id="deactivate" title="Деактивировать элемент?" action="Деактивировать" :open="$variant === 'confirmation'">
<p>Элемент исчезнет из новых форм, но сохранится в существующих данных.</p>
</x-confirmation>
@endsection
