@extends('layouts.app')

@section('title', $storefront->brand_name)

@push('styles')
<style>
    .sf-page {
        --sf-accent: {{ $storefront->accentColor() }};
        --sf-accent-soft: color-mix(in srgb, var(--sf-accent) 14%, white);
        --sf-accent-glow: color-mix(in srgb, var(--sf-accent) 35%, transparent);
        min-height: 100vh;
        background:
            radial-gradient(ellipse 80% 50% at 50% -20%, var(--sf-accent-glow), transparent),
            linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
        color: #0f172a;
    }
    .sf-wrap { max-width: 56rem; margin: 0 auto; padding: 0 1.25rem 3rem; }
    .sf-header {
        display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
        gap: 1rem; padding: 1.75rem 0 1.25rem;
    }
    .sf-brand { display: flex; align-items: center; gap: 1rem; min-width: 0; }
    .sf-logo {
        width: 3.25rem; height: 3.25rem; border-radius: 1rem; object-fit: cover;
        background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 4px 14px rgba(15,23,42,.06);
    }
    .sf-logo--placeholder {
        display: grid; place-items: center; font-weight: 700; font-size: 1.25rem;
        color: var(--sf-accent); background: var(--sf-accent-soft);
    }
    .sf-title { margin: 0; font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; line-height: 1.2; }
    .sf-tagline { margin: .25rem 0 0; font-size: .9rem; color: #64748b; }
    .sf-hero {
        border-radius: 1.25rem; padding: 1.5rem 1.75rem; margin-bottom: 1.75rem;
        background: #fff; border: 1px solid #e2e8f0;
        box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 12px 40px rgba(15,23,42,.06);
    }
    .sf-hero p { margin: 0; line-height: 1.85; color: #475569; white-space: pre-line; }
    .sf-note {
        margin-top: 1rem; padding: .85rem 1rem; border-radius: .75rem;
        background: var(--sf-accent-soft); border-right: 3px solid var(--sf-accent);
        font-size: .875rem; color: #334155; line-height: 1.7;
    }
    .sf-section-title {
        font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
        color: #94a3b8; margin: 0 0 1rem;
    }
    .sf-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
    .sf-card {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem;
        padding: 1.25rem 1.35rem; display: flex; flex-direction: column; gap: .75rem;
        transition: border-color .2s, box-shadow .2s, transform .2s;
    }
    .sf-card:hover {
        border-color: color-mix(in srgb, var(--sf-accent) 40%, #e2e8f0);
        box-shadow: 0 8px 30px rgba(15,23,42,.08); transform: translateY(-2px);
    }
    .sf-card__head { display: flex; justify-content: space-between; align-items: flex-start; gap: .5rem; }
    .sf-card__name { margin: 0; font-size: 1.05rem; font-weight: 700; }
    .sf-badge {
        font-size: .7rem; font-weight: 600; padding: .2rem .55rem; border-radius: 999px;
        background: var(--sf-accent-soft); color: var(--sf-accent); white-space: nowrap;
    }
    .sf-badge--muted { background: #f1f5f9; color: #64748b; }
    .sf-meta { font-size: .8rem; color: #64748b; margin: 0; }
    .sf-prices { list-style: none; margin: 0; padding: 0; }
    .sf-prices li {
        display: flex; justify-content: space-between; align-items: center; gap: .75rem;
        padding: .55rem 0; border-bottom: 1px solid #f1f5f9; font-size: .875rem;
    }
    .sf-prices li:last-child { border-bottom: 0; }
    .sf-price { font-weight: 700; color: var(--sf-accent); white-space: nowrap; }
    .sf-empty {
        text-align: center; padding: 3rem 1.5rem; border-radius: 1rem;
        background: #fff; border: 1px dashed #cbd5e1; color: #64748b;
    }
    .sf-contact {
        margin-top: 2.5rem; padding: 1.5rem; border-radius: 1.25rem;
        background: #0f172a; color: #e2e8f0; text-align: center;
    }
    .sf-contact h2 { margin: 0 0 1rem; font-size: 1rem; font-weight: 600; color: #f8fafc; }
    .sf-contact-chips { display: flex; flex-wrap: wrap; justify-content: center; gap: .5rem; margin-top: 1rem; }
    .sf-chip {
        display: inline-flex; align-items: center; gap: .35rem; padding: .4rem .85rem;
        border-radius: 999px; background: rgba(255,255,255,.08); color: #f1f5f9;
        font-size: .8rem; text-decoration: none; transition: background .15s;
    }
    .sf-chip:hover { background: rgba(255,255,255,.16); color: #fff; }
    .sf-social { display: flex; gap: .65rem; justify-content: center; flex-wrap: wrap; }
    .sf-social__btn {
        width: 2.75rem; height: 2.75rem; border-radius: 50%; display: grid; place-items: center;
        background: rgba(255,255,255,.1); color: #fff; transition: transform .15s, background .15s;
    }
    .sf-social__btn svg { width: 1.35rem; height: 1.35rem; }
    .sf-social__btn:hover { transform: scale(1.08); color: #fff; }
    .sf-social__btn--telegram:hover { background: #229ed9; }
    .sf-social__btn--instagram:hover { background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); }
    .sf-social__btn--whatsapp:hover { background: #25d366; }
    .sf-footer { text-align: center; margin-top: 2rem; font-size: .75rem; color: #94a3b8; }
</style>
@endpush

@section('content')
@php
    $accent = $storefront->accentColor();
    $initial = mb_substr($storefront->brand_name, 0, 1);
@endphp
<div class="sf-page">
    <div class="sf-wrap">
        <header class="sf-header">
            <div class="sf-brand">
                @if ($storefront->logo_path)
                    <img src="{{ $storefront->logo_path }}" alt="{{ $storefront->brand_name }}" class="sf-logo" loading="lazy">
                @else
                    <div class="sf-logo sf-logo--placeholder" aria-hidden="true">{{ $initial }}</div>
                @endif
                <div>
                    <h1 class="sf-title" style="color: {{ $accent }}">{{ $storefront->brand_name }}</h1>
                    @if ($storefront->tagline)
                        <p class="sf-tagline">{{ $storefront->tagline }}</p>
                    @endif
                </div>
            </div>
            @include('storefront.partials.social-icons', ['storefront' => $storefront])
        </header>

        @if ($storefront->description || $storefront->support_note)
            <section class="sf-hero">
                @if ($storefront->description)
                    <p>{{ $storefront->description }}</p>
                @endif
                @if ($storefront->support_note)
                    <div class="sf-note">{{ $storefront->support_note }}</div>
                @endif
            </section>
        @endif

        <section>
            <h2 class="sf-section-title">{{ __('storefront.packages_title') }}</h2>

            @if ($catalog->isEmpty())
                <div class="sf-empty">{{ __('storefront.packages_empty') }}</div>
            @else
                <div class="sf-grid">
                    @foreach ($catalog as $packageRows)
                        @php
                            $package = $packageRows->first()['package'];
                            $isElastic = $package->isElastic();
                        @endphp
                        <article class="sf-card">
                            <div class="sf-card__head">
                                <h3 class="sf-card__name">{{ $package->name }}</h3>
                                @if ($package->service_type)
                                    <span class="sf-badge">{{ $package->service_type->label() }}</span>
                                @endif
                            </div>

                            @if ($package->category)
                                <p class="sf-meta">{{ $package->category->name }}</p>
                            @endif

                            @if ($isElastic && $package->min_data_gb && $package->max_data_gb)
                                <p class="sf-meta">
                                    {{ __('storefront.elastic_range') }}:
                                    {{ persian_digits(rtrim(rtrim(number_format((float) $package->min_data_gb, 2, '.', ''), '0'), '.')) }}
                                    –
                                    {{ persian_digits(rtrim(rtrim(number_format((float) $package->max_data_gb, 2, '.', ''), '0'), '.')) }}
                                    {{ __('storefront.gb_unit') }}
                                </p>
                            @elseif ($package->isUnlimited())
                                <p class="sf-meta">{{ __('storefront.unlimited_data') }}</p>
                            @elseif ($package->data_limit_gb)
                                <p class="sf-meta">{{ __('storefront.data_limit') }}: {{ persian_digits($package->data_limit_gb) }} GB</p>
                            @endif

                            <ul class="sf-prices">
                                @foreach ($packageRows as $row)
                                    @php
                                        $duration = $row['duration'];
                                        $priceLabel = $isElastic
                                            ? format_money($row['display_price'], $package->moneyCurrency()).' '.__('storefront.per_gb')
                                            : format_money($row['display_price'], $package->moneyCurrency());
                                    @endphp
                                    <li>
                                        <span>
                                            {{ $duration->displayLabel() }}
                                            @if ($duration->tier->isTest())
                                                <span class="sf-badge sf-badge--muted">{{ __('packages.test_badge') }}</span>
                                            @endif
                                        </span>
                                        <span class="sf-price">{{ $priceLabel }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($isElastic)
                                <p class="sf-meta" style="margin-top:auto">{{ __('storefront.elastic_hint') }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($storefront->hasContactChannels())
            <section class="sf-contact">
                <h2>{{ __('storefront.contact_us') }}</h2>
                @include('storefront.partials.social-icons', ['storefront' => $storefront])
                <div class="sf-contact-chips">
                    @if ($storefront->phone_contact && $storefront->phoneUrl())
                        <a href="{{ $storefront->phoneUrl() }}" class="sf-chip">{{ persian_digits($storefront->phone_contact) }}</a>
                    @endif
                    @if ($storefront->email_contact)
                        <a href="mailto:{{ $storefront->email_contact }}" class="sf-chip">{{ $storefront->email_contact }}</a>
                    @endif
                    @if ($storefront->website_url)
                        <a href="{{ $storefront->website_url }}" class="sf-chip" target="_blank" rel="noopener">{{ parse_url($storefront->website_url, PHP_URL_HOST) ?: $storefront->website_url }}</a>
                    @endif
                </div>
            </section>
        @endif

        <p class="sf-footer">{{ $storefront->brand_name }}</p>
    </div>
</div>
@endsection
