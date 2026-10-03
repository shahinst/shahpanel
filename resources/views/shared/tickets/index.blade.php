@extends('layouts.panel')

@section('page_title', __('tickets.page_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('tickets.page_title'),
    'subtitle' => __('tickets.subtitle'),
    'icon' => 'bx-support',
    'actions' => '<a href="'.route($panel.'.tickets.create').'" class="btn btn-light btn-sm"><i class="bx bx-plus"></i> '.e(__('tickets.new_ticket')).'</a>',
])

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('tickets.page_title') }}</h3></div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-5">
                <input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="{{ __('app.search') }}">
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select">
                    <option value="">{{ __('tickets.status') }}: —</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bx bx-search"></i> {{ __('app.search') }}</button>
                @if (request()->filled('q') || request()->filled('status'))
                    <a href="{{ route($panel.'.tickets.index') }}" class="btn btn-light">{{ __('app.cancel') }}</a>
                @endif
            </div>
        </form>
        <x-table :headers="[__('tickets.ticket_number'), __('tickets.subject'), __('tickets.requester'), __('tickets.status'), __('tickets.last_activity'), '']">
            @forelse ($tickets as $ticket)
                <tr>
                    <td><code dir="ltr">{{ $ticket->ticket_number }}</code></td>
                    <td>{{ $ticket->subject }}</td>
                    <td>{{ $ticket->requester?->full_name ?: $ticket->requester?->username }}</td>
                    <td><span class="badge bg-secondary">{{ $ticket->status->label() }}</span></td>
                    <td>{{ $ticket->last_activity_at ? jalali_date($ticket->last_activity_at) : '—' }}</td>
                    <td class="text-end">
                        <x-icon-action icon="bx-show" variant="primary" :label="__('app.view')" :href="route($panel.'.tickets.show', $ticket)" />
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">{{ __('tickets.no_tickets') }}</td></tr>
            @endforelse
        </x-table>
        @if ($tickets->hasPages())
            <div class="mt-3">{{ $tickets->links() }}</div>
        @endif
    </div>
</div>
@endsection
