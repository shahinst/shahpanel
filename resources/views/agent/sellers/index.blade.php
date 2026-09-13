@extends('layouts.panel')

@section('page_title', __('menu.sellers'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.sellers'),
    'subtitle' => 'مدیریت فروشندگان زیرمجموعه',
    'icon' => 'bx-store',
    'actions' => view('agent.sellers.partials.create-action')->render(),
])

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('menu.sellers') }}</h3></div>
    <div class="card-body">
        <form method="GET" class="panel-filter-bar">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">{{ __('app.search') }}</label>
                    <input type="search" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="{{ __('app.search') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bx bx-search"></i> {{ __('app.search') }}</button>
                </div>
            </div>
        </form>

        <x-table :headers="[__('auth.username'), __('validation.attributes.full_name'), __('wallet.settlement_currency'), __('wallet.remaining_balance'), __('app.status'), __('app.actions')]">
            @forelse ($sellers as $seller)
                @php
                    $settle = $seller->settlementMoneyCurrency();
                    $settleWallet = $seller->wallets->firstWhere('currency', $settle->value) ?? $seller->wallet;
                @endphp
                <tr>
                    <td>{{ $seller->username }}</td>
                    <td>{{ $seller->full_name }}</td>
                    <td>{{ $settle->label() }}</td>
                    <td>{{ format_money($settleWallet?->balance ?? 0, $settle) }}</td>
                    <td>{{ $seller->status?->value ?? '—' }}</td>
                    <td class="text-nowrap">
                        @include('agent.sellers.partials.impersonate-button', ['seller' => $seller])
                        <a href="{{ route('agent.sellers.edit', $seller) }}" class="btn btn-sm btn-primary">
                            <i class="bx bx-edit"></i> {{ __('app.edit') }}
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        {{ __('app.no_results') }}
                        @can('create', \App\Models\User::class)
                            — <a href="{{ route('agent.sellers.create') }}">{{ __('sellers.create') }}</a>
                        @endcan
                    </td>
                </tr>
            @endforelse
        </x-table>
    </div>
    @if ($sellers->hasPages())
        <div class="card-foot">{{ $sellers->links() }}</div>
    @endif
</div>
@endsection
