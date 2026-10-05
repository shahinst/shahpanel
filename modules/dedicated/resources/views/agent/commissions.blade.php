@extends('layouts.panel')

@section('page_title', __('dedicated::admin.commissions'))

@section('panel_content')
    <x-page-header :title="__('dedicated::admin.commissions')">
        <p class="text-muted mb-0">{{ __('dedicated::admin.commissions_intro') }}</p>
    </x-page-header>

    @if ($sellers->isEmpty())
        <x-alert type="info">{{ __('dedicated::admin.no_sellers') }}</x-alert>
    @elseif ($packages->isEmpty())
        <x-alert type="info">{{ __('dedicated::admin.no_packages') }}</x-alert>
    @else
        <form method="GET" class="card mb-3">
            <div class="card-body d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label">{{ __('dedicated::admin.seller') }}</label>
                    <select name="seller" class="form-select" onchange="this.form.submit()">
                        @foreach ($sellers as $s)
                            <option value="{{ $s->id }}" @selected($seller?->id === $s->id)>{{ $s->full_name }} ({{ $s->username }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('agent.dedicated.commissions.update', $seller) }}" class="card">
            @csrf
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
                    <div>
                        <label class="form-label">{{ __('dedicated::admin.commission_percent') }}</label>
                        <input type="number" name="percent" min="0" max="100" step="0.5" class="form-control" dir="ltr" placeholder="10">
                    </div>
                    <p class="text-muted small mb-1">{{ __('dedicated::admin.commission_percent_hint') }}</p>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>{{ __('dedicated::admin.package') }}</th>
                                <th>{{ __('dedicated::admin.tier') }}</th>
                                <th>{{ __('dedicated::admin.list_price') }}</th>
                                <th>{{ __('dedicated::admin.seller_price') }}</th>
                                <th>{{ __('dedicated::admin.seller_commission') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($packages as $package)
                                @foreach ($package->durations as $duration)
                                    @php
                                        $current = $prices[$duration->id] ?? null;
                                        $paid = (float) ($current ?? $duration->price);
                                    @endphp
                                    <tr>
                                        <td>{{ $package->name }}</td>
                                        <td>{{ $duration->tier->label() }}</td>
                                        <td>{{ format_money($duration->price) }}</td>
                                        <td style="max-width:12rem">
                                            <input type="number" name="prices[{{ $duration->id }}]" min="0" max="{{ $duration->price }}" step="1"
                                                class="form-control" dir="ltr" value="{{ $current !== null ? (float) $current : '' }}"
                                                placeholder="{{ (float) $duration->price }}">
                                        </td>
                                        <td>{{ format_money((float) $duration->price - $paid) }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small">{{ __('dedicated::admin.commission_rule') }}</p>
                <button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('app.save') }}</button>
            </div>
        </form>
    @endif
@endsection
