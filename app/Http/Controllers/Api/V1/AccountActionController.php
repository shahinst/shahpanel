<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountTransformer;
use App\Models\Account;
use App\Models\Server;
use App\Services\AccountRefundService;
use App\Services\AccountService;
use App\Services\AccountTransferService;
use App\Services\PackageService;
use App\Services\SyncService;
use App\Support\AccountNameValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Everything the panel lets a reseller do to one account after buying it,
 * under the same policies and services as the panel's own pages: rename,
 * auto-renew, move to another server, refund, reactivate, refresh usage,
 * delete. Each action answers with the account as it now stands.
 */
class AccountActionController extends Controller
{
    use RespondsWithJson;

    public function update(Request $request, string $accountKey): JsonResponse
    {
        return $this->act($request, $accountKey, 'update', function (Account $account) use ($request): Account {
            $data = $request->validate([
                'remote_username' => array_merge(
                    AccountNameValidator::rules(AccountNameValidator::REMOTE_MAX, required: false),
                    [Rule::unique('accounts', 'remote_username')->ignore($account->id)],
                ),
                'display_label' => ['sometimes', 'nullable', 'string', 'max:60'],
                'auto_renew' => ['sometimes', 'boolean'],
            ]);

            $account->update(array_filter($data, fn ($v, $k) => $k !== 'remote_username' || filled($v), ARRAY_FILTER_USE_BOTH));

            return $account;
        });
    }

    public function transfer(Request $request, string $accountKey, AccountTransferService $transfers, PackageService $packages): JsonResponse
    {
        return $this->act($request, $accountKey, 'transferServer', function (Account $account) use ($request, $transfers, $packages): Account {
            $data = $request->validate(['server_id' => ['required', 'integer', 'exists:servers,id']]);
            $server = Server::query()->findOrFail($data['server_id']);
            $packages->assertServerAllowed($account->package, $server);
            $transfers->transfer($account, $server, $request->user());

            return $account;
        });
    }

    public function refund(Request $request, string $accountKey, AccountRefundService $refunds): JsonResponse
    {
        return $this->act($request, $accountKey, 'refund', function (Account $account) use ($request, $refunds): Account {
            $refunds->refund($account, $request->user());

            return $account;
        });
    }

    public function reactivate(Request $request, string $accountKey, AccountRefundService $refunds): JsonResponse
    {
        return $this->act($request, $accountKey, 'reactivateAfterRefund', function (Account $account) use ($request, $refunds): Account {
            $refunds->reactivate($account, $request->user());

            return $account;
        });
    }

    /** Reads usage from the server now instead of waiting for the schedule. */
    public function sync(Request $request, string $accountKey, SyncService $sync): JsonResponse
    {
        return $this->act($request, $accountKey, 'view', function (Account $account) use ($sync): Account {
            $sync->syncAccount($account);

            return $account;
        });
    }

    public function destroy(Request $request, string $accountKey, AccountService $accounts): JsonResponse
    {
        $account = $this->find($request, $accountKey);

        if ($account === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        if (! Gate::forUser($request->user())->allows('delete', $account)) {
            return $this->fail('forbidden', __('api.forbidden'), 403);
        }

        try {
            $accounts->deleteAccount($account, $request->user());
        } catch (Throwable $e) {
            report($e);

            return $this->fail('action_failed', $e->getMessage(), 422);
        }

        return $this->ok(['deleted' => true, 'id' => $account->id]);
    }

    /** Accounts that run out within the given number of days (default 3). */
    public function expiring(Request $request): JsonResponse
    {
        $days = max(1, min(60, (int) $request->query('days', 3)));
        $accounts = Account::query()->ownedByHierarchy($request->user())
            ->where('status', AccountStatus::Active)
            ->whereBetween('expiry_at', [now(), now()->addDays($days)])
            ->orderBy('expiry_at')
            ->limit(500)
            ->get();

        return $this->ok($accounts->map(fn (Account $a) => AccountTransformer::make($a))->values(), ['days' => $days, 'count' => $accounts->count()]);
    }

    /**
     * @param  \Closure(Account): Account  $action
     */
    protected function act(Request $request, string $accountKey, string $ability, \Closure $action): JsonResponse
    {
        $account = $this->find($request, $accountKey);

        if ($account === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        if (! Gate::forUser($request->user())->allows($ability, $account)) {
            return $this->fail('forbidden', __('api.forbidden'), 403);
        }

        try {
            $account = $action($account);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return $this->fail('action_failed', $e->getMessage(), 422);
        }

        return $this->ok(AccountTransformer::make($account->fresh(), true));
    }

    protected function find(Request $request, string $key): ?Account
    {
        $query = Account::query()->ownedByHierarchy($request->user());

        return ctype_digit($key) ? $query->find((int) $key) : $query->where('remote_username', $key)->first();
    }
}
