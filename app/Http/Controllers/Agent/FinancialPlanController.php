<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentFinancialPlanPurchase;
use App\Services\AgentFinancialPlanService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialPlanController extends Controller
{
    public function index(Request $request, AgentFinancialPlanService $financialPlanService): View
    {
        $preview = $financialPlanService->previewForAgent($request->user());

        $purchases = AgentFinancialPlanPurchase::query()
            ->where('agent_id', $request->user()->id)
            ->latest('purchased_at')
            ->paginate(20);

        return view('agent.financial-plans.index', [
            'purchases' => $purchases,
            'preview' => $preview,
        ]);
    }
}
