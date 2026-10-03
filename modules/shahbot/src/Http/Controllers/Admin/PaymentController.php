<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Services\PaymentService;
use Modules\ShahBot\Telegram\TelegramClient;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', BotPayment::PENDING);

        $payments = BotPayment::query()
            ->with('botUser')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('shahbot::payments', [
            'payments' => $payments,
            'status' => $status,
            'counts' => BotPayment::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function receipt(BotPayment $payment, TelegramClient $telegram): Response
    {
        abort_if($payment->receipt_file_id === null, 404);

        $bytes = Cache::remember('shahbot:receipt:'.$payment->id, now()->addHour(),
            fn () => ($b = $telegram->downloadFile($payment->receipt_file_id)) !== null ? base64_encode($b) : null);

        abort_if($bytes === null, 404);

        return response(base64_decode($bytes), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, BotPayment $payment, PaymentService $payments): RedirectResponse
    {
        try {
            $payments->approve($payment, 'panel:'.$request->user()->username);
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.payment_approved'));
    }

    public function reject(Request $request, BotPayment $payment, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']]);

        try {
            $payments->reject($payment, 'panel:'.$request->user()->username, $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.payment_rejected'));
    }
}
