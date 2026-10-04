<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Support\PanelExtensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use RuntimeException;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class PanelExtensionsTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['accountMenuResolvers', 'navResolvers'] as $property) {
            (new ReflectionProperty(PanelExtensions::class, $property))->setValue(null, []);
        }

        parent::tearDown();
    }

    public function test_unusable_items_are_dropped_and_a_default_icon_is_given(): void
    {
        PanelExtensions::navItem(fn (string $panel) => ['label' => 'One', 'url' => '/one']);
        PanelExtensions::navItem(fn (string $panel) => null);
        PanelExtensions::navItem(fn (string $panel) => ['label' => 'No url']);
        PanelExtensions::navItem(fn (string $panel) => ['label' => 'Two', 'url' => '/two', 'icon' => 'bx-star', 'active' => true]);

        $items = PanelExtensions::navItems('admin');

        $this->assertSame(['One', 'Two'], array_column($items, 'label'));
        $this->assertSame('bx-extension', $items[0]['icon']);
        $this->assertFalse($items[0]['active']);
        $this->assertTrue($items[1]['active']);
    }

    public function test_a_failing_resolver_does_not_break_the_others(): void
    {
        PanelExtensions::accountMenuItem(fn (Account $a, string $p) => throw new RuntimeException('broken module'));
        PanelExtensions::accountMenuItem(fn (Account $a, string $p) => ['label' => 'Works', 'url' => '/works/'.$a->id]);

        $account = $this->makeAccount($this->makeAgent(), $this->makeServer());
        $items = PanelExtensions::accountMenuItems($account, 'admin');

        $this->assertCount(1, $items);
        $this->assertSame('/works/'.$account->id, $items[0]['url']);
        $this->assertSame('bx-extension', $items[0]['icon']);
    }

    public function test_items_render_in_the_side_menu_and_the_account_actions_menu(): void
    {
        PanelExtensions::navItem(fn (string $panel) => $panel === 'admin'
            ? ['label' => 'Ext side entry', 'url' => '/ext-side', 'icon' => 'bx-rocket'] : null);
        PanelExtensions::accountMenuItem(fn (Account $a, string $prefix) => [
            'label' => 'Ext account action', 'url' => '/ext-account/'.$a->id, 'icon' => 'bx-mobile',
        ]);

        $account = $this->makeAccount($this->makeAgent(), $this->makeServer());

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.accounts.wireguard'))
            ->assertOk()
            ->assertSee('Ext side entry')
            ->assertSee('/ext-side', false)
            ->assertSee('Ext account action')
            ->assertSee('/ext-account/'.$account->id, false);
    }
}
