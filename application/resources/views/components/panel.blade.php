@props(['title' => null, 'compact' => false, 'muted' => false])
<section {{ $attributes->class(['panel', 'panel-muted' => $muted]) }}>
@if($title)
<h2 @class(['panel-title-compact' => $compact || $muted])>{{ $title }}</h2>
@endif{{ $slot }}</section>
