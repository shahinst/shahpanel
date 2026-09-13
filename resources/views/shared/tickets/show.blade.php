@extends('layouts.panel')

@section('page_title', $ticket->subject)

@section('panel_content')
@php
    $user = auth()->user();
    $isAdmin = $user->role->value === 'admin';
    $isClosed = $ticket->isClosed();
    $canChangeStatus = ! $isClosed || $isAdmin;
@endphp

@include('partials.panel-page-hero', [
    'title' => $ticket->subject,
    'subtitle' => __('tickets.ticket_number').': '.$ticket->ticket_number,
    'icon' => 'bx-support',
])

<div class="row g-3">
    <div class="col-lg-8">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tickets.message') }}</h3></div>
            <div class="card-body">
                @foreach ($ticket->messages as $message)
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <strong>{{ $message->user?->full_name ?: $message->user?->username }}</strong>
                            <small class="text-muted">{{ jalali_date($message->created_at) }}</small>
                        </div>
                        <div class="small" style="white-space: pre-line;">{{ $message->body }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        @if (! $isClosed)
            <div class="panel-form-section">
                <h4 class="panel-form-section-title">{{ __('tickets.reply') }}</h4>
                <form method="POST" action="{{ route($panel.'.tickets.reply', $ticket) }}">
                    @csrf
                    <x-form.group wide :label="__('tickets.message')">
                        <textarea name="body" rows="4" required class="form-control">{{ old('body') }}</textarea>
                    </x-form.group>
                    <x-form.actions>
                        <x-button type="submit">{{ __('tickets.reply') }}</x-button>
                    </x-form.actions>
                </form>
            </div>
        @else
            <x-alert type="info">{{ __('tickets.closed_notice') }}</x-alert>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tickets.change_status') }}</h3></div>
            <div class="card-body">
                <div class="small mb-3">
                    <div class="text-muted">{{ __('tickets.requester') }}</div>
                    <div>{{ $ticket->requester?->full_name ?: $ticket->requester?->username }}</div>
                </div>
                <div class="small mb-3">
                    <div class="text-muted">{{ __('tickets.assignee') }}</div>
                    <div>{{ $ticket->assignee?->full_name ?: $ticket->assignee?->username }}</div>
                </div>
                @if ($ticket->department)
                    <div class="small mb-3">
                        <div class="text-muted">{{ __('tickets.department') }}</div>
                        <div>{{ $ticket->department->name }}</div>
                    </div>
                @endif
                @if ($ticket->sourceTicket)
                    <div class="small mb-3">
                        <a href="{{ route($panel.'.tickets.show', $ticket->sourceTicket) }}">{{ __('tickets.view_source') }}</a>
                    </div>
                @endif

                @if ($canChangeStatus)
                    <form method="POST" action="{{ route($panel.'.tickets.status', $ticket) }}">
                        @csrf
                        @method('PUT')
                        <x-form.group :label="__('tickets.change_status')">
                            <select name="status" class="form-select">
                                @foreach ($statuses as $status)
                                    @if ($status->value === 'open' && $isClosed && ! $isAdmin)
                                        @continue
                                    @endif
                                    <option value="{{ $status->value }}" @selected($ticket->status === $status)>{{ $status->label() }}</option>
                                @endforeach
                            </select>
                        </x-form.group>
                        <x-button type="submit" class="w-100">{{ __('app.save') }}</x-button>
                    </form>
                @else
                    <span class="badge bg-secondary">{{ $ticket->status->label() }}</span>
                @endif
            </div>
        </div>

        @if ($panel === 'agent' && $ticket->requester?->role->value === 'seller' && ! $isClosed)
            <div class="panel-form-section">
                <h4 class="panel-form-section-title">{{ __('tickets.escalate') }}</h4>
                <form method="POST" action="{{ route($panel.'.tickets.escalate', $ticket) }}">
                    @csrf
                    <x-form.group wide :label="__('tickets.escalate_note')">
                        <textarea name="note" rows="4" required class="form-control">{{ old('note') }}</textarea>
                    </x-form.group>
                    <x-button type="submit" variant="warning" class="w-100">{{ __('tickets.escalate') }}</x-button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
