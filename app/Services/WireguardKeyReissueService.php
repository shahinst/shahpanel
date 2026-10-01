<?php

namespace App\Services;

use App\Enums\ServiceType;
use App\Exceptions\RemoteProvisionException;
use App\Models\Account;

/**
 * صدور دوبارهٔ کلیدهای وایرگارد یک اکانت.
 *
 * چرا لازم است: وایرگارد روی سرور فقط کلید عمومی کلاینت را نگه می‌دارد و کلید
 * خصوصی هرگز به روتر نمی‌رسد. پس اکانت‌هایی که از روی روتر خوانده شده‌اند
 * (MikrotikClientImportService) کلید خصوصی ندارند و پنل هیچ راهی برای ساختن
 * کانفیگ‌شان ندارد — نه به‌خاطر ایرادی در کد، بلکه چون آن کلید جایی وجود ندارد
 * که خوانده شود. تنها راهِ واقعی، ساختن یک جفت کلید نو و جانشین کردن کلید عمومی
 * روی همان peer است.
 *
 * عمداً هنگام ایمپورت انجام نمی‌شود: چرخاندن کلید همهٔ peerها در یک حرکت، همهٔ
 * مشتری‌های فعال آن روتر را هم‌زمان قطع می‌کند. این کار باید خواستهٔ صریح ادمین
 * روی یک اکانت باشد، چون کانفیگ قبلیِ همان مشتری (اگر از جای دیگری داشته) با
 * این کار از کار می‌افتد.
 */
class WireguardKeyReissueService
{
    public function __construct(
        protected MikrotikService $mikrotikService,
    ) {}

    /**
     * آیا این اکانت کانفیگ قابل‌ساخت ندارد و با صدور دوباره درست می‌شود؟
     */
    public function needsReissue(Account $account): bool
    {
        return $account->service_type === ServiceType::Wireguard
            && blank($account->wireguard_private_key_enc);
    }

    public function reissue(Account $account): void
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($account->service_type !== ServiceType::Wireguard || $server === null || ! $server->isMikrotik()) {
            throw new RemoteProvisionException(__('services.wireguard_reissue_not_supported'));
        }

        $currentPublicKey = trim((string) ($account->wireguard_public_key ?? ''));

        if ($currentPublicKey === '') {
            throw new RemoteProvisionException(__('services.wireguard_reissue_no_public_key'));
        }

        $keys = $this->mikrotikService->generateKeys();

        // روتر اول عوض می‌شود و بعد پنل. اگر ترتیب برعکس بود و روتر جواب نمی‌داد،
        // پنل یک کلید خصوصی ذخیره می‌کرد که هیچ peerی آن را نمی‌شناسد و کانفیگِ
        // تحویلی خاموش از کار می‌افتاد؛ این‌طور، شکست روتر یعنی هیچ‌چیز عوض نشده.
        $this->mikrotikService->updatePeer($server, $currentPublicKey, [
            'public_key' => $keys['public_key'],
        ]);

        $account->forceFill([
            'wireguard_private_key_enc' => $keys['private_key'],
            'wireguard_public_key' => $keys['public_key'],
        ])->save();
    }
}
