<?php

namespace Modules\ShahBot\Services;

use Modules\ShahBot\Models\BotTicket;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Telegram\Keyboard;

/**
 * Support conversations. A user's messages gather in their one open ticket;
 * admins answer from the bot (reply button) or from the panel.
 */
class TicketService
{
    public function __construct(protected BotNotifier $notifier) {}

    public function fromUser(BotUser $user, string $text): BotTicket
    {
        $ticket = BotTicket::query()
            ->where('bot_user_id', $user->id)
            ->where('status', '!=', BotTicket::CLOSED)
            ->latest('id')
            ->first()
            ?? BotTicket::query()->create(['bot_user_id' => $user->id, 'status' => BotTicket::OPEN]);

        $ticket->messages()->create(['from_admin' => false, 'author' => $user->displayName(), 'body' => mb_substr($text, 0, 4000)]);
        $ticket->update(['status' => BotTicket::OPEN, 'last_message_at' => now()]);

        $this->notifier->support(
            __('shahbot::bot.admin_new_ticket', [
                'id' => $ticket->id,
                'user' => e($user->displayName()),
                'tg' => $user->telegram_id,
                'text' => e(mb_substr($text, 0, 3000)),
            ]),
            Keyboard::inline([[
                Keyboard::button(__('shahbot::bot.btn_reply'), 'adm:tk:reply:'.$ticket->id),
                Keyboard::button(__('shahbot::bot.btn_close_ticket'), 'adm:tk:close:'.$ticket->id),
            ]])
        );

        return $ticket;
    }

    public function reply(BotTicket $ticket, string $text, string $author): bool
    {
        $ticket->messages()->create(['from_admin' => true, 'author' => mb_substr($author, 0, 128), 'body' => mb_substr($text, 0, 4000)]);
        $ticket->update(['status' => BotTicket::ANSWERED, 'last_message_at' => now()]);

        return $this->notifier->user($ticket->botUser, fn () => __('shahbot::bot.ticket_reply', ['id' => $ticket->id, 'text' => e($text)]));
    }

    public function close(BotTicket $ticket): void
    {
        $ticket->update(['status' => BotTicket::CLOSED]);
    }
}
