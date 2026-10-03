<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MoneyCurrency;
use App\Enums\ServerType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\InboundAllocation;
use App\Models\Server;
use App\Models\ServerInterface;
use App\Models\User;
use App\Services\InboundReseller\InboundAllocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Admin side of the inbound reseller model: give an agent inbounds on a Sanaei
 * server with a traffic quota and a price per GB, watch their usage, and
 * suspend or resume them.
 */
class InboundAllocationController extends Controller
{
    public function __construct(protected InboundAllocationService $service) {}

    public function index(): View
    {
        $allocations = InboundAllocation::query()
            ->with(['agent', 'server'])
            ->withCount(['accounts', 'packages'])
            ->latest('id')
            ->paginate(20);

        return view('admin.inbound-allocations.index', [
            'allocations' => $allocations,
        ]);
    }

    public function create(): View
    {
        return view('admin.inbound-allocations.form', $this->formData(new InboundAllocation([
            'currency' => MoneyCurrency::default()->value,
            'credit_limit' => 0,
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        InboundAllocation::query()->create($this->validated($request));

        return redirect()->route('admin.inbound-allocations.index')->with('success', __('app.saved'));
    }

    public function edit(InboundAllocation $inboundAllocation): View
    {
        return view('admin.inbound-allocations.form', $this->formData($inboundAllocation));
    }

    public function update(Request $request, InboundAllocation $inboundAllocation): RedirectResponse
    {
        $data = $this->validated($request, $inboundAllocation);
        $inboundAllocation->update($data);

        // New inbounds reach the agent's packages too, so accounts created
        // from now on land on them.
        $inboundAllocation->packages()->update(['sanaei_inbound_ids' => json_encode($inboundAllocation->inboundIdList())]);

        return redirect()->route('admin.inbound-allocations.index')->with('success', __('app.saved'));
    }

    public function suspend(InboundAllocation $inboundAllocation): RedirectResponse
    {
        if ($inboundAllocation->isActive()) {
            $this->service->suspend($inboundAllocation, InboundAllocation::SUSPENDED_ADMIN);
        }

        return back()->with('success', __('inbound_resellers.suspended_done'));
    }

    public function resume(InboundAllocation $inboundAllocation): RedirectResponse
    {
        try {
            $count = $this->service->resume($inboundAllocation);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('inbound_resellers.resumed_done', ['count' => persian_digits($count)]));
    }

    public function bill(InboundAllocation $inboundAllocation): RedirectResponse
    {
        $amount = $this->service->bill($inboundAllocation);

        return back()->with('success', __('inbound_resellers.billed_now', [
            'amount' => format_money($amount, $inboundAllocation->currency),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?InboundAllocation $current = null): array
    {
        $validated = $request->validate([
            'agent_user_id' => ['required', Rule::exists('users', 'id')->where('role', UserRole::Agent->value)],
            'server_id' => ['required', Rule::exists('servers', 'id')->where('type', ServerType::Sanaei->value)],
            'title' => ['nullable', 'string', 'max:120'],
            'inbound_ids' => ['required', 'array', 'min:1'],
            'inbound_ids.*' => ['integer', 'min:1'],
            'quota_amount' => ['required', 'numeric', 'min:1'],
            'quota_unit' => ['required', 'in:gb,tb'],
            'price_per_gb' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', Rule::enum(MoneyCurrency::class)],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $inboundIds = array_values(array_unique(array_map('intval', $validated['inbound_ids'])));
        $known = $this->inboundsFor((int) $validated['server_id'])->pluck('id')->all();

        if (array_diff($inboundIds, $known) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'inbound_ids' => __('inbound_resellers.inbound_not_on_server'),
            ]);
        }

        // Moving an allocation to another server would strand its accounts.
        if ($current !== null && (int) $current->server_id !== (int) $validated['server_id'] && $current->accounts()->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'server_id' => __('inbound_resellers.server_locked'),
            ]);
        }

        $gb = (float) $validated['quota_amount'] * ($validated['quota_unit'] === 'tb' ? 1024 : 1);

        return [
            'agent_user_id' => (int) $validated['agent_user_id'],
            'server_id' => (int) $validated['server_id'],
            'title' => $validated['title'] ?? null,
            'inbound_ids' => $inboundIds,
            'quota_bytes' => (int) round($gb * InboundAllocation::GB),
            'price_per_gb' => $validated['price_per_gb'],
            'currency' => $validated['currency'],
            'credit_limit' => $validated['credit_limit'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(InboundAllocation $allocation): array
    {
        $servers = Server::query()->where('type', ServerType::Sanaei)->orderBy('name')->get();
        $quotaGb = $allocation->quota_bytes ? $allocation->quota_bytes / InboundAllocation::GB : null;

        return [
            'allocation' => $allocation,
            'agents' => User::query()->where('role', UserRole::Agent)->orderBy('full_name')->get(['id', 'full_name', 'username']),
            'servers' => $servers,
            'inboundsByServer' => $servers->mapWithKeys(fn (Server $server): array => [
                $server->id => $this->inboundsFor((int) $server->id)->values()->all(),
            ])->all(),
            'quotaAmount' => $quotaGb === null ? null : ($quotaGb >= 1024 && fmod($quotaGb, 1024) == 0 ? $quotaGb / 1024 : round($quotaGb, 2)),
            'quotaUnit' => $quotaGb !== null && $quotaGb >= 1024 && fmod($quotaGb, 1024) == 0 ? 'tb' : ($quotaGb === null ? 'tb' : 'gb'),
        ];
    }

    /**
     * Enabled inbounds the panel last read from the server.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, protocol: ?string, port: ?int}>
     */
    protected function inboundsFor(int $serverId): \Illuminate\Support\Collection
    {
        return ServerInterface::query()
            ->where('server_id', $serverId)
            ->where('category', 'inbound')
            ->orderBy('name')
            ->get()
            ->map(function (ServerInterface $row): ?array {
                $key = (string) $row->remote_key;

                if (! str_starts_with($key, 'inbound:') || ! ctype_digit(substr($key, 8))) {
                    return null;
                }

                return [
                    'id' => (int) substr($key, 8),
                    'name' => (string) $row->name,
                    'protocol' => $row->protocol,
                    'port' => $row->port,
                    'enabled' => (bool) $row->is_enabled,
                ];
            })
            ->filter();
    }
}
