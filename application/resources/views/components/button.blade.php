@props(['kind' => 'primary', 'href' => null, 'disabled' => false, 'type' => 'button', 'icon' => null])
@if($href && !$disabled)
<a href="{{ $href }}" {{ $attributes->class(['btn', 'btn-'.$kind]) }}>@if($icon)<x-icon :name="$icon" />@endif{{ $slot }}</a>
@else
<button type="{{ $type }}" @disabled($disabled) {{ $attributes->class(['btn', 'btn-'.$kind]) }}>@if($icon)<x-icon :name="$icon" />@endif{{ $slot }}</button>
@endif
