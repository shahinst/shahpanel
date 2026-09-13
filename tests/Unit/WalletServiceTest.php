<?php

namespace Tests\Unit;

use App\Enums\ServiceType;
use App\Enums\TransactionType;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $walletService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletService = app(WalletService::class);
    }

    public function test_get_or_create_wallet_returns_existing_wallet(): void
    {
        $user = User::factory()->seller()->create();
        $existing = Wallet::query()->create([
            'user_id' => $user->id,
            'balance' => '150.00',
            'locked_balance' => '0.00',
            'currency' => 'IRT',
            'updated_at' => now(),
        ]);

        $wallet = $this->walletService->getOrCreateWallet($user);

        $this->assertTrue($existing->is($wallet));
        $this->assertSame('150.00', (string) $wallet->balance);
    }

    public function test_credit_and_debit_create_transaction_rows_with_balances(): void
    {
        $user = User::factory()->seller()->create();

        $credit = $this->walletService->credit(
            $user,
            '1000.00',
            TransactionType::Charge,
            ['description' => 'Initial top-up']
        );

        $debit = $this->walletService->debit(
            $user,
            '250.50',
            TransactionType::Purchase,
            ['description' => 'Package purchase']
        );

        $wallet = $this->walletService->getOrCreateWallet($user->fresh());

        $this->assertSame('749.50', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $credit->balance_before);
        $this->assertSame('1000.00', (string) $credit->balance_after);
        $this->assertSame('1000.00', (string) $debit->balance_before);
        $this->assertSame('749.50', (string) $debit->balance_after);
        $this->assertSame('Initial top-up', $credit->description);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_admin_wallet_records_ledger_without_changing_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $wallet = $this->walletService->getOrCreateWallet($admin);
        $wallet->update(['balance' => '100.00']);

        $debit = $this->walletService->debit(
            $admin,
            '500.00',
            TransactionType::Charge,
            ['description' => 'Charge approval debit']
        );

        $credit = $this->walletService->credit(
            $admin,
            '500.00',
            TransactionType::Refund,
            ['description' => 'Refund credit']
        );

        $wallet->refresh();

        $this->assertSame('100.00', (string) $wallet->balance);
        $this->assertSame('100.00', (string) $debit->balance_before);
        $this->assertSame('100.00', (string) $debit->balance_after);
        $this->assertSame('100.00', (string) $credit->balance_after);
    }

    public function test_resolve_buyer_charge_returns_full_price(): void
    {
        $seller = User::factory()->seller()->create();
        $package = Package::query()->create([
            'name' => 'Monthly 50GB',
            'service_type' => ServiceType::Wireguard,
            'duration_days' => 30,
            'base_price' => '1000.00',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $charge = $this->walletService->resolveBuyerCharge($seller, $package, '1000.00');

        $this->assertSame('1000.00', $charge);
    }

    public function test_process_purchase_debits_buyer_for_full_price(): void
    {
        $seller = User::factory()->seller()->create();
        $package = Package::query()->create([
            'name' => 'Monthly 50GB',
            'service_type' => ServiceType::Wireguard,
            'duration_days' => 30,
            'base_price' => '1000.00',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->walletService->credit($seller, '1000.00', TransactionType::Charge);

        $result = $this->walletService->processPurchase(
            $seller,
            '1000.00',
            ['description' => 'Account purchase']
        );

        $sellerWallet = $this->walletService->getOrCreateWallet($seller->fresh());

        $this->assertSame('0.00', (string) $sellerWallet->balance);
        $this->assertSame('1000.00', $result['amount']);
        $this->assertSame(TransactionType::Purchase, $result['transaction']->type);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_process_purchase_rolls_back_when_seller_has_insufficient_balance(): void
    {
        $seller = User::factory()->seller()->create();
        $package = Package::query()->create([
            'name' => 'Monthly 50GB',
            'service_type' => ServiceType::Wireguard,
            'duration_days' => 30,
            'base_price' => '1000.00',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->walletService->credit($seller, '100.00', TransactionType::Charge);

        try {
            $this->walletService->processPurchase($seller, '1000.00');
            $this->fail('Expected InsufficientWalletBalanceException was not thrown.');
        } catch (InsufficientWalletBalanceException) {
            // Expected rollback path.
        }

        $sellerWallet = $this->walletService->getOrCreateWallet($seller->fresh());

        $this->assertSame('100.00', (string) $sellerWallet->balance);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_process_purchase_rolls_back_on_mid_transaction_failure(): void
    {
        $seller = User::factory()->seller()->create();

        $this->walletService->credit($seller, '1000.00', TransactionType::Charge);

        $attempt = 0;
        Transaction::creating(function () use (&$attempt) {
            $attempt++;

            if ($attempt === 1) {
                throw new \RuntimeException('Simulated purchase failure.');
            }
        });

        try {
            $this->walletService->processPurchase($seller, '1000.00');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated purchase failure.', $exception->getMessage());
        }

        $sellerWallet = $this->walletService->getOrCreateWallet($seller->fresh());

        $this->assertSame('1000.00', (string) $sellerWallet->balance);
        $this->assertSame(1, Transaction::query()->count());
    }
}
