<?php

/**
 * کلاینت PHP برای Reseller API پنل — بدون هیچ وابستگی (فقط cURL).
 *
 * برای ربات‌هایی مثل میرزا و دیبات که PHP هستند.
 *
 *   $api = new ShahPanelClient('https://your-domain.example/api/v1', $token);
 *   $accounts = $api->accounts(['status' => 'active']);
 */
class ShahPanelClient
{
    private string $baseUrl;
    private ?string $token;
    private int $timeout;

    public function __construct(string $baseUrl, ?string $token = null, int $timeout = 60)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->timeout = $timeout;
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    // ───────────────────────── احراز هویت ─────────────────────────

    /**
     * ورود با نام کاربری و رمز پنل. توکن را برمی‌گرداند و روی کلاینت می‌نشاند.
     */
    public function login(string $username, string $password, ?string $twoFaCode = null, string $deviceName = 'telegram-bot'): array
    {
        $payload = [
            'username' => $username,
            'password' => $password,
            'device_name' => $deviceName,
        ];

        if ($twoFaCode !== null && $twoFaCode !== '') {
            $payload['two_fa_code'] = $twoFaCode;
        }

        $data = $this->request('POST', '/auth/login', $payload);
        $this->token = $data['token'];

        return $data;
    }

    public function me(): array
    {
        return $this->request('GET', '/auth/me');
    }

    public function logout(): array
    {
        return $this->request('POST', '/auth/logout');
    }

    // ───────────────────────── کاتالوگ ─────────────────────────

    /** پکیج‌های قابل فروش، با قیمت مخصوص همین کاربر. */
    public function packages(?int $sellerId = null): array
    {
        return $this->request('GET', '/catalog/packages', $sellerId ? ['seller_id' => $sellerId] : []);
    }

    public function servers(?int $packageId = null): array
    {
        return $this->request('GET', '/catalog/servers', $packageId ? ['package_id' => $packageId] : []);
    }

    public function price(int $durationId, ?float $dataGb = null): array
    {
        $q = ['package_duration_id' => $durationId];

        if ($dataGb !== null) {
            $q['data_gb'] = $dataGb;
        }

        return $this->request('GET', '/catalog/price', $q);
    }

    // ───────────────────────── اکانت‌ها ─────────────────────────

    /** @param array<string,mixed> $filters status, search, package_id, expiring_within_days, page, per_page … */
    public function accounts(array $filters = []): array
    {
        return $this->request('GET', '/accounts', $filters);
    }

    /** شناسه یا نام کاربری اکانت هر دو قبول است. */
    public function account(string|int $key): array
    {
        return $this->request('GET', '/accounts/'.rawurlencode((string) $key));
    }

    /** قیمت قبل از فروش — برای نمایش به مشتری. */
    public function previewPrice(int $packageId, int $durationId, ?float $dataGb = null): array
    {
        $q = ['package_id' => $packageId, 'package_duration_id' => $durationId];

        if ($dataGb !== null) {
            $q['data_gb'] = $dataGb;
        }

        return $this->request('GET', '/accounts/preview', $q);
    }

    /**
     * فروش اکانت جدید.
     *
     * @param array<string,mixed> $extra مثلاً server_id، client_email، owner_seller_id
     */
    public function sell(int $packageId, int $durationId, string $username, ?float $dataGb = null, array $extra = []): array
    {
        $payload = array_merge([
            'package_id' => $packageId,
            'package_duration_id' => $durationId,
            'remote_username' => $username,
            // پکیج‌های سنایی/v2ray به یک برچسب هم نیاز دارند
            'sanaei_client_name' => $username,
        ], $extra);

        if ($dataGb !== null) {
            $payload['data_gb'] = $dataGb;
        }

        return $this->request('POST', '/accounts', $payload);
    }

    /** کانفیگ/لینک اشتراک/QR برای تحویل به مشتری. */
    public function config(string|int $key): array
    {
        return $this->request('GET', '/accounts/'.rawurlencode((string) $key).'/config');
    }

    /** $refresh=true مصرف را زنده از سرور می‌خواند (کندتر). */
    public function usage(string|int $key, bool $refresh = false): array
    {
        return $this->request('GET', '/accounts/'.rawurlencode((string) $key).'/usage', $refresh ? ['refresh' => 1] : []);
    }

    /**
     * @param string $mode same | add_volume | upgrade_volume
     */
    public function renew(string|int $key, string $mode = 'same', ?int $durationId = null, ?float $dataGb = null): array
    {
        $payload = ['renewal_mode' => $mode];

        if ($durationId !== null) {
            $payload['package_duration_id'] = $durationId;
        }

        if ($dataGb !== null) {
            $payload['data_gb'] = $dataGb;
        }

        return $this->request('POST', '/accounts/'.rawurlencode((string) $key).'/renew', $payload);
    }

    public function enable(string|int $key): array
    {
        return $this->request('POST', '/accounts/'.rawurlencode((string) $key).'/enable');
    }

    public function disable(string|int $key): array
    {
        return $this->request('POST', '/accounts/'.rawurlencode((string) $key).'/disable');
    }

    // ───────────────────── کیف پول / زیرمجموعه / آمار ─────────────────────

    public function wallet(): array
    {
        return $this->request('GET', '/wallet');
    }

    public function transactions(array $filters = []): array
    {
        return $this->request('GET', '/wallet/transactions', $filters);
    }

    /** فقط نماینده. */
    public function resellers(array $filters = []): array
    {
        return $this->request('GET', '/resellers', $filters);
    }

    public function resellerAccounts(int $resellerId, array $filters = []): array
    {
        return $this->request('GET', '/resellers/'.$resellerId.'/accounts', $filters);
    }

    public function dashboard(): array
    {
        return $this->request('GET', '/stats/dashboard');
    }

    // ───────────────────────── هسته ─────────────────────────

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> فقط بخش data برمی‌گردد
     * @throws ShahPanelApiException
     */
    private function request(string $method, string $path, array $params = []): array
    {
        $url = $this->baseUrl.$path;
        $body = null;

        if ($method === 'GET') {
            if ($params !== []) {
                $url .= '?'.http_build_query($params);
            }
        } else {
            $body = json_encode($params, JSON_UNESCAPED_UNICODE);
        }

        $headers = ['Accept: application/json'];

        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new ShahPanelApiException('network_error', 'خطای شبکه: '.$curlError, 0);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new ShahPanelApiException('bad_response', 'پاسخ نامعتبر از سرور (HTTP '.$status.')', $status);
        }

        if (($decoded['ok'] ?? false) !== true) {
            // خطاهای اعتبارسنجی لاراول کلید errors دارند، نه error
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $first = reset($decoded['errors']);
                $message = is_array($first) ? (string) reset($first) : (string) $first;

                throw new ShahPanelApiException('validation_failed', $message, $status, $decoded['errors']);
            }

            $err = $decoded['error'] ?? [];

            throw new ShahPanelApiException(
                (string) ($err['code'] ?? 'unknown'),
                (string) ($err['message'] ?? 'خطای ناشناخته'),
                $status,
            );
        }

        $data = $decoded['data'] ?? [];

        // صفحه‌بندی را کنار داده برمی‌گردانیم تا ربات بتواند صفحهٔ بعد را بگیرد
        if (isset($decoded['meta']['pagination'])) {
            return ['items' => $data, 'pagination' => $decoded['meta']['pagination']];
        }

        return is_array($data) ? $data : ['value' => $data];
    }
}

class ShahPanelApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $validationErrors = [],
    ) {
        parent::__construct($message);
    }

    /** توکن باطل/منقضی شده — ربات باید دوباره login کند. */
    public function needsReauth(): bool
    {
        return $this->httpStatus === 401;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }
}
