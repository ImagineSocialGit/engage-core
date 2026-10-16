<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingServiceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableScheduling();
    }

    public function test_dedicated_service_and_staff_routes_require_authentication_and_enabled_module(): void
    {
        $service = BookableService::factory()->create();

        $this->get(route('crm.scheduling.configuration.services.index'))
            ->assertRedirect(route('login'));
        $this->get(route('crm.scheduling.configuration.services.edit', $service))
            ->assertRedirect(route('login'));
        $this->get(route('crm.scheduling.configuration.services.details.edit', $service))
            ->assertRedirect(route('login'));
        $this->get(route('crm.scheduling.configuration.services.staff.edit', $service))
            ->assertRedirect(route('login'));
        $this->get(route('crm.scheduling.configuration.staff.index'))
            ->assertRedirect(route('login'));

        config()->set('modules.enabled', ['core']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.index'))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.edit', $service))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.details.edit', $service))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.staff.edit', $service))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.staff.index'))
            ->assertNotFound();
    }


    private function enableScheduling(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));

        if (! $this->app->getProvider(SchedulingModuleServiceProvider::class)) {
            $this->app->register(SchedulingModuleServiceProvider::class);
        }
    }
}