@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_codes'))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-filters">
    <a href="{{ route('admin.shahbot.codes.index', ['kind' => 'discount']) }}" @class(['is-active' => $kind === 'discount'])>{{ __('shahbot::admin.discount_codes') }}</a>
    <a href="{{ route('admin.shahbot.codes.index', ['kind' => 'gift']) }}" @class(['is-active' => $kind === 'gift'])>{{ __('shahbot::admin.gift_codes') }}</a>
</div>

<div class="sb-box">
    <header>{{ __('shahbot::admin.new_code') }}</header>
    <div class="sb-body">
        <p class="sb-muted">{{ $kind === 'gift' ? __('shahbot::admin.gift_hint') : __('shahbot::admin.discount_hint') }}</p>
        <form method="POST" action="{{ route('admin.shahbot.codes.store') }}" class="row g-2 align-items-end">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <div class="col-md-2">
                <label class="form-label">{{ __('shahbot::admin.code') }}</label>
                <input type="text" name="code" value="{{ old('code') }}" class="form-control" dir="ltr" placeholder="AUTO" title="{{ __('shahbot::admin.code_hint') }}">
            </div>
            @if ($kind === 'discount')
                <div class="col-md-2">
                    <label class="form-label">{{ __('shahbot::admin.value_type') }}</label>
                    <select name="value_type" class="form-select">
                        <option value="percent">{{ __('shahbot::admin.percent') }}</option>
                        <option value="fixed">{{ __('shahbot::admin.fixed') }}</option>
                    </select>
                </div>
            @endif
            <div class="col-md-2">
                <label class="form-label">{{ __('shahbot::admin.value') }}</label>
                <input type="number" name="value" step="any" min="0.01" value="{{ old('value') }}" class="form-control" required>
            </div>
            @if ($kind === 'discount')
                <div class="col-md-2">
                    <label class="form-label">{{ __('shahbot::admin.min_amount') }}</label>
                    <input type="number" name="min_amount" step="any" min="0" value="{{ old('min_amount') }}" class="form-control">
                </div>
            @endif
            <div class="col-md-1">
                <label class="form-label">{{ __('shahbot::admin.max_uses') }}</label>
                <input type="number" name="max_uses" min="1" value="{{ old('max_uses') }}" class="form-control" placeholder="∞">
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('shahbot::admin.expires_at') }}</label>
                <input type="text" name="expires_at" value="{{ old('expires_at') }}" class="form-control" placeholder="1405/01/01" dir="ltr">
            </div>
            @if ($kind === 'discount')
                <div class="col-md-12">
                    <label class="form-check"><input type="checkbox" name="first_purchase_only" value="1" class="form-check-input"> {{ __('shahbot::admin.first_purchase_only') }}</label>
                </div>
            @endif
            <div class="col-md-1"><button class="btn btn-primary w-100">{{ __('shahbot::admin.save') }}</button></div>
        </form>
    </div>
</div>

<div class="sb-box">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('shahbot::admin.code') }}</th>
                    <th>{{ __('shahbot::admin.value') }}</th>
                    <th>{{ __('shahbot::admin.uses') }}</th>
                    <th>{{ __('shahbot::admin.expires_at') }}</th>
                    <th>{{ __('shahbot::admin.col_status') }}</th>
                    <th>{{ __('shahbot::admin.col_actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($codes as $code)
                    <tr>
                        <td><code dir="ltr">{{ $code->code }}</code>@if ($code->first_purchase_only)<div class="sb-muted">{{ __('shahbot::admin.first_purchase_only') }}</div>@endif</td>
                        <td>
                            {{ $code->value_type === 'percent' ? persian_digits((float) $code->value).'٪' : format_money($code->value) }}
                            @if ($code->min_amount)<div class="sb-muted">{{ __('shahbot::admin.min_amount') }}: {{ format_money($code->min_amount) }}</div>@endif
                        </td>
                        <td>{{ persian_digits($code->used_count) }} / {{ $code->max_uses ? persian_digits($code->max_uses) : __('shahbot::admin.unlimited') }}</td>
                        <td>{{ $code->expires_at ? jalali_date($code->expires_at, 'Y/m/d') : '—' }}</td>
                        <td><span @class(['sb-pill', 'ok' => $code->isUsable(), 'bad' => ! $code->isUsable()])>{{ $code->isUsable() ? __('shahbot::admin.active') : __('shahbot::admin.inactive') }}</span></td>
                        <td class="d-flex gap-1">
                            <x-icon-action icon="bx-power-off" :label="__('shahbot::admin.toggle')" :action="route('admin.shahbot.codes.toggle', $code)" />
                            <x-icon-action icon="bx-trash" variant="danger" :label="__('shahbot::admin.delete')" :action="route('admin.shahbot.codes.destroy', $code)" method="DELETE" :confirm="__('shahbot::admin.confirm_delete')" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $codes->links() }}
@endsection
