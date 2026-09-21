@props(['name', 'label', 'required' => false])
<label for="{{ $name }}" class="form-label">{{ $label }} @if($required)<span class="meta">· обязательно</span>@endif
</label>
