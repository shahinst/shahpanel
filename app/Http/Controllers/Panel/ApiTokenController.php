<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\ApiTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lets an agent or seller mint the token their Telegram bot authenticates with.
 * Shared by both panels; the route prefix decides which one rendered it.
 */
class ApiTokenController extends Controller
{
    public function __construct(protected ApiTokenService $tokens) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $tokens = ApiToken::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        return view('panel.api-tokens.index', [
            'tokens' => $tokens,
            // Only what this role can exercise: a seller has no sub-resellers,
            // so offering them that box would promise something the API refuses.
            'abilityCatalog' => ApiTokenService::abilityCatalog($user->role),
            'routePrefix' => $this->routePrefix($request),
            'baseUrl' => rtrim(config('app.url'), '/').'/api/v1',
        ]);
    }

    /** The endpoint reference, rendered inside the panel rather than served publicly. */
    public function docs(Request $request): View
    {
        $role = $request->user()->role;
        $isAgent = $role === UserRole::Agent;

        $labels = [];

        foreach (ApiTokenService::abilityCatalog() as $group) {
            $labels += $group['abilities'];
        }

        // Sellers get the reference for the endpoints they can actually call.
        $groups = array_values(array_filter(
            \App\Support\ApiDocs::groups(),
            static fn (array $group): bool => $isAgent || ! ($group['agent_only'] ?? false),
        ));

        return view('panel.api-tokens.docs', [
            'groups' => $groups,
            'errors_list' => \App\Support\ApiDocs::errors(),
            'abilityLabels' => $labels,
            'routePrefix' => $this->routePrefix($request),
            'baseUrl' => rtrim(config('app.url'), '/').'/api/v1',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:64'],
            'allowed_ips' => ['nullable', 'string', 'max:512'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:10', 'max:600'],
        ]);

        $issued = $this->tokens->issue(
            $request->user(),
            $data['name'],
            $data['abilities'] ?? null,
            isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            $data['allowed_ips'] ?? null,
            (int) ($data['rate_limit_per_minute'] ?? 120),
        );

        // Shown once. There is no way to read it back afterwards.
        return back()
            ->with('success', __('api.ui_token_created'))
            ->with('new_api_token', $issued['plain_text']);
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        abort_unless((int) $apiToken->user_id === (int) $request->user()->id, 404);

        $this->tokens->revoke($apiToken);

        return back()->with('success', __('api.token_revoked'));
    }

    protected function routePrefix(Request $request): string
    {
        return $request->user()->role === UserRole::Agent ? 'agent' : 'seller';
    }
}
