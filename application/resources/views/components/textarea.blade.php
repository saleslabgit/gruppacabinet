@props(['name', 'label', 'value' => '', 'required' => false, 'help' => '', 'error' => null])
<div class="field">
<x-label :name="$name" :label="$label" :required="$required" />
<textarea id="{{ $name }}" name="{{ $name }}" @required($required) aria-invalid="{{ $error ? 'true' : 'false' }}" aria-describedby="{{ $name }}-help{{ $error ? ' '.$name.'-error' : '' }}" {{ $attributes->class(['form-control', 'is-invalid' => (bool) $error]) }}>{{ $value }}</textarea>
<div class="form-text" id="{{ $name }}-help">{{ $help }}</div>
<x-validation-error :name="$name" :error="$error" />
</div>
