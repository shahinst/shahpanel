<?php

namespace App\Http\Controllers;

use App\Services\AppStoreIconService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PortalIconController extends Controller
{
    public function show(string $filename): BinaryFileResponse|Response
    {
        if (! AppStoreIconService::isStoredIconFilename($filename)) {
            abort(404);
        }

        $path = AppStoreIconService::storedRelativePath($filename);

        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $absolute = Storage::disk('public')->path($path);
        $mime = match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'image/png',
        };

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }
}
