<?php

namespace Modules\ShahBot\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ShahBot\Bot\UpdateHandler;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Support\BotAccess;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;
use Throwable;

/**
 * Telegram webhook of the main bot and of every agent bot. The secret in the
 * URL picks the bot, and Telegram must echo the same secret in the
 * X-Telegram-Bot-Api-Secret-Token header, so nobody who merely guesses a path
 * can feed a bot forged updates.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, BotSettings $settings, BotContext $context, UpdateHandler $handler): JsonResponse
    {
        $bot = null;
        $main = $settings->main('webhook_secret');

        if ($main === '' || ! hash_equals($main, $secret)) {
            $bot = BotInstance::query()->with('owner')->where('webhook_secret', $secret)->where('is_active', true)->first();

            // Per-person access is the only switch, as in polling and the mini app.
            abort_if($bot === null || ! app(BotAccess::class)->allows($bot->owner), 404);
        }

        abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 404);

        try {
            $context->run($bot, fn () => $handler->handle($request->json()->all()));
        } catch (Throwable $e) {
            // Any status but 200 makes Telegram resend the same update again
            // and again, so a failure is logged and swallowed.
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
