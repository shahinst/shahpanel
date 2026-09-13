@extends('layouts.panel')

@section('page_title', __('menu.clients'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.clients'),
    'subtitle' => 'مدیریت مشتریان و اکانت‌های مرتبط',
    'icon' => 'bx-group',
    'actions' => '
        <a href="'.route($panel.'.clients.create').'" class="btn btn-light btn-sm"><i class="bx bx-user-plus"></i> '.e(__('clients.create_client')).'</a>
        <a href="'.route($panel.'.accounts.create').'" class="btn btn-outline-light btn-sm"><i class="bx bx-plus"></i> '.e(__('clients.create_via_account')).'</a>
    ',
])

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('menu.clients') }}</h3></div>
    <div class="card-body">
        <form method="GET" class="panel-filter-bar">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">{{ __('app.search') }}</label>
                    <input type="search" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="{{ __('app.search') }}...">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bx bx-search"></i> {{ __('app.search') }}</button>
                </div>
            </div>
        </form>

        <x-table :headers="[__('auth.username'), __('validation.attributes.full_name'), __('clients.owner'), __('clients.accounts_count'), __('app.status'), __('app.actions')]">
            @forelse ($clients as $client)
                <tr>
                    <td>{{ $client->username }}</td>
                    <td>{{ $client->full_name }}</td>
                    <td>
                        @if ($client->parent)
                            {{ $client->parent->full_name }}
                            <span class="text-muted small">({{ $client->parent->role->label() }})</span>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ persian_digits($client->client_accounts_count) }}</td>
                    <td><span class="badge bg-light text-dark">{{ $client->status->value }}</span></td>
                    <td class="text-nowrap">
                        <a href="{{ route($panel.'.clients.show', $client) }}" class="btn btn-sm btn-primary" title="{{ __('app.view') }}">
                            <i class="bx bx-show"></i>
                        </a>
                        @can('impersonate', $client)
                            <form method="POST" action="{{ route($panel.'.clients.impersonate', $client) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-info" title="{{ __('clients.enter_portal') }}">
                                    <i class="bx bx-log-in-circle"></i> {{ __('clients.enter_portal_short') }}
                                </button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        {{ __('app.no_results') }}
                        — <a href="{{ route($panel.'.clients.create') }}">{{ __('clients.create_client') }}</a>
                    </td>
                </tr>
            @endforelse
        </x-table>
    </div>
    @if ($clients->hasPages())
        <div class="card-foot">{{ $clients->links() }}</div>
    @endif
</div>
@endsection
