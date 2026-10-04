<?php

namespace Modules\ShahBot\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Services\PaymentService;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Telegram\TelegramClient;
use RuntimeException;

/**
 * Receipts and payments made in a reseller's bot, from their own panel.
 *
 * A seller sees their bot's payments. An agent also sees their sellers' bots,
 * but only to look: approving a receipt moves money out of the bot owner's
 * wallet, so only that owner may approve or reject it.
 */
class PaymentsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->reseller($request);
        $bots = $this->visibleBots($user);
        $status = (string) $request->query('status', BotPayment::PENDING);
        $scope = BotPayment::query()->whereHas('botUser', fn ($q) => $q->whereIn('bot_id', $bots->keys()));

        return view('shahbot::panel.payments', [
            'panel' => $user->role === UserRole::Agent ? 'agent' : 'seller',
            'payments' => (clone $scope)->with('botUser')
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest('id')->paginate(30)->withQueryString(),
            'counts' => (clone $scope)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'status' => $status,
            'bots' => $bots,
            'ownBotId' => (int) ($bots->search(fn (BotInstance $b) => (int) $b->owner_user_id === $user->id) ?: 0),
        ]);
    }

    public function receipt(Request $request, BotPayment $payment): Response
    {
        $bot = $this->botOf($payment, $this->visibleBots($this->reseller($request)));
        abort_if($payment->receipt_file_id === null, 404);

        // The file id belongs to the bot that received the photo; another
        // bot's token cannot download it.
        $bytes = Cache::remember('shahbot:receipt:'.$payment->id, now()->addHour(), fn () => app(BotContext::class)->run(
            $bot,
            fn () => ($b = app(TelegramClient::class)->downloadFile($payment->receipt_file_id)) !== null ? base64_encode($b) : null,
        ));
        abort_if($bytes === null, 404);

        return response(base64_decode($bytes), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, BotPayment $payment, PaymentService $payments): RedirectResponse
    {
        $user = $this->reseller($request);
        $this->assertOwnBot($payment, $user);

        try {
            $payments->approve($payment, 'panel:'.$user->username);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.payment_approved'));
    }

    public function reject(Request $request, BotPayment $payment, PaymentService $payments): RedirectResponse
    {
        $user = $this->reseller($request);
        $this->assertOwnBot($payment, $user);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']]);

        try {
            $payments->reject($payment, 'panel:'.$user->username, $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.payment_rejected'));
    }

    protected function reseller(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Agent, UserRole::Seller], true), 403);

        return $user;
    }

    /**
     * @return Collection<int, BotInstance> keyed by bot id
     */
    protected function visibleBots(User $user)
    {
        $owners = collect([$user->id]);

        if ($user->role === UserRole::Agent) {
            $owners = $owners->merge(User::query()->where('parent_id', $user->id)
                ->where('role', UserRole::Seller)->pluck('id'));
        }

        return BotInstance::query()->with('owner:id,full_name,username')
            ->whereIn('owner_user_id', $owners)->get()->keyBy('id');
    }

    protected function botOf(BotPayment $payment, $bots): BotInstance
    {
        $bot = $bots->get((int) $payment->botUser?->bot_id);
        abort_if($bot === null, 404);

        return $bot;
    }

    protected function assertOwnBot(BotPayment $payment, User $user): void
    {
        $bot = BotInstance::query()->find((int) $payment->botUser?->bot_id);
        abort_unless($bot !== null && (int) $bot->owner_user_id === $user->id, 403);
    }
}
