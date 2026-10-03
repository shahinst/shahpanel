<?php

namespace Modules\ShahBot\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ShahBot\Bot\UpdateHandler;
use Modules\ShahBot\Support\BotSettings;
use Throwable;

/**
 * Telegram webhook. The secret is both in the URL and in the
 * X-Telegram-Bot-Api-Secret-Token header Telegram echoes back, so nobody who
 * merely guesses the path can feed the bot forged updates.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, BotSettings $settings, UpdateHandler $handler): JsonResponse
    {
        $expected = $settings->get('webhook_secret');

        if ($expected === ''
            || ! hash_equals($expected, $secret)
            || ! hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(404);
        }

        try {
            $handler->handle($request->json()->all());
        } catch (Throwable $e) {
            // Any status but 200 makes Telegram resend the same update again
            // and again, so a failure is logged and swallowed.
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
