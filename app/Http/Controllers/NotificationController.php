<?php

namespace App\Http\Controllers;

use App\Models\PanelNotification;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = PanelNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->paginate(20);

        $highlightId = $request->integer('id') ?: null;

        return view('shared.notifications.index', compact('notifications', 'highlightId'));
    }

    public function open(PanelNotification $notification, NotificationService $service): RedirectResponse
    {
        abort_unless($notification->user_id === auth()->id(), 403);

        $service->markRead($notification);

        if ($notification->link) {
            return redirect()->to($notification->link);
        }

        return redirect()->route('notifications.index', ['id' => $notification->id]);
    }

    public function markRead(PanelNotification $notification, NotificationService $service): RedirectResponse
    {
        abort_unless($notification->user_id === auth()->id(), 403);

        $service->markRead($notification);

        if ($notification->link) {
            return redirect($notification->link);
        }

        return back();
    }

    public function markAllRead(Request $request, NotificationService $service): RedirectResponse
    {
        $service->markAllRead($request->user());

        return back()->with('success', __('app.saved'));
    }
}
