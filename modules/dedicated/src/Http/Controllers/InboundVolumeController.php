<?php

namespace Modules\Dedicated\Http\Controllers;

use App\Enums\UserRole;
use App\Models\InboundAllocation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\Dedicated\Models\InboundChargeRequest;
use Modules\Dedicated\Models\InboundVolumePack;
use Modules\Dedicated\Services\InboundVolumeService;

/**
 * The inbound agent's own page: how much of the inbound is used, day by day,
 * and asking the admin for more volume.
 */
class InboundVolumeController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $this->agent($request);

        $allocations = InboundAllocation::query()->where('agent_user_id', $agent->id)->with('server')->orderBy('id')->get();
        $ids = $allocations->pluck('id')->all();

        // Daily use comes from the same usage log the billing reads, summed
        // over the accounts that live on each allocation.
        $from = today()->subDays(13);
        $daily = $ids === [] ? collect() : DB::table('account_usage_logs')
            ->join('accounts', 'accounts.id', '=', 'account_usage_logs.account_id')
            ->whereIn('accounts.inbound_allocation_id', $ids)
            ->where('account_usage_logs.recorded_at', '>=', $from)
            ->selectRaw('DATE(account_usage_logs.recorded_at) as day, SUM(rx_delta_bytes + tx_delta_bytes) as bytes')
            ->groupBy('day')
            ->pluck('bytes', 'day');

        $chart = collect(range(13, 0))->map(function (int $back) use ($daily): array {
            $day = today()->subDays($back)->toDateString();

            return ['day' => $day, 'bytes' => (int) ($daily[$day] ?? 0)];
        });

        return view('dedicated::agent.inbound', [
            'allocations' => $allocations,
            'chart' => $chart,
            'packs' => InboundVolumePack::query()->where('is_active', true)
                ->whereIn('server_id', $allocations->pluck('server_id'))
                ->orderBy('sort_order')->orderBy('gb')->get()->groupBy('server_id'),
            'requests' => InboundChargeRequest::query()->where('agent_user_id', $agent->id)->with('allocation')->latest()->limit(20)->get(),
            'balance' => $agent->wallet?->balance ?? '0.00',
        ]);
    }

    public function store(Request $request, InboundAllocation $allocation, InboundVolumeService $volume): RedirectResponse
    {
        $agent = $this->agent($request);
        $data = $request->validate([
            'pack_id' => ['required', 'integer', 'exists:inbound_volume_packs,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // One open request per inbound: a second one would only be a
        // duplicate for the admin to reject.
        $open = InboundChargeRequest::query()->where('allocation_id', $allocation->id)
            ->where('status', InboundChargeRequest::PENDING)->exists();

        if ($open) {
            return back()->with('error', __('dedicated::admin.request_already_open'));
        }

        try {
            $volume->request($agent, $allocation, InboundVolumePack::query()->findOrFail($data['pack_id']), $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('dedicated::admin.request_sent'));
    }

    protected function agent(Request $request): User
    {
        $user = $request->user();

        abort_unless($user->role === UserRole::Agent
            && InboundAllocation::query()->where('agent_user_id', $user->id)->exists(), 403);

        return $user;
    }
}
