<?php

namespace App\Services;

use App\Exceptions\RemoteProvisionException;
use App\Models\Account;
use Illuminate\Support\Facades\Log;

/**
 * سقف سرعت هر اکانت وایرگارد، روی simple queue میکروتیک.
 *
 * صف با نام «{interface}-acct-{id}» ساخته می‌شود تا
 * MikrotikService::removeWireguardInterfaceQueues() — که هر صفی با پیشوند نام
 * اینترفیس را فرزند خودش می‌داند — وقتی اینترفیس جمع می‌شود این را هم ببرد.
 * اگر نام دلخواهی می‌گذاشتیم، صف‌ها روی روتر جا می‌ماندند.
 *
 * هدف صف، آی‌پی وایرگارد خود اکانت است؛ پس سقف فقط روی همان مشتری اعمال
 * می‌شود و نه روی کل اینترفیس.
 */
class WireguardSpeedLimitService
{
    public function __construct(
        protected MikrotikService $mikrotikService,
    ) {}

    /**
     * صف را با مقادیر فعلی اکانت هم‌تراز کن: بساز، به‌روز کن، یا اگر هر دو سقف
     * خالی شده‌اند پاکش کن (خالی یعنی بی‌حد، همان رفتار اکانت‌های بدون صف).
     */
    public function sync(Account $account): void
    {
        $address = $account->wireguardHostAddress();
        $server = $account->server;

        if ($server === null || ! $server->isMikrotik() || $address === null) {
            throw new RemoteProvisionException(__('services.speed_limit_not_supported'));
        }

        $name = $this->queueName($account, $server);

        if (! $this->hasLimit($account)) {
            $this->mikrotikService->removeMatching($server, '/queue/simple', 'name', $name);

            return;
        }

        $this->mikrotikService->ensureSimpleQueue(
            $server,
            $name,
            $address,
            $this->maxLimit($account),
        );
    }

    /**
     * همان sync ولی بی‌صدا: برای مسیرهایی که نباید به‌خاطر روتر خاموش شکست
     * بخورند (مثل حذف اکانت). خطا فقط لاگ می‌شود.
     */
    public function syncQuietly(Account $account): bool
    {
        try {
            $this->sync($account);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('WireGuard speed limit sync failed', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function hasLimit(Account $account): bool
    {
        return (int) ($account->speed_limit_up_kbps ?? 0) > 0
            || (int) ($account->speed_limit_down_kbps ?? 0) > 0;
    }

    /**
     * قالب max-limit روی RouterOS برابر «آپلود/دانلود» از دید هدف است و هر دو
     * نیمه لازم‌اند؛ پس نیمهٔ خالی صفر می‌شود، که روی روتر معنای «بی‌حد» دارد.
     */
    protected function maxLimit(Account $account): string
    {
        return $this->limitPart($account->speed_limit_up_kbps)
            .'/'
            .$this->limitPart($account->speed_limit_down_kbps);
    }

    protected function limitPart(?int $kbps): string
    {
        $kbps = (int) ($kbps ?? 0);

        return $kbps > 0 ? $kbps.'k' : '0';
    }

    /**
     * صف اکانت را بردار. هنگام حذف اکانت صدا زده می‌شود و باید *قبل* از برداشتن
     * peer اجرا شود، چون نام اینترفیس از روی همان peer خوانده می‌شود.
     */
    public function remove(\App\Models\Server $server, string $publicKey, int $accountId): void
    {
        // خطا فقط لاگ می‌شود: برداشتن peer مهم‌تر از برداشتن صف است و نباید
        // به‌خاطر یک صفِ قفل‌شده یا گم‌شده متوقف بماند.
        try {
            $interface = $this->mikrotikService->wireguardPeerInterface($server, $publicKey);

            if ($interface === null) {
                return;
            }

            $this->mikrotikService->removeMatching($server, '/queue/simple', 'name', $this->nameFor($interface, $accountId));
        } catch (\Throwable $exception) {
            Log::warning('WireGuard speed queue remove failed', [
                'account_id' => $accountId,
                'server_id' => $server->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function queueName(Account $account, \App\Models\Server $server): string
    {
        $interface = $this->mikrotikService->wireguardPeerInterface($server, (string) $account->wireguard_public_key)
            ?? $this->mikrotikService->resolveWireguardInterfaceName($server, $account->mikrotik_profile_key);

        return $this->nameFor($interface, (int) $account->id);
    }

    protected function nameFor(string $interface, int $accountId): string
    {
        return $interface.'-acct-'.$accountId;
    }
}
