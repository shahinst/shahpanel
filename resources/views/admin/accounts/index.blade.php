@extends('layouts.panel')

@section('page_title', __('menu.accounts'))

@section('page_actions')
    @can('create', \App\Models\Account::class)
        <x-button :href="route('admin.accounts.create')" size="sm">
            <i class="bx bx-plus align-middle"></i> {{ __('accounts.create') }}
        </x-button>
    @endcan
@endsection

@section('panel_content')
<x-page-header :title="__('menu.accounts')">
    <x-slot:actions>
        @can('create', \App\Models\Account::class)
            <x-button :href="route('admin.accounts.create')">
                <i class="bx bx-plus align-middle"></i> {{ __('accounts.create') }}
            </x-button>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" class="form-inline" style="margin-bottom:15px;">
                    <div class="form-group">
                        <input type="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('app.search') }}...">
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm"><i class="bx bx-search"></i></button>
                </form>

                <x-table :headers="['فروشنده', 'کاربر', __('accounts.package'), 'وضعیت', 'انقضا', __('app.actions')]">
                    @forelse ($accounts as $account)
                        <tr>
                            <td>{{ $account->ownerSeller?->full_name }}</td>
                            <td>{{ $account->remote_username }}</td>
                            <td>{{ $account->package?->name ?? '—' }}</td>
                            <td>@include('shared.accounts.status-toggle', ['account' => $account, 'prefix' => 'admin'])</td>
                            <td>@include('shared.accounts.expiry-cell', ['account' => $account])</td>
                            <td>@include('shared.accounts.wireguard-menu', ['account' => $account, 'prefix' => 'admin'])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                {{ __('app.no_results') }}
                                @can('create', \App\Models\Account::class)
                                    — <a href="{{ route('admin.accounts.create') }}">{{ __('accounts.create') }}</a>
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
