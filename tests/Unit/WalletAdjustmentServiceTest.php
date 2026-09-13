<?php

namespace Tests\Unit;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\WalletAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_credit_creates_adjustment_transaction_and_activity_log(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();

        $adjustment = app(WalletAdjustmentService::class)->adjust(
            $agent,
            '5000.00',
            'credit',
            $admin,
            'test top-up'
        );

        $this->assertInstanceOf(WalletAdjustment::class, $adjustment);
        $this->assertSame('5000.00', (string) $adjustment->amount);
        $this->assertNotNull($adjustment->transaction_id);

        $transaction = Transaction::query()->findOrFail($adjustment->transaction_id);
        $this->assertSame(TransactionType::Adjustment, $transaction->type);
        $this->assertSame('5000.00', (string) $transaction->amount);
        $this->assertSame('test top-up', $transaction->description);

        $agent->load('wallet');
        $this->assertSame('5000.00', (string) $agent->wallet?->balance);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'wallet.adjustment',
            'entity_type' => $adjustment->getMorphClass(),
            'entity_id' => $adjustment->id,
        ]);

        $log = ActivityLog::query()->where('action', 'wallet.adjustment')->firstOrFail();
        $this->assertSame($agent->id, $log->payload['target_user_id'] ?? null);
        $this->assertSame('credit', $log->payload['direction'] ?? null);
    }

    public function test_rejects_non_agent_or_seller_targets(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('فقط نماینده یا فروشنده قابل شارژ است.');

        app(WalletAdjustmentService::class)->adjust(
            $client,
            '1000.00',
            'credit',
            $admin
        );
    }

    public function test_rejects_non_admin_operators(): void
    {
        $agent = User::factory()->agent()->create();
        $seller = User::factory()->seller()->create(['parent_id' => $agent->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('فقط ادمین می‌تواند موجودی را تغییر دهد.');

        app(WalletAdjustmentService::class)->adjust(
            $seller,
            '1000.00',
            'credit',
            $agent
        );
    }
}
