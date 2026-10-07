<?php

namespace Modules\Transfer\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Transfer\Services\TransferService;
use RuntimeException;

class TransferController extends Controller
{
    public function __construct(protected TransferService $transfer) {}

    public function index(): View
    {
        return view('transfer::index', [
            'job' => Str::random(40),
            'chunkBytes' => $this->transfer->chunkBytes(),
            'steps' => TransferService::STEPS,
        ]);
    }

    public function chunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job' => ['required', 'regex:/^[A-Za-z0-9]{40}$/'],
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        try {
            $size = $this->transfer->appendChunk($data['job'], (int) $data['offset'], $request->file('chunk'));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['size' => $size]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job' => ['required', 'regex:/^[A-Za-z0-9]{40}$/'],
            'app_key' => ['nullable', 'string', 'max:200'],
            'confirm' => ['accepted'],
        ]);

        try {
            $this->transfer->start($data['job'], (string) ($data['app_key'] ?? ''));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['status_url' => route('transfer.status', $data['job'])]);
    }

    public function status(string $job): JsonResponse
    {
        $status = $this->transfer->status($job);
        abort_if($status === null, 404);

        return response()->json($status)->header('Cache-Control', 'no-store');
    }
}
