@extends('layouts.panel')

@section('page_title', __('menu.accounts'))

@section('page_actions')
    @can('create', \App\Models\Account::class)
        <x-button :href="route('seller.accounts.create')" size="sm">
            <i class="bx bx-plus align-middle"></i> {{ __('accounts.create') }}
        </x-button>
    @endcan
@endsection

@section('panel_content')
<x-page-header :title="__('menu.accounts')">
    <x-slot:actions>
        @can('create', \App\Models\Account::class)
            <x-button :href="route('seller.accounts.create')">
                <i class="bx bx-plus align-middle"></i> {{ __('accounts.create') }}
            </x-button>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <x-table :headers="['کاربر', __('accounts.package'), 'وضعیت', 'انقضا', __('app.actions')]">
                    @forelse ($accounts as $account)
                        <tr>
                            <td>{{ $account->remote_username }}</td>
                            <td>{{ $account->package?->name ?? '—' }}</td>
                            <td>@include('shared.accounts.status-toggle', ['account' => $account, 'prefix' => 'seller'])</td>
                            <td>@include('shared.accounts.expiry-cell', ['account' => $account])</td>
                            <td>@include('shared.accounts.wireguard-menu', ['account' => $account, 'prefix' => 'seller'])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                {{ __('app.no_results') }}
                                @can('create', \App\Models\Account::class)
                                    — <a href="{{ route('seller.accounts.create') }}">{{ __('accounts.create') }}</a>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </x-table>
            </div>
            @if ($accounts->hasPages())
                <div class="card-footer">{{ $accounts->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
