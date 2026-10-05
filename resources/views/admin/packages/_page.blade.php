{{--
    The package editor's page: a hero, the shared package form, and a save bar
    that stays in reach on a long form. The admin and the special agents both
    build packages here, so the look lives in one place and only the words,
    the chips and the form's target differ.

    Expects: $title, $intro, $icon, $chips (list of [icon, text]), $action,
    $isEdit, $backUrl, $saveNote, $form (the variables for admin.packages._form).
--}}
@push('styles')
<style>
    .pkp { --pkp-accent: #6366f1; --pkp-accent2: #8b5cf6; --pkp-line: rgba(99, 102, 241, .14); }

    .pkp-hero {
        position: relative; overflow: hidden; border-radius: 22px; padding: 1.6rem 1.75rem;
        margin-bottom: 1.25rem; color: #fff;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 52%, #0ea5e9 100%);
        box-shadow: 0 22px 44px -26px rgba(79, 70, 229, .75);
    }
    .pkp-hero::before, .pkp-hero::after {
        content: ""; position: absolute; border-radius: 50%; pointer-events: none;
        background: radial-gradient(circle, rgba(255,255,255,.22), transparent 70%);
    }
    .pkp-hero::before { width: 260px; height: 260px; inset-inline-end: -70px; top: -110px; }
    .pkp-hero::after { width: 180px; height: 180px; inset-inline-start: 18%; bottom: -120px; }
    .pkp-hero__row { position: relative; z-index: 1; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
    .pkp-hero__icon {
        width: 58px; height: 58px; flex: 0 0 58px; border-radius: 18px; display: grid; place-items: center;
        background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28); font-size: 1.75rem;
        backdrop-filter: blur(6px);
    }
    .pkp-hero__title { margin: 0; font-size: 1.35rem; font-weight: 800; letter-spacing: -.01em; }
    .pkp-hero__text { margin: .25rem 0 0; opacity: .88; font-size: .9rem; line-height: 1.8; max-width: 40rem; }
    .pkp-hero__back { margin-inline-start: auto; }
    .pkp-hero__back .btn {
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.3); color: #fff;
        border-radius: 12px; padding: .5rem .95rem; font-weight: 600;
    }
    .pkp-hero__back .btn:hover { background: rgba(255,255,255,.24); color: #fff; }
    .pkp-chips { position: relative; z-index: 1; display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
    .pkp-chip {
        display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .7rem; border-radius: 999px;
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.22); font-size: .78rem; font-weight: 600;
    }

    .pkp-card {
        border: 1px solid var(--pkp-line); border-radius: 20px; background: var(--bs-body-bg, #fff);
        box-shadow: 0 16px 40px -30px rgba(30, 41, 99, .45); padding: 1.5rem 1.5rem 1rem;
    }

    .pkp .form-label, .pkp label.form-label { font-weight: 700; font-size: .86rem; margin-bottom: .4rem; }
    .pkp .form-control, .pkp .form-select {
        border-radius: 12px; border-color: rgba(100, 116, 139, .28); padding: .62rem .85rem;
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .pkp .form-control:focus, .pkp .form-select:focus {
        border-color: var(--pkp-accent); box-shadow: 0 0 0 4px rgba(99, 102, 241, .14);
    }
    .pkp .form-text { font-size: .78rem; }
    .pkp .form-check-input { width: 1.15em; height: 1.15em; cursor: pointer; }
    .pkp .form-check-input:checked { background-color: var(--pkp-accent); border-color: var(--pkp-accent); }
    .pkp .form-check { padding-block: .15rem; }
    .pkp .table { --bs-table-bg: transparent; }
    .pkp .table thead th {
        font-size: .76rem; text-transform: none; color: #64748b; font-weight: 700;
        border-bottom: 1px solid var(--pkp-line);
    }
    .pkp .table td { vertical-align: middle; border-color: var(--pkp-line); }
    .pkp .card, .pkp .border.rounded {
        border-radius: 16px !important; border-color: var(--pkp-line) !important; box-shadow: none;
    }
    .pkp hr { border-color: var(--pkp-line); opacity: 1; }

    .pkp-bar {
        position: sticky; bottom: 0; z-index: 5; display: flex; gap: .6rem; align-items: center; flex-wrap: wrap;
        margin: 1.25rem -1.5rem -1rem; padding: .9rem 1.5rem; border-top: 1px solid var(--pkp-line);
        border-radius: 0 0 20px 20px; background: rgba(255,255,255,.88); backdrop-filter: blur(10px);
    }
    [data-bs-theme="dark"] .pkp-bar { background: rgba(15, 23, 42, .82); }
    .pkp-bar__note { color: #64748b; font-size: .8rem; display: inline-flex; gap: .35rem; align-items: center; }
    .pkp-bar__actions { margin-inline-start: auto; display: flex; gap: .5rem; }
    .pkp-save {
        border: 0; border-radius: 12px; padding: .62rem 1.35rem; font-weight: 700; color: #fff;
        background: linear-gradient(135deg, var(--pkp-accent), var(--pkp-accent2));
        box-shadow: 0 12px 24px -14px rgba(99, 102, 241, .9); transition: filter .15s ease, transform .15s ease;
    }
    .pkp-save:hover { filter: brightness(1.07); color: #fff; }
    .pkp-save:active { transform: translateY(1px); }
    .pkp-cancel { border-radius: 12px; padding: .62rem 1.1rem; font-weight: 600; }

    @media (max-width: 575.98px) {
        .pkp-hero { padding: 1.25rem; border-radius: 18px; }
        .pkp-hero__back { margin-inline-start: 0; width: 100%; }
        .pkp-card { padding: 1.1rem 1rem .75rem; }
        .pkp-bar { margin-inline: -1rem; padding-inline: 1rem; }
        .pkp-bar__actions { width: 100%; }
        .pkp-bar__actions > * { flex: 1; text-align: center; }
    }
    @media (prefers-reduced-motion: reduce) {
        .pkp * { transition: none !important; }
    }
</style>
@endpush

<div class="pkp">
    <section class="pkp-hero">
        <div class="pkp-hero__row">
            <div class="pkp-hero__icon"><i class="bx {{ $icon }}"></i></div>
            <div>
                <h1 class="pkp-hero__title">{{ $title }}</h1>
                <p class="pkp-hero__text">{{ $intro }}</p>
            </div>
            <div class="pkp-hero__back">
                <a href="{{ $backUrl }}" class="btn"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</a>
            </div>
        </div>
        @if (! empty($chips))
            <div class="pkp-chips">
                @foreach ($chips as [$chipIcon, $chipText])
                    <span class="pkp-chip"><i class="bx {{ $chipIcon }}"></i> {{ $chipText }}</span>
                @endforeach
            </div>
        @endif
    </section>

    <div class="pkp-card">
        <form method="POST" action="{{ $action }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif
            <div class="row">
                @include('admin.packages._form', $form)
            </div>
            <div class="pkp-bar">
                <span class="pkp-bar__note"><i class="bx bx-info-circle"></i> {{ $saveNote }}</span>
                <div class="pkp-bar__actions">
                    <a href="{{ $backUrl }}" class="btn btn-light pkp-cancel">{{ __('app.cancel') }}</a>
                    <button type="submit" class="pkp-save"><i class="bx bx-check-circle"></i> {{ __('app.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
