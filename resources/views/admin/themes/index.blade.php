@extends('layouts.panel')

@section('page_title', __('themes.title'))

@section('panel_content')
<div class="vp-page-header">
    <div>
        <h2 class="vp-page-title"><i class="bx bx-palette"></i> {{ __('themes.title') }}</h2>
        <p class="text-muted mb-0">{{ __('themes.intro') }}</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.themes.update') }}" class="theme-picker">
    @csrf
    <div class="row g-3">
        @foreach ($themes as $theme)
            @php $isActive = $theme['slug'] === $active; @endphp
            <div class="col-md-6 col-xl-4">
                <label class="theme-card panel-modern-card h-100 mb-0 {{ $isActive ? 'theme-card--active' : '' }}">
                    <input type="radio" name="theme" value="{{ $theme['slug'] }}" class="theme-card__radio" @checked($isActive)>
                    <div class="theme-card__preview" style="background: {{ $theme['preview'] }};">
                        <span class="theme-card__preview-bar"></span>
                        <span class="theme-card__preview-side"></span>
                        <span class="theme-card__preview-tile"></span>
                        <span class="theme-card__preview-tile"></span>
                        <span class="theme-card__preview-tile theme-card__preview-tile--wide"></span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <h3 class="h5 mb-1">{{ $theme['name'] }}</h3>
                            @if ($isActive)
                                <span class="badge bg-success">{{ __('themes.active') }}</span>
                            @endif
                        </div>
                        <p class="text-muted small mb-2">{{ $theme['description'] !== '' ? __($theme['description']) : '' }}</p>
                        <div class="small text-muted">
                            @if ($theme['author'] !== '') {{ __('themes.by', ['author' => $theme['author']]) }} @endif
                            @if ($theme['version'] !== '') · {{ persian_digits($theme['version']) }} @endif
                        </div>
                    </div>
                </label>
            </div>
        @endforeach
    </div>

    <div class="vp-form-actions mt-3">
        <x-button type="submit">{{ __('themes.apply') }}</x-button>
    </div>

    <p class="text-muted small mt-3 mb-0"><i class="bx bx-info-circle"></i> {{ __('themes.modules_hint') }}</p>
</form>

@push('styles')
<style>
    .theme-card { display: block; cursor: pointer; overflow: hidden; border: 2px solid transparent; transition: border-color .15s, transform .15s, box-shadow .15s; }
    .theme-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .theme-card--active, .theme-card:has(.theme-card__radio:checked) { border-color: var(--accent); }
    .theme-card__radio { position: absolute; opacity: 0; pointer-events: none; }
    .theme-card__preview { position: relative; height: 150px; display: grid; grid-template-columns: 22% 1fr 1fr; grid-template-rows: 18px 1fr 1fr; gap: 8px; padding: 12px; }
    .theme-card__preview span { display: block; border-radius: 6px; background: rgba(255, 255, 255, .22); backdrop-filter: blur(2px); }
    .theme-card__preview-bar { grid-column: 1 / -1; }
    .theme-card__preview-side { grid-row: 2 / 4; background: rgba(255, 255, 255, .34) !important; }
    .theme-card__preview-tile--wide { grid-column: 2 / -1; }
</style>
@endpush
@endsection
