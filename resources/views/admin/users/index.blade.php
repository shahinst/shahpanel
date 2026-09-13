@extends('layouts.panel')

@section('page_title', __('menu.agents'))

@section('page_actions')
    @can('create', \App\Models\User::class)
        <x-button :href="route('admin.users.create')" size="sm">
            <i class="bx bx-plus align-middle"></i> {{ __('agents.create') }}
        </x-button>
    @endcan
@endsection

@section('panel_content')
<x-page-header :title="__('menu.agents')">
    <x-slot:actions>
        @can('create', \App\Models\User::class)
            <x-button :href="route('admin.users.create')">
                <i class="bx bx-plus align-middle"></i> {{ __('agents.create') }}
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
                        <input type="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('app.search') }}">
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">{{ __('app.search') }}</button>
                </form>

                <x-table :headers="[__('auth.username'), __('auth.email'), __('wallet.enabled_currencies'), __('wallet.remaining_balance'), __('app.status'), __('app.actions')]">
                    @forelse ($users as $user)
                        <tr>
                            <td>{{ $user->username }}</td>
                            <td>{{ $user->email }}</td>
                            <td>
                                @foreach ($user->enabledCurrencyCodes() as $code)
                                    {{ \App\Enums\MoneyCurrency::normalize($code)->label() }}@if (! $loop->last)، @endif
                                @endforeach
                            </td>
                            <td>
                                @forelse ($user->wallets as $walletRow)
                                    {{ format_money($walletRow->balance, $walletRow->currency) }}@if (! $loop->last)<br>@endif
                                @empty
                                    {{ format_money(0, \App\Enums\MoneyCurrency::IRT) }}
                                @endforelse
                            </td>
                            <td>{{ $user->status->value }}</td>
                            <td>
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-light">{{ __('app.edit') }}</a>
                                @can('impersonate', $user)
                                    <form method="POST" action="{{ route('admin.users.impersonate', $user) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-info">{{ __('security.impersonation_as') }}</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                {{ __('app.no_results') }}
                                @can('create', \App\Models\User::class)
                                    — <a href="{{ route('admin.users.create') }}">{{ __('agents.create') }}</a>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </x-table>
            </div>
            @if ($users->hasPages())
                <div class="card-footer">{{ $users->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
