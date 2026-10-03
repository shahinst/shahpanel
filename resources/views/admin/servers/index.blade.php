@extends('layouts.panel')

@section('page_title', __('menu.servers'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.servers'),
    'subtitle' => __('ui.servers_index_subtitle'),
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
                        <div class="icon-actions">
                            <x-icon-action icon="bx-cog" variant="primary" :label="__('servers.manage')" :href="route('admin.servers.show', $server)" />
                            <x-icon-action icon="bx-edit" :label="__('app.edit')" :href="route('admin.servers.edit', $server)" />
                            @can('delete', $server)
                                <x-icon-action icon="bx-trash" variant="danger" :label="__('servers.delete')"
                                    :action="route('admin.servers.destroy', $server)" method="DELETE"
                                    :confirm="__('servers.delete_confirm', ['name' => $server->name])" />
                            @endcan
                        </div>
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
