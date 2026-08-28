<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Scheduling\Services\Dashboard\TodayAppointmentsDashboardPanelProvider;
use App\Modules\Scheduling\Services\Dashboard\TomorrowAppointmentsDashboardPanelProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SchedulingDashboardPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-28 20:00:00 UTC');
        config()->set('client.timezone', 'America/Chicago');
        $this->enableScheduling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_today_panel_shows_only_operational_appointments_overlapping_the_client_day(): void
    {
        $contact = Contact::factory()->create(['name' => 'Today Contact']);
        $today = Appointment::factory()->create([
            'contact_id' => $contact->id,
            'status' => Appointment::STATUS_PENDING,
            'starts_at' => CarbonImmutable::parse('2026-08-29 02:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-29 03:00:00 UTC'),
        ]);

        Appointment::factory()->create([
            'status' => Appointment::STATUS_COMPLETED,
            'starts_at' => CarbonImmutable::parse('2026-08-28 18:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-28 19:00:00 UTC'),
        ]);

        Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'starts_at' => CarbonImmutable::parse('2026-08-29 15:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-29 16:00:00 UTC'),
        ]);

        $panel = app(TodayAppointmentsDashboardPanelProvider::class)
            ->panel(Request::create('/'));

        $this->assertSame('scheduling.today', $panel['key']);
        $this->assertSame(1, $panel['count']);
        $this->assertSame(1, $panel['attention_count']);
        $this->assertCount(1, $panel['items']);
        $this->assertSame((string) $today->id, $panel['items'][0]['key']);
        $this->assertSame('Today Contact', $panel['items'][0]['title']);
        $this->assertSame(
            route('crm.scheduling.appointments.show', $today),
            $panel['items'][0]['href'],
        );
    }

    public function test_tomorrow_panel_is_hidden_when_empty_and_presents_tomorrow_for_preparation(): void
    {
        $contact = Contact::factory()->create(['name' => 'Tomorrow Contact']);
        $tomorrow = Appointment::factory()->create([
            'contact_id' => $contact->id,
            'status' => Appointment::STATUS_CONFIRMED,
            'description' => 'Bring the signed intake packet.',
            'starts_at' => CarbonImmutable::parse('2026-08-29 15:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-29 16:00:00 UTC'),
        ]);

        $panel = app(TomorrowAppointmentsDashboardPanelProvider::class)
            ->panel(Request::create('/'));

        $this->assertSame('scheduling.tomorrow', $panel['key']);
        $this->assertSame(1, $panel['count']);
        $this->assertSame(0, $panel['attention_count']);
        $this->assertTrue($panel['hide_when_empty']);
        $this->assertSame((string) $tomorrow->id, $panel['items'][0]['key']);
        $this->assertSame('Tomorrow Contact', $panel['items'][0]['title']);
        $this->assertSame('Bring the signed intake packet.', $panel['items'][0]['description']);
    }

    public function test_dashboard_renders_scheduling_panels_when_enabled_and_filters_them_when_disabled(): void
    {
        $user = User::factory()->create();
        $today = Appointment::factory()->create([
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => CarbonImmutable::parse('2026-08-29 02:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-29 03:00:00 UTC'),
        ]);
        $tomorrow = Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'starts_at' => CarbonImmutable::parse('2026-08-29 15:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-29 16:00:00 UTC'),
        ]);

        config()->set('modules.dashboard.slots', [
            'immediate_work' => [
                'max' => 2,
                'hide_when_empty' => false,
                'panels' => ['scheduling.today'],
            ],
            'context' => [
                'max' => 2,
                'hide_when_empty' => true,
                'panels' => ['scheduling.tomorrow'],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('crm.index'))
            ->assertOk()
            ->assertSee('data-dashboard-panel="scheduling.today"', false)
            ->assertSee('data-dashboard-panel="scheduling.tomorrow"', false)
            ->assertSee(route('crm.scheduling.appointments.show', $today), false)
            ->assertSee(route('crm.scheduling.appointments.show', $tomorrow), false);

        config()->set('modules.enabled', ['core']);

        $this->actingAs($user)
            ->get(route('crm.index'))
            ->assertOk()
            ->assertDontSee('data-dashboard-panel="scheduling.today"', false)
            ->assertDontSee('data-dashboard-panel="scheduling.tomorrow"', false);
    }

    private function enableScheduling(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));

        $this->app->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );
    }
}