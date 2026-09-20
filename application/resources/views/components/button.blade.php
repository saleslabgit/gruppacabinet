@props(['kind' => 'primary', 'href' => null, 'disabled' => false])
@if($href && !$disabled)
<a href="{{ $href }}" {{ $attributes->class(['btn', 'btn-'.$kind]) }}>{{ $slot }}</a>
@else
<button type="button" @disabled($disabled) {{ $attributes->class(['btn', 'btn-'.$kind]) }}>{{ $slot }}</button>
@endif
