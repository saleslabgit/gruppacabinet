@props(['kind' => 'primary', 'href' => null, 'disabled' => false, 'type' => 'button'])
@if($href && !$disabled)
<a href="{{ $href }}" {{ $attributes->class(['btn', 'btn-'.$kind]) }}>{{ $slot }}</a>
@else
<button type="{{ $type }}" @disabled($disabled) {{ $attributes->class(['btn', 'btn-'.$kind]) }}>{{ $slot }}</button>
@endif
