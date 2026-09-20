@props(['name', 'label', 'required' => false])
<label for="{{ $name }}" class="form-label">{{ $label }} <span class="meta">{{ $required ? '· обязательно' : '· необязательно' }}</span>
</label>
