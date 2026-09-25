<?php

namespace App\Services;

use App\Models\Setting;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;

class PortalCustomizationService
{
    public const KEY_ANNOUNCEMENTS = 'portal.announcements';

    public const KEY_SUGGESTED_APPS = 'portal.suggested_apps';

    /**
     * همان مجموعهٔ تگ‌هایی که پیش‌تر به strip_tags داده می‌شد — رفتار محتوای سالم
     * تغییر نمی‌کند، فقط این‌بار صفت‌ها هم پاک‌سازی می‌شوند.
     *
     * @var list<string>
     */
    protected const ANNOUNCEMENT_ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'span', 'a', 'div', 'ul', 'ol', 'li',
    ];

    /**
     * محتوای این تگ‌ها متنِ نمایشی نیست؛ «باز کردن» آن‌ها یعنی ریختن کد اسکریپت یا
     * CSS داخل صفحهٔ کاربر، پس کل گره حذف می‌شود.
     *
     * @var list<string>
     */
    protected const ANNOUNCEMENT_DROPPED_TAGS = [
        'script', 'style', 'template', 'noscript', 'svg', 'math', 'iframe',
        'frame', 'frameset', 'object', 'embed', 'applet', 'canvas', 'form',
        'input', 'button', 'select', 'option', 'textarea', 'link', 'meta',
        'base', 'title', 'head', 'param', 'source', 'track', 'audio', 'video',
    ];

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

        // ترکیب strip_tags و حذف on*= قابل دور زدن بود: صفت‌های تگ‌های مجاز
        // دست‌نخورده می‌ماندند (href="javascript:...") و الگو چون فاصلهٔ پیش از on
        // را لازم داشت، <div/onmouseover=...> را هم رد نمی‌کرد. این خروجی با
        // {!! !!} به همهٔ کاربران نشان داده می‌شود، پس سند را با DOM پیمایش
        // می‌کنیم و هر تگ/صفت غیرمجاز را دور می‌ریزیم.
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>'.$html.'</body>',
            LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return '';
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->sanitizeAnnouncementNode($body);

        $clean = '';

        foreach (iterator_to_array($body->childNodes, false) as $child) {
            $clean .= (string) $document->saveHTML($child);
        }

        return trim($clean);
    }

    /**
     * تگ‌های غیرمجاز را باز یا حذف می‌کند و همهٔ صفت‌ها را جز href سالم روی a دور
     * می‌ریزد — همین لایه جای الگوی قابلِ دورزدنِ قبلی را می‌گیرد.
     */
    protected function sanitizeAnnouncementNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes, false) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                // کامنت و processing instruction چیزی برای نمایش ندارند و در
                // مرورگرهای قدیمی می‌توانند حامل بار مخرب باشند.
                $node->removeChild($child);

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::ANNOUNCEMENT_DROPPED_TAGS, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ANNOUNCEMENT_ALLOWED_TAGS, true)) {
                // تگ ناشناخته را باز می‌کنیم تا متن مدیر از دست نرود، ولی خود تگ
                // و صفت‌هایش (که می‌توانند رویداد باشند) باقی نمی‌مانند.
                $this->sanitizeAnnouncementNode($child);

                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes, false) as $attribute) {
                if ($tag === 'a'
                    && strtolower($attribute->nodeName) === 'href'
                    && $this->isSafeAnnouncementHref((string) $attribute->nodeValue)) {
                    continue;
                }

                $child->removeAttribute($attribute->nodeName);
            }

            $this->sanitizeAnnouncementNode($child);
        }
    }

    /**
     * فقط http(s)، mailto و مسیر داخلی پذیرفته می‌شود؛ javascript: و data: باید رد
     * شوند. کاراکترهای کنترلی/فاصله اول حذف می‌شوند چون مرورگر هم آن‌ها را نادیده
     * می‌گیرد و «java\tscript:» را اجرا می‌کند.
     */
    protected function isSafeAnnouncementHref(string $href): bool
    {
        $href = preg_replace('/[\x00-\x20\x7F]/', '', $href) ?? '';

        return preg_match('#^(?:https?://|mailto:|/(?!/))#i', $href) === 1;
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
