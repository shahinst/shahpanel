{{--
    Icon-only row action with its name in a tooltip, so every action column
    reads the same. A link when `href` is given, otherwise a small form that
    posts to `action` (with `method` spoofing and an optional `confirm`).
--}}
@props([
    'icon',
    'label',
    'href' => null,
    'action' => null,
    'method' => 'POST',
    'variant' => 'light',
    'confirm' => null,
])
@php
    $classes = 'icon-action icon-action--'.$variant;
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }} data-tip="{{ $label }}" title="{{ $label }}" aria-label="{{ $label }}">
        <i class="bx {{ $icon }}" aria-hidden="true"></i>
    </a>
@else
    <form method="POST" action="{{ $action }}" class="icon-action-form"
          @if ($confirm) onsubmit="return confirm(@js($confirm))" @endif>
        @csrf
        @if (strtoupper($method) !== 'POST')
            @method($method)
        @endif
        <button type="submit" {{ $attributes->merge(['class' => $classes]) }} data-tip="{{ $label }}" title="{{ $label }}" aria-label="{{ $label }}">
            <i class="bx {{ $icon }}" aria-hidden="true"></i>
        </button>
    </form>
@endif
@once
    <style>
        .icon-actions { display: inline-flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
        .icon-action-form { display: inline-flex; margin: 0; }
        .icon-action { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0; border-radius: 8px; border: 1px solid transparent; font-size: 1.05rem; line-height: 1; cursor: pointer; text-decoration: none; transition: transform .12s ease, filter .12s ease; }
        .icon-action:hover { transform: translateY(-1px); filter: brightness(.95); text-decoration: none; }
        .icon-action:focus-visible { outline: 2px solid #1668dc; outline-offset: 2px; }
        .icon-action--light { background: #eef2f7; color: #334155; border-color: #dde3ec; }
        .icon-action--primary { background: #e7f0ff; color: #1668dc; border-color: #cfe0ff; }
        .icon-action--info { background: #e6f7fb; color: #0e7490; border-color: #c8edf5; }
        .icon-action--warning { background: #fff5e0; color: #b45309; border-color: #fde7b8; }
        .icon-action--danger { background: #fde8e8; color: #c62828; border-color: #f9cfcf; }
        .icon-action--success { background: #e7f6ec; color: #15803d; border-color: #c9ecd5; }
        /* Tooltip: the action's name, shown on hover and keyboard focus. */
        .icon-action[data-tip]::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%) translateY(4px); white-space: nowrap; background: #1f2937; color: #fff; font-size: .75rem; font-weight: 500; line-height: 1.3; padding: 5px 9px; border-radius: 6px; opacity: 0; pointer-events: none; transition: opacity .12s ease, transform .12s ease; z-index: 30; }
        .icon-action[data-tip]::before { content: ''; position: absolute; bottom: calc(100% + 2px); left: 50%; transform: translateX(-50%); border: 5px solid transparent; border-top-color: #1f2937; opacity: 0; pointer-events: none; transition: opacity .12s ease; z-index: 30; }
        .icon-action[data-tip]:hover::after, .icon-action[data-tip]:focus-visible::after { opacity: 1; transform: translateX(-50%) translateY(0); }
        .icon-action[data-tip]:hover::before, .icon-action[data-tip]:focus-visible::before { opacity: 1; }
    </style>
@endonce
