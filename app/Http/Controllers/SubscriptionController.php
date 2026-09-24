<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionFeedService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SubscriptionController extends Controller
{
    public function show(Request $request, string $token, SubscriptionFeedService $feed): Response
    {
        $account = $feed->resolve($token);

        if ($account === null) {
            return $this->notFound();
        }

        // اکانت غیرفعال/منقضی/تمام‌شده هم همان لینک‌های ذخیره‌شده را می‌گیرد،
        // دقیقاً مثل پورتال کاربر (ClientPortalController::show) که وضعیت را
        // نمایش می‌دهد ولی لینک را پنهان نمی‌کند. وضعیت واقعی از هدر
        // Subscription-Userinfo به کلاینت می‌رسد.
        $body = $feed->body($feed->links($account), $this->wantsBase64($request));

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Subscription-Userinfo' => $feed->userInfoHeader($account),
            'Profile-Update-Interval' => '12',
        ]);
    }

    /**
     * یک پاسخ برای هر سه حالت ناشناس، ابطال‌شده و بدشکل، تا مهاجم نتواند از
     * تفاوت پاسخ‌ها بفهمد اکانتی با آن توکن وجود داشته یا نه. عمداً abort(404)
     * نیست تا صفحهٔ HTML خطا به کلاینت VPN تحویل نشود.
     */
    protected function notFound(): Response
    {
        return response('', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * بسیاری از کلاینت‌ها بدنهٔ base64 می‌خواهند. پیش‌فرض متن ساده است و
     * base64 فقط با درخواست صریح فعال می‌شود.
     */
    protected function wantsBase64(Request $request): bool
    {
        if ($request->boolean('b64') || $request->boolean('base64')) {
            return true;
        }

        return str_contains(strtolower((string) $request->header('Accept', '')), 'base64');
    }
}
