@props(['name', 'label', 'checked' => false, 'error' => null])
<div class="form-check">
<input class="form-check-input" type="checkbox" id="{{ $name }}" name="{{ $name }}" value="1" @checked($checked) @if($error) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif {{ $attributes }}>
<label class="form-check-label" for="{{ $name }}">{{ $label }}</label>
<x-validation-error :name="$name" :error="$error" />
</div>
