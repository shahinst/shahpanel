<?php

namespace Tests\Feature\Tunneling;

use App\Enums\DesiredObjectStatus;
use App\Enums\TunnelDirection;
use App\Enums\TunnelKind;
use App\Models\DesiredNetworkObject;
use App\Models\TunnelAgent;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrchestratorTest extends TestCase
{
    use CreatesTunnelFixtures;
    use RefreshDatabase;

    protected TunnelGroupOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->orchestrator = app(TunnelGroupOrchestrator::class);
    }

    public function test_sync_agents_creates_agents_per_exit_with_unique_transport(): void
    {
        $iran = $this->makeServer('iran', '10.0.0.1');
        $group = $this->makeGroup($iran, [
            $this->makeServer('de', '10.0.0.2'),
            $this->makeServer('tr', '10.0.0.3'),
        ], TunnelKind::Gre, ['agents_per_exit' => 2]);

        $this->orchestrator->syncAgents($group);

        $agents = TunnelAgent::all();

        $this->assertCount(4, $agents);
        $this->assertCount(4, $agents->pluck('transport_network')->unique());
        $this->assertCount(4, $agents->pluck('iran_interface')->unique());
        $this->assertTrue($agents->every(fn (TunnelAgent $a): bool => $a->iran_ip !== '' && $a->foreign_ip !== ''));
    }

    public function test_build_desired_objects_is_idempotent(): void
    {
        $iran = $this->makeServer('iran', '10.0.0.1');
        $group = $this->makeGroup($iran, [$this->makeServer('de', '10.0.0.2')]);

        $this->orchestrator->syncAgents($group);
        $this->orchestrator->buildDesiredObjects($group);

        $first = DesiredNetworkObject::query()
            ->orderBy('id')
            ->get(['marker', 'menu', 'payload'])
            ->map(fn ($o): string => $o->marker.'|'.$o->menu.'|'.json_encode($o->payload))
            ->all();

        $this->assertNotEmpty($first);

        // Second pass must not create, duplicate or alter anything.
        $this->orchestrator->buildDesiredObjects($group->fresh(['iranServer', 'exits.server', 'exits.agents']));

        $second = DesiredNetworkObject::query()
            ->orderBy('id')
            ->get(['marker', 'menu', 'payload'])
            ->map(fn ($o): string => $o->marker.'|'.$o->menu.'|'.json_encode($o->payload))
            ->all();

        $this->assertSame($first, $second);
        $this->assertSame(0, DesiredNetworkObject::query()->where('status', DesiredObjectStatus::Removing->value)->count());
    }

    public function test_reverse_flips_direction_and_keeps_desired_objects(): void
    {
        $iran = $this->makeServer('iran', '10.0.0.1');
        $group = $this->makeGroup($iran, [$this->makeServer('de', '10.0.0.2')]);

        $this->orchestrator->provision($group);
        $markersBefore = DesiredNetworkObject::query()
            ->where('status', '!=', DesiredObjectStatus::Removing->value)
            ->pluck('marker')
            ->sort()
            ->values();

        $this->orchestrator->reverse($group->fresh(['iranServer', 'exits.server', 'exits.agents']));

        $group->refresh();
        $this->assertSame(TunnelDirection::Reverse, $group->direction);

        $markersAfter = DesiredNetworkObject::query()
            ->where('status', '!=', DesiredObjectStatus::Removing->value)
            ->pluck('marker')
            ->sort()
            ->values();

        // Same desired-state surface: clients/routes are regenerated, not lost.
        $this->assertSame($markersBefore->all(), $markersAfter->all());
    }

    public function test_changed_layout_marks_stale_objects_as_removing(): void
    {
        $iran = $this->makeServer('iran', '10.0.0.1');
        $group = $this->makeGroup($iran, [$this->makeServer('de', '10.0.0.2')], TunnelKind::Gre, ['agents_per_exit' => 2]);

        $this->orchestrator->syncAgents($group);
        $this->orchestrator->buildDesiredObjects($group);

        $before = DesiredNetworkObject::query()->where('status', '!=', DesiredObjectStatus::Removing->value)->count();

        // Shrink to one agent per exit: the second agent's objects become stale.
        $group->update(['agents_per_exit' => 1]);
        $group = $group->fresh(['iranServer', 'exits.server', 'exits.agents']);

        $this->orchestrator->syncAgents($group);
        $this->orchestrator->buildDesiredObjects($group->fresh(['iranServer', 'exits.server', 'exits.agents']));

        $removing = DesiredNetworkObject::query()->where('status', DesiredObjectStatus::Removing->value)->count();
        $active = DesiredNetworkObject::query()->where('status', '!=', DesiredObjectStatus::Removing->value)->count();

        $this->assertGreaterThan(0, $removing);
        $this->assertLessThan($before, $active);
        $this->assertSame(1, TunnelAgent::query()->count());
    }

    public function test_provision_snapshots_a_config_version(): void
    {
        $iran = $this->makeServer('iran', '10.0.0.1');
        $group = $this->makeGroup($iran, [$this->makeServer('de', '10.0.0.2')]);

        $this->orchestrator->provision($group);
        $this->orchestrator->provision($group->fresh(['iranServer', 'exits.server', 'exits.agents']));

        $versions = $group->configVersions()->pluck('version')->all();

        $this->assertSame([2, 1], $versions);
    }
}
