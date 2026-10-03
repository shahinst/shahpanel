@extends('layouts.panel')

@section('page_title', __('admins.page_title'))

@section('page_actions')
    <x-button :href="route('admin.administrators.create')" size="sm">
        <i class="bx bx-plus align-middle"></i> {{ __('admins.create') }}
    </x-button>
@endsection

@section('panel_content')
<x-page-header :title="__('admins.page_title')">
    <x-slot:actions>
        <x-button :href="route('admin.administrators.create')">
            <i class="bx bx-plus align-middle"></i> {{ __('admins.create') }}
        </x-button>
    </x-slot:actions>
</x-page-header>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <p class="text-muted small">{{ __('admins.super_admin_hint') }}</p>

                <x-table :headers="[__('auth.username'), __('auth.email'), __('app.status'), __('admins.access_title'), __('app.actions')]">
                    @forelse ($admins as $admin)
                        @php
                            $isSuper = (int) $admin->id === (int) $superAdminId;
                            $granted = is_array($admin->admin_section_permissions)
                                ? $admin->admin_section_permissions
                                : [];
                        @endphp
                        <tr>
                            <td>
                                {{ $admin->username }}
                                @if ($isSuper)
                                    <span class="badge bg-primary">{{ __('admins.super_admin') }}</span>
                                @endif
                            </td>
                            <td>{{ $admin->email }}</td>
                            <td>{{ $admin->status->value }}</td>
                            <td>
                                @if ($isSuper || $granted === [])
                                    <span class="badge bg-success">{{ __('admins.access_full_badge') }}</span>
                                @else
                                    <span class="badge bg-warning">{{ __('admins.access_limited_badge') }}</span>
                                    <span class="text-muted small">{{ __('admins.sections_count') }}: {{ locale_digits((string) count($granted)) }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="icon-actions">
                                    <x-icon-action icon="bx-edit" :label="__('app.edit')" :href="route('admin.administrators.edit', $admin)" />
                                    @if (! $isSuper && (int) $admin->id !== (int) auth()->id())
                                        <x-icon-action icon="bx-trash" variant="danger" :label="__('app.delete')"
                                            :action="route('admin.administrators.destroy', $admin)" method="DELETE"
                                            :confirm="__('admins.delete_confirm')" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">{{ __('admins.list_empty') }}</td>
                        </tr>
                    @endforelse
                </x-table>

                {{ $admins->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
