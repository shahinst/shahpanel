<?php

namespace Modules\Markup\Http\Controllers;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\Markup\Markup;

class MarkupController extends Controller
{
    public function agent(Request $request): View
    {
        $agent = $request->user();
        $sellers = User::query()->where('parent_id', $agent->id)->where('role', UserRole::Seller)->orderBy('username')->get(['id', 'username', 'full_name']);
        $rows = DB::table('agent_markups')->where('agent_user_id', $agent->id)->pluck('percent', 'seller_user_id');

        return view('markup::agent', [
            'sellers' => $sellers,
            'default' => $rows[''] ?? null,
            'overrides' => $rows,
            'max' => Markup::maxPercent(),
            'earned' => $this->earnedBySeller([$agent->id])[$agent->id] ?? [],
        ]);
    }

    public function saveAgent(Request $request): RedirectResponse
    {
        $agent = $request->user();
        $max = Markup::maxPercent();
        $sellerIds = User::query()->where('parent_id', $agent->id)->where('role', UserRole::Seller)->pluck('id')->all();

        $data = $request->validate([
            'default' => ['nullable', 'numeric', 'min:0', 'max:'.$max],
            'sellers' => ['array'],
            'sellers.*' => ['nullable', 'numeric', 'min:0', 'max:'.$max],
        ]);

        DB::transaction(function () use ($agent, $data, $sellerIds): void {
            $this->put($agent->id, null, $data['default'] ?? null);

            foreach ($data['sellers'] ?? [] as $sellerId => $percent) {
                // Only the agent's own sellers; anything else in the request is ignored.
                if (in_array((int) $sellerId, $sellerIds, true)) {
                    $this->put($agent->id, (int) $sellerId, $percent);
                }
            }
        });

        return back()->with('success', __('markup::markup.saved'));
    }

    public function admin(): View
    {
        $agents = User::query()->where('role', UserRole::Agent)->orderBy('username')->get(['id', 'username', 'full_name']);
        $defaults = DB::table('agent_markups')->whereNull('seller_user_id')->pluck('percent', 'agent_user_id');
        $earned = $this->earnedBySeller($agents->pluck('id')->all());

        return view('markup::admin', [
            'agents' => $agents,
            'defaults' => $defaults,
            'totals' => collect($earned)->map(fn (array $bySeller): string => (string) array_sum(array_column($bySeller, 'amount'))),
            'sellerCounts' => User::query()->where('role', UserRole::Seller)->whereIn('parent_id', $agents->pluck('id'))->selectRaw('parent_id, COUNT(*) n')->groupBy('parent_id')->pluck('n', 'parent_id'),
            'max' => Markup::maxPercent(),
        ]);
    }

    public function saveMax(Request $request): RedirectResponse
    {
        $data = $request->validate(['max' => ['required', 'numeric', 'min:0', 'max:100']]);
        Setting::setValue(Markup::MAX_KEY, (string) round((float) $data['max'], 2));

        return back()->with('success', __('markup::markup.saved'));
    }

    protected function put(int $agentId, ?int $sellerId, mixed $percent): void
    {
        $query = DB::table('agent_markups')->where('agent_user_id', $agentId)
            ->where(fn ($q) => $sellerId === null ? $q->whereNull('seller_user_id') : $q->where('seller_user_id', $sellerId));

        if ($percent === null || $percent === '') {
            $query->delete();

            return;
        }

        if ($query->exists()) {
            $query->update(['percent' => round((float) $percent, 2), 'updated_at' => now()]);
        } else {
            DB::table('agent_markups')->insert(['agent_user_id' => $agentId, 'seller_user_id' => $sellerId, 'percent' => round((float) $percent, 2), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * The agent's share of each seller's sales over the last 30 days, from the
     * margin rows the purchase itself wrote — the same numbers the wallet holds.
     *
     * @return array<int, array<int, array{seller: string, amount: float, sales: int}>>
     */
    protected function earnedBySeller(array $agentIds): array
    {
        $rows = DB::table('transactions as t')
            ->join('accounts as a', 'a.id', '=', 't.related_account_id')
            ->join('users as s', 's.id', '=', 'a.owner_seller_id')
            ->whereIn('t.user_id', $agentIds)
            ->whereIn('t.type', [TransactionType::Margin->value, TransactionType::Commission->value])
            ->where('t.created_at', '>=', now()->subDays(30))
            ->groupBy('t.user_id', 's.id', 's.username')
            ->selectRaw('t.user_id agent_id, s.id seller_id, s.username seller, SUM(t.amount) amount, COUNT(*) sales')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->agent_id][(int) $row->seller_id] = ['seller' => $row->seller, 'amount' => (float) $row->amount, 'sales' => (int) $row->sales];
        }

        return $out;
    }
}
