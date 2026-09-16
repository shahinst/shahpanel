@php
    $locales = (array) config('locales.supported', []);
    $current = app()->getLocale();
@endphp

@if (count($locales) > 1)
    <div class="auth-locale d-flex flex-wrap justify-content-center gap-2 mb-3">
        @foreach ($locales as $code => $meta)
            <a href="{{ route('locale.switch', $code) }}" dir="{{ $meta['dir'] }}"
               class="btn btn-sm {{ $code === $current ? 'btn-primary' : 'btn-outline-secondary' }}">
                <span aria-hidden="true">{{ $meta['flag'] }}</span> {{ $meta['name'] }}
            </a>
        @endforeach
    </div>
@endif
