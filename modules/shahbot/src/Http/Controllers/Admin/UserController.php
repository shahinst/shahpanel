<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use App\Enums\TransactionType;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\BotNotifier;
use Modules\ShahBot\Services\BotUserService;
use Modules\ShahBot\Services\CodeService;
use Modules\ShahBot\Services\ShopService;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $users = BotUser::query()
            ->with('bot.owner')
            ->withCount(['orders as paid_orders_count' => fn ($q) => $q->whereIn('type', ['buy', 'renew'])])
            ->withSum(['orders as paid_total' => fn ($q) => $q->whereIn('type', ['buy', 'renew'])], 'amount')
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($w) use ($search): void {
                    $w->where('telegram_id', 'like', '%'.western_digits($search).'%')
                        ->orWhere('username', 'like', '%'.ltrim($search, '@').'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.western_digits($search).'%');
                });
            })
            ->when($request->query('bot') === 'main', fn ($q) => $q->where('bot_id', 0))
            ->when((int) $request->query('owner') > 0, fn ($q) => $q->whereHas('bot', fn ($b) => $b->where('owner_user_id', (int) $request->query('owner'))))
            ->when(in_array($request->query('role'), ['agent', 'seller'], true), fn ($q) => $q->whereHas('bot.owner', fn ($o) => $o->where('role', $request->query('role'))))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($request->query('filter') === 'blocked', fn ($q) => $q->where('is_blocked', true))
            ->when($request->query('filter') === 'customers', fn ($q) => $q->whereHas('orders', fn ($o) => $o->whereIn('type', ['buy', 'renew'])))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('shahbot::users.index', ['users' => $users, 'search' => $search,
            'resellers' => User::query()->whereIn('id', BotInstance::query()->select('owner_user_id'))
                ->orderBy('full_name')->get(['id', 'full_name', 'username', 'role'])]);
    }

    public function show(BotUser $botUser, BotUserService $users, ShopService $shop): View
    {
        $botUser->load(['referrer', 'client']);

        return view('shahbot::users.show', [
            'botUser' => $botUser,
            'balance' => $botUser->client_user_id ? rescue(fn () => $users->balance($botUser), null, false) : null,
            'accounts' => $shop->accounts($botUser),
            'orders' => $botUser->orders()->with('duration.package')->latest('id')->limit(20)->get(),
            'payments' => $botUser->payments()->latest('id')->limit(20)->get(),
            'referrals' => $botUser->referrals()->count(),
        ]);
    }

    public function toggleBlock(BotUser $botUser): RedirectResponse
    {
        $botUser->forceFill(['is_blocked' => ! $botUser->is_blocked])->save();

        return back()->with('success', __('shahbot::admin.saved'));
    }

    public function message(Request $request, BotUser $botUser, BotNotifier $notifier): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:3500']]);

        $ok = $notifier->user($botUser, e($data['text']));

        return back()->with($ok ? 'success' : 'error', __($ok ? 'shahbot::admin.message_sent' : 'shahbot::admin.message_failed'));
    }

    /**
     * Manual wallet correction. A credit is funded by the bot owner (like a
     * gift); a debit goes back to the owner.
     */
    public function wallet(Request $request, BotUser $botUser, BotUserService $users, CodeService $codes, WalletService $wallets): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $amount = number_format((float) $data['amount'], 2, '.', '');
        $note = 'Bot wallet adjustment by '.$request->user()->username.($data['note'] ? ': '.$data['note'] : '');

        try {
            DB::transaction(function () use ($data, $botUser, $users, $codes, $wallets, $amount, $note): void {
                if ($data['direction'] === 'credit') {
                    $codes->transfer($botUser, $amount, $note);

                    return;
                }

                $client = $users->client($botUser);
                $owner = $users->owner($botUser);
                $wallets->debit($client, $amount, TransactionType::Adjustment, ['description' => $note, 'source_user_id' => $owner->id]);
                $wallets->credit($owner, $amount, TransactionType::Adjustment, ['description' => $note, 'source_user_id' => $client->id]);
            });
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.saved'));
    }
}
