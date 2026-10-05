@extends('layouts.panel')

@section('page_title', __('markup::markup.agent_menu'))

@section('panel_content')
<x-page-header :title="__('markup::markup.agent_menu')">
    <p class="text-muted mb-0">{{ __('markup::markup.agent_intro') }}</p>
</x-page-header>

<div class="alert alert-info">{{ __('markup::markup.example') }}</div>

<form method="POST" action="{{ route('agent.markup.update') }}" class="card mb-4">
    @csrf
    <div class="card-body">
        <div class="row g-3 align-items-end mb-3">
            <div class="col-md-4">
                <label class="form-label">{{ __('markup::markup.default_percent') }}</label>
                <div class="input-group">
                    <input type="number" step="0.01" min="0" max="{{ $max }}" name="default" class="form-control" dir="ltr" value="{{ old('default', $default) }}" placeholder="{{ __('markup::markup.manual') }}">
                    <span class="input-group-text">%</span>
                </div>
            </div>
            <div class="col-md-8 text-muted small">{{ __('markup::markup.max_hint', ['max' => $max]) }} {{ __('markup::markup.empty_hint') }}</div>
        </div>

        @if ($sellers->isNotEmpty())
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr>
                        <th>{{ __('markup::markup.seller') }}</th>
                        <th style="width:14rem">{{ __('markup::markup.own_percent') }}</th>
                        <th>{{ __('markup::markup.earned_30d') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($sellers as $seller)
                        <tr>
                            <td>{{ $seller->username }} <span class="text-muted small">{{ $seller->full_name }}</span></td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <input type="number" step="0.01" min="0" max="{{ $max }}" name="sellers[{{ $seller->id }}]" class="form-control" dir="ltr" value="{{ old('sellers.'.$seller->id, $overrides[$seller->id] ?? '') }}" placeholder="{{ __('markup::markup.use_default') }}">
                                    <span class="input-group-text">%</span>
                                </div>
                            </td>
                            <td>{{ format_money($earned[$seller->id]['amount'] ?? 0) }} <span class="text-muted small">({{ persian_digits((string) ($earned[$seller->id]['sales'] ?? 0)) }})</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted mb-0">{{ __('markup::markup.no_sellers') }}</p>
        @endif

        <button class="btn btn-primary mt-3"><i class="bx bx-save"></i> {{ __('app.save') }}</button>
    </div>
</form>
@endsection
