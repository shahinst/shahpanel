<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AgentFinancialPlanPurchase;
use App\Models\AgentFinancialPlanTemplate;
use App\Models\User;
use App\Services\AgentFinancialPlanService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentFinancialPlanPurchaseController extends Controller
{
    public function __construct(
        protected AgentFinancialPlanService $financialPlanService,
        protected WalletService $walletService,
    ) {}

    public function index(Request $request): View
    {
        $purchases = AgentFinancialPlanPurchase::query()
            ->with(['agent', 'template', 'soldBy'])
            ->when($request->filled('agent_id'), fn ($q) => $q->where('agent_id', $request->integer('agent_id')))
            ->latest('purchased_at')
            ->paginate(20)
            ->withQueryString();

        $agents = User::query()
            ->where('role', UserRole::Agent)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'username']);

        return view('admin.financial-plans.purchases.index', compact('purchases', 'agents'));
    }

    public function create(): View
    {
        $templates = AgentFinancialPlanTemplate::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $agents = User::query()
            ->where('role', UserRole::Agent)
            ->with('wallet')
            ->orderBy('full_name')
            ->get();

        foreach ($agents as $agent) {
            if ($agent->wallet === null) {
                $this->walletService->getOrCreateWallet($agent);
                $agent->load('wallet');
            }
        }

        return view('admin.financial-plans.purchases.sell', compact('templates', 'agents'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'exists:users,id'],
            'template_id' => ['required', 'exists:agent_financial_plan_templates,id'],
        ]);

        $agent = User::query()->findOrFail($validated['agent_id']);
        $template = AgentFinancialPlanTemplate::query()->findOrFail($validated['template_id']);

        try {
            $this->financialPlanService->sellTemplateToAgent($template, $agent, $request->user());
        } catch (\Throwable $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.agent-financial-plans.index')
            ->with('success', __('financial_plans.sold_success'));
    }
}
