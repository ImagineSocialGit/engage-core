<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Actions\FinalizeWebinarRegistrationAction;
use App\Modules\Webinars\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebinarAutomationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registration_commits_its_automation_event_with_the_registration(): void
    {
        $finalize = Mockery::mock(FinalizeWebinarRegistrationAction::class);
        $finalize->shouldReceive('handle')->once();
        app()->instance(FinalizeWebinarRegistrationAction::class, $finalize);

        $webinar = Webinar::factory()->create(['external_id' => null]);

        $result = app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Durable',
                'email' => 'durable-webinar@example.com',
            ],
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        $this->assertTrue($result->wasCreated());
        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => 'webinar.registered',
            'subject_type' => $result->registration->getMorphClass(),
            'subject_id' => (string) $result->registration->getKey(),
        ]);
    }

    public function test_registration_rolls_back_if_its_outbox_record_cannot_be_written(): void
    {
        $emit = Mockery::mock(EmitWebinarAutomationEventAction::class);
        $emit->shouldReceive('forRegistration')
            ->once()
            ->andThrow(new RuntimeException('Simulated outbox storage failure.'));
        app()->instance(EmitWebinarAutomationEventAction::class, $emit);

        $finalize = Mockery::mock(FinalizeWebinarRegistrationAction::class);
        $finalize->shouldReceive('handle')->never();
        app()->instance(FinalizeWebinarRegistrationAction::class, $finalize);

        $webinar = Webinar::factory()->create(['external_id' => null]);

        try {
            app(CreateWebinarRegistrationAction::class)->handle(
                validated: [
                    'first_name' => 'Rollback',
                    'email' => 'rollback-webinar@example.com',
                ],
                request: Request::create('/register', 'POST'),
                webinar: $webinar,
            );

            $this->fail('The registration transaction should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated outbox storage failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('contacts', [
            'email' => 'rollback-webinar@example.com',
        ]);
        $this->assertDatabaseCount('webinar_registrations', 0);
        $this->assertDatabaseCount('automation_event_outbox_events', 0);
    }
}