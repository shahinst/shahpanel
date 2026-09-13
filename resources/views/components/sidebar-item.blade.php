@props(['href', 'label', 'icon' => 'bx-circle', 'active' => false])

<li>
    <a href="{{ $href }}" @class(['vp-nav__link', 'is-active' => $active])>
        <i class="bx {{ $icon }} vp-nav__icon"></i>
        <span class="vp-nav__label">{{ $label }}</span>
    </a>
</li>
