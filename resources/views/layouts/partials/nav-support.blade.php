@props(['panel', 'showDepartments' => false])

@php
    use Illuminate\Support\Facades\Route;

    $supportLinks = array_values(array_filter([
        Route::has("{$panel}.tickets.index")
            ? ['route' => "{$panel}.tickets.index", 'label' => __('tickets.page_title'), 'icon' => 'bx-support']
            : null,
        $showDepartments && Route::has("{$panel}.tickets.departments.index")
            ? ['route' => "{$panel}.tickets.departments.index", 'label' => __('tickets.departments'), 'icon' => 'bx-folder']
            : null,
    ]));

    $supportOpen = request()->routeIs("{$panel}.tickets.*");
@endphp

@if ($supportLinks !== [])
    <li class="vp-nav__group" aria-expanded="{{ $supportOpen ? 'true' : 'false' }}">
        <button type="button" @class(['vp-nav__link', 'is-active' => $supportOpen])>
            <i class="bx bx-headphone vp-nav__icon"></i>
            <span class="vp-nav__label">{{ __('tickets.page_title') }}</span>
            <i class="bx bx-chevron-left vp-nav__arrow"></i>
        </button>
        <ul class="vp-nav__sub" style="{{ $supportOpen ? 'display:flex' : 'display:none' }}">
            @foreach ($supportLinks as $link)
                @php
                    $base = preg_replace('/\.(index|edit|create|show|update|status|reply|escalate)$/', '', $link['route']);
                    $active = request()->routeIs($base.'.*') || request()->routeIs($link['route']);
                @endphp
                <li>
                    <a href="{{ route($link['route']) }}" @class(['vp-nav__link', 'is-active' => $active])>
                        <i class="bx {{ $link['icon'] }} vp-nav__icon"></i>
                        <span class="vp-nav__label">{{ $link['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </li>
@endif
