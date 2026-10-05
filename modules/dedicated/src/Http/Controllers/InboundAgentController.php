<?php

namespace Modules\Dedicated\Http\Controllers;

use App\Enums\MoneyCurrency;
use App\Enums\ServerType;
use App\Enums\UserRole;
use App\Models\InboundAllocation;
use App\Models\Server;
use App\Models\ServerInterface;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\Dedicated\Models\InboundChargeRequest;
use Modules\Dedicated\Models\InboundVolumePack;
use Modules\Dedicated\Services\AgentFactory;
use Modules\Dedicated\Services\InboundVolumeService;
use Throwable;

/**
 * The admin's page for inbound agents: who they are and which inbound each
 * one sells on, the volume packs offered to them, and their requests for
 * more volume.
 */
class InboundAgentController extends Controller
{
    /** The list, like the regular agents page: one row per inbound agent. */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $agents = User::query()
            ->where('role', UserRole::Agent)
            ->whereIn('id', InboundAllocation::query()->select('agent_user_id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('username', 'like', "%{$search}%")
                ->orWhere('full_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->with('wallets')
            ->orderBy('username')
            ->paginate(15)
            ->withQueryString();

        return view('dedicated::admin.inbound', [
            'agents' => $agents,
            'allocations' => InboundAllocation::query()->with('server')
                ->whereIn('agent_user_id', $agents->pluck('id'))->get()->groupBy('agent_user_id'),
            'pendingCount' => InboundChargeRequest::query()->where('status', InboundChargeRequest::PENDING)->count(),
            'pendingByAgent' => InboundChargeRequest::query()->where('status', InboundChargeRequest::PENDING)
                ->selectRaw('agent_user_id, COUNT(*) as c')->groupBy('agent_user_id')->pluck('c', 'agent_user_id'),
        ]);
    }

    public function create(): View
    {
        return view('dedicated::admin.inbound-create', $this->serverChoices());
    }

    /** One agent's inbounds: change what they sell on, add another, pause one. */
    public function edit(User $agent): View
    {
        abort_unless(
            $agent->role === UserRole::Agent
                && InboundAllocation::query()->where('agent_user_id', $agent->id)->exists(),
            404,
        );

        return view('dedicated::admin.inbound-edit', [
            'agent' => $agent,
            'rows' => InboundAllocation::query()->with('server')->where('agent_user_id', $agent->id)->oldest()->get(),
            'requests' => InboundChargeRequest::query()->with(['allocation', 'pack'])
                ->where('agent_user_id', $agent->id)->latest()->limit(20)->get(),
        ] + $this->serverChoices());
    }

    /** Volume packs and the agents' requests for them, in one place. */
    public function volume(): View
    {
        return view('dedicated::admin.inbound-volume', [
            'servers' => Server::query()->where('type', ServerType::Sanaei)->orderBy('name')->get(),
            'packs' => InboundVolumePack::query()->with('server')->orderBy('server_id')->orderBy('sort_order')->orderBy('gb')->get(),
            'pending' => InboundChargeRequest::query()->with(['agent', 'allocation', 'pack'])
                ->where('status', InboundChargeRequest::PENDING)->oldest()->get(),
            'history' => InboundChargeRequest::query()->with(['agent', 'allocation'])
                ->where('status', '!=', InboundChargeRequest::PENDING)->latest('reviewed_at')->limit(30)->get(),
        ]);
    }

    /** Another inbound for an agent who already has one. */
    public function addInbound(Request $request, User $agent): RedirectResponse
    {
        abort_unless(InboundAllocation::query()->where('agent_user_id', $agent->id)->exists(), 404);

        $data = $this->allocationData($request);

        InboundAllocation::query()->create([
            'agent_user_id' => $agent->id,
            'server_id' => $data['server_id'],
            'title' => $data['title'],
            'inbound_ids' => $data['inbound_ids'],
            'quota_bytes' => $data['quota_bytes'],
            'price_per_gb' => '0.00',
            'currency' => MoneyCurrency::IRT->value,
            'credit_limit' => 0,
        ]);

        return back()->with('success', __('dedicated::admin.inbound_added'));
    }

    /**
     * Title, inbounds and quota of one allocation. The server stays: accounts
     * already sold live on it, and moving them is not what this form does.
     */
    public function updateInbound(Request $request, InboundAllocation $allocation): RedirectResponse
    {
        $request->merge(['server_id' => $allocation->server_id]);
        $data = $this->allocationData($request);

        // Lowering the quota below what is already used would suspend the
        // agent on the next billing run without anyone noticing why.
        if ($data['quota_bytes'] < (int) $allocation->used_bytes) {
            throw ValidationException::withMessages(['quota_gb' => __('dedicated::admin.quota_below_used')]);
        }

        $allocation->update([
            'title' => $data['title'],
            'inbound_ids' => $data['inbound_ids'],
            'quota_bytes' => $data['quota_bytes'],
        ]);

        return back()->with('success', __('app.saved'));
    }

    /**
     * @return array{server_id: int, title: ?string, inbound_ids: list<int>, quota_bytes: int}
     */
    protected function allocationData(Request $request): array
    {
        $data = $request->validate([
            'server_id' => ['required', Rule::exists('servers', 'id')->where('type', ServerType::Sanaei->value)],
            'title' => ['nullable', 'string', 'max:120'],
            'inbound_ids' => ['required', 'array', 'min:1'],
            'inbound_ids.*' => ['integer', 'min:1'],
            'quota_gb' => ['required', 'integer', 'min:0'],
        ]);

        $inboundIds = array_values(array_unique(array_map('intval', $data['inbound_ids'])));

        if (array_diff($inboundIds, array_keys($this->inboundsFor((int) $data['server_id']))) !== []) {
            throw ValidationException::withMessages(['inbound_ids' => __('inbound_resellers.inbound_not_on_server')]);
        }

        return [
            'server_id' => (int) $data['server_id'],
            'title' => $data['title'] ?? null,
            'inbound_ids' => $inboundIds,
            'quota_bytes' => (int) $data['quota_gb'] * InboundAllocation::GB,
        ];
    }

    /** @return array{servers: Collection, inbounds: array<int, array<int, string>>} */
    protected function serverChoices(): array
    {
        $servers = Server::query()->where('type', ServerType::Sanaei)->orderBy('name')->get();

        return [
            'servers' => $servers,
            'inbounds' => $servers->mapWithKeys(fn (Server $s): array => [$s->id => $this->inboundsFor($s->id)])->all(),
        ];
    }

    /**
     * A new agent and their inbound, made together: an inbound agent is an
     * agent who sells on an inbound, so one without the other is only half
     * made.
     */
    public function store(Request $request, AgentFactory $factory): RedirectResponse
    {
        $data = $request->validate(AgentFactory::rules());
        $allocation = $this->allocationData($request);

        $agent = DB::transaction(function () use ($data, $allocation, $factory, $request): User {
            $agent = $factory->create($data, $request->user());

            // Volume is prepaid through charge requests, so the per-gigabyte
            // after-the-fact billing stays at zero.
            InboundAllocation::query()->create([
                'agent_user_id' => $agent->id,
                'server_id' => $allocation['server_id'],
                'title' => $allocation['title'],
                'inbound_ids' => $allocation['inbound_ids'],
                'quota_bytes' => $allocation['quota_bytes'],
                'price_per_gb' => '0.00',
                'currency' => MoneyCurrency::IRT->value,
                'credit_limit' => 0,
            ]);

            return $agent;
        });

        return redirect()->route('admin.inbound-agents.edit', $agent)->with('success', __('dedicated::admin.inbound_agent_created'));
    }

    public function storePack(Request $request): RedirectResponse
    {
        InboundVolumePack::query()->create($this->packData($request));

        return back()->with('success', __('app.saved'));
    }

    public function updatePack(Request $request, InboundVolumePack $pack): RedirectResponse
    {
        $pack->update($this->packData($request));

        return back()->with('success', __('app.saved'));
    }

    public function destroyPack(InboundVolumePack $pack): RedirectResponse
    {
        // Requests keep their own copy of the price and volume, so removing a
        // pack never changes one already made.
        $pack->delete();

        return back()->with('success', __('app.deleted'));
    }

    public function approve(Request $request, InboundChargeRequest $chargeRequest, InboundVolumeService $volume): RedirectResponse
    {
        $data = $request->validate([
            'approved_gb' => ['required', 'integer', 'min:1', 'max:1000000'],
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $volume->approve($chargeRequest, (int) $data['approved_gb'], $request->user(), $data['admin_note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('dedicated::admin.approve_failed', ['error' => $e->getMessage()]));
        }

        return back()->with('success', __('dedicated::admin.request_approved'));
    }

    public function reject(Request $request, InboundChargeRequest $chargeRequest, InboundVolumeService $volume): RedirectResponse
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:500']]);

        try {
            $volume->reject($chargeRequest, $request->user(), $data['admin_note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('dedicated::admin.request_rejected'));
    }

    protected function packData(Request $request): array
    {
        $data = $request->validate([
            'server_id' => ['required', Rule::exists('servers', 'id')->where('type', ServerType::Sanaei->value)],
            'title' => ['required', 'string', 'max:120'],
            'gb' => ['required', 'integer', 'min:1', 'max:1000000'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return [
            'server_id' => (int) $data['server_id'],
            'title' => $data['title'],
            'gb' => (int) $data['gb'],
            'price' => number_format((float) $data['price'], 2, '.', ''),
            'currency' => MoneyCurrency::IRT->value,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * Inbounds the panel last read from the server, keyed by inbound id.
     *
     * @return array<int, string>
     */
    protected function inboundsFor(int $serverId): array
    {
        return ServerInterface::query()
            ->where('server_id', $serverId)->where('category', 'inbound')->orderBy('name')->get()
            ->filter(fn (ServerInterface $row): bool => str_starts_with((string) $row->remote_key, 'inbound:')
                && ctype_digit(substr((string) $row->remote_key, 8)))
            ->mapWithKeys(fn (ServerInterface $row): array => [
                (int) substr((string) $row->remote_key, 8) => trim($row->name.($row->port ? ' · '.$row->port : '')),
            ])->all();
    }
}

