<?php

namespace App\CrmTunneling\Contracts;

use App\CrmTunneling\Dto\TunnelTestResult;
use App\Models\CrmTunnel;
use App\Models\Server;

interface TunnelDriver
{
    /** @return list<string> log lines */
    public function reset(Server $server): array;

    /** @return list<string> log lines */
    public function provision(CrmTunnel $tunnel): array;

    public function test(CrmTunnel $tunnel): TunnelTestResult;

    /** @return list<string> log lines */
    public function teardown(CrmTunnel $tunnel): array;
}
