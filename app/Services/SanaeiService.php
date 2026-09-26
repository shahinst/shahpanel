<?php

namespace App\Services;

use App\Concerns\RetriesApiCalls;
use App\Enums\ServiceType;
use App\Exceptions\RemoteConnectionException;
use App\Exceptions\RemoteProvisionException;
use App\Models\Server;
use App\Models\ServerInterface;
use App\Services\Sanaei\SanaeiPanelClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SanaeiService
{
    use RetriesApiCalls;

    /** @var array<int, SanaeiPanelClient> */
    protected array $clients = [];

    /** @var array<int, array<string, mixed>> */
    protected array $panelSettingsCache = [];

    /** @var array<string, list<string>> */
    protected array $subscriptionLinksCache = [];

    public function login(Server $server): string
    {
        $client = $this->client($server);
        $client->authenticate();

        if ($server->api_token_enc) {
            return $server->api_token_enc;
        }

        return 'session';
    }

    public function testConnection(Server $server): bool
    {
        return $this->testConnectionDetails($server)['ok'];
    }

    /**
     * @return array{ok: bool, message: string, inbound_count?: int, error?: string, panel_url?: string, api_prefix?: string, tried_urls?: list<string>, debug?: list<array<string, mixed>>}
     */
    public function testConnectionDetails(Server $server): array
    {
        try {
            return $this->client($server)->testConnection();
        } catch (Throwable $exception) {
            Log::channel('sanaei')->warning('Sanaei connection test failed', [
                'server_id' => $server->id,
                'host' => $server->host,
                'port' => $server->port,
                // web_base_path تنها ابزار مخفی‌سازیِ پنل 3x-ui است؛ نوشتن مقدارش
                // در لاگ همان چیزی را لو می‌دهد که SanaeiPanelClient عمداً پنهان
                // می‌کند.
                'has_web_base_path' => filled($server->web_base_path),
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => __('services.sanaei_connect_failed'),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getServerStatus(Server $server): array
    {
        $client = $this->client($server);
        $prefix = $client->resolveApiPrefix();
        $paths = ['/server/status', '/server/getStatus'];
        $lastError = 'وضعیت سرور Sanaei دریافت نشد.';

        foreach ($paths as $path) {
            try {
                $response = $this->apiRequest($server, 'GET', $prefix, $path);

                if (! $response->successful()) {
                    $lastError = 'HTTP '.$response->status().' برای '.$prefix.$path;

                    continue;
                }

                $payload = $response->json('obj') ?? $response->json();

                if (is_array($payload) && (isset($payload['cpu']) || isset($payload['mem']) || isset($payload['disk']))) {
                    return $payload;
                }

                $lastError = 'پاسخ نامعتبر از '.$prefix.$path;
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }
        }

        throw new RemoteConnectionException($lastError);
    }

    /**
     * Short-timeout status read for the admin dashboard monitor (no retry wrapper).
     *
     * @return array<string, mixed>
     */
    public function getServerStatusForMonitor(Server $server): array
    {
        $timeout = max(1, (int) config('shahpanel.server_monitor.sanaei_timeout_seconds', 5));
        $client = $this->client($server);
        $prefix = $client->resolveApiPrefix();
        $paths = ['/server/status', '/server/getStatus'];
        $lastError = 'وضعیت سرور Sanaei دریافت نشد.';

        foreach ($paths as $path) {
            try {
                $response = $client->apiRequest('GET', $prefix, $path, timeoutSeconds: $timeout);

                if (! $response->successful()) {
                    $lastError = 'HTTP '.$response->status().' برای '.$prefix.$path;

                    continue;
                }

                $payload = $response->json('obj') ?? $response->json();

                if (is_array($payload) && (isset($payload['cpu']) || isset($payload['mem']) || isset($payload['disk']))) {
                    return $payload;
                }

                $lastError = 'پاسخ نامعتبر از '.$prefix.$path;
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }
        }

        throw new RemoteConnectionException($lastError);
    }

    /**
     * Inbound IDs attached when provisioning a global panel client (subscribe uses all attached inbounds).
     *
     * @return list<int>
     */
    public function listProvisionInboundIds(Server $server): array
    {
        $ids = [];

        foreach ($this->listInbounds($server) as $inbound) {
            $id = (int) ($inbound['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            if (array_key_exists('enable', $inbound) && ! $inbound['enable']) {
                continue;
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    public function listInbounds(Server $server): array
    {
        $prefix = $this->client($server)->resolveApiPrefix();
        $response = $this->apiRequest($server, 'GET', $prefix, '/inbounds/list');
        $this->assertSuccessful($response, 'list Sanaei inbounds');

        $inbounds = $response->json('obj') ?? $response->json() ?? [];

        return is_array($inbounds) ? $inbounds : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInbound(Server $server, int $inboundId): ?array
    {
        $prefix = $this->client($server)->resolveApiPrefix();

        foreach (["/inbounds/get/{$inboundId}", "/inbounds/{$inboundId}"] as $path) {
            try {
                $response = $this->apiRequest($server, 'GET', $prefix, $path);
                if (! $response->successful()) {
                    continue;
                }

                $inbound = $response->json('obj') ?? $response->json();
                if (is_array($inbound) && (int) ($inbound['id'] ?? 0) === $inboundId) {
                    return $inbound;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        foreach ($this->listInbounds($server) as $inbound) {
            if ((int) ($inbound['id'] ?? 0) === $inboundId) {
                return $inbound;
            }
        }

        return null;
    }

    /**
     * @return list<array{
     *     uuid: string,
     *     email: string,
     *     enable: bool,
     *     data_limit_bytes: ?int,
     *     data_used_bytes: int,
     *     data_remaining_bytes: ?int,
     *     expiry_at: ?\DateTimeInterface,
     *     sub_id: ?string,
     *     raw: array<string, mixed>
     * }>
     */
    public function listClientsFromInbound(Server $server, int $inboundId): array
    {
        $inbound = $this->getInbound($server, $inboundId);

        if ($inbound === null) {
            throw new RemoteConnectionException(__('services.sanaei_inbound_not_found', ['id' => $inboundId]));
        }

        $settings = $this->decodeInboundJson($inbound, 'settings');
        $clients = $settings['clients'] ?? [];
        if (! is_array($clients)) {
            $clients = [];
        }
        $statsByEmail = $this->indexClientStats($inbound);

        $result = [];

        foreach ($clients as $client) {
            $email = scalar_string($client['email'] ?? '');
            $uuid = scalar_string($client['id'] ?? '');

            if ($email === '' || $uuid === '') {
                continue;
            }

            $traffic = $statsByEmail[$email] ?? null;

            if ($traffic === null) {
                $traffic = $this->getClientTraffics($server, $email);
            }

            $snapshot = $traffic !== null
                ? $this->normalizeTrafficSnapshot($traffic)
                : $this->normalizeTrafficSnapshot([
                    'up' => (int) ($client['up'] ?? 0),
                    'down' => (int) ($client['down'] ?? 0),
                    'total' => 0,
                ]);

            $usedBytes = (int) ($snapshot['used_bytes'] ?? ($snapshot['up'] + $snapshot['down']));
            $limitBytes = $this->clientLimitBytes($client);

            if ($limitBytes === null && ! empty($snapshot['limit_bytes'])) {
                $limitBytes = (int) $snapshot['limit_bytes'];
            }

            $remaining = $limitBytes !== null ? max(0, $limitBytes - $usedBytes) : null;

            $result[] = [
                'uuid' => $uuid,
                'email' => $email,
                'enable' => (bool) ($client['enable'] ?? true),
                'data_limit_bytes' => $limitBytes,
                'data_used_bytes' => $usedBytes,
                'data_remaining_bytes' => $remaining,
                'expiry_at' => $this->clientExpiryAt($client),
                'sub_id' => isset($client['subId']) ? scalar_string($client['subId']) : null,
                'raw' => $client,
            ];
        }

        return $result;
    }

    public function serviceTypeFromProtocol(string $protocol): ServiceType
    {
        return match (strtolower(trim($protocol))) {
            'vmess' => ServiceType::SanaeiVmess,
            'vless' => ServiceType::SanaeiVless,
            'trojan' => ServiceType::SanaeiTrojan,
            default => ServiceType::SanaeiVless,
        };
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @return array<string, array<string, mixed>>
     */
    protected function indexClientStats(array $inbound): array
    {
        $indexed = [];
        $candidates = [
            $inbound['clientStats'] ?? null,
            $inbound['clientTraffic'] ?? null,
            $inbound['clients'] ?? null,
        ];

        foreach ($candidates as $list) {
            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $email = scalar_string($row['email'] ?? $row['Email'] ?? '');
                if ($email !== '') {
                    $indexed[$email] = $row;
                }
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $client
     */
    protected function clientLimitBytes(array $client): ?int
    {
        $total = (int) ($client['totalGB'] ?? $client['total'] ?? 0);

        if ($total <= 0) {
            return null;
        }

        // 3x-ui stores bytes in totalGB field for existing clients.
        if ($total > 1024 * 1024) {
            return $total;
        }

        return (int) round($total * 1024 * 1024 * 1024);
    }

    /**
     * @param  array<string, mixed>  $client
     */
    protected function clientExpiryAt(array $client): ?\DateTimeInterface
    {
        $expiryMs = (int) ($client['expiryTime'] ?? 0);

        if ($expiryMs <= 0) {
            return null;
        }

        return (new \DateTimeImmutable)->setTimestamp((int) floor($expiryMs / 1000));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClientByEmail(Server $server, string $email): ?array
    {
        $global = $this->getGlobalClient($server, $email);

        if ($global !== null) {
            return $global;
        }

        return $this->findClientByEmailInInbounds($server, $email);
    }

    /**
     * @return array{client: array<string, mixed>, inbound_ids: list<int>}|null
     */
    public function getGlobalClient(Server $server, string $email): ?array
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        $prefix = $this->client($server)->resolveApiPrefix();
        $encoded = rawurlencode($email);

        foreach (["/clients/get/{$encoded}", "/clients/get/{$email}"] as $path) {
            try {
                $response = $this->apiRequest($server, 'GET', $prefix, $path);

                if ($response->status() === 404) {
                    continue;
                }

                if (! $response->successful()) {
                    continue;
                }

                // A missing client is NOT a 404 here: 3x-ui answers 200 with
                // {"success":false,"msg":"...","obj":null}. Without this guard the
                // "obj ?? whole body" fallback below hands back the error envelope
                // itself, so a deleted client still looks like it exists.
                if ($response->json('success') === false) {
                    continue;
                }

                $body = $response->json();
                $obj = $response->json('obj');

                // Older builds answer with the bare client and no envelope at all,
                // so keep that fallback — but only when there is genuinely no
                // envelope, never when the envelope simply carried a null obj.
                if (! is_array($obj) && is_array($body) && ! array_key_exists('success', $body)) {
                    $obj = $body;
                }

                if (! is_array($obj)) {
                    continue;
                }

                $client = $obj['client'] ?? $obj;

                if (! is_array($client)) {
                    continue;
                }

                $inboundIds = $obj['inboundIds'] ?? $obj['inbound_ids'] ?? [];

                return [
                    'client' => $this->normalizeInboundClient($client),
                    'inbound_ids' => is_array($inboundIds)
                        ? array_values(array_filter(array_map('intval', $inboundIds)))
                        : [],
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return array{client: array<string, mixed>, inbound_id: int}|null
     */
    protected function findClientByEmailInInbounds(Server $server, string $email): ?array
    {
        foreach ($this->listInbounds($server) as $inbound) {
            $inboundId = (int) ($inbound['id'] ?? 0);
            $settings = $this->decodeInboundJson($inbound, 'settings');
            $clients = $settings['clients'] ?? [];
            if (! is_array($clients)) {
                $clients = [];
            }

            foreach ($clients as $client) {
                if (($client['email'] ?? null) === $email) {
                    return [
                        'inbound_id' => $inboundId,
                        'client' => $this->normalizeInboundClient($client),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * ایمیل کلاینت پنل برای یک inbound مشخص.
     *
     * چرا: 3x-ui ایمیل کلاینت را در کل پنل یکتا می‌داند، پس وقتی یک اکانت روی
     * چند inbound ساخته می‌شود نمی‌توان یک ایمیل را تکرار کرد (همان خطای
     * «Duplicate email»). inbound اصلی ایمیل پایه را دست‌نخورده نگه می‌دارد تا
     * هر جست‌وجوی موجود بر اساس accounts.client_email همچنان جواب بدهد و
     * inboundهای بعدی پسوند «-i{id}» می‌گیرند که ذاتاً یکتاست.
     */
    public static function inboundClientEmail(string $baseEmail, int $inboundId, int $primaryInboundId): string
    {
        $baseEmail = trim($baseEmail);

        if ($inboundId <= 0 || $inboundId === $primaryInboundId) {
            return $baseEmail;
        }

        return $baseEmail.'-i'.$inboundId;
    }

    /**
     * inboundهای انتخاب‌شده روی پکیج را به inboundهای واقعاً فعال پنل تبدیل می‌کند.
     *
     * چرا: انتخاب ادمین روی پکیج ذخیره می‌شود ولی ممکن است بعداً روی پنل حذف یا
     * غیرفعال شود؛ خالی بودن انتخاب هم یعنی «همه inboundهای فعال» تا پکیج‌های
     * قدیمی که چیزی انتخاب نکرده‌اند مثل قبل رفتار کنند.
     *
     * @param  list<int>|null  $requestedIds
     * @return list<int>
     */
    public function resolveProvisionInboundIds(Server $server, ?array $requestedIds = null): array
    {
        $available = $this->listProvisionInboundIds($server);

        if ($available === []) {
            throw new RemoteProvisionException(__('services.sanaei_no_active_inbound'));
        }

        $requested = [];

        foreach ($requestedIds ?? [] as $id) {
            $id = (int) $id;

            if ($id > 0 && ! in_array($id, $requested, true)) {
                $requested[] = $id;
            }
        }

        if ($requested === []) {
            return $available;
        }

        $selected = array_values(array_filter(
            $requested,
            static fn (int $id): bool => in_array($id, $available, true)
        ));

        if ($selected === []) {
            throw new RemoteProvisionException(
                __('services.sanaei_package_inbounds_unavailable', ['ids' => implode(', ', $requested)])
            );
        }

        return $selected;
    }

    /**
     * ساخت کلاینت روی inboundهای هدف — به ازای هر inbound یک کلاینت با ایمیل یکتای خودش.
     *
     * چرا مسیر /clients/add حذف شد: 3x-ui اصلاً API کلاینت سراسری ندارد و آن
     * درخواست همیشه 404 می‌شد؛ یک رفت‌وبرگشت بی‌فایده روی هر ساخت.
     *
     * @param  list<int>|null  $inboundIds  inboundهای پکیج؛ null/خالی یعنی همه inboundهای فعال
     * @return array<string, mixed>
     */
    public function createClient(
        Server $server,
        string $email,
        string $uuid,
        int $limitIp = 0,
        ?float $totalGB = null,
        ?int $expiryTime = null,
        int $up = 0,
        int $down = 0,
        ?string $subId = null,
        ?array $inboundIds = null,
    ): array {
        $targets = $this->resolveProvisionInboundIds($server, $inboundIds);
        $primaryInboundId = $targets[0];

        // UUID و subId روی همه inboundها یکسان می‌ماند: subId مشترک باعث می‌شود
        // لینک subscription همه inboundها را یکجا تحویل کاربر بدهد.
        $base = $this->normalizeClientPayload([
            'id' => $uuid,
            'email' => $email,
            'limitIp' => $limitIp,
            'totalGB' => $this->gbToBytes($totalGB),
            'expiryTime' => $expiryTime ?? 0,
            'enable' => true,
            'tgId' => 0,
            'subId' => $subId !== null && $subId !== '' ? $subId : Str::random(16),
            'flow' => '',
            'up' => max(0, $up),
            'down' => max(0, $down),
        ]);

        $created = 0;
        $failures = [];

        foreach ($targets as $inboundId) {
            $client = $base;
            $client['email'] = self::inboundClientEmail($email, $inboundId, $primaryInboundId);

            // چرا: شمارنده مصرف فقط روی کلاینت اصلی نوشته می‌شود؛ تکرار آن روی
            // هر inbound باعث می‌شد جمعِ ترافیک، مصرف کاربر را چند برابر نشان دهد.
            if ($inboundId !== $primaryInboundId) {
                $client['up'] = 0;
                $client['down'] = 0;
            }

            try {
                $this->createClientOnInbound($server, $inboundId, $client);
                $created++;
            } catch (Throwable $exception) {
                $failures[$inboundId] = $exception->getMessage();
            }
        }

        if ($created === 0) {
            throw new RemoteProvisionException(
                $failures !== []
                    ? implode(' | ', $failures)
                    : __('services.sanaei_client_not_registered')
            );
        }

        if ($failures !== []) {
            // چرا استثنا پرتاب نمی‌کنیم: اکانت روی پنل ساخته شده و گزارش «ناموفق»
            // فقط کاربر را گمراه می‌کند؛ کمبود لاگ می‌شود تا «همگام‌سازی» جبرانش کند.
            Log::channel('sanaei')->error('Sanaei client only partially provisioned', [
                'server_id' => $server->id,
                'email' => $email,
                'created_inbounds' => $created,
                'failed_inbounds' => $failures,
            ]);
        }

        return $this->normalizeInboundClient($base);
    }

    /**
     * ثبت یک کلاینت روی یک inbound مشخص.
     *
     * @param  array<string, mixed>  $client
     */
    protected function createClientOnInbound(Server $server, int $inboundId, array $client): void
    {
        $response = $this->client($server)->postAddInboundClient([
            'id' => $inboundId,
            'settings' => json_encode(['clients' => [$client]], JSON_THROW_ON_ERROR),
        ]);

        if ($this->panelMutationSucceeded($response)) {
            return;
        }

        // چرا: پاسخ «Duplicate email» یعنی دقیقاً همان کلاینتی که می‌خواستیم بسازیم
        // از قبل روی پنل هست. پرتاب خطا باعث می‌شد اکانتی که واقعاً ساخته شده
        // «ناموفق» گزارش شود؛ پس آن را به‌عنوان موجود می‌پذیریم و فقط هشدار می‌دهیم.
        if ($this->responseReportsDuplicate($response)) {
            Log::channel('sanaei')->warning('Sanaei client already existed on inbound, adopted', [
                'server_id' => $server->id,
                'inbound_id' => $inboundId,
                'email' => scalar_string($client['email'] ?? ''),
                'panel_message' => panel_api_message($response->json('msg') ?? null, ''),
            ]);

            return;
        }

        if (in_array($response->status(), [404, 405], true)) {
            $this->createClientViaInboundUpdate($server, $inboundId, $client);

            return;
        }

        $this->assertSuccessful($response, 'create Sanaei client', $server);
    }

    /**
     * آیا پنل پاسخ «تکراری بودن» داده است؟
     */
    protected function responseReportsDuplicate(\Illuminate\Http\Client\Response $response): bool
    {
        $json = $response->json();

        if (! is_array($json)) {
            return false;
        }

        $message = mb_strtolower(panel_api_message(
            $json['msg'] ?? $json['message'] ?? $json['error'] ?? $json['obj'] ?? null,
            ''
        ));

        return str_contains($message, 'duplicate')
            || str_contains($message, 'already exist')
            || str_contains($message, 'تکراری');
    }

    /**
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    protected function mapClientToGlobalApi(array $client): array
    {
        return [
            'id' => scalar_string($client['id'] ?? ''),
            'email' => scalar_string($client['email'] ?? ''),
            'limitIp' => scalar_int($client['limitIp'] ?? 0),
            'totalGB' => scalar_int($client['totalGB'] ?? 0),
            'expiryTime' => scalar_int($client['expiryTime'] ?? 0),
            'enable' => scalar_bool($client['enable'] ?? true),
            'tgId' => scalar_int($client['tgId'] ?? 0),
            'subId' => scalar_string($client['subId'] ?? ''),
            'flow' => scalar_string($client['flow'] ?? ''),
            'comment' => scalar_string($client['comment'] ?? ''),
            'reset' => scalar_int($client['reset'] ?? 0),
            'up' => scalar_int($client['up'] ?? 0),
            'down' => scalar_int($client['down'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $client
     */
    protected function createClientViaInboundUpdate(Server $server, int $inboundId, array $client): void
    {
        $inbound = $this->getInbound($server, $inboundId);

        if ($inbound === null) {
            throw new RemoteProvisionException(__('services.sanaei_inbound_not_found_panel', ['id' => $inboundId]));
        }

        $settings = $this->decodeInboundJson($inbound, 'settings');
        $clients = $settings['clients'] ?? [];

        if (! is_array($clients)) {
            $clients = [];
        }

        foreach ($clients as $row) {
            if (is_array($row) && ($row['email'] ?? null) === ($client['email'] ?? null)) {
                return;
            }
        }

        $clients[] = $client;
        $settings['clients'] = $clients;

        $body = $this->prepareInboundUpdateBody($inbound, $settings);
        $response = $this->client($server)->postPathAttempts(['/inbounds/update/'.$inboundId], $body);

        $this->assertSuccessful($response, 'create Sanaei client', $server);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function prepareInboundUpdateBody(array $inbound, array $settings): array
    {
        $body = $inbound;
        unset($body['clientStats'], $body['clientTraffic'], $body['clients']);

        $body['settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (['streamSettings', 'sniffing'] as $jsonField) {
            if (isset($body[$jsonField]) && is_array($body[$jsonField])) {
                $body[$jsonField] = json_encode($body[$jsonField], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        }

        return $body;
    }

    protected function panelMutationSucceeded(\Illuminate\Http\Client\Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $json = $response->json();

        // A 200 that is not the panel's {success|obj} envelope (an HTML login
        // page, a proxy interstitial) is NOT a successful mutation.
        if (! is_array($json) || ! (array_key_exists('success', $json) || array_key_exists('obj', $json))) {
            return false;
        }

        return ($json['success'] ?? true) !== false;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function updateClient(
        Server $server,
        string $email,
        string $uuid,
        array $changes,
        ?int $legacyInboundId = null
    ): array {
        $existing = $this->resolvePanelClient($server, $email, $uuid, $legacyInboundId);

        if ($existing === null) {
            throw new RemoteProvisionException(__('services.sanaei_client_not_found', ['email' => $email]));
        }

        $client = $this->buildClientPayloadForApi(array_merge($existing, $changes), $uuid, $changes);
        $resolvedEmail = scalar_string($client['email'] ?? $email);
        $inboundId = $legacyInboundId ?? (int) ($this->findClientByEmailInInbounds($server, $resolvedEmail)['inbound_id'] ?? 0);
        $modernPath = '/clients/update/'.rawurlencode($resolvedEmail !== '' ? $resolvedEmail : $uuid);
        $modernPayload = $this->mapClientToGlobalApi($client);

        // The client-update contract changed across 3x-ui releases, and a wrong
        // shape does NOT return 404 — the panel answers 200 with success:false,
        // so status-only fallbacks silently "succeed" while nothing changes.
        // Try every known variant and keep the first that really applied.
        $attempts = [
            // 3x-ui >= ~3.5: /clients/update/{email} binds straight to a Client,
            // so the body must be the bare client object.
            [$modernPath, $modernPayload['client'] ?? $modernPayload],
            // Intermediate releases exposed the same route with an envelope.
            [$modernPath, $modernPayload],
        ];

        if ($inboundId > 0) {
            // Classic 3.0.x route: inbound id + serialised settings blob.
            $attempts[] = [
                '/inbounds/updateClient/'.$uuid,
                [
                    'id' => $inboundId,
                    'settings' => json_encode(['clients' => [$client]], JSON_THROW_ON_ERROR),
                ],
            ];
        }

        $response = null;

        foreach ($attempts as [$path, $payload]) {
            $response = $this->client($server)->postPathAttempts([$path], $payload);

            if ($this->panelMutationSucceeded($response)) {
                return $client;
            }
        }

        // Nothing worked — surface the last panel response as the error.
        $this->assertSuccessful($response, 'update Sanaei client');

        throw new RemoteProvisionException(
            __('services.sanaei_client_update_rejected', [
                'email' => $resolvedEmail,
                'reason' => scalar_string($response?->json('msg') ?? __('services.unknown_response')),
            ])
        );
    }

    public function deleteClient(Server $server, string $email, string $uuid, ?int $legacyInboundId = null): void
    {
        $existing = $this->resolvePanelClient($server, $email, $uuid, $legacyInboundId);
        $resolvedEmail = $existing !== null
            ? scalar_string($existing['email'] ?? $email)
            : $email;
        $inboundId = $legacyInboundId ?? (int) ($this->findClientByEmailInInbounds($server, $resolvedEmail)['inbound_id'] ?? 0);
        $modernPath = '/clients/del/'.rawurlencode($resolvedEmail !== '' ? $resolvedEmail : $uuid);

        // Same trap as updateClient: a wrong route shape does not 404, the panel
        // answers 200 with success:false, so a status-only check reports a delete
        // that never happened. Try every known route and require a real success.
        $paths = [$modernPath];

        if ($inboundId > 0) {
            // Classic 3.0.x route, kept for panels that predate /clients/del.
            $paths[] = "/inbounds/{$inboundId}/delClient/{$uuid}";
        }

        $response = null;

        foreach ($paths as $path) {
            $response = $this->client($server)->postPathAttempts([$path], []);

            if ($response->status() === 404) {
                continue;
            }

            if ($this->panelMutationSucceeded($response)) {
                return;
            }
        }

        // Deleting something that is already gone is the desired end state, not a
        // failure — every route reporting "not found" means the work is done.
        if ($this->resolvePanelClient($server, $resolvedEmail, $uuid, $legacyInboundId) === null) {
            return;
        }

        // 3x-ui 3.0.x refuses to delete an inbound's LAST client ("no client
        // remained in Inbound"), which would strand every single-client inbound.
        // Rewriting the inbound without that client achieves the same end state.
        if ($inboundId > 0 && $this->removeClientViaInboundUpdate($server, $inboundId, $resolvedEmail, $uuid)) {
            return;
        }

        if ($response === null || $response->status() === 404) {
            return;
        }

        $this->assertSuccessful($response, 'delete Sanaei client');

        throw new RemoteProvisionException(
            'Sanaei panel refused to delete client "'.$resolvedEmail.'": '.
            scalar_string($response->json('msg') ?? $response->body())
        );
    }

    /**
     * Drop a client by rewriting its inbound's client list.
     *
     * Fallback for panels whose delClient endpoint rejects the request; used only
     * after the dedicated delete routes have already failed.
     */
    protected function removeClientViaInboundUpdate(Server $server, int $inboundId, string $email, string $uuid): bool
    {
        $inbound = $this->getInbound($server, $inboundId);

        if ($inbound === null) {
            return false;
        }

        $settings = $this->decodeInboundJson($inbound, 'settings');
        $clients = $settings['clients'] ?? [];

        if (! is_array($clients)) {
            return false;
        }

        $remaining = array_values(array_filter($clients, static function ($client) use ($email, $uuid): bool {
            if (! is_array($client)) {
                return true;
            }

            return ($client['email'] ?? null) !== $email && ($client['id'] ?? null) !== $uuid;
        }));

        if (count($remaining) === count($clients)) {
            return false;
        }

        $settings['clients'] = $remaining;

        $payload = $inbound;
        $payload['settings'] = json_encode($settings, JSON_THROW_ON_ERROR);
        // Traffic counters are read-only server state; echoing them back is rejected.
        unset($payload['clientStats']);

        foreach (['streamSettings', 'sniffing', 'allocate'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = json_encode($payload[$key], JSON_THROW_ON_ERROR);
            }
        }

        try {
            $response = $this->client($server)->postPathAttempts(['/inbounds/update/'.$inboundId], $payload);
        } catch (Throwable) {
            return false;
        }

        return $this->panelMutationSucceeded($response);
    }

    public function disableClient(Server $server, string $email, string $uuid, ?int $legacyInboundId = null): void
    {
        $this->updateClient($server, $email, $uuid, ['enable' => false], $legacyInboundId);
    }

    public function enableClient(Server $server, string $email, string $uuid, ?int $legacyInboundId = null): void
    {
        $this->updateClient($server, $email, $uuid, ['enable' => true], $legacyInboundId);
    }

    /**
     * همه کلاینت‌های پنل که به یک اکانت تعلق دارند — یکی به ازای هر inbound.
     *
     * چرا از خود پنل می‌خوانیم و از روی پکیج بازسازی نمی‌کنیم: ادمین ممکن است
     * بعد از ساخت اکانت، inboundهای پکیج را عوض کند و آن‌وقت حذف/غیرفعال‌سازی
     * کلاینت‌های جامانده را نمی‌دید. UUID در همه کلاینت‌های یک اکانت یکسان است،
     * پس یک بار خواندن فهرست inboundها برای پیدا کردن همه‌شان کافی است.
     *
     * @return list<array{inbound_id: int, email: string}>
     */
    public function findAccountClients(Server $server, string $uuid, string $baseEmail): array
    {
        $uuid = trim($uuid);
        $baseEmail = trim($baseEmail);
        $aliasPrefix = $baseEmail.'-i';
        $matches = [];

        foreach ($this->listInbounds($server) as $inbound) {
            $inboundId = (int) ($inbound['id'] ?? 0);

            if ($inboundId <= 0 || isset($matches[$inboundId])) {
                continue;
            }

            $settings = $this->decodeInboundJson($inbound, 'settings');
            $clients = is_array($settings['clients'] ?? null) ? $settings['clients'] : [];

            foreach ($clients as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $email = trim(scalar_string($row['email'] ?? ''));

                $matchesUuid = $this->clientIdsMatch(trim(scalar_string($row['id'] ?? '')), $uuid);

                // ctype_digit جلوی ایمیل‌هایی را می‌گیرد که تصادفاً با همین
                // پیشوند شروع می‌شوند ولی پسوندشان شماره inbound ما نیست.
                $matchesEmail = $baseEmail !== '' && (
                    $email === $baseEmail
                    || (str_starts_with($email, $aliasPrefix) && ctype_digit(substr($email, strlen($aliasPrefix))))
                );

                if (! $matchesUuid && ! $matchesEmail) {
                    continue;
                }

                $matches[$inboundId] = ['inbound_id' => $inboundId, 'email' => $email];

                break;
            }
        }

        return array_values($matches);
    }

    /**
     * به‌روزرسانی همه کلاینت‌های یک اکانت روی هر inboundی که روی آن ساخته شده است.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateAccountClients(
        Server $server,
        string $baseEmail,
        string $uuid,
        array $changes,
        ?int $primaryInboundId = null
    ): void {
        $clients = $this->findAccountClients($server, $uuid, $baseEmail);

        if ($clients === []) {
            throw new RemoteProvisionException(__('services.sanaei_client_not_found', ['email' => $baseEmail]));
        }

        $primaryInboundId = $primaryInboundId !== null && $primaryInboundId > 0
            ? $primaryInboundId
            : $clients[0]['inbound_id'];

        $failures = [];

        foreach ($clients as $entry) {
            $payload = $changes;

            // چرا: شمارنده مصرف فقط روی کلاینت اصلی نوشته می‌شود؛ تکرار آن روی
            // هر inbound باعث می‌شد جمعِ ترافیک، مصرف کاربر را چند برابر نشان دهد.
            if ($entry['inbound_id'] !== $primaryInboundId) {
                if (array_key_exists('up', $payload)) {
                    $payload['up'] = 0;
                }

                if (array_key_exists('down', $payload)) {
                    $payload['down'] = 0;
                }
            }

            try {
                $this->updateClient($server, $entry['email'], $uuid, $payload, $entry['inbound_id']);
            } catch (Throwable $exception) {
                $failures[$entry['inbound_id']] = $exception->getMessage();
            }
        }

        $this->assertNoInboundFailures($server, $baseEmail, 'update', $failures, count($clients));
    }

    public function disableAccountClients(
        Server $server,
        string $baseEmail,
        string $uuid,
        ?int $primaryInboundId = null
    ): void {
        $this->updateAccountClients($server, $baseEmail, $uuid, ['enable' => false], $primaryInboundId);
    }

    public function enableAccountClients(
        Server $server,
        string $baseEmail,
        string $uuid,
        ?int $primaryInboundId = null
    ): void {
        $this->updateAccountClients($server, $baseEmail, $uuid, ['enable' => true], $primaryInboundId);
    }

    /**
     * حذف همه کلاینت‌های یک اکانت از همه inboundها.
     */
    public function deleteAccountClients(Server $server, string $baseEmail, string $uuid): void
    {
        $clients = $this->findAccountClients($server, $uuid, $baseEmail);

        // چرا خطا نمی‌دهیم: نبودن کلاینت یعنی همان وضعیت نهایی که می‌خواستیم.
        if ($clients === []) {
            return;
        }

        $failures = [];

        foreach ($clients as $entry) {
            try {
                $this->deleteClient($server, $entry['email'], $uuid, $entry['inbound_id']);
            } catch (Throwable $exception) {
                if ($this->findClientOnInbound($server, $entry['inbound_id'], $uuid) === null) {
                    continue;
                }

                $failures[$entry['inbound_id']] = $exception->getMessage();
            }
        }

        $this->assertNoInboundFailures($server, $baseEmail, 'delete', $failures, count($clients));
    }

    /**
     * چرا: اگر بخشی از کلاینت‌های یک اکانت تغییر کند و بخشی نه، اکانت نیمه‌کاره
     * می‌ماند؛ این حالت نباید بی‌صدا رد شود، پس هم لاگ می‌شود و هم به کاربر
     * گزارش می‌شود تا دوباره تلاش کند.
     *
     * @param  array<int, string>  $failures
     */
    protected function assertNoInboundFailures(
        Server $server,
        string $baseEmail,
        string $operation,
        array $failures,
        int $total
    ): void {
        if ($failures === []) {
            return;
        }

        Log::channel('sanaei')->error('Sanaei multi-inbound operation failed', [
            'server_id' => $server->id,
            'email' => $baseEmail,
            'operation' => $operation,
            'total_clients' => $total,
            'failed_inbounds' => $failures,
        ]);

        throw new RemoteProvisionException(
            __('services.sanaei_inbound_operation_failed', [
                'email' => $baseEmail,
                'inbounds' => implode(', ', array_keys($failures)),
                'reason' => (string) reset($failures),
            ])
        );
    }

    /**
     * جمع مصرف همه کلاینت‌های یک اکانت روی inboundهای مختلف.
     *
     * چرا: از وقتی هر inbound کلاینت جداگانه با ایمیل مخصوص خودش دارد، مصرف
     * کاربر بین چند کلاینت پنل پخش می‌شود؛ خواندن فقط ایمیل پایه سهمیه را
     * کمتر از واقع نشان می‌داد و کاربر عملاً بی‌حساب مصرف می‌کرد.
     *
     * @return array<string, mixed>|null
     */
    public function getAggregatedClientTraffics(Server $server, string $baseEmail): ?array
    {
        $baseEmail = trim($baseEmail);

        if ($baseEmail === '') {
            return null;
        }

        $primary = $this->getClientTraffics($server, $baseEmail);

        $aliasPrefix = $baseEmail.'-i';
        $extraUp = 0;
        $extraDown = 0;
        $foundAlias = false;

        foreach ($this->listInbounds($server) as $inbound) {
            foreach ($this->indexClientStats($inbound) as $email => $row) {
                if (! str_starts_with((string) $email, $aliasPrefix)) {
                    continue;
                }

                if (! ctype_digit(substr((string) $email, strlen($aliasPrefix)))) {
                    continue;
                }

                $foundAlias = true;
                $extraUp += max(0, (int) ($row['up'] ?? 0));
                $extraDown += max(0, (int) ($row['down'] ?? 0));
            }
        }

        if (! $foundAlias) {
            return $primary;
        }

        $merged = is_array($primary) ? $primary : [];
        $merged['email'] = $baseEmail;
        $merged['up'] = max(0, (int) ($merged['up'] ?? 0)) + $extraUp;
        $merged['down'] = max(0, (int) ($merged['down'] ?? 0)) + $extraDown;

        return $merged;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolvePanelClient(
        Server $server,
        string $email,
        string $uuid,
        ?int $legacyInboundId = null
    ): ?array {
        $global = $this->getGlobalClient($server, $email);

        if ($global !== null) {
            $client = $global['client'];

            if ($uuid === '' || $this->clientIdsMatch(scalar_string($client['id'] ?? ''), $uuid)) {
                return $client;
            }
        }

        if ($legacyInboundId !== null && $legacyInboundId > 0) {
            return $this->findClientOnInbound($server, $legacyInboundId, $uuid);
        }

        $found = $this->findClientByEmailInInbounds($server, $email);

        return $found['client'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getClientTraffics(Server $server, string $email): ?array
    {
        $prefix = $this->client($server)->resolveApiPrefix();
        $encoded = rawurlencode($email);

        $paths = [
            "/inbounds/getClientTraffics/{$encoded}",
            "/inbounds/getClientTraffics/{$email}",
            "/clients/traffic/{$encoded}",
        ];

        foreach ($paths as $path) {
            $response = $this->apiRequest($server, 'GET', $prefix, $path);

            if ($response->status() === 404) {
                continue;
            }

            $this->assertSuccessful($response, 'fetch Sanaei client traffic');

            $payload = $response->json();
            if (! is_array($payload)) {
                continue;
            }

            $obj = $payload['obj'] ?? $payload;
            if (is_array($obj)) {
                return $obj;
            }
        }

        return null;
    }

    public function buildSubscriptionLink(Server $server, string $subId): string
    {
        $settings = $this->getPanelSettings($server);
        $configuredSubUri = trim((string) ($settings['subURI'] ?? ''));

        if ($configuredSubUri === '') {
            $configuredSubUri = $this->resolveDefaultSubUri($server, $settings);
        }

        if ($configuredSubUri === '') {
            $url = $this->client($server)->url();

            return $this->alignSubscriptionUriScheme(
                $server,
                $this->joinSubscriptionPath($url->route('/sub'), $subId)
            );
        }

        return $this->alignSubscriptionUriScheme(
            $server,
            $this->joinSubscriptionPath($configuredSubUri, $subId)
        );
    }

    /**
     * Fetch exact config share links as returned by the 3x-ui subscription endpoint.
     *
     * @return list<string>
     */
    public function fetchSubscriptionConfigLinks(Server $server, string $subId): array
    {
        $cacheKey = $server->id.':'.$subId;

        if (isset($this->subscriptionLinksCache[$cacheKey])) {
            return $this->subscriptionLinksCache[$cacheKey];
        }

        $settings = $this->getPanelSettings($server);
        $encrypted = filter_var($settings['subEncrypt'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $candidates = $this->subscriptionFetchCandidates($server, $subId);

        foreach ($candidates as $subUrl) {
            $links = $this->attemptSubscriptionFetch($server, $subId, $subUrl, $settings, $encrypted);

            if ($links !== []) {
                return $this->subscriptionLinksCache[$cacheKey] = $links;
            }
        }

        Log::channel('sanaei')->warning('Subscription fetch returned no config links', [
            'server_id' => $server->id,
            'sub_id' => self::maskSubId($subId),
            'candidates' => array_map(
                static fn (string $url): string => SanaeiPanelClient::redactUrl($url),
                $candidates
            ),
        ]);

        return $this->subscriptionLinksCache[$cacheKey] = [];
    }

    /**
     * subId خودش توکنِ لینک اشتراک است؛ برای همبستگی سطرهای لاگ چند نویسهٔ اول
     * کافی است و نوشتن کاملش یعنی گذاشتن یک اعتبارنامهٔ زندهٔ کاربر در فایل لاگ.
     */
    protected static function maskSubId(string $subId): string
    {
        return $subId === '' ? '' : Str::limit($subId, 6, '…');
    }

    /**
     * @return list<string>
     */
    protected function subscriptionFetchCandidates(Server $server, string $subId): array
    {
        $settings = $this->getPanelSettings($server);
        $candidates = [];
        $primary = $this->buildSubscriptionLink($server, $subId);
        $candidates[] = $primary;

        foreach ([
            'subURI',
            'subJsonURI',
            'subClashURI',
        ] as $settingKey) {
            $uri = trim((string) ($settings[$settingKey] ?? ''));

            if ($uri === '') {
                continue;
            }

            $candidates[] = $this->alignSubscriptionUriScheme(
                $server,
                $this->joinSubscriptionPath($uri, $subId)
            );
        }

        try {
            $panelSub = $this->alignSubscriptionUriScheme(
                $server,
                $this->joinSubscriptionPath(
                    $this->client($server)->url()->route('/sub'),
                    $subId
                )
            );

            if ($panelSub !== $primary) {
                $candidates[] = $panelSub;
            }
        } catch (\Throwable) {
            // Panel URL unavailable — continue with configured subscription URI only.
        }

        foreach ($candidates as $candidate) {
            $flipped = $this->flipUrlScheme($candidate);

            if ($flipped !== null) {
                $candidates[] = $flipped;
            }
        }

        $unique = array_values(array_unique($candidates));

        return $this->sortSubscriptionCandidatesHttpsFirst($unique);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    protected function attemptSubscriptionFetch(
        Server $server,
        string $subId,
        string $subUrl,
        array $settings,
        bool $encrypted
    ): array {
        try {
            $response = Http::timeout(20)
                ->withoutVerifying()
                ->withHeaders($this->subscriptionFetchHeaders($subUrl, $settings))
                ->get($subUrl);

            if (! $response->successful()) {
                Log::channel('sanaei')->warning('Subscription fetch failed', [
                    'server_id' => $server->id,
                    'sub_id' => self::maskSubId($subId),
                    'status' => $response->status(),
                    'url' => SanaeiPanelClient::redactUrl($subUrl),
                ]);

                return [];
            }

            $body = $response->body();
            $links = $this->parseSubscriptionLinks($body, $encrypted);

            if ($links === []) {
                $links = $this->parseSubscriptionLinks($body, ! $encrypted);
            }

            if ($links === []) {
                $links = $this->fetchSubscriptionLinksAsBrowser($subUrl, $settings);
            }

            if ($links === []) {
                // body_prefix حذف شد: بدنهٔ اشتراک شامل لینک‌های vless/vmess با
                // UUID و رمز واقعی کاربر است و لاگ جای نگه‌داشتن آن نیست.
                Log::channel('sanaei')->warning('Subscription body had no parseable links', [
                    'server_id' => $server->id,
                    'sub_id' => self::maskSubId($subId),
                    'url' => SanaeiPanelClient::redactUrl($subUrl),
                    'body_length' => strlen($body),
                ]);
            }

            return $links;
        } catch (\Throwable $exception) {
            Log::channel('sanaei')->warning('Subscription fetch error', [
                'server_id' => $server->id,
                'sub_id' => self::maskSubId($subId),
                'url' => SanaeiPanelClient::redactUrl($subUrl),
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Latest 3x-ui may only embed vless/vmess links in the subscription HTML page.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    protected function fetchSubscriptionLinksAsBrowser(string $subUrl, array $settings): array
    {
        try {
            $headers = $this->subscriptionFetchHeaders($subUrl, $settings);
            unset($headers['Accept'], $headers['User-Agent']);
            $headers['Accept'] = 'text/html,application/xhtml+xml';
            $headers['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

            $response = Http::timeout(20)
                ->withoutVerifying()
                ->withHeaders($headers)
                ->get($subUrl.'?html=1');

            if (! $response->successful()) {
                return [];
            }

            return $this->parseSubscriptionLinks($response->body(), false);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    protected function subscriptionFetchHeaders(string $subUrl, array $settings): array
    {
        $headers = [
            'Accept' => 'text/plain, application/octet-stream;q=0.9, */*;q=0.1',
            'User-Agent' => 'v2rayNG/1.8.29',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        $subDomain = trim((string) ($settings['subDomain'] ?? ''));
        $parts = parse_url($subUrl);

        if ($subDomain !== '' && is_array($parts) && ! empty($parts['host'])) {
            $connectHost = (string) $parts['host'];

            if (filter_var($connectHost, FILTER_VALIDATE_IP)) {
                $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));
                $headers['Host'] = $this->formatHostWithPort($subDomain, $port);
            }
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    protected function parseSubscriptionLinks(string $body, bool $preferEncrypted): array
    {
        $body = trim($body);

        if ($body === '') {
            return [];
        }

        $links = $this->extractProtocolLinks($body);

        if ($links !== []) {
            return $links;
        }

        $links = $this->extractLinksFromSubPageHtml($body);

        if ($links !== []) {
            return $links;
        }

        foreach ($this->decodeSubscriptionBodies($body, $preferEncrypted) as $candidate) {
            $links = $this->extractProtocolLinks($candidate);

            if ($links !== []) {
                return $links;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    protected function decodeSubscriptionBodies(string $body, bool $preferEncrypted): array
    {
        $compact = preg_replace('/\s+/', '', $body) ?? $body;
        $candidates = [];

        if ($preferEncrypted || $this->looksLikeBase64Payload($compact)) {
            $decoded = base64_decode($compact, true);

            if ($decoded !== false && $decoded !== '') {
                $candidates[] = $decoded;
            }
        }

        if (! $preferEncrypted) {
            $decoded = base64_decode($compact, true);

            if ($decoded !== false && $decoded !== '' && ! in_array($decoded, $candidates, true)) {
                $candidates[] = $decoded;
            }
        }

        return $candidates;
    }

    /**
     * 3x-ui may return the subscription info SPA when Accept looks browser-like.
     *
     * @return list<string>
     */
    protected function extractLinksFromSubPageHtml(string $body): array
    {
        if (! str_contains($body, '__SUB_PAGE_DATA__')) {
            return [];
        }

        if (! preg_match('/window\.__SUB_PAGE_DATA__\s*=\s*(\{.*?\})\s*;/s', $body, $matches)) {
            return [];
        }

        $payload = json_decode($matches[1], true);

        if (! is_array($payload)) {
            return [];
        }

        $links = $payload['links'] ?? $payload['result'] ?? [];

        if (! is_array($links)) {
            return [];
        }

        return array_values(array_filter($links, fn ($link): bool => is_string($link)
            && preg_match('#^(vmess|vless|trojan|ss|wireguard)://#i', $link)));
    }

    protected function looksLikeBase64Payload(string $value): bool
    {
        if ($value === '' || preg_match('#^(vmess|vless|trojan|ss|wireguard)://#i', $value)) {
            return false;
        }

        return strlen($value) > 24 && (bool) preg_match('#^[A-Za-z0-9+/]+=*$#', $value);
    }

    /**
     * @return list<string>
     */
    protected function extractProtocolLinks(string $body): array
    {
        $links = [];

        foreach (preg_split('/\R+/', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && preg_match('#^(vmess|vless|trojan|ss|wireguard)://#i', $line)) {
                $links[] = $line;
            }
        }

        if ($links === [] && preg_match('#((?:vmess|vless|trojan|ss|wireguard)://[^\s<>"\']+)#i', $body, $matches)) {
            $links[] = $matches[1];
        }

        return $links;
    }

    protected function flipUrlScheme(string $url): ?string
    {
        if (str_starts_with($url, 'https://')) {
            return 'http://'.substr($url, 8);
        }

        if (str_starts_with($url, 'http://')) {
            return 'https://'.substr($url, 7);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPanelSettings(Server $server): array
    {
        if (isset($this->panelSettingsCache[$server->id])) {
            return $this->panelSettingsCache[$server->id];
        }

        $prefix = $this->client($server)->resolveApiPrefix();

        foreach (['/setting/all', '/settings/all'] as $path) {
            try {
                $response = $this->apiRequest($server, 'POST', $prefix, $path);

                if (! $response->successful()) {
                    continue;
                }

                $payload = $response->json();
                $obj = $payload['obj'] ?? $payload;

                if (is_array($obj) && $obj !== []) {
                    $this->panelSettingsCache[$server->id] = $this->mergePanelDefaultSettings($server, $prefix, $obj);

                    return $this->panelSettingsCache[$server->id];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $this->panelSettingsCache[$server->id] = [];

        return [];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function mergePanelDefaultSettings(Server $server, string $prefix, array $settings): array
    {
        try {
            $response = $this->apiRequest($server, 'POST', $prefix, '/setting/defaultSettings');

            if (! $response->successful()) {
                return $settings;
            }

            $defaults = $response->json('obj') ?? $response->json();

            if (! is_array($defaults)) {
                return $settings;
            }

            foreach ([
                'subURI',
                'subJsonURI',
                'subClashURI',
                'subPath',
                'subJsonPath',
                'subClashPath',
                'subPort',
                'subDomain',
                'subEncrypt',
                'subEnable',
                'subJsonEnable',
                'subClashEnable',
                'remarkModel',
                'subShowInfo',
            ] as $key) {
                if (! array_key_exists($key, $settings) || $settings[$key] === '' || $settings[$key] === null) {
                    if (array_key_exists($key, $defaults) && $defaults[$key] !== '' && $defaults[$key] !== null) {
                        $settings[$key] = $defaults[$key];
                    }
                }
            }
        } catch (\Throwable) {
            // Keep settings from /setting/all only.
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function resolveDefaultSubUri(Server $server, array $settings): string
    {
        $subPort = (int) ($settings['subPort'] ?? 2096);
        $subPath = (string) ($settings['subPath'] ?? '/sub/');
        $subDomain = trim((string) ($settings['subDomain'] ?? ''));
        $subKeyFile = trim((string) ($settings['subKeyFile'] ?? ''));
        $subCertFile = trim((string) ($settings['subCertFile'] ?? ''));

        $scheme = $this->resolveSubscriptionScheme($server, $settings);

        if ($subDomain === '') {
            $subDomain = $this->extractHostnameFromServer($server);
        }

        $useTls = $scheme === 'https';

        if (($subPort === 443 && $useTls) || ($subPort === 80 && ! $useTls)) {
            $authority = $subDomain;
        } else {
            $authority = $this->formatHostWithPort($subDomain, $subPort);
        }

        return $scheme.'://'.$authority.$this->normalizeSubPath($subPath);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function resolveSubscriptionScheme(Server $server, array $settings): string
    {
        $subKeyFile = trim((string) ($settings['subKeyFile'] ?? ''));
        $subCertFile = trim((string) ($settings['subCertFile'] ?? ''));

        if ($subKeyFile !== '' && $subCertFile !== '') {
            return 'https';
        }

        if ($this->serverPanelUsesHttps($server)) {
            return 'https';
        }

        $subPort = (int) ($settings['subPort'] ?? 2096);

        if (in_array($subPort, [443, 2053, 2096, 8443], true)) {
            return 'https';
        }

        return 'http';
    }

    protected function serverPanelUsesHttps(Server $server): bool
    {
        $host = trim((string) $server->host);

        if (str_starts_with(strtolower($host), 'https://')) {
            return true;
        }

        $port = (int) $server->port;

        if (in_array($port, [443, 2053], true)) {
            return true;
        }

        try {
            return str_starts_with($this->client($server)->url()->origin, 'https://');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function alignSubscriptionUriScheme(Server $server, string $uri): string
    {
        $preferred = $this->resolveSubscriptionScheme($server, $this->getPanelSettings($server));

        if ($preferred === 'https' && str_starts_with($uri, 'http://')) {
            return 'https://'.substr($uri, 7);
        }

        if ($preferred === 'http' && str_starts_with($uri, 'https://')) {
            return 'http://'.substr($uri, 8);
        }

        return $uri;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    protected function sortSubscriptionCandidatesHttpsFirst(array $urls): array
    {
        usort($urls, function (string $a, string $b): int {
            $aHttps = str_starts_with($a, 'https://') ? 0 : 1;
            $bHttps = str_starts_with($b, 'https://') ? 0 : 1;

            return $aHttps <=> $bHttps;
        });

        return $urls;
    }

    protected function joinSubscriptionPath(string $baseUri, string $subId): string
    {
        $baseUri = rtrim(trim($baseUri), '/');

        return $baseUri.'/'.$subId;
    }

    protected function normalizeSubPath(string $subPath): string
    {
        $subPath = trim($subPath);

        if ($subPath === '') {
            return '/sub/';
        }

        return '/'.trim($subPath, '/').'/';
    }

    protected function extractHostnameFromServer(Server $server): string
    {
        $host = trim((string) $server->host);

        if (preg_match('#^https?://#i', $host)) {
            $parsed = parse_url($host);
            $hostname = (string) ($parsed['host'] ?? '');
        } else {
            $hostname = explode('/', $host)[0];
            $hostname = explode(':', $hostname)[0];
        }

        return $hostname !== '' ? $hostname : 'localhost';
    }

    protected function formatHostWithPort(string $host, int $port): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '['.$host.']:'.$port;
        }

        return $host.':'.$port;
    }

    /**
     * @return array{
     *     up: int,
     *     down: int,
     *     total: int,
     *     used_bytes: int,
     *     limit_bytes: ?int,
     *     remaining_bytes: ?int,
     *     rx_bytes: int,
     *     tx_bytes: int,
     *     rx_snapshot: int,
     *     tx_snapshot: int
     * }
     */
    public function normalizeTrafficSnapshot(array $traffic): array
    {
        // 3x-ui API fields are from the inbound/server perspective:
        // `up` = traffic toward the client (client download), `down` = from the client (client upload).
        $clientDownload = (int) ($traffic['up'] ?? $traffic['Up'] ?? $traffic['upload'] ?? 0);
        $clientUpload = (int) ($traffic['down'] ?? $traffic['Down'] ?? $traffic['download'] ?? 0);
        $used = max(0, $clientDownload + $clientUpload);

        // In 3x-ui API, `total` is the traffic LIMIT in bytes (0 = unlimited), not usage.
        $limitBytes = (int) ($traffic['total'] ?? $traffic['totalGB'] ?? 0);
        $limitBytes = $limitBytes > 0 ? $limitBytes : null;

        if (isset($traffic['remainingTraffic'])) {
            $remaining = max(0, (int) $traffic['remainingTraffic']);
        } elseif (isset($traffic['remaining'])) {
            $remaining = max(0, (int) $traffic['remaining']);
        } elseif ($limitBytes !== null) {
            $remaining = max(0, $limitBytes - $used);
        } else {
            $remaining = null;
        }

        return [
            'up' => $clientUpload,
            'down' => $clientDownload,
            'total' => $used,
            'used_bytes' => $used,
            'limit_bytes' => $limitBytes,
            'remaining_bytes' => $remaining,
            'download_bytes' => $clientDownload,
            'upload_bytes' => $clientUpload,
            'rx_bytes' => $clientDownload,
            'tx_bytes' => $clientUpload,
            'rx_snapshot' => $clientDownload,
            'tx_snapshot' => $clientUpload,
        ];
    }

    public function findClientOnInbound(Server $server, int $inboundId, string $uuid): ?array
    {
        $client = $this->findClient($server, $inboundId, $uuid);

        if ($client !== null) {
            return $this->normalizeInboundClient($client);
        }

        $inbound = $this->getInbound($server, $inboundId);

        if ($inbound === null) {
            return null;
        }

        $settings = $this->decodeInboundJson($inbound, 'settings');
        $clients = is_array($settings['clients'] ?? null) ? $settings['clients'] : [];

        foreach ($clients as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeInboundClient($row);

            if ($this->clientIdsMatch($normalized['id'] ?? '', $uuid)) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $client
     */
    public function extractSubId(?array $client): ?string
    {
        if ($client === null) {
            return null;
        }

        $normalized = $this->normalizeInboundClient($client);
        $subId = trim(scalar_string($normalized['subId'] ?? ''));

        return $subId !== '' ? $subId : null;
    }

    /**
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    public function normalizeInboundClient(array $client): array
    {
        if (isset($client['subId'])) {
            $client['subId'] = trim(scalar_string($client['subId']));
        } elseif (isset($client['sub_id'])) {
            $client['subId'] = trim(scalar_string($client['sub_id']));
        } elseif (isset($client['SubID'])) {
            $client['subId'] = trim(scalar_string($client['SubID']));
        }

        if (isset($client['id'])) {
            $client['id'] = trim(scalar_string($client['id']));
        }

        return $client;
    }

    protected function clientIdsMatch(string $left, string $right): bool
    {
        return $left !== '' && $right !== '' && strcasecmp($left, $right) === 0;
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @return array<string, mixed>
     */
    protected function decodeInboundJson(array $inbound, string $key): array
    {
        $raw = $inbound[$key] ?? $inbound[Str::snake($key)] ?? [];

        if (is_array($raw)) {
            return $raw;
        }

        return decode_panel_json_field($raw, []);
    }

    public function client(Server $server): SanaeiPanelClient
    {
        if ($server->isRemnawave()) {
            throw new InvalidArgumentException(
                __('services.sanaei_server_is_remnawave', ['name' => $server->name])
            );
        }

        if ($server->isPasarguard()) {
            throw new InvalidArgumentException(
                __('services.sanaei_server_is_pasarguard', ['name' => $server->name])
            );
        }

        return $this->clients[$server->id] ??= new SanaeiPanelClient($server);
    }

    protected function apiRequest(Server $server, string $method, string $prefix, string $path, array $payload = [])
    {
        return $this->withRetry(function () use ($server, $method, $prefix, $path, $payload) {
            return $this->client($server)->apiRequest($method, $prefix, $path, $payload);
        }, 'sanaei.api', [
            'server_id' => $server->id,
            'method' => $method,
            'path' => $prefix.$path,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findClient(Server $server, int $inboundId, string $uuid): ?array
    {
        $prefix = $this->client($server)->resolveApiPrefix();
        $response = $this->apiRequest($server, 'GET', $prefix, '/inbounds/list');
        $this->assertSuccessful($response, 'list Sanaei inbounds');

        $inbounds = $response->json('obj') ?? $response->json() ?? [];

        foreach ($inbounds as $inbound) {
            if ((int) ($inbound['id'] ?? 0) !== $inboundId) {
                continue;
            }

            $settings = $this->decodeInboundJson($inbound, 'settings');
            $clients = $settings['clients'] ?? [];
            if (! is_array($clients)) {
                $clients = [];
            }

            foreach ($clients as $client) {
                if (! is_array($client)) {
                    continue;
                }

                $client = $this->normalizeInboundClient($client);

                if ($this->clientIdsMatch(scalar_string($client['id'] ?? ''), $uuid)) {
                    return $client;
                }
            }
        }

        return null;
    }

    protected function gbToBytes(?float $totalGB): int
    {
        if ($totalGB === null || $totalGB <= 0) {
            return 0;
        }

        return (int) round($totalGB * 1024 * 1024 * 1024);
    }

    /**
     * 3x-ui / Sanaei panel expects numeric fields (e.g. tgId) as JSON numbers, not strings.
     *
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    protected function buildClientPayloadForApi(array $client, string $uuid, array $changes): array
    {
        $normalized = $this->normalizeClientPayload($client);

        if (array_key_exists('totalGB', $changes)) {
            $normalized['totalGB'] = $this->gbToBytes(
                is_numeric($changes['totalGB']) || $changes['totalGB'] === null
                    ? $changes['totalGB']
                    : null
            );
        }

        if (array_key_exists('up', $changes)) {
            $normalized['up'] = scalar_int($changes['up'] ?? 0);
        }

        if (array_key_exists('down', $changes)) {
            $normalized['down'] = scalar_int($changes['down'] ?? 0);
        }

        if (array_key_exists('subId', $changes) && $changes['subId'] !== null && $changes['subId'] !== '') {
            $normalized['subId'] = scalar_string($changes['subId']);
        }

        return [
            'id' => $uuid,
            'email' => scalar_string($normalized['email'] ?? ''),
            'limitIp' => scalar_int($normalized['limitIp'] ?? 0),
            'totalGB' => scalar_int($normalized['totalGB'] ?? 0),
            'expiryTime' => scalar_int($normalized['expiryTime'] ?? 0),
            'enable' => scalar_bool($normalized['enable'] ?? true),
            'tgId' => scalar_int($normalized['tgId'] ?? 0),
            'subId' => scalar_string($normalized['subId'] ?? ''),
            'flow' => scalar_string($normalized['flow'] ?? ''),
            'comment' => scalar_string($normalized['comment'] ?? ''),
            'reset' => scalar_int($normalized['reset'] ?? 0),
            'up' => scalar_int($normalized['up'] ?? 0),
            'down' => scalar_int($normalized['down'] ?? 0),
        ];
    }

    protected function normalizeClientPayload(array $client): array
    {
        if (array_key_exists('tgId', $client)) {
            $client['tgId'] = scalar_int($client['tgId'], 0);
        }

        if (array_key_exists('limitIp', $client)) {
            $client['limitIp'] = scalar_int($client['limitIp'] ?? 0);
        }

        if (array_key_exists('expiryTime', $client)) {
            $client['expiryTime'] = scalar_int($client['expiryTime'] ?? 0);
        }

        if (array_key_exists('totalGB', $client)) {
            $client['totalGB'] = scalar_int($client['totalGB'] ?? 0);
        }

        if (array_key_exists('enable', $client)) {
            $client['enable'] = scalar_bool($client['enable'] ?? true);
        }

        if (array_key_exists('email', $client)) {
            $client['email'] = scalar_string($client['email'] ?? '');
        }

        if (array_key_exists('subId', $client)) {
            $client['subId'] = scalar_string($client['subId'] ?? '');
        }

        if (array_key_exists('up', $client)) {
            $client['up'] = scalar_int($client['up'] ?? 0);
        }

        if (array_key_exists('down', $client)) {
            $client['down'] = scalar_int($client['down'] ?? 0);
        }

        return $client;
    }

    protected function assertSuccessful($response, string $operation, ?Server $server = null): void
    {
        if ($response->successful()) {
            $json = $response->json();

            if (! is_array($json) || ! (array_key_exists('success', $json) || array_key_exists('obj', $json))) {
                throw new RemoteProvisionException(
                    'Failed to '.$operation.': '.__('services.panel_html_instead_of_api')
                );
            }

            if (array_key_exists('success', $json) && $json['success'] === false) {
                throw new RemoteProvisionException(
                    'Failed to '.$operation.': '.panel_api_message(
                        $json['msg'] ?? $json['message'] ?? $json['detail'] ?? $json['error'] ?? $json['obj'] ?? null
                    )
                );
            }

            return;
        }

        $detail = '';

        if (is_array($response->json())) {
            $detail = panel_api_message(
                $response->json('msg') ?? $response->json('message') ?? $response->json('detail') ?? $response->json('error')
            );
        }

        $hint = '';

        if ($response->status() === 404) {
            $hint = ' مسیر API پنل یافت نشد — «مسیر پایه وب» سرور و دسترسی به API کلاینت‌ها (/panel/api/clients) را بررسی کنید.';
            if ($server !== null && trim((string) $server->web_base_path) === '' && preg_match('#^https?://[^/]+/.+#i', trim($server->host))) {
                $hint .= ' اگر آدرس پنل شامل مسیر مخفی است، همان را در فیلد «مسیر پایه وب» هم ثبت کنید.';
            }

            if ($server !== null) {
                $tried = $this->client($server)->recentPostAttemptUrls(3);
                if ($tried !== []) {
                    $hint .= ' آخرین URL: '.implode(' | ', $tried);
                }
            }
        }

        throw new RemoteProvisionException(
            "Failed to {$operation}: HTTP {$response->status()}"
            .($detail !== '' ? " — {$detail}" : '')
            .$hint
        );
    }
}
