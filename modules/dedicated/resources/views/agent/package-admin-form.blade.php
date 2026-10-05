@extends('layouts.panel')

@section('page_title', $package ? __('dedicated::admin.edit_package') : __('dedicated::admin.new_package'))

@push('styles')
<style>
    .dpk { --dpk-accent: #6366f1; --dpk-accent2: #8b5cf6; --dpk-line: rgba(99, 102, 241, .14); }

    .dpk-hero {
        position: relative; overflow: hidden; border-radius: 22px; padding: 1.6rem 1.75rem;
        margin-bottom: 1.25rem; color: #fff;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 52%, #0ea5e9 100%);
        box-shadow: 0 22px 44px -26px rgba(79, 70, 229, .75);
    }
    .dpk-hero::before, .dpk-hero::after {
        content: ""; position: absolute; border-radius: 50%; pointer-events: none;
        background: radial-gradient(circle, rgba(255,255,255,.22), transparent 70%);
    }
    .dpk-hero::before { width: 260px; height: 260px; inset-inline-end: -70px; top: -110px; }
    .dpk-hero::after { width: 180px; height: 180px; inset-inline-start: 18%; bottom: -120px; }
    .dpk-hero__row { position: relative; z-index: 1; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
    .dpk-hero__icon {
        width: 58px; height: 58px; flex: 0 0 58px; border-radius: 18px; display: grid; place-items: center;
        background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28); font-size: 1.75rem;
        backdrop-filter: blur(6px);
    }
    .dpk-hero__title { margin: 0; font-size: 1.35rem; font-weight: 800; letter-spacing: -.01em; }
    .dpk-hero__text { margin: .25rem 0 0; opacity: .88; font-size: .9rem; line-height: 1.8; max-width: 40rem; }
    .dpk-hero__back { margin-inline-start: auto; }
    .dpk-hero__back .btn {
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.3); color: #fff;
        border-radius: 12px; padding: .5rem .95rem; font-weight: 600;
    }
    .dpk-hero__back .btn:hover { background: rgba(255,255,255,.24); color: #fff; }
    .dpk-chips { position: relative; z-index: 1; display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
    .dpk-chip {
        display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .7rem; border-radius: 999px;
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.22); font-size: .78rem; font-weight: 600;
    }

    .dpk-card {
        border: 1px solid var(--dpk-line); border-radius: 20px; background: var(--bs-body-bg, #fff);
        box-shadow: 0 16px 40px -30px rgba(30, 41, 99, .45); padding: 1.5rem 1.5rem 1rem;
    }

    /* The admin's form, dressed for this page only. */
    .dpk .form-label, .dpk label.form-label { font-weight: 700; font-size: .86rem; margin-bottom: .4rem; }
    .dpk .form-control, .dpk .form-select {
        border-radius: 12px; border-color: rgba(100, 116, 139, .28); padding: .62rem .85rem;
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .dpk .form-control:focus, .dpk .form-select:focus {
        border-color: var(--dpk-accent); box-shadow: 0 0 0 4px rgba(99, 102, 241, .14);
    }
    .dpk .form-text { font-size: .78rem; }
    .dpk .form-check-input { width: 1.15em; height: 1.15em; cursor: pointer; }
    .dpk .form-check-input:checked { background-color: var(--dpk-accent); border-color: var(--dpk-accent); }
    .dpk .form-check { padding-block: .15rem; }
    .dpk .table { --bs-table-bg: transparent; }
    .dpk .table thead th {
        font-size: .76rem; text-transform: none; color: #64748b; font-weight: 700;
        border-bottom: 1px solid var(--dpk-line);
    }
    .dpk .table td { vertical-align: middle; border-color: var(--dpk-line); }
    .dpk .card, .dpk .border.rounded {
        border-radius: 16px !important; border-color: var(--dpk-line) !important; box-shadow: none;
    }
    .dpk hr { border-color: var(--dpk-line); opacity: 1; }

    .dpk-bar {
        position: sticky; bottom: 0; z-index: 5; display: flex; gap: .6rem; align-items: center; flex-wrap: wrap;
        margin: 1.25rem -1.5rem -1rem; padding: .9rem 1.5rem; border-top: 1px solid var(--dpk-line);
        border-radius: 0 0 20px 20px; background: rgba(255,255,255,.88); backdrop-filter: blur(10px);
    }
    [data-bs-theme="dark"] .dpk-bar { background: rgba(15, 23, 42, .82); }
    .dpk-bar__note { color: #64748b; font-size: .8rem; display: inline-flex; gap: .35rem; align-items: center; }
    .dpk-bar__actions { margin-inline-start: auto; display: flex; gap: .5rem; }
    .dpk-save {
        border: 0; border-radius: 12px; padding: .62rem 1.35rem; font-weight: 700; color: #fff;
        background: linear-gradient(135deg, var(--dpk-accent), var(--dpk-accent2));
        box-shadow: 0 12px 24px -14px rgba(99, 102, 241, .9); transition: filter .15s ease, transform .15s ease;
    }
    .dpk-save:hover { filter: brightness(1.07); color: #fff; }
    .dpk-save:active { transform: translateY(1px); }
    .dpk-cancel { border-radius: 12px; padding: .62rem 1.1rem; font-weight: 600; }

    @media (max-width: 575.98px) {
        .dpk-hero { padding: 1.25rem; border-radius: 18px; }
        .dpk-hero__back { margin-inline-start: 0; width: 100%; }
        .dpk-card { padding: 1.1rem 1rem .75rem; }
        .dpk-bar { margin-inline: -1rem; padding-inline: 1rem; }
        .dpk-bar__actions { width: 100%; }
        .dpk-bar__actions > * { flex: 1; text-align: center; }
    }
    @media (prefers-reduced-motion: reduce) {
        .dpk * { transition: none !important; }
    }
</style>
@endpush

@section('panel_content')
<div class="dpk">
    <section class="dpk-hero">
        <div class="dpk-hero__row">
            <div class="dpk-hero__icon"><i class="bx {{ $package ? 'bx-edit-alt' : 'bx-package' }}"></i></div>
            <div>
                <h1 class="dpk-hero__title">{{ $package ? __('dedicated::admin.edit_package').' — '.$package->name : __('dedicated::admin.new_package') }}</h1>
                <p class="dpk-hero__text">{{ __('dedicated::admin.package_page_intro') }}</p>
            </div>
            <div class="dpk-hero__back">
                <a href="{{ url()->previous() }}" class="btn"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</a>
            </div>
        </div>
        <div class="dpk-chips">
            <span class="dpk-chip"><i class="bx bx-server"></i> {{ __('dedicated::admin.package_chip_servers', ['count' => persian_digits($servers->count())]) }}</span>
            <span class="dpk-chip"><i class="bx bx-lock-alt"></i> {{ __('dedicated::admin.package_chip_private') }}</span>
            <span class="dpk-chip"><i class="bx bx-wallet"></i> {{ __('dedicated::admin.package_chip_prices') }}</span>
        </div>
    </section>

    <div class="dpk-card">
        {{-- The admin's own package form; the controller hands it only this agent's servers and inbounds. --}}
        <form method="POST" action="{{ $package ? route('agent.dedicated.packages.update', $package) : route('agent.dedicated.packages.store') }}">
            @csrf
            @if ($package)
                @method('PUT')
            @endif
            <div class="row">
                @include('admin.packages._form', ['servers' => $servers, 'package' => $package, 'hideKyc' => true])
            </div>
            <div class="dpk-bar">
                <span class="dpk-bar__note"><i class="bx bx-info-circle"></i> {{ __('dedicated::admin.package_save_note') }}</span>
                <div class="dpk-bar__actions">
                    <a href="{{ url()->previous() }}" class="btn btn-light dpk-cancel">{{ __('app.cancel') }}</a>
                    <button type="submit" class="dpk-save"><i class="bx bx-check-circle"></i> {{ __('app.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
