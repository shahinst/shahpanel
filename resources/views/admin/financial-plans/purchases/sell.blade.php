@extends('layouts.panel')

@section('page_title', __('financial_plans.sell_plan'))

@section('panel_content')
@php
    // طرح‌های مالی ستون ارز ندارند و رابطه‌ی wallet کاربر هم فقط کیف‌پول ارز پیش‌فرض را برمی‌گرداند.
    $planCurrency = \App\Enums\MoneyCurrency::default();
@endphp
<x-card>
    <p class="text-muted">{{ __('financial_plans.fifo_note') }}</p>
    @if (session('error'))
        <x-alert type="danger" class="mb-3">{{ session('error') }}</x-alert>
    @endif
    <form method="POST" action="{{ route('admin.agent-financial-plans.store') }}">
        @csrf
        <div class="row">
            <x-form.group :label="__('financial_plans.agent')" class="col-md-6">
                <select name="agent_id" class="form-control" required>
                    <option value="">{{ __('financial_plans.select_agent') }}</option>
                    @foreach ($agents as $agent)
                        <option value="{{ $agent->id }}" @selected(old('agent_id') == $agent->id)>
                            {{ $agent->full_name }} — {{ format_money($agent->wallet?->balance ?? 0, $planCurrency) }}
                        </option>
                    @endforeach
                </select>
            </x-form.group>
            <x-form.group :label="__('financial_plans.template')" class="col-md-6">
                <select name="template_id" class="form-control" required>
                    <option value="">{{ __('financial_plans.select_template') }}</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}" @selected(old('template_id') == $template->id)>
                            {{ $template->name }}
                            — {{ format_money($template->purchase_price, $planCurrency) }}
                            / {{ format_money($template->credit_amount, $planCurrency) }}
                            ({{ persian_digits(number_format((float) $template->discount_percent, 2)) }}٪)
                        </option>
                    @endforeach
                </select>
            </x-form.group>
            <x-form.actions>
                <x-button type="submit">{{ __('financial_plans.sell_plan') }}</x-button>
                <a href="{{ route('admin.agent-financial-plans.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
