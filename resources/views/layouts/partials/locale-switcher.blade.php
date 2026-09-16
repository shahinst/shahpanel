@php
    $locales = (array) config('locales.supported', []);
    $current = app()->getLocale();
@endphp

@if (count($locales) > 1)
    <div class="dropdown">
        {{-- روی دکمه فقط پرچم زبان فعلی؛ نام زبان در title و aria-label است. --}}
        <button type="button" class="vp-topbar__btn d-inline-flex align-items-center justify-content-center"
                data-bs-toggle="dropdown" aria-expanded="false"
                aria-label="{{ __('menu.language') }}" title="{{ __('menu.language') }}">
            @include('layouts.partials.locale-flag', ['code' => $current, 'size' => 22])
        </button>
        <ul class="dropdown-menu dropdown-menu-end" style="min-width:11rem;">
            @foreach ($locales as $code => $meta)
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 {{ $code === $current ? 'active' : '' }}"
                       href="{{ route('locale.switch', $code) }}" dir="{{ $meta['dir'] }}">
                        @include('layouts.partials.locale-flag', ['code' => $code, 'size' => 20])
                        <span>{{ $meta['name'] }}</span>
                        @if ($code === $current)
                            <i class="bx bx-check ms-auto"></i>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
