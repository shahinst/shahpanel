<?php

namespace Tests\Feature\Tunneling;

use App\Enums\AgentHealth;
use App\Enums\TunnelKind;
use App\Jobs\Tunneling\ApplyTunnelGroupJob;
use App\Jobs\Tunneling\EvaluateTunnelGroupJob;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use App\Models\TunnelMetricSample;
use App\Services\Tunneling\LoadBalancerService;
use App\Services\Tunneling\QualityScoreService;
use App\Services\Tunneling\TelegramAlertService;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use App\Services\Tunneling\TunnelSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FailoverTest extends TestCase
{
    use CreatesTunnelFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    protected function evaluate(TunnelGroup $group): void
    {
        // Let the container inject handle()'s dependencies instead of listing
        // them here, so adding one to the job does not break every test.
        app()->call([new EvaluateTunnelGroupJob($group->id), 'handle']);
    }

    protected function provisionedGroup(array $overrides = [], TunnelKind $kind = TunnelKind::Gre): TunnelGroup
    {
        $iran = $this->makeServer('iran', '10.0.0.1');
        $group = $this->makeGroup($iran, [$this->makeServer('de', '10.0.0.2')], $kind, $overrides + ['status' => 'active']);

        app(TunnelGroupOrchestrator::class)->syncAgents($group);

        return $group->fresh(['iranServer', 'exits.server', 'exits.agents']);
    }

    protected function sampleAgent(TunnelAgent $agent, bool $up, float $lossPct, int $count = 6): void
    {
        for ($i = 0; $i < $count; $i++) {
            TunnelMetricSample::create([
                'tunnel_agent_id' => $agent->id,
                'sampled_at' => now()->subSeconds(5 * $i),
                'up' => $up,
                'latency_ms' => $up ? 80 : null,
                'loss_pct' => $lossPct,
                'rx_bps' => $up ? 10_000_000 : 0,
                'tx_bps' => $up ? 10_000_000 : 0,
                'score' => $up ? 80 : 0,
            ]);
        }
    }

    public function test_total_loss_marks_agent_down_and_group_down(): void
    {
        $group = $this->provisionedGroup();
        $agent = $group->exits->first()->agents->first();

        $this->sampleAgent($agent, up: false, lossPct: 100);
        $this->evaluate($group);

        $agent->refresh();
        $this->assertSame(AgentHealth::Down, $agent->health);
        $this->assertGreaterThan(0, $agent->fail_count);
        $this->assertSame('down', $group->fresh()->status->value);
    }

    public function test_healthy_samples_keep_agent_up(): void
    {
        $group = $this->provisionedGroup();
        $agent = $group->exits->first()->agents->first();

        $this->sampleAgent($agent, up: true, lossPct: 0);
        $this->evaluate($group);

        $agent->refresh();
        $this->assertSame(AgentHealth::Up, $agent->health);
        $this->assertSame(0, $agent->fail_count);
        $this->assertNotNull($agent->last_seen_up_at);
    }

    public function test_persistent_failure_triggers_automatic_kind_switch(): void
    {
        config(['tunneling.scoring.switch_after_failures' => 2, 'tunneling.scoring.switch_cooldown_secs' => 0]);

        $group = $this->provisionedGroup(['auto_switch_kind' => true], TunnelKind::Eoip);
        $agent = $group->exits->first()->agents->first();

        // Two consecutive bad evaluation cycles.
        $this->sampleAgent($agent, up: false, lossPct: 100);
        $this->evaluate($group);
        $this->evaluate($group->fresh(['iranServer', 'exits.server', 'exits.agents']));

        $agent->refresh();
        $this->assertNotSame(TunnelKind::Eoip, $agent->kind);
        $this->assertTrue((bool) ($agent->meta['kind_switched'] ?? false));
        $this->assertNotNull($agent->last_switched_at);

        // The switch re-provisions the group.
        Queue::assertPushed(ApplyTunnelGroupJob::class);
    }

    public function test_l2tp_ladder_switch_respects_ip_variant_gate(): void
    {
        config(['tunneling.dpi.l2tpv3_ip_enabled' => false]);

        $group = $this->provisionedGroup(['auto_switch_l2tp' => true], TunnelKind::L2tpV3Udp);
        $agent = $group->exits->first()->agents->first();

        app(TunnelSwitchService::class)->switchL2tp($agent);

        // v3-udp would normally fall to v3-ip, but the gate skips it to v2.
        $this->assertSame(TunnelKind::L2tpV2, $agent->fresh()->kind);
    }

    public function test_switch_cooldown_blocks_rapid_flapping(): void
    {
        config([
            'tunneling.scoring.switch_after_failures' => 1,
            'tunneling.scoring.switch_cooldown_secs' => 3600,
        ]);

        $group = $this->provisionedGroup(['auto_switch_kind' => true], TunnelKind::Eoip);
        $agent = $group->exits->first()->agents->first();
        $agent->forceFill(['last_switched_at' => now()->subMinute()])->save();

        $this->sampleAgent($agent, up: false, lossPct: 100);
        $this->evaluate($group);

        // Cooldown active: kind must not change again.
        $this->assertSame(TunnelKind::Eoip, $agent->fresh()->kind);
    }

    public function test_reweigh_adjusts_weight_from_quality_score(): void
    {
        $group = $this->provisionedGroup();
        $agent = $group->exits->first()->agents->first();
        $agent->forceFill(['quality_score' => 95.0, 'weight' => 1, 'health' => AgentHealth::Up])->save();

        $changed = app(LoadBalancerService::class)->reweigh($group->fresh(['iranServer', 'exits.server', 'exits.agents']));

        $this->assertTrue($changed);
        $this->assertSame(4, $agent->fresh()->weight);
    }
}
