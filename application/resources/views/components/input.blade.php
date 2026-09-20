@props(['name', 'label', 'value' => '', 'type' => 'text', 'required' => false, 'help' => '', 'error' => null])
<div class="field">
<x-label :name="$name" :label="$label" :required="$required" />
<input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" @if($type !== 'file') value="{{ $value }}" @endif @required($required) aria-invalid="{{ $error ? 'true' : 'false' }}" aria-describedby="{{ $name }}-help{{ $error ? ' '.$name.'-error' : '' }}" {{ $attributes->class(['form-control', 'is-invalid' => (bool) $error]) }}>
<div class="form-text" id="{{ $name }}-help">{{ $help }}</div>
<x-validation-error :name="$name" :error="$error" />
</div>
