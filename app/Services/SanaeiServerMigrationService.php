<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Move panel Sanaei accounts to a new 3x-ui server (server migration).
 * Panel DB (limit, used, expiry, uuid, sub) is the source of truth.
 */
class SanaeiServerMigrationService
{
    public function __construct(
        protected AccountTransferService $transferService,
        protected ServerAccountPushService $pushService,
        protected ServerInterfaceSyncService $interfaceSyncService,
        protected PackageService $packageService,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     transferred: int,
     *     pushed: int,
     *     skipped: int,
     *     failed: int,
     *     lines: list<string>,
     *     errors: list<string>
     * }
     */
    public function migrateFromServer(
        Server $fromServer,
        Server $toServer,
        ?User $actor = null,
        bool $dryRun = false,
        bool $syncInbounds = true,
    ): array {
        $this->assertSanaeiPair($fromServer, $toServer);

        if ($fromServer->id === $toServer->id) {
            throw new InvalidArgumentException('سرور مبدأ و مقصد یکی است.');
        }

        $accounts = $this->accountsOnServer($fromServer);
        $lines = [];
        $errors = [];
        $transferred = 0;
        $failed = 0;

        $lines[] = "تعداد اکانت روی «{$fromServer->name}»: ".$accounts->count();

        if ($dryRun) {
            foreach ($accounts as $account) {
                $lines[] = $this->describeAccount($account, $fromServer, $toServer);
            }

            return $this->result($accounts->count(), 0, 0, 0, 0, $lines, $errors);
        }

        if ($syncInbounds) {
            $sync = $this->interfaceSyncService->sync($toServer);
            $lines = array_merge($lines, $sync['lines']);
            if ($sync['errors'] !== []) {
                $errors = array_merge($errors, $sync['errors']);
            }
        }

        foreach ($accounts as $account) {
            try {
                $this->ensurePackageAllowsServer($account, $toServer);
                $this->transferService->transfer($account, $toServer, $actor);
                $transferred++;
                $lines[] = "✓ انتقال #{$account->id} «{$account->remote_username}» → {$toServer->name}";
            } catch (Throwable $exception) {
                $failed++;
                $errors[] = "اکانت #{$account->id} ({$account->remote_username}): ".$exception->getMessage();
            }
        }

        $lines[] = "جمع انتقال: {$transferred} موفق، {$failed} خطا.";

        return $this->result($accounts->count(), $transferred, 0, 0, 0, $lines, $errors);
    }

    /**
     * Accounts already point at $server in DB — recreate clients on that panel with DB limits/usage.
     *
     * @return array{
     *     total: int,
     *     transferred: int,
     *     pushed: int,
     *     skipped: int,
     *     failed: int,
     *     lines: list<string>,
     *     errors: list<string>
     * }
     */
    public function pushAllOnServer(Server $server, bool $dryRun = false, bool $syncInbounds = true): array
    {
        if (! $server->isSanaei()) {
            throw new InvalidArgumentException('این عملیات فقط برای سرور Sanaei / 3x-ui است.');
        }

        $accounts = $this->accountsOnServer($server);
        $lines = ["تعداد اکانت Sanaei روی «{$server->name}»: ".$accounts->count()];

        if ($dryRun) {
            foreach ($accounts as $account) {
                $lines[] = $this->describeAccount($account, $server, $server);
            }

            return $this->result($accounts->count(), 0, 0, 0, 0, $lines, []);
        }

        if ($syncInbounds) {
            $sync = $this->interfaceSyncService->sync($server);
            $lines = array_merge($lines, $sync['lines']);
        }

        $push = $this->pushService->pushAll($server, onlyMissing: false);
        $lines = array_merge($lines, $push['lines']);

        return $this->result(
            $accounts->count(),
            0,
            $push['pushed'],
            $push['skipped'],
            $push['failed'],
            $lines,
            $push['errors'],
        );
    }

    protected function assertSanaeiPair(Server $from, Server $to): void
    {
        if (! $from->isSanaei() || ! $to->isSanaei()) {
            throw new InvalidArgumentException('هر دو سرور باید از نوع Sanaei (3x-ui) باشند.');
        }

        if (! $from->is_active || ! $to->is_active) {
            throw new InvalidArgumentException('سرور مبدأ و مقصد باید فعال باشند.');
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Account>
     */
    protected function accountsOnServer(Server $server)
    {
        return Account::query()
            ->where('server_id', $server->id)
            ->whereNotIn('status', [AccountStatus::Pending])
            ->where(function ($query): void {
                $query->where('service_type', \App\Enums\ServiceType::Sanaei)
                    ->orWhereNotNull('sanaei_client_uuid');
            })
            ->orderBy('id')
            ->get();
    }

    protected function ensurePackageAllowsServer(Account $account, Server $newServer): void
    {
        $account->loadMissing('package.servers');

        if ($account->package === null) {
            return;
        }

        if ($account->package->servers->contains('id', $newServer->id)) {
            $this->packageService->assertServerAllowed($account->package, $newServer);

            return;
        }

        DB::transaction(function () use ($account, $newServer): void {
            $account->package?->servers()->syncWithoutDetaching([$newServer->id]);
        });

        $account->load('package.servers');
        $this->packageService->assertServerAllowed($account->package, $newServer);
    }

    protected function describeAccount(Account $account, Server $from, Server $to): string
    {
        $usedGb = round($account->data_used_bytes / (1024 ** 3), 2);
        $limitGb = $account->isUnlimited()
            ? '∞'
            : round((int) $account->data_limit_bytes / (1024 ** 3), 2);
        $expiry = $account->expiry_at?->format('Y-m-d H:i') ?? '—';

        return "#{$account->id} {$account->remote_username} | {$usedGb}/{$limitGb} GB | انقضا {$expiry} | {$from->name} → {$to->name}";
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $errors
     * @return array{
     *     total: int,
     *     transferred: int,
     *     pushed: int,
     *     skipped: int,
     *     failed: int,
     *     lines: list<string>,
     *     errors: list<string>
     * }
     */
    protected function result(
        int $total,
        int $transferred,
        int $pushed,
        int $skipped,
        int $failed,
        array $lines,
        array $errors,
    ): array {
        return [
            'total' => $total,
            'transferred' => $transferred,
            'pushed' => $pushed,
            'skipped' => $skipped,
            'failed' => $failed,
            'lines' => $lines,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<array{id: int, name: string, host: string}>
     */
    public function listSanaeiServers(): array
    {
        return Server::query()
            ->where('type', ServerType::Sanaei->value)
            ->orderBy('name')
            ->get(['id', 'name', 'host'])
            ->map(fn (Server $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'host' => $s->host,
            ])
            ->all();
    }
}
