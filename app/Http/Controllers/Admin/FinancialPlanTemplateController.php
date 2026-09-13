<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentFinancialPlanTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialPlanTemplateController extends Controller
{
    public function index(): View
    {
        $templates = AgentFinancialPlanTemplate::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20);

        return view('admin.financial-plans.templates.index', compact('templates'));
    }

    public function create(): View
    {
        return view('admin.financial-plans.templates.form', [
            'template' => new AgentFinancialPlanTemplate([
                'is_active' => true,
                'sort_order' => 0,
                'discount_percent' => 0,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        AgentFinancialPlanTemplate::query()->create($validated);

        return redirect()
            ->route('admin.financial-plan-templates.index')
            ->with('success', __('app.saved'));
    }

    public function edit(AgentFinancialPlanTemplate $financialPlanTemplate): View
    {
        return view('admin.financial-plans.templates.form', [
            'template' => $financialPlanTemplate,
        ]);
    }

    public function update(Request $request, AgentFinancialPlanTemplate $financialPlanTemplate): RedirectResponse
    {
        $financialPlanTemplate->update($this->validated($request));

        return redirect()
            ->route('admin.financial-plan-templates.index')
            ->with('success', __('app.saved'));
    }

    public function destroy(AgentFinancialPlanTemplate $financialPlanTemplate): RedirectResponse
    {
        $financialPlanTemplate->delete();

        return redirect()
            ->route('admin.financial-plan-templates.index')
            ->with('success', __('app.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'credit_amount' => ['required', 'numeric', 'min:1'],
            'purchase_price' => ['required', 'numeric', 'min:1'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]) + [
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) ($request->input('sort_order') ?? 0),
        ];
    }
}
