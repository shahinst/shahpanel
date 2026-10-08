<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountTransformer;
use App\Models\Account;
use App\Models\User;
use App\Services\AgentSellerChargeService;
use App\Services\EndUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * The reseller's customers (portal users) and, for agents, topping up their
 * sellers: the parts of the panel a bot needs beyond buying accounts.
 */
class ClientController extends Controller
{
    use RespondsWithJson;

    // Route parameters are {clientId}: the web routes bind {client} to a User.

    public function index(Request $request, EndUserService $users): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $clients = $users->clientsQueryForViewer($request->user())
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('username', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%")))
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return $this->ok(collect($clients->items())->map(fn (User $c) => $this->payload($c))->values(), [
            'page' => $clients->currentPage(), 'per_page' => $clients->perPage(), 'total' => $clients->total(),
        ]);
    }

    public function store(Request $request, EndUserService $users): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'alpha_dash'],
            'password' => ['nullable', 'string', 'min:6', 'max:64'],
            'full_name' => ['nullable', 'string', 'max:255'],
        ]);
        $password = $data['password'] ?? $users->generatePortalPassword();

        try {
            $client = $users->findOrCreateForAccount($request->user(), $data['username'], $password, $data['full_name'] ?? null);
        } catch (InvalidArgumentException $e) {
            return $this->fail('client_unavailable', $e->getMessage(), 422);
        }

        return $this->ok($this->payload($client) + ['password' => $client->wasRecentlyCreated ? $password : null], [], $client->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, int $clientId, EndUserService $users): JsonResponse
    {
        $model = $users->clientsQueryForViewer($request->user())->whereKey($clientId)->first();

        if ($model === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        $accounts = Account::query()->ownedByHierarchy($request->user())->where('client_user_id', $model->id)->latest('id')->limit(200)->get();

        return $this->ok($this->payload($model) + ['accounts' => $accounts->map(fn (Account $a) => AccountTransformer::make($a))->values()]);
    }

    /** Hands an account to one of the caller's customers. */
    public function assign(Request $request, int $clientId, string $accountKey, EndUserService $users): JsonResponse
    {
        $model = $users->clientsQueryForViewer($request->user())->whereKey($clientId)->first();
        $query = Account::query()->ownedByHierarchy($request->user());
        $account = ctype_digit($accountKey) ? $query->find((int) $accountKey) : $query->where('remote_username', $accountKey)->first();

        if ($model === null || $account === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        $account->forceFill(['client_user_id' => $model->id])->save();

        return $this->ok(AccountTransformer::make($account->fresh(), true));
    }

    public function chargeSeller(Request $request, int $reseller, AgentSellerChargeService $charges): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1', 'max:99999999999']]);
        $seller = User::query()->whereKey($reseller)->where('parent_id', $request->user()->id)->first();

        if ($seller === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        try {
            $charges->charge($request->user(), $seller, (string) $data['amount'], AgentSellerChargeService::FROM_API);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('charge_failed', $e->getMessage(), 422);
        }

        return $this->ok(['reseller_id' => $seller->id, 'amount' => (string) $data['amount']]);
    }

    protected function payload(User $client): array
    {
        return [
            'id' => $client->id,
            'username' => $client->username,
            'full_name' => $client->full_name,
            'phone' => $client->phone,
            'status' => $client->status?->value,
            'created_at' => $client->created_at?->toIso8601String(),
        ];
    }
}
