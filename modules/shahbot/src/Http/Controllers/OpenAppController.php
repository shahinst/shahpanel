<?php

namespace Modules\ShahBot\Http\Controllers;

use App\Models\Account;
use App\Services\SubscriptionFeedService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;

/**
 * One tap from the bot to the customer's VPN app with the subscription
 * already filled in.
 *
 * Telegram buttons only open http(s) links, so the button lands here and
 * this page hands over to the app's own scheme. The link is signed for one
 * account: it cannot be turned into a redirect to anything else.
 */
class OpenAppController extends Controller
{
    /** @var array<string, array{label: string, scheme: string}> */
    public const APPS = [
        'v2rayng' => ['label' => 'v2rayNG', 'scheme' => 'v2rayng://install-config?url={enc}'],
        'hiddify' => ['label' => 'Hiddify', 'scheme' => 'hiddify://import/{raw}'],
        'streisand' => ['label' => 'Streisand', 'scheme' => 'streisand://import/{raw}'],
        'v2box' => ['label' => 'V2Box', 'scheme' => 'v2box://install-sub?url={enc}'],
        'singbox' => ['label' => 'sing-box', 'scheme' => 'sing-box://import-remote-profile?url={enc}'],
    ];

    public static function link(Account $account, string $app): string
    {
        $path = URL::signedRoute('shahbot.open', ['account' => $account->id, 'app' => $app], absolute: false);
        $base = rtrim((string) config('app.url'), '/');

        return str_starts_with($base, 'https://') ? $base.$path : url($path);
    }

    // Outside the web group there is no route model binding: the id comes in plain.
    public function __invoke(Request $request, int $account, string $app): Response
    {
        abort_unless(isset(self::APPS[$app]) && $request->hasValidSignature(false), 404);
        $account = Account::query()->findOrFail($account);

        $url = app(SubscriptionFeedService::class)->urlFor($account);
        $target = strtr(self::APPS[$app]['scheme'], ['{enc}' => rawurlencode($url), '{raw}' => $url]);
        $label = e(self::APPS[$app]['label']);
        $targetHtml = e($target);
        $urlHtml = e($url);
        $targetJs = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $hint = e(__('shahbot::bot.open_app_hint', ['app' => self::APPS[$app]['label']]));

        $html = <<<HTML
            <!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <meta name="robots" content="noindex"><title>{$label}</title>
            <style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;text-align:center}a.b{display:inline-block;padding:.8rem 1.4rem;background:#2563eb;color:#fff;border-radius:.6rem;text-decoration:none}code{word-break:break-all;font-size:.8rem}</style>
            </head><body><p>{$hint}</p><p><a class="b" href="{$targetHtml}">{$label}</a></p><p><code dir="ltr">{$urlHtml}</code></p>
            <script>location.href={$targetJs};</script></body></html>
            HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
