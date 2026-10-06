{{-- Navigation shared by every "My sales bot" page of an agent or seller, in the
     same tile language as the admin's bot section. --}}
@php
    $me = auth()->user();
    $base = $panel.'.shahbot';
    $myBot = \Modules\ShahBot\Models\BotInstance::query()->where('owner_user_id', $me->id)->first();
    $pending = $myBot ? \Modules\ShahBot\Models\BotPayment::query()
        ->where('status', \Modules\ShahBot\Models\BotPayment::PENDING)
        ->whereHas('botUser', fn ($q) => $q->where('bot_id', $myBot->id))->count() : 0;
    $tiles = array_values(array_filter([
        [$base.'.my-bot', 'bx-cog', 'my_bot_settings', [$base.'.my-bot'], 0],
        [$base.'.my-bot.plans', 'bx-package', 'my_plans', [$base.'.my-bot.plans'], 0],
        [$base.'.payments', 'bx-receipt', 'my_payments', [$base.'.payments*'], $pending],
        [$base.'.customers', 'bx-group', 'customers', [$base.'.customers'], 0],
        [$base.'.assign', 'bx-user-check', 'assign_accounts', [$base.'.assign*'], 0],
        $panel === 'agent' ? [$base.'.access', 'bx-key', 'bot_access_my_sellers', [$base.'.access*'], 0] : null,
    ]));
@endphp

<div class="sbp-hero">
    <div class="sbp-hero__icon"><i class="bx bxl-telegram"></i></div>
    <div class="sbp-hero__text">
        <h1>{{ __('shahbot::admin.my_bot') }}</h1>
        <p>{{ $myBot?->username ? '@'.$myBot->username : __('shahbot::admin.my_bot_no_username') }}
            @if ($myBot)
                · <span class="sbp-dot {{ $myBot->is_active && $myBot->token() !== '' ? 'is-on' : '' }}"></span>
                {{ $myBot->is_active && $myBot->token() !== '' ? __('shahbot::admin.bot_online') : __('shahbot::admin.bot_offline') }}
            @endif
        </p>
    </div>
    @if ($myBot?->username)
        <a class="sbp-hero__btn" href="https://t.me/{{ $myBot->username }}" target="_blank" rel="noopener"><i class="bx bx-link-external"></i> {{ __('shahbot::admin.open_bot') }}</a>
    @endif
</div>

<nav class="sbp-tiles">
    @foreach ($tiles as [$route, $icon, $label, $patterns, $badge])
        @continue(! \Illuminate\Support\Facades\Route::has($route))
        <a href="{{ route($route) }}" class="sbp-tile {{ request()->routeIs(...$patterns) ? 'is-active' : '' }}">
            <i class="bx {{ $icon }}"></i>
            <span>{{ __('shahbot::admin.'.$label) }}</span>
            @if ($badge > 0)<b class="sbp-badge">{{ persian_digits($badge) }}</b>@endif
        </a>
    @endforeach
</nav>

@once
<style>
    .sbp-hero{display:flex;align-items:center;gap:1rem;padding:1.15rem 1.25rem;margin-bottom:1rem;border-radius:20px;color:#fff;
        background:radial-gradient(120% 140% at 100% 0%,#5b8cff 0%,#4f46e5 45%,#312e81 100%);box-shadow:0 14px 34px -18px rgba(49,46,129,.7)}
    .sbp-hero__icon{width:52px;height:52px;flex:0 0 52px;border-radius:16px;display:grid;place-items:center;font-size:1.7rem;background:rgba(255,255,255,.16)}
    .sbp-hero__text{flex:1;min-width:0}.sbp-hero__text h1{font-size:1.15rem;font-weight:800;margin:0 0 .15rem;color:#fff}
    .sbp-hero__text p{margin:0;font-size:.85rem;opacity:.9;direction:ltr;text-align:start}
    .sbp-hero__btn{color:#312e81;background:#fff;border-radius:12px;padding:.5rem .85rem;font-size:.82rem;font-weight:700;text-decoration:none;white-space:nowrap}
    .sbp-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#fca5a5;margin-inline:.15rem}.sbp-dot.is-on{background:#86efac}
    .sbp-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:.6rem;margin-bottom:1.25rem}
    .sbp-tile{position:relative;display:flex;flex-direction:column;align-items:center;gap:.35rem;padding:.85rem .5rem;border-radius:16px;
        background:var(--bs-body-bg,#fff);border:1px solid rgba(99,102,241,.14);color:inherit;text-decoration:none;font-size:.84rem;font-weight:600;
        transition:transform .15s,box-shadow .15s,border-color .15s}
    .sbp-tile i{font-size:1.45rem;color:#4f46e5}.sbp-tile:hover{transform:translateY(-2px);box-shadow:0 10px 24px -16px rgba(79,70,229,.6)}
    .sbp-tile.is-active{background:linear-gradient(135deg,#4f46e5,#6d5ae6);border-color:transparent;color:#fff}.sbp-tile.is-active i{color:#fff}
    .sbp-badge{position:absolute;top:.45rem;inset-inline-end:.5rem;min-width:1.25rem;padding:0 .35rem;border-radius:999px;background:#ef4444;color:#fff;font-size:.7rem;line-height:1.25rem}
    /* Cards on these pages pick up the same softer, rounder look. */
    .sbp-page .card{border:1px solid rgba(99,102,241,.12);border-radius:18px;box-shadow:0 8px 26px -20px rgba(15,23,42,.45)}
    .sbp-page .card-body{padding:1.25rem}
    .sbp-section{display:flex;align-items:center;gap:.5rem;font-size:.95rem;font-weight:800;margin:0 0 .85rem}
    .sbp-section i{width:30px;height:30px;border-radius:10px;display:grid;place-items:center;background:rgba(79,70,229,.1);color:#4f46e5}
    @media (max-width:575.98px){.sbp-tiles{grid-template-columns:repeat(2,1fr)}.sbp-hero{flex-wrap:wrap}}
</style>
@endonce
