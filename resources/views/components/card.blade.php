@props([
    'title' => null,
    'footer' => null,
])

<div {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title)
        <div class="card-header">
            <h4 class="card-title mb-0">{{ $title }}</h4>
        </div>
    @endif
    <div class="card-body">
        {{ $slot }}
    </div>
    @if ($footer)
        <div class="card-footer">{{ $footer }}</div>
    @endif
</div>
