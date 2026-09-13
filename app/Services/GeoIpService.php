<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offline IP→country lookup backed by ip_country_ranges.
 *
 * The table is filled by `firewall:sync-country-data`; until it is, lookups
 * simply return unknown rather than reaching out to a third party mid-request.
 */
class GeoIpService
{
    /** @var array<string, string> */
    protected const NAMES = [
        'IR' => 'ایران', 'CN' => 'چین', 'RU' => 'روسیه', 'US' => 'آمریکا',
        'DE' => 'آلمان', 'NL' => 'هلند', 'GB' => 'انگلستان', 'FR' => 'فرانسه',
        'TR' => 'ترکیه', 'AE' => 'امارات', 'IQ' => 'عراق', 'IN' => 'هند',
        'CA' => 'کانادا', 'SE' => 'سوئد', 'FI' => 'فنلاند', 'PL' => 'لهستان',
        'UA' => 'اوکراین', 'VN' => 'ویتنام', 'BR' => 'برزیل', 'ID' => 'اندونزی',
        'SG' => 'سنگاپور', 'JP' => 'ژاپن', 'KR' => 'کره جنوبی', 'HK' => 'هنگ‌کنگ',
        'AM' => 'ارمنستان', 'AZ' => 'آذربایجان', 'AF' => 'افغانستان',
    ];

    /** @return array{code: ?string, name: ?string} */
    public function lookup(string $ip): array
    {
        $long = ip2long($ip);

        if ($long === false) {
            return ['code' => null, 'name' => null];
        }

        $key = 'geoip:'.$ip;

        return Cache::remember($key, 86400, function () use ($long): array {
            if (! Schema::hasTable('ip_country_ranges')) {
                return ['code' => null, 'name' => null];
            }

            $code = DB::table('ip_country_ranges')
                ->where('start_ip', '<=', $long)
                ->where('end_ip', '>=', $long)
                ->orderByDesc('start_ip')
                ->value('country_code');

            if ($code === null) {
                return ['code' => null, 'name' => null];
            }

            $code = strtoupper((string) $code);

            return ['code' => $code, 'name' => self::NAMES[$code] ?? $code];
        });
    }

    public static function countryName(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::NAMES[strtoupper($code)] ?? strtoupper($code);
    }

    /** Regional-indicator emoji for an ISO country code (IR → 🇮🇷). */
    public static function flagEmoji(?string $code): string
    {
        $code = strtoupper((string) $code);

        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return '🏳️';
        }

        $out = '';

        foreach (str_split($code) as $letter) {
            $out .= mb_chr(0x1F1E6 + (ord($letter) - ord('A')), 'UTF-8');
        }

        return $out;
    }

    /** Resolve flag emoji for an IPv4 address (or CIDR → network IP). */
    public function flagForIp(?string $ipOrCidr): string
    {
        $ip = $this->extractIpv4($ipOrCidr);

        if ($ip === null) {
            return '🏳️';
        }

        return self::flagEmoji($this->lookup($ip)['code'] ?? null);
    }

    protected function extractIpv4(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '/')) {
            $value = explode('/', $value, 2)[0];
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null;
    }
}
