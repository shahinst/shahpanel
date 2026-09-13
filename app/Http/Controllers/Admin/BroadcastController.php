<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\NotificationBroadcast;
use App\Models\User;
use App\Services\BroadcastNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BroadcastController extends Controller
{
    public function index(): View
    {
        $broadcasts = NotificationBroadcast::query()
            ->with(['sender', 'reviewer'])
            ->latest()
            ->paginate(20);

        $pendingCount = NotificationBroadcast::query()
            ->where('status', BroadcastStatus::Pending)
            ->count();

        return view('admin.broadcasts.index', compact('broadcasts', 'pendingCount'));
    }

    public function create(): View
    {
        $agents = User::query()
            ->role(UserRole::Agent)
            ->with(['children' => fn ($query) => $query
                ->where('role', UserRole::Seller)
                ->orderBy('full_name')
                ->orderBy('username')])
            ->orderBy('full_name')
            ->orderBy('username')
            ->get(['id', 'full_name', 'username']);

        return view('admin.broadcasts.create', compact('agents'));
    }

    public function store(Request $request, BroadcastNotificationService $service): RedirectResponse
    {
        $validated = $request->validate([
            'audience' => ['required', Rule::enum(BroadcastAudience::class)],
            'seller_scope' => ['nullable', Rule::in(['all', 'selected'])],
            'seller_ids' => ['nullable', 'array'],
            'seller_ids.*' => ['integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'link' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);

        $audience = BroadcastAudience::from($validated['audience']);
        if ($audience === BroadcastAudience::AgentSellers) {
            return back()->with('error', __('broadcasts.invalid_audience'));
        }

        $recipientUserIds = null;

        if ($audience === BroadcastAudience::Sellers && ($validated['seller_scope'] ?? 'all') === 'selected') {
            $recipientUserIds = $this->resolveSelectedSellerIds($validated['seller_ids'] ?? []);

            if ($recipientUserIds === []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'seller_ids' => [__('broadcasts.sellers_required')],
                ]);
            }
        }

        $broadcast = $service->sendAsAdmin(
            $request->user(),
            $audience,
            $validated['title'],
            $validated['body'],
            $validated['link'] ?? null,
            $recipientUserIds,
        );

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('broadcasts', 'public');
            $broadcast->update(['image_path' => $path]);
        }

        $count = $service->dispatch($broadcast->fresh());

        return redirect()
            ->route('admin.broadcasts.index')
            ->with('success', __('broadcasts.sent', ['count' => $count]));
    }

    public function approve(Request $request, NotificationBroadcast $broadcast, BroadcastNotificationService $service): RedirectResponse
    {
        abort_unless($broadcast->status === BroadcastStatus::Pending, 404);
        abort_unless($broadcast->sender?->role === UserRole::Agent, 404);

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->approve($broadcast, $request->user(), $validated['admin_note'] ?? null);

        return back()->with('success', __('broadcasts.approved'));
    }

    public function reject(Request $request, NotificationBroadcast $broadcast, BroadcastNotificationService $service): RedirectResponse
    {
        abort_unless($broadcast->status === BroadcastStatus::Pending, 404);

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->reject($broadcast, $request->user(), $validated['admin_note'] ?? null);

        return back()->with('success', __('broadcasts.rejected'));
    }

    /**
     * @param  list<int|string>  $sellerIds
     * @return list<int>
     */
    protected function resolveSelectedSellerIds(array $sellerIds): array
    {
        $ids = collect($sellerIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $validIds = User::query()
            ->whereIn('id', $ids)
            ->where('role', UserRole::Seller)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($validIds) !== $ids->count()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'seller_ids' => [__('broadcasts.invalid_seller_selection')],
            ]);
        }

        return $validIds;
    }
}
