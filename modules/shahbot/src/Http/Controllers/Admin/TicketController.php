<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotTicket;
use Modules\ShahBot\Services\TicketService;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'open');

        return view('shahbot::tickets.index', [
            'status' => $status,
            'tickets' => BotTicket::query()
                ->with(['botUser', 'messages' => fn ($q) => $q->latest('id')->limit(1)])
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->orderByDesc('last_message_at')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function show(BotTicket $ticket): View
    {
        $ticket->load(['botUser', 'messages']);

        return view('shahbot::tickets.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, BotTicket $ticket, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:3500']]);

        $ok = $tickets->reply($ticket, $data['body'], 'panel:'.$request->user()->username);

        return back()->with($ok ? 'success' : 'error', __($ok ? 'shahbot::admin.message_sent' : 'shahbot::admin.message_failed'));
    }

    public function close(BotTicket $ticket, TicketService $tickets): RedirectResponse
    {
        $tickets->close($ticket);

        return redirect()->route('admin.shahbot.tickets.index')->with('success', __('shahbot::admin.saved'));
    }
}
