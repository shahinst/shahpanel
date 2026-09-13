@extends('layouts.panel')

@section('page_title', __('menu.notifications'))

@section('panel_content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title mb-0">{{ __('menu.notifications') }}</h4>
                <form method="POST" action="{{ route('notifications.read-all') }}" style="display:inline;">
                    @csrf
                    <x-button type="submit" size="sm">{{ __('menu.mark_all_read') }}</x-button>
                </form>
            </div>
            <div class="card-body">
                @forelse ($notifications as $notification)
                    @php
                        $isUnread = ! $notification->is_read;
                        $isHighlighted = isset($highlightId) && (int) $highlightId === (int) $notification->id;
                    @endphp
                    <article @class([
                        'border rounded p-3 mb-3',
                        'border-primary bg-light' => $isHighlighted,
                        'border-start border-4 border-primary' => $isUnread && ! $isHighlighted,
                    ])>
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div>
                                <h5 class="mb-1">{{ $notification->title }}</h5>
                                <p class="mb-2 text-muted">{{ $notification->body }}</p>
                                <small class="text-muted">{{ jalali_date($notification->created_at) }}</small>
                            </div>
                            <div class="d-flex gap-2">
                                @if ($notification->link)
                                    <a href="{{ route('notifications.open', $notification) }}" class="btn btn-sm btn-primary">{{ __('app.view') }}</a>
                                @else
                                    <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('menu.mark_read') }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <p class="text-center text-muted mb-0">{{ __('app.no_results') }}</p>
                @endforelse
            </div>
            @if ($notifications->hasPages())
                <div class="card-footer">{{ $notifications->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
