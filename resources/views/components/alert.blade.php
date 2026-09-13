@props(['type' => 'info'])

@php
    $map = [
        'success' => 'alert-success',
        'warning' => 'alert-warning',
        'error' => 'alert-danger',
        'danger' => 'alert-danger',
        'info' => 'alert-info',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'alert '.($map[$type] ?? 'alert-info').' alert-dismissible fade show']) }} role="alert">
    <div class="alert__content">{{ $slot }}</div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
