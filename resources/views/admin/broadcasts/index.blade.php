@extends('layouts.panel')

@section('page_title', __('broadcasts.page_title'))

@section('panel_content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title mb-0">{{ __('broadcasts.page_title') }}</h4>
                <x-button :href="route('admin.broadcasts.create')" size="sm">{{ __('broadcasts.send_new') }}</x-button>
            </div>
            <div class="card-body">
                @if ($pendingCount > 0)
                    <x-alert type="warning" style="margin-bottom:15px;">{{ __('broadcasts.pending_count', ['count' => persian_digits($pendingCount)]) }}</x-alert>
                @endif

                <x-table :headers="[__('broadcasts.sender'), __('broadcasts.audience'), __('broadcasts.title'), __('broadcasts.status'), __('broadcasts.date'), __('app.actions')]">
                    @forelse ($broadcasts as $broadcast)
                        <tr @class(['warning' => $broadcast->status === \App\Enums\BroadcastStatus::Pending])>
                            <td>{{ $broadcast->sender?->full_name }}</td>
                            <td>{{ $broadcast->audienceLabel() }}</td>
                            <td>{{ $broadcast->title }}</td>
                            <td>{{ $broadcast->status->label() }}</td>
                            <td>{{ jalali_date($broadcast->created_at) }}</td>
                            <td>
                                @if ($broadcast->status === \App\Enums\BroadcastStatus::Pending && $broadcast->sender?->role === \App\Enums\UserRole::Agent)
                                    <form method="POST" action="{{ route('admin.broadcasts.approve', $broadcast) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success">{{ __('broadcasts.approve_btn') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.broadcasts.reject', $broadcast) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-danger">{{ __('broadcasts.reject_btn') }}</button>
                                    </form>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        @if ($broadcast->body)
                            <tr><td colspan="6"><small class="text-muted">{{ $broadcast->body }}</small></td></tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">{{ __('app.no_results') }}</td></tr>
                    @endforelse
                </x-table>
            </div>
            @if ($broadcasts->hasPages())
                <div class="card-footer">{{ $broadcasts->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
