<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApplicationLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class ApplicationLogController extends Controller
{
    public function index(Request $request, ApplicationLogService $logService): View
    {
        try {
            $files = $logService->listFiles();
            $fileNames = array_column($files, 'name');
            $requestedFile = (string) $request->input('file', 'laravel.log');
            $selectedFile = in_array($requestedFile, $fileNames, true)
                ? $requestedFile
                : ($fileNames[0] ?? 'laravel.log');

            $level = (string) $request->input('level', 'all');
            $search = $request->filled('q') ? (string) $request->input('q') : null;
            $lines = (int) $request->input('lines', 500);

            try {
                $result = $logService->read($selectedFile, $lines, $level, $search);
            } catch (InvalidArgumentException $exception) {
                $result = [
                    'content' => $exception->getMessage(),
                    'file' => $selectedFile,
                    'line_count' => 0,
                    'truncated' => false,
                    'file_missing' => true,
                    'file_not_readable' => false,
                ];
            }

            return view('admin.settings.logs', [
                'files' => $files,
                'selectedFile' => $selectedFile,
                'canClearSelectedFile' => $logService->canClear($selectedFile),
                'level' => $level,
                'search' => $search ?? '',
                'lines' => max(50, min(2000, $lines)),
                'logContent' => $result['content'],
                'logLineCount' => $result['line_count'],
                'logTruncated' => $result['truncated'],
                'logMissing' => $result['file_missing'],
                'logNotReadable' => $result['file_not_readable'] ?? false,
                'loadError' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return view('admin.settings.logs', [
                'files' => [],
                'selectedFile' => 'laravel.log',
                'canClearSelectedFile' => false,
                'level' => 'all',
                'search' => '',
                'lines' => 500,
                'logContent' => '',
                'logLineCount' => 0,
                'logTruncated' => false,
                'logMissing' => true,
                'logNotReadable' => false,
                'loadError' => $exception->getMessage(),
            ]);
        }
    }

    public function clear(Request $request, ApplicationLogService $logService): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'string', 'max:255'],
        ]);

        $file = (string) $request->input('file');

        try {
            $result = $logService->clear($file);
        } catch (InvalidArgumentException) {
            return redirect()
                ->route('admin.settings.logs')
                ->with('error', __('settings.logs_clear_failed'));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.settings.logs', ['file' => $file])
                ->with('error', __('settings.logs_clear_failed'));
        }

        if (! $result['cleared']) {
            $message = match ($result['error']) {
                'not_writable' => __('settings.logs_clear_not_writable', ['file' => $file]),
                'missing' => __('settings.logs_file_missing'),
                default => __('settings.logs_clear_failed'),
            };

            return redirect()
                ->route('admin.settings.logs', ['file' => $file])
                ->with('error', $message);
        }

        return redirect()
            ->route('admin.settings.logs', ['file' => $file])
            ->with('success', __('settings.logs_cleared'));
    }
}
