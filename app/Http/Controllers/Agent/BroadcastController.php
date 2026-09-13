<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\NotificationBroadcast;
use App\Services\BroadcastNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BroadcastController extends Controller
{
    public function index(Request $request): View
    {
        $broadcasts = NotificationBroadcast::query()
            ->where('sender_user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return view('agent.broadcasts.index', compact('broadcasts'));
    }

    public function create(): View
    {
        return view('agent.broadcasts.create');
    }

    public function store(Request $request, BroadcastNotificationService $service): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'link' => ['nullable', 'string', 'max:500'],
        ]);

        $service->submitAsAgent(
            $request->user(),
            $validated['title'],
            $validated['body'],
            $validated['link'] ?? null
        );

        return redirect()
            ->route('agent.broadcasts.index')
            ->with('success', __('broadcasts.submitted_for_review'));
    }
}
