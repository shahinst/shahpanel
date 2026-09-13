import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

function viteBase() {
    const raw = process.env.ASSET_URL || process.env.APP_URL || '';

    if (!raw) {
        return '/build/';
    }

    try {
        const pathname = new URL(raw).pathname.replace(/\/$/, '');

        return (pathname && pathname !== '/' ? pathname : '') + '/build/';
    } catch {
        return '/build/';
    }
}

export default defineConfig({
    base: viteBase(),
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/icons.css',
                'resources/css/auth.css',
                'resources/css/portal.css',
                'resources/js/app.js',
                'resources/js/portal.js',
            ],
            refresh: true,
        }),
    ],
});
