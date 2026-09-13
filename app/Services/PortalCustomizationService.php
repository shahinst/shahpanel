<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Str;

class PortalCustomizationService
{
    public const KEY_ANNOUNCEMENTS = 'portal.announcements';

    public const KEY_SUGGESTED_APPS = 'portal.suggested_apps';

    /**
     * @return list<array{id: string, html: string, enabled: bool, sort: int}>
     */
    public function announcements(): array
    {
        $rows = $this->decode(self::KEY_ANNOUNCEMENTS);

        return collect($rows)
            ->filter(fn (array $row): bool => ($row['enabled'] ?? true) && trim(strip_tags((string) ($row['html'] ?? ''))) !== '')
            ->sortBy(fn (array $row): int => (int) ($row['sort'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string, sort: int, apps: list<array{id: string, name: string, url: string, icon_url: string}>}>
     */
    public function suggestedAppCategories(): array
    {
        $rows = $this->decode(self::KEY_SUGGESTED_APPS);

        return collect($rows)
            ->filter(fn (array $row): bool => trim((string) ($row['name'] ?? '')) !== '')
            ->sortBy(fn (array $row): int => (int) ($row['sort'] ?? 0))
            ->map(function (array $row): array {
                $apps = collect($row['apps'] ?? [])
                    ->filter(fn (array $app): bool => trim((string) ($app['name'] ?? '')) !== '' && trim((string) ($app['url'] ?? '')) !== '')
                    ->map(fn (array $app): array => $this->normalizeAppRow($app))
                    ->values()
                    ->all();

                return [
                    'id' => (string) ($row['id'] ?? Str::uuid()),
                    'name' => (string) $row['name'],
                    'sort' => (int) ($row['sort'] ?? 0),
                    'icon_url' => AppStoreIconService::displayUrlForCategory($row),
                    'apps' => $apps,
                ];
            })
            ->filter(fn (array $row): bool => $row['apps'] !== [])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rawAnnouncements(): array
    {
        return $this->decode(self::KEY_ANNOUNCEMENTS);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rawSuggestedApps(): array
    {
        return collect($this->decode(self::KEY_SUGGESTED_APPS))
            ->map(function (array $row): array {
                $row['icon_url'] = AppStoreIconService::displayUrlForCategory($row);
                $row['apps'] = collect($row['apps'] ?? [])
                    ->map(fn (array $app): array => $this->normalizeAppRow($app))
                    ->values()
                    ->all();

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function saveAnnouncements(array $rows): void
    {
        Setting::setValue(self::KEY_ANNOUNCEMENTS, json_encode(array_values($rows), JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function saveSuggestedApps(array $rows): void
    {
        Setting::setValue(self::KEY_SUGGESTED_APPS, json_encode(array_values($rows), JSON_UNESCAPED_UNICODE));
    }

    public function sanitizeAnnouncementHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $allowed = '<p><br><strong><b><em><i><u><span><a><div><ul><ol><li>';
        $clean = strip_tags($html, $allowed);

        return preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;
    }

    /**
     * @param  array<string, mixed>  $app
     * @return array<string, mixed>
     */
    protected function normalizeAppRow(array $app): array
    {
        if (! empty($app['icon_url'])) {
            $app['icon_url'] = AppStoreIconService::normalizePublicUrl((string) $app['icon_url']);
        }

        return $app;
    }

    /**
     * @param  array<string, mixed>  $category
     */
    protected function normalizeCategoryIconUrl(array $category): string
    {
        return AppStoreIconService::displayUrlForCategory($category);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function decode(string $key): array
    {
        $raw = Setting::getValue($key);

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
