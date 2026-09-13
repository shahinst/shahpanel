@extends('layouts.panel')

@section('page_title', __('servers.import_assign').' — '.$server->name)

@section('panel_content')
<p class="btn-toolbar margin-bottom">
    <x-button :href="route('admin.servers.import-clients.create', $server)" variant="ghost">{{ __('app.back') }}</x-button>
</p>

<x-card class="margin-bottom" :title="__('servers.import_summary')">
    <table class="table table-condensed">
        <tbody>
            @if (($payload['source'] ?? '') !== 'pasarguard')
            <tr>
                <th class="col-sm-3">{{ ($payload['source'] ?? '') === 'mikrotik' ? __('servers.profile') : __('servers.inbound') }}</th>
                <td>
                    {{ $payload['profile_name'] ?? $payload['inbound_name'] }}
                    @if (! empty($payload['inbound_id']))
                        (#{{ persian_digits($payload['inbound_id']) }})
                    @endif
                </td>
            </tr>
            @if (! empty($payload['protocol']))
            <tr>
                <th>{{ __('servers.protocol') }}</th>
                <td>{{ $payload['protocol'] }}</td>
            </tr>
            @endif
            @else
            <tr>
                <th class="col-sm-3">{{ __('servers.type') }}</th>
                <td>PasarGuard — {{ __('servers.pasarguard_sync_users') }}</td>
            </tr>
            @endif
            <tr>
                <th>{{ __('servers.import_client_count') }}</th>
                <td>{{ persian_digits(count($payload['clients'])) }}</td>
            </tr>
        </tbody>
    </table>
</x-card>

<x-card :title="__('servers.import_assign')">
    <p class="help-block">{{ __('servers.import_assign_hint') }}</p>
    <p class="help-block text-muted">{{ __('servers.import_package_hint') }}</p>

    @php
        $packages = $packages ?? ($payload['packages'] ?? []);
        $assignedCount = collect($payload['clients'])->where('is_already_imported', true)->count();
    @endphp

    @if ($assignedCount > 0)
        <x-alert type="warning" style="margin-bottom:15px;">
            {{ __('servers.import_already_assigned_hint') }}
            <strong>{{ __('servers.import_assigned_count') }}: {{ persian_digits($assignedCount) }}</strong>
        </x-alert>
    @endif

    <div class="row margin-bottom">
        <div class="col-md-6">
            <label class="control-label">{{ __('servers.import_bulk_owner') }}</label>
            <select id="bulk-owner" class="form-control">
                <option value="">{{ __('servers.import_bulk_owner_none') }}</option>
                @if ($owners->has('agent'))
                    <optgroup label="{{ __('roles.agent') }}">
                        @foreach ($owners['agent'] as $owner)
                            <option value="{{ $owner->id }}">{{ $owner->full_name }} ({{ $owner->username }})</option>
                        @endforeach
                    </optgroup>
                @endif
                @if ($owners->has('seller'))
                    <optgroup label="{{ __('roles.seller') }}">
                        @foreach ($owners['seller'] as $owner)
                            <option value="{{ $owner->id }}">{{ $owner->full_name }} ({{ $owner->username }})</option>
                        @endforeach
                    </optgroup>
                @endif
            </select>
            <button type="button" id="apply-bulk-owner" class="btn btn-default btn-sm mt-2">{{ __('servers.import_bulk_apply') }}</button>
        </div>
        @if (! empty($packages))
            <div class="col-md-6">
                <label class="control-label">{{ __('servers.import_bulk_package') }}</label>
                <select id="bulk-package" class="form-control">
                    <option value="">{{ __('servers.import_choose_package') }}</option>
                    @foreach ($packages as $package)
                        <option value="{{ $package['id'] }}">{{ $package['name'] }} ({{ $package['data_limit_label'] }})</option>
                    @endforeach
                </select>
                <button type="button" id="apply-bulk-package" class="btn btn-default btn-sm mt-2">{{ __('servers.import_bulk_apply') }}</button>
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.servers.import-clients.store', $server) }}">
        @csrf
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>{{ __('servers.import_email') }}</th>
                        <th>{{ __('servers.import_used') }}</th>
                        <th>{{ __('servers.import_limit') }}</th>
                        <th>{{ __('servers.import_remaining') }}</th>
                        <th>{{ __('servers.import_expiry') }}</th>
                        <th>{{ __('servers.import_package') }}</th>
                        <th>{{ __('servers.import_owner') }}</th>
                        <th>{{ __('servers.import_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payload['clients'] as $index => $client)
                        <tr @class(['import-client-row--assigned' => $client['is_already_imported'] ?? $client['existing_account_id']])>
                            <td>
                                <code>{{ $client['email'] ?? $client['username'] ?? $client['label'] ?? '—' }}</code>
                                <input type="hidden" name="assignments[{{ $index }}][uuid]" value="{{ $client['uuid'] }}">
                            </td>
                            <td>{{ format_data_size($client['data_used_bytes'] ?? 0) }}</td>
                            <td>{{ ! empty($client['data_limit_bytes']) ? format_data_size($client['data_limit_bytes']) : __('servers.unlimited') }}</td>
                            <td>
                                @if (isset($client['data_remaining_bytes']) && $client['data_remaining_bytes'] !== null)
                                    {{ format_data_size($client['data_remaining_bytes']) }}
                                @else
                                    {{ __('servers.unlimited') }}
                                @endif
                            </td>
                            <td>{{ ! empty($client['expiry_at']) ? jalali_date($client['expiry_at'], 'Y/m/d') : '—' }}</td>
                            <td>
                                @if (! empty($packages))
                                    <select name="assignments[{{ $index }}][package_id]" class="form-control input-sm package-select" @disabled(($client['existing_account_id'] ?? null) && ! old("assignments.{$index}.update_existing"))>
                                        <option value="">{{ __('servers.import_choose_package') }}</option>
                                        @foreach ($packages as $package)
                                            <option value="{{ $package['id'] }}" @selected((string) old("assignments.{$index}.package_id", $client['suggested_package_id'] ?? $client['existing_package_id'] ?? '') === (string) $package['id'])>
                                                {{ $package['name'] }} ({{ $package['data_limit_label'] }})
                                            </option>
                                        @endforeach
                                    </select>
                                    @if ($client['existing_package_name'] ?? null)
                                        <small class="text-muted d-block mt-1">{{ __('servers.import_current_package') }}: {{ $client['existing_package_name'] }}</small>
                                    @endif
                                @elseif ($client['existing_package_name'] ?? null)
                                    <span class="label label-info">{{ $client['existing_package_name'] }}</span>
                                @else
                                    <span class="text-muted">{{ __('servers.import_no_package') }}</span>
                                @endif
                            </td>
                            <td>
                                <select name="assignments[{{ $index }}][owner_id]" class="form-control input-sm owner-select" @disabled(($client['existing_account_id'] ?? null) && ! old("assignments.{$index}.update_existing"))>
                                    <option value="">{{ __('servers.import_choose_owner') }}</option>
                                    @if ($owners->has('agent'))
                                        <optgroup label="{{ __('roles.agent') }}">
                                            @foreach ($owners['agent'] as $owner)
                                                <option value="{{ $owner->id }}" @selected((string) old("assignments.{$index}.owner_id", $client['existing_owner_id'] ?? '') === (string) $owner->id)>
                                                    {{ $owner->full_name }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if ($owners->has('seller'))
                                        <optgroup label="{{ __('roles.seller') }}">
                                            @foreach ($owners['seller'] as $owner)
                                                <option value="{{ $owner->id }}" @selected((string) old("assignments.{$index}.owner_id", $client['existing_owner_id'] ?? '') === (string) $owner->id)>
                                                    {{ $owner->full_name }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </select>
                            </td>
                            <td>
                                @if ($client['existing_account_id'] ?? null)
                                    <span class="label label-default">{{ __('servers.import_already_exists') }}</span>
                                    @if ($client['existing_owner_name'] ?? null)
                                        <small class="d-block text-muted">{{ $client['existing_owner_name'] }}</small>
                                    @endif
                                    <label class="checkbox-inline d-block mt-1">
                                        <input type="checkbox" name="assignments[{{ $index }}][update_existing]" value="1" class="update-existing-toggle" data-index="{{ $index }}">
                                        {{ __('servers.import_update_owner') }}
                                    </label>
                                    <label class="checkbox-inline d-block">
                                        <input type="checkbox" name="assignments[{{ $index }}][skip]" value="1" checked>
                                        {{ __('servers.import_skip') }}
                                    </label>
                                @else
                                    <span class="label label-success">{{ __('servers.import_new') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-form.actions>
            <x-button type="submit">{{ __('servers.import_save') }}</x-button>
        </x-form.actions>
    </form>
</x-card>

@endsection

@push('styles')
<style>
    .table tbody tr.import-client-row--assigned > td {
        background-color: #fde8e8 !important;
    }

    .table.table-striped tbody tr.import-client-row--assigned:nth-of-type(odd) > td,
    .table.table-striped tbody tr.import-client-row--assigned:nth-of-type(even) > td {
        background-color: #fde8e8 !important;
    }

    .table tbody tr.import-client-row--assigned:hover > td {
        background-color: #fcd4d4 !important;
    }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var bulkSelect = document.getElementById('bulk-owner');
    var applyBtn = document.getElementById('apply-bulk-owner');

    if (applyBtn && bulkSelect) {
        applyBtn.addEventListener('click', function () {
            var value = bulkSelect.value;
            if (!value) return;
            document.querySelectorAll('.owner-select:not(:disabled)').forEach(function (select) {
                select.value = value;
            });
        });
    }

    document.querySelectorAll('.update-existing-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var row = checkbox.closest('tr');
            var ownerSelect = row.querySelector('.owner-select');
            var packageSelect = row.querySelector('.package-select');
            var skipCheckbox = row.querySelector('input[name*="[skip]"]');
            if (!ownerSelect) return;
            ownerSelect.disabled = !checkbox.checked;
            if (packageSelect) {
                packageSelect.disabled = !checkbox.checked;
            }
            if (skipCheckbox) {
                skipCheckbox.checked = !checkbox.checked;
            }
        });
    });

    var bulkPackage = document.getElementById('bulk-package');
    var applyPackageBtn = document.getElementById('apply-bulk-package');
    if (applyPackageBtn && bulkPackage) {
        applyPackageBtn.addEventListener('click', function () {
            var value = bulkPackage.value;
            if (!value) return;
            document.querySelectorAll('.package-select:not(:disabled)').forEach(function (select) {
                select.value = value;
            });
        });
    }
});
</script>
@endpush
