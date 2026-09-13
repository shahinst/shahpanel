@extends('layouts.panel')

@section('page_title', __('menu.servers'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.servers'),
    'subtitle' => 'مدیریت سرورهای VPN و اتصال به پنل',
    'icon' => 'bx-server',
    'actions' => view('admin.servers.partials.index-actions')->render(),
])

<div class="panel-modern-card">
    <div class="card-head">
        <h3>{{ __('menu.servers') }}</h3>
    </div>
    <div class="card-body">
        <x-table :headers="[__('servers.name'), __('servers.host'), __('servers.type'), __('app.status'), __('app.actions')]">
            @forelse ($servers as $server)
                <tr>
                    <td><strong>{{ $server->name }}</strong></td>
                    <td><code>{{ $server->host }}:{{ persian_digits($server->port) }}</code></td>
                    <td>{{ $server->type->value }}</td>
                    <td>
                        <span @class([
                            'panel-status-badge',
                            'panel-status-badge--active' => $server->is_active,
                            'panel-status-badge--inactive' => ! $server->is_active,
                        ])>
                            {{ $server->is_active ? __('accounts.status_active') : __('accounts.status_disabled') }}
                        </span>
                    </td>
                    <td class="text-nowrap">
                        <a href="{{ route('admin.servers.show', $server) }}" class="btn btn-sm btn-primary">
                            <i class="bx bx-cog"></i> {{ __('servers.manage') }}
                        </a>
                        <a href="{{ route('admin.servers.edit', $server) }}" class="btn btn-sm btn-light">
                            <i class="bx bx-edit"></i> {{ __('app.edit') }}
                        </a>
                        @include('admin.servers.partials.delete-form', ['server' => $server])
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        {{ __('app.no_results') }}
                        @can('create', \App\Models\Server::class)
                            — <a href="{{ route('admin.servers.create') }}">{{ __('servers.create') }}</a>
                        @endcan
                    </td>
                </tr>
            @endforelse
        </x-table>
    </div>
    @if ($servers->hasPages())
        <div class="card-foot">{{ $servers->links() }}</div>
    @endif
</div>
@endsection
