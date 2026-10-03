<?php

namespace Modules\ShahBot\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Support\BotAccess;

/**
 * Who gets their own sales bot: the admin decides for agents (and sellers
 * directly under the admin), each agent decides for their own sellers.
 */
class BotAccessController extends Controller
{
    public function admin(Request $request, BotAccess $access): View
    {
        $users = User::query()->whereIn('role', [UserRole::Agent, UserRole::Seller])
            ->with('parent:id,full_name,username,role')
            ->orderBy('role')->orderBy('full_name')
            ->get(['id', 'full_name', 'username', 'role', 'parent_id']);

        return view('shahbot::access', [
            'panel' => 'admin',
            'users' => $users,
            'granted' => $access->grantedIds()->flip(),
            'action' => route('admin.shahbot.access.update'),
            'locked' => false,
        ]);
    }

    public function agent(Request $request, BotAccess $access): View
    {
        $agent = $request->user();
        abort_unless($agent->role === UserRole::Agent, 403);

        return view('shahbot::access', [
            'panel' => 'agent',
            'users' => User::query()->where('parent_id', $agent->id)->where('role', UserRole::Seller)
                ->orderBy('full_name')->get(['id', 'full_name', 'username', 'role', 'parent_id']),
            'granted' => $access->grantedIds()->flip(),
            'action' => route('agent.shahbot.access.update'),
            // An agent without access of their own has nothing to hand out.
            'locked' => ! $access->allows($agent),
        ]);
    }

    public function update(Request $request, BotAccess $access): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'on' => ['required', 'boolean'],
        ]);

        $target = User::query()->findOrFail($data['user_id']);

        // The target comes from the form, so who may change it is decided
        // here and not by which rows the page happened to draw.
        abort_unless($access->canManage($request->user(), $target), 403);

        $access->set($request->user(), $target, (bool) $data['on']);

        return back()->with('success', __('shahbot::admin.saved'));
    }
}
