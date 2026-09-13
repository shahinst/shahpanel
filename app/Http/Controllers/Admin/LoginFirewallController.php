<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Models\IpWhitelist;
use App\Models\LoginAttempt;
use App\Services\FirewallService;
use App\Services\IpGuardService;
use App\Services\SecurityShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Admin view over the login firewall: who was blocked, from where, and the
 * controls to let them back in.
 */
class LoginFirewallController extends Controller
{
    public function __construct(
        protected IpGuardService $guard,
        protected FirewallService $firewall,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'state' => ['nullable', 'in:active,lifted,all'],
            'country' => ['nullable', 'string', 'max:2'],
        ]);

        $state = $filters['state'] ?? 'active';

        $query = BlockedIp::query()->with('unblockedBy')->orderByDesc('blocked_at');

        if ($state === 'active') {
            $query->active();
        } elseif ($state === 'lifted') {
            $query->where(function ($q): void {
                $q->whereNotNull('unblocked_at')
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('expires_at')->where('expires_at', '<=', now());
                    });
            });
        }

        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('ip', 'like', $term)->orWhere('last_username', 'like', $term);
            });
        }

        if (! empty($filters['country'])) {
            $query->where('country_code', strtoupper($filters['country']));
        }

        $blocks = $query->paginate(30)->withQueryString();

        return view('admin.login-firewall.index', [
            'blocks' => $blocks,
            'state' => $state,
            'filters' => $filters,
            'whitelist' => IpWhitelist::query()->with('createdBy')->orderBy('ip')->get(),
            'firewallStatus' => $this->firewall->status(),
            'stats' => [
                'active' => BlockedIp::query()->active()->count(),
                'total' => BlockedIp::query()->count(),
                'in_firewall' => BlockedIp::query()->active()->where('in_firewall', true)->count(),
                'failures_24h' => LoginAttempt::query()
                    ->where('succeeded', false)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
            ],
            'topCountries' => BlockedIp::query()
                ->selectRaw('country_code, COUNT(*) as total')
                ->whereNotNull('country_code')
                ->groupBy('country_code')
                ->orderByDesc('total')
                ->limit(8)
                ->get(),
            'settings' => [
                'max_attempts' => IpGuardService::MAX_ATTEMPTS,
                'window' => IpGuardService::WINDOW_MINUTES,
                'block_minutes' => IpGuardService::BLOCK_MINUTES,
            ],
        ]);
    }

    public function unblock(Request $request, BlockedIp $blockedIp): RedirectResponse
    {
        $this->guard->unblock($blockedIp, $request->user());

        return back()->with('success', __('loginfw.unblocked', ['ip' => $blockedIp->ip]));
    }

    public function blockManually(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'string', 'max:45'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
        ]);

        $block = $this->guard->block(
            $data['ip'],
            reason: 'manual',
            minutes: $data['minutes'] ?? IpGuardService::BLOCK_MINUTES,
        );

        if ($block === null) {
            return back()->with('error', __('loginfw.block_refused'));
        }

        $this->guard->escalateToFirewall($block);

        return back()->with('success', __('loginfw.blocked', ['ip' => $block->ip]));
    }

    public function storeWhitelist(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'string', 'max:45', 'unique:ip_whitelist,ip'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        IpWhitelist::query()->create([
            'ip' => $data['ip'],
            'note' => $data['note'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        Cache::forget('ip-whitelist:'.$data['ip']);

        // A whitelisted address should not stay blocked from a previous strike.
        $existing = BlockedIp::query()->where('ip', $data['ip'])->first();

        if ($existing !== null && $existing->isActive()) {
            $this->guard->unblock($existing, $request->user());
        }

        // Best-effort: keep CrowdSec / Web Shield allowlist in sync.
        try {
            app(SecurityShieldService::class)->whitelistAdd($data['ip']);
        } catch (\Throwable) {
            // Panel login whitelist must succeed even if shield helper is absent.
        }

        return back()->with('success', __('loginfw.whitelist_added', ['ip' => $data['ip']]));
    }

    public function destroyWhitelist(IpWhitelist $whitelist): RedirectResponse
    {
        $ip = $whitelist->ip;
        $whitelist->delete();
        Cache::forget('ip-whitelist:'.$ip);

        try {
            app(SecurityShieldService::class)->whitelistDel($ip);
        } catch (\Throwable) {
            //
        }

        return back()->with('success', __('loginfw.whitelist_removed', ['ip' => $ip]));
    }
}
