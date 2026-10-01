@props(['name', 'label', 'options' => [], 'values' => [], 'required' => false, 'error' => null])
<div class="field" data-multi-select>
<x-label :name="$name" :label="$label" :required="$required" />
<input type="hidden" name="{{ $name }}" value="">
<select id="{{ $name }}" name="{{ $name }}[]" multiple size="6" class="form-select" @required($required) aria-invalid="{{ $error ? 'true' : 'false' }}" aria-describedby="{{ $name }}-help{{ $error ? ' '.$name.'-error' : '' }}">
@foreach($options as $key => $text)
<option value="{{ $key }}" @selected(in_array((string) $key, array_map('strval', $values), true))>{{ $text }}</option>
@endforeach
</select>
<div class="form-text" id="{{ $name }}-help">Можно выбрать несколько значений.</div>
<x-validation-error :name="$name" :error="$error" />
</div>
