@extends('layouts.panel')

@section('page_title', __('markup::markup.admin_menu'))

@section('panel_content')
<x-page-header :title="__('markup::markup.admin_menu')">
    <p class="text-muted mb-0">{{ __('markup::markup.admin_intro') }}</p>
</x-page-header>

<form method="POST" action="{{ route('admin.markup.update') }}" class="card mb-4">
    @csrf
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">{{ __('markup::markup.max_percent') }}</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" max="100" name="max" class="form-control" dir="ltr" value="{{ old('max', $max) }}" required>
                <span class="input-group-text">%</span>
            </div>
        </div>
        <div class="col-md-5 text-muted small">{{ __('markup::markup.max_admin_hint') }}</div>
        <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bx bx-save"></i> {{ __('app.save') }}</button></div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('markup::markup.agent') }}</th>
                <th>{{ __('markup::markup.default_percent') }}</th>
                <th>{{ __('markup::markup.sellers') }}</th>
                <th>{{ __('markup::markup.agent_earned_30d') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($agents as $agent)
                <tr>
                    <td>{{ $agent->username }} <span class="text-muted small">{{ $agent->full_name }}</span></td>
                    <td dir="ltr">{{ isset($defaults[$agent->id]) ? rtrim(rtrim((string) $defaults[$agent->id], '0'), '.').'%' : '—' }}</td>
                    <td>{{ persian_digits((string) ($sellerCounts[$agent->id] ?? 0)) }}</td>
                    <td>{{ format_money($totals[$agent->id] ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted text-center">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
