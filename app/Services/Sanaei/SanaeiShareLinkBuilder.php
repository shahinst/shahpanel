<?php

namespace App\Services\Sanaei;

use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Server;
use App\Services\SanaeiService;

class SanaeiShareLinkBuilder
{
    public function __construct(
        protected SanaeiService $sanaeiService,
    ) {}

    public function buildForAccount(Account $account): ?string
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null || ! $account->sanaei_client_uuid) {
            return null;
        }

        try {
            $clientStub = $this->clientContextFromAccount($account);

            $subId = trim((string) ($account->sanaei_sub_id ?? ''));
            $fromSub = $this->configLinkFromSubscription($server, $subId, $account, $clientStub);

            if ($fromSub !== null) {
                return $fromSub;
            }

            $client = $this->resolveClient($account, $server);

            if ($client === null) {
                return null;
            }

            if ($subId === '') {
                $subId = (string) ($this->sanaeiService->extractSubId($client) ?? '');
                $fromSub = $this->configLinkFromSubscription($server, $subId, $account, $client);

                if ($fromSub !== null) {
                    return $fromSub;
                }
            }

            $legacyInboundId = (int) ($account->sanaei_inbound_id ?? 0);

            if ($legacyInboundId > 0) {
                $inbound = $this->sanaeiService->getInbound($server, $legacyInboundId);

                if ($inbound !== null) {
                    $protocol = strtolower((string) ($inbound['protocol'] ?? ''));

                    $manual = match ($protocol) {
                        'vmess' => $this->buildVmess($inbound, $client, $server),
                        'vless' => $this->buildVless($inbound, $client, $server),
                        'trojan' => $this->buildTrojan($inbound, $client, $server),
                        default => null,
                    };

                    if ($manual !== null) {
                        return $manual;
                    }
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $client
     */
    protected function configLinkFromSubscription(
        Server $server,
        string $subId,
        Account $account,
        array $client
    ): ?string {
        if ($subId === '') {
            return null;
        }

        $links = $this->sanaeiService->fetchSubscriptionConfigLinks($server, $subId);

        return $this->pickConfigLink($links, $account, $client);
    }

    /**
     * @return array<string, mixed>
     */
    protected function clientContextFromAccount(Account $account): array
    {
        return [
            'id' => (string) $account->sanaei_client_uuid,
            'email' => (string) ($account->client_email ?? $account->remote_username ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveClient(Account $account, Server $server): ?array
    {
        $email = trim((string) ($account->client_email ?? $account->remote_username ?? ''));

        if ($email === '') {
            return null;
        }

        return $this->sanaeiService->resolvePanelClient(
            $server,
            $email,
            (string) $account->sanaei_client_uuid,
            $account->sanaei_inbound_id ?: null
        );
    }

    public function subscriptionLinkForAccount(Account $account): ?string
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null || ! $account->sanaei_client_uuid) {
            return null;
        }

        try {
            $subId = trim((string) ($account->sanaei_sub_id ?? ''));

            if ($subId === '') {
                $client = $this->resolveClient($account, $server);
                $subId = (string) ($this->sanaeiService->extractSubId($client) ?? '');
            }

            if ($subId === '') {
                return null;
            }

            return $this->sanaeiService->buildSubscriptionLink($server, $subId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $links
     * @param  array<string, mixed>  $client
     */
    protected function pickConfigLink(array $links, Account $account, array $client): ?string
    {
        if ($links === []) {
            return null;
        }

        if (count($links) === 1) {
            return $links[0];
        }

        $uuid = strtolower(trim((string) ($client['id'] ?? $account->sanaei_client_uuid ?? '')));

        if ($uuid !== '') {
            foreach ($links as $link) {
                if (str_contains(strtolower($link), $uuid)) {
                    return $link;
                }
            }
        }

        $prefix = match ($account->service_type) {
            ServiceType::SanaeiVmess => 'vmess://',
            ServiceType::SanaeiVless => 'vless://',
            ServiceType::SanaeiTrojan => 'trojan://',
            default => null,
        };

        $email = (string) ($client['email'] ?? $account->client_email ?? $account->remote_username ?? '');

        if ($prefix !== null) {
            foreach ($links as $link) {
                if (! str_starts_with(strtolower($link), $prefix)) {
                    continue;
                }

                $fragment = rawurldecode((string) (parse_url($link, PHP_URL_FRAGMENT) ?? ''));

                if ($email !== '' && ($fragment === $email || str_contains($fragment, $email))) {
                    return $link;
                }
            }

            foreach ($links as $link) {
                if (str_starts_with(strtolower($link), $prefix)) {
                    return $link;
                }
            }
        }

        return $links[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @param  array<string, mixed>  $client
     */
    protected function buildVless(array $inbound, array $client, Server $server): string
    {
        $stream = $this->decodeJsonField($inbound, 'streamSettings');
        $external = $this->firstExternalProxy($stream);

        if ($external !== null) {
            return $this->buildVlessWithAddress(
                $inbound,
                $client,
                $server,
                (string) $external['dest'],
                (int) $external['port'],
                (string) ($external['forceTls'] ?? 'same'),
                (string) ($external['remark'] ?? '')
            );
        }

        return $this->buildVlessWithAddress(
            $inbound,
            $client,
            $server,
            $this->resolveInboundAddress($inbound, $server),
            (int) ($inbound['port'] ?? 443),
            'same',
            ''
        );
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @param  array<string, mixed>  $client
     */
    protected function buildVlessWithAddress(
        array $inbound,
        array $client,
        Server $server,
        string $address,
        int $port,
        string $forceTls,
        string $externalRemark
    ): string {
        $uuid = (string) ($client['id'] ?? '');
        $stream = $this->decodeJsonField($inbound, 'streamSettings');
        $network = (string) ($stream['network'] ?? 'tcp');
        $security = (string) ($stream['security'] ?? 'none');
        $remark = $this->remark($inbound, $client, $server, $externalRemark);

        $params = ['type' => $network];

        $settings = $this->decodeJsonField($inbound, 'settings');
        $params['encryption'] = (string) ($settings['encryption'] ?? 'none');

        $flow = (string) ($client['flow'] ?? '');
        if ($flow !== '' && ($network === 'tcp' || in_array($security, ['tls', 'reality'], true))) {
            $params['flow'] = $flow;
        }

        if ($forceTls !== 'same' && $forceTls !== '') {
            $security = $forceTls;
        }

        if ($security === 'tls' || $security === 'reality') {
            $params['security'] = $security;
        } else {
            $params['security'] = 'none';
        }

        $this->appendStreamParams($params, $stream, $network, $security, $this->linkHost($server));

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return sprintf('vless://%s@%s:%d?%s#%s', $uuid, $address, $port, $query, rawurlencode($remark));
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @param  array<string, mixed>  $client
     */
    protected function buildVmess(array $inbound, array $client, Server $server): string
    {
        $stream = $this->decodeJsonField($inbound, 'streamSettings');
        $external = $this->firstExternalProxy($stream);
        $host = $external !== null
            ? (string) $external['dest']
            : $this->resolveInboundAddress($inbound, $server);
        $port = $external !== null
            ? (int) $external['port']
            : (int) ($inbound['port'] ?? 443);
        $network = (string) ($stream['network'] ?? 'tcp');
        $security = (string) ($stream['security'] ?? 'none');
        $remark = $this->remark($inbound, $client, $server, (string) ($external['remark'] ?? ''));

        if ($external !== null && ($external['forceTls'] ?? 'same') !== 'same') {
            $security = (string) $external['forceTls'];
        }

        $payload = [
            'v' => '2',
            'ps' => $remark,
            'add' => $host,
            'port' => (string) $port,
            'id' => (string) ($client['id'] ?? ''),
            'aid' => (string) ($client['alterId'] ?? '0'),
            'scy' => (string) ($client['security'] ?? 'auto'),
            'net' => $network,
            'type' => 'none',
            'host' => '',
            'path' => '',
            'tls' => in_array($security, ['tls', 'reality'], true) ? $security : '',
        ];

        $this->applyVmessNetwork($payload, $stream, $network, $host);

        if ($security === 'tls') {
            $tls = is_array($stream['tlsSettings'] ?? null) ? $stream['tlsSettings'] : [];
            $payload['sni'] = (string) ($this->nestedValue($tls, ['serverName']) ?? $host);
            $fingerprint = $this->nestedValue($tls, ['settings', 'fingerprint']);
            if (is_string($fingerprint) && $fingerprint !== '') {
                $payload['fp'] = $fingerprint;
            }
        }

        if ($security === 'reality') {
            $reality = is_array($stream['realitySettings'] ?? null) ? $stream['realitySettings'] : [];
            $serverNames = $reality['serverNames'] ?? [];
            if (is_array($serverNames) && isset($serverNames[0])) {
                $payload['sni'] = (string) $serverNames[0];
            }
        }

        return 'vmess://'.base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @param  array<string, mixed>  $client
     */
    protected function buildTrojan(array $inbound, array $client, Server $server): string
    {
        $stream = $this->decodeJsonField($inbound, 'streamSettings');
        $external = $this->firstExternalProxy($stream);
        $host = $external !== null
            ? (string) $external['dest']
            : $this->resolveInboundAddress($inbound, $server);
        $port = $external !== null
            ? (int) $external['port']
            : (int) ($inbound['port'] ?? 443);
        $network = (string) ($stream['network'] ?? 'tcp');
        $security = (string) ($stream['security'] ?? 'tls');
        $remark = $this->remark($inbound, $client, $server, (string) ($external['remark'] ?? ''));

        if ($external !== null && ($external['forceTls'] ?? 'same') !== 'same') {
            $security = (string) $external['forceTls'];
        }

        $password = (string) ($client['password'] ?? $client['id'] ?? '');

        $params = [
            'type' => $network,
            'security' => $security !== '' ? $security : 'tls',
        ];

        $this->appendStreamParams($params, $stream, $network, $security, $this->linkHost($server));

        return sprintf(
            'trojan://%s@%s:%d?%s#%s',
            rawurlencode($password),
            $host,
            $port,
            http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            rawurlencode($remark)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $stream
     */
    protected function applyVmessNetwork(array &$payload, array $stream, string $network, string $fallbackHost): void
    {
        if ($network === 'ws') {
            $ws = is_array($stream['wsSettings'] ?? null) ? $stream['wsSettings'] : [];
            $payload['path'] = (string) ($ws['path'] ?? '/');
            $payload['host'] = (string) ($ws['host'] ?? ($ws['headers']['Host'] ?? $fallbackHost));
        } elseif ($network === 'grpc') {
            $grpc = is_array($stream['grpcSettings'] ?? null) ? $stream['grpcSettings'] : [];
            $payload['path'] = (string) ($grpc['serviceName'] ?? '');
            $payload['authority'] = (string) ($grpc['authority'] ?? '');
            if (! empty($grpc['multiMode'])) {
                $payload['type'] = 'multi';
            }
        } elseif ($network === 'tcp') {
            $tcp = is_array($stream['tcpSettings'] ?? null) ? $stream['tcpSettings'] : [];
            $header = is_array($tcp['header'] ?? null) ? $tcp['header'] : [];
            $payload['type'] = (string) ($header['type'] ?? 'none');
        }
    }

    /**
     * @param  array<string, string>  $params
     * @param  array<string, mixed>  $stream
     */
    protected function appendStreamParams(array &$params, array $stream, string $network, string $security, string $fallbackHost): void
    {
        if ($security === 'tls') {
            $tls = is_array($stream['tlsSettings'] ?? null) ? $stream['tlsSettings'] : [];
            $sni = $this->nestedValue($tls, ['serverName']);
            if (is_string($sni) && $sni !== '') {
                $params['sni'] = $sni;
            }
            $fp = $this->nestedValue($tls, ['settings', 'fingerprint']);
            if (is_string($fp) && $fp !== '') {
                $params['fp'] = $fp;
            }
            if (! empty($tls['alpn']) && is_array($tls['alpn'])) {
                $params['alpn'] = implode(',', array_map('strval', $tls['alpn']));
            }
        }

        if ($security === 'reality') {
            $reality = is_array($stream['realitySettings'] ?? null) ? $stream['realitySettings'] : [];
            $settings = is_array($reality['settings'] ?? null) ? $reality['settings'] : [];
            if (! empty($settings['publicKey'])) {
                $params['pbk'] = (string) $settings['publicKey'];
            }
            if (! empty($reality['shortIds'][0])) {
                $params['sid'] = (string) $reality['shortIds'][0];
            }
            if (! empty($settings['fingerprint'])) {
                $params['fp'] = (string) $settings['fingerprint'];
            }
            if (! empty($reality['serverNames'][0])) {
                $params['sni'] = (string) $reality['serverNames'][0];
            }
            if (! empty($settings['spiderX'])) {
                $params['spx'] = (string) $settings['spiderX'];
            }
            if (! empty($settings['mldsa65Verify'])) {
                $params['pqv'] = (string) $settings['mldsa65Verify'];
            }
        }

        if ($network === 'ws') {
            $ws = is_array($stream['wsSettings'] ?? null) ? $stream['wsSettings'] : [];
            $params['path'] = (string) ($ws['path'] ?? '/');
            $params['host'] = (string) ($ws['host'] ?? ($ws['headers']['Host'] ?? $fallbackHost));
        } elseif ($network === 'grpc') {
            $grpc = is_array($stream['grpcSettings'] ?? null) ? $stream['grpcSettings'] : [];
            $params['serviceName'] = (string) ($grpc['serviceName'] ?? '');
            if (! empty($grpc['authority'])) {
                $params['authority'] = (string) $grpc['authority'];
            }
            if (! empty($grpc['multiMode'])) {
                $params['mode'] = 'multi';
            }
        } elseif ($network === 'tcp') {
            $tcp = is_array($stream['tcpSettings'] ?? null) ? $stream['tcpSettings'] : [];
            $header = is_array($tcp['header'] ?? null) ? $tcp['header'] : [];
            if (($header['type'] ?? '') === 'http') {
                $params['headerType'] = 'http';
                $request = is_array($header['request'] ?? null) ? $header['request'] : [];
                $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
                $hosts = $headers['Host'] ?? [];
                if (is_array($hosts) && isset($hosts[0])) {
                    $params['host'] = (string) $hosts[0];
                }
                $paths = $request['path'] ?? [];
                if (is_array($paths) && isset($paths[0])) {
                    $params['path'] = (string) $paths[0];
                }
            }
        } elseif ($network === 'httpupgrade') {
            $httpupgrade = is_array($stream['httpupgradeSettings'] ?? null) ? $stream['httpupgradeSettings'] : [];
            $params['path'] = (string) ($httpupgrade['path'] ?? '/');
            $params['host'] = (string) ($httpupgrade['host'] ?? ($httpupgrade['headers']['Host'] ?? $fallbackHost));
        } elseif ($network === 'xhttp') {
            $xhttp = is_array($stream['xhttpSettings'] ?? null) ? $stream['xhttpSettings'] : [];
            $params['path'] = (string) ($xhttp['path'] ?? '/');
            $params['host'] = (string) ($xhttp['host'] ?? ($xhttp['headers']['Host'] ?? $fallbackHost));
            if (! empty($xhttp['mode'])) {
                $params['mode'] = (string) $xhttp['mode'];
            }
        } elseif ($network === 'kcp') {
            $kcp = is_array($stream['kcpSettings'] ?? null) ? $stream['kcpSettings'] : [];
            $header = is_array($kcp['header'] ?? null) ? $kcp['header'] : [];
            if (! empty($header['type'])) {
                $params['headerType'] = (string) $header['type'];
            }
            if (! empty($kcp['seed'])) {
                $params['seed'] = (string) $kcp['seed'];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $stream
     * @return array<string, mixed>|null
     */
    protected function firstExternalProxy(array $stream): ?array
    {
        $proxies = $stream['externalProxy'] ?? null;

        if (! is_array($proxies) || $proxies === []) {
            return null;
        }

        $first = $proxies[0];

        return is_array($first) ? $first : null;
    }

    /**
     * @param  array<string, mixed>  $inbound
     */
    protected function resolveInboundAddress(array $inbound, Server $server): string
    {
        $listen = trim((string) ($inbound['listen'] ?? ''));

        if ($listen !== '' && ! in_array($listen, ['0.0.0.0', '::', '::0'], true)) {
            return $listen;
        }

        return $this->linkHost($server);
    }

    protected function linkHost(Server $server): string
    {
        $settings = $this->sanaeiService->getPanelSettings($server);
        $subDomain = trim((string) ($settings['subDomain'] ?? ''));

        if ($subDomain !== '') {
            return $subDomain;
        }

        return $this->extractHostnameFromServer($server);
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @param  array<string, mixed>  $client
     */
    protected function remark(array $inbound, array $client, Server $server, string $externalRemark = ''): string
    {
        if ($externalRemark !== '') {
            return $externalRemark;
        }

        $settings = $this->sanaeiService->getPanelSettings($server);
        $remarkModel = (string) ($settings['remarkModel'] ?? '');

        if ($remarkModel === '' || strlen($remarkModel) < 2) {
            return (string) ($client['email'] ?? $inbound['remark'] ?? $inbound['tag'] ?? 'VPN');
        }

        $showInfo = filter_var($settings['subShowInfo'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $this->generateRemarkFromModel($remarkModel, $inbound, $client, $server, $showInfo);
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @param  array<string, mixed>  $client
     */
    protected function generateRemarkFromModel(
        string $remarkModel,
        array $inbound,
        array $client,
        Server $server,
        bool $showInfo,
        string $extra = ''
    ): string {
        $separationChar = $remarkModel[0];
        $orderChars = substr($remarkModel, 1);
        $email = (string) ($client['email'] ?? '');

        $orders = [
            'i' => (string) ($inbound['remark'] ?? ''),
            'e' => $email,
            'o' => $extra,
        ];

        $parts = [];

        for ($index = 0; $index < strlen($orderChars); $index++) {
            $char = $orderChars[$index];
            $value = $orders[$char] ?? '';

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        if ($showInfo && $email !== '') {
            $stats = $this->findClientStats($inbound, $email)
                ?? $this->sanaeiService->getClientTraffics($server, $email);

            if (is_array($stats)) {
                if (! ($stats['enable'] ?? true)) {
                    return '⛔️N/A'.$separationChar.implode($separationChar, $parts);
                }

                $up = (int) ($stats['up'] ?? $stats['Up'] ?? 0);
                $down = (int) ($stats['down'] ?? $stats['Down'] ?? 0);
                $total = (int) ($stats['total'] ?? $stats['Total'] ?? 0);

                if ($total > 0) {
                    $remaining = $total - ($up + $down);

                    if ($remaining > 0) {
                        $parts[] = $this->formatTraffic($remaining).'📊';
                    }
                }
            }
        }

        if ($parts === []) {
            return $email !== '' ? $email : 'VPN';
        }

        return implode($separationChar, $parts);
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @return array<string, mixed>|null
     */
    protected function findClientStats(array $inbound, string $email): ?array
    {
        foreach ($inbound['clientStats'] ?? [] as $stat) {
            if (! is_array($stat)) {
                continue;
            }

            if ((string) ($stat['email'] ?? '') === $email) {
                return $stat;
            }
        }

        return null;
    }

    protected function formatTraffic(int $bytes): string
    {
        $gigabyte = 1073741824;
        $megabyte = 1048576;
        $kilobyte = 1024;

        if ($bytes >= $gigabyte) {
            return number_format($bytes / $gigabyte, 2, '.', '').'GB';
        }

        if ($bytes >= $megabyte) {
            return number_format($bytes / $megabyte, 2, '.', '').'MB';
        }

        if ($bytes >= $kilobyte) {
            return number_format($bytes / $kilobyte, 2, '.', '').'KB';
        }

        return $bytes.'B';
    }

    protected function extractHostnameFromServer(Server $server): string
    {
        $host = trim((string) $server->host);

        if (preg_match('#^https?://#i', $host)) {
            $parsed = parse_url($host);

            return (string) ($parsed['host'] ?? 'localhost');
        }

        $host = explode('/', $host)[0];

        return explode(':', $host)[0] ?: 'localhost';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    protected function nestedValue(array $data, array $keys): mixed
    {
        $current = $data;

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }

            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function decodeJsonField(array $data, string $key): array
    {
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key) ?? $key);
        $raw = $data[$key] ?? $data[$snake] ?? [];

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
