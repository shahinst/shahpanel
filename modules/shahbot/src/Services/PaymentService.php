<?php

namespace Modules\ShahBot\Services;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\Keyboard;

/**
 * Card-to-card wallet top-ups. The money reaches the owner's card, so approval
 * moves the amount from the owner's wallet to the buyer's, exactly like an
 * approved payment request in the client portal.
 */
class PaymentService
{
    public function __construct(
        protected BotSettings $settings,
        protected BotUserService $users,
        protected WalletService $wallets,
        protected BotNotifier $notifier,
    ) {}

    public function start(BotUser $user, string $amount): BotPayment
    {
        if (! $this->settings->bool('topup_enabled')) {
            throw new InvalidArgumentException(__('shahbot::bot.topup_disabled'));
        }

        $amount = (float) preg_replace('/[^\d.]/', '', western_digits($amount));
        $min = (float) $this->settings->get('topup_min');
        $max = (float) $this->settings->get('topup_max');

        if ($amount <= 0 || ($min > 0 && $amount < $min) || ($max > 0 && $amount > $max)) {
            throw new InvalidArgumentException(__('shahbot::bot.topup_range', [
                'min' => format_money($min),
                'max' => format_money($max),
            ]));
        }

        // One open request at a time; an older unpaid one is simply replaced.
        BotPayment::query()
            ->where('bot_user_id', $user->id)
            ->where('status', BotPayment::AWAITING_RECEIPT)
            ->update(['status' => BotPayment::CANCELLED]);

        return BotPayment::query()->create([
            'bot_user_id' => $user->id,
            'amount' => number_format($amount, 2, '.', ''),
            'method' => 'card',
            'status' => BotPayment::AWAITING_RECEIPT,
        ]);
    }

    public function attachReceipt(BotPayment $payment, ?string $fileId, ?string $note): BotPayment
    {
        if ($payment->status !== BotPayment::AWAITING_RECEIPT) {
            throw new InvalidArgumentException(__('shahbot::bot.payment_closed'));
        }

        $payment->update([
            'status' => BotPayment::PENDING,
            'receipt_file_id' => $fileId,
            'receipt_note' => $note !== null ? mb_substr($note, 0, 1000) : null,
        ]);

        $user = $payment->botUser;
        $caption = __('shahbot::bot.admin_new_receipt', [
            'id' => $payment->id,
            'user' => e($user->displayName()),
            'tg' => $user->telegram_id,
            'amount' => format_money($payment->amount),
            'note' => e((string) $payment->receipt_note),
        ]);
        $keyboard = Keyboard::inline([[
            Keyboard::button(__('shahbot::bot.btn_approve'), 'adm:pay:ok:'.$payment->id),
            Keyboard::button(__('shahbot::bot.btn_reject'), 'adm:pay:no:'.$payment->id),
        ]]);

        if ($fileId !== null) {
            $this->notifier->adminsPhoto($fileId, $caption, $keyboard);
        } else {
            $this->notifier->admins($caption, $keyboard);
        }

        return $payment;
    }

    public function approve(BotPayment $payment, string $reviewer): BotPayment
    {
        $payment = DB::transaction(function () use ($payment, $reviewer): BotPayment {
            $locked = BotPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BotPayment::PENDING) {
                throw new InvalidArgumentException(__('shahbot::bot.payment_already_reviewed'));
            }

            $user = $locked->botUser;
            $client = $this->users->client($user);
            $owner = $this->users->owner();
            $amount = number_format((float) $locked->amount, 2, '.', '');
            $context = ['description' => 'Bot card-to-card top-up #'.$locked->id, 'source_user_id' => $client->id];

            try {
                $this->wallets->debit($owner, $amount, TransactionType::Charge, $context);
            } catch (InsufficientWalletBalanceException) {
                throw new InvalidArgumentException(__('shahbot::bot.owner_balance_low'));
            }

            $this->wallets->credit($client, $amount, TransactionType::Charge, array_merge($context, ['source_user_id' => $owner->id]));

            $locked->update([
                'status' => BotPayment::APPROVED,
                'reviewed_by' => mb_substr($reviewer, 0, 128),
                'reviewed_at' => now(),
            ]);

            return $locked;
        });

        $this->notifier->user($payment->botUser, __('shahbot::bot.payment_approved', [
            'amount' => format_money($payment->amount),
            'balance' => format_money($this->users->balance($payment->botUser)),
        ]));

        return $payment;
    }

    public function reject(BotPayment $payment, string $reviewer, ?string $reason = null): BotPayment
    {
        $payment = DB::transaction(function () use ($payment, $reviewer, $reason): BotPayment {
            $locked = BotPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [BotPayment::PENDING, BotPayment::AWAITING_RECEIPT], true)) {
                throw new InvalidArgumentException(__('shahbot::bot.payment_already_reviewed'));
            }

            $locked->update([
                'status' => BotPayment::REJECTED,
                'reviewed_by' => mb_substr($reviewer, 0, 128),
                'reviewed_at' => now(),
                'reject_reason' => $reason !== null ? mb_substr($reason, 0, 250) : null,
            ]);

            return $locked;
        });

        $this->notifier->user($payment->botUser, __('shahbot::bot.payment_rejected', [
            'amount' => format_money($payment->amount),
            'reason' => e($payment->reject_reason ?: '—'),
        ]));

        return $payment;
    }
}
