<?php

namespace Modules\ShahBot\Http\Controllers;

use App\Enums\AccountCategory;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Services\PortalLinkService;
use App\Services\SanaeiPortalService;
use App\Services\SubscriptionFeedService;
use App\Services\WireGuardConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\BotUserService;
use Modules\ShahBot\Services\ShopService;
use Modules\ShahBot\Support\BotAccess;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\TelegramClient;

/**
 * The Telegram Mini App: the user's services, usage and wallet in one screen.
 * The page itself is public; every piece of data is fetched with Telegram's
 * signed initData, which is checked against the bot's token, so only the
 * Telegram user it was issued for ever sees their services.
 */
class MiniAppController extends Controller
{
    public function show(int $bot, BotSettings $settings, BotContext $context): View
    {
        $instance = $this->bot($bot, $settings);

        return $context->run($instance, fn () => view('shahbot::mini-app', [
            'botId' => $bot,
            // An agent's own brand, falling back to the panel's.
            'brand' => $settings->get('brand_name') ?: app_display_name(),
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $settings->get('brand_color')) === 1 ? $settings->get('brand_color') : null,
            'logo' => str_starts_with((string) $settings->get('brand_logo'), 'https://') ? $settings->get('brand_logo') : null,
            'botUsername' => $settings->get('bot_username'),
        ]));
    }

    public function me(Request $request, int $bot, BotSettings $settings, BotContext $context, BotUserService $users, ShopService $shop): JsonResponse
    {
        $instance = $this->bot($bot, $settings);

        return $context->run($instance, function () use ($request, $bot, $settings, $users, $shop): JsonResponse {
            $tgUser = $this->verify((string) $request->input('initData', ''), $settings->get('bot_token'));
            abort_if($tgUser === null, 401);

            $user = BotUser::query()->where('bot_id', $bot)->where('telegram_id', (int) $tgUser['id'])->first();

            if ($user === null || $user->is_blocked) {
                return response()->json(['registered' => false]);
            }

            $feed = app(SubscriptionFeedService::class);

            return response()->json([
                'registered' => true,
                'name' => $user->displayName(),
                'balance' => format_money($users->balance($user)),
                'orders' => BotOrder::query()->where('bot_user_id', $user->id)->whereIn('type', ['buy', 'renew'])->count(),
                'services' => $shop->accounts($user)->map(function (Account $account) use ($feed): array {
                    $limit = (int) $account->data_limit_bytes;
                    $used = (int) $account->data_used_bytes;

                    return [
                        'id' => (int) $account->id,
                        'wireguard' => $account->service_type === ServiceType::Wireguard,
                        'name' => (string) ($account->display_label ?: $account->remote_username),
                        'status' => $account->status->value,
                        'status_label' => __('shahbot::bot.status_'.$account->status->value, [], 'fa'),
                        'package' => (string) ($account->package?->name ?? ''),
                        'used' => format_data_size($used),
                        'limit' => $account->isUnlimited() ? __('shahbot::bot.unlimited', [], 'fa') : format_data_size($limit),
                        'percent' => $limit > 0 ? min(100, (int) round($used * 100 / $limit)) : 0,
                        'expiry' => $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d H:i') : null,
                        'days_left' => $account->expiry_at ? max(0, (int) floor(now()->diffInHours($account->expiry_at, false) / 24)) : null,
                        'sub_url' => $account->service_type->accountCategory() === AccountCategory::V2ray ? $feed->urlFor($account) : null,
                    ];
                })->values(),
                // The store, same rows and prices the bot's buy menu shows, so the
                // mini app is no longer an account viewer with nothing to buy.
                'plans' => rescue(fn () => $shop->groups($user)->map(fn (array $group): array => [
                    'label' => (string) $group['label'],
                    'rows' => $group['rows']->map(fn (array $row): array => [
                        'id' => (int) $row['duration']->id,
                        'name' => (string) $row['package']->name,
                        'period' => $row['duration']->tier->label(),
                        'price' => format_money($row['display_price']),
                    ])->values(),
                ])->values(), [], true),
            ]);
        });
    }

    /**
     * One service, opened from "My services": its config, QR and renewal
     * options, plus the actions on it -- renewing from the wallet and sending
     * the config files into the chat. Everything goes through the same signed
     * initData check as me(), and the account must belong to this Telegram user.
     */
    public function service(Request $request, int $bot, BotSettings $settings, BotContext $context, BotUserService $users, ShopService $shop): JsonResponse
    {
        $instance = $this->bot($bot, $settings);

        return $context->run($instance, function () use ($request, $bot, $settings, $users, $shop): JsonResponse {
            $tgUser = $this->verify((string) $request->input('initData', ''), $settings->get('bot_token'));
            abort_if($tgUser === null, 401);

            $user = BotUser::query()->where('bot_id', $bot)->where('telegram_id', (int) $tgUser['id'])->first();
            abort_if($user === null || $user->is_blocked, 403);

            $account = Account::query()->find((int) $request->input('account'));

            try {
                abort_if($account === null, 404);
                $shop->assertOwnsAccount($user, $account);
            } catch (\InvalidArgumentException) {
                abort(404);
            }

            $wireguard = app(WireGuardConfigService::class);
            $isWg = $account->service_type === ServiceType::Wireguard;

            try {
                return match ((string) $request->input('action', 'show')) {
                    'renew' => $this->renewFromApp($user, $account, (int) $request->input('duration'), $shop, $users),
                    'send' => $this->sendToChat($user, $account, $isWg, $wireguard),
                    default => response()->json($this->serviceDetail($account, $user, $isWg, $wireguard, $shop, $users)),
                };
            } catch (\InvalidArgumentException $e) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        });
    }

    protected function serviceDetail(Account $account, BotUser $user, bool $isWg, WireGuardConfigService $wireguard, ShopService $shop, BotUserService $users): array
    {
        $config = null;
        $qr = null;
        $configError = null;

        if ($isWg) {
            try {
                $config = $wireguard->buildConfig($account);
                $qr = 'data:image/png;base64,'.base64_encode($wireguard->buildQrPng($account));
            } catch (\Throwable $e) {
                $configError = $e->getMessage();
            }
        } elseif ($account->service_type->accountCategory() === AccountCategory::V2ray) {
            $link = app(SubscriptionFeedService::class)->urlFor($account);
            $config = $link;
            $qr = $link ? rescue(fn () => 'data:image/png;base64,'.app(SanaeiPortalService::class)->qrBase64($link), null, false) : null;
        }

        return [
            'ok' => true,
            'id' => (int) $account->id,
            'wireguard' => $isWg,
            'config' => $config,
            'config_error' => $configError,
            'qr' => $qr,
            'filename' => $isWg ? $wireguard->configFilename($account) : null,
            'page' => rescue(fn () => app(PortalLinkService::class)->ensure($account), null, false),
            'balance' => format_money($users->balance($user)),
            'renewals' => $shop->renewalOptions($user, $account)->map(fn (array $o): array => [
                'id' => (int) $o['duration']->id,
                'period' => $o['duration']->tier->label(),
                'price' => format_money($o['price']),
                'affordable' => (float) $users->balance($user) >= (float) $o['price'],
            ])->values(),
        ];
    }

    protected function renewFromApp(BotUser $user, Account $account, int $durationId, ShopService $shop, BotUserService $users): JsonResponse
    {
        $shop->renew($user, $account, $durationId);
        $account->refresh();

        return response()->json([
            'ok' => true,
            'message' => __('shahbot::bot.app_renewed', ['date' => $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d') : '—'], 'fa'),
            'balance' => format_money($users->balance($user)),
        ]);
    }

    /**
     * Telegram's in-app browser does not reliably save files, so "download"
     * sends the config and its QR into the chat with the bot, where they can be
     * saved or forwarded to another phone.
     */
    protected function sendToChat(BotUser $user, Account $account, bool $isWg, WireGuardConfigService $wireguard): JsonResponse
    {
        $tg = app(TelegramClient::class);
        $chat = (int) $user->telegram_id;
        $name = (string) ($account->display_label ?: $account->remote_username);

        if (! $isWg) {
            throw new \InvalidArgumentException(__('shahbot::bot.app_send_wg_only', [], 'fa'));
        }

        try {
            $tg->sendDocumentBytes($chat, $wireguard->buildConfig($account), $wireguard->configFilename($account), $name);
            $tg->sendPhotoBytes($chat, $wireguard->buildQrPng($account), $wireguard->qrFilename($account), $name);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException($e->getMessage());
        }

        return response()->json(['ok' => true, 'message' => __('shahbot::bot.app_sent', [], 'fa')]);
    }

    protected function bot(int $bot, BotSettings $settings): ?BotInstance
    {
        if ($bot === 0) {
            abort_unless($settings->bool('mini_app_enabled'), 404);

            return null;
        }

        $instance = BotInstance::query()->whereKey($bot)->where('is_active', true)->first();
        // The bot works only while its owner still holds bot access.
        abort_if($instance === null || ! app(BotAccess::class)->allows($instance->owner), 404);

        return $instance;
    }

    /**
     * Checks Telegram's signature over initData and returns its user.
     *
     * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
     */
    public static function verify(string $initData, string $token): ?array
    {
        if ($initData === '' || $token === '') {
            return null;
        }

        parse_str($initData, $fields);
        $hash = (string) ($fields['hash'] ?? '');
        unset($fields['hash']);

        if ($hash === '' || (int) ($fields['auth_date'] ?? 0) < time() - 86400) {
            return null;
        }

        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($fields), $fields));
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);

        if (! hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
            return null;
        }

        $user = json_decode((string) ($fields['user'] ?? ''), true);

        return is_array($user) && isset($user['id']) ? $user : null;
    }
}
