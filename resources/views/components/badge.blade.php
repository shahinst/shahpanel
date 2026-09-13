@props(['label', 'color' => 'secondary'])

@php
    $colors = [
        'default' => 'secondary',
        'primary' => 'primary',
        'success' => 'success',
        'green' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        'red' => 'danger',
        'info' => 'info',
        'blue' => 'primary',
        'violet' => 'primary',
    ];
    $class = 'badge bg-'.($colors[$color] ?? 'secondary');
@endphp

<span {{ $attributes->merge(['class' => $class]) }}>{{ $label }}</span>
