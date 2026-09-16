@php
    $locales = (array) config('locales.supported', []);
    $current = app()->getLocale();
    $currentMeta = $locales[$current] ?? reset($locales);
@endphp

@if (count($locales) > 1)
    <div class="dropdown">
        <button type="button" class="vp-topbar__btn" data-bs-toggle="dropdown" aria-expanded="false"
                aria-label="{{ __('menu.language') }}" title="{{ __('menu.language') }}">
            <span aria-hidden="true">{{ $currentMeta['flag'] ?? '🌐' }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" style="min-width:11rem;">
            @foreach ($locales as $code => $meta)
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 {{ $code === $current ? 'active' : '' }}"
                       href="{{ route('locale.switch', $code) }}" dir="{{ $meta['dir'] }}">
                        <span aria-hidden="true">{{ $meta['flag'] }}</span>
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
