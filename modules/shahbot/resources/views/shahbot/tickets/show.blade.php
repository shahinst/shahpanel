@extends('layouts.panel')

@section('page_title', __('shahbot::admin.ticket_title', ['id' => $ticket->id, 'name' => $ticket->botUser->displayName()]))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-box">
    <header>
        <span>{{ __('shahbot::admin.ticket_title', ['id' => persian_digits($ticket->id), 'name' => $ticket->botUser->displayName()]) }}
            <a href="{{ route('admin.shahbot.users.show', $ticket->botUser) }}" class="sb-muted">({{ $ticket->botUser->telegram_id }})</a></span>
        @if ($ticket->status !== 'closed')
            <form method="POST" action="{{ route('admin.shahbot.tickets.close', $ticket) }}">
                @csrf
                <button class="btn btn-sm btn-outline-secondary"><i class="bx bx-lock"></i> {{ __('shahbot::admin.close_ticket') }}</button>
            </form>
        @endif
    </header>
    <div class="sb-body">
        @foreach ($ticket->messages as $message)
            <div @class(['sb-msg', 'admin' => $message->from_admin, 'user' => ! $message->from_admin])>
                {{ $message->body }}
                <small>{{ $message->from_admin ? __('shahbot::admin.you').' · '.$message->author : $message->author }} · {{ jalali_date($message->created_at, 'Y/m/d H:i') }}</small>
            </div>
        @endforeach

        <form method="POST" action="{{ route('admin.shahbot.tickets.reply', $ticket) }}" class="mt-3">
            @csrf
            <textarea name="body" rows="3" maxlength="3500" class="form-control mb-2" required placeholder="{{ __('shahbot::admin.reply') }}"></textarea>
            <button class="btn btn-primary"><i class="bx bx-send"></i> {{ __('shahbot::admin.send') }}</button>
        </form>
    </div>
</div>
@endsection
