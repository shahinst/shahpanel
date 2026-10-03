@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_tickets'))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-filters">
    @foreach (['open', 'answered', 'closed', 'all'] as $value)
        <a href="{{ route('admin.shahbot.tickets.index', ['status' => $value]) }}" @class(['is-active' => $status === $value])>{{ $value === 'all' ? __('shahbot::admin.all') : __('shahbot::admin.ticket_'.$value) }}</a>
    @endforeach
</div>

<div class="sb-box">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>#</th><th>{{ __('shahbot::admin.col_user') }}</th><th>{{ __('shahbot::admin.last_message') }}</th><th>{{ __('shahbot::admin.col_status') }}</th><th>{{ __('shahbot::admin.col_date') }}</th></tr></thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr>
                        <td><a href="{{ route('admin.shahbot.tickets.show', $ticket) }}">#{{ persian_digits($ticket->id) }}</a></td>
                        <td>{{ $ticket->botUser->displayName() }}</td>
                        <td><a href="{{ route('admin.shahbot.tickets.show', $ticket) }}" class="text-reset">{{ \Illuminate\Support\Str::limit($ticket->messages->first()?->body, 90) }}</a></td>
                        <td><span @class(['sb-pill', 'warn' => $ticket->status === 'open', 'ok' => $ticket->status === 'answered'])>{{ __('shahbot::admin.ticket_'.$ticket->status) }}</span></td>
                        <td>{{ jalali_date($ticket->last_message_at ?? $ticket->created_at, 'Y/m/d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $tickets->links() }}
@endsection
