@extends('layouts.app')

@section('title', config('app.name'))

@section('content')
    <div class="flex min-h-full flex-col items-center justify-center px-4 py-16">
        <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-bold text-slate-900">{{ config('app.name') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('app.tagline') }}</p>
                                </div>

            @if (! vpnpanel_installed())
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ __('app.install_required') }}
                    <span class="mr-1">نصب از راه SSH انجام می‌شود: <code>php artisan install:finalize</code></span>
                </div>
            @else
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {{ __('app.welcome') }} — فاز ۰ با موفقیت راه‌اندازی شد.
                </div>
            @endif
        </div>
    </div>
@endsection
