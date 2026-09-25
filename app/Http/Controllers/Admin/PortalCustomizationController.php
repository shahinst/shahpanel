<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AppStoreIconService;
use App\Services\PortalCustomizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PortalCustomizationController extends Controller
{
    public function edit(PortalCustomizationService $portal): View
    {
        return view('admin.automation.portal', [
            'announcements' => $portal->rawAnnouncements(),
            'appCategories' => $portal->rawSuggestedApps(),
        ]);
    }

    public function update(Request $request, PortalCustomizationService $portal, AppStoreIconService $icons): RedirectResponse
    {
        $validated = $request->validate([
            'announcements' => ['nullable', 'array'],
            'announcements.*.html' => ['nullable', 'string', 'max:20000'],
            'announcements.*.enabled' => ['nullable', 'boolean'],
            'announcements.*.sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'app_categories' => ['nullable', 'array'],
            'app_categories.*.name' => ['nullable', 'string', 'max:120'],
            'app_categories.*.sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            // SSRF: این مقدار مستقیم به دانلودکنندهٔ آیکون می‌رسد، پس باید یک نشانی
            // واقعی http/https باشد تا file://، gopher:// و شبه‌نشانی‌ها رد شوند.
            'app_categories.*.icon_source_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'app_categories.*.icon_url' => ['nullable', 'string', 'max:500'],
            'app_categories.*.apps' => ['nullable', 'array'],
            'app_categories.*.apps.*.name' => ['nullable', 'string', 'max:120'],
            'app_categories.*.apps.*.url' => ['nullable', 'url', 'max:500'],
            'app_categories.*.apps.*.icon_url' => ['nullable', 'string', 'max:500'],
        ]);

        $oldCategories = collect($portal->rawSuggestedApps())->keyBy('id');

        $announcements = [];
        foreach ($validated['announcements'] ?? [] as $row) {
            $html = $portal->sanitizeAnnouncementHtml((string) ($row['html'] ?? ''));
            if ($html === '') {
                continue;
            }

            $announcements[] = [
                'id' => (string) ($row['id'] ?? Str::uuid()),
                'html' => $html,
                'enabled' => (bool) ($row['enabled'] ?? true),
                'sort' => (int) ($row['sort'] ?? 0),
            ];
        }

        $categories = [];
        foreach ($validated['app_categories'] ?? [] as $category) {
            $name = trim((string) ($category['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $apps = [];
            foreach ($category['apps'] ?? [] as $app) {
                $appName = trim((string) ($app['name'] ?? ''));
                $url = trim((string) ($app['url'] ?? ''));
                if ($appName === '' || $url === '') {
                    continue;
                }

                $iconUrl = trim((string) ($app['icon_url'] ?? ''));
                if ($iconUrl === '' || $request->boolean('refresh_icons')) {
                    $iconUrl = $icons->resolveAndStore($url);
                } else {
                    $iconUrl = AppStoreIconService::normalizePublicUrl($iconUrl);
                }

                $apps[] = [
                    'id' => (string) ($app['id'] ?? Str::uuid()),
                    'name' => $appName,
                    'url' => $url,
                    'icon_url' => $iconUrl,
                ];
            }

            if ($apps === []) {
                continue;
            }

            $categoryId = (string) ($category['id'] ?? Str::uuid());
            $oldCategory = $oldCategories->get($categoryId);
            $iconSourceUrl = trim((string) ($category['icon_source_url'] ?? ''));
            $categoryIconUrl = trim((string) ($category['icon_url'] ?? ''));
            $oldIconSourceUrl = trim((string) ($oldCategory['icon_source_url'] ?? ''));

            if ($iconSourceUrl !== '') {
                $shouldDownloadIcon = $categoryIconUrl === ''
                    || $request->boolean('refresh_icons')
                    || $iconSourceUrl !== $oldIconSourceUrl
                    || ! AppStoreIconService::storedFileExists($categoryIconUrl);

                if ($shouldDownloadIcon) {
                    $categoryIconUrl = $icons->storeFromSource($iconSourceUrl);
                } else {
                    $categoryIconUrl = AppStoreIconService::normalizePublicUrl($categoryIconUrl);
                }
            } else {
                $categoryIconUrl = '';
            }

            $categories[] = [
                'id' => $categoryId,
                'name' => $name,
                'sort' => (int) ($category['sort'] ?? 0),
                'icon_source_url' => $iconSourceUrl,
                'icon_url' => $categoryIconUrl,
                'apps' => $apps,
            ];
        }

        $portal->saveAnnouncements($announcements);
        $portal->saveSuggestedApps($categories);

        return redirect()
            ->route('admin.automation.portal')
            ->with('success', __('automation.portal_saved'));
    }
}
