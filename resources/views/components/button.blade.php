@props([
    'type' => 'button',
    'variant' => 'primary',
    'href' => null,
    'size' => null,
])

@php
    $map = [
        'primary' => 'btn-primary',
        'secondary' => 'btn-secondary',
        'danger' => 'btn-danger',
        'success' => 'btn-success',
        'warning' => 'btn-warning',
        'ghost' => 'btn-light',
        'info' => 'btn-info',
    ];
    $sizeClass = match ($size) {
        'sm', 'xs' => ' btn-sm',
        'lg' => ' btn-lg',
        default => '',
    };
    $classes = 'btn '.($map[$variant] ?? 'btn-primary').$sizeClass;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
