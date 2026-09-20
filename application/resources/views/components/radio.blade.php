@props(['name', 'label', 'value', 'checked' => false])
<div class="form-check">
<input class="form-check-input" type="radio" id="{{ $name }}-{{ $value }}" name="{{ $name }}" value="{{ $value }}" @checked($checked) {{ $attributes }}>
<label class="form-check-label" for="{{ $name }}-{{ $value }}">{{ $label }}</label>
</div>
