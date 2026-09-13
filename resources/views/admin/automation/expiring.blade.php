@extends('layouts.panel')

@section('page_title', 'آستانه‌ی اکانت‌های در حال انقضا')

@section('panel_content')
@php
    use App\Support\ExpiringAccountThresholds as T;
@endphp

@include('partials.panel-page-hero', [
    'title' => 'آستانه‌ی اکانت‌های در حال انقضا',
    'subtitle' => 'تعیین می‌کند چه اکانت‌هایی در فهرست «اکانت‌های در حال انقضا» دیده شوند',
    'icon' => 'bx-time-five',
])

<x-card>
    <div class="card-body">
        <p class="text-muted small">
            این مقادیر برای کل پنل اعمال می‌شوند: ادمین، نماینده‌ها و فروشنده‌ها همگی بر اساس همین آستانه،
            اکانت‌های خودشان را در فهرست <a href="{{ route('admin.accounts.expiring') }}">اکانت‌های در حال انقضا</a> می‌بینند.
            یک اکانت وقتی فهرست می‌شود که <strong>یا</strong> تا این تعداد روز منقضی شود، <strong>یا</strong> کمتر از این مقدار حجم داشته باشد.
        </p>

        <form method="POST" action="{{ route('admin.automation.expiring.update') }}">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-md-5">
                    <x-form.group label="هشدار انقضا (روز)"
                                  hint="بین {{ persian_digits(T::MIN_DAYS) }} تا {{ persian_digits(T::MAX_DAYS) }} روز — پیش‌فرض {{ persian_digits(T::DEFAULT_DAYS) }}">
                        <input type="number" name="days" dir="ltr" class="form-control"
                               min="{{ T::MIN_DAYS }}" max="{{ T::MAX_DAYS }}" step="1" required
                               value="{{ old('days', $days) }}">
                    </x-form.group>
                    @error('days') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-5">
                    <x-form.group label="هشدار حجم (مگابایت)"
                                  hint="بین {{ persian_digits(T::MIN_VOLUME_MB) }} مگ تا {{ persian_digits(T::MAX_VOLUME_MB) }} مگ (۵ گیگ) — پیش‌فرض {{ persian_digits(T::DEFAULT_VOLUME_MB) }} (۲ گیگ)">
                        <input type="number" name="volume_mb" dir="ltr" class="form-control"
                               min="{{ T::MIN_VOLUME_MB }}" max="{{ T::MAX_VOLUME_MB }}" step="1" required
                               value="{{ old('volume_mb', $volumeMb) }}">
                    </x-form.group>
                    @error('volume_mb') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <x-button type="submit" class="w-100"><i class="bx bx-save"></i> ذخیره</x-button>
                </div>
            </div>

            <p class="form-text text-muted small mb-0 mt-2">
                در حال حاضر: اکانت‌هایی که تا <strong>{{ persian_digits($days) }} روز</strong> آینده منقضی می‌شوند
                یا کمتر از <strong>{{ format_data_size($volumeMb * 1024 * 1024) }}</strong> حجم دارند.
            </p>
        </form>
    </div>
</x-card>
@endsection
