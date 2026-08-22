<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcessHighwaySurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_highway_is_available_when_flow_routes_are_disabled(): void
    {
        config()->set('modules.enabled', array_values(array_filter(
            config('modules.enabled', []),
            fn (string $module): bool => $module !== 'flow_routes',
        )));

        $response = $this->actingAs($this->createUser())
            ->get(route('crm.process-highway.index'));

        $response
            ->assertOk()
            ->assertSee('Process Highway')
            ->assertSee('No process routes are enabled');
    }

    public function test_process_highway_presents_current_routes_in_plain_language(): void
    {
        $statusId = DB::table('contact_statuses')->insertGetId([
            'key' => 'engaged',
            'name' => 'Engaged',
            'is_core' => false,
            'is_active' => true,
            'is_customized' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $routeId = DB::table('flow_routes')->insertGetId([
            'key' => 'process_highway_test_route',
            'contact_status_id' => $statusId,
            'name' => 'Engaged follow-up',
            'description' => 'Keep the next action obvious.',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => 'contact_status',
            'trigger_key' => 'engaged',
            'is_active' => true,
            'is_customized' => false,
            'meta' => json_encode(['definition' => ['category' => 'consumer_lifecycle']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flow_route_points')->insert([
            'flow_route_id' => $routeId,
            'key' => 'create_follow_up',
            'type' => 'create_task',
            'name' => 'Create follow-up task',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'definition' => json_encode(['task_template_key' => 'follow_up']),
            'settings' => json_encode([]),
            'cancel_conditions' => json_encode([]),
            'is_customized' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->createUser())
            ->get(route('crm.process-highway.index'));

        $response
            ->assertOk()
            ->assertSee('Lifecycle')
            ->assertSee('Engaged follow-up')
            ->assertSee('A contact becomes Engaged.')
            ->assertSee('Create follow-up task');
    }

    private function createUser(): \App\Models\User
    {
        return \App\Models\User::factory()->create();
    }
}