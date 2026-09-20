@props(['tone' => 'info'])
<div {{ $attributes->class(['notice', 'tone-'.$tone]) }} role="{{ $tone === 'danger' ? 'alert' : 'status' }}">{{ $slot }}</div>
