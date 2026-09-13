<?php

namespace App\Http\Controllers;

use App\Services\BroadcastBannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BroadcastBannerController extends Controller
{
    public function dismiss(Request $request, BroadcastBannerService $service): Response|JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'notification_id' => ['required', 'integer', 'min:1'],
        ]);

        $service->dismiss($request->user(), (int) $validated['notification_id']);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return back();
    }
}
