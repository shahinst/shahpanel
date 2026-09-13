@props(['title' => null])

<div {{ $attributes->merge(['class' => 'vp-page-header']) }}>
    <div class="flex-grow-1">
        @if ($title)
            <h4 class="vp-page-title">{{ $title }}</h4>
        @endif
        {{ $slot }}
    </div>
    @isset($actions)
        <div class="vp-page-actions">
            {{ $actions }}
        </div>
    @endisset
</div>
