@props([
    'ip' => '',
    'flag' => null,
])
@php
    $ipValue = trim((string) $ip);
    $flagEmoji = $flag ?? ($ipValue !== '' ? ip_flag($ipValue) : '🏳️');
@endphp
<span {{ $attributes->class(['ip-with-flag', 'text-nowrap']) }} @if($ipValue !== '') title="{{ $ipValue }}" @endif>
    <span class="fs-5 align-middle" aria-hidden="true">{{ $flagEmoji }}</span>
    @if ($ipValue !== '')
        <code dir="ltr" class="align-middle">{{ $ipValue }}</code>
    @else
        <span class="text-muted">—</span>
    @endif
</span>
