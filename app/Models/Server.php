<?php

namespace App\Models;

use App\Enums\AccountCategory;
use App\Enums\PasarguardConnectionMode;
use App\Enums\ServerHealthStatus;
use App\Enums\ServerType;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class Server extends Model
{
    protected $fillable = [
        'name',
        'location',
        'type',
        'pasarguard_mode',
        'pasarguard_groups',
        'pasarguard_groups_synced_at',
        'remnawave_squads',
        'remnawave_squads_synced_at',
        'remnawave_active_squads',
        'remnawave_nodes',
        'remnawave_nodes_synced_at',
        'role',
        'host',
        'client_host',
        'client_port',
        'public_ip',
        'cisco_vpn_hostname',
        'cisco_group_policy',
        'cisco_tunnel_group',
        'cisco_verify_ssl',
        'cisco_write_memory',
        'cisco_simultaneous_logins',
        'ocserv_vpn_address',
        'ocserv_default_max_sessions',
        'ocserv_group',
        'ocserv_verify_ssl',
        'port',
        'ssh_port',
        'web_base_path',
        'username_enc',
        'password_enc',
        'api_token_enc',
        'remnawave_api_key_enc',
        'is_public',
        'max_accounts',
        'wireguard_persistent_keepalive',
        'account_cap',
        'is_active',
        'is_hub',
        'wan_interface',
        'api_ssl',
        'show_in_account_filters',
        'show_on_dashboard',
        'last_health_check_at',
        'last_health_status',
        'notes',
        'ovpn_profile_path',
        'ovpn_profile_original_name',
        'l2tp_use_ipsec',
        'l2tp_ipsec_secret_enc',
        'backup_schedule_enabled',
        'backup_times',
    ];

    protected $hidden = [
        'username_enc',
        'password_enc',
        'api_token_enc',
        'remnawave_api_key_enc',
        'l2tp_ipsec_secret_enc',
    ];

    protected function casts(): array
    {
        return [
            'type' => ServerType::class,
            'pasarguard_mode' => PasarguardConnectionMode::class,
            'pasarguard_groups' => 'array',
            'pasarguard_groups_synced_at' => 'datetime',
            'remnawave_squads' => 'array',
            'remnawave_squads_synced_at' => 'datetime',
            'remnawave_active_squads' => 'array',
            'remnawave_nodes' => 'array',
            'remnawave_nodes_synced_at' => 'datetime',
            'port' => 'integer',
            'client_port' => 'integer',
            'ssh_port' => 'integer',
            'username_enc' => 'encrypted',
            'password_enc' => 'encrypted',
            'api_token_enc' => 'encrypted',
            'remnawave_api_key_enc' => 'encrypted',
            'is_public' => 'boolean',
            'max_accounts' => 'integer',
            'wireguard_persistent_keepalive' => 'integer',
            'is_active' => 'boolean',
            'is_hub' => 'boolean',
            'api_ssl' => 'boolean',
            'cisco_verify_ssl' => 'boolean',
            'cisco_write_memory' => 'boolean',
            'cisco_simultaneous_logins' => 'integer',
            'ocserv_default_max_sessions' => 'integer',
            'ocserv_verify_ssl' => 'boolean',
            'show_in_account_filters' => 'boolean',
            'show_on_dashboard' => 'boolean',
            'last_health_check_at' => 'datetime',
            'last_health_status' => ServerHealthStatus::class,
            'account_cap' => 'integer',
            'l2tp_use_ipsec' => 'boolean',
            'l2tp_ipsec_secret_enc' => 'encrypted',
            'last_cpu_pct' => 'float',
            'last_throughput_bps' => 'integer',
            'last_conntrack' => 'integer',
            'last_conntrack_max' => 'integer',
            'metrics_sampled_at' => 'datetime',
            'backup_schedule_enabled' => 'boolean',
            'backup_times' => 'array',
        ];
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_server');
    }

    public function packagesAsDefault(): HasMany
    {
        return $this->hasMany(Package::class, 'default_server_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(ServerSyncLog::class);
    }

    public function interfaces(): HasMany
    {
        return $this->hasMany(ServerInterface::class);
    }

    public function managedInterfaces(): HasMany
    {
        return $this->hasMany(ManagedInterface::class);
    }

    public function tunnelGroupsAsIran(): HasMany
    {
        return $this->hasMany(TunnelGroup::class, 'iran_server_id');
    }

    public function tunnelGroupExits(): HasMany
    {
        return $this->hasMany(TunnelGroupExit::class);
    }

    public function desiredNetworkObjects(): HasMany
    {
        return $this->hasMany(DesiredNetworkObject::class);
    }

    public function metricSamples(): HasMany
    {
        return $this->hasMany(ServerMetricSample::class);
    }

    public function routerScripts(): HasMany
    {
        return $this->hasMany(RouterScript::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Servers that can host accounts in the given category (MikroTik for WG/PPP, panels for V2ray).
     */
    public function scopeForAccountCategory(Builder $query, AccountCategory $category): Builder
    {
        return $query->whereIn('type', $category->serverTypeValues());
    }

    /** Normalized metrics timestamp (handles legacy string values from DB). */
    public function metricsSampledAt(): ?Carbon
    {
        $value = $this->metrics_sampled_at;

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    public function isMikrotik(): bool
    {
        return $this->type === ServerType::Mikrotik;
    }

    public function hasOvpnProfile(): bool
    {
        return app(\App\Services\ServerOvpnProfileService::class)->hasProfile($this);
    }

    /** Persistent keepalive for WireGuard peers (seconds). Null → global config default. */
    public function wireguardPersistentKeepalive(): int
    {
        $seconds = $this->wireguard_persistent_keepalive;

        if ($seconds !== null && $seconds >= 0) {
            return (int) $seconds;
        }

        return (int) config('shahpanel.wireguard.persistent_keepalive', 10);
    }

    /**
     * Hostname or IP shown in client VPN configs (WireGuard Endpoint, OpenVPN remote).
     * Always uses the server «host» field — never public_ip.
     */
    public function vpnClientEndpointHost(): string
    {
        return $this->normalizeConnectionHost((string) $this->host);
    }

    /**
     * Host used to reach the router/panel API from shahpanel.
     * When host is a domain, optional public_ip (IPv4) is preferred for API stability.
     */
    public function apiConnectionHost(): string
    {
        $host = $this->normalizeConnectionHost((string) $this->host);

        if (! $this->isIpAddress($host)) {
            $ip = trim((string) ($this->public_ip ?? ''));
            if ($ip !== '' && $this->isIpAddress($ip)) {
                return $ip;
            }
        }

        return $host;
    }

    /**
     * نشانی‌ای که باید در کانفیگ‌ها و لینک اشتراکِ تحویلی به کاربر بنشیند.
     *
     * فروشندهٔ دارای تونل، پنل را روی IP مستقیم سرور مدیریت می‌کند ولی کاربرش
     * باید به تونل وصل شود. خالی‌بودن client_host یعنی رفتار قبلی: همان میزبان
     * مدیریتی به کاربر داده می‌شود.
     */
    public function clientHost(): string
    {
        $clientHost = $this->normalizeConnectionHost((string) ($this->client_host ?? ''));

        if ($clientHost !== '') {
            return $clientHost;
        }

        return $this->normalizeConnectionHost((string) $this->host);
    }

    /** آیا نشانی کاربرمحور تنظیم شده؟ مبنای «دست نزن» برای بازنویس نشانی‌ها. */
    public function hasClientHost(): bool
    {
        return $this->normalizeConnectionHost((string) ($this->client_host ?? '')) !== '';
    }

    /** پورت اختیاری کانفیگ‌های کاربر؛ null یعنی پورت اینباند/کانفیگ حفظ شود. */
    public function clientPort(): ?int
    {
        $port = (int) ($this->client_port ?? 0);

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    /** SSH/SFTP port on MikroTik for native .backup download (separate from API port). */
    public function mikrotikSshPort(): ?int
    {
        if (! $this->isMikrotik() || $this->ssh_port === null) {
            return null;
        }

        $port = (int) $this->ssh_port;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    public function normalizeConnectionHost(string $host): string
    {
        $host = trim($host);
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = (string) (explode('/', $host, 2)[0] ?? $host);

        if (preg_match('/^\[(.+)\](?::\d+)?$/', $host, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^(.+):(\d+)$/', $host, $matches) && ! filter_var($host, FILTER_VALIDATE_IP)) {
            return trim($matches[1]);
        }

        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):(\d+)$/', $host, $matches)) {
            return $matches[1];
        }

        return rtrim($host, '/');
    }

    protected function isIpAddress(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Short, RouterOS-safe location code derived from the server's `location`
     * (e.g. «ترکیه» / «Turkey» → "tr"). Used to name client-facing tunnel
     * interfaces per foreign location (wg-tr, ppp-tr, ...). Falls back to a
     * slug of the location text, then to the server id.
     */
    public function locationCode(): string
    {
        $raw = trim((string) ($this->location ?? ''));

        if ($raw !== '') {
            $normalized = mb_strtolower($raw);

            $map = [
                'tr' => ['ترکیه', 'تركیه', 'turkey', 'türkiye', 'turkiye', 'istanbul', 'استانبول'],
                'de' => ['آلمان', 'germany', 'deutschland', 'frankfurt', 'فرانکفورت'],
                'nl' => ['هلند', 'netherlands', 'holland', 'amsterdam', 'آمستردام'],
                'fr' => ['فرانسه', 'france', 'paris', 'پاریس'],
                'gb' => ['انگلیس', 'انگلستان', 'بریتانیا', 'uk', 'united kingdom', 'england', 'britain', 'london', 'لندن'],
                'us' => ['آمریکا', 'امریکا', 'usa', 'united states', 'america'],
                'fi' => ['فنلاند', 'finland', 'helsinki', 'هلسینکی'],
                'se' => ['سوئد', 'sweden', 'stockholm'],
                'ru' => ['روسیه', 'russia', 'moscow', 'مسکو'],
                'ae' => ['امارات', 'uae', 'emirates', 'dubai', 'دبی'],
                'ir' => ['ایران', 'iran', 'tehran', 'تهران'],
                'ca' => ['کانادا', 'canada'],
                'pl' => ['لهستان', 'poland', 'warsaw'],
                'at' => ['اتریش', 'austria', 'vienna'],
                'ch' => ['سوئیس', 'switzerland', 'zurich'],
                'jp' => ['ژاپن', 'japan', 'tokyo'],
                'sg' => ['سنگاپور', 'singapore'],
                'in' => ['هند', 'india'],
                'it' => ['ایتالیا', 'italy', 'rome', 'milan'],
                'es' => ['اسپانیا', 'spain', 'madrid'],
                'am' => ['ارمنستان', 'armenia', 'yerevan'],
                't__' => [],
            ];

            foreach ($map as $code => $aliases) {
                foreach ($aliases as $alias) {
                    if (mb_strpos($normalized, $alias) !== false) {
                        return $code;
                    }
                }
            }

            // No known country: slugify the ASCII portion (e.g. "Tehran-DC" → "tehran").
            $ascii = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
            $ascii = trim($ascii, '-');
            if ($ascii !== '') {
                $first = explode('-', $ascii)[0];

                return substr($first, 0, 8);
            }
        }

        return 's'.$this->getKey();
    }

    /**
     * SSTP role: internal = inside-Iran listener; external = abroad initiator.
     */
    public function isInternal(): bool
    {
        return ($this->role ?? 'internal') === 'internal';
    }

    public function isExternal(): bool
    {
        return $this->role === 'external';
    }

    public function isSanaei(): bool
    {
        return $this->type === ServerType::Sanaei;
    }

    public function isPasarguard(): bool
    {
        return $this->type === ServerType::Pasarguard;
    }

    public function isRemnawave(): bool
    {
        return $this->type === ServerType::Remnawave;
    }

    public function isCiscoAnyconnect(): bool
    {
        return $this->type === ServerType::CiscoAnyconnect;
    }

    public function isOcserv(): bool
    {
        return $this->type === ServerType::Ocserv;
    }

    public function isAnyconnectFamily(): bool
    {
        return $this->isCiscoAnyconnect() || $this->isOcserv();
    }

    public function hasStoredRemnawaveApiToken(): bool
    {
        return $this->isRemnawave() && trim((string) ($this->api_token_enc ?? '')) !== '';
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    public function remnawaveSquadCatalog(): array
    {
        return \App\Services\Remnawave\RemnawaveSquadCatalog::forServer($this);
    }

    /**
     * UUIDهای squad انتخاب‌شده برای این سرور (activeInternalSquads پیش‌فرض).
     *
     * @return list<string>
     */
    public function remnawaveActiveSquadUuids(): array
    {
        $active = $this->remnawave_active_squads;
        if (! is_array($active)) {
            return [];
        }

        $catalogUuids = array_column($this->remnawaveSquadCatalog(), 'uuid');

        return array_values(array_filter(array_map('strval', $active), function (string $uuid) use ($catalogUuids): bool {
            return $uuid !== '' && ($catalogUuids === [] || in_array($uuid, $catalogUuids, true));
        }));
    }

    public function hasRemnawaveActiveSquads(): bool
    {
        return $this->remnawaveActiveSquadUuids() !== [];
    }

    public function isPasarguardReseller(): bool
    {
        if (! $this->isPasarguard()) {
            return false;
        }

        return ($this->pasarguard_mode ?? PasarguardConnectionMode::Reseller) === PasarguardConnectionMode::Reseller;
    }

    public function isPasarguardAdmin(): bool
    {
        return $this->isPasarguard() && ! $this->isPasarguardReseller();
    }

    /** Sanaei or PasarGuard — VPN panel with inbound sync. */
    public function isPanelBacked(): bool
    {
        return $this->type?->isPanelBacked() ?? false;
    }

    public function isCompatibleWithServiceType(ServiceType $serviceType): bool
    {
        if ($serviceType->isRemnawave()) {
            return $this->type === ServerType::Remnawave;
        }

        if ($serviceType->isCiscoAnyconnect()) {
            return $this->type === ServerType::CiscoAnyconnect;
        }

        if ($serviceType->isOcserv()) {
            return $this->type === ServerType::Ocserv;
        }

        if ($serviceType->isPasarguard()) {
            return $this->type === ServerType::Pasarguard;
        }

        if ($serviceType->isSanaei()) {
            return $this->type === ServerType::Sanaei;
        }

        return $this->type === ServerType::Mikrotik;
    }

    public function scopeCompatibleWithServiceType(Builder $query, ServiceType $serviceType): Builder
    {
        if ($serviceType->isRemnawave()) {
            return $query->where('type', ServerType::Remnawave);
        }

        if ($serviceType->isCiscoAnyconnect()) {
            return $query->where('type', ServerType::CiscoAnyconnect);
        }

        if ($serviceType->isOcserv()) {
            return $query->where('type', ServerType::Ocserv);
        }

        if ($serviceType->isPasarguard()) {
            return $query->where('type', ServerType::Pasarguard);
        }

        if ($serviceType->isSanaei()) {
            return $query->where('type', ServerType::Sanaei);
        }

        return $query->where('type', ServerType::Mikrotik);
    }

    public function supportsRemoteBackup(): bool
    {
        return $this->isMikrotik() || $this->isSanaei() || $this->isPasarguard() || $this->isRemnawave();
    }

    public function backups(): HasMany
    {
        return $this->hasMany(ServerBackup::class);
    }

    /**
     * ساعت‌های بک‌آپ زمان‌بندی‌شده — همیشه به وقت پنل، یعنی config('app.timezone').
     *
     * @return list<string>
     */
    public function backupTimes(): array
    {
        return self::normalizeBackupTimes($this->backup_times);
    }

    /**
     * فرم ادمین یک رشتهٔ چندمقداری می‌فرستد و ستون json یک آرایه برمی‌گرداند؛
     * هر دو از همین‌جا به فهرست مرتب و یکتای «HH:MM» تبدیل می‌شوند تا مقایسهٔ
     * رشته‌ای در زمان‌بند قابل اعتماد باشد.
     *
     * @return list<string>
     */
    public static function normalizeBackupTimes(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;\x{060C}]+/u', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $times = [];

        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            // ارقام فارسی/عربیِ ورودی ادمین باید پیش از تطبیق با HH:MM لاتین شوند.
            $candidate = trim(western_digits((string) $item));
            $candidate = str_replace(['.', '：'], ':', $candidate);

            if (preg_match('/^(\d{1,2}):(\d{2})$/', $candidate, $matches) !== 1) {
                continue;
            }

            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            if ($hour > 23 || $minute > 59) {
                continue;
            }

            $times[] = sprintf('%02d:%02d', $hour, $minute);
        }

        $times = array_values(array_unique($times));
        sort($times);

        return $times;
    }
}
