<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\ApiTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin oversight of every API credential in the panel: who holds one, what it
 * can reach, how heavily it is used, and a way to pull it.
 */
class ApiTokenAdminController extends Controller
{
    public function __construct(protected ApiTokenService $tokens) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,revoked,expired'],
        ]);

        $query = ApiToken::query()
            ->with('user')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->whereHas('user', function ($q) use ($term): void {
                $q->where('username', 'like', $term)
                    ->orWhere('full_name', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        match ($filters['status'] ?? null) {
            'revoked' => $query->whereNotNull('revoked_at'),
            'expired' => $query->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()),
            'active' => $query->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
            default => null,
        };

        $tokens = $query->paginate(30)->withQueryString();

        return view('admin.api-tokens.index', [
            'tokens' => $tokens,
            'filters' => $filters,
            'stats' => $this->stats(),
        ]);
    }

    public function destroy(ApiToken $apiToken): RedirectResponse
    {
        $this->tokens->revoke($apiToken);

        return back()->with('success', __('api.token_revoked'));
    }

    /** @return array<string, int> */
    protected function stats(): array
    {
        $live = ApiToken::query()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));

        return [
            'total' => ApiToken::query()->count(),
            'active' => (clone $live)->count(),
            'users' => ApiToken::query()->distinct()->count('user_id'),
            'requests' => (int) ApiToken::query()->sum('request_count'),
            'used_24h' => ApiToken::query()->where('last_used_at', '>=', now()->subDay())->count(),
        ];
    }
}
