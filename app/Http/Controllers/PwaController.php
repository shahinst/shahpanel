<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Lets the panel be installed as an app (Windows, Android, macOS, iOS): a
 * manifest coloured by the active theme, a small service worker that keeps
 * the shell reachable when the network drops, and the page it shows then.
 */
class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        $theme = panel_theme();
        $name = app_display_name();
        $color = $theme['theme_color'] ?? '#4f46e5';

        return response()->json([
            'id' => '/',
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => $name,
            'lang' => str_replace('_', '-', app()->getLocale()),
            'dir' => locale_dir(),
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => $color,
            'theme_color' => $color,
            'icons' => [
                ['src' => asset('images/pwa/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('images/pwa/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('images/pwa/maskable-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('images/pwa/maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Network first for pages, so nothing stale is ever shown; the icons and
     * the offline page are kept so the app opens even with no connection.
     */
    public function worker(): Response
    {
        $version = trim((string) @file_get_contents(base_path('VERSION'))) ?: '0';
        $offline = route('pwa.offline', [], false);
        $precache = json_encode([$offline, asset('images/pwa/icon-192.png'), asset('images/pwa/icon-512.png')], JSON_UNESCAPED_SLASHES);

        $js = <<<JS
const CACHE = 'shahpanel-{$version}';
const PRECACHE = {$precache};
const OFFLINE = '{$offline}';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        return;
    }
    if (PRECACHE.includes(url.pathname) || PRECACHE.includes(request.url)) {
        event.respondWith(caches.match(request).then((hit) => hit || fetch(request)));
    }
});
JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    public function offline(): View
    {
        return view('pwa.offline');
    }
}
