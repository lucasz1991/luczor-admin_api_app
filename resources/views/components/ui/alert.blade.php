@props(['tone' => 'info'])
<div {{ $attributes->class('ui-alert ui-alert--'.$tone) }} role="{{ $tone === 'danger' ? 'alert' : 'status' }}">{{ $slot }}</div>
