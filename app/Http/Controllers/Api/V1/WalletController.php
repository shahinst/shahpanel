<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    use RespondsWithJson;

    public function __construct(protected WalletService $wallets) {}

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->getOrCreateWallet($request->user());

        return $this->ok([
            'balance' => (string) $wallet->balance,
            'locked_balance' => (string) $wallet->locked_balance,
            'available' => bcsub((string) $wallet->balance, (string) $wallet->locked_balance, 2),
            'currency' => $wallet->currency,
            'updated_at' => optional($wallet->updated_at)->toIso8601String(),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $data = $request->validate([
            // نوع ناشناخته باید ۴۲۲ بدهد، نه فیلتری که بی‌صدا نادیده گرفته شود.
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $query = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id');

        if (! empty($data['type'])) {
            $query->where('type', $data['type']);
        }

        if (! empty($data['from'])) {
            $query->where('created_at', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->where('created_at', '<=', $data['to']);
        }

        $page = $query->paginate($this->perPage($data['per_page'] ?? null, 25, 200));

        return $this->paginated($page, static fn (Transaction $t): array => [
            'id' => $t->id,
            'type' => $t->type?->value ?? (string) $t->type,
            'amount' => (string) $t->amount,
            'balance_before' => (string) $t->balance_before,
            'balance_after' => (string) $t->balance_after,
            'description' => $t->description,
            'related_account_id' => $t->related_account_id,
            'related_invoice_id' => $t->related_invoice_id,
            'created_at' => optional($t->created_at)->toIso8601String(),
        ]);
    }
}
