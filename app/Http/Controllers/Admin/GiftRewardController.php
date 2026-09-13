<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GiftRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class GiftRewardController extends Controller
{
    public function index(GiftRewardService $giftRewardService): View
    {
        $agents = $giftRewardService->activeAgents();
        $sellers = $giftRewardService->activeSellers();

        return view('admin.gifts.index', compact('agents', 'sellers'));
    }

    public function sellers(Request $request, GiftRewardService $giftRewardService): JsonResponse
    {
        $agentIds = collect($request->input('agent_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        $sellers = $giftRewardService->sellersForAgents($agentIds)->map(fn (User $seller): array => [
            'id' => $seller->id,
            'full_name' => $seller->full_name,
            'username' => $seller->username,
            'parent_id' => $seller->parent_id,
        ]);

        return response()->json(['sellers' => $sellers]);
    }

    public function storeDays(Request $request, GiftRewardService $giftRewardService): RedirectResponse
    {
        $validated = $request->validate([
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => ['integer', 'exists:users,id'],
            'seller_ids' => ['nullable', 'array'],
            'seller_ids.*' => ['integer', 'exists:users,id'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $agentIds = collect($validated['agent_ids'] ?? [])->map(fn ($id): int => (int) $id)->all();
        $sellerIds = collect($validated['seller_ids'] ?? [])->map(fn ($id): int => (int) $id)->all();

        try {
            $result = $giftRewardService->extendAccountDays(
                $request->user(),
                $agentIds,
                $sellerIds,
                (int) $validated['days'],
                $validated['note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', $exception->getMessage());
        }

        $message = __('gifts.days_summary', [
            'updated' => persian_digits((string) $result['updated']),
            'recipients' => persian_digits((string) $result['recipients']),
            'days' => persian_digits((string) $validated['days']),
            'skipped' => persian_digits((string) $result['skipped_unlimited']),
            'failed' => persian_digits((string) count($result['failed'])),
        ]);

        $redirect = redirect()
            ->route('admin.gifts.index')
            ->with(count($result['failed']) > 0 ? 'warning' : 'success', $message);

        if ($result['failed'] !== []) {
            $redirect->with('gift_failures', $result['failed']);
        }

        return $redirect;
    }

    public function storeWallet(Request $request, GiftRewardService $giftRewardService): RedirectResponse
    {
        $validated = $request->validate([
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => ['integer', 'exists:users,id'],
            'seller_ids' => ['nullable', 'array'],
            'seller_ids.*' => ['integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $agentIds = collect($validated['agent_ids'] ?? [])->map(fn ($id): int => (int) $id)->all();
        $sellerIds = collect($validated['seller_ids'] ?? [])->map(fn ($id): int => (int) $id)->all();

        try {
            $result = $giftRewardService->creditWallets(
                $request->user(),
                $agentIds,
                $sellerIds,
                money_string((string) $validated['amount']),
                $validated['note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', $exception->getMessage());
        }

        $message = __('gifts.wallet_summary', [
            'credited' => persian_digits((string) $result['credited']),
            'recipients' => persian_digits((string) $result['recipients']),
            'amount' => format_toman(money_string((string) $validated['amount'])),
            'failed' => persian_digits((string) count($result['failed'])),
        ]);

        $redirect = redirect()
            ->route('admin.gifts.index')
            ->with(count($result['failed']) > 0 ? 'warning' : 'success', $message);

        if ($result['failed'] !== []) {
            $redirect->with('gift_failures', $result['failed']);
        }

        return $redirect;
    }
}
