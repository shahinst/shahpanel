<?php

namespace Modules\Tunneling\Http\Controllers\Admin;

use AppHttpControllersController;
use App\Enums\ServerType;
use App\Enums\TunnelKind;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Tunneling\TunnelWizardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * 5-step, session-backed tunneling wizard: a fast opinionated front door
 * that builds a normal TunnelGroup (same engine as the advanced form in
 * TunnelGroupController) without exposing every advanced knob up front.
 *
 * Step 1: client service type(s) — WireGuard / PPP / both.
 * Step 2: Iran server + one or more foreign (exit) servers.
 * Step 3: tunnel kind(s) — if more than one, balancer vs. priority failover.
 * Step 4: wipe previous router configuration?
 * Step 5: full review + start.
 */
class TunnelWizardController extends Controller
{
    protected const SESSION_KEY = 'tunneling_wizard';

    /** Kinds offered by the wizard, in a fixed, deterministic order. */
    protected const AVAILABLE_KINDS = [
        TunnelKind::L2tpV3Udp,
        TunnelKind::Gre,
        TunnelKind::Ipip,
        TunnelKind::Eoip,
        TunnelKind::Gre6,
        TunnelKind::Sixto4,
    ];

    public function step1(): View
    {
        return view('tunneling::wizard.step1', [
            'data' => $this->data(),
        ]);
    }

    public function step1Store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'client_service_types' => ['required', 'array', 'min:1'],
            'client_service_types.*' => ['string', Rule::in(['wireguard', 'ppp'])],
        ]);

        $this->put([
            'name' => $validated['name'] ?? null,
            'client_service_types' => array_values($validated['client_service_types']),
        ]);

        return redirect()->route('admin.tunneling.wizard.step2');
    }

    public function step2(): View|RedirectResponse
    {
        if ($redirect = $this->requireStep(1)) {
            return $redirect;
        }

        return view('tunneling::wizard.step2', [
            'data' => $this->data(),
            'servers' => $this->mikrotikServers(),
        ]);
    }

    public function step2Store(Request $request): RedirectResponse
    {
        if ($redirect = $this->requireStep(1)) {
            return $redirect;
        }

        $validated = $request->validate([
            'iran_server_id' => ['required', 'integer', Rule::exists('servers', 'id')],
            'exit_ids' => ['required', 'array', 'min:1'],
            'exit_ids.*' => ['integer', 'distinct', Rule::exists('servers', 'id'), 'different:iran_server_id'],
        ]);

        $this->put([
            'iran_server_id' => (int) $validated['iran_server_id'],
            'exit_ids' => array_map('intval', $validated['exit_ids']),
        ]);

        return redirect()->route('admin.tunneling.wizard.step3');
    }

    public function step3(): View|RedirectResponse
    {
        if ($redirect = $this->requireStep(2)) {
            return $redirect;
        }

        return view('tunneling::wizard.step3', [
            'data' => $this->data(),
            'kinds' => self::AVAILABLE_KINDS,
        ]);
    }

    public function step3Store(Request $request): RedirectResponse
    {
        if ($redirect = $this->requireStep(2)) {
            return $redirect;
        }

        $availableValues = array_map(fn (TunnelKind $k): string => $k->value, self::AVAILABLE_KINDS);

        $validated = $request->validate([
            'tunnel_kinds' => ['required', 'array', 'min:1'],
            'tunnel_kinds.*' => ['string', Rule::in($availableValues)],
            'kind_balance_choice' => ['nullable', 'string', Rule::in(['balanced', 'priority'])],
            'kind_priority_first' => ['nullable', 'string', Rule::in($availableValues)],
        ]);

        // Preserve the wizard's fixed, deterministic kind order (not raw POST order).
        $selected = array_values(array_intersect($availableValues, $validated['tunnel_kinds']));

        $selectionMode = 'single';
        $priorityOrder = null;

        if (count($selected) > 1) {
            $selectionMode = ($validated['kind_balance_choice'] ?? 'balanced') === 'priority' ? 'priority' : 'balanced';

            if ($selectionMode === 'priority') {
                $first = $validated['kind_priority_first'] ?? $selected[0];

                if (! in_array($first, $selected, true)) {
                    $first = $selected[0];
                }

                $priorityOrder = array_values(array_unique(array_merge([$first], $selected)));
            }
        }

        $this->put([
            'tunnel_kinds' => $selected,
            'kind_selection_mode' => $selectionMode,
            'kind_priority_order' => $priorityOrder,
        ]);

        return redirect()->route('admin.tunneling.wizard.step4');
    }

    public function step4(): View|RedirectResponse
    {
        if ($redirect = $this->requireStep(3)) {
            return $redirect;
        }

        return view('tunneling::wizard.step4', [
            'data' => $this->data(),
        ]);
    }

    public function step4Store(Request $request): RedirectResponse
    {
        if ($redirect = $this->requireStep(3)) {
            return $redirect;
        }

        $validated = $request->validate([
            'wipe_previous' => ['nullable', 'boolean'],
        ]);

        $this->put([
            'wipe_previous' => $request->boolean('wipe_previous'),
        ]);

        return redirect()->route('admin.tunneling.wizard.step5');
    }

    public function step5(): View|RedirectResponse
    {
        if ($redirect = $this->requireStep(4)) {
            return $redirect;
        }

        $data = $this->data();

        $iranServer = Server::query()->find($data['iran_server_id'] ?? null);
        $exitServers = Server::query()->whereIn('id', $data['exit_ids'] ?? [])->get();

        $kinds = array_map(
            static fn (string $value): TunnelKind => TunnelKind::from($value),
            $data['tunnel_kinds'] ?? [],
        );

        $priorityOrder = ! empty($data['kind_priority_order'])
            ? array_map(static fn (string $v): TunnelKind => TunnelKind::from($v), $data['kind_priority_order'])
            : null;

        return view('tunneling::wizard.step5', [
            'data' => $data,
            'iranServer' => $iranServer,
            'exitServers' => $exitServers,
            'kinds' => $kinds,
            'priorityOrder' => $priorityOrder,
        ]);
    }

    public function finish(TunnelWizardService $wizard): RedirectResponse
    {
        if ($redirect = $this->requireStep(4)) {
            return $redirect;
        }

        $data = $this->data();

        try {
            $group = $wizard->createGroup($data, auth()->user());
        } catch (RuntimeException $e) {
            return redirect()
                ->route('admin.tunneling.wizard.step5')
                ->with('error', $e->getMessage());
        }

        Session::forget(self::SESSION_KEY);

        return redirect()
            ->route('admin.tunneling.groups.show', $group)
            ->with('success', __('tunneling.wizard_group_created'));
    }

    public function restart(): RedirectResponse
    {
        Session::forget(self::SESSION_KEY);

        return redirect()->route('admin.tunneling.wizard.step1');
    }

    /** @return array<string, mixed> */
    protected function data(): array
    {
        return (array) Session::get(self::SESSION_KEY, []);
    }

    /** @param  array<string, mixed>  $values */
    protected function put(array $values): void
    {
        Session::put(self::SESSION_KEY, array_merge($this->data(), $values));
    }

    /**
     * Guards a step against direct navigation before earlier steps are
     * filled in; redirects back to the first incomplete step.
     */
    protected function requireStep(int $completedThrough): ?RedirectResponse
    {
        $data = $this->data();

        if ($completedThrough >= 1 && empty($data['client_service_types'])) {
            return redirect()->route('admin.tunneling.wizard.step1')->with('warning', __('tunneling.wizard_step_required'));
        }

        if ($completedThrough >= 2 && (empty($data['iran_server_id']) || empty($data['exit_ids']))) {
            return redirect()->route('admin.tunneling.wizard.step2')->with('warning', __('tunneling.wizard_step_required'));
        }

        if ($completedThrough >= 3 && empty($data['tunnel_kinds'])) {
            return redirect()->route('admin.tunneling.wizard.step3')->with('warning', __('tunneling.wizard_step_required'));
        }

        if ($completedThrough >= 4 && ! array_key_exists('wipe_previous', $data)) {
            return redirect()->route('admin.tunneling.wizard.step4')->with('warning', __('tunneling.wizard_step_required'));
        }

        return null;
    }

    protected function mikrotikServers()
    {
        return Server::query()
            ->active()
            ->where('type', ServerType::Mikrotik)
            ->orderBy('name')
            ->get();
    }
}
