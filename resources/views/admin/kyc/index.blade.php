@extends('layouts.panel')

@section('page_title', __('kyc.list_title'))

@section('panel_content')
<x-page-header :title="__('kyc.list_title')">
    <x-slot:actions>
        <x-button :href="route('admin.kyc.settings')" size="sm" variant="secondary">{{ __('kyc.title') }}</x-button>
    </x-slot:actions>
</x-page-header>

<div class="panel-modern-card">
    <div class="card-body">
        <form method="GET" class="mb-3 row g-2">
            <div class="col-md-4">
                <select name="status" class="form-select form-select-sm">
                    <option value="">{{ __('ui.all_statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100">{{ __('app.search') }}</button>
            </div>
        </form>

        <x-table :headers="['#', __('ui.col_name'), __('kyc.national_code'), __('kyc.document'), __('app.status'), __('ui.col_attempts'), __('ui.col_initiated_by'), __('app.actions')]">
            @forelse ($items as $item)
                <tr>
                    <td>{{ $item->id }}</td>
                    <td>{{ $item->fullName() }}</td>
                    <td dir="ltr">{{ $item->maskedNationalCode() }}</td>
                    <td>
                        @if ($item->has_document)
                            <span class="badge bg-success">{{ __('kyc.has_document') }}</span>
                        @else
                            <span class="badge bg-secondary">{{ __('kyc.no_document') }}</span>
                        @endif
                    </td>
                    <td>{{ $item->status->label() }}</td>
                    <td>{{ $item->verify_attempts }}/{{ $item->max_verify_attempts }}</td>
                    <td>{{ $item->initiatedBy?->full_name ?? '—' }}</td>
                    <td class="text-nowrap">
                        <a href="{{ route('admin.kyc.show', $item) }}" class="btn btn-sm btn-light">{{ __('app.edit') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($items->hasPages())
        <div class="card-foot">{{ $items->links() }}</div>
    @endif
</div>
@endsection
