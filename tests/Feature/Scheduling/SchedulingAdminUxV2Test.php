<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SchedulingAdminUxV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 12:00:00 UTC');

        config()->set('client.timezone', 'UTC');
        config()->set('scheduling.public.enabled', true);
        config()->set('scheduling.public.url', 'https://schedule.example.test');

        $this->enableScheduling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_appointment_type_workspace_surfaces_hosts_next_appointment_and_share_link(): void
    {
        $user = User::factory()->create();
        $host = SchedulingHost::factory()->create([
            'name' => 'Taylor Host',
            'status' => SchedulingHost::STATUS_ACTIVE,
        ]);
        $service = $this->shareableService([
            'name' => 'Planning Call',
            'key' => 'planning_call',
            'duration_minutes' => 30,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->getKey(),
            'scheduling_host_id' => $host->getKey(),
            'is_active' => true,
            'sort_order' => 10,
        ]);

        Appointment::factory()->create([
            'bookable_service_id' => $service->getKey(),
            'scheduling_host_id' => $host->getKey(),
            'status' => Appointment::STATUS_CONFIRMED,
            'starts_at' => CarbonImmutable::parse('2026-09-10 14:30:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-10 15:00:00 UTC'),
        ]);

        $response = $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.index'));

        $response
            ->assertOk()
            ->assertViewIs('crm.scheduling.services.index')
            ->assertSee('data-scheduling-appointment-type-workspace', false)
            ->assertSee(
                'data-scheduling-appointment-type-card="'.$service->getKey().'"',
                false,
            )
            ->assertSee('Taylor Host')
            ->assertSee('Sep 10, 2:30 PM')
            ->assertSee(
                'https://schedule.example.test/services/planning_call',
                false,
            )
            ->assertSee('Copy booking link')
            ->assertSee(
                route('crm.scheduling.configuration.services.edit', $service),
                false,
            );
    }

    public function test_appointment_type_editor_presents_the_guided_setup_path_and_related_surfaces(): void
    {
        $service = $this->shareableService([
            'name' => 'Discovery Call',
            'key' => 'discovery_call',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.configuration.services.edit', $service));

        $response
            ->assertOk()
            ->assertViewIs('crm.scheduling.services.edit')
            ->assertSee('data-scheduling-service-setup-path', false)
            ->assertSeeInOrder([
                '1. Basics',
                '2. Availability',
                '3. Booking form',
                '4. Confirmation &amp; reminders',
                '5. After booking',
                '6. Advanced',
            ], false)
            ->assertSee('id="basics"', false)
            ->assertSee('id="availability"', false)
            ->assertSee('id="booking-form"', false)
            ->assertSee('id="communications"', false)
            ->assertSee('id="after-booking"', false)
            ->assertSee('id="advanced"', false)
            ->assertSee('data-scheduling-booking-form-summary', false)
            ->assertSee('data-scheduling-service-copy-link', false)
            ->assertSee(
                'https://schedule.example.test/services/discovery_call',
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.communications.index'),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.after-booking.index'),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.resources.index'),
                false,
            )
            ->assertSee(
                route('crm.settings.team.index'),
                false,
            );
    }

    public function test_private_appointment_type_explains_why_it_is_not_shareable(): void
    {
        $service = $this->shareableService([
            'name' => 'Internal Review',
            'key' => 'internal_review',
            'is_public' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.configuration.services.index'))
            ->assertOk()
            ->assertSee('Not shareable yet')
            ->assertSee('Turn on customer self-booking to get a shareable link.')
            ->assertDontSee(
                'data-scheduling-booking-link="'.$service->getKey().'"',
                false,
            );
    }

    /** @param array<string, mixed> $attributes */
    private function shareableService(array $attributes = []): BookableService
    {
        return BookableService::factory()->create(array_replace([
            'status' => BookableService::STATUS_ACTIVE,
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'remote_method' => BookableService::REMOTE_METHOD_PHONE,
            'in_person_arrangement' => null,
            'location_type' => BookableService::LOCATION_TYPE_PHONE,
            'is_public' => true,
        ], $attributes));
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