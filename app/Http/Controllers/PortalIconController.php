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

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // این مسیر بیرون از هر گروه احراز هویت است و روی مبدأ خودِ پنل پاسخ
        // می‌دهد. یک SVG می‌تواند <script> داشته باشد، پس آیکون‌های SVGِ قدیمی
        // که پیش‌تر ذخیره شده‌اند روی دیسک می‌مانند اما دیگر سرو نمی‌شوند.
        if ($extension === 'svg') {
            abort(404);
        }

        $absolute = Storage::disk('public')->path($path);
        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            default => 'image/png',
        };

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=604800, immutable',
            // nosniff جلوی تفسیر یک بایت‌آرایهٔ دستکاری‌شده به‌عنوان HTML را
            // می‌گیرد و CSP حتی اگر فایلی از گذشته اسکریپت داشته باشد اجرای آن
            // را روی مبدأ پنل ممنوع می‌کند.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}
