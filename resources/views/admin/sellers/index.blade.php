@extends('layouts.panel')

@section('page_title', __('menu.sellers'))

@section('page_actions')
    @can('create', \App\Models\User::class)
        <x-button :href="route('admin.sellers.create')" size="sm">
            <i class="bx bx-plus align-middle"></i> {{ __('sellers.create') }}
        </x-button>
    @endcan
@endsection

@section('panel_content')
<x-page-header :title="__('menu.sellers')">
    <x-slot:actions>
        @can('create', \App\Models\User::class)
            <x-button :href="route('admin.sellers.create')">
                <i class="bx bx-plus align-middle"></i> {{ __('sellers.create') }}
            </x-button>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <x-table :headers="[__('ui.col_name'), __('ui.col_username'), __('ui.col_parent'), __('wallet.settlement_currency'), __('wallet.remaining_balance'), __('app.status'), __('app.actions')]">
                    @forelse ($sellers as $seller)
                        @php
                            $settle = $seller->settlementMoneyCurrency();
                            $settleWallet = ($seller->relationLoaded('wallets') ? $seller->wallets : collect())
                                ->firstWhere('currency', $settle->value)
                                ?? $seller->wallet;
                        @endphp
                        <tr>
                            <td>{{ $seller->full_name }}</td>
                            <td>{{ $seller->username }}</td>
                            <td>{{ $seller->parent?->full_name ?? '—' }}</td>
                            <td>{{ $settle->label() }}</td>
                            <td>{{ format_money($settleWallet?->balance ?? 0, $settle) }}</td>
                            <td>{{ $seller->status->value }}</td>
                            <td>
                                <a href="{{ route('admin.sellers.edit', $seller) }}" class="btn btn-sm btn-light">{{ __('app.edit') }}</a>
                                @can('impersonate', $seller)
                                    <form method="POST" action="{{ route('admin.sellers.impersonate', $seller) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-info">{{ __('security.impersonation_as') }}</button>
                                    </form>
                                @endcan
                                <form method="POST" action="{{ route('admin.sellers.promote', $seller) }}" style="display:inline;"
                                      onsubmit="return confirm('{{ __('sellers.promote_confirm') }}')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-warning">{{ __('sellers.promote') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                {{ __('app.no_results') }}
                                @can('create', \App\Models\User::class)
                                    — <a href="{{ route('admin.sellers.create') }}">{{ __('sellers.create') }}</a>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </x-table>
            </div>
            @if ($sellers->hasPages())
                <div class="card-footer">{{ $sellers->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
