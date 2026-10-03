@props(['href', 'label', 'icon' => 'bx-circle', 'active' => false, 'badge' => null])

<li>
    <a href="{{ $href }}" @class(['vp-nav__link', 'is-active' => $active])>
        <i class="bx {{ $icon }} vp-nav__icon"></i>
        <span class="vp-nav__label">{{ $label }}</span>
        @if ($badge)
            <span class="vp-nav__badge" style="margin-inline-start:auto;min-width:22px;height:20px;padding:0 6px;border-radius:999px;background:#ef4444;color:#fff;font-size:.72rem;font-weight:700;display:inline-grid;place-items:center;line-height:1;">{{ $badge }}</span>
        @endif
    </a>
</li>
