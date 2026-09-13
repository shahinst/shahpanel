@php
    $routePanel = explode('.', request()->route()?->getName() ?? '')[0] ?? 'admin';
    $heroTone = $tone ?? (in_array($routePanel, ['admin', 'agent', 'seller'], true) ? $routePanel : 'slate');
@endphp

<div class="panel-page-hero panel-page-hero--{{ $heroTone }}">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 position-relative" style="z-index:1;">
        <div class="d-flex align-items-start gap-3">
            @if (! empty($icon))
                <div class="hero-icon"><i class="bx {{ $icon }}"></i></div>
            @endif
            <div>
                <h2>{{ $title }}</h2>
                @if (! empty($subtitle))
                    <p>{{ $subtitle }}</p>
                @endif
            </div>
        </div>
        @if (! empty($actions))
            <div class="hero-actions d-flex flex-wrap gap-2">
                {!! $actions !!}
            </div>
        @endif
    </div>
</div>
