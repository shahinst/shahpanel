{{-- The author's links and the panel version, for the footer and the menu. --}}
@php
    $authorLinks = (array) config('shahpanel.author_links', []);
    $panelVersion = app(\App\Services\PanelVersionService::class)->current();
    $variant = $variant ?? 'footer';
@endphp
<div class="author-links author-links--{{ $variant }}">
    @if ($variant === 'auth')
        <div class="author-links__brand">{{ __('updates.product_name') }}</div>
    @endif
    <div class="author-links__icons">
        @if (! empty($authorLinks['github']))
            <a href="{{ $authorLinks['github'] }}" target="_blank" rel="noopener" title="GitHub" aria-label="GitHub">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 .5a11.5 11.5 0 0 0-3.64 22.41c.58.1.79-.25.79-.56v-2c-3.2.7-3.88-1.37-3.88-1.37-.53-1.33-1.29-1.69-1.29-1.69-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.71 1.26 3.37.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.69 0-1.26.45-2.29 1.19-3.09-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.17 1.18a11 11 0 0 1 5.77 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.8 1.19 1.83 1.19 3.09 0 4.42-2.7 5.39-5.26 5.68.41.36.78 1.06.78 2.14v3.17c0 .31.21.67.8.56A11.5 11.5 0 0 0 12 .5Z"/></svg>
            </a>
        @endif
        @if (! empty($authorLinks['telegram']))
            <a href="{{ $authorLinks['telegram'] }}" target="_blank" rel="noopener" title="Telegram" aria-label="Telegram">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M21.94 4.3 18.7 19.6c-.24 1.07-.88 1.34-1.78.83l-4.93-3.63-2.38 2.29c-.26.26-.48.48-.99.48l.35-5.02 9.14-8.26c.4-.35-.09-.55-.62-.2L6.2 13.2 1.33 11.68c-1.06-.33-1.08-1.06.22-1.57L20.6 2.77c.88-.33 1.65.2 1.34 1.53Z"/></svg>
            </a>
        @endif
        @if (! empty($authorLinks['youtube']))
            <a href="{{ $authorLinks['youtube'] }}" target="_blank" rel="noopener" title="YouTube" aria-label="YouTube">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2 31.3 31.3 0 0 0 0 12a31.3 31.3 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1A31.3 31.3 0 0 0 24 12a31.3 31.3 0 0 0-.5-5.8ZM9.6 15.6V8.4l6.3 3.6-6.3 3.6Z"/></svg>
            </a>
        @endif
    </div>
    <div class="author-links__version" dir="ltr">{{ __('updates.version_label', ['version' => $panelVersion]) }}</div>
</div>
@once
<style>
    .author-links { display: flex; align-items: center; gap: 6px 14px; flex-wrap: wrap; }
    .author-links__icons { display: flex; gap: 10px; flex-wrap: wrap; }
    .author-links a { display: inline-flex; align-items: center; gap: 6px; color: inherit; opacity: .75; text-decoration: none; transition: opacity .15s; }
    .author-links a:hover { opacity: 1; }
    .author-links__version { font-size: .8rem; opacity: .7; font-variant-numeric: tabular-nums; }
    .author-links--menu { flex-direction: column; align-items: center; text-align: center; padding: 14px 18px 18px; border-top: 1px solid rgba(127, 127, 127, .2); margin-top: 8px; }
    .author-links--menu .author-links__icons { justify-content: center; gap: 18px; }
    .author-links--menu a svg { width: 20px; height: 20px; }
    .author-links--menu .author-links__version { margin-top: 8px; }
    .author-links--footer { justify-content: space-between; }
    .author-links--auth { flex-direction: column; align-items: center; text-align: center; gap: 8px; padding: 22px 0 18px; color: #64748b; }
    .author-links--auth .author-links__brand { font-weight: 800; font-size: 1rem; color: #334155; letter-spacing: .2px; }
    .author-links--auth .author-links__icons { justify-content: center; gap: 20px; }
    .author-links--auth a svg { width: 22px; height: 22px; }
</style>
@endonce
