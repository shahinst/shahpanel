<?php

namespace Modules\ShahBot\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotUser;

/**
 * The people who joined a reseller's bot.
 *
 * A seller sees their own bot's members; an agent sees theirs and every one of
 * their direct sellers' bots, with a filter per seller. Which bots count is
 * worked out here from the logged-in user, never taken from the request, so a
 * filter value can only narrow the list.
 */
class CustomersController extends Controller
{
    public function index(Request $request): View
    {
        $me = $request->user();
        abort_unless(in_array($me->role, [UserRole::Agent, UserRole::Seller], true), 403);

        $owners = collect([$me]);

        if ($me->role === UserRole::Agent) {
            $owners = $owners->merge(User::query()->where('parent_id', $me->id)->where('role', UserRole::Seller)
                ->orderBy('full_name')->get(['id', 'full_name', 'username', 'role']));
        }

        $ownerFilter = (int) $request->query('owner', 0);
        $ownerIds = $ownerFilter > 0 && $owners->contains('id', $ownerFilter) ? [$ownerFilter] : $owners->pluck('id')->all();
        $botIds = BotInstance::query()->whereIn('owner_user_id', $ownerIds)->pluck('id');

        return view('shahbot::panel.customers', [
            'panel' => $me->role === UserRole::Agent ? 'agent' : 'seller',
            'owners' => $owners,
            'users' => self::filtered(BotUser::query()->whereIn('bot_id', $botIds), $request)
                ->with('bot.owner:id,full_name,username')->latest('id')->paginate(30)->withQueryString(),
        ]);
    }

    /**
     * Search shared with the admin list: telegram id, @username, name or phone,
     * plus "has bought" and a joined-date range.
     */
    public static function filtered($query, Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        return $query
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($w) use ($search): void {
                    $w->where('telegram_id', 'like', '%'.western_digits($search).'%')
                        ->orWhere('username', 'like', '%'.ltrim($search, '@').'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.western_digits($search).'%');
                });
            })
            ->when($request->query('bought') === '1', fn ($q) => $q->whereHas('orders', fn ($o) => $o->whereIn('type', ['buy', 'renew'])))
            ->when($request->query('bought') === '0', fn ($q) => $q->whereDoesntHave('orders', fn ($o) => $o->whereIn('type', ['buy', 'renew'])))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
    }
}
