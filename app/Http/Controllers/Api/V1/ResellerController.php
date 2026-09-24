<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountTransformer;
use App\Models\Account;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An agent's own sellers. Read-only by design: the panel gives agents no way
 * to move money into a seller's wallet (WalletAdjustmentService refuses any
 * actor that is not an admin), so the API does not invent one either.
 */
class ResellerController extends Controller
{
    use RespondsWithJson;

    public function __construct(protected WalletService $wallets) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            // وضعیت ناشناخته باید ۴۲۲ بدهد، نه فیلتری که بی‌صدا نادیده گرفته شود.
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'per_page' => ['nullable', 'integer'],
        ]);

        $query = User::query()
            ->whereNull('deleted_at')
            ->where('role', UserRole::Seller->value)
            ->whereIn('id', User::subtreeUserIds($request->user()))
            ->where('id', '!=', $request->user()->id)
            ->withCount('ownedAccountsAsSeller')
            ->orderBy('username');

        if (! empty($data['search'])) {
            $term = '%'.$data['search'].'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('username', 'like', $term)
                    ->orWhere('full_name', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $page = $query->paginate($this->perPage($data['per_page'] ?? null));

        return $this->paginated($page, fn (User $u): array => $this->sellerPayload($u));
    }

    public function show(Request $request, int $reseller): JsonResponse
    {
        $seller = $this->findSeller($request, $reseller);

        if ($seller === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        $payload = $this->sellerPayload($seller);

        $payload['accounts_summary'] = [
            'total' => Account::query()->where('owner_seller_id', $seller->id)->count(),
            'active' => Account::query()->where('owner_seller_id', $seller->id)->where('status', 'active')->count(),
            'expired' => Account::query()->where('owner_seller_id', $seller->id)->where('status', 'expired')->count(),
        ];

        return $this->ok($payload);
    }

    public function accounts(Request $request, int $reseller): JsonResponse
    {
        $seller = $this->findSeller($request, $reseller);

        if ($seller === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        $page = Account::query()
            ->where('owner_seller_id', $seller->id)
            ->with(['package', 'packageDuration', 'server'])
            ->orderByDesc('id')
            ->paginate($this->perPage($request->input('per_page')));

        return $this->paginated($page, static fn (Account $a): array => AccountTransformer::make($a));
    }

    protected function findSeller(Request $request, int $id): ?User
    {
        return User::query()
            ->whereNull('deleted_at')
            ->where('role', UserRole::Seller->value)
            ->whereIn('id', User::subtreeUserIds($request->user()))
            ->where('id', '!=', $request->user()->id)
            ->find($id);
    }

    /** @return array<string, mixed> */
    protected function sellerPayload(User $seller): array
    {
        $wallet = $this->wallets->getOrCreateWallet($seller);

        return [
            'id' => $seller->id,
            'username' => $seller->username,
            'full_name' => $seller->full_name,
            'phone' => $seller->phone,
            'email' => $seller->email,
            'status' => $seller->status->value,
            'telegram_id' => $seller->telegram_id,
            'accounts_count' => $seller->owned_accounts_as_seller_count ?? null,
            'wallet' => [
                'balance' => (string) $wallet->balance,
                'currency' => $wallet->currency,
            ],
            'last_login_at' => optional($seller->last_login_at)->toIso8601String(),
            'created_at' => optional($seller->created_at)->toIso8601String(),
        ];
    }
}
