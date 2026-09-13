<?php

/**
 * نمونهٔ اتصال ربات تلگرام به پنل.
 *
 * الگوی توصیه‌شده: هر نماینده/فروشنده یک‌بار توکن خودش را به ربات می‌دهد،
 * ربات آن را کنار chat_id ذخیره می‌کند و از آن به بعد همهٔ کارها با همان
 * توکن انجام می‌شود — یعنی هر کس فقط اکانت‌های خودش را می‌بیند.
 */

require __DIR__.'/ShahPanelClient.php';

const PANEL_API = 'https://your-domain.example/api/v1';

// ─────────────────────── ۱) اتصال کاربر به پنل ───────────────────────
// وقتی کاربر در ربات /connect می‌زند و نام کاربری و رمز پنلش را می‌دهد.

function connectUser(int $chatId, string $username, string $password, ?string $twoFa = null): string
{
    $api = new ShahPanelClient(PANEL_API);

    try {
        $result = $api->login($username, $password, $twoFa, 'telegram:'.$chatId);
    } catch (ShahPanelApiException $e) {
        if ($e->errorCode === 'two_fa_required') {
            return 'کد ورود دو مرحله‌ای را بفرستید.';
        }

        return '❌ '.$e->getMessage();
    }

    // توکن را برای این chat_id ذخیره کنید (دیتابیس خود ربات)
    saveToken($chatId, $result['token']);

    $u = $result['user'];

    return sprintf(
        "✅ متصل شدید\n👤 %s (%s)\n💰 موجودی: %s %s",
        $u['full_name'] ?: $u['username'],
        $u['role'] === 'agent' ? 'نماینده' : 'فروشنده',
        number_format((float) $u['wallet']['balance']),
        $u['wallet']['currency'],
    );
}

/** کلاینت آمادهٔ همان کاربر. */
function apiFor(int $chatId): ShahPanelClient
{
    $token = loadToken($chatId);

    if ($token === null) {
        throw new RuntimeException('اول با /connect به پنل وصل شوید.');
    }

    return new ShahPanelClient(PANEL_API, $token);
}

// ─────────────────────── ۲) منوی خرید ───────────────────────

function showPackages(int $chatId): array
{
    $packages = apiFor($chatId)->packages();

    $buttons = [];

    foreach ($packages as $p) {
        if (! $p['available_for_new_accounts']) {
            continue;
        }

        foreach ($p['durations'] as $d) {
            $label = sprintf(
                '%s — %s — %s تومان',
                $p['name'],
                $d['tier'] ?? ($d['days'].' روز'),
                number_format((float) $d['purchase_total']),
            );

            $buttons[] = [[
                'text' => $label,
                'callback_data' => "buy:{$p['id']}:{$d['id']}:".($p['is_elastic'] ? '1' : '0'),
            ]];
        }
    }

    return ['inline_keyboard' => $buttons];
}

// ─────────────────────── ۳) فروش ───────────────────────

function sellAccount(int $chatId, int $packageId, int $durationId, string $customerName, ?float $gb): string
{
    $api = apiFor($chatId);

    // نام یکتا برای اکانت
    $username = preg_replace('/[^a-z0-9_-]/', '', strtolower($customerName)).'-'.substr(bin2hex(random_bytes(3)), 0, 5);

    try {
        // اول قیمت را نشان بده / موجودی را بسنج
        $quote = $api->previewPrice($packageId, $durationId, $gb);
        $wallet = $api->wallet();

        if ((float) $wallet['available'] < (float) $quote['final_charge']) {
            return sprintf(
                "❌ موجودی کافی نیست.\nقیمت: %s | موجودی: %s",
                number_format((float) $quote['final_charge']),
                number_format((float) $wallet['available']),
            );
        }

        $result = $api->sell($packageId, $durationId, $username, $gb);
    } catch (ShahPanelApiException $e) {
        if ($e->needsReauth()) {
            forgetToken($chatId);

            return '🔑 اتصال شما منقضی شد. دوباره /connect کنید.';
        }

        return '❌ '.$e->getMessage();
    }

    $account = $result['account'];

    // کانفیگ را بگیر و برای مشتری بفرست
    $config = $api->config($account['id']);

    if (! empty($config['v2ray']['subscription_link'])) {
        sendMessage($chatId, "🔗 لینک اشتراک:\n`".$config['v2ray']['subscription_link'].'`');
    }

    if (! empty($config['wireguard']['config'])) {
        sendDocument($chatId, $username.'.conf', $config['wireguard']['config']);

        if (! empty($config['wireguard']['qr_base64'])) {
            sendPhoto($chatId, base64_decode($config['wireguard']['qr_base64']));
        }
    }

    return sprintf(
        "✅ اکانت ساخته شد\n👤 %s\n📦 %s\n📅 انقضا: %s",
        $account['username'],
        $account['data']['unlimited'] ? 'نامحدود' : $account['data']['limit_gb'].' گیگ',
        substr((string) $account['expires_at'], 0, 10),
    );
}

// ─────────────────────── ۴) لیست و مدیریت ───────────────────────

function listAccounts(int $chatId, int $page = 1): string
{
    $res = apiFor($chatId)->accounts(['per_page' => 10, 'page' => $page, 'sort' => 'expiry']);

    if ($res['items'] === []) {
        return 'اکانتی ندارید.';
    }

    $lines = [];

    foreach ($res['items'] as $a) {
        $icon = match ($a['status']) {
            'active' => '🟢',
            'disabled' => '🔴',
            'expired' => '⏰',
            'exhausted' => '📭',
            default => '⚪',
        };

        $volume = $a['data']['unlimited']
            ? 'نامحدود'
            : sprintf('%s/%s گیگ', $a['data']['used_gb'], $a['data']['limit_gb']);

        $lines[] = sprintf('%s %s — %s', $icon, $a['username'], $volume);
    }

    $p = $res['pagination'];
    $lines[] = sprintf("\nصفحه %d از %d — مجموع %d", $p['current_page'], $p['last_page'], $p['total']);

    return implode("\n", $lines);
}

/** اکانت‌هایی که تا ۳ روز دیگر منقضی می‌شوند — برای یادآوری خودکار. */
function expiringSoon(int $chatId): string
{
    $res = apiFor($chatId)->accounts(['expiring_within_days' => 3, 'per_page' => 50]);

    if ($res['items'] === []) {
        return 'اکانتی نزدیک انقضا نیست.';
    }

    $lines = ['⏰ نزدیک انقضا:'];

    foreach ($res['items'] as $a) {
        $lines[] = sprintf('• %s — %s', $a['username'], substr((string) $a['expires_at'], 0, 10));
    }

    return implode("\n", $lines);
}

function renewAccount(int $chatId, string $key): string
{
    try {
        // same = دورهٔ تازه با همان حجم و صفر شدن مصرف
        $res = apiFor($chatId)->renew($key, 'same');
    } catch (ShahPanelApiException $e) {
        return '❌ '.$e->getMessage();
    }

    return '✅ تمدید شد تا '.substr((string) $res['account']['expires_at'], 0, 10);
}

/** افزودن حجم بدون صفر شدن مصرف. */
function addVolume(int $chatId, string $key, float $gb): string
{
    try {
        $res = apiFor($chatId)->renew($key, 'add_volume', null, $gb);
    } catch (ShahPanelApiException $e) {
        return '❌ '.$e->getMessage();
    }

    return '✅ حجم کل: '.$res['account']['data']['limit_gb'].' گیگ';
}

// ─────────────────────── ۵) پنل نماینده ───────────────────────

function myResellers(int $chatId): string
{
    $res = apiFor($chatId)->resellers(['per_page' => 20]);

    $lines = ['👥 فروشنده‌های من:'];

    foreach ($res['items'] as $s) {
        $lines[] = sprintf(
            '• %s — %d اکانت — موجودی %s',
            $s['username'],
            (int) $s['accounts_count'],
            number_format((float) $s['wallet']['balance']),
        );
    }

    return implode("\n", $lines);
}

function dashboard(int $chatId): string
{
    $d = apiFor($chatId)->dashboard();
    $a = $d['accounts'];

    return sprintf(
        "📊 داشبورد\n💰 موجودی: %s\n\n📦 کل: %d\n🟢 فعال: %d\n⏰ منقضی: %d\n⚠️ انقضا تا ۷ روز: %d\n🆕 امروز: %d",
        number_format((float) $d['wallet']['balance']),
        $a['total'], $a['active'], $a['expired'], $a['expiring_7d'], $a['created_today'],
    );
}

// ─────────────────────── توابعی که باید خودت پیاده کنی ───────────────────────
// saveToken/loadToken/forgetToken → دیتابیس ربات
// sendMessage/sendPhoto/sendDocument → Telegram Bot API
