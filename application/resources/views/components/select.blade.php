@props(['name', 'label', 'options' => [], 'value' => '', 'required' => false, 'help' => '', 'error' => null])
<div class="field">
<x-label :name="$name" :label="$label" :required="$required" />
<select id="{{ $name }}" name="{{ $name }}" @required($required) aria-invalid="{{ $error ? 'true' : 'false' }}" aria-describedby="{{ $name }}-help{{ $error ? ' '.$name.'-error' : '' }}" {{ $attributes->class(['form-select', 'is-invalid' => (bool) $error]) }}>
@foreach($options as $key => $text)
<option value="{{ $key }}" @selected((string) $key === (string) $value)>{{ $text }}</option>
@endforeach
</select>
<div class="form-text" id="{{ $name }}-help">{{ $help }}</div>
<x-validation-error :name="$name" :error="$error" />
</div>
