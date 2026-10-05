@php
    use Illuminate\Support\Facades\Route;

    // The group is always there; a kind of agent that comes with a module only
    // appears while that module is on.
    $agentLinks = array_values(array_filter([
        ['route' => 'admin.users.index', 'label' => __('menu.agents_regular'), 'icon' => 'bx-user-pin', 'match' => ['admin.users.*']],
        module_active('dedicated') ? ['route' => 'admin.dedicated.index', 'label' => __('menu.agents_dedicated'), 'icon' => 'bx-server', 'match' => ['admin.dedicated.*']] : null,
        module_active('dedicated') ? ['route' => 'admin.inbound-agents.index', 'label' => __('menu.agents_inbound'), 'icon' => 'bx-transfer-alt', 'match' => ['admin.inbound-allocations.*', 'admin.inbound-agents.*']] : null,
    ], fn ($link): bool => is_array($link) && Route::has($link['route'])));

    $isOpen = collect($agentLinks)->contains(fn (array $link): bool => request()->routeIs(...$link['match']));
@endphp

@if ($agentLinks !== [])
    <li class="vp-nav__group" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
        <button type="button" @class(['vp-nav__link', 'is-active' => $isOpen])>
            <i class="bx bx-user-pin vp-nav__icon"></i>
            <span class="vp-nav__label">{{ __('menu.agents') }}</span>
            <i class="bx bx-chevron-left vp-nav__arrow"></i>
        </button>
        <ul class="vp-nav__sub" style="{{ $isOpen ? 'display:flex' : 'display:none' }}">
            @foreach ($agentLinks as $link)
                <li>
                    <a href="{{ route($link['route']) }}" @class(['vp-nav__link', 'is-active' => request()->routeIs(...$link['match'])])>
                        <i class="bx {{ $link['icon'] }} vp-nav__icon"></i>
                        <span class="vp-nav__label">{{ $link['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </li>
@endif
