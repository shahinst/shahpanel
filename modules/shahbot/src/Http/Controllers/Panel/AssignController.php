<?php

namespace Modules\ShahBot\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\ShahBot\Models\AccountAssignment;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\AccountAssignService;
use Modules\ShahBot\Support\BotAccess;

/**
 * "Give an account" on a reseller's bot pages: their own accounts on one side,
 * the people who started their bot on the other.
 */
class AssignController extends Controller
{
    public function index(Request $request): View
    {
        $me = $this->reseller($request);
        $bot = BotInstance::query()->where('owner_user_id', $me->id)->first();
        $search = trim((string) $request->query('q', ''));

        $accounts = Account::query()->ownedByHierarchy($me)
            ->with(['clientUser:id,full_name,username', 'ownerSeller:id,full_name,username'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('remote_username', 'like', '%'.$search.'%')
                ->orWhere('display_label', 'like', '%'.$search.'%')))
            ->latest('id')->paginate(20)->withQueryString();

        $active = AccountAssignment::query()->active()->with('botUser')
            ->whereIn('account_id', $accounts->pluck('id'))->get()->keyBy('account_id');

        return view('shahbot::panel.assign', [
            'panel' => $me->role === UserRole::Agent ? 'agent' : 'seller',
            'bot' => $bot,
            'accounts' => $accounts,
            'active' => $active,
            'members' => $bot === null ? collect() : BotUser::query()->where('bot_id', $bot->id)
                ->latest('id')->limit(1000)->get(['id', 'telegram_id', 'first_name', 'last_name', 'username']),
        ]);
    }

    public function store(Request $request, Account $account, AccountAssignService $assign): RedirectResponse
    {
        $me = $this->reseller($request);
        $bot = BotInstance::query()->where('owner_user_id', $me->id)->first();
        $data = $request->validate(['member' => ['required', 'string', 'max:64']]);

        if ($bot === null) {
            return back()->with('error', __('shahbot::admin.my_bot_needed_first'));
        }

        $member = $assign->findMember($bot->id, $data['member']);

        if ($member === null) {
            return back()->withInput()->with('error', __('shahbot::bot.give_user_not_found'));
        }

        try {
            $told = $assign->assign($me, $bot->id, $account, $member, $me);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with($told ? 'success' : 'warning', __($told ? 'shahbot::admin.assign_done' : 'shahbot::admin.assign_done_unsent', [
            'account' => $account->display_label ?: $account->remote_username,
            'member' => $member->displayName(),
        ]));
    }

    public function destroy(Request $request, AccountAssignment $assignment, AccountAssignService $assign): RedirectResponse
    {
        $me = $this->reseller($request);
        $bot = BotInstance::query()->where('owner_user_id', $me->id)->first();

        abort_unless($bot !== null && (int) $assignment->bot_id === (int) $bot->id, 404);

        try {
            $assign->revoke($me, $assignment);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.assign_revoked'));
    }

    protected function reseller(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Agent, UserRole::Seller], true), 403);
        abort_unless(app(BotAccess::class)->allows($user), 403);

        return $user;
    }
}
