@props([
    'title',
    'value',
    'hint' => null,
    'icon' => 'bx-bar-chart',
    'color' => 'primary',
])

@php
    $soft = match ($color) {
        'green', 'success' => 'success',
        'red', 'danger' => 'danger',
        'yellow', 'warning' => 'warning',
        default => 'primary',
    };
@endphp

<div class="col-xl-3 col-md-6">
    <div class="card">
        <div class="card-body">
            <div class="d-flex align-items-center">
                <div class="avatar">
                    <div class="avatar-title rounded bg-soft-{{ $soft }} text-{{ $soft }} font-size-24">
                        <i class="bx {{ $icon }}"></i>
                    </div>
                </div>
                <div class="flex-grow-1 ms-3">
                    <p class="text-muted mb-1">{{ $title }}</p>
                    <h4 class="mb-0">{{ $value }}</h4>
                    @if ($hint)
                        <small class="text-muted">{{ $hint }}</small>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
