<?php

namespace App\CrmTunneling\Dto;

final class TunnelTestResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $greRunning,
        public string $pingTunnel,
        public string $pingInternet,
        public bool $passed,
        public array $raw = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gre_running' => $this->greRunning,
            'ping_tunnel' => $this->pingTunnel,
            'ping_internet' => $this->pingInternet,
            'passed' => $this->passed,
            'raw' => $this->raw,
        ];
    }
}
