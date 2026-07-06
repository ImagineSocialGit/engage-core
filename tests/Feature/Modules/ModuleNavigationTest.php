<?php

namespace Tests\Feature\Modules;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use Tests\TestCase;

class ModuleNavigationTest extends TestCase
{
    public function test_webinars_nav_item_renders_when_webinars_module_is_enabled(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'tasks',
            'campaigns',
            'webinars',
        ]);

        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/')
            ->assertOk()
            ->assertSee('Webinars');
    }

    public function test_webinars_nav_item_does_not_render_when_webinars_module_is_disabled(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'tasks',
            'campaigns',
        ]);

        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/')
            ->assertOk()
            ->assertDontSee('Webinars');
    }

    public function test_message_templates_nav_item_renders_when_messaging_module_is_enabled(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/')
            ->assertOk()
            ->assertSee('Message Templates');
    }

    public function test_message_templates_nav_item_does_not_render_when_messaging_module_is_disabled(): void
    {
        config()->set('modules.enabled', []);

        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/')
            ->assertOk()
            ->assertDontSee('Message Templates');
    }

    public function test_automatic_follow_ups_nav_item_renders_when_flow_routes_module_is_enabled(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/')
            ->assertOk()
            ->assertSee('Automatic Follow-ups');
    }

    public function test_automatic_follow_ups_nav_item_does_not_render_when_flow_routes_module_is_disabled(): void
    {
        config()->set('modules.enabled', [
            'workflow',
        ]);

        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/')
            ->assertOk()
            ->assertDontSee('Automatic Follow-ups');
    }
}
