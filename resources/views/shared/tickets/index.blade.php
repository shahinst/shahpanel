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
        <x-table :headers="[__('tickets.ticket_number'), __('tickets.subject'), __('tickets.requester'), __('tickets.status'), __('tickets.last_activity'), '']">
            @forelse ($tickets as $ticket)
                <tr>
                    <td><code dir="ltr">{{ $ticket->ticket_number }}</code></td>
                    <td>{{ $ticket->subject }}</td>
                    <td>{{ $ticket->requester?->full_name ?: $ticket->requester?->username }}</td>
                    <td><span class="badge bg-secondary">{{ $ticket->status->label() }}</span></td>
                    <td>{{ $ticket->last_activity_at ? jalali_date($ticket->last_activity_at) : '—' }}</td>
                    <td class="text-end">
                        <a href="{{ route($panel.'.tickets.show', $ticket) }}" class="btn btn-sm btn-outline-primary">{{ __('app.view') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">{{ __('tickets.no_tickets') }}</td></tr>
            @endforelse
        </x-table>
    </div>
</div>
@endsection
