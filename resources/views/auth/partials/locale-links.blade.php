@php
    $locales = (array) config('locales.supported', []);
    $current = app()->getLocale();
@endphp

@if (count($locales) > 1)
    {{--
        فقط پرچم نشان داده می‌شود، بدون نام زبان. نام در aria-label و title
        می‌ماند تا صفحه‌خوان و راهنمای موس آن را داشته باشند.
    --}}
    <div class="auth-locale d-flex flex-wrap justify-content-center gap-2 mb-3" role="group"
         aria-label="{{ __('menu.language') }}">
        @foreach ($locales as $code => $meta)
            <a href="{{ route('locale.switch', $code) }}"
               title="{{ $meta['name'] }}"
               aria-label="{{ $meta['name'] }}"
               @if ($code === $current) aria-current="true" @endif
               class="auth-locale__item d-inline-flex align-items-center justify-content-center p-1 rounded"
               style="line-height:0;border:2px solid {{ $code === $current ? 'var(--bs-primary, #0d6efd)' : 'transparent' }};
                      opacity:{{ $code === $current ? '1' : '.65' }};transition:opacity .15s,border-color .15s;"
               onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='{{ $code === $current ? '1' : '.65' }}'">
                @include('layouts.partials.locale-flag', ['code' => $code, 'size' => 30])
            </a>
        @endforeach
    </div>
@endif
