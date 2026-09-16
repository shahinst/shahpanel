<?php

namespace App\Services;

use App\Enums\ServiceType;
use App\Exceptions\RemoteProvisionException;
use App\Models\Server;
use App\Models\ServerInterface;
use InvalidArgumentException;

class MikrotikProfileService
{
    public function wireguardRemoteKey(string $interfaceName): string
    {
        return 'profile:wg:'.$interfaceName;
    }

    public function pppRemoteKey(string $profileName): string
    {
        return 'profile:ppp:'.$profileName;
    }

    public function isWireguardProfile(ServerInterface $profile): bool
    {
        return $profile->category === 'wireguard'
            || str_starts_with($profile->remote_key, 'profile:wg:')
            || str_starts_with($profile->remote_key, 'wg:');
    }

    public function isPppProfile(ServerInterface $profile): bool
    {
        return $profile->category === 'ppp'
            || str_starts_with($profile->remote_key, 'profile:ppp:');
    }

    public function resolve(Server $server, ServiceType $serviceType, ?string $profileKey = null): ServerInterface
    {
        if ($profileKey !== null && $profileKey !== '') {
            $profile = ServerInterface::query()
                ->where('server_id', $server->id)
                ->where('remote_key', $profileKey)
                ->first();

            if ($profile !== null) {
                if ($this->isWireguardProfile($profile)) {
                    throw new InvalidArgumentException(__('services.mikrotik_wireguard_no_profile'));
                }

                return $profile;
            }
        }

        if ($serviceType === ServiceType::Wireguard) {
            throw new InvalidArgumentException(__('services.mikrotik_wireguard_no_profile'));
        }

        return app(MikrotikPppProfileService::class)->resolveProfile($server, $serviceType, $profileKey);
    }

    /**
     * @param  bool  $respectProfileKeyWhenFull  Keep profile when at capacity (existing secret updates).
     */
    public function resolveForPush(
        Server $server,
        ServiceType $serviceType,
        ?string $profileKey = null,
        bool $respectProfileKeyWhenFull = false,
    ): ServerInterface {
        if ($serviceType === ServiceType::Wireguard) {
            throw new InvalidArgumentException(__('services.mikrotik_wireguard_no_profile'));
        }

        return app(MikrotikPppProfileService::class)->resolveProfile(
            $server,
            $serviceType,
            $profileKey,
            $respectProfileKeyWhenFull,
        );
    }

    public function wireguardInterfaceName(ServerInterface $profile): string
    {
        if (preg_match('/^profile:wg:(.+)$/', $profile->remote_key, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^wg:(.+)$/', $profile->remote_key, $matches)) {
            return $matches[1];
        }

        return $profile->name;
    }

    public function pppProfileName(ServerInterface $profile): string
    {
        if (preg_match('/^profile:ppp:(.+)$/', $profile->remote_key, $matches)) {
            return $matches[1];
        }

        return $profile->name;
    }

    public function primaryPort(ServerInterface $profile): ?int
    {
        if ($profile->port !== null) {
            return (int) $profile->port;
        }

        $ports = $profile->meta['ports'] ?? [];

        if ($ports === []) {
            return null;
        }

        return (int) reset($ports);
    }

    /**
     * @return list<string>
     */
    public function enabledPppServices(array $servicePorts): array
    {
        return array_keys(array_filter($servicePorts, fn ($port) => $port !== null && $port > 0));
    }

    public function mapPppServiceToServiceType(string $service): ServiceType
    {
        return match (strtolower($service)) {
            'l2tp' => ServiceType::L2tp,
            'ovpn' => ServiceType::Openvpn,
            default => ServiceType::Ppp,
        };
    }

    public function pppServiceForType(ServiceType $serviceType): string
    {
        return match ($serviceType) {
            ServiceType::Openvpn => 'ovpn',
            ServiceType::L2tp => 'l2tp',
            default => 'any',
        };
    }
}
