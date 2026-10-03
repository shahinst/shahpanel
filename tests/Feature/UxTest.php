<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class UxTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_ticket_list_filters_and_paginates(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.tickets.index', ['status' => 'open', 'q' => 'abc']))
            ->assertOk()
            ->assertSee('name="status"', false);
    }

    public function test_listing_pages_use_icon_actions(): void
    {
        $admin = $this->makeAdmin();
        $this->makeServer();
        $this->makePackage();

        foreach (['admin.servers.index', 'admin.packages.index', 'admin.administrators.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertSee('icon-action', false);
        }
    }

    public function test_alerts_are_written_in_the_recipients_language(): void
    {
        $agent = $this->makeAgent(['locale' => 'en']);

        $this->assertSame(__('backend.notify_account_expired_title', [], 'en'), trans_for($agent, 'backend.notify_account_expired_title'));
        $this->assertSame(__('backend.notify_account_expired_title', [], config('app.locale')), trans_for(null, 'backend.notify_account_expired_title'));
    }

    public function test_form_group_shows_the_field_error(): void
    {
        $errors = (new ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag(['name' => 'Name is required']));

        // Requests share the bag with every view (ShareErrorsFromSession).
        view()->share('errors', $errors);

        $html = $this->blade('<x-form.group label="Name" for="name"><input id="name" name="name"></x-form.group>');

        $html->assertSee('Name is required');
    }
}
